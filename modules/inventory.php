<div class="topbar">
  <div>
    <h2>Inventario</h2>
    <div class="sub" id="invSub">Cargando...</div>
  </div>
  <div class="meta">
    <span id="clockNow">--:--</span>
  </div>
</div>

<div class="content">
  <!-- Switch principal: producto terminado vs insumos -->
  <div class="cat-tabs" style="margin-bottom:6px;">
    <button class="cat-tab active" data-inv-kind="productos">📦 Productos terminados</button>
    <button class="cat-tab" data-inv-kind="insumos">🥬 Insumos / materia prima</button>
  </div>

  <!-- ════════ PRODUCTOS TERMINADOS ════════ -->
  <section id="invProductosPane">
    <div class="cat-tabs">
      <button class="cat-tab active" data-filter="all">Todos</button>
      <button class="cat-tab" data-filter="low">⚠ Stock bajo</button>
      <button class="cat-tab" data-filter="out">✗ Sin stock</button>
    </div>
    <div class="inv-grid" id="invGrid"></div>
  </section>

  <!-- ════════ INSUMOS ════════ -->
  <section id="invInsumosPane" style="display:none;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
      <div class="cat-tabs" style="margin:0;">
        <button class="cat-tab active" data-ins-filter="all">Todos</button>
        <button class="cat-tab" data-ins-filter="low">⚠ Stock bajo</button>
        <button class="cat-tab" data-ins-filter="out">✗ Agotado</button>
      </div>
      <button class="btn btn-success" id="insNewBtn">+ Nuevo insumo</button>
    </div>
    <div class="inv-grid" id="insGrid"></div>
  </section>
</div>

<!-- Modal de movimiento (compartido productos/insumos) -->
<div class="modal-bg" id="movModal">
  <div class="modal">
    <h3 id="movTitle">Movimiento de stock</h3>
    <div class="sub" id="movSub"></div>

    <label class="inv-label">Tipo</label>
    <select id="movTipo" class="inv-input">
      <option value="entrada">↑ Entrada (compra/recepción)</option>
      <option value="salida">↓ Salida (uso/consumo)</option>
      <option value="merma">✗ Merma (caducidad/desperdicio)</option>
      <option value="ajuste">= Ajuste (poner stock en…)</option>
    </select>

    <label class="inv-label">Cantidad <small id="movUnidad" style="color:var(--muted);"></small></label>
    <input type="number" id="movCantidad" class="inv-input" min="0" step="any" value="1">

    <label class="inv-label">Motivo</label>
    <input type="text" id="movMotivo" class="inv-input" placeholder="Ej. Compra mayorista, caducidad…">

    <div class="modal-actions" style="margin-top:16px;">
      <button class="btn btn-ghost" id="movCancel">Cancelar</button>
      <button class="btn btn-primary" id="movSave">Guardar</button>
    </div>
  </div>
</div>

<!-- Modal nuevo/editar insumo -->
<div class="modal-bg" id="insModal">
  <div class="modal">
    <h3 id="insModalTitle">Nuevo insumo</h3>
    <div class="sub">Materia prima que se consume al cocinar (no se vende).</div>

    <label class="inv-label">Nombre *</label>
    <input type="text" id="insNombre" class="inv-input" placeholder="Ej. Pan de hamburguesa, Bolsa de papas, Carne…">

    <div class="grid-2" style="gap:10px;">
      <div>
        <label class="inv-label">Unidad</label>
        <select id="insUnidad" class="inv-input">
          <option value="pieza">pieza</option>
          <option value="bolsa">bolsa</option>
          <option value="paquete">paquete</option>
          <option value="kg">kg</option>
          <option value="g">g</option>
          <option value="litro">litro</option>
          <option value="ml">ml</option>
          <option value="caja">caja</option>
        </select>
      </div>
      <div>
        <label class="inv-label">Stock mínimo (alerta)</label>
        <input type="number" id="insMinimo" class="inv-input" min="0" step="any" value="0">
      </div>
    </div>

    <div id="insStockInicialWrap">
      <label class="inv-label">Stock inicial</label>
      <input type="number" id="insStockInicial" class="inv-input" min="0" step="any" value="0">
    </div>

    <div class="modal-actions" style="margin-top:16px;">
      <button class="btn btn-ghost" id="insCancel">Cancelar</button>
      <button class="btn btn-primary" id="insSave">Guardar</button>
    </div>
  </div>
</div>

<script>document.body.dataset.module = "inventory";</script>
