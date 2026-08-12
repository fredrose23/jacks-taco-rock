<?php require_role('admin'); ?>
<div class="topbar">
  <div><h2>Sistema · Promociones y descuentos</h2><div class="sub">Crear cupones, happy hour, descuentos por categoría</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="toolbar"><button class="btn btn-primary" onclick="openPromoModal()">+ Nueva promoción</button></div>
  <div id="promosGrid" class="data-wrap"></div>
</div>

<div class="modal-bg" id="promoModal">
  <div class="modal" style="max-width:600px;">
    <h3>Promoción</h3>
    <input type="hidden" id="prId">
    <div class="grid-2">
      <div>
        <label class="inv-label">Nombre</label>
        <input class="inv-input" id="prNombre">
        <label class="inv-label">Tipo</label>
        <select class="inv-input" id="prTipo">
          <option value="porcentaje">% Porcentaje</option>
          <option value="monto_fijo">$ Monto fijo</option>
          <option value="2x1">2x1</option>
          <option value="3x100">3 x $100</option>
        </select>
        <label class="inv-label">Valor <small style="color:var(--muted);">(% o $ según tipo)</small></label>
        <input type="number" step="0.01" class="inv-input" id="prValor">
        <label class="inv-label">Código de cupón (opcional)</label>
        <input class="inv-input" id="prCodigo" placeholder="HAPPY20">
        <label class="inv-label">Uso máximo</label>
        <input type="number" class="inv-input" id="prUsoMax" placeholder="Sin límite">
      </div>
      <div>
        <label class="inv-label">Aplicable a</label>
        <select class="inv-input" id="prAplicable">
          <option value="todo">Toda la orden</option>
          <option value="categoria">Categoría específica</option>
          <option value="producto">Producto específico</option>
        </select>
        <label class="inv-label">Categoría / Producto</label>
        <select class="inv-input" id="prTarget"></select>
        <label class="inv-label">Vigencia</label>
        <div class="grid-2" style="gap:6px;">
          <input type="date" class="inv-input" id="prDesde">
          <input type="date" class="inv-input" id="prHasta">
        </div>
        <label class="inv-label">Horario (opcional · Happy Hour)</label>
        <div class="grid-2" style="gap:6px;">
          <input type="time" class="inv-input" id="prHoraDesde">
          <input type="time" class="inv-input" id="prHoraHasta">
        </div>
      </div>
    </div>
    <label class="inv-label">Descripción</label>
    <textarea class="inv-input" id="prDesc" rows="2"></textarea>
    <label><input type="checkbox" id="prActiva" checked> Activa</label>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('promoModal')">Cancelar</button>
      <button class="btn btn-primary" id="prSave">Guardar</button>
    </div>
  </div>
</div>
<script>document.body.dataset.module = "sys-promotions";</script>
