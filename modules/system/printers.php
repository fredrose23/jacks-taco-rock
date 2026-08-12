<?php require_role('admin'); ?>
<div class="topbar">
  <div><h2>Sistema · Impresoras</h2><div class="sub">Estaciones de impresión por ubicación (Cocina, Barra, Caja)</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="toolbar"><button class="btn btn-primary" onclick="openPrtModal()">+ Nueva impresora</button></div>
  <div id="prtGrid" class="data-wrap"></div>

  <div class="panel" style="margin-top:20px;">
    <h3>¿Cómo funciona el routing?</h3>
    <p style="font-size:13px;color:var(--muted);line-height:1.7;">
      Cada <b>categoría</b> tiene una impresora default (configurable en <b>Sistema → Menú → Categorías</b>).<br>
      Cada <b>producto</b> puede sobrescribir esa default.<br>
      Cuando se envía una comanda, los items se <b>agrupan por impresora</b> y se imprime una hoja por estación.<br>
      <small>Por ejemplo: una orden con Burger + Coca se imprime en Cocina A (parrilla) y en Barra.</small>
    </p>
  </div>
</div>

<div class="modal-bg" id="prtModal">
  <div class="modal">
    <h3>Impresora</h3>
    <input type="hidden" id="prtId">
    <label class="inv-label">Nombre</label>
    <input class="inv-input" id="prtNombre">
    <label class="inv-label">Ubicación</label>
    <select class="inv-input" id="prtUbicacion">
      <option value="cocina">Cocina</option>
      <option value="barra">Barra</option>
      <option value="caja">Caja</option>
    </select>
    <label class="inv-label">Tipo</label>
    <select class="inv-input" id="prtTipo">
      <option value="comanda">Solo comanda</option>
      <option value="ticket">Solo ticket</option>
      <option value="ambos">Ambos</option>
    </select>
    <div class="grid-2">
      <div>
        <label class="inv-label">Driver</label>
        <select class="inv-input" id="prtDriver">
          <option value="navegador">Navegador (window.print)</option>
          <option value="escpos_usb">ESC/POS USB</option>
          <option value="escpos_red">ESC/POS Red</option>
        </select>
      </div>
      <div>
        <label class="inv-label">Destino (IP:puerto o ruta)</label>
        <input class="inv-input" id="prtDestino" placeholder="ej. 192.168.1.50:9100">
      </div>
      <div>
        <label class="inv-label">Ancho (mm)</label>
        <input type="number" class="inv-input" id="prtAncho" value="80">
      </div>
      <div>
        <label class="inv-label">Copias</label>
        <input type="number" class="inv-input" id="prtCopias" value="1">
      </div>
    </div>
    <label><input type="checkbox" id="prtActiva" checked> Activa</label>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('prtModal')">Cancelar</button>
      <button class="btn btn-primary" id="prtSave">Guardar</button>
    </div>
  </div>
</div>
<script>document.body.dataset.module = "sys-printers";</script>
