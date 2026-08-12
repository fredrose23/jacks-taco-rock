/**
 * Lógica de la aplicación según el módulo activo
 */
const STATUS_LBL = { libre: 'Libre', ocupada: 'Ocupada', por_cobrar: 'Por cobrar' };
const MODULE = document.body.dataset.module;

/*
 * Variables a nivel de módulo declaradas ARRIBA del IIFE para evitar TDZ
 * (Temporal Dead Zone) cuando alguna función init las accede antes de
 * llegar a su declaración en el orden de ejecución del script.
 */
let ORDER_STATE       = { orden: null, productos: [], catActive: null, comensal: 1, comensales: [1] };
let SPLIT             = [];
let METHODS           = [];
let CURRENT_PAY_TOTAL = 0;
let PAY_GOODS_TOTAL   = 0;
let PAY_DISCOUNT      = { monto: 0, motivo: '' };
let KITCHEN_FILTER    = +(localStorage.getItem('kitchenFilter') || 0); // 0=todas, 1=cocina1, 2=cocina2
let INV_DATA          = [];
let INV_FILTER        = 'all';
let INS_DATA          = [];
let INS_FILTER        = 'all';
let MODS_DISH         = null;
let MODS_SELECTED     = {};

(async function init() {
  try {
    if (MODULE === 'tables')    await initTables();
    if (MODULE === 'menu')      await initMenu();
    if (MODULE === 'orders')    await initOrder();
    if (MODULE === 'kitchen')   await initKitchen();
    if (MODULE === 'reports')   await initReports();
    if (MODULE === 'inventory') await initInventory();
  } catch (e) {
    console.error('Module init failed:', e);
    if (typeof toast === 'function') toast('Error al cargar: ' + e.message, 'error');
  }
})();

/* =============== MODAL: NUEVO PEDIDO LLEVAR / DOMICILIO =============== */
let TAKEOUT_TIPO = 'llevar';
let TAKEOUT_CLIENTE_ID = null;

window.openTakeoutModal = async (tipo) => {
  TAKEOUT_TIPO = tipo;
  TAKEOUT_CLIENTE_ID = null;
  document.getElementById('toTitle').textContent =
    tipo === 'domicilio' ? '🛵 Nuevo pedido a domicilio' : '📦 Nuevo pedido para llevar';
  document.getElementById('toSub').textContent =
    tipo === 'domicilio'
      ? 'Captura los datos del cliente. Si ya está registrado, se autocompleta al teclear el teléfono.'
      : 'Captura quién recoge el pedido (mínimo nombre).';

  ['toTel','toNombre','toDir','toRef','toEnvio','toPickup','toRepartidor','toMetodoPago'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });

  // Mostrar/ocultar campos según tipo
  document.getElementById('toDireccionFields').style.display = tipo === 'domicilio' ? '' : 'none';
  document.getElementById('toPickupField').style.display = tipo === 'llevar' ? '' : 'none';

  if (tipo === 'domicilio') {
    // Sin default: el restaurante define el envío manualmente (no se calcula solo)
    document.getElementById('toEnvio').value = '';
    // Cargar repartidores activos
    try {
      const reps = await API.get('delivery/repartidores');
      const sel = document.getElementById('toRepartidor');
      sel.innerHTML = '<option value="">Sin asignar</option>' + reps.map(r =>
        `<option value="${r.id}">${r.nombre} ${r.turno_estado==='en_curso'?'· en turno':'· no en turno'} (${r.pendientes} pend.)</option>`
      ).join('');
    } catch (e) { console.warn('Sin repartidores:', e); }
  }

  // Bind lookup por teléfono (debounce 400ms)
  const tel = document.getElementById('toTel');
  tel.oninput = () => {
    clearTimeout(window._toLookupT);
    window._toLookupT = setTimeout(() => lookupCliente(tel.value), 400);
  };

  document.getElementById('toSave').onclick = createTakeoutOrder;
  openModal('takeoutModal');
};

async function lookupCliente(q) {
  const box = document.getElementById('toMatches');
  if (!q || q.length < 3) { box.style.display = 'none'; return; }
  try {
    const matches = await API.get('clientes/search', { q });
    if (!matches.length) { box.style.display = 'none'; return; }
    box.innerHTML = matches.map(c => `
      <div class="cli-match" onclick='pickCliente(${JSON.stringify(c).replace(/'/g,"&apos;")})'>
        <b>${c.nombre}</b> · ${c.telefono}
        ${c.direccion ? `<br><small>📍 ${c.direccion}</small>` : ''}
        ${c.total_pedidos ? `<span class="freq-tag">${c.total_pedidos} pedidos</span>` : ''}
      </div>`).join('');
    box.style.display = '';
  } catch (e) { /* silent */ }
}

window.pickCliente = (c) => {
  TAKEOUT_CLIENTE_ID = c.id;
  document.getElementById('toTel').value = c.telefono;
  document.getElementById('toNombre').value = c.nombre;
  document.getElementById('toDir').value = c.direccion || '';
  document.getElementById('toRef').value = c.referencias || '';
  document.getElementById('toMatches').style.display = 'none';
  toast(`Cliente ${c.nombre} cargado`, 'info');
};

async function createTakeoutOrder() {
  const data = {
    tipo: TAKEOUT_TIPO,
    cliente_telefono: document.getElementById('toTel').value.trim(),
    cliente_nombre:   document.getElementById('toNombre').value.trim(),
    cliente_direccion: document.getElementById('toDir').value.trim(),
    cliente_referencias: document.getElementById('toRef').value.trim(),
    costo_envio: +document.getElementById('toEnvio').value || 0,
    hora_pickup: document.getElementById('toPickup').value || null,
    metodo_pago: document.getElementById('toMetodoPago')?.value || null,
    repartidor_id: +document.getElementById('toRepartidor').value || null,
  };
  try {
    const res = await API.post('ordenes/create_takeout', data);
    closeModal('takeoutModal');
    toast('Pedido creado · agrega productos', 'success');
    // Redirigir a la página de pedido con el orden_id
    location.href = `${APP.sysUrl}/index.php?module=orders&orden=${res.orden_id}`;
  } catch (e) { toast(e.message, 'error'); }
}

/**
 * Venta rápida / mostrador: crea una orden sin mesa ni cocina y abre la
 * pantalla de pedido para agregar productos y cobrar al instante.
 */
window.startMostrador = async function () {
  try {
    const res = await API.post('ordenes/create_mostrador', {});
    location.href = `${APP.sysUrl}/index.php?module=orders&orden=${res.orden_id}`;
  } catch (e) { toast(e.message, 'error'); }
};

/* =============== MESAS =============== */
async function initTables() {
  const grid = document.getElementById('tablesGrid');
  const sub = document.getElementById('viewSub');
  const load = async () => {
    try {
      const mesas = await API.get('mesas/list');
      sub.textContent = `${mesas.length} mesas · Toca una mesa para gestionarla`;
      grid.innerHTML = '';
      mesas.forEach(m => {
        const a = document.createElement('a');
        a.href = `${APP.sysUrl}/index.php?module=orders&mesa=${m.id}`;
        a.className = `table-card status-${m.estado}`;
        a.innerHTML = `
          <div class="bar"></div>
          <div class="num">${mesaLabel(m, 'M')}</div>
          <div class="seats">${(m.nombre||'').trim() ? `<b>M${String(m.numero).padStart(2,'0')}</b> · ` : ''}${m.capacidad} personas · ${m.zona}</div>
          ${m.descripcion ? `<div class="table-desc" title="${m.descripcion}">${m.descripcion}</div>` : ''}
          <div class="status-pill">${STATUS_LBL[m.estado]}</div>
          ${(+m.platillos || +m.aguas) ? `<div class="total">${[
              +m.platillos ? `${m.platillos} platillos` : '',
              +m.aguas ? `${m.aguas} aguas` : ''
            ].filter(Boolean).join(' · ')} · <b>${fmt(m.total)}</b></div>` : ''}`;
        grid.appendChild(a);
      });
    } catch (e) {
      grid.innerHTML = `<div class="empty-state"><div class="ico">⚠</div>${e.message}</div>`;
    }
  };
  await load();
  setInterval(load, 8000);
}

/* =============== MENÚ =============== */
async function initMenu() {
  const tabs = document.getElementById('menuCats');
  const grid = document.getElementById('menuGrid');
  const productos = await API.get('productos/list');
  const cats = ['Todos', ...new Set(productos.map(p => p.categoria))];
  let active = 'Todos';

  const renderTabs = () => {
    tabs.innerHTML = '';
    cats.forEach(c => {
      const b = document.createElement('button');
      b.className = 'cat-tab' + (active === c ? ' active' : '');
      b.textContent = c;
      b.onclick = () => { active = c; renderTabs(); renderGrid(); };
      tabs.appendChild(b);
    });
  };
  const renderGrid = () => {
    grid.innerHTML = '';
    productos
      .filter(p => active === 'Todos' || p.categoria === active)
      .forEach(d => {
        const card = document.createElement('div');
        card.className = 'dish-card clickable' + (!d.disponible ? ' out' : '') + (d.destacado ? ' featured' : '');
        const imgHtml = d.imagen
          ? `<img src="${APP.url}/assets/img/products/${d.imagen}" alt="${d.nombre}" loading="lazy" style="width:100%;height:100%;object-fit:cover;">`
          : `<span style="font-size:48px;">${d.emoji || '🍽'}</span>`;
        card.innerHTML = `
          <div class="dish-img">${imgHtml}</div>
          ${d.destacado ? `<div class="badge">★ TOP</div>` : ''}
          <div class="dish-body">
            <div class="cat">${d.categoria}</div>
            <h4>${d.nombre}</h4>
            <p>${d.descripcion || ''}</p>
            <div class="price">${fmt(d.precio)}</div>
          </div>`;
        card.onclick = () => showDishPreview(d);
        grid.appendChild(card);
      });
  };
  renderTabs(); renderGrid();
  document.getElementById('viewSub').textContent = `${productos.length} platillos · ${cats.length - 1} categorías`;
}

/* =============== TOMAR ORDEN =============== */
async function initOrder() {
  try {
    const params = window.CURRENT_ORDEN_ID
      ? { orden_id: window.CURRENT_ORDEN_ID }
      : { mesa_id: window.CURRENT_MESA_ID };
    const [orden, productos, combos] = await Promise.all([
      API.get('ordenes/get_or_create', params),
      API.get('productos/list'),
      API.get('productos/combos').catch(() => []),
    ]);
    ORDER_STATE.orden = orden;
    ORDER_STATE.productos = productos;
    ORDER_STATE.combos = combos || [];
    ORDER_STATE.comensales = [...new Set([1, ...orden.items.map(i => i.comensal)])].sort((a,b)=>a-b);
    ORDER_STATE.comensal = ORDER_STATE.comensales[0];
    const cats = [...new Set(productos.map(p => p.categoria))];
    ORDER_STATE.catActive = cats[0];

    // Renderizar info según tipo
    renderOrderHeader(orden);

    const ordenId = orden.id || orden.orden_id || window.CURRENT_ORDEN_ID || '';
    document.getElementById('orderTableSub').textContent = ordenId ? `Orden #${ordenId}` : 'Orden nueva';
    renderOrderTabs(cats);
    renderOrderDishes();
    renderComensalBar();
    renderCart();
    bindOrderActions();
  } catch (e) {
    // Errores visibles en lugar de pantalla en blanco
    document.getElementById('orderTableSub').innerHTML = '<span style="color:var(--red);">⚠ Error al cargar</span>';
    document.getElementById('orderDishes').innerHTML = `
      <div class="empty-state" style="grid-column:1/-1;">
        <div class="ico">⚠</div>
        <h3>No se pudo cargar la orden</h3>
        <p style="margin:8px 0 16px;">${e.message}</p>
        <button class="btn btn-primary" onclick="location.reload()">↻ Reintentar</button>
        <a class="btn btn-ghost" href="${APP.sysUrl}/index.php?module=tables" style="margin-left:8px;">← Volver a mesas</a>
      </div>`;
    toast(e.message, 'error');
    console.error('initOrder failed:', e);
  }
}

function renderComensalBar() {
  const sel = document.getElementById('comensalSelect');
  sel.innerHTML = ORDER_STATE.comensales.map(c => `<option value="${c}" ${c===ORDER_STATE.comensal?'selected':''}>Comensal ${c}</option>`).join('');
  sel.onchange = e => { ORDER_STATE.comensal = +e.target.value; renderCart(); };
  document.getElementById('addComensal').onclick = () => {
    const next = Math.max(...ORDER_STATE.comensales) + 1;
    ORDER_STATE.comensales.push(next);
    ORDER_STATE.comensal = next;
    renderComensalBar();
  };
}

function renderOrderHeader(orden) {
  const title = document.getElementById('orderHeaderTitle');
  const label = document.getElementById('orderTableLabel');
  const customerBox = document.getElementById('customerInfoBox');
  const envioRow = document.getElementById('envioRow');

  const tipo = (orden.tipo || 'local').toLowerCase();
  // "Cambiar de mesa" solo tiene sentido en pedidos de mesa (local)
  const mvBtn = document.getElementById('btnMoveMesa');
  if (mvBtn) mvBtn.style.display = tipo === 'local' ? '' : 'none';
  // "Liberar mesa" solo en pedidos de mesa que quedaron SIN productos activos
  const freeBtn = document.getElementById('btnFreeMesa');
  if (freeBtn) {
    const activos = (orden.items || []).reduce((s, i) => s + Math.max(0, (i.cantidad||0) - (i.cantidad_cancelada||0)), 0);
    freeBtn.style.display = (tipo === 'local' && activos === 0) ? '' : 'none';
  }
  if (tipo === 'mostrador') {
    title.innerHTML = '🥤 Venta rápida (mostrador)';
    label.textContent = `Venta #${orden.id}`;
    customerBox.style.display = 'none';
    envioRow.style.display = 'none';
  } else if (tipo === 'local') {
    title.textContent = 'Tomar pedido';
    label.textContent = mesaLabel({ nombre: orden.mesa_nombre, numero: orden.mesa_numero })
                        || `Mesa ${window.CURRENT_MESA_ID || orden.mesa_id || '?'}`;
    customerBox.style.display = 'none';
    envioRow.style.display = 'none';
  } else {
    const icon = tipo === 'domicilio' ? '🛵' : '📦';
    title.innerHTML = `${icon} Pedido ${tipo === 'domicilio' ? 'a domicilio' : 'para llevar'}`;
    label.textContent = `Orden #${orden.id}`;
    customerBox.style.display = '';
    customerBox.innerHTML = `
      <div class="ci-line"><b>${orden.cliente_nombre || '—'}</b> · ${orden.cliente_telefono || ''}</div>
      ${orden.cliente_direccion ? `<div class="ci-line">📍 ${orden.cliente_direccion}</div>` : ''}
      ${orden.cliente_referencias ? `<div class="ci-line">📝 ${orden.cliente_referencias}</div>` : ''}
      ${orden.repartidor_id ? `<div class="ci-line">🛵 Repartidor: <b>asignado</b></div>` : ''}
      ${+orden.costo_envio > 0 ? `<div class="ci-line">Envío: <b>${fmt(orden.costo_envio)}</b></div>` : ''}
    `;
    if (+orden.costo_envio > 0) {
      envioRow.style.display = '';
      document.getElementById('sumEnvio').textContent = fmt(orden.costo_envio);
    }
  }
}

function renderOrderTabs(cats) {
  const tabs = document.getElementById('orderCats');
  tabs.innerHTML = '';

  // Pestaña especial de Combos al inicio si hay combos disponibles
  if ((ORDER_STATE.combos || []).length) {
    const cb = document.createElement('button');
    cb.className = 'cat-tab cat-combos' + (ORDER_STATE.catActive === '__combos__' ? ' active' : '');
    cb.innerHTML = '🎁 Combos';
    cb.onclick = () => { ORDER_STATE.catActive = '__combos__'; renderOrderTabs(cats); renderOrderDishes(); };
    tabs.appendChild(cb);
  }

  cats.forEach(c => {
    const b = document.createElement('button');
    b.className = 'cat-tab' + (ORDER_STATE.catActive === c ? ' active' : '');
    b.textContent = c;
    b.onclick = () => { ORDER_STATE.catActive = c; renderOrderTabs(cats); renderOrderDishes(); };
    tabs.appendChild(b);
  });
}

function renderOrderDishes() {
  const grid = document.getElementById('orderDishes');
  grid.innerHTML = '';
  // Cambiar el layout del grid según si estamos en modo combos
  grid.classList.toggle('combos-mode', ORDER_STATE.catActive === '__combos__');

  // Modo COMBOS
  if (ORDER_STATE.catActive === '__combos__') {
    (ORDER_STATE.combos || []).forEach(c => {
      const itemsTxt = (c.items || []).map(i => `${i.cantidad}× ${i.nombre}`).join(' · ');
      const card = document.createElement('div');
      card.className = 'combo-card';
      card.innerHTML = `
        <div class="combo-head">
          <span class="combo-em">${c.emoji || '🎁'}</span>
          <div class="combo-meta">
            <h4>${c.nombre}</h4>
            ${c.descripcion ? `<small class="desc">${c.descripcion}</small>` : ''}
          </div>
        </div>
        <div class="combo-items">${itemsTxt}</div>
        <div class="combo-price">${fmt(c.precio)}</div>`;
      card.onclick = () => addComboToOrder(c);
      grid.appendChild(card);
    });
    if (!(ORDER_STATE.combos || []).length) {
      grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;">
        <div class="ico">🎁</div>
        <h3>Sin combos activos</h3>
        <p>Crea combos en <b>Sistema → Combos</b></p>
      </div>`;
    }
    return;
  }

  ORDER_STATE.productos
    .filter(p => p.categoria === ORDER_STATE.catActive)
    .forEach(d => {
      const hasMods = (d.modificadores || []).length > 0;
      const sinStock = d.maneja_stock && d.stock <= 0;
      const agotado  = !d.disponible || sinStock;
      const el = document.createElement('button');
      el.className = 'order-dish' + (agotado ? ' dish-off' : '');
      el.disabled = agotado;
      el.innerHTML = `
        ${agotado ? `<span class="off">${sinStock && d.disponible ? 'Agotado' : 'N/D'}</span>` : ''}
        ${d.cocina_2 ? `<span class="cocina-mini" title="Se prepara en Cocina B">🍳</span>` : ''}
        ${hasMods ? `<span class="mods-mini" title="Tiene opciones (sin/extra)">⚙</span>` : ''}
        <span class="zoom-hint" title="Mantén pulsado para ver detalle">🔍</span>
        ${dishVisual(d)}
        <div class="nm">${d.nombre}</div>
        <div class="pr">${fmt(d.precio)}</div>`;

      // Click corto → agregar / abrir modal de mods
      el.onclick = (ev) => {
        if (el.dataset.longpressed === '1') {
          delete el.dataset.longpressed;
          ev.preventDefault();
          return;
        }
        onDishClick(d);
      };

      // Long-press (touch + mouse) → preview grande
      attachLongPress(el, () => showDishPreview(d));

      // Right-click (desktop) → preview grande
      el.oncontextmenu = (ev) => {
        ev.preventDefault();
        showDishPreview(d);
      };

      grid.appendChild(el);
    });
}

/**
 * Adjunta un detector de long-press (~500 ms) a un elemento.
 * Funciona en touch y mouse. Cuando dispara el callback, marca el elemento
 * para que el siguiente click sea ignorado (no se ejecute la acción normal).
 */
function attachLongPress(el, callback, ms = 500) {
  let timer = null;
  let moved = false;
  const start = (e) => {
    moved = false;
    timer = setTimeout(() => {
      if (!moved) {
        el.dataset.longpressed = '1';
        callback(e);
      }
    }, ms);
  };
  const cancel = () => { if (timer) { clearTimeout(timer); timer = null; } };
  const move = () => { moved = true; cancel(); };

  el.addEventListener('touchstart', start, { passive: true });
  el.addEventListener('touchmove',  move,  { passive: true });
  el.addEventListener('touchend',   cancel);
  el.addEventListener('touchcancel',cancel);
  el.addEventListener('mousedown',  start);
  el.addEventListener('mousemove',  move);
  el.addEventListener('mouseup',    cancel);
  el.addEventListener('mouseleave', cancel);
}

/**
 * Muestra el preview grande de un platillo con su imagen, descripción y
 * botón directo para agregarlo al pedido (si está disponible y no está
 * cargando otra cosa).
 */
function showDishPreview(d) {
  const img = document.getElementById('dpImg');
  if (d.imagen) {
    const url = `${APP.url}/assets/img/products/${d.imagen}`;
    img.innerHTML = `<img src="${url}" alt="${d.nombre}">`;
  } else {
    img.innerHTML = `<div class="dp-emoji">${d.emoji || '🍽'}</div>`;
  }
  document.getElementById('dpCat').textContent = d.categoria || '';
  document.getElementById('dpName').textContent = d.nombre || '';
  document.getElementById('dpDesc').textContent = d.descripcion || 'Sin descripción.';
  document.getElementById('dpPrice').textContent = fmt(d.precio);

  const tags = [];
  if (d.destacado) tags.push('<span class="dp-tag dp-featured">⭐ DESTACADO</span>');
  if (d.cocina_2)  tags.push('<span class="dp-tag dp-c2">🍳 Cocina B</span>');
  if ((d.modificadores || []).length) tags.push('<span class="dp-tag dp-mods">⚙ Tiene opciones</span>');
  if (!d.disponible) tags.push('<span class="dp-tag dp-na">No disponible</span>');
  document.getElementById('dpTags').innerHTML = tags.join(' ');

  const addBtn = document.getElementById('dpAdd');
  addBtn.disabled = !d.disponible;
  // Solo mostrar botón de agregar si estamos en la pantalla de pedido
  if (typeof onDishClick === 'function' && document.body.dataset.module === 'orders') {
    addBtn.style.display = '';
    addBtn.onclick = () => { closeModal('dishPreviewModal'); onDishClick(d); };
  } else {
    addBtn.style.display = 'none';
  }
  openModal('dishPreviewModal');
}

/** Render común para imagen/emoji de un platillo en cualquier grid */
function dishVisual(d) {
  if (d.imagen) {
    const base = d.imagen.replace(/\.png$/, '');
    return `<div class="dish-pic">
      <img src="${APP.url}/assets/img/products/${base}-thumb.png" alt="${d.nombre}" loading="lazy">
    </div>`;
  }
  return `<div class="em">${d.emoji || '🍽'}</div>`;
}

/**
 * Al tocar un platillo:
 *  - Si NO tiene grupos de modificadores → agrega directo
 *  - Si tiene → abre modal con opciones (sin algo / extra X con cargo)
 */
function onDishClick(d) {
  const hasMods = (d.modificadores || []).length > 0;
  if (!hasMods) return addToCart(d.id);
  openModsModal(d);
}

async function addToCart(producto_id, modificadores = [], notas = '') {
  try {
    const params = {
      producto_id,
      comensal: ORDER_STATE.comensal,
      modificadores,
      notas,
    };
    // Para pedidos llevar/domicilio mandamos orden_id; para mesas mandamos mesa_id
    if (window.CURRENT_ORDEN_ID) params.orden_id = window.CURRENT_ORDEN_ID;
    else params.mesa_id = window.CURRENT_MESA_ID;
    await API.post('ordenes/add_item', params);
    await reloadOrder();
  } catch (e) { toast(e.message, 'error'); }
}

/**
 * Agrega un combo al pedido — descompone en los productos que lo conforman
 * con precio prorrateado (lo hace el backend).
 */
async function addComboToOrder(combo) {
  if (!confirm(`¿Agregar combo "${combo.nombre}" por ${fmt(combo.precio)}?`)) return;
  try {
    const params = {
      combo_id: combo.id,
      comensal: ORDER_STATE.comensal,
    };
    if (window.CURRENT_ORDEN_ID) params.orden_id = window.CURRENT_ORDEN_ID;
    else params.mesa_id = window.CURRENT_MESA_ID;
    await API.post('ordenes/add_combo', params);
    toast(`Combo "${combo.nombre}" agregado al pedido`, 'success');
    await reloadOrder();
  } catch (e) { toast(e.message, 'error'); }
}

/* ============ MODAL DE MODIFICADORES ============ */
function openModsModal(d) {
  MODS_DISH = d;
  MODS_SELECTED = {}; // { grupo_id: [{id,nombre,precio_extra}, ...] }
  const wrap = document.getElementById('modsModalBody');
  wrap.innerHTML = `
    <div class="mods-dish-head">
      <span class="em">${d.emoji}</span>
      <div>
        <h4>${d.nombre}</h4>
        <div class="pr">${fmt(d.precio)}</div>
      </div>
    </div>
    ${d.modificadores.map(g => `
      <div class="mods-group">
        <h5>${g.nombre} ${g.obligatorio==1?'<span style="color:var(--red);font-size:11px;">* obligatorio</span>':''}
          <small style="color:var(--muted);">(${g.tipo==='radio'?'elige uno':'elige varios'})</small></h5>
        <div class="mods-options" data-grupo="${g.id}" data-tipo="${g.tipo}" data-max="${g.max_selecciones||1}" data-obligatorio="${g.obligatorio}">
          ${g.opciones.map(o => `
            <button class="mod-chip" type="button" data-id="${o.id}" data-nombre="${o.nombre}" data-precio="${o.precio_extra}">
              <span>${o.nombre}</span>
              ${o.precio_extra>0?`<span class="price">+${fmt(o.precio_extra)}</span>`:''}
            </button>
          `).join('')}
        </div>
      </div>
    `).join('')}
    <label class="inv-label" style="margin-top:10px;">Notas para cocina (opcional)</label>
    <textarea id="modsNotas" class="inv-input" rows="2" placeholder="Ej. sin sal, jugoso, salsa aparte..."></textarea>
    <div class="mods-total" id="modsTotalRow">
      <span>Total del platillo:</span>
      <b id="modsTotalVal">${fmt(d.precio)}</b>
    </div>`;

  // bind chips
  wrap.querySelectorAll('.mods-options').forEach(group => {
    const gid = +group.dataset.grupo;
    const tipo = group.dataset.tipo;
    const max = +group.dataset.max || 1;
    group.querySelectorAll('.mod-chip').forEach(chip => {
      chip.onclick = () => {
        const opt = { id:+chip.dataset.id, nombre:chip.dataset.nombre, precio_extra:+chip.dataset.precio, grupo:gid };
        if (tipo === 'radio') {
          group.querySelectorAll('.mod-chip').forEach(c => c.classList.remove('selected'));
          chip.classList.add('selected');
          MODS_SELECTED[gid] = [opt];
        } else {
          chip.classList.toggle('selected');
          MODS_SELECTED[gid] = MODS_SELECTED[gid] || [];
          if (chip.classList.contains('selected')) {
            if (MODS_SELECTED[gid].length >= max) {
              const first = group.querySelector('.mod-chip.selected:not([data-id="'+opt.id+'"])');
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

  openModal('modsModal');
}

function updateModsTotal() {
  const base = +MODS_DISH.precio;
  const extra = Object.values(MODS_SELECTED).flat().reduce((s, o) => s + (+o.precio_extra || 0), 0);
  document.getElementById('modsTotalVal').textContent = fmt(base + extra);
}

async function confirmAddWithMods() {
  // Validar obligatorios
  const wrap = document.getElementById('modsModalBody');
  let faltan = [];
  wrap.querySelectorAll('.mods-options').forEach(g => {
    const gid = +g.dataset.grupo;
    if (g.dataset.obligatorio == '1' && (!MODS_SELECTED[gid] || !MODS_SELECTED[gid].length)) {
      faltan.push(g.previousElementSibling?.firstChild?.nodeValue?.trim() || 'grupo');
    }
  });
  if (faltan.length) { toast('Falta elegir: ' + faltan.join(', '), 'error'); return; }

  const modificadores = Object.values(MODS_SELECTED).flat();
  const notas = document.getElementById('modsNotas').value.trim();
  closeModal('modsModal');
  await addToCart(MODS_DISH.id, modificadores, notas);
}

async function reloadOrder() {
  const params = window.CURRENT_ORDEN_ID
    ? { orden_id: window.CURRENT_ORDEN_ID }
    : { mesa_id: window.CURRENT_MESA_ID };
  ORDER_STATE.orden = await API.get('ordenes/get_or_create', params);
  // mantén comensales sincronizados
  const fromItems = [...new Set(ORDER_STATE.orden.items.map(i => i.comensal))];
  ORDER_STATE.comensales = [...new Set([...ORDER_STATE.comensales, ...fromItems])].sort((a,b)=>a-b);
  renderComensalBar();
  renderCart();
}

function renderCart() {
  const o = ORDER_STATE.orden;
  const wrap = document.getElementById('cartItems');
  wrap.innerHTML = '';

  // Agrupar items por comensal para mejor visibilidad
  const grupos = {};
  o.items.forEach(it => { (grupos[it.comensal] = grupos[it.comensal] || []).push(it); });

  if (!o.items.length) {
    wrap.innerHTML = `<div class="cart-empty">Toca un platillo del menú para agregarlo</div>`;
  } else {
    Object.keys(grupos).sort((a,b)=>+a-+b).forEach(comensal => {
      const titulo = document.createElement('div');
      titulo.className = 'comensal-divider';
      titulo.textContent = `Comensal ${comensal}`;
      wrap.appendChild(titulo);
      grupos[comensal].forEach(it => {
        const mods = it.modificadores_json ? JSON.parse(it.modificadores_json) : [];
        const extra = +it.precio_extra || 0;
        const cancQty = +it.cantidad_cancelada || 0;
        const effQty  = Math.max(0, it.cantidad - cancQty);
        const fully   = effQty <= 0;
        const row = document.createElement('div');
        row.className = 'cart-item' + (fully ? ' item-cancelado' : '');
        // Sub-línea de lo cancelado (tachado) — visible para auditoría en mesa
        const cancelNote = cancQty > 0
          ? `<div class="cancel-note"><s>❌ ${cancQty}× cancelado${cancQty>1?'s':''}</s>${it.motivo_cancel?` · ${it.motivo_cancel}`:''}</div>`
          : '';
        row.innerHTML = `
          <div>
            <div class="nm ${fully?'txt-cancel':''}">${it.emoji} ${it.nombre} ${it.enviado ? '<span class="sent-tag">✓ enviado</span>' : ''}${it.para_llevar==1 ? ' <span class="llevar-tag">🥡 PARA LLEVAR</span>' : ''}</div>
            <div class="pr">${fmt(it.precio)}${extra>0?` <span style="color:var(--accent-2);">+ ${fmt(extra)} extras</span>`:''} c/u</div>
            ${mods.length ? `<div class="cart-mods">${mods.map(m => `<span class="mod-pill">${m.nombre}${+m.precio_extra>0?' +'+fmt(m.precio_extra):''}</span>`).join('')}</div>` : ''}
            ${it.notas ? `<div class="note-tag">★ ${it.notas}</div>` : ''}
            ${cancelNote}
            <div class="item-actions">
              ${!it.enviado ? `
                <button class="link-btn" data-act="note" data-id="${it.id}" data-name="${it.nombre}" data-notes="${it.notas||''}">★ nota</button>
                ${(ORDER_STATE.orden?.tipo||'local')==='local' ? `<button class="link-btn ${it.para_llevar==1?'llevar-on':''}" data-act="toggle-llevar" data-id="${it.id}">🥡 ${it.para_llevar==1?'Para llevar ✓':'Para llevar'}</button>` : ''}
                <button class="link-btn" data-act="del" data-id="${it.id}">🗑 Quitar</button>
              ` : (fully ? '' : `
                <button class="link-btn link-cancel" data-act="cancel-sent" data-id="${it.id}" data-name="${it.nombre}" data-disp="${effQty}">⚠ Cancelar (admin)</button>
              `)}
            </div>
          </div>
          <div class="qty-controls">
            ${it.enviado ? `<span class="qty-num ${fully?'txt-cancel':''}">×${effQty}</span>` : `
              <button class="qty-btn" data-act="dec" data-id="${it.id}">−</button>
              <span class="qty-num">${it.cantidad}</span>
              <button class="qty-btn" data-act="inc" data-id="${it.id}">+</button>
            `}
          </div>`;
        wrap.appendChild(row);
      });
    });

    wrap.querySelectorAll('.qty-btn').forEach(b => {
      b.onclick = async () => {
        await API.post('ordenes/change_qty', { item_id: +b.dataset.id, delta: b.dataset.act === 'inc' ? 1 : -1 });
        await reloadOrder();
      };
    });
    wrap.querySelectorAll('.link-btn').forEach(b => {
      b.onclick = async () => {
        if (b.dataset.act === 'del') {
          await API.post('ordenes/change_qty', { item_id: +b.dataset.id, delta: -999 });
          await reloadOrder();
        } else if (b.dataset.act === 'note') {
          openNotesModal(+b.dataset.id, b.dataset.name, b.dataset.notes);
        } else if (b.dataset.act === 'toggle-llevar') {
          try { await API.post('ordenes/toggle_llevar_item', { item_id: +b.dataset.id }); await reloadOrder(); }
          catch (e) { toast(e.message, 'error'); }
        } else if (b.dataset.act === 'cancel-sent') {
          openCancelSentModal(+b.dataset.id, b.dataset.name, +b.dataset.disp);
        }
      };
    });
  }

  const envio = +o.costo_envio || 0;
  const descuento = +o.descuento || 0;
  const totalConEnvio = (+o.total) + envio;
  const hasIVA = +o.iva > 0.001;
  document.getElementById('sumSubtotal').textContent = fmt(o.subtotal);
  document.getElementById('sumTax').textContent = fmt(o.iva);
  document.getElementById('sumTotal').textContent = fmt(totalConEnvio);
  // Ocultar IVA y subtotal si no hay IVA (precios brutos) — más limpio para el cliente
  document.getElementById('ivaRow').style.display = hasIVA ? '' : 'none';
  document.getElementById('subtotalRow').style.display = (hasIVA || envio > 0 || descuento > 0) ? '' : 'none';
  // Mostrar descuento si hay promo aplicada
  const descRow = document.getElementById('descuentoRow');
  if (descRow) {
    descRow.style.display = descuento > 0 ? '' : 'none';
    document.getElementById('sumDescuento').textContent = '−' + fmt(descuento);
    document.getElementById('descuentoLabel').textContent = o.promocion_id ? '(promo aplicada)' : '';
  }

  const hasNew = o.items.some(i => !i.enviado);
  const hasItems = o.items.length > 0;
  // Mostrador (venta rápida): también manda su comanda-ticket a cocina.
  const sendBtn = document.getElementById('sendKitchen');
  sendBtn.style.display = '';
  sendBtn.disabled = !hasNew;
  const payBtn = document.getElementById('goPay');
  if (payBtn) payBtn.disabled = !hasItems; // el botón no existe para meseros
}

/**
 * Cancelar un item YA ENVIADO a cocina.
 * Si el usuario es admin → un solo modal con motivo.
 * Si NO es admin → primer intento devuelve requires_admin, se muestra modal
 * pidiendo credenciales de admin para autorizar.
 */
function openCancelSentModal(itemId, name, disp) {
  const total = +disp || 1;
  // Cancelación parcial: si hay más de 1, preguntar cuántos cancelar
  let cantidad = total;
  if (total > 1) {
    const resp = prompt(`⚠ Cancelar "${name}"?\n\nHay ${total} sin cancelar. ¿Cuántos quieres cancelar? (1 a ${total})`, String(total));
    if (resp === null) return;
    cantidad = parseInt(resp, 10);
    if (!cantidad || cantidad < 1 || cantidad > total) { toast(`Cantidad inválida (1 a ${total})`, 'error'); return; }
  }
  const motivo = prompt(`Motivo de la cancelación de ${cantidad}× "${name}":\n\n(Queda registrado en bitácora y aparece tachado en el ticket)`);
  if (!motivo || motivo.trim().length < 3) {
    if (motivo !== null) toast('Motivo requerido (mín. 3 caracteres)', 'error');
    return;
  }
  doCancelItem(itemId, motivo.trim(), null, null, cantidad);
}

async function doCancelItem(itemId, motivo, adminUser, adminPass, cantidad) {
  try {
    const data = { item_id: itemId, motivo };
    if (cantidad) data.cantidad = cantidad;
    if (adminUser) data.admin_user = adminUser;
    if (adminPass) data.admin_pass = adminPass;
    await API.post('ordenes/cancel_item', data);
    toast('Platillo cancelado · registrado en bitácora', 'success');
    await reloadOrder();
  } catch (e) {
    // Si requiere autorización admin, abrir modal específico
    if ((e.message || '').includes('autorización del administrador')
        || (e.message || '').includes('credenciales')) {
      openAdminAuthModal(motivo, (user, pass) => doCancelItem(itemId, motivo, user, pass, cantidad));
    } else {
      toast(e.message, 'error');
    }
  }
}

/**
 * Modal genérico para pedir credenciales de un admin que autorice una acción.
 */
function openAdminAuthModal(motivo, callback) {
  const html = `
    <div class="modal-bg open" id="adminAuthModal" style="z-index:200;">
      <div class="modal" style="max-width:400px;">
        <h3 style="color:var(--red);">🔐 Autorización requerida</h3>
        <div class="sub">Esta acción solo puede ser autorizada por un administrador.</div>
        <div style="background:rgba(232,90,79,.1);border-left:3px solid var(--red);padding:8px 12px;border-radius:6px;margin:12px 0;font-size:13px;">
          <b>Motivo:</b> ${motivo}
        </div>
        <label class="inv-label">Usuario admin</label>
        <input class="inv-input" id="adminAuthUser" autocomplete="username">
        <label class="inv-label">Contraseña admin</label>
        <input type="password" class="inv-input" id="adminAuthPass" autocomplete="current-password">
        <div class="modal-actions" style="margin-top:14px;">
          <button class="btn btn-ghost" onclick="document.getElementById('adminAuthModal').remove()">Cancelar</button>
          <button class="btn btn-success" id="adminAuthOK">Autorizar y cancelar</button>
        </div>
      </div>
    </div>`;
  const tmp = document.createElement('div');
  tmp.innerHTML = html;
  document.body.appendChild(tmp.firstElementChild);
  setTimeout(() => document.getElementById('adminAuthUser').focus(), 100);
  document.getElementById('adminAuthOK').onclick = () => {
    const user = document.getElementById('adminAuthUser').value.trim();
    const pass = document.getElementById('adminAuthPass').value;
    if (!user || !pass) { toast('Usuario y contraseña requeridos', 'error'); return; }
    document.getElementById('adminAuthModal').remove();
    callback(user, pass);
  };
  // Enter para confirmar
  document.getElementById('adminAuthPass').onkeydown = e => {
    if (e.key === 'Enter') document.getElementById('adminAuthOK').click();
  };
}

function openNotesModal(itemId, name, current) {
  document.getElementById('notesItemName').textContent = name;
  document.getElementById('notesText').value = current || '';
  document.getElementById('notesModal').classList.add('open');
  document.getElementById('notesCancel').onclick = () => document.getElementById('notesModal').classList.remove('open');
  document.getElementById('notesSave').onclick = async () => {
    await API.post('ordenes/update_notes', { item_id: itemId, notas: document.getElementById('notesText').value });
    document.getElementById('notesModal').classList.remove('open');
    await reloadOrder();
  };
}

/* =============== CAMBIAR TIPO DE PEDIDO =============== */
async function openChangeTypeModal() {
  const o = ORDER_STATE.orden;
  if (!o) return;
  const tipoActual = (o.tipo || 'local').toLowerCase();
  const tipoLabel = ({local:'🍽 Local', llevar:'📦 Llevar', domicilio:'🛵 Domicilio'})[tipoActual];

  // Validar restricciones
  if (o.estado === 'cobrada')   { toast('No se puede cambiar una orden ya cobrada', 'error'); return; }
  if (o.estado === 'cancelada') { toast('No se puede cambiar una orden cancelada', 'error'); return; }
  if (tipoActual === 'domicilio' && ['en_camino','entregada'].includes(o.estado_entrega)) {
    toast('No se puede cambiar: el pedido ya está en camino o entregado', 'error');
    return;
  }

  document.getElementById('changeTypeCurrentSub').innerHTML =
    `Tipo actual: <b>${tipoLabel}</b> · Cambiar a otra modalidad`;

  // Pre-llenar campos con datos existentes
  document.getElementById('ctNombre').value = o.cliente_nombre || '';
  document.getElementById('ctTel').value    = o.cliente_telefono || '';
  document.getElementById('ctDir').value    = o.cliente_direccion || '';
  document.getElementById('ctRef').value    = o.cliente_referencias || '';
  document.getElementById('ctEnvio').value  = +o.costo_envio || 30;

  // Cargar repartidores activos
  try {
    const reps = await API.get('delivery/repartidores');
    document.getElementById('ctRepartidor').innerHTML = '<option value="">Sin asignar</option>' +
      reps.map(r => `<option value="${r.id}">${r.nombre} ${r.turno_estado==='en_curso'?'· en turno':''}</option>`).join('');
  } catch (e) {}

  // Cargar mesas LIBRES para opción de cambiar a local
  try {
    const mesas = await API.get('mesas/list');
    const libres = mesas.filter(m => m.estado === 'libre');
    document.getElementById('ctMesa').innerHTML = '<option value="">Selecciona una mesa libre…</option>' +
      libres.map(m => `<option value="${m.id}">${mesaLabel(m, 'M')} · ${m.capacidad}p · ${m.zona}</option>`).join('');
  } catch (e) {}

  // Marcar el tipo actual como deshabilitado
  document.querySelectorAll('.tipo-option').forEach(label => {
    const tipo = label.dataset.tipo;
    const radio = label.querySelector('input');
    radio.checked = false;
    label.classList.remove('selected');
    if (tipo === tipoActual) {
      label.classList.add('disabled');
      radio.disabled = true;
    } else {
      label.classList.remove('disabled');
      radio.disabled = false;
    }
    label.onclick = () => {
      if (radio.disabled) return;
      document.querySelectorAll('.tipo-option').forEach(l => l.classList.remove('selected'));
      label.classList.add('selected');
      radio.checked = true;
      updateChangeTypeFields(tipo);
    };
  });

  // Limpiar selección
  document.getElementById('changeTypeMesaField').style.display = 'none';
  document.getElementById('changeTypeClienteFields').style.display = 'none';
  document.getElementById('changeTypeDomicilioFields').style.display = 'none';
  document.getElementById('ctConfirm').disabled = true;
  document.getElementById('ctConfirm').onclick = confirmChangeType;

  openModal('changeTypeModal');
}

function updateChangeTypeFields(nuevoTipo) {
  const mesa = document.getElementById('changeTypeMesaField');
  const cli  = document.getElementById('changeTypeClienteFields');
  const dom  = document.getElementById('changeTypeDomicilioFields');
  mesa.style.display = (nuevoTipo === 'local')                  ? '' : 'none';
  cli.style.display  = (nuevoTipo === 'llevar' || nuevoTipo === 'domicilio') ? '' : 'none';
  dom.style.display  = (nuevoTipo === 'domicilio')              ? '' : 'none';
  document.getElementById('ctConfirm').disabled = false;
}

async function confirmChangeType() {
  const selected = document.querySelector('.tipo-option.selected');
  if (!selected) { toast('Selecciona un tipo', 'error'); return; }
  const nuevoTipo = selected.dataset.tipo;
  const data = { orden_id: ORDER_STATE.orden.id, tipo: nuevoTipo };

  if (nuevoTipo === 'local') {
    const mesa_id = +document.getElementById('ctMesa').value;
    if (!mesa_id) { toast('Selecciona una mesa', 'error'); return; }
    data.mesa_id = mesa_id;
  } else if (nuevoTipo === 'llevar') {
    data.cliente_nombre   = document.getElementById('ctNombre').value.trim();
    data.cliente_telefono = document.getElementById('ctTel').value.trim();
  } else if (nuevoTipo === 'domicilio') {
    data.cliente_nombre     = document.getElementById('ctNombre').value.trim();
    data.cliente_telefono   = document.getElementById('ctTel').value.trim();
    data.cliente_direccion  = document.getElementById('ctDir').value.trim();
    data.cliente_referencias= document.getElementById('ctRef').value.trim();
    data.costo_envio        = +document.getElementById('ctEnvio').value || 0;
    data.repartidor_id      = +document.getElementById('ctRepartidor').value || null;
  }

  try {
    await API.post('ordenes/cambiar_tipo', data);
    closeModal('changeTypeModal');
    toast(`✓ Pedido cambiado a ${nuevoTipo.toUpperCase()}`, 'success');

    // Si cambió a local con mesa nueva, redirigir a esa mesa.
    if (nuevoTipo === 'local' && data.mesa_id) {
      location.href = `${APP.sysUrl}/index.php?module=orders&mesa=${data.mesa_id}`;
    } else {
      // Cambió a llevar/domicilio: la mesa ya no aplica (se liberó). SIEMPRE
      // recargamos por ORDEN, nunca por la mesa vieja (evita cuenta vacía/bug).
      location.href = `${APP.sysUrl}/index.php?module=orders&orden=${ORDER_STATE.orden.id}`;
    }
  } catch (e) { toast(e.message, 'error'); }
}

/* =============== PROMOCIONES (aplicar al pedido) =============== */
// Nombre distinto del admin.openPromoModal (que es para crear/editar promos)
async function openApplyPromoModal() {
  document.getElementById('promoCodigo').value = '';
  try {
    const promos = await API.get('productos/promociones_activas');
    const list = document.getElementById('promoList');
    if (!promos.length) {
      list.innerHTML = '<em style="color:var(--muted);">No hay promociones activas en este horario.</em>';
    } else {
      list.innerHTML = promos.map(p => {
        const valorTxt = p.tipo === 'porcentaje' ? `${p.valor}% off`
                       : p.tipo === 'monto_fijo'  ? `−${fmt(p.valor)}`
                       : p.tipo === '2x1'         ? '2x1'
                       : p.tipo === '3x100'       ? '3 x $100'
                       : '';
        return `<div class="promo-card" data-id="${p.id}">
          <div class="promo-info">
            <h5>${p.nombre} <span class="promo-val">${valorTxt}</span></h5>
            ${p.descripcion ? `<small>${p.descripcion}</small>` : ''}
            ${p.codigo ? `<small style="color:var(--accent-2);">Código: <code>${p.codigo}</code></small>` : ''}
          </div>
          <button class="btn btn-primary" data-promo-id="${p.id}">Aplicar</button>
        </div>`;
      }).join('');
      list.querySelectorAll('[data-promo-id]').forEach(b => {
        b.onclick = () => aplicarPromocion({ promo_id: +b.dataset.promoId });
      });
    }
  } catch (e) { toast(e.message, 'error'); }

  // ¿Hay promoción aplicada? Mostrar botón de quitar
  const quitarBtn = document.getElementById('promoQuitar');
  if (ORDER_STATE.orden?.promocion_id) {
    quitarBtn.style.display = '';
    quitarBtn.onclick = quitarPromocion;
  } else {
    quitarBtn.style.display = 'none';
  }

  document.getElementById('promoAplicarCodigo').onclick = () => {
    const codigo = document.getElementById('promoCodigo').value.trim().toUpperCase();
    if (!codigo) { toast('Ingresa un código', 'error'); return; }
    aplicarPromocion({ codigo });
  };
  openModal('promoModal');
}

async function aplicarPromocion(params) {
  try {
    const res = await API.post('admin/promo_apply', {
      orden_id: ORDER_STATE.orden.id,
      ...params,
    });
    toast(`✓ ${res.promo} aplicada · descuento ${fmt(res.descuento)}`, 'success');
    closeModal('promoModal');
    await reloadOrder();
  } catch (e) { toast(e.message, 'error'); }
}

async function quitarPromocion() {
  try {
    await API.post('ordenes/quitar_promo', { orden_id: ORDER_STATE.orden.id });
    toast('Promoción quitada', 'info');
    closeModal('promoModal');
    await reloadOrder();
  } catch (e) { toast(e.message, 'error'); }
}

function bindOrderActions() {
  document.getElementById('sendKitchen').onclick = async () => {
    try {
      const sinImprimir = !!document.getElementById('chkSinImprimir')?.checked;
      const res = await API.post('ordenes/send_kitchen', { orden_id: ORDER_STATE.orden.id, sin_imprimir: sinImprimir });
      const impreso = res.impreso || {};
      refreshKitchenBadge();
      // Regreso a Mesas (aplica a TODOS los tipos: local, llevar, domicilio, web).
      const goTables = () => { location.href = `${APP.sysUrl}/index.php?module=tables`; };

      if (sinImprimir) {
        toast('Enviado a cocina (sin imprimir)', 'success');
        goTables();
        return;
      }
      // SIEMPRE mostramos el modal con el estado de impresión de cada cocina y el
      // botón de reimprimir (por si se acabó el rollo o no imprimió). Si todo
      // imprimió, auto-regresa a Mesas; si algo falló, se queda hasta que actúen.
      showComandas(res.comandas, impreso, goTables);
    } catch (e) { toast(e.message, 'error'); }
  };
  const goPayBtn = document.getElementById('goPay');
  if (goPayBtn) goPayBtn.onclick = openSplitCustomerOrPayDirect;
  const splitModeBtn = document.getElementById('splitMode');
  if (splitModeBtn) splitModeBtn.onclick = openSplitCustomerOrPayDirect;
  const btnPromo = document.getElementById('btnPromo');
  if (btnPromo) btnPromo.onclick = openApplyPromoModal;
  const btnCT = document.getElementById('btnChangeType');
  if (btnCT) btnCT.onclick = openChangeTypeModal;
  const btnMv = document.getElementById('btnMoveMesa');
  if (btnMv) btnMv.onclick = openMoveMesaModal;
  const btnFree = document.getElementById('btnFreeMesa');
  if (btnFree) btnFree.onclick = async () => {
    const mesaId = window.CURRENT_MESA_ID || ORDER_STATE.orden?.mesa_id;
    if (!mesaId) { toast('No hay mesa que liberar', 'error'); return; }
    if (!confirm('¿Liberar esta mesa? Quedará libre porque no tiene productos.')) return;
    try {
      await API.post('ordenes/liberar_mesa', { mesa_id: mesaId });
      toast('Mesa liberada', 'success');
      location.href = `${APP.sysUrl}/index.php?module=tables`;
    } catch (e) { toast(e.message, 'error'); }
  };
  document.getElementById('comandaClose').onclick = () => document.getElementById('comandaModal').classList.remove('open');
}

/**
 * Mover toda la cuenta a otra mesa libre (interior → terraza, etc.).
 */
async function openMoveMesaModal() {
  const o = ORDER_STATE.orden;
  const sel = document.getElementById('moveMesaSelect');
  sel.innerHTML = '<option value="">Cargando mesas…</option>';
  document.getElementById('moveMesaModal').classList.add('open');
  document.getElementById('moveMesaCancel').onclick = () => document.getElementById('moveMesaModal').classList.remove('open');

  let mesas = [];
  try { mesas = await API.get('mesas/list'); } catch (e) { toast(e.message, 'error'); }
  const libres = mesas.filter(m => m.estado === 'libre' && m.id !== o.mesa_id);
  if (!libres.length) {
    sel.innerHTML = '<option value="">No hay mesas libres ahora mismo</option>';
  } else {
    sel.innerHTML = '<option value="">Selecciona una mesa libre…</option>' +
      libres.map(m => `<option value="${m.id}">${mesaLabel(m)} · ${m.zona || ''} (${m.capacidad}p)</option>`).join('');
  }

  document.getElementById('moveMesaConfirm').onclick = async () => {
    const destino = +sel.value;
    if (!destino) { toast('Selecciona la mesa destino', 'error'); return; }
    try {
      const res = await API.post('ordenes/mover_mesa', { orden_id: o.id, mesa_id: destino });
      toast('Cuenta movida a la nueva mesa', 'success');
      // Recargar por orden_id (la mesa cambió) para no crear una orden nueva
      location.href = `${APP.sysUrl}/index.php?module=orders&orden=${res.orden_id}`;
    } catch (e) { toast(e.message, 'error'); }
  };
}

/**
 * Nombre visible de una cocina: 1 → "Cocina A", 2 → "Cocina B".
 * (Internamente el sistema sigue usando el número 1/2 como clave.)
 */
function cocinaNom(n) { return (+n === 2) ? 'Cocina B' : 'Cocina A'; }
function cocinaLetra(n) { return (+n === 2) ? 'B' : 'A'; }

/**
 * Etiqueta visible de una mesa: nombre personalizado ("Terraza 1") si lo tiene,
 * o "Mesa 05" (número con cero) si no.
 * @param {object} m  objeto con .nombre y .numero
 * @param {string} pref  prefijo cuando no hay nombre (default "Mesa ")
 */
function mesaLabel(m, pref = 'Mesa ') {
  if (!m) return '';
  const nom = (m.nombre || '').trim();
  if (nom) return nom;
  const num = m.numero ?? m.mesa_numero;
  return (num != null && num !== '') ? pref + String(num).padStart(2, '0') : '';
}

/**
 * Construye el HTML de UNA comanda
 * @param {object} c    objeto comanda con .id, .cocina, .mesa, .items, .created
 */
function buildComandaHtml(c) {
  const m = c.mesa;
  const grupos = {};
  c.items.forEach(it => { (grupos[it.comensal||1] = grupos[it.comensal||1] || []).push(it); });
  const itemsHtml = Object.keys(grupos).sort((a,b)=>+a-+b).map(comensal => `
    <div class="ctr"><b>—— Comensal ${comensal} ——</b></div>
    ${grupos[comensal].map(i => {
      const mods = i.modificadores_json ? JSON.parse(i.modificadores_json) : [];
      const tagCocina2 = (c.cocina === 1 && i.cocina_2 == 1) ? ' <small>[→ Cocina B]</small>' : '';
      return `
        <div class="li big"><span>${i.cantidad}× ${i.nombre}${tagCocina2}</span></div>
        ${mods.length ? `<div style="padding-left:8px;font-size:11px;">${mods.map(mm => `→ ${mm.nombre}${+mm.precio_extra>0?' (+$'+(+mm.precio_extra).toFixed(2)+')':''}`).join('<br>')}</div>` : ''}
        ${i.notas ? `<div style="padding-left:8px;font-style:italic;">★ ${i.notas}</div>` : ''}
      `;
    }).join('')}
  `).join('<hr>');

  const titulo = 'COMANDA · ' + cocinaNom(c.cocina).toUpperCase();
  return `
    <div class="ctr"><h2>JACKS TACO ROCK</h2></div>
    <div class="ctr">*** ${titulo} ***</div>
    <hr>
    <div class="li"><b>Ticket:</b><span>#${String(c.id).padStart(3,'0')}</span></div>
    ${m.numero
      ? `<div class="li"><b>Mesa:</b><span>${mesaLabel(m)} (${m.capacidad}p)</span></div>`
      : `<div class="li"><b>Tipo:</b><span>${m.zona || 'PARA LLEVAR'}</span></div>
         ${m.descripcion ? `<div class="li"><b>Cliente:</b><span>${m.descripcion}</span></div>` : ''}`}
    <div class="li"><b>Hora:</b><span>${c.created}</span></div>
    <div class="li"><b>Mesero:</b><span>${APP.user?.nombre || '--'}</span></div>
    <hr>
    ${itemsHtml}
    <hr>
    <div class="ctr">${c.cocina === 2 ? '¡A PREPARAR EN COCINA B!' : '¡A PREPARAR EN COCINA A!'}</div>`;
}

/**
 * Muestra una o varias comandas en el modal como CONFIRMACIÓN. La impresión
 * ya la hizo el servidor automáticamente a la impresora de cada cocina; aquí
 * solo mostramos el estado y un botón para REIMPRIMIR si hizo falta (papel
 * atascado, cocina que estaba apagada, etc.).
 *
 * @param {object} impreso  mapa {cocina: true|false} devuelto por el servidor
 */
function showComandas(comandas, impreso = {}, onDone = null) {
  const preview = document.getElementById('comandaPreview');
  const subInfo = document.getElementById('comandaSub');

  const algunFallo = comandas.some(c => impreso[c.cocina] === false);
  subInfo.innerHTML = algunFallo
    ? '⚠ <b>Una comanda NO se imprimió</b> — reimprímela antes de continuar (impresora apagada, sin red o sin rollo).'
    : '✓ <b>Impreso.</b> Verifica que salieron completas; si se acabó el rollo o salió a medias, usa “Reimprimir”.';

  preview.innerHTML = comandas.map((c, idx) => {
    const ok = impreso[c.cocina] !== false;
    const estado = ok
      ? '<span class="printed-tag">✓ Impreso automáticamente</span>'
      : '<span class="printed-tag" style="background:var(--red);color:#fff;">✗ No imprimió — reintenta</span>';
    return `
    <div class="comanda-block cocina-${c.cocina}">
      <div class="comanda-block-head">
        <div>
          <span class="cocina-tag cocina-${c.cocina}">${c.cocina === 2 ? '🍳' : '🔥'} ${cocinaNom(c.cocina)}</span>
          <small style="display:block;color:var(--muted);margin-top:4px;">${c.items.length} item${c.items.length>1?'s':''}</small>
        </div>
        <button class="btn ${ok ? 'btn-ghost' : 'btn-primary'} print-comanda-btn" data-idx="${idx}">
          ↻ Reimprimir ${cocinaNom(c.cocina)}
        </button>
      </div>
      <div class="comanda-inline-wrap">
        <div class="comanda">${buildComandaHtml(c)}</div>
      </div>
      <div class="comanda-status" id="comanda-status-${idx}">${estado}</div>
    </div>`;
  }).join('');

  // El botón REIMPRIME por el servidor a la impresora térmica (no el navegador)
  preview.querySelectorAll('.print-comanda-btn').forEach(btn => {
    btn.onclick = async () => {
      const idx = +btn.dataset.idx;
      const c = comandas[idx];
      const status = document.getElementById(`comanda-status-${idx}`);
      btn.disabled = true;
      try {
        const r = await API.post('ordenes/reprint_comanda', { comanda_id: c.id });
        if (r.impreso) {
          if (status) status.innerHTML = '<span class="printed-tag">✓ Reimpreso en ' + cocinaNom(c.cocina) + '</span>';
          toast('Comanda reenviada a ' + cocinaNom(c.cocina), 'success');
        } else {
          if (status) status.innerHTML = '<span class="printed-tag" style="background:var(--red);color:#fff;">✗ Impresora de ' + cocinaNom(c.cocina) + ' no responde</span>';
          toast('La impresora de ' + cocinaNom(c.cocina) + ' no responde', 'error');
        }
      } catch (e) { toast(e.message, 'error'); }
      btn.disabled = false;
    };
  });

  // Botón "Volver a Mesas": solo cuando venimos de enviar a cocina (onDone).
  const goBtn = document.getElementById('comandaGoTables');
  let countdownTimer = null;
  const stopCountdown = () => { if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; } };
  const finish = () => { stopCountdown(); document.getElementById('comandaModal').classList.remove('open'); if (onDone) onDone(); };

  if (goBtn) {
    if (onDone) {
      goBtn.style.display = '';
      goBtn.onclick = finish;
      if (!algunFallo) {
        // Todo imprimió → auto-regreso a Mesas con cuenta regresiva (cancelable).
        let secs = 8;
        goBtn.textContent = `✓ Todo bien · Volver a Mesas (${secs})`;
        countdownTimer = setInterval(() => {
          secs--;
          if (secs <= 0) { finish(); return; }
          goBtn.textContent = `✓ Todo bien · Volver a Mesas (${secs})`;
        }, 1000);
      } else {
        goBtn.textContent = '✓ Ya reimprimí · Volver a Mesas';
      }
    } else {
      goBtn.style.display = 'none';
    }
  }
  // Cualquier clic en "Reimprimir" cancela el auto-regreso (para revisar con calma).
  preview.querySelectorAll('.print-comanda-btn').forEach(b => b.addEventListener('click', stopCountdown));

  // Bind del cierre aquí mismo para que funcione también fuera de la página de
  // pedidos (p. ej. al aceptar un pedido web desde el módulo de Pedidos web).
  const closeBtn = document.getElementById('comandaClose');
  if (closeBtn) closeBtn.onclick = () => { stopCountdown(); document.getElementById('comandaModal').classList.remove('open'); };

  document.getElementById('comandaModal').classList.add('open');
}

/* =============== SPLIT POR CLIENTE =============== */
async function openSplitCustomerOrPayDirect() {
  const o = ORDER_STATE.orden;
  if (!o.items.length) return;
  const comensales = [...new Set(o.items.map(i => i.comensal))];
  if (comensales.length === 1) {
    // No hay split, ir directo al cobro
    return openPayModal(o.total, o.subtotal, o.iva, o.items, null);
  }
  // Mostrar modal de split — SOLO informativo (desglose por comensal).
  // El grupo paga junto y se imprime UN solo ticket con todo (#2).
  const grupos = await API.get('ordenes/split_by_customer', { orden_id: o.id });
  const granTotal = grupos.reduce((s, g) => s + (+g.total), 0);
  const wrap = document.getElementById('splitCustomerList');
  wrap.innerHTML = grupos.map(g => `
    <div class="split-customer-card">
      <div class="split-customer-head">
        <h4>Comensal ${g.comensal}</h4>
        <div class="amount">${fmt(g.total)}</div>
      </div>
      <div class="split-customer-items">
        ${g.items.map(i => `<div class="li"><span>${i.cantidad}× ${i.nombre}</span><span>${fmt(i.total)}</span></div>`).join('')}
      </div>
    </div>`).join('') + `
    <div class="split-grandtotal">Total de todo: <b>${fmt(granTotal)}</b></div>
    <div class="split-hint">💡 El ticket sale en una sola hoja con la cuenta de cada comensal y el total al final.</div>`;

  document.getElementById('splitCustomerCancel').onclick = () =>
    document.getElementById('splitCustomerModal').classList.remove('open');
  const payAllBtn = document.getElementById('splitPayAll');
  payAllBtn.textContent = '💵 Cobrar todo (un solo ticket)';
  payAllBtn.onclick = () => {
    document.getElementById('splitCustomerModal').classList.remove('open');
    openPayModal(o.total, o.subtotal, o.iva, o.items, null);
  };
  document.getElementById('splitCustomerModal').classList.add('open');
}

/* =============== COBRO (split por método) =============== */
async function openPayModal(total, subtotal, iva, items, etiqueta) {
  if (!METHODS.length) METHODS = await API.get('reportes/metodos_pago');
  PAY_GOODS_TOTAL = +total;            // total de la mercancía (antes de descuento)
  PAY_DISCOUNT = { monto: 0, motivo: '' };
  CURRENT_PAY_TOTAL = +total;
  document.getElementById('paySub').textContent = fmt(subtotal);
  document.getElementById('payTax').textContent = fmt(iva);
  document.getElementById('payTotal').textContent = fmt(total);
  document.getElementById('payModalSub').textContent = etiqueta
    ? `${etiqueta} · ${items.length} items`
    : `Orden completa · ${items.length} platillos`;
  SPLIT = [{ metodo: 'cash', monto: Number(total).toFixed(2) }];
  renderSplit();
  setupDiscountUI();
  document.getElementById('payModal').classList.add('open');

  document.getElementById('payCancel').onclick = () => document.getElementById('payModal').classList.remove('open');
  document.getElementById('splitAdd').onclick = () => {
    const paid = SPLIT.reduce((s, p) => s + (+p.monto || 0), 0);
    const remaining = Math.max(0, CURRENT_PAY_TOTAL - paid);
    SPLIT.push({ metodo: 'card', monto: remaining.toFixed(2) });
    renderSplit();
  };
  document.getElementById('payConfirm').onclick = () => confirmPay(items);
}

function renderSplit() {
  const wrap = document.getElementById('splitList');
  wrap.innerHTML = '';
  SPLIT.forEach((p, idx) => {
    const row = document.createElement('div');
    row.className = 'split-row';
    row.innerHTML = `
      <select data-idx="${idx}" data-k="metodo">
        ${METHODS.map(m => `<option value="${m.codigo}" ${m.codigo===p.metodo?'selected':''}>${m.icono} ${m.nombre}</option>`).join('')}
      </select>
      <input type="number" min="0" step="0.01" value="${p.monto}" data-idx="${idx}" data-k="monto" />
      <button class="rm" data-idx="${idx}">×</button>`;
    wrap.appendChild(row);
  });
  wrap.querySelectorAll('select, input').forEach(el => {
    el.oninput = el.onchange = e => {
      const { idx, k } = e.target.dataset;
      SPLIT[idx][k] = (k === 'monto') ? (+e.target.value || 0) : e.target.value;
      updateDiff();
    };
  });
  wrap.querySelectorAll('.rm').forEach(b => {
    b.onclick = () => { SPLIT.splice(+b.dataset.idx, 1); renderSplit(); };
  });
  updateDiff();
}

function updateDiff() {
  const paid = SPLIT.reduce((s, p) => s + (+p.monto || 0), 0);
  const diff = +(CURRENT_PAY_TOTAL - paid).toFixed(2);
  document.getElementById('payPaid').textContent = fmt(paid);
  const row = document.getElementById('payDiffRow');
  const lbl = document.getElementById('payDiffLbl');
  const val = document.getElementById('payDiff');
  if (diff > 0.001) { lbl.textContent = 'Falta'; val.textContent = fmt(diff); row.classList.remove('zero'); document.getElementById('payConfirm').disabled = true; }
  else if (diff < -0.001) { lbl.textContent = 'Cambio'; val.textContent = fmt(-diff); row.classList.remove('zero'); document.getElementById('payConfirm').disabled = false; }
  else { lbl.textContent = 'Diferencia'; val.textContent = fmt(0); row.classList.add('zero'); document.getElementById('payConfirm').disabled = false; }
}

/**
 * Configura la sección de descuento del modal de cobro.
 * Solo visible para admin y cajero.
 */
function setupDiscountUI() {
  const box = document.getElementById('payDiscountBox');
  if (!box) return;
  const rol = (APP.user && APP.user.rol) || '';
  const permitido = rol === 'admin' || rol === 'cajero';
  box.style.display = permitido ? '' : 'none';
  if (!permitido) return;

  const toggle = document.getElementById('payDiscountToggle');
  const fields = document.getElementById('payDiscountFields');
  const monto  = document.getElementById('payDescMonto');
  const motivo = document.getElementById('payDescMotivo');
  // Reset visual
  fields.style.display = 'none';
  monto.value = '';
  motivo.value = '';

  toggle.onclick = () => {
    const abrir = fields.style.display === 'none';
    fields.style.display = abrir ? '' : 'none';
    if (abrir) monto.focus();
  };
  monto.oninput = motivo.oninput = applyDiscount;
  document.getElementById('payDescClear').onclick = () => {
    monto.value = ''; motivo.value = ''; applyDiscount();
  };
}

/**
 * Recalcula el total a pagar aplicando el descuento capturado.
 */
function applyDiscount() {
  let desc = +(document.getElementById('payDescMonto').value) || 0;
  const motivo = (document.getElementById('payDescMotivo').value || '').trim();
  // No puede superar el total de la mercancía
  desc = Math.max(0, Math.min(desc, PAY_GOODS_TOTAL));
  PAY_DISCOUNT = { monto: desc, motivo };
  CURRENT_PAY_TOTAL = +(PAY_GOODS_TOTAL - desc).toFixed(2);

  const row = document.getElementById('payDescRow');
  document.getElementById('payDescShow').textContent = '-' + fmt(desc);
  row.style.display = desc > 0 ? '' : 'none';
  document.getElementById('payTotal').textContent = fmt(CURRENT_PAY_TOTAL);

  // Reajustar el primer método de pago al nuevo total (comodidad)
  if (SPLIT.length === 1) SPLIT[0].monto = CURRENT_PAY_TOTAL.toFixed(2);
  renderSplit();
}

async function confirmPay(items) {
  try {
    // Validar motivo si hay descuento
    if (PAY_DISCOUNT.monto > 0 && !PAY_DISCOUNT.motivo) {
      toast('Indica el motivo del descuento', 'error');
      document.getElementById('payDescMotivo').focus();
      return;
    }
    // Para el MVP cobramos la orden completa al confirmar (incluso si vino desde split por comensal).
    // En una versión siguiente puedes registrar pagos parciales por comensal y cerrar cuando todo esté pagado.
    const sinTicket = !!document.getElementById('chkSinTicket')?.checked;
    const res = await API.post('ordenes/pay', {
      orden_id: ORDER_STATE.orden.id,
      pagos: SPLIT.map(p => ({ metodo: p.metodo, monto: +p.monto || 0 })),
      descuento: PAY_DISCOUNT.monto || 0,
      descuento_motivo: PAY_DISCOUNT.motivo || '',
      sin_ticket: sinTicket
    });
    document.getElementById('payModal').classList.remove('open');
    const t = res.ticket || {};
    // Con "sin ticket" no hay impresión que validar → volver directo a Mesas.
    if (res.sin_ticket) { location.href = `${APP.sysUrl}/index.php?module=tables`; return; }
    // El ticket ya se imprimió solo en caja. En el caso normal NO mostramos
    // modal: volvemos directo a Mesas. Solo si el ticket NO salió mostramos el
    // modal para poder reimprimirlo.
    if (t.impreso === false) {
      showTicket(t);
      toast('Pago confirmado, pero el ticket no se imprimió — usa “Reimprimir”', 'error');
    } else {
      location.href = `${APP.sysUrl}/index.php?module=tables`;
    }
  } catch (e) { toast(e.message, 'error'); }
}

function showTicket(t) {
  const html = buildTicketHtml(t);
  document.getElementById('ticketPreview').innerHTML = html;
  document.getElementById('printArea').innerHTML = `<div class="comanda">${html}</div>`;
  document.getElementById('ticketModal').classList.add('open');

  // Estado de impresión automática (el servidor ya lo mandó a la caja)
  const sub = document.querySelector('#ticketModal .sub');
  if (sub) {
    sub.innerHTML = (t.impreso === false)
      ? '⚠ La impresora de caja no respondió · usa “Reimprimir”'
      : '✓ Ticket impreso automáticamente en caja';
  }

  document.getElementById('ticketClose').onclick = () => location.href = `${APP.sysUrl}/index.php?module=tables`;
  // "Imprimir" ahora REIMPRIME por el servidor a la impresora térmica (no navegador)
  const printBtn = document.getElementById('ticketPrint');
  printBtn.textContent = '↻ Reimprimir ticket';
  printBtn.onclick = async () => {
    printBtn.disabled = true;
    try {
      const r = await API.post('ordenes/reprint_ticket', { orden_id: t.orden_id });
      toast(r.impreso ? 'Ticket reenviado a caja' : 'La impresora de caja no responde', r.impreso ? 'success' : 'error');
    } catch (e) { toast(e.message, 'error'); }
    printBtn.disabled = false;
  };
}

/**
 * Construye el HTML del ticket para impresora térmica 80mm.
 * - Incluye logo del negocio
 * - Tipografía monoespaciada (Courier) para impresión nítida
 * - Oculta IVA cuando es 0
 * - Suma extras al precio total por línea
 */
function buildTicketHtml(t) {
  const nombre   = window.APP_CFG?.negocio_nombre   || 'JACKS ROCK';
  const eslogan  = window.APP_CFG?.negocio_eslogan  || '';
  const horario  = window.APP_CFG?.negocio_horario  || '';
  const telefono = window.APP_CFG?.negocio_telefono || '';
  const pie      = window.APP_CFG?.ticket_pie || '¡GRACIAS POR TU COMPRA · DIOS TE BENDIGA!';

  return `
    <div class="ctr ticket-logo">
      <img src="${APP.url}/assets/img/logo-ticket.png?v=${Date.now()}" alt=""
           onerror="this.onerror=null; this.src='${APP.url}/assets/img/logo.png'; this.style.filter='grayscale(1) contrast(1.4)';">
    </div>
    <div class="ctr"><h2>${nombre}</h2></div>
    ${eslogan  ? `<div class="ctr small">${eslogan}</div>` : ''}
    ${horario  ? `<div class="ctr small">${horario}</div>` : ''}
    ${telefono ? `<div class="ctr small">Tel ${telefono}</div>` : ''}
    <hr>
    <div class="li"><b>Ticket:</b><span>V-${String(t.orden_id).padStart(4,'0')}</span></div>
    <div class="li"><b>Fecha:</b><span>${t.fecha||''}</span></div>
    <div class="li"><b>Atendió:</b><span>${APP.user?.nombre || '--'}</span></div>
    ${(t.mesa_etiqueta || t.mesa_numero) ? `<div class="li"><b>Mesa:</b><span>${t.mesa_etiqueta || String(t.mesa_numero).padStart(2,'0')}</span></div>` : ''}
    ${t.cliente_nombre ? `<div class="li"><b>Cliente:</b><span>${t.cliente_nombre}</span></div>` : ''}
    <hr>
    ${(() => {
      const items = t.items || [];
      const renderItem = (i) => {
        const extras  = +i.precio_extra || 0;
        const cancQty = +i.cantidad_cancelada || 0;
        const effQty  = Math.max(0, i.cantidad - cancQty);
        const totalLinea = (+i.precio + extras) * effQty;
        let h = '';
        if (effQty > 0) {
          h += `<div class="li"><span>${effQty}× ${i.nombre}</span><span>${fmt(totalLinea)}</span></div>`;
          if (extras > 0) h += `<div class="ticket-extras">+ extras ${fmt(extras)} c/u</div>`;
          if (i.notas)    h += `<div class="ticket-extras">→ ${i.notas}</div>`;
        }
        if (cancQty > 0) {
          h += `<div class="li ti-cancel"><span><s>${cancQty}× ${i.nombre}</s></span><span><s>CANCELADO</s></span></div>`;
          if (i.motivo_cancel) h += `<div class="ticket-extras ti-cancel">✗ ${i.motivo_cancel}</div>`;
        }
        return h;
      };
      const lineTot = (i) => (+i.precio + (+i.precio_extra || 0)) * Math.max(0, i.cantidad - (+i.cantidad_cancelada || 0));

      // #2 — Cuenta dividida = más de un comensal. Se imprime UN solo ticket
      // con la cuenta de cada comensal y, al final, el total de todo.
      const comensales = [...new Set(items.map(i => +i.comensal || 1))];
      if (comensales.length <= 1) return items.map(renderItem).join('');

      return comensales.sort((a, b) => a - b).map(c => {
        const sub = items.filter(i => (+i.comensal || 1) === c);
        const subTot = sub.reduce((s, i) => s + lineTot(i), 0);
        return `<div class="ctr split-head"><b>—— Comensal ${c} ——</b></div>
          ${sub.map(renderItem).join('')}
          <div class="li split-sub"><span>Subtotal comensal ${c}</span><span>${fmt(subTot)}</span></div>`;
      }).join('<hr>');
    })()}
    <hr>
    ${(+t.iva > 0.001) ? `
      <div class="li"><span>Subtotal</span><span>${fmt(t.subtotal)}</span></div>
      <div class="li"><span>IVA</span><span>${fmt(t.iva)}</span></div>` : ''}
    ${(+t.descuento > 0) ? `<div class="li"><span>Descuento${t.descuento_motivo ? ' ('+t.descuento_motivo+')' : ''}</span><span>-${fmt(t.descuento)}</span></div>` : ''}
    ${(+t.costo_envio > 0) ? `<div class="li"><span>Envío</span><span>${fmt(t.costo_envio)}</span></div>` : ''}
    ${(+t.propina > 0) ? `<div class="li"><span>Propina</span><span>${fmt(t.propina)}</span></div>` : ''}
    <div class="li big"><span>TOTAL</span><span>${fmt(t.total)}</span></div>
    ${(() => {
      // Propina SUGERIDA (no se suma al total) — solo orientativa para el cliente
      const base = +t.subtotal || Math.max(0, (+t.total || 0) - (+t.costo_envio || 0) - (+t.propina || 0));
      const pct  = +(window.APP_CFG?.propina_sugerida_pct ?? 10);
      if (!(base > 0) || !(pct > 0)) return '';
      const sug = base * pct / 100;
      return `
        <div class="li propina-sug"><span>Propina sugerida ${pct}%</span><span>${fmt(sug)}</span></div>
        <div class="ctr small propina-sug-note">* Opcional · NO incluida en el total</div>`;
    })()}
    <hr>
    <div class="ctr"><b>Formas de pago</b></div>
    ${(t.pagos||[]).map(p => {
      const m = (METHODS||[]).find(x => x.codigo === p.metodo) || { nombre: p.metodo || '?' };
      return `<div class="li"><span>${m.icono||''} ${m.nombre}</span><span>${fmt(p.monto)}</span></div>`;
    }).join('')}
    ${(+t.cambio > 0.001) ? `<div class="li"><span>Cambio</span><span>${fmt(t.cambio)}</span></div>` : ''}
    <hr>
    <div class="ctr">${pie.replace(/·/g, '<br>')}</div>`;
}

/**
 * Devuelve los botones de acción de una comanda según el rol del usuario actual.
 * cocina/admin   → "▶ Iniciar" + "✓ Lista" (los que cocinan)
 * mesero/cajero  → "📤 Servida" (cuando recogen el plato y lo llevan a la mesa)
 * repartidor     → "📤 Recogida" cuando es para domicilio
 * otros          → ninguna acción (solo ven)
 */
function kitchenButtonsByRole(c) {
  const rol = APP.user?.rol || '';
  const btns = [];
  if (c.estado === 'nueva' && ['cocina','admin'].includes(rol)) {
    btns.push(`<button class="btn btn-primary" data-act="preparando" data-id="${c.id}">▶ Iniciar</button>`);
  }
  if (c.estado === 'preparando' && ['cocina','admin'].includes(rol)) {
    btns.push(`<button class="btn btn-success" data-act="lista" data-id="${c.id}">✓ Lista</button>`);
  }
  if (c.estado === 'lista' && ['cocina','admin','cajero','mesero','repartidor'].includes(rol)) {
    const label = rol === 'repartidor' ? '📤 Recogida' : '📤 Servida';
    btns.push(`<button class="btn btn-ghost" data-act="servida" data-id="${c.id}">${label}</button>`);
  }
  if (!btns.length) {
    btns.push(`<small style="color:var(--muted);font-size:11px;">Solo vista</small>`);
  }
  return btns.join('');
}

/* =============== COCINA con SSE =============== */
async function initKitchen() {
  // setup filtro
  document.querySelectorAll('.kf-btn').forEach(b => {
    if (+b.dataset.cocina === KITCHEN_FILTER) b.classList.add('active');
    else b.classList.remove('active');
    b.onclick = () => {
      KITCHEN_FILTER = +b.dataset.cocina;
      localStorage.setItem('kitchenFilter', KITCHEN_FILTER);
      document.querySelectorAll('.kf-btn').forEach(x => x.classList.toggle('active', +x.dataset.cocina === KITCHEN_FILTER));
      renderKitchen();
    };
  });
  setupAvisoCocina();
  await renderKitchen();
  await renderAvisosCocina();

  // Server-Sent Events para refresco en tiempo real
  try {
    const es = new EventSource(`${APP.url}/api/sse.php`);
    es.addEventListener('kitchen-update', async () => {
      await renderKitchen();
      await renderAvisosCocina();
      refreshKitchenBadge();
    });
    es.onerror = () => { /* navegador reconectará */ };
  } catch (e) {
    setInterval(() => { renderKitchen(); renderAvisosCocina(); }, 5000);
  }
}

/**
 * Configura el botón y modal para enviar avisos sueltos a una cocina
 * (recordatorios tipo "freír bolsas de papas").
 */
function setupAvisoCocina() {
  const btn = document.getElementById('btnAvisoCocina');
  if (!btn) return;
  const msgInput = document.getElementById('avisoMsg');

  btn.onclick = () => {
    msgInput.value = '';
    openModal('avisoModal');
  };
  // Chips de avisos rápidos → rellenan el mensaje
  document.querySelectorAll('#avisoPresets .aviso-chip').forEach(chip => {
    chip.onclick = () => { msgInput.value = chip.dataset.msg; msgInput.focus(); };
  });
  document.getElementById('avisoSend').onclick = async () => {
    const mensaje = (msgInput.value || '').trim();
    const cocina = +document.getElementById('avisoCocinaSel').value || 2;
    if (!mensaje) { toast('Escribe o elige un aviso', 'error'); return; }
    try {
      const r = await API.post('cocina/aviso_create', { cocina, mensaje });
      closeModal('avisoModal');
      if (r.impreso === false) {
        toast(`Aviso mostrado en ${cocinaNom(cocina)}, pero la impresora no respondió`, 'info');
      } else {
        toast(`Aviso enviado e impreso en ${cocinaNom(cocina)}`, 'success');
      }
      await renderAvisosCocina();
    } catch (e) { toast(e.message, 'error'); }
  };
}

/**
 * Muestra los avisos pendientes como banners arriba de las comandas.
 * Respeta el filtro de estación seleccionado.
 */
async function renderAvisosCocina() {
  const box = document.getElementById('avisosCocina');
  if (!box) return;
  try {
    const params = KITCHEN_FILTER ? { cocina: KITCHEN_FILTER } : {};
    const avisos = await API.get('cocina/avisos', params);
    if (!avisos.length) { box.innerHTML = ''; return; }
    box.innerHTML = avisos.map(a => {
      const elapsed = Math.floor((Date.now() - new Date(a.created_at).getTime()) / 60000);
      const estacion = (a.cocina == 2 ? '🍳 ' : '🔥 ') + cocinaNom(a.cocina);
      return `
        <div class="aviso-banner cocina-${a.cocina}">
          <div class="aviso-txt">
            <span class="aviso-msg">${a.mensaje}</span>
            <small>${estacion} · ${a.usuario || ''} · hace ${elapsed}m</small>
          </div>
          <button class="btn btn-success aviso-done" data-id="${a.id}">✓ Atendido</button>
        </div>`;
    }).join('');
    box.querySelectorAll('.aviso-done').forEach(b => {
      b.onclick = async () => {
        try {
          await API.post('cocina/aviso_done', { id: +b.dataset.id });
          await renderAvisosCocina();
        } catch (e) { toast(e.message, 'error'); }
      };
    });
  } catch (e) { box.innerHTML = ''; }
}

async function renderKitchen() {
  const grid = document.getElementById('kitchenGrid');
  const empty = document.getElementById('kitchenEmpty');
  try {
    const params = KITCHEN_FILTER ? { cocina: KITCHEN_FILTER } : {};
    const data = await API.get('cocina/list', params);
    if (!data.length) { grid.innerHTML = ''; empty.style.display = 'block'; return; }
    empty.style.display = 'none';
    grid.innerHTML = '';
    data.forEach(c => {
      const elapsed = Math.floor((Date.now() - new Date(c.created_at).getTime()) / 60000);
      const grupos = {};
      c.items.forEach(it => { (grupos[it.comensal||1] = grupos[it.comensal||1] || []).push(it); });

      const itemsHtml = Object.keys(grupos).sort((a,b)=>+a-+b).map(com => `
        ${Object.keys(grupos).length > 1 ? `<div class="comensal-mini">Comensal ${com}</div>` : ''}
        ${grupos[com].map(i => {
          const mods = i.modificadores_json ? JSON.parse(i.modificadores_json) : [];
          // En Cocina A, marcar visualmente los items que en realidad prepara Cocina B
          const tagC2 = (c.cocina == 1 && i.cocina_2 == 1) ? `<span class="cocina-tag cocina-2" style="font-size:9px;margin-left:6px;">🍳 B</span>` : '';
          const tagLlevar = (i.para_llevar == 1) ? ` <span class="llevar-tag">🥡 PARA LLEVAR</span>` : '';
          return `
            <div class="li"><span><b>${i.cantidad}×</b>${i.nombre}${tagC2}${tagLlevar}</span></div>
            ${mods.length ? `<div class="ki-mods">${mods.map(m => `→ ${m.nombre}${+m.precio_extra>0?' (+$'+(+m.precio_extra).toFixed(2)+')':''}`).join('<br>')}</div>` : ''}
            ${i.notas ? `<div class="ki-note">★ ${i.notas}</div>` : ''}
          `;
        }).join('')}
      `).join('');

      const cocinaBadge = c.cocina == 2
        ? `<span class="cocina-tag cocina-2">🍳 ${cocinaNom(2)}</span>`
        : `<span class="cocina-tag cocina-1">🔥 ${cocinaNom(1)}</span>`;

      const card = document.createElement('div');
      card.className = 'ticket ' + c.estado + ' cocina-' + c.cocina;
      card.innerHTML = `
        <div class="ticket-head">
          <div>
            <h4>${mesaLabel({ nombre: c.mesa_nombre, numero: c.mesa_numero })} ${cocinaBadge}${(+c.ronda > 1) ? ` <span class="ronda-tag">RONDA ${c.ronda}</span>` : ''}</h4>
            <div class="ticket-status">${c.estado === 'nueva' ? 'Nueva' : c.estado === 'preparando' ? 'En preparación' : 'Lista'}</div>
          </div>
          <div class="time">Orden ${c.orden_id || '#'+String(c.id).padStart(3,'0')}<br>hace ${elapsed}m<br><small>${c.mesero||''}</small></div>
        </div>
        <div class="ticket-items">${itemsHtml}</div>
        <div class="ticket-actions">
          ${kitchenButtonsByRole(c)}
        </div>`;
      grid.appendChild(card);
    });
    grid.querySelectorAll('button[data-act]').forEach(b => {
      b.onclick = async () => {
        await API.post('cocina/update_status', { id: +b.dataset.id, estado: b.dataset.act });
        if (b.dataset.act === 'lista') toast(`Comanda lista para servir`, 'info');
        await renderKitchen();
        refreshKitchenBadge();
      };
    });
  } catch (e) {
    grid.innerHTML = `<div class="empty-state"><div class="ico">⚠</div>${e.message}</div>`;
  }
}

/* =============== REPORTES con rango =============== */
let REPORTS_DATA = null;
let REPORTS_SORT = 'unidades';

// Hora 0–23 → "7 PM"
function rptHoraLabel(h) {
  const ap = h < 12 ? 'AM' : 'PM';
  const hh = (h % 12) === 0 ? 12 : (h % 12);
  return `${hh} ${ap}`;
}

function renderReportsAll() {
  const d = REPORTS_DATA;
  if (!d) return;
  const prods = (d.productos || []).slice().sort((a, b) =>
    REPORTS_SORT === 'ingresos' ? b.ingresos - a.ingresos : b.unidades - a.unidades);
  const totalU = d.unidades || prods.reduce((s, p) => s + p.unidades, 0) || 1;
  const body = document.getElementById('rptAllBody');
  body.innerHTML = prods.length
    ? prods.map((p, i) => `<tr>
        <td>${i + 1}</td>
        <td><b>${p.nombre}</b></td>
        <td><small>${p.categoria}</small></td>
        <td>${p.unidades}</td>
        <td>${(p.unidades / totalU * 100).toFixed(1)}%</td>
        <td><b>${fmt(p.ingresos)}</b></td>
      </tr>`).join('')
    : '<tr><td colspan="6"><em>Sin ventas en el rango.</em></td></tr>';
}

async function initReports() {
  const apply = async () => {
    const desde = document.getElementById('rptDesde').value;
    const hasta = document.getElementById('rptHasta').value;
    let d;
    try { d = await API.get('reportes/dashboard', { desde, hasta }); }
    catch (e) { toast(e.message, 'error'); return; }
    REPORTS_DATA = d;
    document.getElementById('rptRangeLbl').textContent = `Del ${desde} al ${hasta}`;
    document.getElementById('rptSales').textContent = fmt(d.ventas);
    document.getElementById('rptOrders').textContent = d.ordenes;
    document.getElementById('rptAvg').textContent = fmt(d.promedio);
    document.getElementById('rptUnits').textContent = d.unidades || 0;
    document.getElementById('rptDays').textContent = d.por_dia.length;

    // A qué hora se vende más
    const hourWrap = document.getElementById('rptByHour');
    if (!(d.por_hora || []).length) {
      hourWrap.innerHTML = '<em>Sin datos.</em>';
    } else {
      const maxH = Math.max(...d.por_hora.map(x => x.ventas));
      const pico = d.por_hora.reduce((a, b) => b.ventas > a.ventas ? b : a);
      hourWrap.innerHTML = d.por_hora.map(x => {
        const pct = maxH ? (x.ventas / maxH * 100) : 0;
        const esPico = x.hora === pico.hora;
        return `<div class="rpt-bar-row">
          <span class="rpt-bar-lbl">${rptHoraLabel(x.hora)}${esPico ? ' 🔥' : ''}</span>
          <div class="rpt-bar"><div class="rpt-bar-fill" style="width:${pct}%${esPico ? ';background:var(--green)' : ''}"></div></div>
          <span class="rpt-bar-val"><b>${fmt(x.ventas)}</b> · ${x.ordenes} ord.</span>
        </div>`;
      }).join('');
    }

    // Top 5
    const top = document.getElementById('rptTop');
    top.innerHTML = d.top.length
      ? d.top.map((t, i) => `<div class="rpt-row">
          <span>${['🥇','🥈','🥉','4.','5.'][i] || (i+1)+'.'} ${t.nombre}</span>
          <span><b>${t.unidades}</b> uds · ${fmt(t.ingresos)}</span></div>`).join('')
      : '<em>Sin ventas.</em>';

    // Por categoría (lo que más se mueve)
    const cat = document.getElementById('rptByCat');
    if (!(d.por_categoria || []).length) {
      cat.innerHTML = '<em>Sin datos.</em>';
    } else {
      const maxC = Math.max(...d.por_categoria.map(x => x.ingresos));
      cat.innerHTML = d.por_categoria.map(x => {
        const pct = maxC ? (x.ingresos / maxC * 100) : 0;
        return `<div class="rpt-bar-row">
          <span class="rpt-bar-lbl">${x.categoria}</span>
          <div class="rpt-bar"><div class="rpt-bar-fill" style="width:${pct}%"></div></div>
          <span class="rpt-bar-val"><b>${fmt(x.ingresos)}</b> · ${x.unidades} uds</span>
        </div>`;
      }).join('');
    }

    // Métodos de pago
    const pay = document.getElementById('rptPayments');
    pay.innerHTML = d.metodos.length
      ? d.metodos.map(m => {
          const pct = d.ventas ? (m.total / d.ventas * 100).toFixed(0) : 0;
          return `<div class="rpt-row">
              <span>${m.icono} ${m.nombre} <small>(${m.transacciones} trans.)</small></span>
              <span><b>${fmt(m.total)}</b> · ${pct}%</span></div>`;
        }).join('')
      : '<em>Sin cobros en el rango.</em>';

    // Ventas por día
    const day = document.getElementById('rptByDay');
    if (!d.por_dia.length) {
      day.innerHTML = '<em>Sin datos.</em>';
    } else {
      const max = Math.max(...d.por_dia.map(x => +x.total));
      day.innerHTML = d.por_dia.map(x => {
        const pct = max ? (x.total / max * 100) : 0;
        return `<div class="rpt-bar-row">
          <span class="rpt-bar-lbl">${x.dia}</span>
          <div class="rpt-bar"><div class="rpt-bar-fill" style="width:${pct}%"></div></div>
          <span class="rpt-bar-val"><b>${fmt(x.total)}</b> · ${x.ordenes} ord.</span>
        </div>`;
      }).join('');
    }

    // Todo lo que se vendió (tabla completa)
    renderReportsAll();
  };

  document.getElementById('rptApply').onclick = apply;

  // Orden de la tabla completa
  document.querySelectorAll('[data-rpt-sort]').forEach(b => {
    b.onclick = () => {
      document.querySelectorAll('[data-rpt-sort]').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      REPORTS_SORT = b.dataset.rptSort;
      renderReportsAll();
    };
  });

  // Exportar CSV de todo lo vendido
  const csvBtn = document.getElementById('rptExportCsv');
  if (csvBtn) csvBtn.onclick = () => {
    const d = REPORTS_DATA;
    if (!d || !(d.productos || []).length) { toast('Aplica un rango con ventas primero', 'error'); return; }
    const prods = d.productos.slice().sort((a, b) =>
      REPORTS_SORT === 'ingresos' ? b.ingresos - a.ingresos : b.unidades - a.unidades);
    const rows = [['Producto', 'Categoria', 'Unidades', 'Ingresos']];
    prods.forEach(p => rows.push([p.nombre, p.categoria, p.unidades, p.ingresos]));
    rows.push(['TOTAL', '', d.unidades || '', d.ventas || '']);
    const csv = rows.map(r => r.map(c => {
      const s = String(c ?? '');
      return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    }).join(',')).join('\r\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `ventas_${d.rango.desde}_a_${d.rango.hasta}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    toast('CSV exportado', 'success');
  };

  document.querySelectorAll('.range-presets button').forEach(b => {
    b.onclick = () => {
      const today = new Date();
      const iso = d => localYMD(d);
      let desde = iso(today), hasta = iso(today);
      if (b.dataset.preset === 'yesterday') {
        const y = new Date(today); y.setDate(y.getDate()-1);
        desde = hasta = iso(y);
      } else if (b.dataset.preset === 'week') {
        const w = new Date(today); w.setDate(w.getDate()-6);
        desde = iso(w);
      } else if (b.dataset.preset === 'month') {
        desde = iso(new Date(today.getFullYear(), today.getMonth(), 1));
      }
      document.getElementById('rptDesde').value = desde;
      document.getElementById('rptHasta').value = hasta;
      apply();
    };
  });
  await apply();
}

/* =============== INVENTARIO =============== */
async function initInventory() {
  // Switch principal: productos terminados / insumos
  document.querySelectorAll('[data-inv-kind]').forEach(b => {
    b.onclick = () => {
      document.querySelectorAll('[data-inv-kind]').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      const kind = b.dataset.invKind;
      document.getElementById('invProductosPane').style.display = kind === 'productos' ? '' : 'none';
      document.getElementById('invInsumosPane').style.display = kind === 'insumos' ? '' : 'none';
      if (kind === 'insumos') loadInsumos(); else loadInv();
    };
  });

  // Filtros productos
  document.querySelectorAll('[data-filter]').forEach(b => {
    b.onclick = () => {
      document.querySelectorAll('[data-filter]').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      INV_FILTER = b.dataset.filter;
      renderInv();
    };
  });
  // Filtros insumos
  document.querySelectorAll('[data-ins-filter]').forEach(b => {
    b.onclick = () => {
      document.querySelectorAll('[data-ins-filter]').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      INS_FILTER = b.dataset.insFilter;
      renderInsumos();
    };
  });
  const newBtn = document.getElementById('insNewBtn');
  if (newBtn) newBtn.onclick = () => openInsumoModal(null);

  await loadInv();
}

async function loadInv() {
  INV_DATA = await API.get('inventario/list');
  document.getElementById('invSub').textContent = `${INV_DATA.length} productos · ${INV_DATA.filter(x=>x.bajo).length} en stock bajo`;
  renderInv();
}

function renderInv() {
  const grid = document.getElementById('invGrid');
  grid.innerHTML = '';
  const filtered = INV_DATA.filter(p => {
    if (INV_FILTER === 'low') return p.bajo;
    if (INV_FILTER === 'out') return p.stock <= 0;
    return true;
  });
  if (!filtered.length) { grid.innerHTML = '<div class="empty-state">Sin productos en este filtro.</div>'; return; }
  filtered.forEach(p => {
    const card = document.createElement('div');
    const status = p.stock <= 0 ? 'out' : (p.bajo ? 'low' : 'ok');
    card.className = `inv-card inv-${status}`;
    card.innerHTML = `
      <div class="inv-head">
        <span class="inv-em">${p.emoji}</span>
        <div>
          <h4>${p.nombre}</h4>
          <div class="inv-cat">${p.categoria}</div>
        </div>
      </div>
      <div class="inv-stock">
        <div class="inv-num">${p.stock} <small>${p.unidad}</small></div>
        <div class="inv-min">Mínimo: ${p.stock_minimo}</div>
      </div>
      <button class="btn btn-primary inv-btn" data-id="${p.id}" data-name="${p.nombre}" data-unidad="${p.unidad||''}">+ Movimiento</button>`;
    grid.appendChild(card);
  });
  grid.querySelectorAll('.inv-btn').forEach(b => {
    b.onclick = () => openMovModal('producto', +b.dataset.id, b.dataset.name, b.dataset.unidad);
  });
}

/**
 * Modal de movimiento de stock, compartido entre productos terminados e insumos.
 * @param {'producto'|'insumo'} kind
 */
function openMovModal(kind, id, name, unidad) {
  document.getElementById('movTitle').textContent = kind === 'insumo' ? 'Movimiento de insumo' : 'Movimiento de stock';
  document.getElementById('movSub').textContent = name;
  document.getElementById('movUnidad').textContent = unidad ? `(en ${unidad})` : '';
  document.getElementById('movTipo').value = 'entrada';
  document.getElementById('movCantidad').value = 1;
  document.getElementById('movMotivo').value = '';
  document.getElementById('movModal').classList.add('open');
  document.getElementById('movCancel').onclick = () => document.getElementById('movModal').classList.remove('open');
  document.getElementById('movSave').onclick = async () => {
    const cantidad = +document.getElementById('movCantidad').value;
    if (!(cantidad > 0)) { toast('Cantidad inválida', 'error'); return; }
    const endpoint = kind === 'insumo' ? 'insumos/movimiento' : 'inventario/movimiento';
    const payload = {
      tipo: document.getElementById('movTipo').value,
      cantidad,
      motivo: document.getElementById('movMotivo').value,
    };
    if (kind === 'insumo') payload.insumo_id = id; else payload.producto_id = id;
    try {
      await API.post(endpoint, payload);
      document.getElementById('movModal').classList.remove('open');
      toast('Movimiento registrado', 'success');
      if (kind === 'insumo') await loadInsumos(); else await loadInv();
    } catch (e) { toast(e.message, 'error'); }
  };
}

/* ---- INSUMOS (materia prima) ---- */
async function loadInsumos() {
  try { INS_DATA = await API.get('insumos/list'); }
  catch (e) { INS_DATA = []; toast(e.message, 'error'); }
  const bajos = INS_DATA.filter(x => x.bajo).length;
  document.getElementById('invSub').textContent = `${INS_DATA.length} insumos · ${bajos} en stock bajo`;
  renderInsumos();
}

function renderInsumos() {
  const grid = document.getElementById('insGrid');
  grid.innerHTML = '';
  const filtered = INS_DATA.filter(p => {
    if (INS_FILTER === 'low') return p.bajo;
    if (INS_FILTER === 'out') return p.stock <= 0;
    return true;
  });
  if (!filtered.length) {
    grid.innerHTML = '<div class="empty-state"><div class="ico">🥬</div>Sin insumos en este filtro. Crea uno con "+ Nuevo insumo".</div>';
    return;
  }
  filtered.forEach(p => {
    const status = p.stock <= 0 ? 'out' : (p.bajo ? 'low' : 'ok');
    const card = document.createElement('div');
    card.className = `inv-card inv-${status}`;
    card.innerHTML = `
      <div class="inv-head">
        <span class="inv-em">🥬</span>
        <div>
          <h4>${p.nombre}</h4>
          <div class="inv-cat">Insumo · ${p.unidad}</div>
        </div>
      </div>
      <div class="inv-stock">
        <div class="inv-num">${(+p.stock).toLocaleString('es-MX')} <small>${p.unidad}</small></div>
        <div class="inv-min">Mínimo: ${(+p.stock_minimo).toLocaleString('es-MX')}</div>
      </div>
      <div style="display:flex;gap:6px;">
        <button class="btn btn-primary ins-mov" data-id="${p.id}" data-name="${p.nombre}" data-unidad="${p.unidad}" style="flex:1;">+ Movimiento</button>
        <button class="btn btn-ghost ins-edit" data-id="${p.id}" title="Editar">✏</button>
        <button class="btn btn-ghost ins-del" data-id="${p.id}" data-name="${p.nombre}" title="Archivar" style="color:var(--red);">🗑</button>
      </div>`;
    grid.appendChild(card);
  });
  grid.querySelectorAll('.ins-mov').forEach(b => {
    b.onclick = () => openMovModal('insumo', +b.dataset.id, b.dataset.name, b.dataset.unidad);
  });
  grid.querySelectorAll('.ins-edit').forEach(b => {
    b.onclick = () => openInsumoModal(INS_DATA.find(x => x.id === +b.dataset.id));
  });
  grid.querySelectorAll('.ins-del').forEach(b => {
    b.onclick = async () => {
      if (!confirm(`¿Archivar el insumo "${b.dataset.name}"? Dejará de aparecer en la lista.`)) return;
      try { await API.post('insumos/eliminar', { id: +b.dataset.id }); toast('Insumo archivado', 'info'); await loadInsumos(); }
      catch (e) { toast(e.message, 'error'); }
    };
  });
}

/** Modal crear/editar insumo. Pasa null para crear. */
function openInsumoModal(insumo) {
  const esNuevo = !insumo;
  document.getElementById('insModalTitle').textContent = esNuevo ? 'Nuevo insumo' : 'Editar insumo';
  document.getElementById('insNombre').value = insumo?.nombre || '';
  document.getElementById('insUnidad').value = insumo?.unidad || 'pieza';
  document.getElementById('insMinimo').value = insumo?.stock_minimo ?? 0;
  // El stock inicial solo se captura al crear (después se mueve con movimientos)
  document.getElementById('insStockInicialWrap').style.display = esNuevo ? '' : 'none';
  document.getElementById('insStockInicial').value = 0;
  document.getElementById('insModal').classList.add('open');
  document.getElementById('insCancel').onclick = () => document.getElementById('insModal').classList.remove('open');
  document.getElementById('insSave').onclick = async () => {
    const nombre = document.getElementById('insNombre').value.trim();
    if (!nombre) { toast('El nombre es obligatorio', 'error'); return; }
    const payload = {
      nombre,
      unidad: document.getElementById('insUnidad').value,
      stock_minimo: +document.getElementById('insMinimo').value || 0,
    };
    if (esNuevo) payload.stock_inicial = +document.getElementById('insStockInicial').value || 0;
    else payload.id = insumo.id;
    try {
      await API.post('insumos/save', payload);
      document.getElementById('insModal').classList.remove('open');
      toast(esNuevo ? 'Insumo creado' : 'Insumo actualizado', 'success');
      await loadInsumos();
    } catch (e) { toast(e.message, 'error'); }
  };
}
