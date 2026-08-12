<?php
/**
 * Server-Sent Events para la pantalla de cocina.
 * Conexión HTTP persistente que empuja eventos cuando hay cambios.
 *
 * Trigger: cuando se envía una comanda nueva (ordenes/send_kitchen)
 *          o cambia un estado (cocina/update_status), se toca el archivo
 *          config/.sse-trigger.
 *
 * Cliente: new EventSource(APP.url + '/api/sse.php')
 */
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit;
}

/*
 * CRÍTICO: liberar el lock de sesión INMEDIATAMENTE.
 * PHP bloquea la sesión por defecto mientras un script la tiene abierta.
 * Como este script corre en bucle infinito, sin esto TODOS los demás
 * requests del mismo navegador se quedarían colgados esperando.
 */
session_write_close();

// Headers SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // nginx
header('Connection: keep-alive');
ignore_user_abort(false);
set_time_limit(0);

while (ob_get_level() > 0) ob_end_flush();

$triggerFile = __DIR__ . '/../config/.sse-trigger';
$lastMtime = file_exists($triggerFile) ? filemtime($triggerFile) : 0;
$lastPing = time();
$pingEvery = 15; // ping cada 15s para mantener conexión viva

while (!connection_aborted()) {
    clearstatcache(true, $triggerFile);
    $mtime = file_exists($triggerFile) ? filemtime($triggerFile) : 0;

    if ($mtime > $lastMtime) {
        $lastMtime = $mtime;
        // Enviar conteo y lista resumida de comandas activas
        $rows = db()->query("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN estado='nueva' THEN 1 ELSE 0 END) AS nuevas
            FROM comandas WHERE estado IN ('nueva','preparando','lista')
        ")->fetch();
        echo "event: kitchen-update\n";
        echo "data: " . json_encode($rows) . "\n\n";
        @ob_flush(); @flush();
    }

    if (time() - $lastPing >= $pingEvery) {
        echo ": ping " . date('H:i:s') . "\n\n";
        @ob_flush(); @flush();
        $lastPing = time();
    }

    usleep(800000); // 0.8 seg
}
