<?php require_role('admin','cajero'); ?>
<div class="topbar">
  <div>
    <h2>Cierres consolidados</h2>
    <div class="sub" id="clsSub">Conciliación de todos los empleados</div>
  </div>
  <div class="meta">
    <?php if (current_user()['rol'] === 'admin'): ?>
      <button class="btn" id="clsCierreDia" style="background:var(--red);color:#fff;font-weight:700;">🔒 Cerrar día</button>
    <?php endif; ?>
    <span id="clockNow">--:--</span>
  </div>
</div>

<div class="content">
  <!-- Filtros -->
  <div class="date-range-bar">
    <label>Desde <input type="date" id="clsDesde" value="<?= date('Y-m-d') ?>"></label>
    <label>Hasta <input type="date" id="clsHasta" value="<?= date('Y-m-d') ?>"></label>
    <label>Rol
      <select class="inv-input" id="clsRol" style="padding:6px 10px;">
        <option value="">Todos</option>
        <option value="mesero">Meseros</option>
        <option value="repartidor">Repartidores</option>
        <option value="cajero">Cajeros</option>
        <option value="cocina">Cocina</option>
      </select>
    </label>
    <label>Empleado
      <select class="inv-input" id="clsEmpleado" style="padding:6px 10px;">
        <option value="">Todos</option>
      </select>
    </label>
    <button class="btn btn-primary" id="clsApply">Aplicar</button>
    <div class="range-presets">
      <button data-preset="today">Hoy</button>
      <button data-preset="yesterday">Ayer</button>
      <button data-preset="week">7 días</button>
      <button data-preset="month">Mes</button>
    </div>
  </div>

  <!-- Banner del último cierre de día -->
  <div id="clsBanner"></div>

  <!-- Resumen -->
  <div class="dash-grid" id="clsResumen"></div>

  <!-- Acciones de reporte -->
  <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;">
    <button class="btn btn-ghost" id="clsPrintZ">🖨 Imprimir Corte Z</button>
    <button class="btn btn-ghost" id="clsExportCsv">⬇ Exportar CSV</button>
  </div>

  <!-- Pestañas -->
  <div class="cat-tabs" style="margin-top:18px;">
    <button class="cat-tab active" data-cls-tab="empleado">👥 Por empleado</button>
    <button class="cat-tab" data-cls-tab="repartidor">🛵 Por repartidor</button>
    <button class="cat-tab" data-cls-tab="dia">📅 Por día</button>
    <button class="cat-tab" data-cls-tab="turnos">📋 Detalle de turnos</button>
    <button class="cat-tab" data-cls-tab="tipos">🛒 Por tipo de pedido</button>
  </div>

  <div id="cls-empleado"   class="cls-tab"></div>
  <div id="cls-repartidor" class="cls-tab" style="display:none;"></div>
  <div id="cls-dia"        class="cls-tab" style="display:none;"></div>
  <div id="cls-turnos"     class="cls-tab" style="display:none;"></div>
  <div id="cls-tipos"      class="cls-tab" style="display:none;"></div>
</div>

<!-- Modal: detalle de un corte -->
<div class="modal-bg" id="corteModal">
  <div class="modal" style="max-width:760px;">
    <h3 id="corteTitle">Detalle del corte</h3>
    <div class="sub" id="corteSub"></div>
    <div id="corteBody"></div>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('corteModal')">Cerrar</button>
      <button class="btn btn-primary" id="cortePrint">🖨 Imprimir corte</button>
    </div>
  </div>
</div>

<script>document.body.dataset.module = "closures";</script>
