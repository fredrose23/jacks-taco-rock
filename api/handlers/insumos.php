<?php
/**
 * Handlers para INSUMOS (materia prima que se consume al cocinar, no se vende).
 * Control de stock con movimientos manuales (entrada/salida/merma/ajuste).
 */
return [
    /** Lista de insumos activos con bandera de stock bajo */
    'list' => function () {
        $rows = db()->query("
            SELECT id, nombre, unidad, stock, stock_minimo
            FROM insumos
            WHERE activo = 1
            ORDER BY nombre
        ")->fetchAll();
        foreach ($rows as &$r) {
            $r['stock']        = (float)$r['stock'];
            $r['stock_minimo'] = (float)$r['stock_minimo'];
            $r['bajo']         = $r['stock'] <= $r['stock_minimo'];
        }
        json_response($rows);
    },

    /** Crear o actualizar un insumo (solo admin). El stock se mueve aparte. */
    'save' => function () {
        require_method('POST');
        api_require_role('admin','encargado');
        $in = json_input();
        $id           = (int)($in['id'] ?? 0);
        $nombre       = trim((string)($in['nombre'] ?? ''));
        $unidad       = trim((string)($in['unidad'] ?? 'pieza')) ?: 'pieza';
        $stock_minimo = max(0, (float)($in['stock_minimo'] ?? 0));

        if ($nombre === '') json_response(['error' => 'El nombre es obligatorio'], 400);

        $pdo = db();
        if ($id) {
            $pdo->prepare("UPDATE insumos SET nombre=?, unidad=?, stock_minimo=? WHERE id=?")
                ->execute([$nombre, $unidad, $stock_minimo, $id]);
            log_action('insumo_editar', 'insumo', $id, $nombre);
            json_response(['ok' => true, 'id' => $id]);
        } else {
            $stock_inicial = max(0, (float)($in['stock_inicial'] ?? 0));
            $pdo->prepare("INSERT INTO insumos (nombre, unidad, stock, stock_minimo) VALUES (?,?,?,?)")
                ->execute([$nombre, $unidad, $stock_inicial, $stock_minimo]);
            $newId = (int)$pdo->lastInsertId();
            // Registrar el stock inicial como movimiento de entrada (trazabilidad)
            if ($stock_inicial > 0) {
                $pdo->prepare("INSERT INTO movimientos_insumo (insumo_id, tipo, cantidad, stock_resultante, motivo, usuario_id) VALUES (?,?,?,?,?,?)")
                    ->execute([$newId, 'entrada', $stock_inicial, $stock_inicial, 'Stock inicial', current_user()['id']]);
            }
            log_action('insumo_crear', 'insumo', $newId, $nombre);
            json_response(['ok' => true, 'id' => $newId]);
        }
    },

    /** Entrada/salida/merma/ajuste de stock de un insumo (solo admin) */
    'movimiento' => function () {
        require_method('POST');
        api_require_role('admin','encargado');
        $in = json_input();
        $insumo_id = (int)($in['insumo_id'] ?? 0);
        $tipo      = $in['tipo'] ?? 'entrada';
        $cantidad  = (float)($in['cantidad'] ?? 0);
        $motivo    = trim((string)($in['motivo'] ?? ''));

        if (!in_array($tipo, ['entrada','salida','ajuste','merma'])) {
            json_response(['error' => 'Tipo inválido'], 400);
        }
        if ($cantidad <= 0) json_response(['error' => 'Cantidad inválida'], 400);

        $pdo = db();
        $get = $pdo->prepare("SELECT stock FROM insumos WHERE id=? AND activo=1");
        $get->execute([$insumo_id]);
        $row = $get->fetch();
        if (!$row) json_response(['error' => 'Insumo no encontrado'], 404);
        $stock = (float)$row['stock'];

        $nuevo = match ($tipo) {
            'entrada'        => $stock + $cantidad,
            'salida','merma' => max(0, $stock - $cantidad),
            'ajuste'         => $cantidad,
        };
        $nuevo = round($nuevo, 3);

        $pdo->prepare("UPDATE insumos SET stock=? WHERE id=?")->execute([$nuevo, $insumo_id]);
        $pdo->prepare("INSERT INTO movimientos_insumo (insumo_id, tipo, cantidad, stock_resultante, motivo, usuario_id) VALUES (?,?,?,?,?,?)")
            ->execute([$insumo_id, $tipo, $cantidad, $nuevo, $motivo ?: null, current_user()['id']]);

        log_action('insumo_movimiento', 'insumo', $insumo_id, "$tipo $cantidad → $nuevo");
        json_response(['ok' => true, 'stock' => $nuevo]);
    },

    /** Historial de movimientos (de un insumo o de todos) */
    'movimientos' => function () {
        $insumo_id = (int)($_GET['insumo_id'] ?? 0);
        $sql = "SELECT m.*, i.nombre AS insumo, i.unidad, u.nombre AS usuario
                FROM movimientos_insumo m
                JOIN insumos i ON i.id = m.insumo_id
                LEFT JOIN usuarios u ON u.id = m.usuario_id";
        $params = [];
        if ($insumo_id) { $sql .= " WHERE m.insumo_id=?"; $params[] = $insumo_id; }
        $sql .= " ORDER BY m.created_at DESC LIMIT 100";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        json_response($stmt->fetchAll());
    },

    /** Desactivar (archivar) un insumo (solo admin) */
    'eliminar' => function () {
        require_method('POST');
        api_require_role('admin','encargado');
        $in = json_input();
        $id = (int)($in['id'] ?? 0);
        db()->prepare("UPDATE insumos SET activo=0 WHERE id=?")->execute([$id]);
        log_action('insumo_eliminar', 'insumo', $id, '');
        json_response(['ok' => true]);
    },
];
