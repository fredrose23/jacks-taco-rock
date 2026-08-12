<div class="topbar">
  <div><h2>🌐 Pedidos web</h2><div class="sub" id="webSub">Pedidos recibidos desde la página pública</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="toolbar">
    <button class="cat-tab active" data-web-filter="pendientes">⏳ Pendientes <span id="webPendCount" class="nav-badge" style="display:none;"></span></button>
    <button class="cat-tab" data-web-filter="aceptados">✓ Aceptados</button>
    <button class="cat-tab" data-web-filter="rechazados">✗ Rechazados hoy</button>
    <button class="cat-tab" data-web-filter="hoy">Todos del día</button>
    <label style="margin-left:auto;display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);">
      <input type="checkbox" id="webSound" checked> 🔔 Sonido
    </label>
  </div>
  <div id="webGrid"></div>
</div>

<script>document.body.dataset.module = "web_orders";</script>
