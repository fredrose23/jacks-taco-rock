<?php
require_once dirname(__DIR__) . '/includes/auth.php';
header('Content-Type: text/html; charset=utf-8');

$error = '';
if (!empty($_GET['razon'])) {
    $error = match($_GET['razon']) {
        'horario'     => '⏱ Tu sesión se cerró porque estás fuera de tu horario.',
        'desactivado' => '🚫 Tu cuenta fue desactivada.',
        default => '',
    };
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';
    $res = attempt_login($usuario, $password);
    if ($res['ok']) {
        $role = user_role();
        $first = ROLE_ACCESS[$role][0] ?? 'tables';
        header('Location: ' . SYS_URL . '/index.php?module=' . $first);
        exit;
    }
    $error = $res['msg'] ?? 'Error al iniciar sesión';
}

if (is_logged_in()) {
    header('Location: ' . SYS_URL . '/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Ingresar · <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Bungee&family=Fredoka:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/styles.css">
</head>
<body class="login-body">
<div class="login-screen">
  <form class="login-card" method="post" autocomplete="off">
    <div class="login-logo">
      <div class="logo-wrap">
        <img src="<?= logo_url() ?>"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
        <div class="logo-fallback" style="display:none;">
          <span class="jacks">Jacks</span>
          <span class="taco">TACO ROCK</span>
        </div>
      </div>
      <h1><?= e(APP_NAME) ?></h1>
      <p><?= e(APP_SLOGAN) ?></p>
    </div>

    <?php if ($error): ?>
      <div class="login-error"><?= e($error) ?></div>
    <?php endif; ?>

    <label>Usuario</label>
    <input type="text" name="usuario" required autofocus value="<?= e($_POST['usuario'] ?? '') ?>">

    <label>Contraseña</label>
    <input type="password" name="password" required>

    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:14px;">Ingresar</button>

    <div class="login-foot">
      <a href="<?= APP_URL ?>/" class="login-back">← Ver el menú público</a>
    </div>
  </form>
</div>
</body>
</html>
