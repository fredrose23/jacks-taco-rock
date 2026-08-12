<?php require_role('admin'); ?>
<div class="topbar">
  <div><h2>Sistema · Menú (Productos y Categorías)</h2><div class="sub">CRUD completo del menú con precios y asignación de impresora</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="cat-tabs">
    <button class="cat-tab active" data-tab="prods">🍽 Productos</button>
    <button class="cat-tab" data-tab="cats">🗂 Categorías</button>
  </div>

  <div id="tab-prods" class="tab-content">
    <div class="toolbar">
      <button class="btn btn-primary" onclick="openProdModal()">+ Nuevo producto</button>
      <input class="inv-input" id="prodSearch" placeholder="🔍 Buscar..." style="max-width:240px;">
    </div>
    <div id="prodsGrid" class="data-wrap"></div>
  </div>

  <div id="tab-cats" class="tab-content" style="display:none;">
    <div class="toolbar"><button class="btn btn-primary" onclick="openCatModal()">+ Nueva categoría</button></div>
    <div id="catsGrid" class="data-wrap"></div>
  </div>
</div>

<!-- Modal producto -->
<div class="modal-bg" id="prodModal">
  <div class="modal" style="max-width:600px;">
    <h3 id="prodTitle">Producto</h3>
    <input type="hidden" id="pId">
    <div class="grid-2">
      <div>
        <label class="inv-label">Nombre</label>
        <input class="inv-input" id="pNombre">
        <label class="inv-label">Categoría</label>
        <select class="inv-input" id="pCategoria"></select>
        <label class="inv-label">Precio</label>
        <input type="number" step="0.01" class="inv-input" id="pPrecio">
        <label class="inv-label">Emoji</label>
        <input class="inv-input" id="pEmoji" maxlength="4">
      </div>
      <div>
        <label style="background:rgba(240,176,66,.12);padding:8px 12px;border-radius:8px;border:1px solid var(--yellow);display:block;margin-bottom:10px;">
          <input type="checkbox" id="pManejaStock"> 📦 <b>Maneja stock</b>
          <small style="color:var(--muted);display:block;margin-left:22px;">Marca solo si es un producto comprado (bebidas, snacks). Los platillos elaborados NO manejan stock.</small>
        </label>
        <div id="stockFields">
          <label class="inv-label">Stock actual</label>
          <input type="number" class="inv-input" id="pStock" value="50">
          <label class="inv-label">Stock mínimo</label>
          <input type="number" class="inv-input" id="pStockMin" value="5">
          <label class="inv-label">Unidad</label>
          <input class="inv-input" id="pUnidad" value="pz">
        </div>
      </div>
    </div>
    <label class="inv-label">Descripción</label>
    <textarea class="inv-input" id="pDesc" rows="2"></textarea>

    <!-- ───── Imagen del producto ───── -->
    <label class="inv-label" style="margin-top:14px;">📷 Imagen del producto <small style="color:var(--muted);">(opcional · PNG/JPG/WebP · máx 5 MB)</small></label>
    <div class="prod-img-uploader">
      <div class="prod-img-preview" id="pImgPreview">
        <span id="pImgEmojiFallback">🍽</span>
        <img id="pImgPreviewImg" style="display:none;">
      </div>
      <div class="prod-img-actions">
        <input type="file" id="pImgFile" accept="image/png,image/jpeg,image/webp" style="display:none;">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('pImgFile').click()">📁 Elegir imagen</button>
        <button type="button" class="btn btn-ghost" id="pImgDelete" style="color:var(--red);display:none;">🗑 Quitar imagen</button>
        <div id="pImgStatus" style="font-size:11px;color:var(--muted);margin-top:6px;">Sin imagen, se usará el emoji.</div>
        <div id="pImgProgress" style="display:none;margin-top:6px;">
          <div style="background:var(--panel-2);height:5px;border-radius:3px;overflow:hidden;">
            <div id="pImgProgressBar" style="background:var(--accent);height:100%;width:0;transition:width .2s;"></div>
          </div>
        </div>
      </div>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:18px;margin-top:14px;align-items:center;">
      <label><input type="checkbox" id="pDestacado"> ★ Destacado</label>
      <label><input type="checkbox" id="pDisponible" checked> Disponible</label>
      <label style="background:rgba(91,180,224,.12);padding:6px 12px;border-radius:8px;border:1px solid var(--blue);">
        <input type="checkbox" id="pCocina2"> 🍳 <b>Se prepara en Cocina B</b>
        <small style="color:var(--muted);display:block;margin-left:22px;">Si no se marca, se prepara en Cocina A (default)</small>
      </label>
    </div>
    <label class="inv-label">Grupos de modificadores</label>
    <div id="pMods" class="checkbox-list"></div>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('prodModal')">Cancelar</button>
      <button class="btn btn-primary" id="pSave">Guardar</button>
    </div>
  </div>
</div>

<!-- Modal categoría -->
<div class="modal-bg" id="catModal">
  <div class="modal">
    <h3>Categoría</h3>
    <input type="hidden" id="cId">
    <label class="inv-label">Nombre</label>
    <input class="inv-input" id="cNombre">
    <label class="inv-label">Orden</label>
    <input type="number" class="inv-input" id="cOrden" value="0">
    <label class="inv-label">Impresora default</label>
    <select class="inv-input" id="cImpresora"><option value="">Ninguna</option></select>
    <label><input type="checkbox" id="cActiva" checked> Activa</label>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('catModal')">Cancelar</button>
      <button class="btn btn-primary" id="cSave">Guardar</button>
    </div>
  </div>
</div>
<script>document.body.dataset.module = "sys-menu";</script>
