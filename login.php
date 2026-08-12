<?php
// El login se movió a /sistema/login.php — redirigir por compatibilidad.
require_once __DIR__ . '/config/config.php';
header('Location: ' . SYS_URL . '/login.php' . (isset($_GET['razon']) ? '?razon=' . urlencode($_GET['razon']) : ''));
exit;
