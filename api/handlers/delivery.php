<?php
/**
 * Delivery · workflow de pedidos a domicilio.
 *
 * Estados de entrega:
 *   pendiente  → recién creado, sin repartidor
 *   asignada   → ya tiene repartidor asignado, espera salir
 *   en_camino  → repartidor salió a entregar
 *   entregada  → entregado al cliente y cobrado
 *   no_entregada → cliente no estaba, no quiso, etc. (incluye motivo)
 *   cancelada  → cliente canceló antes
 */
require_once __DIR__ . '/../../includes/escpos.php'; // impresión térmica directa

return [
    /**
     * Lista pedidos a domicilio. Filtros:
     *   filter=activos  → pendientes, asignadas, en_camino, listas
     *   filter=mios     → mis asignados (repartidor) o creados por mí (cajero)
     *   filter=entregados → entregados hoy
     *   filter=all_today  → todos los del día
     */
    'list' => function () {
        $u = current_user();
        $filter = $_GET['filter'] ?? 'activos';

        $where = ["o.tipo = 'domicilio'"];
        $params = [];

        switch ($filter) {
            case 'activos':
                $where[] = "o.estado_entrega IN ('pendiente','asignada','en_camino')";
                $where[] = "o.estado <> 'cancelada'";
                break;
            case 'mios':
                $where[] = "o.repartidor_id = ?";
                $params[] = $u['id'];
                break;
            case 'entregados':
                $where[] = "o.estado_entrega = 'entregada'";
                $where[] = "DATE(o.entregada_at) = CURDATE()";
                break;
            case 'all_today':
                $where[] = "DATE(o.abierta_at) = CURDATE()";
                break;
        }

        $sql = "SELECT o.id, o.tipo, o.estado, o.estado_entrega,
                       o.cliente_nombre, o.cliente_telefono, o.cliente_direccion,
                       o.cliente_referencias, o.costo_envio, o.paga_con, o.web_metodo_pago, o.total, o.propina,
                       o.abierta_at, o.en_camino_at, o.entregada_at, o.repartidor_id,
                       u.nombre AS repartidor_nombre,
                       (SELECT COUNT(*) FROM orden_items WHERE orden_id = o.id) AS num_items,
                       (SELECT COUNT(*) FROM comandas WHERE orden_id = o.id AND estado='lista') AS comandas_listas,
                       (SELECT COUNT(*) FROM comandas WHERE orden_id = o.id AND estado IN ('nueva','preparando')) AS comandas_en_curso
                FROM ordenes o
                LEFT JOIN usuarios u ON u.id = o.repartidor_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.abierta_at DESC
                LIMIT 200";

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        json_response($rows);
    },

    /**
     * Detalle de un pedido domicilio (con items + pagos)
     */
    'detalle' => function () {
        $id = (int)($_GET['id'] ?? 0);
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT o.*, u.nombre AS repartidor_nombre,
                   c.nombre AS cliente_db_nombre, c.total_pedidos AS cliente_total_pedidos
            FROM ordenes o
            LEFT JOIN usuarios u ON u.id = o.repartidor_id
            LEFT JOIN clientes c ON c.id = o.cliente_id
            WHERE o.id=?
        ");
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        if (!$o) json_response(['error' => 'No encontrada'], 404);

        $items = $pdo->prepare("SELECT * FROM orden_items WHERE orden_id=? ORDER BY id");
        $items->execute([$id]);
        $o['items'] = $items->fetchAll();

        $pagos = $pdo->prepare("SELECT p.*, m.nombre AS metodo FROM pagos p JOIN metodos_pago m ON m.id=p.metodo_id WHERE p.orden_id=?");
        $pagos->execute([$id]);
        $o['pagos'] = $pagos->fetchAll();
        json_response($o);
    },

    /**
     * Lista de repartidores disponibles (en turno activo)
     */
    'repartidores' => function () {
        $stmt = db()->query("
            SELECT u.id, u.nombre,
                   (SELECT COUNT(*) FROM ordenes WHERE repartidor_id=u.id AND estado_entrega IN ('asignada','en_camino')) AS pendientes,
                   t.estado AS turno_estado
            FROM usuarios u
            LEFT JOIN turnos t ON t.usuario_id=u.id AND t.fecha=CURDATE() AND t.estado='en_curso'
            WHERE u.rol='repartidor' AND u.activo=1
            ORDER BY pendientes ASC, u.nombre
        ");
        json_response($stmt->fetchAll());
    },

    /**
     * Asignar repartidor a una orden (cajero/admin)
     */
    'asignar' => function () {
        require_method('POST');
        // ERROR 007: los meseros (p.ej. Leo) también pueden asignar/cambiar repartidor.
        api_require_role('admin','encargado','cajero','mesero');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        if (!$orden_id) json_response(['error' => 'Datos incompletos'], 400);

        $pdo = db();
        $o = $pdo->prepare("SELECT repartidor_id, total, costo_envio, tipo, estado_entrega FROM ordenes WHERE id=?");
        $o->execute([$orden_id]);
        $ord = $o->fetch();
        if (!$ord || $ord['tipo'] !== 'domicilio') json_response(['error' => 'Pedido de domicilio no encontrado'], 404);
        if ($ord['estado_entrega'] === 'entregada') json_response(['error' => 'El pedido ya fue entregado'], 400);

        // Repartidor: usa el nuevo si viene; si no, conserva el actual.
        $repartidor_id = (int)($in['repartidor_id'] ?? 0) ?: (int)($ord['repartidor_id'] ?? 0);
        $cambiaEnvio   = isset($in['costo_envio']) && $in['costo_envio'] !== '';
        if (!$repartidor_id && !$cambiaEnvio) json_response(['error' => 'No hay cambios que guardar'], 400);

        // Validar repartidor si se va a (re)asignar
        $repNombre = '';
        if ($repartidor_id) {
            $rs = $pdo->prepare("SELECT nombre, rol FROM usuarios WHERE id=? AND activo=1");
            $rs->execute([$repartidor_id]);
            $r = $rs->fetch();
            if (!$r || $r['rol'] !== 'repartidor') json_response(['error' => 'Repartidor inválido'], 400);
            $repNombre = $r['nombre'];
        }

        // ERROR 007: se puede CAMBIAR repartidor y/o envío aunque ya esté asignado
        // o en camino (mientras no esté entregado).
        $sets = []; $vals = [];
        if ($repartidor_id) {
            $sets[] = 'repartidor_id=?'; $vals[] = $repartidor_id;
            $sets[] = "estado_entrega=CASE WHEN estado_entrega IN ('pendiente') THEN 'asignada' ELSE estado_entrega END";
        }
        if ($cambiaEnvio) {
            // En domicilio, ordenes.total = SOLO la comida; el envío va aparte y
            // se suma al cobrar. Por eso al cambiar el envío NO se toca total.
            $sets[] = 'costo_envio=?'; $vals[] = (float)$in['costo_envio'];
        }
        $vals[] = $orden_id;
        $pdo->prepare("UPDATE ordenes SET " . implode(',', $sets) . " WHERE id=? AND tipo='domicilio' AND estado_entrega<>'entregada'")
            ->execute($vals);

        log_action('delivery_asignar', 'orden', $orden_id,
            ($repNombre ? "Repartidor: $repNombre" : "") . ($cambiaEnvio ? " · envío $" . number_format((float)$in['costo_envio'],2) : ''));
        @touch(__DIR__ . '/../../config/.sse-trigger');
        json_response(['ok' => true]);
    },

    /**
     * Cambiar estado de entrega (en_camino, no_entregada, cancelada)
     */
    'estado' => function () {
        require_method('POST');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        $estado = $in['estado'] ?? '';
        $motivo = trim($in['motivo'] ?? '');
        $valid = ['en_camino','no_entregada','cancelada','entregada'];
        if (!in_array($estado, $valid)) json_response(['error' => 'Estado inválido'], 400);

        $pdo = db();
        $u = current_user();
        // Sólo el repartidor asignado o admin/cajero pueden cambiar el estado
        $stmt = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
        $stmt->execute([$orden_id]);
        $o = $stmt->fetch();
        if (!$o) json_response(['error' => 'Orden no encontrada'], 404);
        $puede = in_array($u['rol'], ['admin','encargado','cajero']) || ($o['repartidor_id'] == $u['id']);
        if (!$puede) json_response(['error' => 'No autorizado'], 403);

        // El repartidor debe tener turno abierto para salir a entregar
        if ($u['rol'] === 'repartidor' && $estado === 'en_camino' && !current_turno_id()) {
            json_response(['error' => '⏱ Abre tu turno antes de salir a entregar (Mi turno).'], 403);
        }

        if ($estado === 'no_entregada' && strlen($motivo) < 3) {
            json_response(['error' => 'Motivo requerido para no entregada'], 400);
        }
        // Marcar ENTREGADA sin cobrar de nuevo: sólo válido si la orden YA fue
        // cobrada (p.ej. se pagó en caja y quedó atorada en 'en_camino'). Si no
        // está cobrada, hay que usar "Entregada · Cobrar" (entregar_y_cobrar).
        if ($estado === 'entregada') {
            if ($o['estado'] !== 'cobrada') {
                json_response(['error' => 'Esta orden aún no se ha cobrado. Usa "Entregada · Cobrar".'], 400);
            }
            $pdo->prepare("UPDATE ordenes SET estado_entrega='entregada', entregada_at=COALESCE(entregada_at, NOW()) WHERE id=?")
                ->execute([$orden_id]);
        } elseif ($estado === 'cancelada') {
            $pdo->prepare("UPDATE ordenes SET estado_entrega='cancelada', estado='cancelada', motivo_cancelacion=?, cerrada_at=NOW() WHERE id=?")
                ->execute([$motivo ?: 'Cancelada por delivery', $orden_id]);
        } elseif ($estado === 'no_entregada') {
            $pdo->prepare("UPDATE ordenes SET estado_entrega='no_entregada', motivo_cancelacion=? WHERE id=?")
                ->execute([$motivo, $orden_id]);
        } elseif ($estado === 'en_camino') {
            // Registrar cuándo salió a entregar
            $pdo->prepare("UPDATE ordenes SET estado_entrega='en_camino', en_camino_at=NOW() WHERE id=?")
                ->execute([$orden_id]);
        } else {
            $pdo->prepare("UPDATE ordenes SET estado_entrega=? WHERE id=?")
                ->execute([$estado, $orden_id]);
        }

        log_action('delivery_estado', 'orden', $orden_id,
            "→ $estado" . ($motivo ? " · $motivo" : ''));

        @touch(__DIR__ . '/../../config/.sse-trigger');
        json_response(['ok' => true]);
    },

    /**
     * Marcar entregada + cobrar (la usa el repartidor desde la calle).
     * Reutiliza la lógica de pago de ordenes/pay.
     */
    'entregar_y_cobrar' => function () {
        require_method('POST');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        $pagos = $in['pagos'] ?? [];
        $propina = (float)($in['propina'] ?? 0);

        $pdo = db();
        $u = current_user();
        $stmt = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
        $stmt->execute([$orden_id]);
        $o = $stmt->fetch();
        if (!$o) json_response(['error' => 'Orden no encontrada'], 404);
        if ($o['repartidor_id'] != $u['id'] && !in_array($u['rol'], ['admin','encargado','cajero'])) {
            json_response(['error' => 'Solo el repartidor asignado puede entregar'], 403);
        }
        if ($o['estado'] === 'cobrada') json_response(['error' => 'Orden ya cobrada'], 400);
        // El repartidor necesita turno abierto para cobrar (el dinero entra a su corte)
        if ($u['rol'] === 'repartidor' && !current_turno_id()) {
            json_response(['error' => '⏱ Abre tu turno antes de cobrar (Mi turno).'], 403);
        }

        $turno_id = current_turno_id();
        $total_esperado = (float)$o['total'] + $propina + (float)$o['costo_envio'];
        $sumPagos = 0;
        foreach ($pagos as $p) $sumPagos += (float)($p['monto'] ?? 0);
        if ($sumPagos + 0.01 < $total_esperado) {
            json_response(['error' => 'Monto pagado insuficiente', 'falta' => $total_esperado - $sumPagos], 400);
        }

        $pdo->beginTransaction();
        try {
            $metodos = $pdo->query("SELECT id, codigo FROM metodos_pago")->fetchAll(PDO::FETCH_KEY_PAIR);
            $methodIds = array_flip($metodos);
            $insPago = $pdo->prepare("INSERT INTO pagos (orden_id, metodo_id, monto, referencia, turno_id) VALUES (?,?,?,?,?)");
            // Registrar el monto aplicado (sin el cambio) — ver nota en ordenes/pay
            $restante = $total_esperado;
            foreach ($pagos as $p) {
                $mid = $methodIds[$p['metodo'] ?? 'cash'] ?? null;
                if (!$mid) throw new Exception("Método inválido");
                $aplicado = min((float)$p['monto'], $restante);
                if ($aplicado <= 0) continue;
                $restante = round($restante - $aplicado, 2);
                $insPago->execute([$orden_id, $mid, $aplicado, $p['referencia'] ?? null, $turno_id]);
            }
            if ($propina > 0) {
                $pdo->prepare("INSERT INTO propinas (orden_id, mesero_id, monto, turno_id) VALUES (?,?,?,?)")
                    ->execute([$orden_id, $u['id'], $propina, $turno_id]);
            }
            $pdo->prepare("UPDATE ordenes SET
                estado='cobrada',
                estado_entrega='entregada',
                entregada_at=NOW(),
                cerrada_at=NOW(),
                propina=?,
                turno_id=COALESCE(turno_id, ?)
              WHERE id=?")
                ->execute([$propina, $turno_id, $orden_id]);

            // Actualizar cliente
            $pdo->prepare("UPDATE clientes c
                JOIN ordenes o ON o.cliente_id=c.id
                SET c.total_pedidos=c.total_pedidos+1,
                    c.total_gastado=c.total_gastado+?,
                    c.last_order_at=NOW()
                WHERE o.id=?")->execute([$total_esperado, $orden_id]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['error' => $e->getMessage()], 500);
        }

        log_action('delivery_entregada', 'orden', $orden_id,
            "Total " . number_format($total_esperado, 2) . ($propina>0?" · Propina ".number_format($propina,2):""));
        @touch(__DIR__ . '/../../config/.sse-trigger');

        // Imprimir el ticket de venta en caja (servidor → red).
        $itemsT = $pdo->prepare("SELECT * FROM orden_items WHERE orden_id=? ORDER BY comensal, id");
        $itemsT->execute([$orden_id]);
        $pgT = $pdo->prepare("SELECT mp.codigo AS metodo, pa.monto FROM pagos pa
                              JOIN metodos_pago mp ON mp.id=pa.metodo_id WHERE pa.orden_id=?");
        $pgT->execute([$orden_id]);
        $ticket = [
            'orden_id' => $orden_id,
            'mesa_id'  => null,
            'mesa_numero' => null,
            'mesa_etiqueta' => '🛵 DOMICILIO',
            'cliente_nombre' => $o['cliente_nombre'] ?? null,
            'subtotal' => (float)$o['subtotal'],
            'iva'      => (float)$o['iva'],
            'costo_envio' => (float)$o['costo_envio'],
            'propina'  => $propina,
            'total'    => $total_esperado,
            'pagado'   => $sumPagos,
            'cambio'   => max(0, $sumPagos - $total_esperado),
            'items'    => $itemsT->fetchAll(),
            'pagos'    => $pgT->fetchAll(),
            'fecha'    => date('Y-m-d H:i:s'),
        ];
        $impreso = print_ticket_red($ticket);

        json_response([
            'ok' => true,
            'total' => $total_esperado,
            'cambio' => max(0, $sumPagos - $total_esperado),
            'impreso' => $impreso,
        ]);
    },
];
