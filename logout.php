<?php
// El logout se movió a /sistema/logout.php — redirigir por compatibilidad.
require_once __DIR__ . '/config/config.php';
header('Location: ' . SYS_URL . '/logout.php');
exit;
