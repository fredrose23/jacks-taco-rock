<?php $hoy = date('Y-m-d'); ?>
<div class="topbar">
  <div><h2>Propinas</h2><div class="sub" id="tipsSub">Reporte por mesero y detalle</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="date-range-bar">
    <label>Desde <input type="date" id="tDesde" value="<?= $hoy ?>"></label>
    <label>Hasta <input type="date" id="tHasta" value="<?= $hoy ?>"></label>
    <button class="btn btn-primary" id="tApply">Aplicar</button>
  </div>

  <div class="panel"><h3>Por mesero</h3><div id="tByMesero"></div></div>
  <div class="panel" style="margin-top:16px;"><h3>Detalle de propinas</h3><div id="tDetail"></div></div>
</div>
<script>document.body.dataset.module = "tips";</script>
