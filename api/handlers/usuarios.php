<?php
/**
 * CRUD de usuarios (solo admin) — modelo simplificado con horario
 */
return [
    'list' => function () {
        api_require_role('admin');
        $rows = db()->query("
            SELECT id, nombre, usuario, rol, activo,
                   horario_activo, dias_laborales, hora_entrada, hora_salida,
                   permisos_extra, permisos_quitar,
                   created_at
            FROM usuarios
            ORDER BY rol, nombre
        ")->fetchAll();
        json_response($rows);
    },

    'create' => function () {
        api_require_role('admin');
        require_method('POST');
        $in = json_input();
        $nombre   = trim($in['nombre'] ?? '');
        $usuario  = trim($in['usuario'] ?? '');
        $password = $in['password'] ?? '';
        $rol      = $in['rol'] ?? 'mesero';

        if (!$nombre || !$usuario || strlen($password) < 6) {
            json_response(['error' => 'Datos incompletos. Contraseña min. 6 caracteres.'], 400);
        }
        if (!in_array($rol, ['admin','mesero','cocina','cajero','repartidor'])) {
            json_response(['error' => 'Rol inválido'], 400);
        }

        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            db()->prepare("INSERT INTO usuarios (nombre, usuario, password_hash, rol) VALUES (?,?,?,?)")
                ->execute([$nombre, $usuario, $hash, $rol]);
            $id = (int)db()->lastInsertId();
            log_action('user_create', 'usuario', $id, "Creado: $usuario ($rol)");
            json_response(['ok' => true, 'id' => $id]);
        } catch (PDOException $e) {
            json_response(['error' => 'Usuario ya existe o error: ' . $e->getMessage()], 400);
        }
    },

    'update' => function () {
        api_require_role('admin');
        require_method('POST');
        $in = json_input();
        $id = (int)($in['id'] ?? 0);
        if (!$id) json_response(['error' => 'ID requerido'], 400);

        $sets = []; $vals = [];
        foreach (['nombre','usuario','rol'] as $f) {
            if (isset($in[$f])) { $sets[] = "$f=?"; $vals[] = $in[$f]; }
        }
        if (!empty($in['password']) && strlen($in['password']) >= 6) {
            $sets[] = "password_hash=?"; $vals[] = password_hash($in['password'], PASSWORD_BCRYPT);
        }
        if (isset($in['activo'])) { $sets[] = "activo=?"; $vals[] = (int)$in['activo']; }

        // ── Permisos personalizados ──
        // Recibe permisos_efectivos = array de módulos que el admin marcó.
        // Calcula automáticamente qué es "extra" y qué es "quitar" comparando
        // contra el default del rol.
        if (isset($in['permisos_efectivos']) && is_array($in['permisos_efectivos'])) {
            $efectivos = array_values(array_filter(array_map('trim', $in['permisos_efectivos'])));
            // Necesitamos el rol que tendrá DESPUÉS del update
            $rolNuevo = $in['rol'] ?? null;
            if (!$rolNuevo) {
                $s = db()->prepare("SELECT rol FROM usuarios WHERE id=?");
                $s->execute([$id]);
                $rolNuevo = $s->fetchColumn();
            }
            $base = ROLE_ACCESS[$rolNuevo] ?? [];
            $extra  = array_values(array_diff($efectivos, $base));
            $quitar = array_values(array_diff($base, $efectivos));
            $sets[] = "permisos_extra=?";  $vals[] = $extra  ? implode(',', $extra)  : null;
            $sets[] = "permisos_quitar=?"; $vals[] = $quitar ? implode(',', $quitar) : null;
        }

        // ── Horario ──
        if (isset($in['horario_activo'])) {
            $sets[] = "horario_activo=?"; $vals[] = (int)$in['horario_activo'];
        }
        if (array_key_exists('dias_laborales', $in)) {
            $dias = $in['dias_laborales'];
            if (is_array($dias)) {
                $dias = array_filter(array_map('intval', $dias), fn($d) => $d >= 1 && $d <= 7);
                $dias = $dias ? implode(',', $dias) : null;
            } elseif (is_string($dias) && trim($dias) === '') {
                $dias = null;
            }
            $sets[] = "dias_laborales=?"; $vals[] = $dias;
        }
        if (array_key_exists('hora_entrada', $in)) {
            $sets[] = "hora_entrada=?"; $vals[] = $in['hora_entrada'] ?: null;
        }
        if (array_key_exists('hora_salida', $in)) {
            $sets[] = "hora_salida=?"; $vals[] = $in['hora_salida'] ?: null;
        }

        if (!$sets) json_response(['error' => 'Sin cambios'], 400);
        $vals[] = $id;
        db()->prepare("UPDATE usuarios SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
        log_action('user_update', 'usuario', $id, "Editado");
        json_response(['ok' => true]);
    },

    /**
     * Devuelve el catálogo de módulos + accesos default por rol.
     * El form de usuario lo usa para pintar los checkboxes correctos.
     */
    'permisos_catalogo' => function () {
        api_require_role('admin');
        json_response([
            'catalogo'    => MODULES_CATALOG,
            'role_access' => ROLE_ACCESS,
        ]);
    },

    'delete' => function () {
        api_require_role('admin');
        require_method('POST');
        $in = json_input();
        $id = (int)($in['id'] ?? 0);
        if ($id === current_user()['id']) {
            json_response(['error' => 'No puedes desactivarte a ti mismo'], 400);
        }
        db()->prepare("UPDATE usuarios SET activo=0 WHERE id=?")->execute([$id]);
        log_action('user_delete', 'usuario', $id, "Desactivado");
        json_response(['ok' => true]);
    },
];
