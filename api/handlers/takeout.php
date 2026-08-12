<?php
/**
 * Pedidos PARA LLEVAR (cliente pasa a recoger)
 * Estructura similar a delivery pero más simple — sin repartidor ni envío.
 */
return [
    'list' => function () {
        $filter = $_GET['filter'] ?? 'activos';

        $where = ["o.tipo = 'llevar'"];
        $params = [];
        switch ($filter) {
            case 'activos':
                $where[] = "o.estado = 'abierta'";
                break;
            case 'cobrados':
                $where[] = "o.estado = 'cobrada'";
                $where[] = "DATE(o.cerrada_at) = CURDATE()";
                break;
            case 'all_today':
                $where[] = "DATE(o.abierta_at) = CURDATE()";
                break;
        }

        $sql = "SELECT o.id, o.tipo, o.estado, o.cliente_nombre, o.cliente_telefono,
                       o.hora_pickup, o.total, o.abierta_at, o.cerrada_at,
                       u.nombre AS atendio,
                       (SELECT COUNT(*) FROM orden_items WHERE orden_id = o.id) AS num_items,
                       (SELECT COUNT(*) FROM comandas WHERE orden_id = o.id AND estado='lista') AS comandas_listas,
                       (SELECT COUNT(*) FROM comandas WHERE orden_id = o.id AND estado IN ('nueva','preparando')) AS comandas_en_curso
                FROM ordenes o
                LEFT JOIN usuarios u ON u.id = o.usuario_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.abierta_at DESC
                LIMIT 200";

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        json_response($stmt->fetchAll());
    },
];
