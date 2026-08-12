<div class="topbar">
  <div><h2>Mi turno</h2><div class="sub" id="myShiftSub">Cargando…</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div id="myShiftStatus"></div>

  <div class="panel" style="margin-top:20px;">
    <h3>Mis cortes recientes</h3>
    <div id="myShiftHistory"></div>
  </div>
</div>

<!-- Modal · Abrir turno -->
<div class="modal-bg" id="openShiftModal">
  <div class="modal">
    <h3>Abrir turno</h3>
    <div class="sub">Registra el fondo inicial con el que arrancas (efectivo en caja para dar cambios)</div>
    <label class="inv-label">Fondo inicial (efectivo)</label>
    <input type="number" step="0.01" class="inv-input" id="msFondo" value="0">
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('openShiftModal')">Cancelar</button>
      <button class="btn btn-success" id="msAbrir">Abrir turno</button>
    </div>
  </div>
</div>

<!-- Modal · Cerrar turno -->
<div class="modal-bg" id="closeShiftModal">
  <div class="modal">
    <h3>Cerrar mi turno</h3>
    <div class="sub">Cuenta el efectivo físico y registra cualquier diferencia</div>
    <div class="pay-summary" id="msCloseSummary"></div>
    <label class="inv-label">Efectivo contado en tu caja</label>
    <input type="number" step="0.01" class="inv-input" id="msContado" value="0">
    <label class="inv-label">Observaciones (opcional)</label>
    <textarea class="inv-input" id="msObs" rows="2"></textarea>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('closeShiftModal')">Cancelar</button>
      <button class="btn btn-success" id="msCerrar">Cerrar turno</button>
    </div>
  </div>
</div>

<script>document.body.dataset.module = "my_shift";</script>
