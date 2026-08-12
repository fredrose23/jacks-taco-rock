<?php
/**
 * Administración de pedidos web (requiere login).
 * El restaurante acepta o rechaza los pedidos que llegan de la página pública.
 */
return [
    /** Conteo de pendientes (para el badge del sidebar) */
    'count' => function () {
        api_require_login();
        $n = db()->query("SELECT COUNT(*) FROM ordenes WHERE tipo='web' AND estado_web='pendiente'")->fetchColumn();
        json_response(['count' => (int)$n]);
    },

    /** Lista de pedidos web */
    'list' => function () {
        api_require_login();
        $filter = $_GET['filter'] ?? 'pendientes';
        $where = ["o.tipo='web'"];
        switch ($filter) {
            case 'pendientes': $where[] = "o.estado_web='pendiente'"; break;
            case 'aceptados':  $where[] = "o.estado_web='aceptado' AND o.estado='abierta'"; break;
            case 'rechazados': $where[] = "o.estado_web='rechazado' AND DATE(o.web_creado_at)=CURDATE()"; break;
            case 'hoy':        $where[] = "DATE(o.web_creado_at)=CURDATE()"; break;
        }
        $sql = "SELECT o.id, o.estado, o.estado_web, o.web_tipo_entrega, o.estado_entrega,
                       o.cliente_nombre, o.cliente_telefono, o.cliente_direccion,
                       o.cliente_referencias, o.cliente_lat, o.cliente_lng,
                       o.costo_envio, o.paga_con, o.envio_distancia_km, o.envio_por_cotizar,
                       o.total, o.web_creado_at, o.web_aceptado_at,
                       o.web_motivo_rechazo, o.repartidor_id,
                       u.nombre AS repartidor_nombre,
                       (SELECT COUNT(*) FROM orden_items WHERE orden_id=o.id) AS num_items
                FROM ordenes o
                LEFT JOIN usuarios u ON u.id=o.repartidor_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.web_creado_at DESC
                LIMIT 100";
        $rows = db()->query($sql)->fetchAll();

        // Items de cada pedido
        $itemsStmt = db()->prepare("SELECT nombre, cantidad, precio, COALESCE(precio_extra,0) AS precio_extra, notas FROM orden_items WHERE orden_id=? ORDER BY id");
        foreach ($rows as &$r) {
            $itemsStmt->execute([$r['id']]);
            $r['items'] = $itemsStmt->fetchAll();
        }
        json_response($rows);
    },

    /**
     * Guarda el costo de envío (y opcionalmente "paga con") de un pedido web
     * pendiente, ANTES de aceptarlo. Sirve para pasarle el total al cliente.
     */
    'set_envio' => function () {
        api_require_role('admin','encargado','cajero','mesero');
        require_method('POST');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        $costo_envio = isset($in['costo_envio']) ? (float)$in['costo_envio'] : null;
        $paga_con    = isset($in['paga_con']) && $in['paga_con'] !== '' ? (float)$in['paga_con'] : null;

        $pdo = db();
        $stmt = $pdo->prepare("SELECT estado_web, total FROM ordenes WHERE id=? AND tipo='web'");
        $stmt->execute([$orden_id]);
        $o = $stmt->fetch();
        if (!$o) json_response(['error' => 'No encontrado'], 404);
        if ($o['estado_web'] !== 'pendiente') json_response(['error' => 'El pedido ya fue procesado'], 400);

        $sets = []; $vals = [];
        if ($costo_envio !== null) {
            $sets[] = "costo_envio=?"; $vals[] = $costo_envio;
            $sets[] = "envio_por_cotizar=0";
        }
        $sets[] = "paga_con=?"; $vals[] = $paga_con;
        $vals[] = $orden_id;
        $pdo->prepare("UPDATE ordenes SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);

        $total = (float)$o['total'] + ($costo_envio ?? 0);
        json_response([
            'ok' => true,
            'total' => $total,
            'cambio' => $paga_con !== null ? max(0, $paga_con - $total) : null,
        ]);
    },

    /**
     * Aceptar pedido web → lo convierte en orden real, lo manda a cocina.
     */
    'aceptar' => function () {
        api_require_role('admin','encargado','cajero','mesero');
        require_method('POST');
        require_jornada_abierta();
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);

        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM ordenes WHERE id=? AND tipo='web'");
        $stmt->execute([$orden_id]);
        $o = $stmt->fetch();
        if (!$o) json_response(['error' => 'Pedido no encontrado'], 404);
        if ($o['estado_web'] !== 'pendiente') json_response(['error' => 'El pedido ya fue procesado'], 400);

        $userId = current_user()['id'];
        $tipoReal = $o['web_tipo_entrega']; // 'llevar' | 'domicilio'
        $repartidor_id = (int)($in['repartidor_id'] ?? 0) ?: null;

        // Costo de envío: usa el que viene en el request, o el ya guardado con set_envio
        $costo_envio = isset($in['costo_envio']) && $in['costo_envio'] !== ''
            ? (float)$in['costo_envio']
            : (float)$o['costo_envio'];

        // #4 — Para domicilio SIEMPRE debe haber un costo de envío definido (> 0)
        if ($tipoReal === 'domicilio' && $costo_envio <= 0) {
            json_response([
                'error' => 'Define primero el costo de envío de este pedido (no puede ir en $0).',
                'requiere_envio' => true,
                'distancia_km' => $o['envio_distancia_km'],
            ], 400);
        }

        // "Paga con" (para el cambio del repartidor)
        $paga_con = isset($in['paga_con']) && $in['paga_con'] !== '' ? (float)$in['paga_con'] : $o['paga_con'];

        $estado_entrega = $tipoReal === 'domicilio'
            ? ($repartidor_id ? 'asignada' : 'pendiente')
            : null;

        // usuario_id: el que ACEPTA la orden web queda como responsable de la
        // venta (antes quedaba NULL → salía "(sin asignar)" en los reportes).
        $pdo->prepare("UPDATE ordenes SET
            tipo=?, estado_web='aceptado', web_aceptado_at=NOW(),
            costo_envio=?, envio_por_cotizar=0, paga_con=?,
            repartidor_id=?, estado_entrega=?, turno_id=COALESCE(turno_id, ?),
            usuario_id=COALESCE(usuario_id, ?)
          WHERE id=?")
          ->execute([$tipoReal, $costo_envio, $paga_con, $repartidor_id, $estado_entrega, current_turno_id(), $userId, $orden_id]);

        // Mandar a cocina (crea las comandas y devuelve datos para imprimir)
        $comandas = dispatch_orden_cocina($orden_id, $userId);

        // Impresión directa a cada cocina (servidor → red). Suprime errores.
        require_once __DIR__ . '/../../includes/escpos.php';
        $meseroNombre = current_user()['nombre'] ?? '';
        $impreso = [];
        foreach ($comandas as $cmd) {
            $cmd['mesero'] = $meseroNombre;
            $cmd['tipo']   = $tipoReal;
            $impreso[$cmd['cocina']] = print_comanda_red($cmd);
        }

        log_action('web_aceptar', 'orden', $orden_id,
            strtoupper($tipoReal) . " · {$o['cliente_nombre']} · " . count($comandas) . " comandas");

        json_response([
            'ok'       => true,
            'tipo'     => $tipoReal,
            'orden_id' => $orden_id,
            'n_comandas' => count($comandas),
            'comandas' => $comandas, // objetos imprimibles {id,cocina,mesa,items,created}
            'impreso'  => $impreso,
        ]);
    },

    /** Rechazar pedido web con motivo */
    'rechazar' => function () {
        api_require_role('admin','encargado','cajero','mesero');
        require_method('POST');
        $in = json_input();
        $orden_id = (int)($in['orden_id'] ?? 0);
        $motivo = trim($in['motivo'] ?? '');
        if (strlen($motivo) < 3) json_response(['error' => 'Motivo requerido'], 400);

        $pdo = db();
        $stmt = $pdo->prepare("SELECT estado_web FROM ordenes WHERE id=? AND tipo='web'");
        $stmt->execute([$orden_id]);
        $estado = $stmt->fetchColumn();
        if (!$estado) json_response(['error' => 'No encontrado'], 404);
        if ($estado !== 'pendiente') json_response(['error' => 'El pedido ya fue procesado'], 400);

        $pdo->prepare("UPDATE ordenes SET estado_web='rechazado', estado='cancelada',
                       web_motivo_rechazo=?, cerrada_at=NOW() WHERE id=?")
            ->execute([$motivo, $orden_id]);
        log_action('web_rechazar', 'orden', $orden_id, $motivo);
        json_response(['ok' => true]);
    },
];
