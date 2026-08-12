<?php
/**
 * Reporte de propinas
 */
return [
    'reporte' => function () {
        $desde = $_GET['desde'] ?? date('Y-m-d');
        $hasta = $_GET['hasta'] ?? date('Y-m-d');
        $stmt = db()->prepare("
            SELECT u.nombre AS mesero,
                   COUNT(p.id) AS num_ordenes,
                   COALESCE(SUM(p.monto),0) AS total
            FROM propinas p
            LEFT JOIN usuarios u ON u.id=p.mesero_id
            WHERE DATE(p.created_at) BETWEEN ? AND ?
            GROUP BY u.id
            ORDER BY total DESC
        ");
        $stmt->execute([$desde, $hasta]);

        $detalle = db()->prepare("
            SELECT p.id, p.monto, p.porcentaje, p.created_at,
                   u.nombre AS mesero, o.id AS orden_id, m.numero AS mesa
            FROM propinas p
            LEFT JOIN usuarios u ON u.id=p.mesero_id
            LEFT JOIN ordenes o ON o.id=p.orden_id
            LEFT JOIN mesas m ON m.id=o.mesa_id
            WHERE DATE(p.created_at) BETWEEN ? AND ?
            ORDER BY p.created_at DESC
        ");
        $detalle->execute([$desde, $hasta]);

        json_response([
            'rango'   => ['desde'=>$desde,'hasta'=>$hasta],
            'por_mesero' => $stmt->fetchAll(),
            'detalle'    => $detalle->fetchAll(),
            'total'      => (float)array_sum(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'total')),
        ]);
    },
];
