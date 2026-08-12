<?php
/**
 * Reservaciones
 */
return [
    'list' => function () {
        $desde = $_GET['desde'] ?? date('Y-m-d');
        $hasta = $_GET['hasta'] ?? date('Y-m-d', strtotime('+30 days'));
        $stmt = db()->prepare("
            SELECT r.*, m.numero AS mesa_numero, u.nombre AS creado_por
            FROM reservaciones r
            LEFT JOIN mesas m ON m.id=r.mesa_id
            LEFT JOIN usuarios u ON u.id=r.created_by
            WHERE r.fecha BETWEEN ? AND ?
            ORDER BY r.fecha, r.hora
        ");
        $stmt->execute([$desde, $hasta]);
        json_response($stmt->fetchAll());
    },
    'save' => function () {
        require_method('POST');
        $in = json_input();
        $fields = ['mesa_id','cliente_nombre','cliente_telefono','fecha','hora','personas','notas','estado'];
        $id = (int)($in['id'] ?? 0);
        if (!$id) $in['created_by'] = current_user()['id'] ?? null;
        $data = array_intersect_key($in, array_flip($fields));
        foreach ($data as $k=>$v) if ($v === '') $data[$k] = null;
        $pdo = db();
        if ($id) {
            $sets = implode(',', array_map(fn($k)=>"$k=?", array_keys($data)));
            $vals = array_values($data); $vals[] = $id;
            $pdo->prepare("UPDATE reservaciones SET $sets WHERE id=?")->execute($vals);
        } else {
            $data['created_by'] = $in['created_by'] ?? null;
            $cols = implode(',', array_keys($data));
            $ph = implode(',', array_fill(0, count($data), '?'));
            $pdo->prepare("INSERT INTO reservaciones ($cols) VALUES ($ph)")->execute(array_values($data));
            $id = (int)$pdo->lastInsertId();
        }
        log_action('reservacion_save', 'reservacion', $id, "");
        json_response(['ok'=>true,'id'=>$id]);
    },
    'estado' => function () {
        require_method('POST');
        $in = json_input();
        $id = (int)($in['id'] ?? 0);
        $estado = $in['estado'] ?? '';
        if (!in_array($estado, ['pendiente','confirmada','llegada','cancelada','no_show'])) {
            json_response(['error'=>'Estado inválido'], 400);
        }
        db()->prepare("UPDATE reservaciones SET estado=? WHERE id=?")->execute([$estado, $id]);
        // Si llegó, marcar mesa como ocupada
        if ($estado === 'llegada') {
            $r = db()->prepare("SELECT mesa_id FROM reservaciones WHERE id=?");
            $r->execute([$id]);
            $mid = $r->fetchColumn();
            if ($mid) db()->prepare("UPDATE mesas SET estado='ocupada' WHERE id=?")->execute([$mid]);
        }
        log_action('reservacion_estado', 'reservacion', $id, $estado);
        json_response(['ok'=>true]);
    },
    'delete' => function () {
        require_method('POST');
        $id = (int)(json_input()['id'] ?? 0);
        db()->prepare("DELETE FROM reservaciones WHERE id=?")->execute([$id]);
        log_action('reservacion_delete', 'reservacion', $id, "");
        json_response(['ok'=>true]);
    },
];
