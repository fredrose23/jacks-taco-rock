<?php
/**
 * Clientes frecuentes — alta automática al cobrar pedidos de llevar/domicilio,
 * y búsqueda por teléfono para autocompletar al tomar el pedido.
 */

return [
    /**
     * Búsqueda rápida — para autocompletar al teclear teléfono o nombre
     * Devuelve hasta 10 resultados
     */
    'search' => function () {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 3) { json_response([]); }
        $like = "%$q%";
        $stmt = db()->prepare("
            SELECT id, telefono, nombre, direccion, referencias, total_pedidos, last_order_at
            FROM clientes
            WHERE telefono LIKE ? OR nombre LIKE ?
            ORDER BY total_pedidos DESC, last_order_at DESC
            LIMIT 10
        ");
        $stmt->execute([$like, $like]);
        json_response($stmt->fetchAll());
    },

    /**
     * Match exacto por teléfono — se llama al perder foco del campo teléfono
     */
    'lookup' => function () {
        $tel = trim($_GET['tel'] ?? '');
        if (!$tel) json_response(['cliente' => null]);
        $stmt = db()->prepare("SELECT * FROM clientes WHERE telefono = ? LIMIT 1");
        $stmt->execute([$tel]);
        json_response(['cliente' => $stmt->fetch() ?: null]);
    },

    'list' => function () {
        $limit = min(200, max(20, (int)($_GET['limit'] ?? 100)));
        $rows = db()->query("
            SELECT * FROM clientes
            ORDER BY total_pedidos DESC, last_order_at DESC
            LIMIT $limit
        ")->fetchAll();
        json_response($rows);
    },

    'save' => function () {
        require_method('POST');
        $in = json_input();
        $id = (int)($in['id'] ?? 0);
        $tel = trim($in['telefono'] ?? '');
        $nombre = trim($in['nombre'] ?? '');
        if (!$tel || !$nombre) {
            json_response(['error' => 'Teléfono y nombre son obligatorios'], 400);
        }
        $direccion = trim($in['direccion'] ?? '') ?: null;
        $referencias = trim($in['referencias'] ?? '') ?: null;
        $notas = trim($in['notas'] ?? '') ?: null;

        $pdo = db();
        if ($id) {
            $pdo->prepare("UPDATE clientes SET telefono=?, nombre=?, direccion=?, referencias=?, notas=? WHERE id=?")
                ->execute([$tel, $nombre, $direccion, $referencias, $notas, $id]);
            log_action('cliente_update', 'cliente', $id, $nombre);
        } else {
            try {
                $pdo->prepare("INSERT INTO clientes (telefono, nombre, direccion, referencias, notas) VALUES (?,?,?,?,?)")
                    ->execute([$tel, $nombre, $direccion, $referencias, $notas]);
                $id = (int)$pdo->lastInsertId();
                log_action('cliente_create', 'cliente', $id, "$nombre · $tel");
            } catch (PDOException $e) {
                // Duplicate phone? get existing
                $stmt = $pdo->prepare("SELECT id FROM clientes WHERE telefono=?");
                $stmt->execute([$tel]);
                $existing = $stmt->fetchColumn();
                if ($existing) json_response(['error' => 'Ese teléfono ya existe (ID '.$existing.')'], 400);
                throw $e;
            }
        }
        json_response(['ok' => true, 'id' => $id]);
    },

    'delete' => function () {
        api_require_role('admin','encargado');
        require_method('POST');
        $id = (int)(json_input()['id'] ?? 0);
        db()->prepare("DELETE FROM clientes WHERE id=?")->execute([$id]);
        log_action('cliente_delete', 'cliente', $id, "");
        json_response(['ok' => true]);
    },
];
