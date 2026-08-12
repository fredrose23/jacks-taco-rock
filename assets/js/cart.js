/**
 * Carrito web público · Jacks Rock
 * Maneja: agregar productos, drawer del carrito, checkout (llevar/domicilio),
 * GPS con Leaflet y envío del pedido a la API pública.
 */
(function () {
  const CFG = window.WEB_CFG || {};
  const fmt = n => '$' + Number(n || 0).toFixed(2);

  // Estado del carrito (persistido en localStorage)
  let cart = loadCart();
  let tipoEntrega = null;   // 'llevar' | 'domicilio'
  let metodoPago = 'efectivo'; // 'efectivo' | 'transferencia'
  let gps = { lat: null, lng: null };
  let map = null, marker = null;
  let cfgData = null;       // config pública (datos de transferencia, etc.)

  // Cargar config pública al inicio (datos de transferencia)
  fetch(CFG.apiBase + '?r=web/config').then(r => r.json()).then(d => { cfgData = d; }).catch(() => {});

  const PROD_MODS = window.PROD_MODS || {};

  function loadCart() {
    try { return JSON.parse(localStorage.getItem('jacksCart') || '[]'); }
    catch (e) { return []; }
  }
  function saveCart() {
    localStorage.setItem('jacksCart', JSON.stringify(cart));
    renderFab();
  }

  /** Firma única de una línea: mismo producto + mismos modificadores + misma nota = se agrupa. */
  function lineSig(item) {
    const mods = (item.modificadores || []).map(m => m.id).sort((a, b) => a - b).join(',');
    return item.id + '|' + mods + '|' + (item.notas || '');
  }

  /** Agrega un ítem al carrito (agrupa por firma si ya existe idéntico). */
  function pushToCart(item) {
    const sig = lineSig(item);
    const existing = cart.find(i => lineSig(i) === sig);
    if (existing) existing.qty += (item.qty || 1);
    else cart.push(item);
    saveCart();
    bumpFab();
  }

  /* ============ AGREGAR PRODUCTOS ============ */
  document.querySelectorAll('.add-cart-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = +btn.dataset.id;
      // Producto con modificadores → abrir modal de personalización
      if (btn.dataset.mods === '1' && PROD_MODS[id]) {
        openModsModal({
          id,
          nombre: btn.dataset.nombre,
          precio: +btn.dataset.precio,
          emoji: btn.dataset.emoji || '🍽',
          grupos: PROD_MODS[id],
        });
        return;
      }
      // Producto simple → agregar directo
      pushToCart({
        id,
        nombre: btn.dataset.nombre,
        precio: +btn.dataset.precio,
        emoji: btn.dataset.emoji || '🍽',
        qty: 1,
        modificadores: [],
        notas: '',
        precioExtra: 0,
      });
      // Feedback visual
      const orig = btn.textContent;
      btn.textContent = '✓ Agregado';
      btn.classList.add('added');
      setTimeout(() => { btn.textContent = orig; btn.classList.remove('added'); }, 900);
    });
  });

  /* ============ MODAL DE PERSONALIZACIÓN ============ */
  const modsOverlay = document.getElementById('modsOverlay');
  const modsModal   = document.getElementById('modsModal');
  let MODS_DISH = null;
  let MODS_SELECTED = {}; // { grupo_id: [{id,nombre,precio_extra}, ...] }

  function openModsModal(dish) {
    MODS_DISH = dish;
    MODS_SELECTED = {};
    const body = document.getElementById('modsBody');
    body.innerHTML = `
      <div class="mods-dish-head">
        <span class="em">${dish.emoji}</span>
        <div><h4>${dish.nombre}</h4><div class="pr">${fmt(dish.precio)}</div></div>
      </div>
      ${dish.grupos.map(g => `
        <div class="mods-group">
          <h5>${g.nombre}
            ${g.obligatorio == 1 ? '<span class="req">* obligatorio</span>' : ''}
            <span class="hint">(${g.tipo === 'radio' ? 'elige uno' : 'elige varios'})</span>
          </h5>
          <div class="mods-options" data-grupo="${g.id}" data-tipo="${g.tipo}" data-max="${g.max_selecciones || 1}" data-obligatorio="${g.obligatorio}" data-nombre="${g.nombre.replace(/"/g, '&quot;')}">
            ${g.opciones.map(o => `
              <button class="mod-chip" type="button" data-id="${o.id}" data-nombre="${o.nombre.replace(/"/g, '&quot;')}" data-precio="${o.precio_extra}">
                <span>${o.nombre}</span>
                ${o.precio_extra > 0 ? `<span class="price">+${fmt(o.precio_extra)}</span>` : ''}
              </button>`).join('')}
          </div>
        </div>`).join('')}
      <label class="mods-notas-label">📝 Notas para cocina (opcional)</label>
      <textarea id="modsNotas" rows="2" placeholder="Ej. sin sal, término medio, salsa aparte…"></textarea>`;

    // Bind chips
    body.querySelectorAll('.mods-options').forEach(group => {
      const gid = +group.dataset.grupo;
      const tipo = group.dataset.tipo;
      const max = +group.dataset.max || 1;
      group.querySelectorAll('.mod-chip').forEach(chip => {
        chip.onclick = () => {
          const opt = { id: +chip.dataset.id, nombre: chip.dataset.nombre, precio_extra: +chip.dataset.precio, grupo: gid };
          if (tipo === 'radio') {
            group.querySelectorAll('.mod-chip').forEach(c => c.classList.remove('selected'));
            chip.classList.add('selected');
            MODS_SELECTED[gid] = [opt];
          } else {
            chip.classList.toggle('selected');
            MODS_SELECTED[gid] = MODS_SELECTED[gid] || [];
            if (chip.classList.contains('selected')) {
              if (MODS_SELECTED[gid].length >= max) {
                const first = group.querySelector('.mod-chip.selected:not([data-id="' + opt.id + '"])');
                if (first) { first.classList.remove('selected'); MODS_SELECTED[gid].shift(); }
              }
              MODS_SELECTED[gid].push(opt);
            } else {
              MODS_SELECTED[gid] = MODS_SELECTED[gid].filter(x => x.id !== opt.id);
            }
          }
          updateModsTotal();
        };
      });
    });

    updateModsTotal();
    modsModal.classList.add('open');
    modsOverlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function closeModsModal() {
    modsModal.classList.remove('open');
    modsOverlay.classList.remove('show');
    document.body.style.overflow = '';
    MODS_DISH = null;
  }

  function modsExtra() {
    return Object.values(MODS_SELECTED).flat().reduce((s, o) => s + (+o.precio_extra || 0), 0);
  }
  function updateModsTotal() {
    if (!MODS_DISH) return;
    document.getElementById('modsTotal').textContent = fmt(MODS_DISH.precio + modsExtra());
  }

  document.getElementById('modsClose').addEventListener('click', closeModsModal);
  modsOverlay.addEventListener('click', closeModsModal);

  document.getElementById('modsAdd').addEventListener('click', () => {
    if (!MODS_DISH) return;
    // Validar grupos obligatorios
    const faltan = [];
    document.querySelectorAll('#modsBody .mods-options').forEach(g => {
      const gid = +g.dataset.grupo;
      if (g.dataset.obligatorio == '1' && (!MODS_SELECTED[gid] || !MODS_SELECTED[gid].length)) {
        faltan.push(g.dataset.nombre);
      }
    });
    if (faltan.length) { alert('Falta elegir: ' + faltan.join(', ')); return; }

    const modificadores = Object.values(MODS_SELECTED).flat();
    const notas = (document.getElementById('modsNotas').value || '').trim();
    pushToCart({
      id: MODS_DISH.id,
      nombre: MODS_DISH.nombre,
      precio: MODS_DISH.precio,
      emoji: MODS_DISH.emoji,
      qty: 1,
      modificadores,
      notas,
      precioExtra: modsExtra(),
    });
    closeModsModal();
    openCart();
  });

  /* ============ BOTÓN FLOTANTE ============ */
  const fab = document.getElementById('cartFab');
  const fabCount = document.getElementById('cartFabCount');
  const fabTotal = document.getElementById('cartFabTotal');

  function lineUnit(i) { return i.precio + (i.precioExtra || 0); }
  function cartCount() { return cart.reduce((s, i) => s + i.qty, 0); }
  function cartTotal() { return cart.reduce((s, i) => s + lineUnit(i) * i.qty, 0); }

  function renderFab() {
    const n = cartCount();
    fabCount.textContent = n;
    fabTotal.textContent = fmt(cartTotal());
    fab.classList.toggle('show', n > 0);
  }
  function bumpFab() {
    fab.classList.remove('bump');
    void fab.offsetWidth; // reflow
    fab.classList.add('bump');
  }

  fab.addEventListener('click', openCart);

  /* ============ DRAWER DEL CARRITO ============ */
  const drawer = document.getElementById('cartDrawer');
  const overlay = document.getElementById('cartOverlay');
  const itemsWrap = document.getElementById('cartItems');

  function openCart() {
    renderCart();
    drawer.classList.add('open');
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeCart() {
    drawer.classList.remove('open');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }
  document.getElementById('cartClose').addEventListener('click', closeCart);
  overlay.addEventListener('click', closeCart);

  function renderCart() {
    if (!cart.length) {
      itemsWrap.innerHTML = '<div class="cart-empty">Tu carrito está vacío.<br>Agrega algo del menú 🌮</div>';
    } else {
      itemsWrap.innerHTML = cart.map((it, idx) => {
        const mods = it.modificadores || [];
        const modsHtml = mods.length
          ? `<div class="ci-mods">${mods.map(m => `<span class="ci-mod-pill">${m.nombre}${+m.precio_extra > 0 ? ' +' + fmt(m.precio_extra) : ''}</span>`).join('')}</div>`
          : '';
        const noteHtml = it.notas ? `<div class="ci-note">📝 ${it.notas}</div>` : '';
        return `
        <div class="cart-item">
          <span class="ci-emoji">${it.emoji}</span>
          <div class="ci-info">
            <div class="ci-name">${it.nombre}</div>
            <div class="ci-price">${fmt(lineUnit(it))} c/u</div>
            ${modsHtml}
            ${noteHtml}
          </div>
          <div class="ci-qty">
            <button class="ci-btn" data-act="dec" data-idx="${idx}">−</button>
            <span>${it.qty}</span>
            <button class="ci-btn" data-act="inc" data-idx="${idx}">+</button>
          </div>
          <div class="ci-total">${fmt(lineUnit(it) * it.qty)}</div>
        </div>`;
      }).join('');
      itemsWrap.querySelectorAll('.ci-btn').forEach(b => {
        b.addEventListener('click', () => {
          const idx = +b.dataset.idx;
          if (b.dataset.act === 'inc') cart[idx].qty++;
          else cart[idx].qty--;
          if (cart[idx].qty <= 0) cart.splice(idx, 1);
          saveCart();
          renderCart();
        });
      });
    }
    document.getElementById('cartTotal').textContent = fmt(cartTotal());
    document.getElementById('cartCheckout').disabled = cart.length === 0;
  }

  document.getElementById('cartCheckout').addEventListener('click', () => {
    closeCart();
    openCheckout();
  });

  /* ============ CHECKOUT ============ */
  const ckModal = document.getElementById('checkoutModal');
  const ckOverlay = document.getElementById('checkoutOverlay');

  function openCheckout() {
    showStep('tipo');
    tipoEntrega = null;
    gps = { lat: null, lng: null };
    ckModal.classList.add('open');
    ckOverlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeCheckout() {
    ckModal.classList.remove('open');
    ckOverlay.classList.remove('show');
    document.body.style.overflow = '';
  }
  document.getElementById('checkoutClose').addEventListener('click', closeCheckout);
  ckOverlay.addEventListener('click', closeCheckout);

  function showStep(step) {
    ['tipo','datos','exito'].forEach(s => {
      document.getElementById('step-' + s).style.display = (s === step) ? 'block' : 'none';
    });
  }

  // Selección de tipo de entrega
  document.querySelectorAll('.entrega-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      tipoEntrega = btn.dataset.entrega;
      document.getElementById('domicilioFields').style.display =
        (tipoEntrega === 'domicilio') ? 'block' : 'none';
      gps = { lat: null, lng: null };
      renderCheckResumen();
      updateConfirmState();
      showStep('datos');
    });
  });

  document.getElementById('checkBack').addEventListener('click', () => showStep('tipo'));

  // Selección de forma de pago
  document.querySelectorAll('.pago-opt').forEach(btn => {
    btn.addEventListener('click', () => {
      metodoPago = btn.dataset.pago;
      document.querySelectorAll('.pago-opt').forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      document.getElementById('pagoEfectivo').style.display = metodoPago === 'efectivo' ? 'block' : 'none';
      document.getElementById('pagoTransfer').style.display = metodoPago === 'transferencia' ? 'block' : 'none';
      if (metodoPago === 'transferencia') renderTransferData();
    });
  });

  function renderTransferData() {
    const box = document.getElementById('transferBox');
    const t = cfgData?.transfer || {};
    if (!t.cuenta && !t.clabe) {
      box.innerHTML = `<div class="tb-aviso">Pregunta los datos de transferencia por WhatsApp al confirmar tu pedido.</div>`;
      return;
    }
    box.innerHTML = `
      <div class="tb-row"><span>🏦 Banco</span><b>${t.banco || '—'}</b></div>
      <div class="tb-row"><span>👤 Titular</span><b>${t.titular || '—'}</b></div>
      ${t.cuenta ? `<div class="tb-row"><span>Cuenta/Tarjeta</span><b>${t.cuenta}</b> <button class="tb-copy" data-copy="${t.cuenta}">copiar</button></div>` : ''}
      ${t.clabe ? `<div class="tb-row"><span>CLABE</span><b>${t.clabe}</b> <button class="tb-copy" data-copy="${t.clabe}">copiar</button></div>` : ''}
      <div class="tb-aviso">📲 Transfiere y manda tu comprobante por WhatsApp al confirmar. Tu pedido se prepara una vez recibido el pago.</div>`;
    box.querySelectorAll('.tb-copy').forEach(b => {
      b.onclick = () => { navigator.clipboard.writeText(b.dataset.copy); b.textContent = '✓ copiado'; setTimeout(() => b.textContent = 'copiar', 1500); };
    });
  }

  function renderCheckResumen() {
    const esDom = tipoEntrega === 'domicilio';
    const lineas = cart.map(i => `<div class="cr-line"><span>${i.qty}× ${i.nombre}</span><span>${fmt(lineUnit(i)*i.qty)}</span></div>`).join('');
    const total = cartTotal();

    // El envío ya NO se calcula en la web — lo define el restaurante.
    const envioRow = esDom
      ? `<div class="cr-line cr-cotizar"><span>🛵 Envío</span><span>lo confirma el restaurante</span></div>`
      : '';

    document.getElementById('checkResumen').innerHTML = `
      ${lineas}
      ${envioRow}
      <div class="cr-line cr-total"><span>${esDom ? 'SUBTOTAL' : 'TOTAL'}</span><span>${fmt(total)}</span></div>
      ${esDom ? `<div class="cr-aviso-envio">📲 El total final (con envío) te lo confirmamos por WhatsApp según tu ubicación. Tú decides si continúas o cancelas.</div>` : ''}`;
  }

  /** El botón confirmar siempre habilitado (GPS es opcional, recomendado) */
  function updateConfirmState() {
    const btn = document.getElementById('checkConfirm');
    btn.disabled = false;
    btn.textContent = 'Enviar pedido';
  }

  /* ============ GPS + LEAFLET ============ */
  document.getElementById('ckGpsBtn').addEventListener('click', () => {
    const status = document.getElementById('ckGpsStatus');
    if (!navigator.geolocation) {
      status.textContent = 'Tu navegador no soporta GPS. Escribe la dirección.';
      return;
    }
    status.textContent = '📡 Obteniendo tu ubicación…';
    navigator.geolocation.getCurrentPosition(
      pos => {
        gps.lat = pos.coords.latitude;
        gps.lng = pos.coords.longitude;
        status.innerHTML = '✅ Ubicación capturada. Arrastra el marcador para ajustar.';
        showMap(gps.lat, gps.lng);
      },
      err => {
        status.textContent = '⚠ No se pudo obtener ubicación (' + err.message + '). Activa el GPS y permite el acceso.';
      },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  });

  function showMap(lat, lng) {
    const mapDiv = document.getElementById('ckMap');
    mapDiv.style.display = 'block';
    document.getElementById('mapHint').style.display = 'block';

    if (!map) {
      map = L.map('ckMap').setView([lat, lng], 16);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap',
        maxZoom: 19,
      }).addTo(map);
      marker = L.marker([lat, lng], { draggable: true }).addTo(map);
      marker.on('dragend', () => {
        const p = marker.getLatLng();
        gps.lat = p.lat; gps.lng = p.lng;
      });
    } else {
      map.setView([lat, lng], 16);
      marker.setLatLng([lat, lng]);
    }
    // Fix de render de Leaflet dentro de modal
    setTimeout(() => map.invalidateSize(), 200);
  }

  /* ============ ENVIAR PEDIDO ============ */
  document.getElementById('checkConfirm').addEventListener('click', async () => {
    const nombre = document.getElementById('ckNombre').value.trim();
    const tel = document.getElementById('ckTel').value.replace(/\D/g, '');
    if (nombre.length < 2) { alert('Escribe tu nombre'); return; }
    if (tel.length < 10) { alert('Escribe un teléfono válido (10 dígitos)'); return; }

    // Validar transferencia
    if (metodoPago === 'transferencia' && !document.getElementById('ckTransferOk').checked) {
      alert('Para pago por transferencia, marca la casilla de que transferirás antes de que preparen tu pedido.');
      return;
    }

    const payload = {
      tipo_entrega: tipoEntrega,
      metodo_pago: metodoPago,
      cliente: { nombre, telefono: tel },
      items: cart.map(i => ({
        producto_id: i.id,
        cantidad: i.qty,
        modificadores: (i.modificadores || []).map(m => ({ id: m.id, nombre: m.nombre, precio_extra: m.precio_extra })),
        notas: i.notas || '',
      })),
      combos: [],
    };

    // Con cuánto paga (solo efectivo)
    if (metodoPago === 'efectivo') {
      const pc = +document.getElementById('ckPagaCon').value || 0;
      if (pc > 0) payload.paga_con = pc;
    }

    if (tipoEntrega === 'domicilio') {
      const dir = document.getElementById('ckDir').value.trim();
      if (dir.length < 5) { alert('Escribe tu dirección'); return; }
      payload.cliente.direccion = dir;
      payload.cliente.referencias = document.getElementById('ckRef').value.trim();
      // GPS opcional pero muy recomendado (ayuda al repartidor a llegar)
      if (gps.lat && gps.lng) {
        payload.cliente.lat = gps.lat;
        payload.cliente.lng = gps.lng;
      }
    }

    const btn = document.getElementById('checkConfirm');
    btn.disabled = true;
    btn.textContent = 'Enviando…';

    try {
      const res = await fetch(CFG.apiBase + '?r=web/submit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok) {
        // Si cerró mientras armaba el pedido, avisar y recargar
        if (data.cerrado) { alert('🌙 ' + (data.error || 'Estamos cerrados.')); location.reload(); return; }
        throw new Error(data.error || 'Error al enviar');
      }

      // Limpiar carrito y mostrar éxito
      cart = [];
      saveCart();
      document.getElementById('exitoMsg').textContent = data.mensaje;
      const waBtn = document.getElementById('waConfirmBtn');
      if (data.whatsapp) {
        waBtn.href = data.whatsapp;
        waBtn.style.display = '';
      } else {
        waBtn.style.display = 'none';
      }
      showStep('exito');
    } catch (e) {
      alert('No se pudo enviar el pedido: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Confirmar pedido';
    }
  });

  document.getElementById('exitoClose').addEventListener('click', closeCheckout);

  // Init
  renderFab();
})();
