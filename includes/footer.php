  </main>
</div>

<!-- Modal de cobro -->
<div class="modal-bg" id="payModal">
  <div class="modal">
    <h3>Cobro de la cuenta</h3>
    <div class="sub" id="payModalSub">Mesa --</div>
    <div class="pay-summary">
      <div class="row"><span>Subtotal</span><span id="paySub">$0.00</span></div>
      <div class="row"><span>IVA</span><span id="payTax">$0.00</span></div>
      <div class="row" id="payDescRow" style="display:none;color:var(--red);"><span id="payDescLbl">Descuento</span><span id="payDescShow">-$0.00</span></div>
      <div class="row total"><span>Total a pagar</span><span id="payTotal">$0.00</span></div>
    </div>

    <!-- Descuento (solo admin / cajero) -->
    <div id="payDiscountBox" style="display:none;border:1px dashed var(--line);border-radius:10px;padding:10px 12px;margin-bottom:12px;">
      <button type="button" id="payDiscountToggle" class="btn btn-ghost" style="width:100%;justify-content:center;">🏷 Aplicar descuento</button>
      <div id="payDiscountFields" style="display:none;margin-top:10px;">
        <div class="grid-2" style="gap:8px;">
          <div>
            <label class="inv-label">Monto a descontar ($)</label>
            <input type="number" min="0" step="0.01" class="inv-input" id="payDescMonto" placeholder="0.00">
          </div>
          <div>
            <label class="inv-label">Motivo</label>
            <input type="text" class="inv-input" id="payDescMotivo" placeholder="Ej. cliente frecuente, cortesía…">
          </div>
        </div>
        <button type="button" id="payDescClear" class="btn btn-ghost" style="margin-top:8px;font-size:12px;">Quitar descuento</button>
      </div>
    </div>

    <div style="font-size:13px;color:var(--muted);margin-bottom:10px;">Selecciona uno o varios métodos de pago</div>
    <div class="split-list" id="splitList"></div>
    <button class="split-add" id="splitAdd">+ Agregar otro método de pago</button>
    <div class="pay-summary" style="margin-top:14px;">
      <div class="row"><span>Pagado</span><span id="payPaid">$0.00</span></div>
      <div class="row diff" id="payDiffRow"><span id="payDiffLbl">Falta</span><span id="payDiff">$0.00</span></div>
    </div>
    <label class="chk-big" style="margin-top:10px;">
      <input type="checkbox" id="chkSinTicket"> Cobrar <b>sin imprimir</b> ticket
    </label>
    <div class="modal-actions" style="margin-top:14px;gap:12px;">
      <button class="btn btn-ghost" id="payCancel">Cancelar</button>
      <button class="btn btn-success btn-lg" id="payConfirm" disabled>Confirmar pago</button>
    </div>
  </div>
</div>

<!-- Modal comanda(s) - hasta dos hojas (Cocina 1 + Cocina 2) -->
<div class="modal-bg" id="comandaModal">
  <div class="modal" id="comandaModalInner" style="max-width:720px;">
    <h3>Comanda(s) enviada(s) a cocina</h3>
    <div class="sub" id="comandaSub">Pulsa "Imprimir" en cada hoja para mandarla a su impresora</div>
    <div id="comandaPreview" class="comandas-grid"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" id="comandaClose">Cerrar</button>
      <button class="btn btn-success" id="comandaGoTables" style="display:none;font-size:16px;padding:14px 20px;">✓ Todo bien · Volver a Mesas</button>
    </div>
  </div>
</div>

<!-- Modal ticket de venta -->
<div class="modal-bg" id="ticketModal">
  <div class="modal" style="max-width:340px;">
    <h3>Pago realizado</h3>
    <div class="sub">Ticket de venta</div>
    <div style="display:flex;justify-content:center;margin:14px 0;">
      <div class="comanda" id="ticketPreview"></div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" id="ticketClose">Cerrar</button>
      <button class="btn btn-primary" id="ticketPrint">Imprimir ticket</button>
    </div>
  </div>
</div>

<!-- Preview de platillo (long-press / right-click en grids de productos) -->
<div class="modal-bg" id="dishPreviewModal">
  <div class="modal dish-preview-modal">
    <button class="dp-close" onclick="closeModal('dishPreviewModal')" aria-label="Cerrar">×</button>
    <div class="dp-img" id="dpImg"></div>
    <div class="dp-body">
      <div class="dp-cat" id="dpCat"></div>
      <h3 id="dpName">--</h3>
      <p id="dpDesc"></p>
      <div class="dp-tags" id="dpTags"></div>
      <div class="dp-price" id="dpPrice">$0.00</div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="closeModal('dishPreviewModal')">Cerrar</button>
      <button class="btn btn-primary" id="dpAdd">+ Agregar al pedido</button>
    </div>
  </div>
</div>

<div class="print-area" id="printArea"></div>
<div class="toast-wrap" id="toastWrap"></div>

<script src="<?= APP_URL ?>/assets/js/api.js?v=<?= asset_v('assets/js/api.js') ?>"></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= asset_v('assets/js/app.js') ?>"></script>
<script src="<?= APP_URL ?>/assets/js/weborders.js?v=<?= asset_v('assets/js/weborders.js') ?>"></script>
<script src="<?= APP_URL ?>/assets/js/admin.js?v=<?= asset_v('assets/js/admin.js') ?>"></script>
</body>
</html>
