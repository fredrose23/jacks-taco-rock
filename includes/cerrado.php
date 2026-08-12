<?php
/**
 * Página mostrada cuando el restaurante está cerrado (fuera de horario o
 * cierre manual). Se incluye desde menu.php / index.php.
 * Requiere: $estado (de restaurante_abierto), $cfg (config).
 */
http_response_code(503); // Service Unavailable (temporal) + Retry-After
header('Retry-After: 3600');
header('Content-Type: text/html; charset=utf-8');

$nombre   = $cfg['negocio_nombre']   ?? 'Jacks Rock';
$eslogan  = $cfg['negocio_eslogan']  ?? '';
$telefono = $cfg['negocio_telefono'] ?? '';
$wsTel    = preg_replace('/\D/', '', $cfg['whatsapp_pedidos'] ?? ($cfg['whatsapp_domicilio'] ?? $telefono));
$semana   = horario_semanal();
$hoyN     = (int)date('N');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($nombre) ?> · Cerrado</title>
<link href="https://fonts.googleapis.com/css2?family=Bungee&family=Fredoka:wght@500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#0e2e2a;--soft:#14403a;--panel:#1a4f47;--border:rgba(255,255,255,.08);--accent:#e8743a;--accent2:#f0b042;--text:#f5ead0;--muted:#9bb8b1;--teal:#216c61;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:'Fredoka',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
  .card{max-width:420px;width:100%;text-align:center;}
  .logo{width:110px;height:110px;border-radius:50%;background:var(--teal);object-fit:cover;margin:0 auto 16px;box-shadow:0 8px 30px rgba(0,0,0,.4);}
  h1{font-family:'Bungee',sans-serif;color:var(--accent);font-size:30px;text-shadow:3px 3px 0 #000;margin-bottom:6px;}
  .tagline{color:var(--accent2);font-size:13px;margin-bottom:24px;}
  .estado{background:var(--soft);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:18px;}
  .estado .closed-icon{font-size:48px;margin-bottom:8px;}
  .estado h2{font-family:'Bungee',sans-serif;font-size:20px;color:var(--accent2);margin-bottom:8px;}
  .estado p{color:var(--muted);font-size:14px;line-height:1.5;}
  .horario{background:var(--soft);border:1px solid var(--border);border-radius:14px;padding:16px 20px;text-align:left;margin-bottom:18px;}
  .horario h3{font-size:13px;color:var(--accent);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;text-align:center;}
  .h-row{display:flex;justify-content:space-between;padding:6px 0;font-size:14px;border-bottom:1px dashed var(--border);}
  .h-row:last-child{border-bottom:none;}
  .h-row.hoy{color:var(--accent2);font-weight:700;}
  .h-row .dia{color:var(--muted);}
  .h-row.hoy .dia{color:var(--accent2);}
  .h-cerrado{color:var(--muted);opacity:.6;}
  .wa{display:inline-flex;align-items:center;gap:8px;background:#25d366;color:#fff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:700;font-size:15px;margin-top:6px;}
  .footer{color:var(--muted);font-size:11px;opacity:.6;margin-top:18px;}
</style>
</head>
<body>
  <div class="card">
    <img src="<?= APP_URL ?>/assets/img/logo.png" class="logo" alt="<?= e($nombre) ?>" onerror="this.style.display='none'">
    <h1><?= e($nombre) ?></h1>
    <?php if ($eslogan): ?><div class="tagline"><?= e($eslogan) ?></div><?php endif; ?>

    <div class="estado">
      <div class="closed-icon">🌙</div>
      <h2>Estamos cerrados</h2>
      <p><?= e($estado['msg']) ?></p>
    </div>

    <div class="horario">
      <h3>🕐 Horario de atención</h3>
      <?php foreach ($semana as $i => $d): $esHoy = ($i + 1) === $hoyN; ?>
        <div class="h-row <?= $esHoy ? 'hoy' : '' ?>">
          <span class="dia"><?= e($d['dia']) ?><?= $esHoy ? ' (hoy)' : '' ?></span>
          <span class="<?= $d['abierto'] ? '' : 'h-cerrado' ?>"><?= e($d['horario']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($wsTel): ?>
      <a class="wa" href="https://wa.me/52<?= e($wsTel) ?>" target="_blank">
        📲 Escríbenos por WhatsApp
      </a>
    <?php endif; ?>

    <div class="footer">© <?= date('Y') ?> <?= e($nombre) ?></div>
  </div>
</body>
</html>
<?php exit; ?>
