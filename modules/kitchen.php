<div class="topbar">
  <div>
    <h2>Pantalla de cocina</h2>
    <div class="sub">Comandas activas · refresca en tiempo real</div>
  </div>
  <div class="meta">
    <button class="btn btn-primary" id="btnAvisoCocina">📢 Avisar a Cocina B</button>
    <span id="clockNow">--:--</span>
  </div>
</div>

<div class="content">
  <div class="kitchen-filter-bar">
    <span class="kf-lbl">Mostrar:</span>
    <button class="kf-btn active" data-cocina="0">🍽 Todas</button>
    <button class="kf-btn" data-cocina="1">🔥 Cocina A</button>
    <button class="kf-btn" data-cocina="2">🍳 Cocina B</button>
    <small style="margin-left:auto;color:var(--muted);">Tu selección se guarda en este dispositivo</small>
  </div>

  <!-- Avisos sueltos (recordatorios tipo "freír papas") -->
  <div id="avisosCocina" class="avisos-cocina"></div>

  <div class="kitchen-grid" id="kitchenGrid"></div>
  <div id="kitchenEmpty" class="empty-state" style="display:none;">
    <div class="ico">♨</div>
    <h3>No hay comandas activas</h3>
    <p>Las nuevas comandas aparecerán aquí automáticamente.</p>
  </div>
</div>

<!-- Modal: enviar aviso a cocina -->
<div class="modal-bg" id="avisoModal">
  <div class="modal" style="max-width:460px;">
    <h3>📢 Avisar a la cocina</h3>
    <div class="sub">Manda un recordatorio rápido a una estación</div>

    <label class="inv-label" style="margin-top:12px;">Estación</label>
    <select class="inv-input" id="avisoCocinaSel">
      <option value="2" selected>🍳 Cocina B</option>
      <option value="1">🔥 Cocina A</option>
    </select>

    <label class="inv-label" style="margin-top:12px;">Avisos rápidos</label>
    <div class="aviso-presets" id="avisoPresets">
      <button type="button" class="aviso-chip" data-msg="🍟 Freír bolsas de papas">🍟 Freír bolsas de papas</button>
      <button type="button" class="aviso-chip" data-msg="🥔 Preparar más papas">🥔 Preparar más papas</button>
      <button type="button" class="aviso-chip" data-msg="🧊 Reponer hielo">🧊 Reponer hielo</button>
      <button type="button" class="aviso-chip" data-msg="🔥 Encender freidora">🔥 Encender freidora</button>
    </div>

    <label class="inv-label" style="margin-top:12px;">O escribe un mensaje</label>
    <input type="text" class="inv-input" id="avisoMsg" maxlength="160" placeholder="Mensaje personalizado…">

    <div class="modal-actions" style="margin-top:16px;">
      <button class="btn btn-ghost" onclick="closeModal('avisoModal')">Cancelar</button>
      <button class="btn btn-primary" id="avisoSend">Enviar aviso</button>
    </div>
  </div>
</div>

<script>document.body.dataset.module = "kitchen";</script>
