<?php
require_once __DIR__ . '/functions.php';
header('Content-Type: text/html; charset=utf-8');
// Evitar caché del HTML; los assets (JS/CSS) ya tienen ?v= con mtime para
// que el navegador detecte cambios automáticamente.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$current = $_GET['module'] ?? 'tables';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="theme-color" content="#0e2e2a">
<title><?= e(APP_NAME) ?> · <?= ucfirst($current) ?></title>
<link rel="manifest" href="<?= APP_URL ?>/manifest.json">
<link rel="icon" type="image/png" href="<?= logo_url() ?>">
<link href="https://fonts.googleapis.com/css2?family=Bungee&family=Fredoka:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/styles.css?v=<?= asset_v('assets/css/styles.css') ?>">
<script>
  window.APP = {
    url:    "<?= APP_URL ?>",
    sysUrl: "<?= SYS_URL ?>",
    api:    "<?= APP_URL ?>/api/",
    tax:    <?= TAX_RATE ?>,
    name:   "<?= e(APP_NAME) ?>",
    user:   <?= json_encode($user) ?>
  };
  window.APP_CFG = <?= json_encode([
    'negocio_nombre'   => cfg('negocio_nombre',   APP_NAME),
    'negocio_eslogan'  => cfg('negocio_eslogan',  APP_SLOGAN),
    'negocio_horario'  => cfg('negocio_horario',  ''),
    'negocio_telefono' => cfg('negocio_telefono', ''),
    'ticket_pie'       => cfg('ticket_pie',       '¡GRACIAS POR TU COMPRA!'),
  ]) ?>;

  // Service worker (PWA)
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register("<?= APP_URL ?>/sw.js").catch(()=>{});
    });
  }
</script>
</head>
<body>
<div class="app">
