<?php
/**
 * Handlers para el recurso COCINA (comandas)
 */
require_once __DIR__ . '/../../includes/escpos.php'; // impresión térmica directa

/** Marca el archivo que dispara el refresco en tiempo real (SSE) de cocina. */
function _sse_ping(): void {
    @touch(__DIR__ . '/../../config/.sse-trigger');
}

return [
    /**
     * Lista los avisos pendientes (recordatorios sueltos tipo "freír papas").
     * ?cocina=2 filtra por estación; sin parámetro trae todos los pendientes.
     */
    'avisos' => function () {
        $cocina = isset($_GET['cocina']) ? (int)$_GET['cocina'] : 0;
        $sql = "SELECT a.id, a.cocina, a.mensaje, a.created_at, u.nombre AS usuario
                FROM avisos_cocina a
                LEFT JOIN usuarios u ON u.id = a.usuario_id
                WHERE a.estado='pendiente'";
        $params = [];
        if ($cocina) { $sql .= " AND a.cocina=?"; $params[] = $cocina; }
        $sql .= " ORDER BY a.created_at ASC";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        json_response($stmt->fetchAll());
    },
    /** Crea un aviso para una estación de cocina (por defecto Cocina 2). */
    'aviso_create' => function () {
        require_method('POST');
        $u = api_require_login();
        $in = json_input();
        $mensaje = trim((string)($in['mensaje'] ?? ''));
        $cocina  = (int)($in['cocina'] ?? 2);
        if ($mensaje === '') json_response(['error' => 'El mensaje no puede estar vacío'], 400);
        if (mb_strlen($mensaje) > 160) $mensaje = mb_substr($mensaje, 0, 160);
        if (!in_array($cocina, [1, 2], true)) $cocina = 2;
        db()->prepare("INSERT INTO avisos_cocina (cocina, mensaje, usuario_id) VALUES (?,?,?)")
            ->execute([$cocina, $mensaje, $u['id'] ?? null]);
        log_action('aviso_cocina', 'cocina', $cocina, $mensaje);
        _sse_ping();
        // Imprimir el aviso en la impresora de esa cocina (servidor → red)
        $impreso = print_aviso_red($cocina, $mensaje, $u['nombre'] ?? '');
        json_response(['ok' => true, 'impreso' => $impreso]);
    },
    /** Marca un aviso como atendido (lo quita de la pantalla). */
    'aviso_done' => function () {
        require_method('POST');
        api_require_login();
        $id = (int)(json_input()['id'] ?? 0);
        if (!$id) json_response(['error' => 'ID requerido'], 400);
        db()->prepare("UPDATE avisos_cocina SET estado='atendido', atendido_at=NOW() WHERE id=?")->execute([$id]);
        _sse_ping();
        json_response(['ok' => true]);
    },

    'list' => function () {
        $pdo = db();
        $cocina = isset($_GET['cocina']) ? (int)$_GET['cocina'] : 0; // 0 = todas
        $sql = "
            SELECT c.id, c.orden_id, c.mesa_id, c.cocina, c.ronda, c.estado, c.created_at,
                   m.numero AS mesa_numero, m.nombre AS mesa_nombre, m.capacidad,
                   u.nombre AS mesero
            FROM comandas c
            JOIN mesas m ON m.id = c.mesa_id
            JOIN ordenes o ON o.id = c.orden_id
            LEFT JOIN usuarios u ON u.id = c.usuario_id
            WHERE c.estado IN ('nueva','preparando','lista')
              AND o.estado = 'abierta'
        ";
        $params = [];
        if ($cocina) { $sql .= " AND c.cocina = ?"; $params[] = $cocina; }
        $sql .= " ORDER BY c.created_at ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Items: si es comanda Cocina 1, traer TODOS los items de la orden marcados para
        // esa comanda. Si es Cocina 2, traer solo los items con cocina_2=1 de la orden.
        $itemsAll = $pdo->prepare("
            SELECT oi.*, COALESCE(p.cocina_2, 0) AS cocina_2
            FROM orden_items oi
            JOIN productos p ON p.id = oi.producto_id
            WHERE oi.comanda_id = ?
            ORDER BY oi.comensal, oi.id
        ");
        // Cocina 2: SOLO los items cocina_2 de ESA ronda (no de toda la mesa).
        // Los items de una ronda apuntan a la comanda de esa misma orden+ronda.
        $itemsCocina2 = $pdo->prepare("
            SELECT oi.*, 1 AS cocina_2
            FROM orden_items oi
            JOIN productos p ON p.id = oi.producto_id
            JOIN comandas cc ON cc.id = oi.comanda_id
            WHERE cc.orden_id = ? AND cc.ronda = ? AND p.cocina_2 = 1
            ORDER BY oi.comensal, oi.id
        ");
        foreach ($rows as &$c) {
            if ($c['cocina'] == 2) {
                $itemsCocina2->execute([$c['orden_id'], $c['ronda']]);
                $c['items'] = $itemsCocina2->fetchAll();
            } else {
                $itemsAll->execute([$c['id']]);
                $c['items'] = $itemsAll->fetchAll();
            }
        }
        json_response($rows);
    },

    'update_status' => function () {
        require_method('POST');
        $u = api_require_login();
        $in = json_input();
        $id = (int)($in['id'] ?? 0);
        $estado = $in['estado'] ?? '';
        $validos = ['nueva','preparando','lista','servida'];
        if (!in_array($estado, $validos)) json_response(['error' => 'Estado inválido'], 400);

        // Permisos por estado:
        //   preparando / lista  → solo cocina / admin (los que cocinan)
        //   servida             → cocina + admin + cajero + mesero + repartidor
        //                         (mesero al recoger el plato; repartidor al recoger pedido a domicilio)
        //   nueva (revertir)    → solo admin
        $permitidos = match($estado) {
            'preparando','lista' => ['cocina','admin'],
            'servida'            => ['cocina','admin','cajero','mesero','repartidor'],
            'nueva'              => ['admin'],
        };
        if (!in_array($u['rol'], $permitidos)) {
            json_response(['error' => "Tu rol no puede cambiar a estado '$estado'"], 403);
        }

        $sql = "UPDATE comandas SET estado=? WHERE id=?";
        $params = [$estado, $id];
        if ($estado === 'servida') {
            $sql = "UPDATE comandas SET estado=?, servida_at=NOW() WHERE id=?";
        }
        db()->prepare($sql)->execute($params);
        @touch(__DIR__ . '/../../config/.sse-trigger');
        log_action('cocina_estado', 'comanda', $id, "→ $estado");
        json_response(['ok' => true]);
    },

    'count' => function () {
        $cocina = isset($_GET['cocina']) ? (int)$_GET['cocina'] : 0;
        $sql = "SELECT COUNT(*) FROM comandas c JOIN ordenes o ON o.id=c.orden_id
                WHERE c.estado IN ('nueva','preparando','lista') AND o.estado='abierta'";
        $params = [];
        if ($cocina) { $sql .= " AND c.cocina = ?"; $params[] = $cocina; }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        json_response(['count' => (int)$stmt->fetchColumn()]);
    },
];
