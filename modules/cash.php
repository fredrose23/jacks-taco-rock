<div class="topbar">
  <div><h2>Caja · Cortes y arqueo</h2><div class="sub" id="cashSub">Estado del turno actual</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div id="cashStatus"></div>

  <div class="panel" style="margin-top:20px;">
    <h3>Historial de cortes (últimos 30)</h3>
    <div id="cashHistory"></div>
  </div>
</div>

<!-- Modal abrir corte -->
<div class="modal-bg" id="openCorteModal">
  <div class="modal">
    <h3>Abrir corte de caja</h3>
    <div class="sub">Registra el efectivo con el que inicias el turno</div>
    <label class="inv-label">Fondo inicial (efectivo)</label>
    <input type="number" step="0.01" class="inv-input" id="fondoInicial" value="0">
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('openCorteModal')">Cancelar</button>
      <button class="btn btn-success" id="abrirCorte">Abrir caja</button>
    </div>
  </div>
</div>

<!-- Modal cerrar corte -->
<div class="modal-bg" id="closeCorteModal">
  <div class="modal">
    <h3>Cerrar corte de caja</h3>
    <div class="sub">Cuenta el efectivo físico y registra diferencias</div>
    <div class="pay-summary" id="closeCorteSummary"></div>
    <label class="inv-label">Efectivo contado en caja</label>
    <input type="number" step="0.01" class="inv-input" id="efectivoContado" value="0">
    <label class="inv-label">Observaciones</label>
    <textarea class="inv-input" id="corteObs" rows="2"></textarea>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('closeCorteModal')">Cancelar</button>
      <button class="btn btn-success" id="cerrarCorte">Cerrar caja</button>
    </div>
  </div>
</div>
<script>document.body.dataset.module = "cash";</script>
