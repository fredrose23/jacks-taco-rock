<?php
/**
 * Router del SISTEMA (sistema/index.php)
 *   sistema/?module=tables|orders|kitchen|...
 *   sistema/?module=system&sub=users|tables|...
 *
 * Los recursos compartidos (includes, modules, assets, api) viven en la raíz
 * del proyecto, un nivel arriba.
 */
define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/includes/auth.php';
require_login();

$module = $_GET['module'] ?? null;
$sub    = $_GET['sub'] ?? null;

$allowed = ['tables','menu','orders','kitchen','reports','inventory','cash','tips','reservations','system',
            'dashboard','my_shift','delivery','clients','closures','tickets','takeout','web_orders'];

if (!$module) {
    $module = ROLE_ACCESS[user_role()][0] ?? 'tables';
}

// OBLIGAR TURNO ABIERTO: sin turno en curso, lo único accesible es "Mi turno".
// Aplica a TODOS (incluido admin). Al abrir el turno se desbloquea el resto.
if ($module !== 'my_shift' && !current_turno_id()) {
    header('Location: ' . SYS_URL . '/index.php?module=my_shift&abrir=1');
    exit;
}

if (!in_array($module, $allowed, true) || !can_access($module)) {
    $module = ROLE_ACCESS[user_role()][0] ?? 'tables';
    header('Location: ' . SYS_URL . '/index.php?module=' . $module);
    exit;
}

// System: resolver submódulo
if ($module === 'system') {
    require_role('admin');
    $sub = $sub ?: 'users';
    if (!array_key_exists($sub, SYSTEM_MODULES)) $sub = 'users';
    $file = ROOT_DIR . "/modules/system/{$sub}.php";
} else {
    $file = ROOT_DIR . "/modules/{$module}.php";
}

if (!file_exists($file)) {
    http_response_code(404);
    die("Módulo no encontrado: $module" . ($sub ? "/$sub" : ''));
}

include ROOT_DIR . '/includes/header.php';
include ROOT_DIR . '/includes/sidebar.php';
include $file;
include ROOT_DIR . '/includes/footer.php';
