<?php
require_role('admin');

// URL pública del menú (auto-detecta el host actual)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$autoUrl = $scheme . '://' . $host . APP_URL . '/menu.php';

// Permitir override manual desde la configuración (para cuando el QR debe apuntar
// a la IP de la red local en vez de localhost)
$cfg = db()->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
$urlOverride = $cfg['menu_url_publica'] ?? '';
$menuUrl = trim($urlOverride) ?: $autoUrl;
?>
<div class="topbar">
  <div>
    <h2>Sistema · QR del menú público</h2>
    <div class="sub">Imprime el QR y pégalo en mesas / ventana para que clientes vean la carta digital</div>
  </div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="qr-grid">
    <!-- QR principal -->
    <div class="qr-card">
      <div class="qr-wrap" id="qrCanvas"></div>
      <div class="qr-url-box">
        <label class="inv-label">URL del menú</label>
        <div style="display:flex;gap:6px;">
          <input id="menuUrlInput" class="inv-input" value="<?= e($menuUrl) ?>" readonly>
          <button class="btn btn-ghost" onclick="copyUrl()">📋</button>
        </div>
        <small style="color:var(--muted);">Esta URL no cambia aunque actualices precios o productos. El QR siempre apunta a la carta vigente.</small>
      </div>

      <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;">
        <button class="btn btn-primary" onclick="window.open('<?= e($menuUrl) ?>','_blank')">👁 Ver menú público</button>
        <button class="btn btn-success" onclick="downloadQR()">⬇ Descargar PNG</button>
        <button class="btn btn-ghost" onclick="printPoster()">🖨 Imprimir cartel</button>
      </div>
    </div>

    <!-- Panel de instrucciones -->
    <div class="panel qr-panel">
      <h3>Cómo usarlo</h3>
      <ol style="padding-left:18px;font-size:13px;line-height:1.8;color:var(--muted);">
        <li>Descarga el QR o imprime esta página</li>
        <li>Recórtalo y pégalo en las mesas o ventana del local</li>
        <li>El cliente abre la cámara del celular y apunta al código</li>
        <li>Aparece notificación con un link → toca → ve el menú en su celular</li>
      </ol>

      <h3 style="margin-top:20px;">Tamaño del QR</h3>
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <button class="btn btn-ghost" onclick="setQrSize(180)">Pequeño</button>
        <button class="btn btn-ghost" onclick="setQrSize(280)">Mediano</button>
        <button class="btn btn-ghost" onclick="setQrSize(380)">Grande</button>
      </div>

      <h3 style="margin-top:20px;">⚠ Importante para tu red</h3>
      <p style="color:var(--muted);font-size:12px;line-height:1.6;">
        Si los clientes escanean desde la <b>WiFi del local</b>, la URL debe ser la
        <b>IP local de este servidor</b>, no <code>localhost</code>. Configúrala
        en <b>Sistema → Negocio</b> con la clave <code>menu_url_publica</code>
        (ej: <code>http://192.168.1.50/jacks-rock/menu.php</code>).
      </p>
      <?php if (strpos($menuUrl, 'localhost') !== false): ?>
        <div style="background:rgba(240,176,66,.15);color:var(--yellow);padding:10px 12px;border-radius:8px;margin-top:10px;font-size:12px;">
          ⚠ La URL actual usa <code>localhost</code> — sólo va a funcionar desde
          ESTE equipo. Configura la IP real para que funcione desde celulares.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Cartel imprimible -->
  <div class="poster-wrap">
    <div class="poster" id="posterArea">
      <div class="poster-head">
        <div class="poster-logo">
          <img src="<?= logo_url() ?>" onerror="this.style.display='none'">
        </div>
        <h1><?= e($cfg['negocio_nombre'] ?? 'JACKS ROCK') ?></h1>
        <p><?= e($cfg['negocio_eslogan'] ?? 'Más rock, mejor sabor') ?></p>
      </div>
      <div class="poster-qr" id="posterQR"></div>
      <div class="poster-body">
        <h2>📱 ESCANEA PARA VER NUESTRO MENÚ</h2>
        <p>Apunta la cámara de tu celular al código de arriba</p>
        <div class="poster-meta">
          <div>🕐 <?= e($cfg['negocio_horario'] ?? '') ?></div>
          <?php if (!empty($cfg['negocio_telefono'])): ?>
            <div>📞 <?= e($cfg['negocio_telefono']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>document.body.dataset.module = "sys-qr";</script>

<!-- QRCode.js desde CDN, fallback a API si no carga -->
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js" onerror="window._noQrLib=true"></script>
<script>
const MENU_URL = <?= json_encode($menuUrl) ?>;
let qrSize = 280;

function generateQR(size = qrSize) {
  qrSize = size;
  const target  = document.getElementById('qrCanvas');
  const posterT = document.getElementById('posterQR');
  if (window.qrcode && !window._noQrLib) {
    // Generación local (sin internet)
    [target, posterT].forEach((el, idx) => {
      const px = idx === 1 ? 360 : size;
      const q = qrcode(0, 'M');
      q.addData(MENU_URL);
      q.make();
      el.innerHTML = q.createImgTag(Math.max(4, Math.floor(px / 30)), 0);
    });
  } else {
    // Fallback API pública
    const url = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encodeURIComponent(MENU_URL)}`;
    target.innerHTML  = `<img src="${url}" alt="QR del menú">`;
    posterT.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=360x360&data=${encodeURIComponent(MENU_URL)}" alt="QR">`;
  }
}
function setQrSize(s) { generateQR(s); }

function copyUrl() {
  navigator.clipboard.writeText(MENU_URL).then(() => {
    if (typeof toast === 'function') toast('URL copiada al portapapeles');
  });
}

function downloadQR() {
  const img = document.querySelector('#qrCanvas img');
  if (!img) return;
  const a = document.createElement('a');
  a.href = img.src;
  a.download = 'jacks-rock-menu-qr.png';
  // Si es un dataURL del lib local funciona directo; si es URL externa el navegador
  // lo descarga normal en la mayoría de los casos
  document.body.appendChild(a);
  a.click();
  a.remove();
}

function printPoster() {
  document.body.classList.add('printing-poster');
  window.print();
  setTimeout(() => document.body.classList.remove('printing-poster'), 500);
}

// Esperar a que cargue qrcode lib, luego renderizar
window.addEventListener('load', () => generateQR());
</script>
