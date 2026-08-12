<?php $hoy = date('Y-m-d'); ?>
<div class="topbar">
  <div>
    <h2>Reportes</h2>
    <div class="sub" id="rptRangeLbl">Cargando…</div>
  </div>
  <div class="meta">
    <span id="clockNow">--:--</span>
  </div>
</div>

<div class="content">
  <div class="date-range-bar">
    <label>Desde <input type="date" id="rptDesde" value="<?= $hoy ?>"></label>
    <label>Hasta <input type="date" id="rptHasta" value="<?= $hoy ?>"></label>
    <button class="btn btn-primary" id="rptApply">Aplicar</button>
    <div class="range-presets">
      <button data-preset="today">Hoy</button>
      <button data-preset="yesterday">Ayer</button>
      <button data-preset="week">Últimos 7 días</button>
      <button data-preset="month">Este mes</button>
    </div>
  </div>

  <!-- KPIs -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;">
    <div class="table-card" style="cursor:default;"><div class="bar" style="background:var(--green);"></div><div class="seats">Ventas</div><div class="num" id="rptSales">$0.00</div></div>
    <div class="table-card" style="cursor:default;"><div class="bar" style="background:var(--blue);"></div><div class="seats">Órdenes cobradas</div><div class="num" id="rptOrders">0</div></div>
    <div class="table-card" style="cursor:default;"><div class="bar" style="background:var(--purple);"></div><div class="seats">Ticket promedio</div><div class="num" id="rptAvg">$0.00</div></div>
    <div class="table-card" style="cursor:default;"><div class="bar" style="background:var(--yellow);"></div><div class="seats">Productos vendidos</div><div class="num" id="rptUnits">0</div></div>
    <div class="table-card" style="cursor:default;"><div class="bar" style="background:var(--accent);"></div><div class="seats">Días con ventas</div><div class="num" id="rptDays">0</div></div>
  </div>

  <!-- A qué hora se vende más -->
  <div class="panel" style="margin-top:20px;">
    <h3>⏰ A qué hora se vende más</h3>
    <div id="rptByHour" class="rpt-list"><em>Sin datos aún.</em></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
    <div class="panel">
      <h3>🏆 Lo más vendido (Top 5)</h3>
      <div id="rptTop" class="rpt-list"><em>Sin datos aún.</em></div>
    </div>
    <div class="panel">
      <h3>📂 Lo que más se mueve (por categoría)</h3>
      <div id="rptByCat" class="rpt-list"><em>Sin datos aún.</em></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
    <div class="panel">
      <h3>💳 Métodos de pago</h3>
      <div id="rptPayments" class="rpt-list"><em>Sin datos aún.</em></div>
    </div>
    <div class="panel">
      <h3>📅 Ventas por día</h3>
      <div id="rptByDay" class="rpt-list"><em>Sin datos aún.</em></div>
    </div>
  </div>

  <!-- Todo lo que se vendió -->
  <div class="panel" style="margin-top:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <h3 style="margin:0;">🧾 Todo lo que se vendió</h3>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <div class="cat-tabs" style="margin:0;">
          <button class="cat-tab active" data-rpt-sort="unidades">Por unidades</button>
          <button class="cat-tab" data-rpt-sort="ingresos">Por ingresos</button>
        </div>
        <button class="btn btn-ghost" id="rptExportCsv">⬇ Exportar CSV</button>
      </div>
    </div>
    <div style="overflow-x:auto;margin-top:10px;">
      <table class="data-table" id="rptAllTable">
        <thead><tr><th>#</th><th>Producto</th><th>Categoría</th><th>Unidades</th><th>% uds</th><th>Ingresos</th></tr></thead>
        <tbody id="rptAllBody"><tr><td colspan="6"><em>Sin datos aún.</em></td></tr></tbody>
      </table>
    </div>
  </div>
</div>
<script>document.body.dataset.module = "reports";</script>
