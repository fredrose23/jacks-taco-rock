<?php require_role('admin'); ?>
<div class="topbar">
  <div><h2>Sistema · Mesas</h2><div class="sub">Definir cantidad, capacidad y zonas</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="toolbar"><button class="btn btn-primary" onclick="openMesaModal()">+ Nueva mesa</button></div>
  <div id="mesasGrid" class="data-wrap"></div>
</div>

<div class="modal-bg" id="mesaModal">
  <div class="modal">
    <h3 id="mesaTitle">Nueva mesa</h3>
    <input type="hidden" id="mId">
    <label class="inv-label">Número <small style="color:var(--muted);">(identificador interno)</small></label>
    <input type="number" class="inv-input" id="mNumero" min="1">
    <label class="inv-label">Nombre <small style="color:var(--muted);">(opcional · cómo la ves en pantallas y tickets)</small></label>
    <input class="inv-input" id="mNombre" maxlength="40" placeholder="Ej. Terraza 1, Barra K (vacío = &quot;Mesa 05&quot;)">
    <label class="inv-label">Capacidad</label>
    <input type="number" class="inv-input" id="mCapacidad" value="4" min="1">
    <label class="inv-label">Zona</label>
    <select class="inv-input" id="mZona">
      <option>Salón</option><option>Terraza</option><option>Barra</option><option>VIP</option>
    </select>
    <label class="inv-label">Descripción <small style="color:var(--muted);">(material, ubicación, características)</small></label>
    <input class="inv-input" id="mDescripcion" placeholder="Ej. Mesa de madera, cerca de la ventana">
    <label class="inv-label"><input type="checkbox" id="mActiva" checked> Mesa activa</label>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('mesaModal')">Cancelar</button>
      <button class="btn btn-primary" id="mSave">Guardar</button>
    </div>
  </div>
</div>
<script>document.body.dataset.module = "sys-tables";</script>
