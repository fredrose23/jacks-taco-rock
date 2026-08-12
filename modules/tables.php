<div class="topbar">
  <div>
    <h2>Vista de mesas</h2>
    <div class="sub" id="viewSub">Cargando mesas...</div>
  </div>
  <div class="meta">
    <span id="clockNow">--:--</span>
    <span>·</span>
    <span>Caja abierta</span>
  </div>
</div>

<div class="content">
  <?php
    // Solo roles que pueden levantar pedidos llevar/domicilio:
    $rol = current_user()['rol'];
    $puedeLevantar = in_array($rol, ['admin','cajero','mesero']);
  ?>
  <?php if ($puedeLevantar): ?>
    <div class="quick-orders">
      <button class="btn btn-primary" onclick="openTakeoutModal('llevar')">
        📦 + Nuevo pedido <b>para llevar</b>
      </button>
      <button class="btn btn-primary" onclick="openTakeoutModal('domicilio')">
        🛵 + Nuevo pedido <b>a domicilio</b>
      </button>
      <?php // ERROR 005: la venta rápida (mostrador) NO es para meseros ?>
      <?php if (in_array($rol, ['admin','cajero'])): ?>
      <button class="btn btn-success" onclick="startMostrador()">
        🥤 + <b>Venta rápida</b> (mostrador)
      </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="legend">
    <span><span class="sw" style="background:var(--green)"></span> Libre</span>
    <span><span class="sw" style="background:var(--red)"></span> Ocupada</span>
    <span><span class="sw" style="background:var(--yellow)"></span> Por cobrar</span>
  </div>
  <div class="tables-grid" id="tablesGrid">
    <div class="empty-state"><div class="ico">⏳</div>Cargando...</div>
  </div>
</div>

<!-- Modal · Nuevo pedido llevar/domicilio -->
<div class="modal-bg" id="takeoutModal">
  <div class="modal" style="max-width:560px;">
    <h3 id="toTitle">Nuevo pedido</h3>
    <div class="sub" id="toSub"></div>

    <!-- Lookup de cliente por teléfono -->
    <label class="inv-label">Teléfono del cliente</label>
    <div style="position:relative;">
      <input class="inv-input" id="toTel" inputmode="tel" placeholder="Ej. 9621234567" autocomplete="off">
      <div id="toMatches" class="cli-matches" style="display:none;"></div>
    </div>

    <label class="inv-label">Nombre del cliente *</label>
    <input class="inv-input" id="toNombre">

    <div id="toDireccionFields" style="display:none;">
      <label class="inv-label">Dirección *</label>
      <input class="inv-input" id="toDir" placeholder="Calle, número, colonia">

      <label class="inv-label">Referencias</label>
      <input class="inv-input" id="toRef" placeholder="Casa azul, frente al parque…">

      <div class="grid-2" style="gap:10px;">
        <div>
          <label class="inv-label">Costo de envío</label>
          <input type="number" step="0.01" class="inv-input" id="toEnvio">
        </div>
        <div>
          <label class="inv-label">Repartidor (opcional)</label>
          <select class="inv-input" id="toRepartidor">
            <option value="">Sin asignar</option>
          </select>
        </div>
      </div>
    </div>

    <div id="toPickupField" style="display:none;">
      <label class="inv-label">Hora estimada de recoger (opcional)</label>
      <input type="time" class="inv-input" id="toPickup">
    </div>

    <label class="inv-label">Método de pago</label>
    <select class="inv-input" id="toMetodoPago">
      <option value="">— Sin especificar —</option>
      <option value="efectivo">💵 Efectivo</option>
      <option value="tarjeta">💳 Tarjeta</option>
      <option value="transferencia">🏦 Transferencia</option>
    </select>

    <div class="modal-actions" style="margin-top:14px;">
      <button class="btn btn-ghost" onclick="closeModal('takeoutModal')">Cancelar</button>
      <button class="btn btn-success" id="toSave">Crear pedido y continuar</button>
    </div>
  </div>
</div>

<script>document.body.dataset.module = "tables";</script>
