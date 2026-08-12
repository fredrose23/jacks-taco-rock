<?php require_role('admin','cajero'); ?>
<div class="topbar">
  <div><h2>Tickets · Ventas cobradas</h2><div class="sub" id="ticSub">Cargando…</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="date-range-bar">
    <label>Desde <input type="date" id="ticDesde" value="<?= date('Y-m-d') ?>"></label>
    <label>Hasta <input type="date" id="ticHasta" value="<?= date('Y-m-d') ?>"></label>
    <label>Tipo
      <select class="inv-input" id="ticTipo" style="padding:6px 10px;">
        <option value="">Todos</option>
        <option value="local">🍽 Local</option>
        <option value="llevar">📦 Llevar</option>
        <option value="domicilio">🛵 Domicilio</option>
      </select>
    </label>
    <label>Empleado
      <select class="inv-input" id="ticEmp" style="padding:6px 10px;">
        <option value="">Todos</option>
      </select>
    </label>
    <input class="inv-input" id="ticSearch" placeholder="🔍 ID, cliente, teléfono…" style="flex:1;min-width:200px;">
    <button class="btn btn-primary" id="ticApply">Aplicar</button>
    <div class="range-presets">
      <button data-preset="today">Hoy</button>
      <button data-preset="yesterday">Ayer</button>
      <button data-preset="week">7 días</button>
      <button data-preset="month">Mes</button>
    </div>
  </div>

  <div class="cash-card highlight" id="ticResumen" style="margin-bottom:16px;text-align:center;">
    <div class="lbl">💰 Total vendido en este rango</div>
    <div class="val" style="font-size:28px;">$0.00</div>
  </div>

  <div id="ticList"></div>
</div>
<script>document.body.dataset.module = "tickets";</script>
