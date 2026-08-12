<?php require_role('admin');
$rLat = (float)cfg('restaurante_lat', 15.012659);
$rLng = (float)cfg('restaurante_lng', -92.406619);
$horarios = db()->query("SELECT * FROM horarios_atencion ORDER BY dia")->fetchAll();
$diasNom = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
$webOn = (int)cfg('web_pedidos_activo', 1);
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<div class="topbar">
  <div><h2>Sistema · Negocio</h2><div class="sub">Datos generales, logo, ubicación y avisos que aparecen en tickets y pantallas</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">

  <!-- ───── LOGO ───── -->
  <div class="biz-form" style="margin-bottom:18px;">
    <h3 style="margin-bottom:14px;color:var(--accent);">🖼 Logo del negocio</h3>
    <div class="logo-uploader">
      <div class="logo-preview-wrap">
        <div class="logo-preview-circle">
          <img id="logoPreview"
               src="<?= APP_URL ?>/assets/img/logo.png?v=<?= file_exists(__DIR__.'/../../assets/img/logo.png') ? filemtime(__DIR__.'/../../assets/img/logo.png') : '0' ?>"
               alt="Logo actual"
               onerror="this.style.display='none'; document.getElementById('logoEmpty').style.display='grid';">
          <div id="logoEmpty" class="logo-empty" style="display:none;">
            <span>Sin logo</span>
            <small>Sube uno abajo</small>
          </div>
        </div>
      </div>

      <div class="logo-actions">
        <p style="color:var(--muted);font-size:13px;line-height:1.6;margin-bottom:12px;">
          El logo aparece en el sidebar del sistema, en la pantalla de login, en el menú público
          y en el cartel del QR. Se recorta cuadrado y se genera automáticamente en 4 tamaños
          (PWA 192/512, favicon, principal).
        </p>
        <ul style="color:var(--muted);font-size:12px;line-height:1.7;list-style:none;padding:0;margin-bottom:14px;">
          <li>📁 Formatos: <b style="color:var(--text);">PNG, JPG, WebP</b></li>
          <li>📐 Recomendado: <b style="color:var(--text);">512×512px</b> o más, cuadrado</li>
          <li>⚖ Peso máximo: <b style="color:var(--text);">5 MB</b></li>
          <li>🔄 El logo anterior queda en backup con timestamp</li>
        </ul>

        <input type="file" id="logoFile" accept="image/png,image/jpeg,image/webp" style="display:none;">
        <button class="btn btn-primary" id="logoBtnSelect">📁 Seleccionar imagen…</button>
        <button class="btn btn-success" id="logoBtnUpload" style="display:none;">⬆ Subir y reemplazar</button>
        <button class="btn btn-ghost" id="logoBtnCancel" style="display:none;">Cancelar</button>
        <div id="logoFileName" style="margin-top:10px;font-size:12px;color:var(--muted);"></div>
        <div id="logoProgress" style="display:none;margin-top:10px;">
          <div style="background:var(--panel-2);border-radius:4px;height:6px;overflow:hidden;">
            <div id="logoProgressBar" style="background:var(--accent);height:100%;width:0;transition:width .2s;"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ───── LOGO PARA TICKETS (escala de grises) ───── -->
  <div class="biz-form" style="margin-bottom:18px;">
    <h3 style="margin-bottom:6px;color:var(--accent);">🧾 Logo para tickets</h3>
    <p style="color:var(--muted);font-size:13px;line-height:1.6;margin-bottom:12px;">
      Versión del logo en <b>escala de grises con alto contraste</b>, optimizada para que se
      vea bien en la impresora térmica (que imprime en blanco y negro). Aparece en la parte
      superior de los tickets de venta.
    </p>
    <div class="logo-uploader">
      <div class="logo-preview-wrap">
        <div class="logo-preview-circle" style="background:#fff;border-radius:8px;">
          <img id="ticketLogoPreview"
               src="<?= APP_URL ?>/assets/img/logo-ticket.png?v=<?= file_exists(__DIR__.'/../../assets/img/logo-ticket.png') ? filemtime(__DIR__.'/../../assets/img/logo-ticket.png') : '0' ?>"
               alt="Logo de ticket"
               style="filter:none;"
               onerror="this.style.display='none'; document.getElementById('ticketLogoEmpty').style.display='grid';">
          <div id="ticketLogoEmpty" class="logo-empty" style="display:none;color:#666;">
            <span>Sin logo</span>
            <small>de ticket</small>
          </div>
        </div>
      </div>
      <div class="logo-actions">
        <p style="color:var(--muted);font-size:12px;line-height:1.6;margin-bottom:12px;">
          Lo más fácil: genera la versión de ticket directamente desde tu logo a color.
          También puedes subir una imagen diferente (se convertirá a grises automáticamente).
        </p>
        <button class="btn btn-primary" id="ticketLogoFromMain">✨ Generar desde el logo principal</button>
        <input type="file" id="ticketLogoFile" accept="image/png,image/jpeg,image/webp" style="display:none;">
        <button class="btn btn-ghost" id="ticketLogoBtnSelect">📁 Subir otra imagen…</button>
        <button class="btn btn-ghost" id="ticketLogoBtnDelete" style="color:var(--red);display:none;">🗑 Quitar</button>
        <div id="ticketLogoStatus" style="margin-top:10px;font-size:12px;color:var(--muted);"></div>
      </div>
    </div>
  </div>

  <!-- ───── UBICACIÓN DEL RESTAURANTE ───── -->
  <div class="biz-form" style="margin-bottom:18px;">
    <h3 style="margin-bottom:6px;color:var(--accent);">📍 Ubicación del restaurante</h3>
    <p style="color:var(--muted);font-size:13px;line-height:1.6;margin-bottom:12px;">
      Marca dónde está tu local. Sirve como referencia para los repartidores. Arrastra el
      marcador 🔴 hasta la puerta del local, o usa el botón para centrarlo en tu ubicación actual.
    </p>

    <div class="map-toolbar">
      <button type="button" class="btn btn-ghost" id="mapGpsBtn">📡 Centrar en mi ubicación</button>
      <div class="map-coords">
        <span>Lat: <code id="mapLat"><?= $rLat ?></code></span>
        <span>Lng: <code id="mapLng"><?= $rLng ?></code></span>
      </div>
      <button type="button" class="btn btn-success" id="mapSaveBtn">💾 Guardar ubicación</button>
    </div>

    <div id="bizMap" class="biz-map"></div>
  </div>

  <!-- ───── HORARIO DE ATENCIÓN + SWITCH ───── -->
  <div class="biz-form" style="margin-bottom:18px;">
    <h3 style="margin-bottom:6px;color:var(--accent);">🕐 Horario de atención (pedidos web)</h3>
    <p style="color:var(--muted);font-size:13px;line-height:1.6;margin-bottom:12px;">
      Fuera de este horario, la página de pedidos (<code><?= APP_URL ?>/</code>) y el menú muestran
      "cerrado" a los clientes. El sistema interno (<code>/sistema/</code>) sigue funcionando siempre.
    </p>

    <label class="switch-row">
      <input type="checkbox" id="webSwitch" <?= $webOn ? 'checked' : '' ?>>
      <span><b>Aceptar pedidos web</b> — apágalo para cerrar al instante (aunque sea horario)</span>
    </label>

    <table class="horario-table">
      <thead><tr><th>Día</th><th>Abierto</th><th>Desde</th><th>Hasta</th></tr></thead>
      <tbody>
        <?php foreach ($horarios as $h): ?>
          <tr data-dia="<?= $h['dia'] ?>">
            <td><b><?= $diasNom[$h['dia']] ?></b></td>
            <td><input type="checkbox" class="h-abierto" <?= $h['abierto'] ? 'checked' : '' ?>></td>
            <td><input type="time" class="h-inicio inv-input" value="<?= substr($h['hora_inicio'],0,5) ?>"></td>
            <td><input type="time" class="h-fin inv-input" value="<?= substr($h['hora_fin'],0,5) ?>"></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="text-align:right;margin-top:12px;">
      <button type="button" class="btn btn-primary" id="horarioSave">💾 Guardar horario</button>
    </div>
  </div>

  <!-- ───── DATOS DEL NEGOCIO ───── -->
  <form class="biz-form" id="bizForm">
    <h3 style="margin-bottom:14px;color:var(--accent);">🏢 Datos generales</h3>
    <div id="bizFields"></div>
    <div style="margin-top:20px;text-align:right;">
      <button type="button" class="btn btn-primary" id="bizSave">Guardar cambios</button>
    </div>
  </form>

</div>
<script>
  document.body.dataset.module = "sys-business";
  window.REST_LOC = { lat: <?= $rLat ?>, lng: <?= $rLng ?> };
</script>
