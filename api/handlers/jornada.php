<?php
/**
 * JORNADA · apertura y cierre del negocio.
 *
 * Solo admin o encargado pueden abrir/cerrar. Mientras no haya una jornada
 * abierta nadie puede iniciar turno ni levantar pedidos, y la web del cliente
 * permanece cerrada. Al abrir, la web abre automáticamente.
 */
return [
    /** Estado actual del negocio (lo consulta el sidebar) */
    'estado' => function () {
        api_require_login();
        $j = jornada_abierta();
        $u = current_user();
        json_response([
            'abierta'      => (bool)$j,
            'jornada'      => $j ?: null,
            'puede_abrir'  => in_array($u['rol'], ['admin','encargado']),
            'abierta_por'  => $j['abierta_por_nombre'] ?? null,
            'abierta_at'   => $j['abierta_at'] ?? null,
        ]);
    },

    /** Abrir el negocio (inicia la jornada y abre la web) */
    'abrir' => function () {
        require_method('POST');
        api_require_role('admin','encargado');
        $u = current_user();
        $pdo = db();

        if (jornada_abierta()) {
            json_response(['error' => 'El negocio ya está abierto'], 400);
        }

        $in = json_input();
        $notas = trim((string)($in['notas'] ?? '')) ?: null;

        $pdo->prepare("INSERT INTO jornadas (fecha, estado, abierta_por, abierta_at, notas)
                       VALUES (CURDATE(), 'abierta', ?, NOW(), ?)")
            ->execute([$u['id'], $notas]);
        $id = (int)$pdo->lastInsertId();

        // Asegurar que el switch de pedidos web quede encendido al abrir
        $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('web_pedidos_activo','1')
                       ON DUPLICATE KEY UPDATE valor='1'")->execute();

        log_action('negocio_abrir', 'jornada', $id, "Abrió {$u['nombre']} ({$u['rol']})");
        @touch(__DIR__ . '/../../config/.sse-trigger');

        json_response([
            'ok' => true,
            'jornada_id' => $id,
            'abierta_por' => $u['nombre'],
            'hora' => date('H:i'),
        ]);
    },

    /** Cerrar el negocio (cierra la jornada y apaga la web) */
    'cerrar' => function () {
        require_method('POST');
        api_require_role('admin','encargado');
        $u = current_user();
        $pdo = db();

        $j = jornada_abierta();
        if (!$j) json_response(['error' => 'El negocio no está abierto'], 400);

        $in = json_input();
        $notas = trim((string)($in['notas'] ?? '')) ?: null;

        // Avisar si quedan cuentas sin cobrar (no bloquea, solo informa)
        $pend = (int)$pdo->query("SELECT COUNT(*) FROM ordenes WHERE estado='abierta'")->fetchColumn();

        $pdo->prepare("UPDATE jornadas
                       SET estado='cerrada', cerrada_por=?, cerrada_at=NOW(),
                           notas = COALESCE(CONCAT(COALESCE(notas,''), ?), notas)
                       WHERE id=?")
            ->execute([$u['id'], $notas ? " · Cierre: $notas" : null, $j['id']]);

        // Apagar pedidos web al cerrar el negocio
        $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('web_pedidos_activo','0')
                       ON DUPLICATE KEY UPDATE valor='0'")->execute();

        log_action('negocio_cerrar', 'jornada', (int)$j['id'],
            "Cerró {$u['nombre']} ({$u['rol']})" . ($pend ? " · {$pend} cuentas abiertas" : ''));
        @touch(__DIR__ . '/../../config/.sse-trigger');

        json_response([
            'ok' => true,
            'ordenes_abiertas' => $pend,
            'hora' => date('H:i'),
        ]);
    },

    /** Historial de aperturas (para auditoría) */
    'historial' => function () {
        api_require_role('admin','encargado','cajero');
        $rows = db()->query("
            SELECT j.*, ua.nombre AS abrio, uc.nombre AS cerro
            FROM jornadas j
            LEFT JOIN usuarios ua ON ua.id = j.abierta_por
            LEFT JOIN usuarios uc ON uc.id = j.cerrada_por
            ORDER BY j.abierta_at DESC LIMIT 60
        ")->fetchAll();
        json_response($rows);
    },
];
