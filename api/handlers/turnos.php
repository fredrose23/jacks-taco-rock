<?php
/**
 * Turnos = cortes manuales por usuario
 *
 * El usuario abre su turno al llegar (con fondo inicial si aplica) y lo cierra
 * al terminar. NO HAY scheduling pre-programado por admin — el horario
 * laboral se configura por usuario (usuarios.horario_*) y es solo para
 * restringir acceso al login, no para crear turnos.
 */

return [
    /**
     * Mi turno actual + métricas en vivo
     */
    'mio' => function () {
        $u = current_user();
        $stmt = db()->prepare("
            SELECT * FROM turnos
            WHERE usuario_id = ? AND estado = 'en_curso'
            ORDER BY abierto_at DESC
            LIMIT 1
        ");
        $stmt->execute([$u['id']]);
        $turno = $stmt->fetch();
        if (!$turno) json_response(['turno' => null]);

        $metrics = _turno_metrics((int)$turno['id']);
        json_response(['turno' => $turno, 'metrics' => $metrics]);
    },

    /**
     * Abrir turno (lo llama el usuario cuando llega a trabajar)
     */
    'abrir' => function () {
        require_method('POST');
        require_jornada_abierta();   // nadie inicia turno si el negocio no está abierto
        $in = json_input();
        $fondo = (float)($in['fondo_inicial'] ?? 0);
        $u = current_user();
        $pdo = db();

        // Si ya tiene uno abierto, devolverlo
        $stmt = $pdo->prepare("SELECT id FROM turnos WHERE usuario_id=? AND estado='en_curso' LIMIT 1");
        $stmt->execute([$u['id']]);
        if ($existing = $stmt->fetchColumn()) {
            json_response(['ok' => true, 'id' => (int)$existing, 'msg' => 'Ya estaba abierto']);
        }

        $pdo->prepare("
            INSERT INTO turnos
              (usuario_id, fecha, hora_inicio, hora_fin, estado,
               abierto_at, fondo_inicial)
            VALUES (?, CURDATE(), CURTIME(), CURTIME(), 'en_curso', NOW(), ?)
        ")->execute([$u['id'], $fondo]);

        $id = (int)$pdo->lastInsertId();
        log_action('turno_abrir', 'turno', $id, "Fondo inicial: $fondo");
        json_response(['ok' => true, 'id' => $id]);
    },

    /**
     * Cerrar turno → genera corte definitivo
     */
    'cerrar' => function () {
        require_method('POST');
        $in = json_input();
        $u = current_user();
        $pdo = db();

        $stmt = $pdo->prepare("SELECT * FROM turnos WHERE usuario_id=? AND estado='en_curso' LIMIT 1");
        $stmt->execute([$u['id']]);
        $t = $stmt->fetch();
        if (!$t) json_response(['error' => 'No tienes turno abierto'], 400);

        $efectivo_contado = (float)($in['efectivo_contado'] ?? 0);
        $observaciones = trim($in['observaciones'] ?? '');

        $m = _turno_metrics((int)$t['id']);
        $esperado = (float)$t['fondo_inicial'] + $m['efectivo'];
        $diff = round($efectivo_contado - $esperado, 2);

        $pdo->prepare("UPDATE turnos SET
            estado='cerrado',
            cerrado_at=NOW(),
            hora_fin=CURTIME(),
            efectivo_esperado=?, efectivo_contado=?, diferencia=?,
            total_tarjeta=?, total_transfer=?, total_otros=?,
            total_ventas=?, total_propinas=?, total_envios=?,
            num_ordenes=?, observaciones_cierre=?
          WHERE id=?")
          ->execute([
              $esperado, $efectivo_contado, $diff,
              $m['tarjeta'], $m['transfer'], $m['otros'],
              $m['ventas'], $m['propinas'], $m['envios'],
              $m['num_ordenes'], $observaciones, $t['id']
          ]);

        log_action('turno_cerrar', 'turno', (int)$t['id'],
            "Diferencia: " . number_format($diff, 2));

        json_response([
            'ok' => true,
            'corte' => array_merge($m, [
                'esperado' => $esperado,
                'contado'  => $efectivo_contado,
                'diferencia' => $diff,
            ])
        ]);
    },

    /**
     * Detalle de un corte (para reimprimir o revisar)
     */
    'detalle' => function () {
        $id = (int)($_GET['id'] ?? 0);
        $u = current_user();
        $pdo = db();
        $stmt = $pdo->prepare("SELECT t.*, u.nombre AS usuario, u.rol FROM turnos t JOIN usuarios u ON u.id=t.usuario_id WHERE t.id=?");
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if (!$t) json_response(['error' => 'No encontrado'], 404);
        if ($t['usuario_id'] != $u['id'] && $u['rol'] !== 'admin') {
            json_response(['error' => 'No autorizado'], 403);
        }

        $ord = $pdo->prepare("
            SELECT o.id, o.tipo, o.mesa_id, m.numero AS mesa_numero,
                   o.cliente_nombre, o.total, o.cerrada_at,
                   GROUP_CONCAT(CONCAT(mp.codigo,':',p.monto) SEPARATOR ' · ') AS pagos
            FROM ordenes o
            LEFT JOIN mesas m ON m.id = o.mesa_id
            LEFT JOIN pagos p ON p.orden_id = o.id
            LEFT JOIN metodos_pago mp ON mp.id = p.metodo_id
            WHERE o.turno_id = ? AND o.estado = 'cobrada'
            GROUP BY o.id
            ORDER BY o.cerrada_at DESC
        ");
        $ord->execute([$id]);
        $t['ordenes'] = $ord->fetchAll();
        json_response($t);
    },

    /**
     * CIERRE DE DÍA (solo admin): cierra TODOS los turnos abiertos de todos los
     * usuarios (calculando sus totales), libera todas las mesas, finaliza las
     * comandas de cocina pendientes y deja el sistema en cero para el día
     * siguiente.
     *
     * Si hay órdenes abiertas SIN cobrar, primero devuelve la lista para que el
     * admin confirme (requiere_confirmacion). Con forzar=true, esas órdenes se
     * cancelan (motivo "Cierre de día").
     */
    'cierre_dia' => function () {
        require_method('POST');
        api_require_role('admin','encargado');
        $in = json_input();
        $forzar = !empty($in['forzar']);
        $pdo = db();

        // Efectivo total contado (opcional) para conciliación global del día
        $efectivoContado = isset($in['efectivo_contado']) && $in['efectivo_contado'] !== ''
            ? (float)$in['efectivo_contado'] : null;

        // Órdenes abiertas sin cobrar (se perderían al cerrar el día)
        $abiertas = $pdo->query("
            SELECT o.id, o.tipo, o.total, o.estado_web, o.estado_entrega,
                   o.cliente_nombre, o.repartidor_id, m.numero AS mesa_numero
            FROM ordenes o LEFT JOIN mesas m ON m.id = o.mesa_id
            WHERE o.estado = 'abierta'
            ORDER BY o.id
        ")->fetchAll();

        // #3 — Pedidos con repartidor EN RUTA (alguien todavía en la calle)
        $enRuta = array_values(array_filter($abiertas, fn($o) => ($o['estado_entrega'] ?? '') === 'en_camino'));

        if ($abiertas && !$forzar) {
            // Pedir confirmación explícita antes de cancelarlas
            json_response([
                'requiere_confirmacion' => true,
                'n_abiertas' => count($abiertas),
                'ordenes_abiertas' => $abiertas,
                'n_en_ruta'  => count($enRuta),
                'en_ruta'    => $enRuta,
            ]);
        }

        $turnos = $pdo->query("SELECT * FROM turnos WHERE estado='en_curso'")->fetchAll();

        $esperadoTotal = 0;
        $pdo->beginTransaction();
        try {
            // 1) Cerrar todos los turnos abiertos con sus totales
            $upd = $pdo->prepare("UPDATE turnos SET
                estado='cerrado', cerrado_at=NOW(), hora_fin=CURTIME(),
                efectivo_esperado=?, efectivo_contado=?, diferencia=0,
                total_tarjeta=?, total_transfer=?, total_otros=?,
                total_ventas=?, total_propinas=?, total_envios=?,
                num_ordenes=?, observaciones_cierre=?
              WHERE id=?");
            $nTurnos = 0;
            foreach ($turnos as $t) {
                $m = _turno_metrics((int)$t['id']);
                $esperado = (float)$t['fondo_inicial'] + $m['efectivo'];
                $esperadoTotal += $esperado;
                $upd->execute([
                    $esperado, $esperado,
                    $m['tarjeta'], $m['transfer'], $m['otros'],
                    $m['ventas'], $m['propinas'], $m['envios'],
                    $m['num_ordenes'],
                    'Cierre global de fin de día (admin)' . ($efectivoContado !== null ? ' · efectivo conciliado en conjunto' : ' · efectivo no conciliado individualmente'),
                    $t['id'],
                ]);
                $nTurnos++;
            }

            // 2) Cancelar órdenes abiertas sin cobrar (si el admin lo confirmó)
            $nCanceladas = 0;
            if ($forzar && $abiertas) {
                $pdo->prepare("UPDATE ordenes
                    SET estado='cancelada', motivo_cancelacion='Cierre de día', cerrada_at=NOW(),
                        estado_web = CASE WHEN tipo='web' AND estado_web='pendiente' THEN 'rechazado' ELSE estado_web END
                    WHERE estado='abierta'")->execute();
                $nCanceladas = count($abiertas);
            }

            // 3) Finalizar comandas de cocina pendientes
            $nComandas = (int)$pdo->query("SELECT COUNT(*) FROM comandas WHERE estado IN ('nueva','preparando','lista')")->fetchColumn();
            $pdo->prepare("UPDATE comandas SET estado='servida', servida_at=NOW() WHERE estado IN ('nueva','preparando','lista')")->execute();

            // 4) Liberar todas las mesas
            $nMesas = (int)$pdo->query("SELECT COUNT(*) FROM mesas WHERE estado<>'libre'")->fetchColumn();
            $pdo->prepare("UPDATE mesas SET estado='libre'")->execute();

            // 5) Guardar marca del último cierre (para el banner del módulo)
            $u = current_user();
            $diffTotal = $efectivoContado !== null ? round($efectivoContado - $esperadoTotal, 2) : null;
            $marca = json_encode([
                'at'    => date('Y-m-d H:i'),
                'por'   => $u['nombre'] ?? 'admin',
                'turnos'=> $nTurnos,
                'esperado' => round($esperadoTotal, 2),
                'contado'  => $efectivoContado,
                'diff'     => $diffTotal,
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('ultimo_cierre_dia', ?)
                           ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([$marca]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(['error' => 'No se pudo cerrar el día: ' . $e->getMessage()], 500);
        }

        log_action('cierre_dia', 'turno', null,
            "Turnos: $nTurnos · Órdenes canceladas: $nCanceladas · Mesas: $nMesas · Comandas: $nComandas"
            . ($efectivoContado !== null ? " · Efectivo contado: $efectivoContado (dif " . ($diffTotal) . ")" : ""));
        @touch(__DIR__ . '/../../config/.sse-trigger');

        json_response([
            'ok' => true,
            'turnos_cerrados'    => $nTurnos,
            'ordenes_canceladas' => $nCanceladas,
            'mesas_liberadas'    => $nMesas,
            'comandas_cerradas'  => $nComandas,
            'efectivo_esperado'  => round($esperadoTotal, 2),
            'efectivo_contado'   => $efectivoContado,
            'diferencia'         => $diffTotal,
        ]);
    },

    /**
     * Mis cortes anteriores (los cerrados)
     */
    'mis_cortes' => function () {
        $u = current_user();
        $stmt = db()->prepare("
            SELECT id, fecha, hora_inicio, hora_fin, estado,
                   total_ventas, total_propinas, diferencia, num_ordenes, cerrado_at
            FROM turnos
            WHERE usuario_id = ? AND estado = 'cerrado'
            ORDER BY cerrado_at DESC
            LIMIT 30
        ");
        $stmt->execute([$u['id']]);
        json_response($stmt->fetchAll());
    },
];

function _turno_metrics(int $turno_id): array {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT m.codigo, COALESCE(SUM(p.monto), 0) AS total
        FROM pagos p JOIN metodos_pago m ON m.id = p.metodo_id
        WHERE p.turno_id = ?
        GROUP BY m.codigo
    ");
    $stmt->execute([$turno_id]);
    $by = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $efectivo = (float)($by['cash'] ?? 0);
    $tarjeta  = (float)($by['card'] ?? 0);
    $transfer = (float)($by['transfer'] ?? 0);
    $otros = 0;
    foreach ($by as $k => $v) if (!in_array($k, ['cash','card','transfer'])) $otros += (float)$v;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS num, COALESCE(SUM(total),0) AS ventas, COALESCE(SUM(costo_envio),0) AS envios
        FROM ordenes WHERE turno_id=? AND estado='cobrada'
    ");
    $stmt->execute([$turno_id]);
    $row = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM propinas WHERE turno_id=?");
    $stmt->execute([$turno_id]);
    $propinas = (float)$stmt->fetchColumn();

    return [
        'efectivo' => round($efectivo,2), 'tarjeta'  => round($tarjeta,2),
        'transfer' => round($transfer,2), 'otros'    => round($otros,2),
        'ventas'   => round((float)$row['ventas'],2),
        'envios'   => round((float)$row['envios'],2),
        'propinas' => round($propinas,2),
        'num_ordenes' => (int)$row['num'],
    ];
}
