<?php require_role('admin'); ?>
<div class="topbar">
  <div>
    <h2>Sistema · Errores y crashes</h2>
    <div class="sub" id="errSub">Cargando...</div>
  </div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="toolbar">
    <button class="cat-tab active" data-filter="all">Todos</button>
    <button class="cat-tab" data-filter="Fatal">🔴 Fatales</button>
    <button class="cat-tab" data-filter="Exception">⚠ Exceptions</button>
    <button class="cat-tab" data-filter="Error">Errores</button>
    <button class="cat-tab" data-filter="Warning">Warnings</button>
    <button class="cat-tab" data-filter="pendiente">📌 Sin resolver</button>
    <input class="inv-input" id="errSearch" placeholder="🔍 Buscar mensaje, archivo..." style="flex:1;max-width:280px;">
    <button class="btn btn-ghost" onclick="loadErrors()">↻ Refrescar</button>
    <button class="btn btn-ghost" id="errPurge" style="color:var(--red);">🗑 Vaciar antiguos (30+ días)</button>
  </div>

  <div class="panel" style="margin-bottom:16px;">
    <h3 style="font-size:14px;">📊 Resumen</h3>
    <div id="errStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-top:8px;"></div>
  </div>

  <div id="errList"></div>
</div>

<!-- Modal detalle de error -->
<div class="modal-bg" id="errModal">
  <div class="modal" style="max-width:760px;">
    <h3>Detalle del error</h3>
    <div id="errDetail"></div>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('errModal')">Cerrar</button>
      <button class="btn btn-success" id="errResolve">Marcar como resuelto</button>
    </div>
  </div>
</div>

<script>document.body.dataset.module = "sys-errors";</script>
