<?php
$u = current_user();
?>
<div class="topbar">
  <div>
    <h2>Hola, <?= e(explode(' ', $u['nombre'])[0]) ?> 👋</h2>
    <div class="sub">Resumen de tu día y métricas en vivo</div>
  </div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div id="dashTurnoCard"></div>

  <div class="dash-grid" id="dashStats" style="margin-top:18px;"></div>

  <div class="panel" style="margin-top:18px;">
    <h3 id="dashOrdsTitle">Mis órdenes del día</h3>
    <div id="dashOrders"></div>
  </div>

  <?php if ($u['rol'] === 'repartidor'): ?>
    <div class="panel" style="margin-top:18px;">
      <h3>Mis entregas pendientes</h3>
      <div id="dashDeliveries"></div>
    </div>
  <?php endif; ?>
</div>
<script>
  document.body.dataset.module = "dashboard";
  window.CURRENT_ROL = "<?= e($u['rol']) ?>";
</script>
