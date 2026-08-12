<?php
/**
 * Handlers para el recurso PRODUCTOS / MENÚ
 */
return [
    'list' => function () {
        $pdo = db();
        $rows = $pdo->query("
            SELECT p.id, p.nombre, p.descripcion, p.precio, p.emoji, p.imagen,
                   p.destacado, p.disponible, p.cocina_2, p.maneja_stock, p.stock,
                   c.id AS categoria_id, c.nombre AS categoria
            FROM productos p
            JOIN categorias c ON c.id = p.categoria_id
            ORDER BY c.orden, p.nombre
        ")->fetchAll();

        // Traer grupos de modificadores asignados a cada producto
        $grupos = $pdo->prepare("
            SELECT g.id, g.nombre, g.tipo, g.obligatorio, g.max_selecciones
            FROM producto_modificador_grupo pmg
            JOIN modificador_grupos g ON g.id = pmg.grupo_id
            WHERE pmg.producto_id = ?
        ");
        $opciones = $pdo->prepare("
            SELECT id, nombre, precio_extra
            FROM modificadores
            WHERE grupo_id = ? AND activo = 1
            ORDER BY orden, nombre
        ");

        foreach ($rows as &$r) {
            $r['precio']       = (float)$r['precio'];
            $r['destacado']    = (bool)$r['destacado'];
            $r['disponible']   = (bool)$r['disponible'];
            $r['cocina_2']     = (int)$r['cocina_2'];
            $r['maneja_stock'] = (int)$r['maneja_stock'];
            $r['stock']        = (int)$r['stock'];
            // grupos de modificadores
            $grupos->execute([$r['id']]);
            $gs = $grupos->fetchAll();
            foreach ($gs as &$g) {
                $opciones->execute([$g['id']]);
                $g['opciones'] = array_map(function($o){
                    $o['precio_extra'] = (float)$o['precio_extra'];
                    return $o;
                }, $opciones->fetchAll());
            }
            $r['modificadores'] = $gs;
        }
        json_response($rows);
    },

    'categorias' => function () {
        $pdo = db();
        $rows = $pdo->query("SELECT * FROM categorias WHERE activa=1 ORDER BY orden")->fetchAll();
        json_response($rows);
    },

    /**
     * Combos activos con sus items — para mostrar en la pantalla de pedido
     */
    'combos' => function () {
        $pdo = db();
        $rows = $pdo->query("
            SELECT id, nombre, descripcion, precio, emoji
            FROM combos
            WHERE activo = 1
            ORDER BY nombre
        ")->fetchAll();

        $items = $pdo->prepare("
            SELECT ci.cantidad, p.id, p.nombre, p.emoji, p.precio
            FROM combo_items ci
            JOIN productos p ON p.id = ci.producto_id
            WHERE ci.combo_id = ?
        ");
        foreach ($rows as &$c) {
            $items->execute([$c['id']]);
            $c['items'] = $items->fetchAll();
            $c['precio'] = (float)$c['precio'];
        }
        json_response($rows);
    },

    /**
     * Promociones activas (para aplicar manualmente al cobrar)
     */
    'promociones_activas' => function () {
        $hoy = date('Y-m-d');
        $ahora = date('H:i:s');
        $stmt = db()->prepare("
            SELECT id, nombre, descripcion, tipo, valor, codigo, aplicable_a,
                   categoria_id, producto_id
            FROM promociones
            WHERE activa = 1
              AND (desde IS NULL OR desde <= ?)
              AND (hasta IS NULL OR hasta >= ?)
              AND (hora_desde IS NULL OR hora_desde <= ?)
              AND (hora_hasta IS NULL OR hora_hasta >= ?)
              AND (uso_max IS NULL OR uso_actual < uso_max)
            ORDER BY nombre
        ");
        $stmt->execute([$hoy, $hoy, $ahora, $ahora]);
        json_response($stmt->fetchAll());
    },

    'toggle_available' => function () {
        require_method('POST');
        $in = json_input();
        $id = (int)($in['id'] ?? 0);
        $disp = (int)($in['disponible'] ?? 1);
        db()->prepare("UPDATE productos SET disponible=? WHERE id=?")->execute([$disp, $id]);
        json_response(['ok' => true]);
    },
];
