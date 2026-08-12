<?php
$mesa_id  = (int)($_GET['mesa']  ?? 0);
$orden_id = (int)($_GET['orden'] ?? 0);
if (!$mesa_id && !$orden_id) {
    header('Location: ' . SYS_URL . '/index.php?module=tables');
    exit;
}
?>
<div class="topbar">
  <div>
    <h2 id="orderHeaderTitle">Tomar pedido</h2>
    <div class="sub" id="orderTableLabel">Cargando…</div>
  </div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="order-layout">
    <div class="order-menu">
      <div class="cat-tabs" id="orderCats"></div>
      <div class="order-dishes" id="orderDishes"></div>
    </div>

    <div class="order-cart">
      <h3>Pedido</h3>
      <div class="table-label" id="orderTableSub">Cargando orden...</div>

      <!-- Datos del cliente (solo si es llevar/domicilio) -->
      <div id="customerInfoBox" class="customer-info-box" style="display:none;"></div>

      <div class="comensal-bar">
        <label>Comensal activo:</label>
        <select id="comensalSelect"></select>
        <button class="btn-tiny" id="addComensal" title="Agregar comensal">+</button>
        <button class="btn-tiny" id="splitMode" title="Vista por comensal">⇄</button>
      </div>

      <div class="cart-items" id="cartItems"></div>
      <div class="cart-summary">
        <div class="row" id="subtotalRow"><span>Subtotal</span><span id="sumSubtotal">$0.00</span></div>
        <div class="row" id="ivaRow" style="display:none;"><span>IVA</span><span id="sumTax">$0.00</span></div>
        <div class="row" id="envioRow" style="display:none;"><span>Envío</span><span id="sumEnvio">$0.00</span></div>
        <div class="row" id="descuentoRow" style="display:none;color:var(--green);"><span>🏷 Descuento <small id="descuentoLabel"></small></span><span id="sumDescuento">$0.00</span></div>
        <div class="row total-row"><span>TOTAL</span><span id="sumTotal">$0.00</span></div>
        <button class="btn btn-ghost" id="btnPromo" style="margin-top:8px;font-size:12px;padding:6px;">🏷 Aplicar promoción / cupón</button>
      </div>
      <div class="cart-actions">
        <button class="btn btn-primary" id="sendKitchen" disabled>Enviar a cocina · Imprimir comanda</button>
        <label style="font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;justify-content:center;">
          <input type="checkbox" id="chkSinImprimir"> Enviar <b>sin imprimir</b> comanda
        </label>
        <?php // ERROR 002/004: los meseros NO cobran; solo caja y admin ?>
        <?php if (in_array(current_user()['rol'], ['admin','cajero'])): ?>
        <button class="btn btn-success" id="goPay" disabled>Cobrar</button>
        <?php endif; ?>
        <button class="btn btn-ghost" id="btnMoveMesa" style="color:var(--blue);display:none;">🔁 Cambiar de mesa</button>
        <button class="btn btn-ghost" id="btnFreeMesa" style="color:var(--red);display:none;">🔓 Liberar mesa (vacía)</button>
        <button class="btn btn-ghost" id="btnChangeType" style="color:var(--accent-2);">🔄 Cambiar tipo de pedido</button>
        <a class="btn btn-ghost" href="<?= SYS_URL ?>/index.php?module=tables">← Volver a mesas</a>
      </div>
    </div>
  </div>
</div>

<!-- Modal · Cambiar tipo de pedido -->
<div class="modal-bg" id="changeTypeModal">
  <div class="modal" style="max-width:540px;">
    <h3>🔄 Cambiar tipo de pedido</h3>
    <div class="sub" id="changeTypeCurrentSub">Cliente cambió de opinión</div>

    <label class="inv-label" style="margin-top:10px;">Nuevo tipo</label>
    <div class="tipo-options">
      <label class="tipo-option" data-tipo="local">
        <input type="radio" name="newTipo" value="local">
        <span>🍽 Local <small>(asignar mesa)</small></span>
      </label>
      <label class="tipo-option" data-tipo="llevar">
        <input type="radio" name="newTipo" value="llevar">
        <span>📦 Para llevar <small>(cliente lo recoge)</small></span>
      </label>
      <label class="tipo-option" data-tipo="domicilio">
        <input type="radio" name="newTipo" value="domicilio">
        <span>🛵 Domicilio <small>(repartidor lo lleva)</small></span>
      </label>
    </div>

    <!-- Campo: Mesa (cuando se cambia a local) -->
    <div id="changeTypeMesaField" style="display:none;">
      <label class="inv-label">Mesa disponible</label>
      <select class="inv-input" id="ctMesa">
        <option value="">Selecciona una mesa libre…</option>
      </select>
    </div>

    <!-- Campos: Cliente (cuando se cambia a llevar/domicilio) -->
    <div id="changeTypeClienteFields" style="display:none;">
      <div class="grid-2" style="gap:10px;">
        <div>
          <label class="inv-label">Nombre del cliente *</label>
          <input class="inv-input" id="ctNombre">
        </div>
        <div>
          <label class="inv-label">Teléfono</label>
          <input class="inv-input" id="ctTel" inputmode="tel">
        </div>
      </div>
    </div>

    <!-- Campos: Domicilio (solo cuando es domicilio) -->
    <div id="changeTypeDomicilioFields" style="display:none;">
      <label class="inv-label">Dirección *</label>
      <input class="inv-input" id="ctDir" placeholder="Calle, número, colonia">
      <label class="inv-label">Referencias</label>
      <input class="inv-input" id="ctRef" placeholder="Casa azul, frente al parque…">
      <div class="grid-2" style="gap:10px;">
        <div>
          <label class="inv-label">Costo de envío</label>
          <input type="number" step="0.01" class="inv-input" id="ctEnvio" value="30">
        </div>
        <div>
          <label class="inv-label">Repartidor (opcional)</label>
          <select class="inv-input" id="ctRepartidor">
            <option value="">Sin asignar</option>
          </select>
        </div>
      </div>
    </div>

    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('changeTypeModal')">Cancelar</button>
      <button class="btn btn-success" id="ctConfirm" disabled>Cambiar tipo</button>
    </div>
  </div>
</div>

<!-- Modal · Aplicar promoción / cupón -->
<div class="modal-bg" id="promoModal">
  <div class="modal" style="max-width:520px;">
    <h3>🏷 Aplicar promoción</h3>
    <div class="sub">Selecciona una promoción activa o ingresa un código de cupón</div>

    <label class="inv-label">Código de cupón</label>
    <div style="display:flex;gap:6px;">
      <input class="inv-input" id="promoCodigo" placeholder="Ej. HAPPY20" style="text-transform:uppercase;">
      <button class="btn btn-primary" id="promoAplicarCodigo">Aplicar</button>
    </div>

    <hr style="margin:16px 0;border:none;border-top:1px solid var(--border);">

    <h4 style="font-size:13px;color:var(--accent-2);margin-bottom:8px;">Promociones activas hoy</h4>
    <div id="promoList"></div>

    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('promoModal')">Cerrar</button>
      <button class="btn btn-ghost" id="promoQuitar" style="color:var(--red);display:none;">Quitar promoción aplicada</button>
    </div>
  </div>
</div>

<!-- Modal modificadores -->
<div class="modal-bg" id="modsModal">
  <div class="modal" style="max-width:500px;">
    <h3>Personalizar platillo</h3>
    <div class="sub">Quita o agrega ingredientes (con cargo si aplica)</div>
    <div id="modsModalBody"></div>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('modsModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="confirmAddWithMods()">Agregar al pedido</button>
    </div>
  </div>
</div>

<!-- Modal notas por platillo -->
<div class="modal-bg" id="notesModal">
  <div class="modal" style="max-width:420px;">
    <h3>Nota para cocina</h3>
    <div class="sub" id="notesItemName">--</div>
    <textarea id="notesText" rows="3" class="inv-input" placeholder="Ej. sin cebolla, término medio, salsa aparte..."></textarea>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" id="notesCancel">Cancelar</button>
      <button class="btn btn-primary" id="notesSave">Guardar nota</button>
    </div>
  </div>
</div>

<!-- Modal split por cliente -->
<div class="modal-bg" id="splitCustomerModal">
  <div class="modal" style="max-width:680px;">
    <h3>Cobro dividido por comensal</h3>
    <div class="sub">Selecciona qué comensal pagas ahora.</div>
    <div id="splitCustomerList"></div>
    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" id="splitCustomerCancel">Cerrar</button>
      <button class="btn btn-success" id="splitPayAll">Cobrar TODO junto</button>
    </div>
  </div>
</div>

<!-- Modal · Cambiar la cuenta a otra mesa -->
<div class="modal-bg" id="moveMesaModal">
  <div class="modal" style="max-width:480px;">
    <h3>🔁 Cambiar de mesa</h3>
    <div class="sub">Mueve toda la cuenta (productos y comandas) a otra mesa libre. Ej.: del interior a la terraza.</div>
    <label class="inv-label" style="margin-top:12px;">Mesa destino (libre)</label>
    <select class="inv-input" id="moveMesaSelect">
      <option value="">Selecciona una mesa libre…</option>
    </select>
    <div class="modal-actions" style="margin-top:16px;">
      <button class="btn btn-ghost" id="moveMesaCancel">Cancelar</button>
      <button class="btn btn-primary" id="moveMesaConfirm">Mover cuenta</button>
    </div>
  </div>
</div>

<script>
  document.body.dataset.module = "orders";
  window.CURRENT_MESA_ID  = <?= $mesa_id  ?: 'null' ?>;
  window.CURRENT_ORDEN_ID = <?= $orden_id ?: 'null' ?>;
</script>
