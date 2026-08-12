<?php
/**
 * Handlers para el recurso MESAS
 */
return [
    'list' => function () {
        $pdo = db();
        // Enlazamos SOLO UNA orden abierta por mesa (la que tenga productos; si
        // hay empate o varias, la más reciente). Esto evita que una mesa
        // aparezca duplicada cuando tiene más de una orden abierta.
        $rows = $pdo->query("
            SELECT m.id, m.numero, m.nombre, m.capacidad, m.estado, m.zona, m.descripcion,
                   o.id AS orden_id,
                   COALESCE(o.total, 0) AS total,
                   (SELECT SUM(cantidad) FROM orden_items WHERE orden_id = o.id) AS items,
                   -- platillos = comida (todo lo que NO sea Bebidas); aguas = Bebidas
                   (SELECT COALESCE(SUM(GREATEST(oi.cantidad - COALESCE(oi.cantidad_cancelada,0),0)),0)
                    FROM orden_items oi JOIN productos p ON p.id=oi.producto_id
                    JOIN categorias c ON c.id=p.categoria_id
                    WHERE oi.orden_id=o.id AND c.nombre<>'Bebidas') AS platillos,
                   (SELECT COALESCE(SUM(GREATEST(oi.cantidad - COALESCE(oi.cantidad_cancelada,0),0)),0)
                    FROM orden_items oi JOIN productos p ON p.id=oi.producto_id
                    JOIN categorias c ON c.id=p.categoria_id
                    WHERE oi.orden_id=o.id AND c.nombre='Bebidas') AS aguas
            FROM mesas m
            LEFT JOIN ordenes o ON o.id = (
                SELECT o2.id FROM ordenes o2
                WHERE o2.mesa_id = m.id AND o2.estado = 'abierta'
                ORDER BY (SELECT COUNT(*) FROM orden_items WHERE orden_id = o2.id) DESC, o2.id DESC
                LIMIT 1
            )
            WHERE m.activa = 1
            ORDER BY m.numero
        ")->fetchAll();
        json_response($rows);
    },

    'get' => function () {
        $id = (int)($_GET['id'] ?? 0);
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM mesas WHERE id = ?");
        $stmt->execute([$id]);
        json_response($stmt->fetch() ?: ['error' => 'Mesa no encontrada']);
    },
];
