<?php
require_once dirname(__DIR__) . '/includes/auth.php';
logout();
header('Location: ' . SYS_URL . '/login.php');
exit;
