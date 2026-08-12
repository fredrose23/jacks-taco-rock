<?php $hoy = date('Y-m-d'); ?>
<div class="topbar">
  <div><h2>Reservaciones</h2><div class="sub" id="resSub">Cargando...</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="date-range-bar">
    <label>Desde <input type="date" id="resDesde" value="<?= $hoy ?>"></label>
    <label>Hasta <input type="date" id="resHasta" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></label>
    <button class="btn btn-primary" id="resApply">Aplicar</button>
    <button class="btn btn-success" onclick="openResModal()">+ Nueva reservación</button>
  </div>

  <div id="resList"></div>
</div>

<div class="modal-bg" id="resModal">
  <div class="modal" style="max-width:520px;">
    <h3>Reservación</h3>
    <input type="hidden" id="rId">
    <div class="grid-2">
      <div>
        <label class="inv-label">Cliente</label>
        <input class="inv-input" id="rNombre">
        <label class="inv-label">Teléfono</label>
        <input class="inv-input" id="rTel">
        <label class="inv-label">Personas</label>
        <input type="number" class="inv-input" id="rPersonas" value="2" min="1">
      </div>
      <div>
        <label class="inv-label">Fecha</label>
        <input type="date" class="inv-input" id="rFecha" value="<?= $hoy ?>">
        <label class="inv-label">Hora</label>
        <input type="time" class="inv-input" id="rHora" value="19:00">
        <label class="inv-label">Mesa (opcional)</label>
        <select class="inv-input" id="rMesa"><option value="">Cualquiera</option></select>
      </div>
    </div>
    <label class="inv-label">Notas</label>
    <textarea class="inv-input" id="rNotas" rows="2" placeholder="Cumpleaños, alergias, etc."></textarea>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('resModal')">Cancelar</button>
      <button class="btn btn-primary" id="rSave">Guardar</button>
    </div>
  </div>
</div>
<script>document.body.dataset.module = "reservations";</script>
