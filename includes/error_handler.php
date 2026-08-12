<?php
/**
 * Manejador global de errores: persiste a archivo Y base de datos
 *
 * Captura:
 *  - Excepciones no manejadas (set_exception_handler)
 *  - Errores PHP de cualquier nivel (set_error_handler)
 *  - Errores fatales y de parsing (register_shutdown_function)
 */

if (!defined('JR_LOG_DIR')) {
    define('JR_LOG_DIR', __DIR__ . '/../logs');
}

/**
 * Persiste un error al archivo y a la BD
 */
function jr_log_error(array $err): void {
    // ---------- 1. archivo de log (siempre funciona, sin depender de BD) ----------
    if (!is_dir(JR_LOG_DIR)) @mkdir(JR_LOG_DIR, 0775, true);
    $logFile = JR_LOG_DIR . '/error-' . date('Y-m-d') . '.log';
    $line = sprintf(
        "[%s] [%s] %s\n  en %s:%d\n  URL: %s %s\n  IP: %s\n%s\n%s\n",
        date('Y-m-d H:i:s'),
        $err['tipo'] ?? 'Unknown',
        $err['mensaje'] ?? '',
        $err['archivo'] ?? '?',
        $err['linea'] ?? 0,
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $_SERVER['REQUEST_URI'] ?? '-',
        $_SERVER['REMOTE_ADDR'] ?? '-',
        !empty($err['stack']) ? "  Stack:\n    " . str_replace("\n", "\n    ", $err['stack']) : '',
        str_repeat('-', 60)
    );
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    // ---------- 2. base de datos (mejor para el visor del admin) ----------
    try {
        if (function_exists('db')) {
            $userId = $_SESSION['user']['id'] ?? null;
            db()->prepare("
                INSERT INTO errores_log
                  (tipo, mensaje, archivo, linea, stack, url, metodo, usuario_id, ip, user_agent)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                substr($err['tipo'] ?? 'Unknown', 0, 20),
                $err['mensaje'] ?? '',
                $err['archivo'] ?? null,
                $err['linea'] ?? null,
                $err['stack'] ?? null,
                substr($_SERVER['REQUEST_URI'] ?? '', 0, 255),
                substr($_SERVER['REQUEST_METHOD'] ?? '', 0, 10),
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        }
    } catch (Throwable $_) {
        // si la BD también falla, ya quedó en archivo
    }
}

/**
 * Excepciones no manejadas
 */
set_exception_handler(function (Throwable $e) {
    jr_log_error([
        'tipo'    => 'Exception',
        'mensaje' => $e->getMessage(),
        'archivo' => $e->getFile(),
        'linea'   => $e->getLine(),
        'stack'   => $e->getTraceAsString(),
    ]);
    // Mantener respuesta legible para el cliente
    if (!headers_sent()) {
        http_response_code(500);
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error'  => 'Error interno del servidor',
                'detail' => $e->getMessage(),
            ]);
        } else {
            echo '<h1>Error interno</h1><p>Reportado automáticamente al administrador.</p>';
        }
    }
});

/**
 * Errores PHP (warnings, notices, deprecations, etc.)
 */
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    $tipo = match (true) {
        in_array($severity, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR]) => 'Error',
        in_array($severity, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING]) => 'Warning',
        in_array($severity, [E_NOTICE, E_USER_NOTICE]) => 'Notice',
        in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED]) => 'Deprecated',
        default => 'Error[' . $severity . ']',
    };
    jr_log_error([
        'tipo'    => $tipo,
        'mensaje' => $message,
        'archivo' => $file,
        'linea'   => $line,
    ]);
    return false; // dejar que PHP siga su proceso normal
});

/**
 * Errores fatales / parse errors (no se capturan con set_error_handler)
 */
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    jr_log_error([
        'tipo'    => 'Fatal',
        'mensaje' => $err['message'],
        'archivo' => $err['file'],
        'linea'   => $err['line'],
    ]);
});
