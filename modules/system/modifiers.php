<?php require_role('admin'); ?>
<div class="topbar">
  <div><h2>Sistema · Modificadores</h2><div class="sub">Grupos y opciones de personalización (extras, sin, término…)</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="toolbar"><button class="btn btn-primary" onclick="openModGroupModal()">+ Nuevo grupo</button></div>
  <div id="modGrupos"></div>
</div>

<div class="modal-bg" id="modGroupModal">
  <div class="modal">
    <h3>Grupo de modificadores</h3>
    <input type="hidden" id="mgId">
    <label class="inv-label">Nombre del grupo</label>
    <input class="inv-input" id="mgNombre" placeholder="Ej. Extras, Término, Sin...">
    <label class="inv-label">Tipo</label>
    <select class="inv-input" id="mgTipo">
      <option value="radio">Radio (elige uno)</option>
      <option value="check">Check (elige varios)</option>
    </select>
    <label class="inv-label">Máximo de selecciones (si es check)</label>
    <input type="number" class="inv-input" id="mgMax" value="1">
    <label><input type="checkbox" id="mgObligatorio"> Obligatorio</label>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('modGroupModal')">Cancelar</button>
      <button class="btn btn-primary" id="mgSave">Guardar</button>
    </div>
  </div>
</div>

<div class="modal-bg" id="modOptModal">
  <div class="modal">
    <h3>Opción</h3>
    <input type="hidden" id="moId">
    <input type="hidden" id="moGrupoId">
    <label class="inv-label">Nombre</label>
    <input class="inv-input" id="moNombre">
    <label class="inv-label">Precio extra (puede ser 0)</label>
    <input type="number" step="0.01" class="inv-input" id="moPrecio" value="0">
    <label><input type="checkbox" id="moActivo" checked> Activo</label>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('modOptModal')">Cancelar</button>
      <button class="btn btn-primary" id="moSave">Guardar</button>
    </div>
  </div>
</div>
<script>document.body.dataset.module = "sys-modifiers";</script>
