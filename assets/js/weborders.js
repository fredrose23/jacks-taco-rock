/**
 * Pedidos web · módulo de aceptación (admin/cajero/mesero)
 * Se carga junto con admin.js. Usa las mismas variables globales (API, fmt, toast).
 */
(function () {
  let WEB_ORDERS = [];
  let WEB_FILTER = 'pendientes';
  let WEB_LAST_PENDING = -1;

  // Exponer init para el dispatcher de admin.js
  window.initWebOrders = async function () {
    document.querySelectorAll('[data-web-filter]').forEach(b => {
      b.onclick = () => {
        document.querySelectorAll('[data-web-filter]').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        WEB_FILTER = b.dataset.webFilter;
        loadWebOrders();
      };
    });
    await loadWebOrders();
    if (window._webInterval) clearInterval(window._webInterval);
    window._webInterval = setInterval(loadWebOrders, 8000);
  };

  async function loadWebOrders() {
    try { WEB_ORDERS = await API.get('webadmin/list', { filter: WEB_FILTER }); }
    catch (e) { WEB_ORDERS = []; }

    let pendientes = 0;
    try { const c = await API.get('webadmin/count'); pendientes = c.count; } catch (e) {}

    const soundOn = document.getElementById('webSound')?.checked;
    if (WEB_LAST_PENDING >= 0 && pendientes > WEB_LAST_PENDING && soundOn) {
      playNewOrderSound();
      toast('🌐 ¡Nuevo pedido web!', 'info');
    }
    WEB_LAST_PENDING = pendientes;

    const badge = document.getElementById('webPendCount');
    if (badge) {
      if (pendientes > 0) { badge.style.display = 'inline-block'; badge.textContent = pendientes; }
      else badge.style.display = 'none';
    }
    const sub = document.getElementById('webSub');
    if (sub) sub.textContent = `${WEB_ORDERS.length} pedidos · ${pendientes} pendiente${pendientes !== 1 ? 's' : ''} de confirmar`;

    const wrap = document.getElementById('webGrid');
    if (!wrap) return;
    if (!WEB_ORDERS.length) {
      wrap.innerHTML = `<div class="empty-state"><div class="ico">🌐</div>
        <h3>Sin pedidos en este filtro</h3>
        <p>Los pedidos de la página web aparecerán aquí.</p></div>`;
      return;
    }
    wrap.innerHTML = `<div class="dlv-grid">${WEB_ORDERS.map(renderWebCard).join('')}</div>`;
    wrap.querySelectorAll('[data-web-action]').forEach(b => {
      b.onclick = () => webAction(b.dataset.webAction, +b.dataset.id);
    });

    // Recalcular total/cambio en vivo al teclear envío o paga con
    const recalc = (id) => {
      const o = WEB_ORDERS.find(x => x.id === id);
      if (!o) return;
      const envio = +(wrap.querySelector(`.web-envio[data-id="${id}"]`)?.value || 0);
      const pagaCon = +(wrap.querySelector(`.web-pagacon[data-id="${id}"]`)?.value || 0);
      const total = (+o.total) + envio;
      const totalEl = wrap.querySelector(`.web-total-val[data-id="${id}"]`);
      const cambioEl = wrap.querySelector(`.web-cambio-val[data-id="${id}"]`);
      if (totalEl) totalEl.textContent = fmt(total);
      if (cambioEl) cambioEl.textContent = pagaCon > 0 ? `Cambio: ${fmt(Math.max(0, pagaCon - total))}` : '';
    };
    wrap.querySelectorAll('.web-envio, .web-pagacon').forEach(inp => {
      inp.oninput = () => recalc(+inp.dataset.id);
    });
    // Guardar envío (para pasarle el total al cliente antes de aceptar)
    wrap.querySelectorAll('.wc-save').forEach(b => {
      b.onclick = async () => {
        const id = +b.dataset.id;
        const envio = +(wrap.querySelector(`.web-envio[data-id="${id}"]`)?.value || 0);
        const pagaCon = wrap.querySelector(`.web-pagacon[data-id="${id}"]`)?.value || '';
        try {
          const r = await API.post('webadmin/set_envio', { orden_id: id, costo_envio: envio, paga_con: pagaCon });
          // Actualizar el objeto en memoria para que "aceptar" use estos valores
          const o = WEB_ORDERS.find(x => x.id === id);
          if (o) { o.costo_envio = envio; o.envio_por_cotizar = 0; o.paga_con = pagaCon || null; }
          toast(`💾 Envío guardado · Total ${fmt(r.total)}${r.cambio!==null?` · cambio ${fmt(r.cambio)}`:''}`, 'success');
        } catch (e) { toast(e.message, 'error'); }
      };
    });
  }

  function renderWebCard(o) {
    const elapsed = Math.floor((Date.now() - new Date(o.web_creado_at).getTime()) / 60000);
    const esDomicilio = o.web_tipo_entrega === 'domicilio';
    const itemsTxt = (o.items || []).map(i =>
      `<div class="li"><span>${i.cantidad}× ${i.nombre}</span><span>${fmt((+i.precio + +i.precio_extra) * i.cantidad)}</span></div>` +
      (i.notas ? `<div class="ki-note" style="padding-left:0;">→ ${i.notas}</div>` : '')
    ).join('');

    let estadoTag, estadoClass;
    if (o.estado_web === 'pendiente')     { estadoTag = '⏳ PENDIENTE'; estadoClass = 'pendiente'; }
    else if (o.estado_web === 'aceptado') { estadoTag = '✓ ACEPTADO';   estadoClass = 'asignada'; }
    else                                   { estadoTag = '✗ RECHAZADO';  estadoClass = 'no_entregada'; }

    const esPendiente = o.estado_web === 'pendiente';
    const puedeEditar = esPendiente && ['admin', 'cajero', 'mesero'].includes(APP.user?.rol);

    const mapsLink = (o.cliente_lat && o.cliente_lng)
      ? `<a href="https://maps.google.com/?q=${o.cliente_lat},${o.cliente_lng}" target="_blank" class="maps-link">🗺 Abrir en Maps</a>`
      : '';

    // Bloque de envío + paga con + total (editable si es domicilio pendiente)
    let bloqueEnvio = '';
    if (esDomicilio) {
      if (puedeEditar) {
        bloqueEnvio = `
          <div class="web-cobro">
            <div class="wc-row">
              <label>🛵 Envío $</label>
              <input type="number" class="wc-input web-envio" data-id="${o.id}" value="${+o.envio_por_cotizar ? '' : (+o.costo_envio || '')}" placeholder="0" step="1" min="0">
              <button class="btn btn-ghost wc-save" data-id="${o.id}" title="Guardar para pasar total al cliente">💾</button>
            </div>
            <div class="wc-row">
              <label>💵 Paga con $</label>
              <input type="number" class="wc-input web-pagacon" data-id="${o.id}" value="${o.paga_con || ''}" placeholder="opcional" step="1" min="0">
            </div>
            <div class="wc-total">
              <span>Total: <b class="web-total-val" data-id="${o.id}">${fmt(+o.total + (+o.costo_envio||0))}</b></span>
              <span class="web-cambio-val" data-id="${o.id}">${o.paga_con ? `Cambio: ${fmt(Math.max(0, +o.paga_con - (+o.total + (+o.costo_envio||0))))}` : ''}</span>
            </div>
          </div>`;
      } else {
        bloqueEnvio = `
          <div class="dlv-row"><span class="lbl">🛵 Envío</span><span>${+o.envio_por_cotizar ? '<b style="color:var(--accent-2);">POR COTIZAR</b>' : fmt(o.costo_envio)}</span></div>
          ${o.paga_con ? `<div class="dlv-row"><span class="lbl">💵 Paga con</span><span>${fmt(o.paga_con)} · cambio ${fmt(Math.max(0,+o.paga_con-(+o.total+ +o.costo_envio)))}</span></div>` : ''}
          <div class="dlv-row"><span class="lbl">💰 Total</span><span><b>${fmt(+o.total + +o.costo_envio)}</b></span></div>`;
      }
    } else {
      bloqueEnvio = `<div class="dlv-row"><span class="lbl">💰 Total</span><span><b>${fmt(o.total)}</b></span></div>`;
    }

    let actions = '';
    if (esPendiente && puedeEditar) {
      actions += `<button class="btn btn-success" data-web-action="aceptar" data-id="${o.id}">✓ Aceptar y enviar a cocina</button>`;
      actions += `<button class="btn btn-ghost" data-web-action="rechazar" data-id="${o.id}" style="color:var(--red);">✗ Rechazar</button>`;
    } else if (o.estado_web === 'aceptado') {
      actions += `<small style="color:var(--green);">✓ Aceptado · en ${esDomicilio ? '🛵 Domicilios' : '📦 Para llevar'}</small>`;
      // #5 — Permitir agregar más productos a un pedido ya aceptado (abre la orden)
      if (o.estado === 'abierta') {
        actions += `<a class="btn btn-ghost" href="${APP.sysUrl}/index.php?module=orders&orden=${o.id}">➕ Agregar productos</a>`;
      }
    } else if (o.estado_web === 'rechazado') {
      actions += `<small style="color:var(--red);">Rechazado: ${o.web_motivo_rechazo || ''}</small>`;
    }

    return `<div class="dlv-card dlv-${estadoClass}">
      <div class="dlv-head">
        <div>
          <h4>${o.cliente_nombre || 'Sin nombre'}</h4>
          <small>📞 ${o.cliente_telefono || '—'}</small>
        </div>
        <div class="dlv-status">
          <span class="dlv-tag dlv-${estadoClass}">${estadoTag}</span>
          <small>#${o.id} · hace ${elapsed}m</small>
        </div>
      </div>
      <div class="dlv-body">
        <div class="dlv-row"><span class="lbl">🛍 Tipo</span><span>${esDomicilio ? '🛵 A domicilio' : '📦 Para recoger'}</span></div>
        ${esDomicilio ? `<div class="dlv-row"><span class="lbl">📍 Dirección</span><span>${o.cliente_direccion || '—'}</span></div>` : ''}
        ${o.cliente_referencias ? `<div class="dlv-row"><span class="lbl">📝 Ref</span><span>${o.cliente_referencias}</span></div>` : ''}
        ${esDomicilio && o.envio_distancia_km ? `<div class="dlv-row"><span class="lbl">📏 Distancia</span><span>${o.envio_distancia_km} km <small>(referencia)</small></span></div>` : ''}
        ${mapsLink ? `<div class="dlv-row"><span class="lbl">🗺 Ubicación</span><span>${mapsLink}</span></div>` : ''}
        <div class="web-items">${itemsTxt}</div>
        <div class="dlv-row" style="margin-top:6px;"><span class="lbl">Subtotal</span><span>${fmt(o.total)}</span></div>
        ${bloqueEnvio}
      </div>
      <div class="dlv-actions">${actions}</div>
    </div>`;
  }

  async function webAction(action, id) {
    try {
      if (action === 'aceptar') {
        const o = WEB_ORDERS.find(x => x.id === id);
        const payload = { orden_id: id };   // repartidor se asigna luego en Domicilios

        if (o?.web_tipo_entrega === 'domicilio') {
          // Leer envío y paga con de los inputs de la card
          const grid = document.getElementById('webGrid');
          const envio = +(grid.querySelector(`.web-envio[data-id="${id}"]`)?.value || o.costo_envio || 0);
          const pagaCon = grid.querySelector(`.web-pagacon[data-id="${id}"]`)?.value || '';

          if (envio <= 0 && +o.envio_por_cotizar) {
            toast('⚠ Define primero el costo de envío (campo 🛵 Envío)', 'error');
            return;
          }
          payload.costo_envio = envio;
          if (pagaCon) payload.paga_con = pagaCon;

          const cambioTxt = pagaCon ? ` · cambio ${fmt(Math.max(0, +pagaCon - (+o.total + envio)))}` : '';
          if (!confirm(`¿Aceptar pedido a DOMICILIO de ${o.cliente_nombre}?\n\nTotal: ${fmt(+o.total + envio)} (envío ${fmt(envio)})${cambioTxt}\n\nSe enviará a cocina. El repartidor se asigna desde "🛵 Domicilios".`)) return;
        } else {
          if (!confirm(`¿Aceptar pedido para RECOGER de ${o.cliente_nombre}? Se enviará a cocina.`)) return;
        }

        const res = await API.post('webadmin/aceptar', payload);
        const msg = o?.web_tipo_entrega === 'domicilio'
          ? '✓ Aceptado y enviado a cocina · asigna repartidor en 🛵 Domicilios'
          : '✓ Aceptado y enviado a cocina';
        toast(msg, 'success');
        // #5 — Imprimir la(s) comanda(s) de cocina al aceptar el pedido web
        if (res.comandas && res.comandas.length && typeof showComandas === 'function') {
          showComandas(res.comandas, res.impreso || {});
        }
        loadWebOrders();
        if (typeof refreshKitchenBadge === 'function') refreshKitchenBadge();
      } else if (action === 'rechazar') {
        const motivo = prompt('Motivo del rechazo (cerrado, fuera de zona, sin stock, etc.):');
        if (!motivo || motivo.trim().length < 3) { if (motivo !== null) toast('Motivo requerido', 'error'); return; }
        await API.post('webadmin/rechazar', { orden_id: id, motivo: motivo.trim() });
        toast('Pedido rechazado', 'info');
        loadWebOrders();
      }
    } catch (e) { toast(e.message, 'error'); }
  }

  /** Beep de aviso con Web Audio (sin archivo externo) */
  function playNewOrderSound() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.18, 0.36].forEach((t, i) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880 + i * 220;
        gain.gain.setValueAtTime(0.001, ctx.currentTime + t);
        gain.gain.exponentialRampToValueAtTime(0.3, ctx.currentTime + t + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.15);
        osc.connect(gain); gain.connect(ctx.destination);
        osc.start(ctx.currentTime + t);
        osc.stop(ctx.currentTime + t + 0.15);
      });
    } catch (e) { /* navegador bloqueó audio */ }
  }
})();
