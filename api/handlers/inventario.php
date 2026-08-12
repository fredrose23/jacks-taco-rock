<?php
/**
 * Handlers para INVENTARIO (stock simple por producto)
 */
return [
    /** Lista productos QUE MANEJAN STOCK */
    'list' => function () {
        $pdo = db();
        $rows = $pdo->query("
            SELECT p.id, p.nombre, p.emoji, p.precio, p.stock, p.stock_minimo, p.unidad,
                   p.disponible, c.nombre AS categoria
            FROM productos p
            JOIN categorias c ON c.id = p.categoria_id
            WHERE p.maneja_stock = 1
            ORDER BY c.orden, p.nombre
        ")->fetchAll();
        foreach ($rows as &$r) {
            $r['precio']      = (float)$r['precio'];
            $r['stock']       = (int)$r['stock'];
            $r['stock_minimo']= (int)$r['stock_minimo'];
            $r['disponible']  = (bool)$r['disponible'];
            $r['bajo']        = $r['stock'] <= $r['stock_minimo'];
        }
        json_response($rows);
    },

    /** Entrada/salida/ajuste de stock manual (solo admin) */
    'movimiento' => function () {
        require_method('POST');
        api_require_role('admin','encargado');
        $in = json_input();
        $producto_id = (int)($in['producto_id'] ?? 0);
        $tipo        = $in['tipo'] ?? 'entrada';
        $cantidad    = (int)($in['cantidad'] ?? 0);
        $motivo      = trim((string)($in['motivo'] ?? ''));

        if (!in_array($tipo, ['entrada','salida','ajuste','merma'])) {
            json_response(['error' => 'Tipo inválido'], 400);
        }
        if ($cantidad <= 0) json_response(['error' => 'Cantidad inválida'], 400);

        $pdo = db();
        $get = $pdo->prepare("SELECT stock FROM productos WHERE id=?");
        $get->execute([$producto_id]);
        $stock = (int)$get->fetchColumn();
        if (!$stock && !$pdo->query("SELECT 1 FROM productos WHERE id=$producto_id")->fetch()) {
            json_response(['error' => 'Producto no encontrado'], 404);
        }

        $nuevo = match($tipo) {
            'entrada' => $stock + $cantidad,
            'salida','merma' => max(0, $stock - $cantidad),
            'ajuste' => $cantidad,
        };

        $pdo->prepare("UPDATE productos SET stock=? WHERE id=?")->execute([$nuevo, $producto_id]);
        $pdo->prepare("INSERT INTO movimientos_stock (producto_id, tipo, cantidad, stock_resultante, motivo, usuario_id) VALUES (?,?,?,?,?,?)")
            ->execute([$producto_id, $tipo, $cantidad, $nuevo, $motivo, current_user()['id']]);

        json_response(['ok' => true, 'stock' => $nuevo]);
    },

    /** Historial de movimientos */
    'movimientos' => function () {
        $producto_id = (int)($_GET['producto_id'] ?? 0);
        $sql = "SELECT m.*, p.nombre AS producto, u.nombre AS usuario
                FROM movimientos_stock m
                JOIN productos p ON p.id = m.producto_id
                LEFT JOIN usuarios u ON u.id = m.usuario_id";
        $params = [];
        if ($producto_id) { $sql .= " WHERE m.producto_id=?"; $params[] = $producto_id; }
        $sql .= " ORDER BY m.created_at DESC LIMIT 100";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        json_response($stmt->fetchAll());
    },

    /** Productos en stock bajo (solo los que manejan stock) */
    'alertas' => function () {
        $rows = db()->query("
            SELECT id, nombre, stock, stock_minimo, unidad
            FROM productos
            WHERE maneja_stock = 1 AND stock <= stock_minimo AND disponible = 1
            ORDER BY stock ASC
        ")->fetchAll();
        json_response($rows);
    },
];
