<?php require_role('admin'); ?>
<div class="topbar">
  <div><h2>Sistema · Combos y paquetes</h2><div class="sub">Agrupar productos con precio especial</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="toolbar"><button class="btn btn-primary" onclick="openComboModal()">+ Nuevo combo</button></div>
  <div id="combosGrid" class="data-wrap"></div>
</div>

<div class="modal-bg" id="comboModal">
  <div class="modal" style="max-width:580px;">
    <h3>Combo</h3>
    <input type="hidden" id="cmId">
    <div class="grid-2">
      <div>
        <label class="inv-label">Nombre</label>
        <input class="inv-input" id="cmNombre">
        <label class="inv-label">Emoji</label>
        <input class="inv-input" id="cmEmoji" value="🎁">
      </div>
      <div>
        <label class="inv-label">Precio combo</label>
        <input type="number" step="0.01" class="inv-input" id="cmPrecio">
        <label class="inv-label"><input type="checkbox" id="cmActivo" checked> Activo</label>
      </div>
    </div>
    <label class="inv-label">Descripción</label>
    <textarea class="inv-input" id="cmDesc" rows="2"></textarea>

    <label class="inv-label">Productos incluidos</label>
    <div id="cmItemsList"></div>
    <button class="split-add" id="cmAddItem">+ Agregar producto</button>

    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('comboModal')">Cancelar</button>
      <button class="btn btn-primary" id="cmSave">Guardar</button>
    </div>
  </div>
</div>
<script>document.body.dataset.module = "sys-combos";</script>
