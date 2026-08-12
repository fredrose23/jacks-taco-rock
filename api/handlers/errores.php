<?php
/**
 * Visor de errores (solo admin)
 */
return [
    'list' => function () {
        api_require_role('admin');
        $limit = min(500, max(20, (int)($_GET['limit'] ?? 200)));
        $rows = db()->query("
            SELECT e.*, u.nombre AS usuario
            FROM errores_log e
            LEFT JOIN usuarios u ON u.id = e.usuario_id
            ORDER BY e.created_at DESC
            LIMIT $limit
        ")->fetchAll();
        json_response($rows);
    },

    'stats' => function () {
        api_require_role('admin');
        $stats = db()->query("
            SELECT tipo, COUNT(*) AS total,
                   SUM(CASE WHEN resuelto=0 THEN 1 ELSE 0 END) AS pendientes,
                   SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) AS hoy
            FROM errores_log
            GROUP BY tipo
            ORDER BY total DESC
        ")->fetchAll();
        $total_24h = db()->query("SELECT COUNT(*) FROM errores_log WHERE created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
        json_response(['por_tipo' => $stats, 'total_24h' => (int)$total_24h]);
    },

    'resolve' => function () {
        api_require_role('admin');
        require_method('POST');
        $id = (int)(json_input()['id'] ?? 0);
        db()->prepare("UPDATE errores_log SET resuelto=1 WHERE id=?")->execute([$id]);
        log_action('error_resuelto', 'error', $id, "Marcado como resuelto");
        json_response(['ok' => true]);
    },

    'purge' => function () {
        api_require_role('admin');
        require_method('POST');
        $deleted = db()->exec("DELETE FROM errores_log WHERE created_at < NOW() - INTERVAL 30 DAY");
        log_action('error_purge', null, null, "$deleted errores >30 días eliminados");
        json_response(['ok' => true, 'deleted' => $deleted]);
    },
];
