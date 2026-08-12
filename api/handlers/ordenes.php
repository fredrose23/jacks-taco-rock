<?php
/**
 * Handlers para el recurso ORDENES
 */
require_once __DIR__ . '/../../includes/escpos.php'; // impresión térmica directa

return [
    /** Obtiene (o crea) la orden abierta de una mesa con sus items.
     *  Para pedidos local: usa mesa_id. Para llevar/domicilio: usa orden_id.
     */
    'get_or_create' => function () {
        // Filtrar "null" como string que viene del query string si CURRENT_*_ID es null
        $mesa_raw  = $_GET['mesa_id']  ?? '';
        $orden_raw = $_GET['orden_id'] ?? '';
        $mesa_id     = ($mesa_raw  === '' || $mesa_raw  === 'null') ? 0 : (int)$mesa_raw;
        $orden_id_in = ($orden_raw === '' || $orden_raw === 'null') ? 0 : (int)$orden_raw;

        $user = current_user();
        $pdo = db();

        if ($orden_id_in) {
            $stmt = $pdo->prepare("SELECT * FROM ordenes WHERE id=? AND estado='abierta'");
            $stmt->execute([$orden_id_in]);
            $row = $stmt->fetch();
            if (!$row) json_response(['error' => 'Orden no encontrada'], 404);
            $orden_id = (int)$row['id'];
        } else {
            if (!$mesa_id) json_response(['error' => 'Se requiere mesa_id u orden_id válidos'], 400);
            require_jornada_abierta();   // no se abren cuentas si el negocio está cerrado
            $orden_id = get_or_create_order($mesa_id, $user['id'] ?? null);
        }

        $orden = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
        $orden->execute([$orden_id]);
        $data = $orden->fetch();

        $items = $pdo->prepare("
            SELECT oi.*, p.emoji
            FROM orden_items oi
            JOIN productos p ON p.id = oi.producto_id
            WHERE oi.orden_id = ?
            ORDER BY oi.comensal, oi.id
        ");
        $items->execute([$orden_id]);
        $data['items'] = $items->fetchAll();

        $data['subtotal'] = (float)$data['subtotal'];
        $data['iva']      = (float)$data['iva'];
        $data['total']    = (float)$data['total'];
        foreach ($data['items'] as &$it) {
            $it['precio']   = (float)$it['precio'];
            $it['enviado']  = (bool)$it['enviado'];
            $it['comensal'] = (int)($it['comensal'] ?? 1);
        }
        json_response($data);
    },

    /** Agrega un item (con modificadores opcionales).
     *  Acepta mesa_id (para pedidos en mesa) u orden_id (para llevar/domicilio). */
    'add_item' => function () {
        require_method('POST');
        require_jornada_abierta();
        $in = json_input();
        $mesa_id     = (int)($in['mesa_id'] ?? 0);
        $orden_id_in = (int)($in['orden_id'] ?? 0);
        $producto_id = (int)($in['producto_id'] ?? 0);
        $comensal    = max(1, (int)($in['comensal'] ?? 1));
        $notas       = trim((string)($in['notas'] ?? ''));
        $modificadores = $in['modificadores'] ?? []; // [{grupo,nombre,precio_extra}, ...]

        $pdo = db();
        $prod = $pdo->prepare("SELECT id, nombre, precio, disponible, stock, maneja_stock FROM productos WHERE id=?");
        $prod->execute([$producto_id]);
        $p = $prod->fetch();
        if (!$p) json_response(['error' => 'Producto no encontrado'], 404);
        if (!$p['disponible']) json_response(['error' => 'Producto no disponible'], 400);
        // Solo bloquear por stock si el producto maneja stock
        if ($p['maneja_stock'] && (int)$p['stock'] <= 0) {
            json_response(['error' => 'Sin stock de '.$p['nombre']], 400);
        }

        $user = current_user();
        // Si vino orden_id explícito (pedidos llevar/domicilio) lo usamos.
        // Si no, creamos/obtenemos orden por mesa.
        if ($orden_id_in) {
            $orden_id = $orden_id_in;
        } elseif ($mesa_id) {
            $orden_id = get_or_create_order($mesa_id, $user['id'] ?? null);
        } else {
            json_response(['error' => 'mesa_id u orden_id requerido'], 400);
        }

        // ERROR 003: una mesa la atiende el mesero que la abrió. Otro mesero NO
        // puede agregarle productos a una mesa ajena; solo su dueño o un admin.
        assert_mesa_no_ajena($orden_id, $user);

        $extra = 0;
        foreach ($modificadores as $m) $extra += (float)($m['precio_extra'] ?? 0);
        $modsJson = $modificadores ? json_encode($modificadores, JSON_UNESCAPED_UNICODE) : null;

        // Si no hay mods ni notas, incrementa item existente igual
        if (!$modsJson && !$notas) {
            $find = $pdo->prepare("SELECT id FROM orden_items
                                   WHERE orden_id=? AND producto_id=? AND enviado=0 AND comensal=?
                                     AND COALESCE(notas,'')='' AND modificadores_json IS NULL LIMIT 1");
            $find->execute([$orden_id, $producto_id, $comensal]);
            if ($existing = $find->fetch()) {
                $pdo->prepare("UPDATE orden_items SET cantidad=cantidad+1 WHERE id=?")->execute([$existing['id']]);
                recalc_order_totals($orden_id);
                marcar_mesa_ocupada($orden_id);
                json_response(['ok' => true, 'orden_id' => $orden_id]);
            }
        }
        $ins = $pdo->prepare("INSERT INTO orden_items
            (orden_id, producto_id, nombre, precio, cantidad, comensal, notas, modificadores_json, precio_extra)
            VALUES (?,?,?,?,1,?,?,?,?)");
        $ins->execute([$orden_id, $p['id'], $p['nombre'], $p['precio'], $comensal, $notas ?: null, $modsJson, $extra]);
        recalc_order_totals($orden_id);
        marcar_mesa_ocupada($orden_id);
        log_action('item_add', 'orden', $orden_id, "{$p['nombre']} ({$comensal}) " . ($modsJson?'(+mods)':''));
        json_response(['ok' => true, 'orden_id' => $orden_id]);
    },

    /** Agrega un combo a la orden (varios productos) */
    'add_combo' => function () {
        require_method('POST');
        require_jornada_abierta();
        $in = json_input();
        $mesa_id  = (int)($in['mesa_id'] ?? 0);
        $orden_id_in = (int)($in['orden_id'] ?? 0);
        $combo_id = (int)($in['combo_id'] ?? 0);
        $comensal = max(1, (int)($in['comensal'] ?? 1));

        $pdo = db();
        $combo = $pdo->prepare("SELECT * FROM combos WHERE id=? AND activo=1");
        $combo->execute([$combo_id]);
        $c = $combo->fetch();
        if (!$c) json_response(['error'=>'Combo no encontrado'], 404);

        $user = current_user();
        if ($orden_id_in) {
            $orden_id = $orden_id_in;
        } elseif ($mesa_id) {
            $orden_id = get_or_create_order($mesa_id, $user['id'] ?? null);
        } else {
            json_response(['error' => 'mesa_id u orden_id requerido'], 400);
        }

        // ERROR 003: una mesa la atiende el mesero que la abrió. Otro mesero NO
        // puede agregarle productos a una mesa ajena; solo su dueño o un admin.
        assert_mesa_no_ajena($orden_id, $user);

        // Calcular suma original de items para prorratear el precio del combo
        $items = $pdo->prepare("
            SELECT ci.cantidad, p.id, p.nombre, p.precio
            FROM combo_items ci JOIN productos p ON p.id=ci.producto_id
            WHERE ci.combo_id=?");
        $items->execute([$combo_id]);
        $itemsArr = $items->fetchAll();
        $sumOrig = 0;
        foreach ($itemsArr as $it) $sumOrig += $it['cantidad'] * $it['precio'];
        $factor = $sumOrig > 0 ? ($c['precio'] / $sumOrig) : 1;

        $nota = "🎁 {$c['nombre']}";
        $ins = $pdo->prepare("INSERT INTO orden_items
            (orden_id, producto_id, nombre, precio, cantidad, comensal, notas)
            VALUES (?,?,?,?,?,?,?)");
        foreach ($itemsArr as $it) {
            $precioProrrateado = round($it['precio'] * $factor, 2);
            $ins->execute([$orden_id, $it['id'], $it['nombre'], $precioProrrateado, $it['cantidad'], $comensal, $nota]);
        }
        recalc_order_totals($orden_id);
        marcar_mesa_ocupada($orden_id);
        log_action('combo_add', 'orden', $orden_id, $c['nombre']);
        json_response(['ok'=>true, 'orden_id'=>$orden_id]);
    },

    /** Actualiza la nota de un item */
    'update_notes' => function () {
        require_method('POST');
        $in = json_input();
        $item_id = (int)($in['item_id'] ?? 0);
        $notas = trim((string)($in['notas'] ?? ''));

        $pdo = db();
        $stmt = $pdo->prepare("SELECT enviado FROM orden_items WHERE id=?");
        $stmt->execute([$item_id]);
        $it = $stmt->fetch();
        if (!$it) json_response(['error' => 'Item no encontrado'], 404);
        if ($it['enviado']) json_response(['error' => 'No se puede modificar un item ya enviado'], 400);

        $pdo->prepare("UPDATE orden_items SET notas=? WHERE id=?")->execute([$notas ?: null, $item_id]);
        json_response(['ok' => true]);
    },

    /** Cambia comensal de un item */
    'move_to_comensal' => function () {
        require_method('POST');
        $in = json_input();
        $item_id = (int)($in['item_id'] ?? 0);
        $comensal = max(1, (int)($in['comensal'] ?? 1));

        $pdo = db();
        $pdo->prepare("UPDATE orden_items SET comensal=? WHERE id=?")->execute([$comensal, $item_id]);
        json_response(['ok' => true]);
    },

    /** Cambia la cantidad de un item (delta +1 / -1) */
    /**
     * Marca / desmarca un producto (aún no enviado) como "para llevar" dentro
     * de una cuenta de mesa. Se cobra junto con la cuenta, pero la cocina lo
     * empaca. Solo afecta items no enviados a cocina.
     */
    'toggle_llevar_item' => function () {
        require_method('POST');
        api_require_login();
        $item_id = (int)(json_input()['item_id'] ?? 0);
        if (!$item_id) json_response(['error' => 'item_id requerido'], 400);
        $pdo = db();
        $st = $pdo->prepare("SELECT enviado FROM orden_items WHERE id=?");
        $st->execute([$item_id]);
        $it = $st->fetch();
        if (!$it) json_response(['error' => 'Item no encontrado'], 404);
        if ($it['enviado']) json_response(['error' => 'Ya se envió a cocina; no se puede cambiar'], 400);
        $pdo->prepare("UPDATE orden_items SET para_llevar = 1 - COALESCE(para_llevar,0) WHERE id=?")->execute([$item_id]);
        json_response(['ok' => true]);
    },

    'change_qty' => function () {
        require_method('POST');
        $in = json_input();
        $item_id = (int)($in['item_id'] ?? 0);
        $delta = (int)($in['delta'] ?? 0);

        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM orden_items WHERE id=?");
        $stmt->execute([$item_id]);
        $it = $stmt->fetch();
        if (!$it) json_response(['error' => 'Item no encontrado'], 404);
        if ($it['enviado']) json_response(['error' => 'No se puede modificar un item ya enviado a cocina'], 400);

        $nueva = $it['cantidad'] + $delta;
        if ($nueva <= 0) {
            $pdo->prepare("DELETE FROM orden_items WHERE id=?")->execute([$item_id]);
        } else {
            $pdo->prepare("UPDATE orden_items SET cantidad=? WHERE id=?")->execute([$nueva, $item_id]);
        }
        recalc_order_totals($it['orden_id']);
        json_response(['ok' => true]);
    },

    /**
     * Envía los items pendientes a cocina con 3 escenarios posibles:
     *
     *   A) Solo items de Cocina 1 → 1 comanda en Cocina 1
     *   B) Solo items de Cocina 2 → 1 comanda en Cocina 2
     *   C) Items de ambas        → 2 comandas:
     *                              - Cocina 1 imprime TODA la comanda
     *                              - Cocina 2 imprime SOLO los items que se preparan ahí
     *
     * En el caso C, los items de Cocina 2 también aparecen en la hoja de Cocina 1
     * con un tag "🍳 C2" al lado para que Cocina 1 sepa que ese item NO lo
     * prepara ella, solo lo coordina.
     */
    'send_kitchen' => function () {
        require_method('POST');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        $sinImprimir = !empty($in['sin_imprimir']); // opción: registrar sin imprimir comanda

        $pdo = db();
        $orden = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
        $orden->execute([$orden_id]);
        $o = $orden->fetch();
        if (!$o) json_response(['error' => 'Orden no encontrada'], 404);

        // #4 — No permitir enviar a cocina un domicilio sin costo de envío definido
        if ($o['tipo'] === 'domicilio' && (float)($o['costo_envio'] ?? 0) <= 0) {
            json_response(['error' => 'Asigna el costo de envío antes de enviar el pedido a cocina'], 400);
        }

        // Traer items pendientes con flag cocina_2 del producto
        $items = $pdo->prepare("
            SELECT oi.*, COALESCE(p.cocina_2, 0) AS cocina_2
            FROM orden_items oi
            JOIN productos p ON p.id = oi.producto_id
            WHERE oi.orden_id=? AND oi.enviado=0
            ORDER BY oi.comensal, oi.id
        ");
        $items->execute([$orden_id]);
        $nuevos = $items->fetchAll();
        if (!$nuevos) json_response(['error' => 'No hay items para enviar'], 400);

        $userId = current_user()['id'] ?? null;
        // Para pedidos en mesa: cargar la mesa. Para llevar/domicilio: armar
        // un "pseudo-mesa" con los datos del cliente para que el ticket de
        // cocina muestre algo útil.
        if ($o['mesa_id']) {
            $mesaStmt = $pdo->prepare("SELECT * FROM mesas WHERE id=?");
            $mesaStmt->execute([$o['mesa_id']]);
            $mesa = $mesaStmt->fetch();
        } else {
            $tipoLabel = $o['tipo'] === 'domicilio' ? '🛵 DOMICILIO' : ($o['tipo'] === 'mostrador' ? '🥤 MOSTRADOR' : '📦 LLEVAR');
            $mesa = [
                'id'        => 0,
                'numero'    => '',
                'capacidad' => '',
                'zona'      => $tipoLabel,
                'descripcion' => trim(($o['cliente_nombre'] ?? '') . ' · ' . ($o['cliente_telefono'] ?? ''), ' ·'),
            ];
        }

        // Separar items por destino
        $itemsCocina1 = array_values(array_filter($nuevos, fn($it) => $it['cocina_2'] == 0));
        $itemsCocina2 = array_values(array_filter($nuevos, fn($it) => $it['cocina_2'] == 1));

        // Info de la orden para la comanda-TICKET de PARA LLEVAR / DOMICILIO
        // (lleva precios, dirección y forma de pago; como un ticket sin logo).
        $ordenInfo = null;
        if (in_array($o['tipo'], ['llevar','domicilio','mostrador'], true)) {
            $ordenInfo = [
                'cliente_nombre'      => $o['cliente_nombre'] ?? null,
                'cliente_telefono'    => $o['cliente_telefono'] ?? null,
                'cliente_direccion'   => $o['cliente_direccion'] ?? null,
                'cliente_referencias' => $o['cliente_referencias'] ?? null,
                'hora_pickup' => $o['hora_pickup'] ?? null,
                'subtotal'    => (float)$o['subtotal'],
                'total'       => (float)$o['total'],
                'costo_envio' => (float)($o['costo_envio'] ?? 0),
                'paga_con'    => (float)($o['paga_con'] ?? 0),
                'metodo_pago' => $o['web_metodo_pago'] ?? null,
            ];
        }

        // Para PICK-UP / DOMICILIO: items ya enviados en rondas anteriores (para
        // concatenarlos al fondo de la comanda como "YA EN PREPARACION").
        $itemsPrevios = [];
        if ($ordenInfo !== null) {
            $prev = $pdo->prepare("SELECT oi.* FROM orden_items oi
                                   WHERE oi.orden_id=? AND oi.enviado=1 AND COALESCE(oi.cantidad_cancelada,0) < oi.cantidad
                                   ORDER BY oi.comensal, oi.id");
            $prev->execute([$orden_id]);
            $itemsPrevios = $prev->fetchAll();
        }

        // Ronda / tanda: cada envío a cocina de la MISMA orden es una ronda nueva.
        // Sirve para distinguir tandas de una mesa (aunque pidan lo mismo).
        $rondaStmt = $pdo->prepare("SELECT COALESCE(MAX(ronda),0)+1 FROM comandas WHERE orden_id=?");
        $rondaStmt->execute([$orden_id]);
        $ronda = (int)$rondaStmt->fetchColumn();

        $insComanda = $pdo->prepare("INSERT INTO comandas (orden_id, mesa_id, usuario_id, cocina, ronda) VALUES (?,?,?,?,?)");
        $updItem    = $pdo->prepare("UPDATE orden_items SET enviado=1, comanda_id=? WHERE id=?");
        $comandas = [];

        // Cocina A (1) SIEMPRE recibe una comanda con TODOS los items, aunque el
        // pedido sea solo para Cocina B — así Cocina A lleva el control completo.
        $insComanda->execute([$orden_id, $o['mesa_id'], $userId, 1, $ronda]);
        $c1_id = (int)$pdo->lastInsertId();
        foreach ($nuevos as $it) $updItem->execute([$c1_id, $it['id']]);
        $comandas[] = [
            'id'=>$c1_id, 'orden_id'=>$orden_id, 'cocina'=>1, 'ronda'=>$ronda, 'mesa'=>$mesa, 'items'=>$nuevos,
            'created'=>date('H:i'), 'orden'=>$ordenInfo, 'completa'=>true, 'tipo'=>$o['tipo'],
            'items_previos'=>$itemsPrevios,
        ];

        // Cocina B (2) recibe SOLO sus items, si los hay.
        if ($itemsCocina2) {
            $insComanda->execute([$orden_id, $o['mesa_id'], $userId, 2, $ronda]);
            $c2_id = (int)$pdo->lastInsertId();
            $comandas[] = [
                'id'=>$c2_id, 'orden_id'=>$orden_id, 'cocina'=>2, 'ronda'=>$ronda, 'mesa'=>$mesa, 'items'=>$itemsCocina2,
                'created'=>date('H:i'), 'orden'=>null, 'completa'=>false, 'tipo'=>$o['tipo'],
            ];
        }
        $caso = $itemsCocina2 ? ($itemsCocina1 ? 'ambas' : 'solo C2') : 'solo C1';

        @touch(__DIR__ . '/../../config/.sse-trigger');

        $destino = $o['mesa_id']
            ? "Mesa #{$o['mesa_id']}"
            : strtoupper($o['tipo']) . ' · ' . ($o['cliente_nombre'] ?? '?');
        log_action('send_kitchen', 'comanda', $comandas[0]['id'],
            "$destino · " . count($nuevos) . " items · $caso");

        // Impresión directa a la impresora de cada cocina (servidor → red).
        // Suprime errores: si una cocina está apagada, NO rompe el envío.
        $meseroNombre = current_user()['nombre'] ?? '';
        $impreso = [];
        foreach ($comandas as $cmd) {
            $cmd['mesero'] = $meseroNombre;
            $cmd['tipo']   = $o['tipo'];
            // Si se pidió enviar SIN imprimir, solo se registra (impreso = null).
            $impreso[$cmd['cocina']] = $sinImprimir ? null : print_comanda_red($cmd);
        }

        json_response([
            'ok'        => true,
            'caso'      => $caso, // 'solo C1' | 'solo C2' | 'ambas'
            'comandas'  => $comandas,
            'impreso'   => $impreso,
            'sin_imprimir' => $sinImprimir,
        ]);
    },

    /** Cobra la orden registrando uno o varios pagos (con propina opcional) */
    'pay' => function () {
        require_method('POST');
        // ERROR 002/004: solo caja y admin pueden cobrar. Los meseros NO cobran
        // (así se obliga a que la comanda pase por caja y quede registrada).
        api_require_role('admin','cajero');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        $pagos = $in['pagos'] ?? [];
        $propina = (float)($in['propina'] ?? 0);
        $propinaPct = (int)($in['propina_pct'] ?? 0);
        $sinTicket = !empty($in['sin_ticket']); // opción: cobrar sin imprimir ticket

        $pdo = db();
        $orden = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
        $orden->execute([$orden_id]);
        $o = $orden->fetch();
        if (!$o) json_response(['error' => 'Orden no encontrada'], 404);
        if ($o['estado'] !== 'abierta') json_response(['error' => 'Orden ya cerrada'], 400);

        $costo_envio = (float)($o['costo_envio'] ?? 0);
        $total = (float)$o['total'] + $propina + $costo_envio;
        $sum = 0;
        foreach ($pagos as $p) $sum += (float)($p['monto'] ?? 0);
        if ($sum + 0.01 < $total) {
            json_response(['error' => 'El monto pagado es menor al total', 'falta' => $total - $sum], 400);
        }

        $userId = current_user()['id'] ?? null;
        $turnoId = current_turno_id();
        // Compat hacia atrás con cortes_caja
        $corteId = null;
        $cs = $pdo->prepare("SELECT id FROM cortes_caja WHERE usuario_id=? AND estado='abierto' LIMIT 1");
        $cs->execute([$userId]);
        if ($r = $cs->fetch()) $corteId = (int)$r['id'];

        $pdo->beginTransaction();
        try {
            $metodos = $pdo->query("SELECT id, codigo FROM metodos_pago")->fetchAll(PDO::FETCH_KEY_PAIR);
            $metodosByCode = array_flip($metodos);
            $insPago = $pdo->prepare("INSERT INTO pagos (orden_id, metodo_id, monto, referencia, corte_id, turno_id) VALUES (?,?,?,?,?,?)");
            // Guardamos el monto APLICADO a la cuenta, NO lo que entregó el cliente:
            // si paga con $500 una cuenta de $355, se registra $355 (el cambio NO
            // es venta). Así los reportes y cortes no se inflan con el cambio.
            $restante = $total;
            foreach ($pagos as $p) {
                $codigo = $p['metodo'] ?? 'cash';
                $metodo_id = $metodosByCode[$codigo] ?? null;
                if (!$metodo_id) throw new Exception("Método inválido: $codigo");
                $aplicado = min((float)$p['monto'], $restante);
                if ($aplicado <= 0) continue; // todo este pago fue cambio
                $restante = round($restante - $aplicado, 2);
                $insPago->execute([$orden_id, $metodo_id, $aplicado, $p['referencia'] ?? null, $corteId, $turnoId]);
            }
            // Propina
            if ($propina > 0) {
                $pdo->prepare("INSERT INTO propinas (orden_id, mesero_id, monto, porcentaje, turno_id) VALUES (?,?,?,?,?)")
                    ->execute([$orden_id, $o['usuario_id'], $propina, $propinaPct, $turnoId]);
                $pdo->prepare("UPDATE ordenes SET propina=?, propina_pct=? WHERE id=?")
                    ->execute([$propina, $propinaPct, $orden_id]);
            }
            // Asociar la orden al turno de quien cobra (si no estaba)
            $pdo->prepare("UPDATE ordenes SET turno_id = COALESCE(turno_id, ?) WHERE id=?")
                ->execute([$turnoId, $orden_id]);

            // Actualizar info de cliente frecuente
            $pdo->prepare("
                UPDATE clientes
                SET total_pedidos = total_pedidos + 1,
                    total_gastado = total_gastado + ?,
                    last_order_at = NOW()
                WHERE id = (SELECT cliente_id FROM ordenes WHERE id=?)
            ")->execute([$total, $orden_id]);

            // Descontar stock SOLO para productos que manejan stock
            $itemsOrden = $pdo->prepare("
                SELECT oi.producto_id, SUM(oi.cantidad) AS qty, p.maneja_stock, p.stock
                FROM orden_items oi
                JOIN productos p ON p.id = oi.producto_id
                WHERE oi.orden_id = ?
                GROUP BY oi.producto_id
            ");
            $itemsOrden->execute([$orden_id]);
            $updStock = $pdo->prepare("UPDATE productos SET stock = GREATEST(0, stock - ?) WHERE id=? AND maneja_stock=1");
            $insMov = $pdo->prepare("INSERT INTO movimientos_stock (producto_id, tipo, cantidad, stock_resultante, motivo, usuario_id, orden_id) VALUES (?,?,?,?,?,?,?)");
            foreach ($itemsOrden->fetchAll() as $row) {
                if (!$row['maneja_stock']) continue; // <- saltamos los que no manejan stock
                $updStock->execute([$row['qty'], $row['producto_id']]);
                $stockResult = max(0, (int)$row['stock'] - (int)$row['qty']);
                $insMov->execute([$row['producto_id'], 'salida', $row['qty'], $stockResult, 'Venta orden #'.$orden_id, $userId, $orden_id]);
            }

            $pdo->prepare("UPDATE ordenes SET estado='cobrada', cerrada_at=NOW() WHERE id=?")->execute([$orden_id]);
            // Domicilio cobrado en caja: cerrar también la entrega para que NO se
            // quede atorado en 'en_camino'/'asignada' en el tablero de reparto.
            if (($o['tipo'] ?? '') === 'domicilio'
                && in_array($o['estado_entrega'] ?? '', ['pendiente','asignada','en_camino'], true)) {
                $pdo->prepare("UPDATE ordenes SET estado_entrega='entregada', entregada_at=COALESCE(entregada_at, NOW()) WHERE id=?")
                    ->execute([$orden_id]);
            }
            $pdo->prepare("UPDATE mesas SET estado='libre' WHERE id=?")->execute([$o['mesa_id']]);
            $pdo->commit();

            $metodos_usados = implode(', ', array_map(fn($p) => ($p['metodo']??'?') . ':$' . number_format((float)($p['monto']??0),2), $pagos));
            log_action('cobro', 'orden', $orden_id, "Total $" . number_format($total,2) . " · {$metodos_usados}" . ($propina>0?" · Propina $".number_format($propina,2):''));
        } catch (Throwable $e) {
            $pdo->rollBack();
            log_action('cobro_error', 'orden', $orden_id, "FALLÓ: " . $e->getMessage());
            json_response(['error' => $e->getMessage()], 500);
        }

        $items = $pdo->prepare("SELECT * FROM orden_items WHERE orden_id=? ORDER BY comensal, id");
        $items->execute([$orden_id]);

        // Etiqueta de mesa para el ticket (nombre personalizado o "Mesa 05")
        $mesaNumero = null; $mesaEtiqueta = null;
        if ($o['mesa_id']) {
            $mn = $pdo->prepare("SELECT numero, nombre FROM mesas WHERE id=?");
            $mn->execute([$o['mesa_id']]);
            if ($mrow = $mn->fetch()) {
                $mesaNumero = $mrow['numero'] ?: null;
                $mesaEtiqueta = function_exists('mesa_label') ? mesa_label($mrow) : ('Mesa ' . $mrow['numero']);
            }
        }

        $ticket = [
            'orden_id' => $orden_id,
            'mesa_id'  => $o['mesa_id'],
            'mesa_numero'   => $mesaNumero,
            'mesa_etiqueta' => $mesaEtiqueta,
            'cliente_nombre'=> $o['cliente_nombre'] ?? null,
            'subtotal' => (float)$o['subtotal'],
            'iva'      => (float)$o['iva'],
            'costo_envio' => (float)($o['costo_envio'] ?? 0),
            'propina'  => $propina,
            'total'    => $total,
            'pagado'   => $sum,
            'cambio'   => max(0, $sum - $total),
            'items'    => $items->fetchAll(),
            'pagos'    => $pagos,
            'fecha'    => date('Y-m-d H:i:s'),
        ];

        // Impresión directa del ticket a la impresora de caja (servidor → red).
        // Si se pidió cobrar SIN ticket, se omite (impreso = null).
        $ticket['impreso'] = $sinTicket ? null : print_ticket_red($ticket);

        json_response(['ok' => true, 'ticket' => $ticket, 'sin_ticket' => $sinTicket]);
    },

    /** Reimprime una comanda existente a la impresora de su cocina. */
    'reprint_comanda' => function () {
        require_method('POST');
        api_require_login();
        $id = (int)(json_input()['comanda_id'] ?? 0);
        if (!$id) json_response(['error' => 'comanda_id requerido'], 400);
        $pdo = db();
        $c = $pdo->prepare("
            SELECT c.*, m.numero AS mesa_numero, m.nombre AS mesa_nombre, m.zona, m.descripcion AS mesa_desc,
                   o.tipo AS orden_tipo, u.nombre AS mesero
            FROM comandas c
            LEFT JOIN mesas m ON m.id = c.mesa_id
            LEFT JOIN ordenes o ON o.id = c.orden_id
            LEFT JOIN usuarios u ON u.id = c.usuario_id
            WHERE c.id=?");
        $c->execute([$id]);
        $row = $c->fetch();
        if (!$row) json_response(['error' => 'Comanda no encontrada'], 404);
        if ((int)$row['cocina'] === 2) {
            // SOLO los items cocina_2 de ESA ronda (no de toda la mesa).
            $it = $pdo->prepare("SELECT oi.*, 1 AS cocina_2
                                 FROM orden_items oi
                                 JOIN productos p ON p.id=oi.producto_id
                                 JOIN comandas cc ON cc.id=oi.comanda_id
                                 WHERE cc.orden_id=? AND cc.ronda=? AND p.cocina_2=1
                                 ORDER BY oi.comensal, oi.id");
            $it->execute([$row['orden_id'], (int)($row['ronda'] ?? 1)]);
        } else {
            $it = $pdo->prepare("SELECT oi.*, COALESCE(p.cocina_2,0) AS cocina_2
                                 FROM orden_items oi JOIN productos p ON p.id=oi.producto_id
                                 WHERE oi.comanda_id=? ORDER BY oi.comensal, oi.id");
            $it->execute([$id]);
        }
        $tipoOrden = $row['orden_tipo'] ?? 'local';
        // Para llevar/domicilio en Cocina A: reimprimir como TICKET (con precios).
        $ordenInfo = null; $completa = false;
        if (in_array($tipoOrden, ['llevar','domicilio','mostrador'], true) && (int)$row['cocina'] === 1) {
            $od = $pdo->prepare("SELECT cliente_nombre,cliente_telefono,cliente_direccion,cliente_referencias,hora_pickup,subtotal,total,costo_envio,paga_con,web_metodo_pago FROM ordenes WHERE id=?");
            $od->execute([$row['orden_id']]);
            if ($orow = $od->fetch()) {
                $ordenInfo = [
                    'cliente_nombre'=>$orow['cliente_nombre'],'cliente_telefono'=>$orow['cliente_telefono'],
                    'cliente_direccion'=>$orow['cliente_direccion'],'cliente_referencias'=>$orow['cliente_referencias'],
                    'hora_pickup'=>$orow['hora_pickup'],
                    'subtotal'=>(float)$orow['subtotal'],'total'=>(float)$orow['total'],
                    'costo_envio'=>(float)$orow['costo_envio'],'paga_con'=>(float)$orow['paga_con'],
                    'metodo_pago'=>$orow['web_metodo_pago'],
                ];
                $completa = true;
            }
        }
        // Items de rondas anteriores (para "YA EN PREPARACION" en pick-up/domicilio)
        $itemsPrevios = [];
        if ($completa) {
            $pv = $pdo->prepare("SELECT oi.* FROM orden_items oi
                                 JOIN comandas cc ON cc.id=oi.comanda_id
                                 WHERE cc.orden_id=? AND cc.cocina=1 AND cc.ronda < ?
                                   AND COALESCE(oi.cantidad_cancelada,0) < oi.cantidad
                                 ORDER BY oi.comensal, oi.id");
            $pv->execute([$row['orden_id'], (int)($row['ronda'] ?? 1)]);
            $itemsPrevios = $pv->fetchAll();
        }
        $comanda = [
            'id'     => (int)$row['id'],
            'orden_id' => (int)$row['orden_id'],
            'ronda'  => (int)($row['ronda'] ?? 1),
            'cocina' => (int)$row['cocina'],
            'mesa'   => ['numero'=>$row['mesa_numero'], 'nombre'=>$row['mesa_nombre'], 'zona'=>$row['zona'], 'descripcion'=>$row['mesa_desc']],
            'tipo'   => $tipoOrden,
            'mesero' => $row['mesero'] ?? '',
            'items'  => $it->fetchAll(),
            'items_previos' => $itemsPrevios,
            'created'=> date('H:i', strtotime($row['created_at'])),
            'orden'  => $ordenInfo,
            'completa' => $completa,
        ];
        json_response(['ok' => true, 'impreso' => print_comanda_red($comanda)]);
    },

    /** Lista las comandas de una orden (para poder reimprimirlas). */
    'orden_comandas' => function () {
        api_require_login();
        $orden_id = (int)($_GET['orden_id'] ?? 0);
        if (!$orden_id) json_response(['error' => 'orden_id requerido'], 400);
        $pdo = db();
        $mr = $pdo->prepare("SELECT COALESCE(MAX(ronda),0) FROM comandas WHERE orden_id=?");
        $mr->execute([$orden_id]);
        $ultima = (int)$mr->fetchColumn();
        $cs = $pdo->prepare("SELECT id, cocina, ronda FROM comandas WHERE orden_id=? ORDER BY ronda, cocina");
        $cs->execute([$orden_id]);
        json_response(['ultima_ronda' => $ultima, 'comandas' => $cs->fetchAll()]);
    },

    /** Reimprime el ticket de una orden ya cobrada a la impresora de caja. */
    'reprint_ticket' => function () {
        require_method('POST');
        api_require_login();
        $orden_id = (int)(json_input()['orden_id'] ?? 0);
        if (!$orden_id) json_response(['error' => 'orden_id requerido'], 400);
        $pdo = db();
        $o = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
        $o->execute([$orden_id]);
        $ord = $o->fetch();
        if (!$ord) json_response(['error' => 'Orden no encontrada'], 404);
        $items = $pdo->prepare("SELECT * FROM orden_items WHERE orden_id=? ORDER BY comensal, id");
        $items->execute([$orden_id]);
        $mesaNum = null; $mesaEtiqueta = null;
        if ($ord['mesa_id']) {
            $mn = $pdo->prepare("SELECT numero, nombre FROM mesas WHERE id=?");
            $mn->execute([$ord['mesa_id']]);
            if ($mrow = $mn->fetch()) { $mesaNum = $mrow['numero'] ?: null; $mesaEtiqueta = function_exists('mesa_label') ? mesa_label($mrow) : ('Mesa '.$mrow['numero']); }
        }
        $pg = $pdo->prepare("SELECT mp.codigo AS metodo, pa.monto FROM pagos pa JOIN metodos_pago mp ON mp.id=pa.metodo_id WHERE pa.orden_id=?");
        $pg->execute([$orden_id]);
        $ticket = [
            'orden_id' => $orden_id, 'mesa_id' => $ord['mesa_id'],
            'mesa_numero' => $mesaNum, 'mesa_etiqueta' => $mesaEtiqueta,
            'cliente_nombre' => $ord['cliente_nombre'] ?? null,
            'subtotal' => (float)$ord['subtotal'], 'iva' => (float)$ord['iva'],
            'costo_envio' => (float)($ord['costo_envio'] ?? 0), 'propina' => (float)($ord['propina'] ?? 0),
            'total' => (float)$ord['total'] + (float)($ord['propina'] ?? 0) + (float)($ord['costo_envio'] ?? 0),
            'items' => $items->fetchAll(), 'pagos' => $pg->fetchAll(),
            'fecha' => $ord['cerrada_at'] ?? date('Y-m-d H:i:s'),
        ];
        json_response(['ok' => true, 'impreso' => print_ticket_red($ticket)]);
    },

    /** Resumen de la orden agrupado por comensal para split */
    'split_by_customer' => function () {
        $orden_id = (int)($_GET['orden_id'] ?? 0);
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT comensal, nombre, precio, COALESCE(precio_extra,0) AS precio_extra,
                   GREATEST(cantidad - COALESCE(cantidad_cancelada,0), 0) AS cantidad,
                   ((precio + COALESCE(precio_extra,0)) * GREATEST(cantidad - COALESCE(cantidad_cancelada,0), 0)) AS total
            FROM orden_items WHERE orden_id=? ORDER BY comensal, id");
        $stmt->execute([$orden_id]);
        $items = $stmt->fetchAll();

        $grupos = [];
        foreach ($items as $it) {
            if ((int)$it['cantidad'] <= 0) continue; // línea totalmente cancelada → no entra al split
            $c = (int)$it['comensal'];
            if (!isset($grupos[$c])) $grupos[$c] = ['comensal' => $c, 'items' => [], 'subtotal' => 0];
            $grupos[$c]['items'][] = $it;
            $grupos[$c]['subtotal'] += (float)$it['total'];
        }
        // Agregar IVA por comensal
        foreach ($grupos as &$g) {
            $g['iva']   = round($g['subtotal'] * TAX_RATE, 2);
            $g['total'] = round($g['subtotal'] + $g['iva'], 2);
        }
        json_response(array_values($grupos));
    },

    /**
     * Crea una nueva orden de tipo 'llevar' o 'domicilio' (no usa mesa)
     */
    'create_takeout' => function () {
        require_method('POST');
        require_jornada_abierta();
        $in = json_input();
        $tipo = $in['tipo'] ?? 'llevar';
        if (!in_array($tipo, ['llevar','domicilio'])) {
            json_response(['error' => 'Tipo inválido'], 400);
        }
        $nombre = trim($in['cliente_nombre'] ?? '');
        $tel    = trim($in['cliente_telefono'] ?? '');
        $direccion = trim($in['cliente_direccion'] ?? '');
        $referencias = trim($in['cliente_referencias'] ?? '');
        $repartidor_id = (int)($in['repartidor_id'] ?? 0) ?: null;
        $costo_envio = (float)($in['costo_envio'] ?? 0);
        $hora_pickup = $in['hora_pickup'] ?? null;
        $metodo_pago = trim((string)($in['metodo_pago'] ?? '')) ?: null; // efectivo|tarjeta|transferencia

        if (!$nombre) json_response(['error' => 'El nombre del cliente es obligatorio'], 400);
        if ($tipo === 'domicilio') {
            if (!$tel) json_response(['error' => 'El teléfono es obligatorio para domicilio'], 400);
            if (!$direccion) json_response(['error' => 'La dirección es obligatoria para domicilio'], 400);
            // El envío se define manualmente; no se permite domicilio sin costo de envío
            if ($costo_envio <= 0) json_response(['error' => 'Indica el costo de envío (no puede ir en $0 un pedido a domicilio)'], 400);
        }

        $pdo = db();
        $u = current_user();

        // Buscar o crear cliente si dio teléfono
        $cliente_id = null;
        if ($tel) {
            $s = $pdo->prepare("SELECT id FROM clientes WHERE telefono=?");
            $s->execute([$tel]);
            $cliente_id = (int)($s->fetchColumn() ?: 0) ?: null;
            if (!$cliente_id) {
                $pdo->prepare("INSERT INTO clientes (telefono, nombre, direccion, referencias) VALUES (?,?,?,?)")
                    ->execute([$tel, $nombre, $direccion ?: null, $referencias ?: null]);
                $cliente_id = (int)$pdo->lastInsertId();
                log_action('cliente_auto', 'cliente', $cliente_id, "Auto al levantar pedido");
            }
        }

        $estado_entrega = $tipo === 'domicilio'
            ? ($repartidor_id ? 'asignada' : 'pendiente')
            : null;

        $turno_id = current_turno_id();

        $pdo->prepare("
            INSERT INTO ordenes
              (mesa_id, tipo, cliente_id, cliente_nombre, cliente_telefono,
               cliente_direccion, cliente_referencias, repartidor_id,
               costo_envio, estado_entrega, hora_pickup, web_metodo_pago,
               usuario_id, turno_id)
            VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$tipo, $cliente_id, $nombre, $tel ?: null,
                     $direccion ?: null, $referencias ?: null, $repartidor_id,
                     $costo_envio, $estado_entrega, $hora_pickup ?: null, $metodo_pago,
                     $u['id'], $turno_id]);

        $orden_id = (int)$pdo->lastInsertId();
        log_action('orden_create', 'orden', $orden_id, strtoupper($tipo) . " · $nombre");
        json_response(['ok' => true, 'orden_id' => $orden_id]);
    },

    /**
     * Venta de mostrador / venta rápida: crea una orden tipo 'mostrador'
     * sin mesa ni cliente. No pasa por cocina; se cobra directo.
     * Ej.: alguien solo compra aguas de sabor y se va.
     */
    'create_mostrador' => function () {
        require_method('POST');
        // ERROR 005: la venta rápida (mostrador) es solo para caja y admin.
        api_require_role('admin','cajero');
        require_jornada_abierta();
        $u = current_user();
        $turno_id = current_turno_id();
        $pdo = db();
        $pdo->prepare("
            INSERT INTO ordenes (mesa_id, tipo, cliente_nombre, usuario_id, turno_id, estado)
            VALUES (NULL, 'mostrador', 'Mostrador', ?, ?, 'abierta')
        ")->execute([$u['id'], $turno_id]);
        $orden_id = (int)$pdo->lastInsertId();
        log_action('orden_create', 'orden', $orden_id, 'MOSTRADOR · venta rápida');
        json_response(['ok' => true, 'orden_id' => $orden_id]);
    },

    /** Quitar promoción aplicada a una orden */
    'quitar_promo' => function () {
        require_method('POST');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        $pdo = db();
        $stmt = $pdo->prepare("SELECT promocion_id, descuento FROM ordenes WHERE id=?");
        $stmt->execute([$orden_id]);
        $o = $stmt->fetch();
        if (!$o) json_response(['error' => 'Orden no encontrada'], 404);

        // Si tenía uso contabilizado, retrocederlo
        if ($o['promocion_id']) {
            $pdo->prepare("UPDATE promociones SET uso_actual = GREATEST(0, uso_actual - 1) WHERE id=?")
                ->execute([$o['promocion_id']]);
        }
        $pdo->prepare("UPDATE ordenes SET promocion_id=NULL, descuento=0 WHERE id=?")
            ->execute([$orden_id]);
        recalc_order_totals($orden_id);
        log_action('promo_quitar', 'orden', $orden_id, "");
        json_response(['ok' => true]);
    },

    /**
     * Cambia el tipo de pedido (local / llevar / domicilio).
     * Caso típico:
     *   - Cliente pidió domicilio pero ahora va a pasar a recoger → domicilio→llevar
     *   - Cliente iba a recoger pero ahora quiere domicilio → llevar→domicilio
     *   - Cliente estaba en mesa pero quiere llevar el resto → local→llevar/domicilio
     *   - Cliente iba a recoger pero llegó al local → llevar→local (asignar mesa)
     *
     * Reglas:
     *   - No permitido si la orden ya fue cobrada o cancelada
     *   - No permitido si el repartidor ya está en_camino o entregada
     *   - Al cambiar de domicilio → reset envío/repartidor/dirección
     *   - Al cambiar de local → liberar mesa anterior
     *   - Al cambiar a local → asignar y ocupar mesa
     *   - Al cambiar a domicilio → requerir dirección
     *   - Recalcula totales
     */
    'cambiar_tipo' => function () {
        require_method('POST');
        api_require_role('admin','encargado','cajero','mesero');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        $nuevo_tipo = $in['tipo'] ?? '';
        if (!in_array($nuevo_tipo, ['local','llevar','domicilio'])) {
            json_response(['error' => 'Tipo inválido'], 400);
        }

        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
        $stmt->execute([$orden_id]);
        $o = $stmt->fetch();
        if (!$o) json_response(['error' => 'Orden no encontrada'], 404);
        if ($o['estado'] === 'cobrada')   json_response(['error' => 'No se puede cambiar una orden cobrada'], 400);
        if ($o['estado'] === 'cancelada') json_response(['error' => 'No se puede cambiar una orden cancelada'], 400);
        if ($o['tipo'] === $nuevo_tipo)   json_response(['ok' => true, 'msg' => 'Ya es ese tipo']);

        // Si era domicilio en ruta, bloquear
        if ($o['tipo'] === 'domicilio' && in_array($o['estado_entrega'] ?? '', ['en_camino','entregada'])) {
            json_response(['error' => 'No se puede cambiar: el pedido ya está en camino o entregado'], 400);
        }

        $tipo_anterior = $o['tipo'];
        $mesa_anterior = $o['mesa_id'];

        if ($nuevo_tipo === 'local') {
            $mesa_id = (int)($in['mesa_id'] ?? 0);
            if (!$mesa_id) json_response(['error' => 'Selecciona una mesa'], 400);
            $m = $pdo->prepare("SELECT estado FROM mesas WHERE id=? AND activa=1");
            $m->execute([$mesa_id]);
            $estadoMesa = $m->fetchColumn();
            if (!$estadoMesa)         json_response(['error' => 'Mesa no encontrada'], 404);
            if ($estadoMesa !== 'libre') json_response(['error' => "Mesa no disponible (está $estadoMesa)"], 400);

            if ($mesa_anterior) $pdo->prepare("UPDATE mesas SET estado='libre' WHERE id=?")->execute([$mesa_anterior]);
            $pdo->prepare("UPDATE ordenes SET
                tipo='local', mesa_id=?,
                cliente_id=NULL, cliente_nombre=NULL, cliente_telefono=NULL,
                cliente_direccion=NULL, cliente_referencias=NULL,
                repartidor_id=NULL, costo_envio=0, estado_entrega=NULL, hora_pickup=NULL
              WHERE id=?")->execute([$mesa_id, $orden_id]);
            $pdo->prepare("UPDATE mesas SET estado='ocupada' WHERE id=?")->execute([$mesa_id]);
        }
        elseif ($nuevo_tipo === 'llevar') {
            $nombre = trim($in['cliente_nombre'] ?? $o['cliente_nombre'] ?? '');
            $tel    = trim($in['cliente_telefono'] ?? $o['cliente_telefono'] ?? '');
            if (!$nombre) json_response(['error' => 'Nombre del cliente requerido'], 400);

            if ($mesa_anterior) $pdo->prepare("UPDATE mesas SET estado='libre' WHERE id=?")->execute([$mesa_anterior]);
            $pdo->prepare("UPDATE ordenes SET
                tipo='llevar', mesa_id=NULL,
                cliente_nombre=?, cliente_telefono=?,
                cliente_direccion=NULL, cliente_referencias=NULL,
                repartidor_id=NULL, costo_envio=0, estado_entrega=NULL
              WHERE id=?")->execute([$nombre, $tel ?: null, $orden_id]);
        }
        elseif ($nuevo_tipo === 'domicilio') {
            $nombre = trim($in['cliente_nombre'] ?? $o['cliente_nombre'] ?? '');
            $tel    = trim($in['cliente_telefono'] ?? $o['cliente_telefono'] ?? '');
            $dir    = trim($in['cliente_direccion'] ?? '');
            $ref    = trim($in['cliente_referencias'] ?? '');
            $envio  = (float)($in['costo_envio'] ?? cfg('costo_envio_default', 30));
            $rep_id = (int)($in['repartidor_id'] ?? 0) ?: null;

            if (!$nombre) json_response(['error' => 'Nombre del cliente requerido'], 400);
            if (!$tel)    json_response(['error' => 'Teléfono requerido para domicilio'], 400);
            if (!$dir)    json_response(['error' => 'Dirección requerida para domicilio'], 400);

            // Buscar/crear cliente
            $cs = $pdo->prepare("SELECT id FROM clientes WHERE telefono=?");
            $cs->execute([$tel]);
            $cliente_id = (int)($cs->fetchColumn() ?: 0) ?: null;
            if (!$cliente_id) {
                $pdo->prepare("INSERT INTO clientes (telefono, nombre, direccion, referencias) VALUES (?,?,?,?)")
                    ->execute([$tel, $nombre, $dir, $ref ?: null]);
                $cliente_id = (int)$pdo->lastInsertId();
            }

            if ($mesa_anterior) $pdo->prepare("UPDATE mesas SET estado='libre' WHERE id=?")->execute([$mesa_anterior]);
            $estado_entrega = $rep_id ? 'asignada' : 'pendiente';
            $pdo->prepare("UPDATE ordenes SET
                tipo='domicilio', mesa_id=NULL,
                cliente_id=?, cliente_nombre=?, cliente_telefono=?,
                cliente_direccion=?, cliente_referencias=?,
                repartidor_id=?, costo_envio=?, estado_entrega=?
              WHERE id=?")->execute([
                  $cliente_id, $nombre, $tel, $dir, $ref ?: null,
                  $rep_id, $envio, $estado_entrega, $orden_id
              ]);
        }

        // Mantener las comandas consistentes con la mesa (o sin mesa) de la orden.
        $pdo->prepare("UPDATE comandas SET mesa_id=(SELECT mesa_id FROM ordenes WHERE id=?) WHERE orden_id=?")
            ->execute([$orden_id, $orden_id]);

        recalc_order_totals($orden_id);
        log_action('orden_cambiar_tipo', 'orden', $orden_id,
            "De " . strtoupper($tipo_anterior) . " → " . strtoupper($nuevo_tipo));
        @touch(__DIR__ . '/../../config/.sse-trigger');
        json_response(['ok' => true]);
    },

    /**
     * Mueve toda la cuenta (orden + comandas) de una mesa a otra mesa libre.
     * Ej.: pareja pidió en mesa 2 (interior) y se cambia a la terraza.
     */
    'mover_mesa' => function () {
        require_method('POST');
        api_require_role('admin','encargado','cajero','mesero');
        $in = json_input();
        $orden_id     = (int)($in['orden_id'] ?? 0);
        $mesa_destino = (int)($in['mesa_id'] ?? 0);

        $pdo = db();
        $o = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
        $o->execute([$orden_id]);
        $orden = $o->fetch();
        if (!$orden) json_response(['error' => 'Orden no encontrada'], 404);
        if ($orden['estado'] !== 'abierta') json_response(['error' => 'La cuenta ya está cerrada'], 400);
        if ($orden['tipo'] !== 'local')     json_response(['error' => 'Solo aplica a pedidos en mesa'], 400);
        if (!$mesa_destino)                 json_response(['error' => 'Selecciona la mesa destino'], 400);
        if ($mesa_destino == (int)$orden['mesa_id']) json_response(['error' => 'Es la misma mesa'], 400);

        $md = $pdo->prepare("SELECT numero, estado FROM mesas WHERE id=? AND activa=1");
        $md->execute([$mesa_destino]);
        $dest = $md->fetch();
        if (!$dest) json_response(['error' => 'Mesa destino no encontrada'], 404);
        if ($dest['estado'] !== 'libre') {
            json_response(['error' => "La mesa {$dest['numero']} no está libre (está {$dest['estado']})"], 400);
        }

        $mesa_anterior = (int)$orden['mesa_id'];
        $estAnt = 'ocupada';
        if ($mesa_anterior) {
            $ea = $pdo->prepare("SELECT estado FROM mesas WHERE id=?");
            $ea->execute([$mesa_anterior]);
            $estAnt = $ea->fetchColumn() ?: 'ocupada';
        }
        // El destino hereda el estado de la mesa anterior (ocupada o por_cobrar)
        $estDest = in_array($estAnt, ['ocupada','por_cobrar']) ? $estAnt : 'ocupada';

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE ordenes  SET mesa_id=? WHERE id=?")->execute([$mesa_destino, $orden_id]);
            $pdo->prepare("UPDATE comandas SET mesa_id=? WHERE orden_id=?")->execute([$mesa_destino, $orden_id]);
            $pdo->prepare("UPDATE mesas SET estado=? WHERE id=?")->execute([$estDest, $mesa_destino]);
            if ($mesa_anterior) $pdo->prepare("UPDATE mesas SET estado='libre' WHERE id=?")->execute([$mesa_anterior]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['error' => 'No se pudo mover la cuenta: ' . $e->getMessage()], 500);
        }

        log_action('mover_mesa', 'orden', $orden_id, "Mesa $mesa_anterior → mesa {$dest['numero']}");
        @touch(__DIR__ . '/../../config/.sse-trigger');
        json_response(['ok' => true, 'orden_id' => $orden_id, 'mesa_destino' => $mesa_destino]);
    },

    /** Marcar mesa para cobro */
    'request_bill' => function () {
        require_method('POST');
        $in = json_input();
        $mesa_id = (int)($in['mesa_id'] ?? 0);
        db()->prepare("UPDATE mesas SET estado='por_cobrar' WHERE id=?")->execute([$mesa_id]);
        json_response(['ok' => true]);
    },

    /**
     * ERROR 001: Libera una mesa que quedó "ocupada" pero SIN productos (se
     * agregaron y luego se quitaron todos). Solo libera si no hay una cuenta
     * abierta con productos; las órdenes abiertas vacías se cierran.
     */
    'liberar_mesa' => function () {
        require_method('POST');
        api_require_role('admin','cajero','mesero');
        $in = json_input();
        $mesa_id = (int)($in['mesa_id'] ?? 0);
        if (!$mesa_id) json_response(['error' => 'mesa_id requerido'], 400);
        $pdo = db();

        $conItems = $pdo->prepare("
            SELECT COUNT(*) FROM ordenes o
            WHERE o.mesa_id=? AND o.estado='abierta'
              AND (SELECT COALESCE(SUM(GREATEST(cantidad - COALESCE(cantidad_cancelada,0),0)),0)
                   FROM orden_items WHERE orden_id=o.id) > 0");
        $conItems->execute([$mesa_id]);
        if ((int)$conItems->fetchColumn() > 0) {
            json_response(['error' => 'La mesa tiene una cuenta con productos. Cóbrala o cancélala primero.'], 400);
        }

        $pdo->prepare("DELETE FROM comandas WHERE orden_id IN (SELECT id FROM ordenes WHERE mesa_id=? AND estado='abierta')")->execute([$mesa_id]);
        $pdo->prepare("DELETE FROM ordenes WHERE mesa_id=? AND estado='abierta'")->execute([$mesa_id]);
        $pdo->prepare("UPDATE mesas SET estado='libre' WHERE id=?")->execute([$mesa_id]);
        log_action('liberar_mesa', 'mesa', $mesa_id, 'Mesa liberada (estaba vacía)');
        @touch(__DIR__ . '/../../config/.sse-trigger');
        json_response(['ok' => true]);
    },

    /**
     * Cancelar un item (o parte de su cantidad) con motivo. NUNCA se borra:
     * queda registrado para que aparezca tachado en el ticket y se descuente
     * del total. Se puede cancelar en CUALQUIER estado de la orden (incluso
     * entregada o cobrada) — útil cuando el cliente devuelve algo al final.
     *
     * Parámetros: item_id, motivo, cantidad (opcional; default = lo que reste).
     *
     * Reglas de autorización:
     *  - Item NO enviado a cocina: cualquier usuario logueado puede cancelarlo.
     *  - Item YA enviado a cocina / orden ya cobrada: solo ADMIN, o credenciales
     *    de admin (admin_user + admin_pass) para autorizar.
     */
    'cancel_item' => function () {
        require_method('POST');
        $u = api_require_login();
        $in = json_input();
        $item_id = (int)($in['item_id'] ?? 0);
        $motivo = trim($in['motivo'] ?? '');
        if (strlen($motivo) < 3) json_response(['error'=>'Motivo requerido (mín. 3 caracteres)'], 400);

        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM orden_items WHERE id=?");
        $stmt->execute([$item_id]);
        $it = $stmt->fetch();
        if (!$it) json_response(['error'=>'Item no encontrado'], 404);

        $yaCancelada = (int)($it['cantidad_cancelada'] ?? 0);
        $disponible  = (int)$it['cantidad'] - $yaCancelada;
        if ($disponible <= 0) json_response(['error'=>'Este producto ya está cancelado por completo'], 400);

        // Cantidad a cancelar (default: todo lo que reste)
        $cancelarQty = (int)($in['cantidad'] ?? $disponible);
        $cancelarQty = max(1, min($cancelarQty, $disponible));

        // ¿La orden ya fue cobrada? → tratamos como acción sensible
        $oStmt = $pdo->prepare("SELECT estado FROM ordenes WHERE id=?");
        $oStmt->execute([$it['orden_id']]);
        $estadoOrden = $oStmt->fetchColumn();
        $accionSensible = $it['enviado'] || $estadoOrden === 'cobrada';

        $autorizado_por = $u['id'];
        $autorizado_nombre = $u['nombre'];

        if ($accionSensible && !in_array($u['rol'], ['admin','encargado'])) {
            $admin_user = trim($in['admin_user'] ?? '');
            $admin_pass = $in['admin_pass'] ?? '';
            if (!$admin_user || !$admin_pass) {
                json_response([
                    'error' => $estadoOrden === 'cobrada'
                        ? 'La cuenta ya fue cobrada. Requiere autorización del administrador.'
                        : 'Este platillo ya está en cocina. Requiere autorización del administrador.',
                    'requires_admin' => true,
                ], 403);
            }
            $adm = $pdo->prepare("SELECT id, nombre, password_hash FROM usuarios
                                  WHERE usuario=? AND rol IN ('admin','encargado') AND activo=1");
            $adm->execute([$admin_user]);
            $a = $adm->fetch();
            if (!$a || !password_verify($admin_pass, $a['password_hash'])) {
                log_action('cancel_intento_fallido', 'orden_item', $item_id,
                    "Usuario {$u['nombre']} intentó cancelar con admin '$admin_user' incorrecto");
                json_response(['error' => 'Credenciales de admin incorrectas'], 403);
            }
            $autorizado_por = (int)$a['id'];
            $autorizado_nombre = $a['nombre'];
        }

        $nuevaCancelada = $yaCancelada + $cancelarQty;
        $full = $nuevaCancelada >= (int)$it['cantidad'] ? 1 : 0;
        $pdo->prepare("UPDATE orden_items
                       SET cantidad_cancelada=?, cancelado=?, motivo_cancel=?,
                           cancelado_at=NOW(), cancelado_por=?
                       WHERE id=?")
            ->execute([$nuevaCancelada, $full, $motivo, $autorizado_por, $item_id]);

        recalc_order_totals($it['orden_id']);

        // ERROR 001: si la orden quedó SIN productos activos, liberar la mesa
        // (evita que una mesa se quede "ocupada" sin nada agregado).
        $quedan = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(cantidad - COALESCE(cantidad_cancelada,0),0)),0)
                                 FROM orden_items WHERE orden_id=?");
        $quedan->execute([$it['orden_id']]);
        if ((int)$quedan->fetchColumn() === 0) {
            $pdo->prepare("UPDATE mesas SET estado='libre'
                           WHERE id=(SELECT mesa_id FROM ordenes WHERE id=? AND estado='abierta' AND tipo='local')")
                ->execute([$it['orden_id']]);
            @touch(__DIR__ . '/../../config/.sse-trigger');
        }

        // Si la orden ya estaba cobrada, recalc bajó el total → reflejar como ajuste
        $autorizadoMsg = ($autorizado_por != $u['id'])
            ? " · Autorizado por admin {$autorizado_nombre}"
            : "";
        log_action('cancel_item', 'orden_item', $item_id,
            "{$cancelarQty}× {$it['nombre']} · Motivo: $motivo · Solicitó {$u['nombre']}$autorizadoMsg"
            . ($estadoOrden === 'cobrada' ? " · ORDEN YA COBRADA" : ""));

        json_response(['ok'=>true, 'cancelado_qty'=>$cancelarQty, 'orden_id'=>(int)$it['orden_id']]);
    },

    /** Cancelar orden completa con motivo (libera mesa) */
    'cancel_order' => function () {
        require_method('POST');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        $motivo = trim($in['motivo'] ?? '');
        if (strlen($motivo) < 3) json_response(['error'=>'Motivo requerido'], 400);
        // sólo admin/cajero
        api_require_role('admin','encargado','cajero');
        $pdo = db();
        $orden = $pdo->prepare("SELECT * FROM ordenes WHERE id=?");
        $orden->execute([$orden_id]);
        $o = $orden->fetch();
        if (!$o) json_response(['error'=>'Orden no encontrada'], 404);
        $pdo->prepare("UPDATE ordenes SET estado='cancelada', motivo_cancelacion=?, cerrada_at=NOW() WHERE id=?")
            ->execute([$motivo, $orden_id]);
        $pdo->prepare("UPDATE mesas SET estado='libre' WHERE id=?")->execute([$o['mesa_id']]);
        log_action('cancel_order', 'orden', $orden_id, "Motivo: $motivo");
        json_response(['ok'=>true]);
    },
];
