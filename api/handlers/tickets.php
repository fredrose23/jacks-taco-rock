<?php
/**
 * Visor de tickets / ventas cobradas
 *  - Lista filtrable por fecha, tipo, método, empleado
 *  - Detalle completo para reimprimir
 */
return [
    'list' => function () {
        api_require_role('admin','encargado','cajero');
        $desde = $_GET['desde'] ?? date('Y-m-d');
        $hasta = $_GET['hasta'] ?? date('Y-m-d');
        $tipo = $_GET['tipo'] ?? '';      // local | llevar | domicilio
        $usuario_id = (int)($_GET['usuario_id'] ?? 0);
        $q = trim($_GET['q'] ?? '');      // búsqueda por id, cliente

        $where = ["o.estado = 'cobrada'", "DATE(o.cerrada_at) BETWEEN ? AND ?"];
        $params = [$desde, $hasta];
        if ($tipo) { $where[] = "o.tipo = ?"; $params[] = $tipo; }
        if ($usuario_id) { $where[] = "o.usuario_id = ?"; $params[] = $usuario_id; }
        if ($q) {
            $where[] = "(o.id LIKE ? OR o.cliente_nombre LIKE ? OR o.cliente_telefono LIKE ?)";
            $like = "%$q%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);

        $stmt = db()->prepare("
            SELECT o.id, o.tipo, o.mesa_id, o.cliente_nombre, o.cliente_telefono,
                   o.subtotal, o.iva, o.total, o.propina, o.costo_envio,
                   o.cerrada_at, o.estado,
                   m.numero AS mesa_numero,
                   u.nombre AS atendio,
                   r.nombre AS repartidor,
                   GROUP_CONCAT(DISTINCT CONCAT(mp.codigo,':',p.monto) SEPARATOR '|') AS pagos_csv
            FROM ordenes o
            LEFT JOIN mesas m    ON m.id = o.mesa_id
            LEFT JOIN usuarios u ON u.id = o.usuario_id
            LEFT JOIN usuarios r ON r.id = o.repartidor_id
            LEFT JOIN pagos p    ON p.orden_id = o.id
            LEFT JOIN metodos_pago mp ON mp.id = p.metodo_id
            WHERE $whereSql
            GROUP BY o.id
            ORDER BY o.cerrada_at DESC
            LIMIT 500
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $totales = ['n' => count($rows), 'total' => 0];
        foreach ($rows as &$r) {
            $totales['total'] += (float)$r['total'];
            $r['pagos_arr'] = [];
            if ($r['pagos_csv']) {
                foreach (explode('|', $r['pagos_csv']) as $part) {
                    [$cod, $monto] = explode(':', $part);
                    $r['pagos_arr'][] = ['metodo' => $cod, 'monto' => (float)$monto];
                }
            }
            unset($r['pagos_csv']);
        }
        json_response(['totales' => $totales, 'tickets' => $rows]);
    },

    /** Detalle completo para reimprimir */
    'detalle' => function () {
        api_require_role('admin','encargado','cajero');
        $id = (int)($_GET['id'] ?? 0);
        $pdo = db();
        $o = $pdo->prepare("
            SELECT o.*, m.numero AS mesa_numero, u.nombre AS atendio
            FROM ordenes o
            LEFT JOIN mesas m ON m.id = o.mesa_id
            LEFT JOIN usuarios u ON u.id = o.usuario_id
            WHERE o.id = ?
        ");
        $o->execute([$id]);
        $orden = $o->fetch();
        if (!$orden) json_response(['error' => 'No encontrado'], 404);

        $items = $pdo->prepare("
            SELECT id, nombre, precio, COALESCE(precio_extra,0) AS precio_extra,
                   cantidad, COALESCE(cantidad_cancelada,0) AS cantidad_cancelada,
                   COALESCE(comensal,1) AS comensal,
                   notas, modificadores_json, cancelado, motivo_cancel
            FROM orden_items WHERE orden_id = ? ORDER BY comensal, id
        ");
        $items->execute([$id]);

        $pagos = $pdo->prepare("
            SELECT mp.codigo AS metodo, mp.nombre, mp.icono, p.monto, p.referencia
            FROM pagos p JOIN metodos_pago mp ON mp.id = p.metodo_id
            WHERE p.orden_id = ?
        ");
        $pagos->execute([$id]);

        $orden['items'] = $items->fetchAll();
        $orden['pagos'] = $pagos->fetchAll();

        json_response($orden);
    },
];
