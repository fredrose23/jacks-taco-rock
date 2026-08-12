<?php
/**
 * Router del API REST
 *
 * IMPORTANTE: PHP bloquea la sesión durante todo el request. Para evitar que
 * los endpoints del API se serialicen (uno a la vez por navegador), liberamos
 * el lock de sesión inmediatamente después de la autenticación. Esto permite
 * que múltiples llamadas del API se ejecuten en paralelo sin bloquearse entre sí.
 */

// Capturar CUALQUIER output accidental (warnings, notices, var_dumps olvidados)
// para que NUNCA se mezcle con la respuesta JSON.
ob_start();

require_once __DIR__ . '/../includes/auth.php';

// Apagar display_errors DESPUÉS del require (config.php lo pone en 1 al cargar).
// Los warnings se siguen capturando vía error_handler.php → logs/error-*.log +
// tabla errores_log, pero no se imprimen al cliente.
ini_set('display_errors', '0');
ini_set('html_errors', '0');

header('Content-Type: application/json; charset=utf-8');

$route = $_GET['r'] ?? '';
$parts = explode('/', trim($route, '/'));
if (count($parts) < 2) {
    json_response(['error' => 'Ruta inválida. Formato: ?r=recurso/accion'], 400);
}

[$resource, $action] = [$parts[0], $parts[1]];
$endpoint = "$resource/$action";

// Endpoints públicos (sin sesión)
$publicRoutes = ['auth/login', 'web/menu', 'web/config', 'web/submit', 'web/estado'];
if (!in_array($endpoint, $publicRoutes)) {
    api_require_login();
}

// Endpoints que NECESITAN escribir a $_SESSION (no liberar el lock todavía)
$writesSession = ['auth/login', 'auth/logout'];

// Liberar el lock de sesión inmediatamente para no bloquear otros requests
if (!in_array($endpoint, $writesSession)) {
    session_write_close();
}

$file = __DIR__ . "/handlers/{$resource}.php";
if (!file_exists($file)) {
    json_response(['error' => "Recurso no encontrado: $resource"], 404);
}

$controller = require $file;
if (!is_array($controller) || !isset($controller[$action])) {
    json_response(['error' => "Acción no encontrada: $action"], 404);
}

try {
    $controller[$action]();
} catch (Throwable $e) {
    json_response(['error' => 'Error del servidor', 'detail' => $e->getMessage()], 500);
}
