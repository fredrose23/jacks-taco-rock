<div class="topbar">
  <div><h2>Clientes frecuentes</h2><div class="sub" id="clientsSub">Cargando…</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="toolbar">
    <input class="inv-input" id="cliSearch" placeholder="🔍 Buscar por teléfono o nombre…" style="flex:1;max-width:380px;">
    <button class="btn btn-primary" onclick="openCliModal()">+ Nuevo cliente</button>
  </div>
  <div id="cliGrid"></div>
</div>

<div class="modal-bg" id="cliModal">
  <div class="modal" style="max-width:520px;">
    <h3 id="cliTitle">Cliente</h3>
    <input type="hidden" id="cliId">
    <div class="grid-2">
      <div>
        <label class="inv-label">Teléfono *</label>
        <input class="inv-input" id="cliTel" inputmode="numeric">
        <label class="inv-label">Nombre *</label>
        <input class="inv-input" id="cliNombre">
      </div>
      <div>
        <label class="inv-label">Dirección</label>
        <input class="inv-input" id="cliDir">
        <label class="inv-label">Referencias</label>
        <input class="inv-input" id="cliRef" placeholder="Casa azul, frente al parque…">
      </div>
    </div>
    <label class="inv-label">Notas internas</label>
    <textarea class="inv-input" id="cliNotas" rows="2"></textarea>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('cliModal')">Cancelar</button>
      <button class="btn btn-primary" id="cliSave">Guardar</button>
    </div>
  </div>
</div>
<script>document.body.dataset.module = "clients";</script>
