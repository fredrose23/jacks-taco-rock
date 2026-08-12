<?php require_role('admin'); ?>
<div class="topbar">
  <div><h2>Sistema · Bitácora de actividad</h2><div class="sub" id="auditSub">Últimos 100 eventos del sistema</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="toolbar">
    <button class="btn btn-ghost" onclick="loadAudit(100)">Últimos 100</button>
    <button class="btn btn-ghost" onclick="loadAudit(500)">Últimos 500</button>
    <input class="inv-input" id="auditFilter" placeholder="🔍 Filtrar por usuario, acción o entidad..." style="flex:1;max-width:380px;">
  </div>
  <div id="auditTable"></div>
</div>
<script>document.body.dataset.module = "sys-audit";</script>
