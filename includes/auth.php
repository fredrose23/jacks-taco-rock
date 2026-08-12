<?php
/**
 * Sistema de autenticación, autorización por roles y auditoría
 *
 * MODELO DE HORARIO (estilo Chulisima):
 *   Cada usuario puede tener UN horario fijo: días + hora de entrada/salida.
 *   El admin NUNCA se restringe (siempre puede entrar).
 *   Otros roles solo entran si tienen el flag desactivado, o si está activado
 *   y la hora actual está dentro de su ventana.
 *
 *   Si la sesión está activa y el usuario sale de su ventana, el siguiente
 *   request lo desconecta automáticamente.
 */
require_once __DIR__ . '/functions.php';

/**
 * Permisos por rol — define QUÉ ÍTEM del sidebar puede ver cada rol.
 * El primer ítem es la pantalla a la que cae por default al hacer login.
 *
 *  admin       → ve TODO
 *  encargado   → como admin PERO sin "system" (no crea usuarios ni toca config).
 *                Es quien abre/cierra el negocio y autoriza cancelaciones.
 *  mesero      → mesas, menú, cocina (ver), domicilios (ver), reservas, mi turno
 *  cocina      → cocina + menú (consulta)
 *  cajero      → casi todo lo operativo + reportes + cierres
 *  repartidor  → domicilios (suyos), cocina (ver), menú (consulta)
 */
const ROLE_ACCESS = [
    'admin'      => ['dashboard','tables','menu','orders','kitchen','delivery','takeout','web_orders',
                     'reservations','tickets','reports','closures','cash','tips',
                     'inventory','clients','my_shift','system'],
    'encargado'  => ['dashboard','tables','menu','orders','kitchen','delivery','takeout','web_orders',
                     'reservations','tickets','reports','closures','cash','tips',
                     'inventory','clients','my_shift'],
    'mesero'     => ['tables','menu','orders','kitchen','delivery','takeout','web_orders',
                     'reservations','clients','my_shift','dashboard'],
    'cocina'     => ['kitchen','menu','my_shift','dashboard'],
    'cajero'     => ['tables','orders','kitchen','menu','delivery','takeout','web_orders',
                     'tickets','reports','closures','cash','clients','my_shift','dashboard'],
    'repartidor' => ['delivery','kitchen','menu','my_shift','dashboard'],
];

/**
 * Catálogo de TODOS los módulos del sistema, agrupados para el form de
 * permisos por usuario.
 */
const MODULES_CATALOG = [
    'Operación' => [
        'tables'       => ['▦', 'Mesas'],
        'orders'       => ['🛒', 'Pedido'],
        'kitchen'      => ['♨', 'Cocina'],
        'web_orders'   => ['🌐', 'Pedidos web'],
        'takeout'      => ['📦', 'Para llevar'],
        'delivery'     => ['🛵', 'Domicilios'],
        'reservations' => ['📅', 'Reservaciones'],
    ],
    'Personal & Consulta' => [
        'my_shift'     => ['⏱', 'Mi turno'],
        'dashboard'    => ['📊', 'Mi dashboard'],
        'menu'         => ['☰', 'Menú (consulta)'],
        'clients'      => ['👤', 'Clientes'],
    ],
    'Reportes & Caja' => [
        'cash'         => ['💰', 'Caja · Cortes'],
        'tips'         => ['🪙', 'Propinas'],
        'tickets'      => ['🧾', 'Tickets · Ventas'],
        'reports'      => ['▤', 'Reportes'],
        'closures'     => ['📋', 'Cierres consolidados'],
    ],
    'Administración' => [
        'inventory'    => ['📦', 'Inventario'],
        'system'       => ['⚙', 'Sistema (admin)'],
    ],
];

/** Submódulos de Sistema (solo admin) */
const SYSTEM_MODULES = [
    'users'       => ['Usuarios',          '👥'],
    'tables'      => ['Mesas',             '🪑'],
    'menu'        => ['Menú · Productos',  '🍽'],
    'modifiers'   => ['Modificadores',     '⚙'],
    'combos'      => ['Combos',            '🎁'],
    'printers'    => ['Impresoras',        '🖨'],
    'qr'          => ['QR del menú',       '📱'],
    'promotions'  => ['Promociones',       '🏷'],
    'business'    => ['Negocio',           '🏢'],
    'audit'       => ['Bitácora',          '📋'],
    'errors'      => ['Errores · Crashes', '🐞'],
];

function current_user(): ?array { return $_SESSION['user'] ?? null; }
function is_logged_in(): bool { return !empty($_SESSION['user']); }
function user_role(): ?string { return $_SESSION['user']['rol'] ?? null; }

/**
 * Calcula los módulos efectivos del usuario actual:
 *   (default del rol) + permisos_extra − permisos_quitar
 * Resultado cacheado por request.
 */
function effective_modules(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $u = current_user();
    if (!$u) return $cache = [];

    $base = ROLE_ACCESS[$u['rol']] ?? [];

    try {
        $stmt = db()->prepare("SELECT permisos_extra, permisos_quitar FROM usuarios WHERE id=?");
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();
        $extra  = $row['permisos_extra']  ? array_filter(array_map('trim', explode(',', $row['permisos_extra'])))  : [];
        $quitar = $row['permisos_quitar'] ? array_filter(array_map('trim', explode(',', $row['permisos_quitar']))) : [];
    } catch (Throwable $e) {
        $extra = $quitar = [];
    }

    $cache = array_values(array_unique(array_diff(array_merge($base, $extra), $quitar)));
    return $cache;
}

function can_access(string $module): bool {
    if (!user_role()) return false;
    $top = explode('/', $module)[0];
    return in_array($top, effective_modules());
}

/**
 * Verifica si un usuario puede entrar / continuar sesión según su horario.
 *  - Admin: SIEMPRE (no se restringe)
 *  - horario_activo = 0: SIEMPRE (sin restricción)
 *  - horario_activo = 1: revisa día actual (1=Lun..7=Dom) en dias_laborales
 *                       (vacío = todos los días) y hora actual en ventana
 *
 * @return ['ok' => bool, 'msg' => string]
 */
function check_schedule(array $user): array {
    if ($user['rol'] === 'admin') return ['ok' => true];
    if (empty($user['horario_activo'])) return ['ok' => true];

    $diaActual = (int)date('N'); // 1..7 (ISO)
    $horaActual = date('H:i:s');

    // Días: csv como "1,2,3,4,5" o vacío = todos
    $dias = trim((string)($user['dias_laborales'] ?? ''));
    if ($dias !== '') {
        $arr = array_filter(array_map('intval', explode(',', $dias)));
        if ($arr && !in_array($diaActual, $arr, true)) {
            return ['ok' => false, 'msg' => 'No tienes acceso hoy según tu horario configurado.'];
        }
    }

    $entrada = $user['hora_entrada'] ?? null;
    $salida  = $user['hora_salida']  ?? null;
    if ($entrada && $salida) {
        // Permite ventanas que cruzan medianoche (ej. 18:00 a 02:00)
        if ($entrada <= $salida) {
            if ($horaActual < $entrada || $horaActual > $salida) {
                return ['ok' => false,
                  'msg' => "Tu horario es de " . substr($entrada,0,5) . " a " . substr($salida,0,5) . "."];
            }
        } else {
            // Ventana nocturna: válida si hora >= entrada o hora <= salida
            if (!($horaActual >= $entrada || $horaActual <= $salida)) {
                return ['ok' => false,
                  'msg' => "Tu horario es de " . substr($entrada,0,5) . " a " . substr($salida,0,5) . "."];
            }
        }
    }
    return ['ok' => true];
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . SYS_URL . '/login.php');
        exit;
    }
    // Verificación en cada request: si salió de su horario, cierra sesión
    $u = current_user();
    // Recargar datos frescos (por si admin cambió su horario en vivo)
    static $checked = false;
    if (!$checked) {
        $checked = true;
        $stmt = db()->prepare("SELECT activo, horario_activo, dias_laborales, hora_entrada, hora_salida, rol FROM usuarios WHERE id=?");
        $stmt->execute([$u['id']]);
        if ($row = $stmt->fetch()) {
            if (!$row['activo']) {
                logout();
                header('Location: ' . SYS_URL . '/login.php?razon=desactivado');
                exit;
            }
            $u = array_merge($u, $row);
            $check = check_schedule($u);
            if (!$check['ok']) {
                logout();
                header('Location: ' . SYS_URL . '/login.php?razon=horario');
                exit;
            }
        }
    }
}

function require_role(string ...$roles): void {
    require_login();
    if (!in_array(user_role(), $roles)) {
        http_response_code(403);
        die('Acceso denegado.');
    }
}

function api_require_login(): array {
    if (!is_logged_in()) json_response(['error' => 'No autenticado'], 401);
    return current_user();
}

function api_require_role(string ...$roles): array {
    $u = api_require_login();
    if (!in_array($u['rol'], $roles)) json_response(['error' => 'Rol insuficiente'], 403);
    return $u;
}

function attempt_login(string $usuario, string $password): array {
    $stmt = db()->prepare("SELECT * FROM usuarios WHERE usuario=? AND activo=1");
    $stmt->execute([$usuario]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return ['ok' => false, 'msg' => 'Usuario o contraseña incorrectos'];
    }

    $check = check_schedule($u);
    if (!$check['ok']) {
        log_action('login_bloqueado', 'usuario', (int)$u['id'], 'Fuera de horario');
        return ['ok' => false, 'msg' => $check['msg']];
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'      => (int)$u['id'],
        'nombre'  => $u['nombre'],
        'usuario' => $u['usuario'],
        'rol'     => $u['rol'],
    ];
    log_action('login', 'usuario', (int)$u['id'], 'Inicio de sesión');
    return ['ok' => true];
}

function logout(): void {
    if (is_logged_in()) log_action('logout', 'usuario', current_user()['id'], 'Cerró sesión');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function log_action(string $accion, ?string $entidad = null, ?int $entidad_id = null, string $descripcion = ''): void {
    try {
        $userId = current_user()['id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        db()->prepare("INSERT INTO bitacora (usuario_id, accion, entidad, entidad_id, descripcion, ip) VALUES (?,?,?,?,?,?)")
            ->execute([$userId, $accion, $entidad, $entidad_id, $descripcion, $ip]);
    } catch (Throwable $e) {}
}

// cfg() se movió a functions.php (lo usan también páginas públicas como menu.php)

/**
 * ID del turno (corte) abierto del usuario actual, si lo hay
 */
function current_turno_id(): ?int {
    $u = current_user();
    if (!$u) return null;
    static $cached = null;
    if ($cached !== null) return $cached ?: null;
    $stmt = db()->prepare("SELECT id FROM turnos WHERE usuario_id=? AND estado='en_curso' LIMIT 1");
    $stmt->execute([$u['id']]);
    $cached = (int)($stmt->fetchColumn() ?: 0);
    return $cached ?: null;
}
