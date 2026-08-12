/**
 * Lógica de los módulos Sistema + Caja + Propinas + Reservaciones
 */

const MOD = document.body.dataset.module;

/*
 * Variables a nivel de módulo (declaradas arriba del IIFE para evitar TDZ).
 */
let USERS = [];
let MESAS = [];
let PRODS = [];
let CATS = [];
let PRINTERS = [];
let MOD_GROUPS = [];
let PROMOS = [];
let AUDIT = [];
let COMBOS = [];
let ALL_PRODS = [];
let ERRORS = [];
let ERR_FILTER = 'all';
let RES = [];
let RES_MESAS = [];
let SHIFTS = [];
let SHIFT_USERS = [];
let CLIENTS = [];
let DELIVERY_ORDERS = [];
let DELIVERY_FILTER = 'activos';
let TAKEOUT_ORDERS = [];
let TAKEOUT_FILTER = 'activos';
// (Pedidos web viven en weborders.js con su propio scope)

// Helpers globales
window.closeModal = id => { const m = document.getElementById(id); if (m) m.classList.remove('open'); };
window.openModal  = id => { const m = document.getElementById(id); if (m) m.classList.add('open'); };

/**
 * Helpers defensivos para setear formularios sin tronar si un campo no existe
 * (por ejemplo si el navegador trae una versión vieja del HTML cacheada).
 */
function setVal(id, v)  { const el = document.getElementById(id); if (el) el.value = v ?? ''; }
function setChk(id, v)  { const el = document.getElementById(id); if (el) el.checked = !!v; }
function setText(id, v) { const el = document.getElementById(id); if (el) el.textContent = v ?? ''; }
function setHTML(id, v) { const el = document.getElementById(id); if (el) el.innerHTML = v ?? ''; }

(async function init() {
  try {
    switch (MOD) {
      case 'sys-users':       return await initSysUsers();
      case 'sys-tables':      return await initSysTables();
      case 'sys-menu':        return await initSysMenu();
      case 'sys-printers':    return await initSysPrinters();
      case 'sys-business':    return await initSysBusiness();
      case 'sys-promotions':  return await initSysPromotions();
      case 'sys-audit':       return await initSysAudit();
      case 'sys-modifiers':   return await initSysModifiers();
      case 'sys-combos':      return await initSysCombos();
      case 'sys-errors':      return await initSysErrors();
      case 'sys-shifts':      return await initSysShifts();
      case 'cash':            return await initCash();
      case 'tips':            return await initTips();
      case 'reservations':    return await initReservations();
      case 'my_shift':        return await initMyShift();
      case 'clients':         return await initClients();
      case 'delivery':        return await initDelivery();
      case 'dashboard':       return await initDashboard();
      case 'closures':        return await initClosures();
      case 'tickets':         return await initTickets();
      case 'takeout':         return await initTakeout();
      case 'web_orders':      return await initWebOrders();
    }
  } catch (e) {
    console.error('Admin module init failed:', e);
    if (typeof toast === 'function') toast('Error al cargar: ' + e.message, 'error');
  }
})();

/* ============ VISOR DE ERRORES ============ */
async function initSysErrors() {
  await loadErrors();
  document.querySelectorAll('[data-filter]').forEach(b => {
    b.onclick = () => {
      document.querySelectorAll('[data-filter]').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      ERR_FILTER = b.dataset.filter;
      renderErrors();
    };
  });
  document.getElementById('errSearch').oninput = renderErrors;
  document.getElementById('errPurge').onclick = async () => {
    if (!confirm('¿Borrar errores de más de 30 días?')) return;
    const r = await API.post('errores/purge', {});
    toast(`${r.deleted} errores antiguos eliminados`);
    loadErrors();
  };
}
// Declaración de función (hoisted) — la llamamos desde initSysErrors antes
// de que se ejecute cualquier asignación a window.* más abajo en el archivo.
async function loadErrors() {
  [ERRORS] = await Promise.all([API.get('errores/list', { limit: 300 })]);
  const stats = await API.get('errores/stats');
  document.getElementById('errSub').textContent = `${ERRORS.length} eventos · ${stats.total_24h} en últimas 24h`;
  document.getElementById('errStats').innerHTML = stats.por_tipo.map(s => `
    <div class="cash-card">
      <div class="lbl">${errTypeIcon(s.tipo)} ${s.tipo}</div>
      <div class="val" style="font-size:18px;">${s.total}</div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;">
        ${s.pendientes} sin resolver · ${s.hoy} hoy
      </div>
    </div>
  `).join('') || '<em style="color:var(--green);">Sin errores · Todo limpio 🎉</em>';
  renderErrors();
}
// Exponer en window para los onclick inline del HTML
window.loadErrors = loadErrors;
function errTypeIcon(t) {
  return ({Fatal:'🔴',Exception:'⚠',Error:'⛔',Warning:'⚠',Notice:'ℹ',Deprecated:'📋'}[t]) || '•';
}
function renderErrors() {
  const q = (document.getElementById('errSearch').value || '').toLowerCase();
  const rows = ERRORS.filter(e => {
    if (ERR_FILTER === 'pendiente' && e.resuelto == 1) return false;
    if (ERR_FILTER !== 'all' && ERR_FILTER !== 'pendiente' && e.tipo !== ERR_FILTER) return false;
    if (q && !(e.mensaje + ' ' + (e.archivo||'') + ' ' + (e.url||'')).toLowerCase().includes(q)) return false;
    return true;
  });
  document.getElementById('errList').innerHTML = rows.length ? `<table class="data-table">
    <thead><tr><th></th><th>Fecha</th><th>Tipo</th><th>Mensaje</th><th>Archivo</th><th>URL</th><th>Usuario</th><th></th></tr></thead>
    <tbody>${rows.map(e => `
      <tr class="err-row ${e.resuelto==1?'err-resuelto':''}" onclick='showErrDetail(${JSON.stringify(e).replace(/'/g,"&apos;")})'>
        <td style="font-size:18px;">${errTypeIcon(e.tipo)}</td>
        <td><small>${e.created_at}</small></td>
        <td><span class="err-tag err-${e.tipo}">${e.tipo}</span></td>
        <td style="max-width:380px;"><b>${escapeHtml(e.mensaje).slice(0, 120)}${e.mensaje.length>120?'…':''}</b></td>
        <td><small>${(e.archivo||'').split(/[\\/]/).pop()}${e.linea?':'+e.linea:''}</small></td>
        <td><small>${e.url||'—'}</small></td>
        <td>${e.usuario||'<i>?</i>'}</td>
        <td>${e.resuelto==1?'✓':'<span style="color:var(--yellow);">●</span>'}</td>
      </tr>`).join('')}</tbody></table>`
    : '<div class="empty-state"><div class="ico">🎉</div><h3>Sin errores con este filtro</h3></div>';
}
function escapeHtml(s) {
  return (s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}
window.showErrDetail = (e) => {
  document.getElementById('errDetail').innerHTML = `
    <div class="err-detail-grid">
      <div><b>Tipo</b><div><span class="err-tag err-${e.tipo}">${e.tipo}</span></div></div>
      <div><b>Fecha</b><div>${e.created_at}</div></div>
      <div><b>Usuario</b><div>${e.usuario||'?'}</div></div>
      <div><b>IP</b><div>${e.ip||'?'}</div></div>
      <div style="grid-column:1/-1;"><b>Mensaje</b><pre class="err-pre">${escapeHtml(e.mensaje)}</pre></div>
      <div style="grid-column:1/-1;"><b>Archivo</b><div><code>${e.archivo||'?'}:${e.linea||'?'}</code></div></div>
      <div style="grid-column:1/-1;"><b>URL</b><div><code>${e.metodo||''} ${e.url||'—'}</code></div></div>
      ${e.user_agent?`<div style="grid-column:1/-1;"><b>Navegador</b><div><small>${escapeHtml(e.user_agent)}</small></div></div>`:''}
      ${e.stack?`<div style="grid-column:1/-1;"><b>Stack trace</b><pre class="err-pre err-stack">${escapeHtml(e.stack)}</pre></div>`:''}
    </div>`;
  document.getElementById('errResolve').style.display = e.resuelto == 1 ? 'none' : '';
  document.getElementById('errResolve').onclick = async () => {
    await API.post('errores/resolve', { id: e.id });
    toast('Marcado como resuelto');
    closeModal('errModal');
    loadErrors();
  };
  openModal('errModal');
};

/* ============ ROL TAG helper ============ */
function rolBadge(rol) {
  return `<span class="role-tag role-${rol}">${rol}</span>`;
}

/* ============ USUARIOS (modelo Chulisima + permisos por usuario) ============ */
const DIAS_NOMBRES = { 1:'Lun', 2:'Mar', 3:'Mié', 4:'Jue', 5:'Vie', 6:'Sáb', 7:'Dom' };
let PERM_CATALOG = null;   // { catalogo: {...}, role_access: {...} }

async function initSysUsers() {
  // Cargar catálogo de permisos una sola vez
  try { PERM_CATALOG = await API.get('usuarios/permisos_catalogo'); }
  catch (e) { console.warn('No se pudo cargar catálogo de permisos', e); }

  await loadUsers();
  document.getElementById('uSave').onclick = saveUser;

  // Cambio de rol → reset checkboxes al default del nuevo rol
  document.getElementById('uRol').onchange = () => {
    renderPermisosGrid(getDefaultPermsForRole(document.getElementById('uRol').value));
  };
  // Botón "Restablecer al default del rol"
  document.getElementById('uResetPerms').onclick = (e) => {
    e.preventDefault();
    renderPermisosGrid(getDefaultPermsForRole(document.getElementById('uRol').value));
    toast('Permisos restablecidos al default del rol', 'info');
  };
}

function getDefaultPermsForRole(rol) {
  return PERM_CATALOG?.role_access?.[rol] || [];
}

function calcularPermisosEfectivos(u) {
  const base   = getDefaultPermsForRole(u.rol);
  const extra  = (u.permisos_extra  || '').split(',').filter(Boolean);
  const quitar = (u.permisos_quitar || '').split(',').filter(Boolean);
  return [...new Set([...base, ...extra])].filter(m => !quitar.includes(m));
}

/**
 * Renderiza el grid de checkboxes agrupado por categoría.
 * @param {string[]} permisosActivos  módulos que deben quedar marcados
 */
function renderPermisosGrid(permisosActivos) {
  const grid = document.getElementById('uPermisosGrid');
  if (!grid || !PERM_CATALOG) return;
  const active = new Set(permisosActivos);
  const rol = document.getElementById('uRol').value;
  const base = new Set(getDefaultPermsForRole(rol));

  let html = '';
  for (const [grupo, items] of Object.entries(PERM_CATALOG.catalogo)) {
    html += `<div class="permisos-col">
      <h5>${grupo}</h5>`;
    for (const [key, [icon, label]] of Object.entries(items)) {
      const checked = active.has(key);
      const isBase = base.has(key);
      const tag = checked === isBase ? '' : (checked ? '<span class="perm-tag perm-add">+</span>' : '<span class="perm-tag perm-rm">−</span>');
      html += `<label class="perm-row ${checked ? 'on' : ''}">
        <input type="checkbox" class="uPerm" value="${key}" ${checked?'checked':''}>
        <span>${icon} ${label}</span>
        ${tag}
      </label>`;
    }
    html += `</div>`;
  }
  grid.innerHTML = html;

  // Toggle visual al click
  grid.querySelectorAll('.uPerm').forEach(c => {
    c.onchange = () => {
      c.closest('.perm-row').classList.toggle('on', c.checked);
      // Recalcular tag de cada fila
      grid.querySelectorAll('.perm-row').forEach(r => {
        const k = r.querySelector('.uPerm').value;
        const ch = r.querySelector('.uPerm').checked;
        const tag = r.querySelector('.perm-tag');
        if (tag) tag.remove();
        if (ch !== base.has(k)) {
          const span = document.createElement('span');
          span.className = 'perm-tag ' + (ch ? 'perm-add' : 'perm-rm');
          span.textContent = ch ? '+' : '−';
          r.appendChild(span);
        }
      });
    };
  });
}
async function loadUsers() {
  USERS = await API.get('usuarios/list');
  const html = `<table class="data-table">
    <thead><tr><th>#</th><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Estado</th><th>Horario</th><th>Permisos</th><th></th></tr></thead>
    <tbody>${USERS.map(u => {
      const horario = u.horario_activo == 1
        ? formatHorario(u)
        : '<span class="muted">Sin restricción</span>';
      const extras  = (u.permisos_extra  || '').split(',').filter(Boolean).length;
      const quitar  = (u.permisos_quitar || '').split(',').filter(Boolean).length;
      const permTxt = (extras+quitar) === 0
        ? '<span class="muted">Default del rol</span>'
        : `${extras?`<span class="perm-tag perm-add">+${extras}</span>`:''} ${quitar?`<span class="perm-tag perm-rm">−${quitar}</span>`:''}`;
      return `<tr>
        <td>${u.id}</td>
        <td><b>${u.nombre}</b></td>
        <td><code>${u.usuario}</code></td>
        <td>${rolBadge(u.rol)}</td>
        <td>${u.activo == 1 ? '<span style="color:var(--green);">✓ Activo</span>' : '<span style="color:var(--red);">✗ Inactivo</span>'}</td>
        <td><small>${horario}</small></td>
        <td><small>${permTxt}</small></td>
        <td>
          <button class="link-btn" onclick="editUser(${u.id})">✏ Editar</button>
          ${u.activo == 1 ? `<button class="link-btn" onclick="deleteUser(${u.id})">🚫</button>` : ''}
        </td>
      </tr>`;
    }).join('')}</tbody></table>`;
  document.getElementById('usersGrid').innerHTML = html;
}

function formatHorario(u) {
  const dias = (u.dias_laborales || '').split(',').filter(Boolean);
  const diasTxt = dias.length
    ? dias.map(d => DIAS_NOMBRES[+d]).join(' ')
    : 'Todos los días';
  const horas = (u.hora_entrada && u.hora_salida)
    ? ` · ${u.hora_entrada.slice(0,5)}–${u.hora_salida.slice(0,5)}`
    : '';
  return diasTxt + horas;
}

window.openUserModal = function() {
  document.getElementById('userModalTitle').textContent = 'Nuevo usuario';
  ['uId','uNombre','uUsuario','uPassword','uHoraEntrada','uHoraSalida'].forEach(id => {
    const el = document.getElementById(id); if (el) el.value = '';
  });
  document.getElementById('uRol').value = 'mesero';
  document.getElementById('uActivo').checked = true;
  document.getElementById('uHorarioActivo').checked = false;
  document.querySelectorAll('.uDia').forEach(c => c.checked = false);
  renderPermisosGrid(getDefaultPermsForRole('mesero'));
  openModal('userModal');
};

window.editUser = function(id) {
  const u = USERS.find(x => x.id === id);
  if (!u) return;
  document.getElementById('userModalTitle').textContent = `Editar ${u.nombre} (${u.usuario})`;
  setVal('uId', u.id);
  setVal('uNombre', u.nombre);
  setVal('uUsuario', u.usuario);
  setVal('uPassword', '');
  setVal('uRol', u.rol);
  setChk('uActivo', u.activo == 1);
  setChk('uHorarioActivo', u.horario_activo == 1);
  setVal('uHoraEntrada', u.hora_entrada ? u.hora_entrada.slice(0,5) : '');
  setVal('uHoraSalida',  u.hora_salida  ? u.hora_salida.slice(0,5)  : '');
  const dias = (u.dias_laborales || '').split(',').filter(Boolean).map(Number);
  document.querySelectorAll('.uDia').forEach(c => { c.checked = dias.includes(+c.value); });
  renderPermisosGrid(calcularPermisosEfectivos(u));
  openModal('userModal');
};

window.deleteUser = async function(id) {
  if (!confirm('¿Deshabilitar este usuario? (puedes reactivarlo después)')) return;
  await API.post('usuarios/delete', { id });
  toast('Usuario deshabilitado');
  loadUsers();
};

async function saveUser() {
  const id = document.getElementById('uId').value;
  const dias = Array.from(document.querySelectorAll('.uDia:checked')).map(c => +c.value);
  const permisos = Array.from(document.querySelectorAll('.uPerm:checked')).map(c => c.value);
  const data = {
    nombre:  document.getElementById('uNombre').value.trim(),
    usuario: document.getElementById('uUsuario').value.trim(),
    rol:     document.getElementById('uRol').value,
    activo:  document.getElementById('uActivo').checked ? 1 : 0,
    horario_activo: document.getElementById('uHorarioActivo').checked ? 1 : 0,
    dias_laborales: dias,
    hora_entrada: document.getElementById('uHoraEntrada').value || null,
    hora_salida:  document.getElementById('uHoraSalida').value  || null,
    permisos_efectivos: permisos,
  };
  const pwd = document.getElementById('uPassword').value;
  if (pwd) data.password = pwd;

  try {
    if (id) { data.id = +id; await API.post('usuarios/update', data); }
    else    { await API.post('usuarios/create', data); }
    closeModal('userModal');
    toast('Usuario guardado');
    loadUsers();
  } catch (e) { toast(e.message, 'error'); }
}

/* ============ MESAS (CRUD) ============ */
async function initSysTables() {
  await loadMesas();
  document.getElementById('mSave').onclick = saveMesa;
}
async function loadMesas() {
  MESAS = await API.get('admin/mesas_list');
  document.getElementById('mesasGrid').innerHTML = `<table class="data-table">
    <thead><tr><th>#</th><th>Mesa</th><th>Nombre</th><th>Capacidad</th><th>Zona</th><th>Descripción</th><th>Estado</th><th></th></tr></thead>
    <tbody>${MESAS.map(m => `
      <tr><td>${m.id}</td><td>M${String(m.numero).padStart(2,'0')}</td>
      <td>${(m.nombre||'').trim() ? `<b>${m.nombre}</b>` : '<span class="muted">—</span>'}</td>
      <td>${m.capacidad} personas</td><td>${m.zona}</td>
      <td><small>${m.descripcion || '<span class="muted">—</span>'}</small></td>
      <td>${m.activa ? m.estado : '✗ Inactiva'}</td>
      <td><button class="link-btn" onclick="editMesa(${m.id})">✏</button>
      <button class="link-btn" onclick="delMesa(${m.id})">🗑</button></td></tr>`).join('')}</tbody></table>`;
}
window.openMesaModal = () => {
  document.getElementById('mesaTitle').textContent = 'Nueva mesa';
  ['mId','mNumero','mNombre','mDescripcion'].forEach(id => setVal(id, ''));
  setVal('mCapacidad', 4);
  setVal('mZona', 'Salón');
  setChk('mActiva', true);
  openModal('mesaModal');
};
window.editMesa = id => {
  const m = MESAS.find(x => x.id === id);
  document.getElementById('mesaTitle').textContent = 'Editar mesa';
  setVal('mId', m.id);
  setVal('mNumero', m.numero);
  setVal('mNombre', m.nombre);
  setVal('mCapacidad', m.capacidad);
  setVal('mZona', m.zona);
  setVal('mDescripcion', m.descripcion);
  setChk('mActiva', !!m.activa);
  openModal('mesaModal');
};
window.delMesa = async id => {
  if (!confirm('¿Eliminar esta mesa?')) return;
  await API.post('admin/mesas_delete', { id });
  toast('Mesa eliminada'); loadMesas();
};
async function saveMesa() {
  const data = {
    numero: +document.getElementById('mNumero').value,
    nombre: document.getElementById('mNombre').value.trim() || null,
    capacidad: +document.getElementById('mCapacidad').value,
    zona: document.getElementById('mZona').value,
    descripcion: document.getElementById('mDescripcion').value.trim() || null,
    activa: document.getElementById('mActiva').checked ? 1 : 0,
  };
  const id = document.getElementById('mId').value;
  if (id) data.id = +id;
  await API.post('admin/mesas_save', data);
  closeModal('mesaModal'); toast('Mesa guardada'); loadMesas();
}

/* ============ MENÚ (productos + categorías) ============ */
async function initSysMenu() {
  // pestañas
  document.querySelectorAll('[data-tab]').forEach(b => {
    b.onclick = () => {
      document.querySelectorAll('[data-tab]').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      document.getElementById('tab-prods').style.display = b.dataset.tab === 'prods' ? '' : 'none';
      document.getElementById('tab-cats').style.display  = b.dataset.tab === 'cats'  ? '' : 'none';
    };
  });
  [PRODS, CATS, PRINTERS, MOD_GROUPS] = await Promise.all([
    API.get('admin/productos_list'),
    API.get('admin/categorias_list'),
    API.get('admin/impresoras_list'),
    API.get('admin/mods_grupos_list'),
  ]);
  renderProds(); renderCats();
  document.getElementById('pSave').onclick = saveProd;
  document.getElementById('cSave').onclick = saveCat;
  document.getElementById('prodSearch').oninput = renderProds;
}
function renderProds() {
  const q = (document.getElementById('prodSearch').value || '').toLowerCase();
  const rows = PRODS.filter(p => !q || p.nombre.toLowerCase().includes(q) || p.categoria.toLowerCase().includes(q));
  document.getElementById('prodsGrid').innerHTML = `<table class="data-table">
    <thead><tr><th></th><th>Producto</th><th>Categoría</th><th>Cocina</th><th>Precio</th><th>Stock</th><th></th></tr></thead>
    <tbody>${rows.map(p => `
      <tr>
        <td style="width:54px;">${productThumb(p, 44)}</td>
        <td><b>${p.nombre}</b> ${p.destacado==1?'⭐':''} ${p.disponible==0?'<span class="off-tag">N/D</span>':''}</td>
        <td>${p.categoria}</td>
        <td>${p.cocina_2==1 ? '<span class="cocina-tag cocina-2">🍳 Cocina 2</span>' : '<span class="cocina-tag cocina-1">🔥 Cocina 1</span>'}</td>
        <td>${fmt(p.precio)}</td>
        <td>${p.maneja_stock==1 ? `<b>${p.stock}</b> ${p.unidad||'pz'}` : '<span class="muted">— sin stock</span>'}</td>
        <td><button class="link-btn" onclick="editProd(${p.id})">✏</button>
            <button class="link-btn" onclick="delProd(${p.id})">🗑</button></td>
      </tr>`).join('')}</tbody></table>`;
}

/**
 * Genera un thumbnail (imagen o emoji fallback) para usar en grids/tablas.
 */
function productThumb(p, size = 44) {
  if (p.imagen) {
    const base = p.imagen.replace(/\.png$/, '');
    const thumbUrl = `${APP.url}/assets/img/products/${base}-thumb.png`;
    return `<img src="${thumbUrl}" alt="${p.nombre||''}" class="prod-thumb" style="width:${size}px;height:${size}px;">`;
  }
  return `<div class="prod-thumb prod-emoji" style="width:${size}px;height:${size}px;font-size:${size*0.55}px;">${p.emoji||'🍽'}</div>`;
}
function renderCats() {
  document.getElementById('catsGrid').innerHTML = `<table class="data-table">
    <thead><tr><th>Orden</th><th>Categoría</th><th>Impresora default</th><th>Estado</th><th></th></tr></thead>
    <tbody>${CATS.map(c => `
      <tr><td>${c.orden}</td><td>${c.nombre}</td><td>${c.impresora || '—'}</td>
      <td>${c.activa ? '✓' : '✗'}</td>
      <td><button class="link-btn" onclick="editCat(${c.id})">✏</button>
          <button class="link-btn" onclick="delCat(${c.id})">🗑</button></td></tr>`).join('')}</tbody></table>`;
}

window.openProdModal = () => editProd(0);
window.editProd = async id => {
  // Si los datos no se han cargado todavía, no abrir el modal en blanco
  if (!CATS || !CATS.length) { toast('Las categorías aún no se han cargado, espera un momento', 'error'); return; }
  // Si el modal no está en el DOM (versión vieja cacheada), forzar refresh
  if (!document.getElementById('prodModal')) {
    if (confirm('La página parece estar desactualizada. ¿Recargar?')) location.reload(true);
    return;
  }
  const p = (id ? PRODS.find(x => x.id === id) : null) || {};
  setText('prodTitle', id ? 'Editar producto' : 'Nuevo producto');
  setVal('pId', id || '');
  setVal('pNombre', p.nombre);
  setVal('pPrecio', p.precio);
  setVal('pEmoji', p.emoji || '🍽');
  setVal('pStock', p.stock ?? 50);
  setVal('pStockMin', p.stock_minimo ?? 5);
  setVal('pUnidad', p.unidad || 'pz');
  setVal('pDesc', p.descripcion);
  setChk('pDestacado', p.destacado == 1);
  setChk('pDisponible', p.disponible == 1 || !id);
  setChk('pCocina2', p.cocina_2 == 1);
  setChk('pManejaStock', p.maneja_stock == 1);
  setHTML('pCategoria', CATS.map(c => `<option value="${c.id}" ${c.id==p.categoria_id?'selected':''}>${c.nombre}</option>`).join(''));

  // Mostrar/ocultar campos de stock según el flag
  const toggleStockFields = () => {
    const el = document.getElementById('stockFields');
    if (el) el.style.display = document.getElementById('pManejaStock').checked ? '' : 'none';
  };
  toggleStockFields();
  const mst = document.getElementById('pManejaStock');
  if (mst) mst.onchange = toggleStockFields;

  // grupos de modificadores asignados
  let asignados = [];
  if (id) {
    try {
      const mods = await API.get('admin/productos_mods', { id });
      asignados = mods.map(g => g.id);
    } catch (e) { console.error('No se pudieron cargar mods:', e); }
  }
  setHTML('pMods', (MOD_GROUPS || []).map(g => `
    <label><input type="checkbox" value="${g.id}" ${asignados.includes(g.id)?'checked':''}> ${g.nombre} <small>(${g.tipo})</small></label>
  `).join(''));

  // Bind del uploader de imagen
  bindProductImage(p);

  openModal('prodModal');
};

/**
 * Configura el uploader de imagen del producto dentro del modal.
 */
function bindProductImage(p) {
  const file     = document.getElementById('pImgFile');
  const preview  = document.getElementById('pImgPreviewImg');
  const fallback = document.getElementById('pImgEmojiFallback');
  const delBtn   = document.getElementById('pImgDelete');
  const status   = document.getElementById('pImgStatus');
  const progress = document.getElementById('pImgProgress');
  const progBar  = document.getElementById('pImgProgressBar');
  if (!file) return;

  const showImage = (url) => {
    preview.src = url + '?t=' + Date.now();
    preview.style.display = '';
    fallback.style.display = 'none';
    delBtn.style.display = '';
    status.textContent = '✓ Imagen cargada';
  };
  const showEmoji = () => {
    preview.style.display = 'none';
    fallback.style.display = '';
    fallback.textContent = p.emoji || '🍽';
    delBtn.style.display = 'none';
    status.textContent = 'Sin imagen, se usará el emoji.';
  };

  if (p.imagen) showImage(`${APP.url}/assets/img/products/${p.imagen}`);
  else showEmoji();

  // Cambia emoji al teclear en el campo del modal
  const emoInput = document.getElementById('pEmoji');
  if (emoInput) emoInput.oninput = () => {
    if (fallback.style.display !== 'none') fallback.textContent = emoInput.value || '🍽';
  };

  file.onchange = async () => {
    const f = file.files[0];
    if (!f) return;
    if (!p.id) {
      toast('Primero guarda el producto, luego sube la imagen', 'error');
      file.value = ''; return;
    }
    if (f.size > 5 * 1024 * 1024) {
      toast('Máximo 5 MB', 'error'); file.value = ''; return;
    }

    // Preview local instantáneo
    const reader = new FileReader();
    reader.onload = e => { preview.src = e.target.result; preview.style.display = ''; fallback.style.display = 'none'; };
    reader.readAsDataURL(f);

    // Subir
    progress.style.display = ''; progBar.style.width = '20%';
    const form = new FormData();
    form.append('producto_id', p.id);
    form.append('imagen', f);

    try {
      const res = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.upload.onprogress = e => {
          if (e.lengthComputable) progBar.style.width = (20 + (e.loaded/e.total)*70) + '%';
        };
        xhr.onload = () => {
          progBar.style.width = '100%';
          try {
            const d = JSON.parse(xhr.responseText);
            if (xhr.status >= 200 && xhr.status < 300) resolve(d);
            else reject(new Error(d.error || 'Error'));
          } catch (e) { reject(new Error('Respuesta inválida')); }
        };
        xhr.onerror = () => reject(new Error('Error de red'));
        xhr.open('POST', `${APP.api}index.php?r=admin/producto_imagen_upload`);
        xhr.send(form);
      });
      toast('Imagen subida ✓', 'success');
      showImage(res.url);
      // actualizar p en memoria por si se vuelve a subir
      p.imagen = res.imagen;
      setTimeout(() => { progress.style.display = 'none'; progBar.style.width = '0%'; }, 600);
    } catch (e) {
      toast(e.message, 'error');
      progress.style.display = 'none';
      showEmoji();
    } finally { file.value = ''; }
  };

  delBtn.onclick = async () => {
    if (!confirm('¿Quitar la imagen y volver al emoji?')) return;
    try {
      await API.post('admin/producto_imagen_delete', { producto_id: p.id });
      p.imagen = null;
      showEmoji();
      toast('Imagen eliminada');
    } catch (e) { toast(e.message, 'error'); }
  };
}
window.delProd = async id => {
  if (!confirm('¿Eliminar este producto?\n\nSi tiene ventas u otro historial, solo se desactivará (N/D) para no afectar reportes.')) return;
  try {
    const r = await API.post('admin/productos_delete', { id });
    if (r.deleted) toast('Producto eliminado');
    else toast(`Solo se desactivó: tiene ${r.motivo}`, 'info');
    PRODS = await API.get('admin/productos_list'); renderProds();
  } catch (e) { toast(e.message, 'error'); }
};
async function saveProd() {
  const id = document.getElementById('pId').value;
  const data = {
    categoria_id: +document.getElementById('pCategoria').value,
    nombre: document.getElementById('pNombre').value.trim(),
    descripcion: document.getElementById('pDesc').value.trim(),
    precio: +document.getElementById('pPrecio').value,
    emoji: document.getElementById('pEmoji').value || '🍽',
    destacado: document.getElementById('pDestacado').checked ? 1 : 0,
    disponible: document.getElementById('pDisponible').checked ? 1 : 0,
    stock: +document.getElementById('pStock').value,
    stock_minimo: +document.getElementById('pStockMin').value,
    unidad: document.getElementById('pUnidad').value,
    cocina_2: document.getElementById('pCocina2').checked ? 1 : 0,
    maneja_stock: document.getElementById('pManejaStock').checked ? 1 : 0,
  };
  if (id) data.id = +id;
  const res = await API.post('admin/productos_save', data);
  const pid = res.id || +id;
  // guardar grupos de modificadores
  const grupos = Array.from(document.querySelectorAll('#pMods input:checked')).map(x => +x.value);
  await API.post('admin/productos_set_mods', { producto_id: pid, grupos });
  closeModal('prodModal'); toast('Producto guardado');
  PRODS = await API.get('admin/productos_list'); renderProds();
  // Refrescar también el cache global de productos (para órdenes y menú)
  try { window._PRODUCTOS_CACHE = await API.get('productos/list'); } catch(e) {}
}

window.openCatModal = () => editCat(0);
window.editCat = id => {
  const c = id ? CATS.find(x => x.id === id) : {};
  document.getElementById('cId').value = id || '';
  document.getElementById('cNombre').value = c.nombre || '';
  document.getElementById('cOrden').value = c.orden ?? 0;
  document.getElementById('cActiva').checked = c.activa == 1 || !id;
  document.getElementById('cImpresora').innerHTML = '<option value="">Ninguna</option>' + PRINTERS.map(i => `<option value="${i.id}" ${i.id==c.impresora_id?'selected':''}>${i.nombre}</option>`).join('');
  openModal('catModal');
};
async function saveCat() {
  const id = document.getElementById('cId').value;
  const data = {
    nombre: document.getElementById('cNombre').value.trim(),
    orden: +document.getElementById('cOrden').value,
    impresora_id: +document.getElementById('cImpresora').value || null,
    activa: document.getElementById('cActiva').checked ? 1 : 0,
  };
  if (id) data.id = +id;
  await API.post('admin/categorias_save', data);
  closeModal('catModal'); toast('Categoría guardada');
  CATS = await API.get('admin/categorias_list'); renderCats();
}
window.delCat = async id => {
  if (!confirm('¿Eliminar esta categoría?\n\nSolo se puede eliminar si no tiene productos.')) return;
  try {
    await API.post('admin/categorias_delete', { id });
    toast('Categoría eliminada');
    CATS = await API.get('admin/categorias_list'); renderCats();
  } catch (e) { toast(e.message, 'error'); }
};

/* ============ IMPRESORAS ============ */
async function initSysPrinters() {
  await loadPrt();
  document.getElementById('prtSave').onclick = savePrt;
}
async function loadPrt() {
  PRINTERS = await API.get('admin/impresoras_list');
  document.getElementById('prtGrid').innerHTML = `<table class="data-table">
    <thead><tr><th>Nombre</th><th>Ubicación</th><th>Tipo</th><th>Driver</th><th>Destino</th><th>Activa</th><th></th></tr></thead>
    <tbody>${PRINTERS.map(p => `
      <tr><td><b>${p.nombre}</b></td><td>${p.ubicacion}</td><td>${p.tipo}</td>
      <td>${p.driver}</td><td>${p.destino||'—'}</td><td>${p.activa?'✓':'✗'}</td>
      <td><button class="link-btn" onclick="editPrt(${p.id})">✏</button>
      <button class="link-btn" onclick="delPrt(${p.id})">🗑</button></td></tr>`).join('')}</tbody></table>`;
}
window.openPrtModal = () => editPrt(0);
window.editPrt = id => {
  const p = id ? PRINTERS.find(x => x.id === id) : {};
  document.getElementById('prtId').value = id || '';
  document.getElementById('prtNombre').value = p.nombre || '';
  document.getElementById('prtUbicacion').value = p.ubicacion || 'cocina';
  document.getElementById('prtTipo').value = p.tipo || 'comanda';
  document.getElementById('prtDriver').value = p.driver || 'navegador';
  document.getElementById('prtDestino').value = p.destino || '';
  document.getElementById('prtAncho').value = p.ancho_mm || 80;
  document.getElementById('prtCopias').value = p.copias || 1;
  document.getElementById('prtActiva').checked = p.activa == 1 || !id;
  openModal('prtModal');
};
window.delPrt = async id => {
  if (!confirm('¿Eliminar impresora?')) return;
  await API.post('admin/impresoras_delete', { id });
  toast('Eliminada'); loadPrt();
};
async function savePrt() {
  const id = document.getElementById('prtId').value;
  const data = {
    nombre: document.getElementById('prtNombre').value.trim(),
    ubicacion: document.getElementById('prtUbicacion').value,
    tipo: document.getElementById('prtTipo').value,
    driver: document.getElementById('prtDriver').value,
    destino: document.getElementById('prtDestino').value,
    ancho_mm: +document.getElementById('prtAncho').value,
    copias: +document.getElementById('prtCopias').value,
    activa: document.getElementById('prtActiva').checked ? 1 : 0,
  };
  if (id) data.id = +id;
  await API.post('admin/impresoras_save', data);
  closeModal('prtModal'); toast('Guardada'); loadPrt();
}

/* ============ NEGOCIO ============ */
async function initSysBusiness() {
  // ───── Sección de configuración ─────
  const cfg = await API.get('admin/config_list');
  const wrap = document.getElementById('bizFields');
  wrap.innerHTML = cfg.map(c => {
    const id = 'cfg_' + c.clave;
    let input;
    if (c.tipo === 'textarea') input = `<textarea id="${id}" class="inv-input" rows="2">${c.valor||''}</textarea>`;
    else if (c.tipo === 'number') input = `<input id="${id}" type="number" class="inv-input" value="${c.valor||''}">`;
    else input = `<input id="${id}" class="inv-input" value="${c.valor||''}">`;
    return `<div class="biz-row">
      <label class="inv-label">${c.descripcion || c.clave} <small style="color:var(--muted);">(${c.clave})</small></label>
      ${input}
    </div>`;
  }).join('');
  document.getElementById('bizSave').onclick = async () => {
    const cambios = {};
    cfg.forEach(c => { cambios[c.clave] = document.getElementById('cfg_' + c.clave).value; });
    await API.post('admin/config_save', { cambios });
    toast('Configuración guardada · recarga para ver cambios');
  };

  // ───── Sección del logo ─────
  initLogoUploader();

  // ───── Mini-mapa de ubicación ─────
  initLocationMap();

  // ───── Logo de ticket ─────
  initTicketLogo();

  // ───── Horario de atención ─────
  initHorario();
}

function initHorario() {
  const btn = document.getElementById('horarioSave');
  if (!btn) return;
  btn.onclick = async () => {
    const horarios = [];
    document.querySelectorAll('.horario-table tbody tr').forEach(tr => {
      horarios.push({
        dia: +tr.dataset.dia,
        abierto: tr.querySelector('.h-abierto').checked ? 1 : 0,
        hora_inicio: tr.querySelector('.h-inicio').value || '18:00',
        hora_fin: tr.querySelector('.h-fin').value || '22:30',
      });
    });
    const web_activo = document.getElementById('webSwitch').checked ? 1 : 0;
    try {
      await API.post('admin/horario_save', { horarios, web_activo });
      toast('🕐 Horario guardado', 'success');
    } catch (e) { toast(e.message, 'error'); }
  };
}

/** Generar/subir el logo de tickets en escala de grises */
function initTicketLogo() {
  const preview = document.getElementById('ticketLogoPreview');
  const empty   = document.getElementById('ticketLogoEmpty');
  const status  = document.getElementById('ticketLogoStatus');
  const delBtn  = document.getElementById('ticketLogoDelete') || document.getElementById('ticketLogoBtnDelete');
  const fileInp = document.getElementById('ticketLogoFile');
  if (!preview) return;

  const showImg = (url) => {
    preview.src = url;
    preview.style.display = '';
    if (empty) empty.style.display = 'none';
    if (delBtn) delBtn.style.display = '';
  };
  const showEmpty = () => {
    preview.style.display = 'none';
    if (empty) empty.style.display = 'grid';
    if (delBtn) delBtn.style.display = 'none';
  };

  // Mostrar botón quitar si ya hay logo (preview cargó)
  if (preview.complete && preview.naturalWidth > 0) { if (delBtn) delBtn.style.display = ''; }

  // Generar desde el logo principal (un click)
  document.getElementById('ticketLogoFromMain').onclick = async () => {
    status.textContent = '⏳ Generando…';
    const form = new FormData();
    form.append('source', 'principal');
    try {
      const res = await fetch(`${APP.api}index.php?r=admin/logo_ticket`, { method: 'POST', body: form });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Error');
      showImg(data.url);
      status.textContent = '✓ Logo de ticket generado desde el logo principal';
      toast('Logo de ticket listo', 'success');
    } catch (e) { status.textContent = '⚠ ' + e.message; toast(e.message, 'error'); }
  };

  // Subir otra imagen
  document.getElementById('ticketLogoBtnSelect').onclick = () => fileInp.click();
  fileInp.onchange = async () => {
    const f = fileInp.files[0];
    if (!f) return;
    if (f.size > 5*1024*1024) { toast('Máximo 5 MB', 'error'); return; }
    status.textContent = '⏳ Subiendo y convirtiendo a grises…';
    const form = new FormData();
    form.append('imagen', f);
    try {
      const res = await fetch(`${APP.api}index.php?r=admin/logo_ticket`, { method: 'POST', body: form });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Error');
      showImg(data.url);
      status.textContent = '✓ Logo de ticket actualizado (convertido a grises)';
      toast('Logo de ticket actualizado', 'success');
    } catch (e) { status.textContent = '⚠ ' + e.message; toast(e.message, 'error'); }
    finally { fileInp.value = ''; }
  };

  // Quitar
  if (delBtn) delBtn.onclick = async () => {
    if (!confirm('¿Quitar el logo de tickets? Los tickets saldrán sin logo (o usarán el principal).')) return;
    try {
      await API.post('admin/logo_ticket_delete', {});
      showEmpty();
      status.textContent = 'Logo de ticket eliminado';
      toast('Eliminado', 'info');
    } catch (e) { toast(e.message, 'error'); }
  };
}

/** Mapa Leaflet para ver/ajustar la ubicación del restaurante */
function initLocationMap() {
  const mapDiv = document.getElementById('bizMap');
  if (!mapDiv || typeof L === 'undefined') return;

  const loc = window.REST_LOC || { lat: 15.012659, lng: -92.406619 };
  let curLat = loc.lat, curLng = loc.lng;

  const map = L.map('bizMap').setView([curLat, curLng], 16);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 19,
  }).addTo(map);

  const marker = L.marker([curLat, curLng], { draggable: true }).addTo(map);
  marker.bindPopup('🍔 Tu restaurante<br><small>Arrástrame a la puerta del local</small>').openPopup();

  const updateCoords = (lat, lng) => {
    curLat = +(+lat).toFixed(6); curLng = +(+lng).toFixed(6);
    document.getElementById('mapLat').textContent = curLat;
    document.getElementById('mapLng').textContent = curLng;
  };

  marker.on('dragend', () => {
    const p = marker.getLatLng();
    updateCoords(p.lat, p.lng);
  });
  // Click en el mapa también mueve el marcador
  map.on('click', (e) => {
    marker.setLatLng(e.latlng);
    updateCoords(e.latlng.lat, e.latlng.lng);
  });

  // Botón GPS
  document.getElementById('mapGpsBtn').onclick = () => {
    if (!navigator.geolocation) { toast('Tu navegador no soporta GPS', 'error'); return; }
    toast('📡 Obteniendo ubicación…', 'info');
    navigator.geolocation.getCurrentPosition(
      pos => {
        const { latitude, longitude } = pos.coords;
        map.setView([latitude, longitude], 17);
        marker.setLatLng([latitude, longitude]);
        updateCoords(latitude, longitude);
        toast('Ubicación capturada · ajusta el marcador si hace falta', 'success');
      },
      err => toast('No se pudo obtener ubicación: ' + err.message, 'error'),
      { enableHighAccuracy: true, timeout: 10000 }
    );
  };

  // Guardar
  document.getElementById('mapSaveBtn').onclick = async () => {
    try {
      await API.post('admin/ubicacion_save', { lat: curLat, lng: curLng });
      toast('📍 Ubicación guardada · los envíos se calculan desde aquí', 'success');
    } catch (e) { toast(e.message, 'error'); }
  };

  // Leaflet necesita recalcular su tamaño cuando el contenedor ya está visible
  setTimeout(() => map.invalidateSize(), 250);
}

function initLogoUploader() {
  const fileInput  = document.getElementById('logoFile');
  const btnSelect  = document.getElementById('logoBtnSelect');
  const btnUpload  = document.getElementById('logoBtnUpload');
  const btnCancel  = document.getElementById('logoBtnCancel');
  const fileLabel  = document.getElementById('logoFileName');
  const preview    = document.getElementById('logoPreview');
  const progress   = document.getElementById('logoProgress');
  const progBar    = document.getElementById('logoProgressBar');
  if (!fileInput || !btnSelect) return;

  let selected = null;
  let originalSrc = preview ? preview.src : '';

  btnSelect.onclick = () => fileInput.click();

  fileInput.onchange = () => {
    const f = fileInput.files[0];
    if (!f) return;
    if (f.size > 5 * 1024 * 1024) { toast('Archivo muy grande (máx 5 MB)', 'error'); return; }
    selected = f;
    fileLabel.textContent = `${f.name} · ${(f.size/1024).toFixed(0)} KB`;
    // Vista previa local antes de subir
    const reader = new FileReader();
    reader.onload = e => { preview.src = e.target.result; preview.style.display = ''; };
    reader.readAsDataURL(f);
    btnUpload.style.display = '';
    btnCancel.style.display = '';
    btnSelect.style.display = 'none';
  };

  btnCancel.onclick = () => {
    selected = null;
    fileInput.value = '';
    fileLabel.textContent = '';
    preview.src = originalSrc;
    btnUpload.style.display = 'none';
    btnCancel.style.display = 'none';
    btnSelect.style.display = '';
    progress.style.display = 'none';
    progBar.style.width = '0%';
  };

  btnUpload.onclick = async () => {
    if (!selected) return;
    btnUpload.disabled = true;
    progress.style.display = '';
    progBar.style.width = '20%';

    const form = new FormData();
    form.append('logo', selected);

    try {
      const url = `${APP.api}index.php?r=admin/logo_upload`;
      // Usamos XHR para poder mostrar progreso
      const res = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.upload.onprogress = e => {
          if (e.lengthComputable) progBar.style.width = (20 + (e.loaded/e.total)*70) + '%';
        };
        xhr.onload = () => {
          progBar.style.width = '100%';
          try {
            const data = JSON.parse(xhr.responseText);
            if (xhr.status >= 200 && xhr.status < 300) resolve(data);
            else reject(new Error(data.error || 'Error en el upload'));
          } catch (e) { reject(new Error('Respuesta inválida del servidor')); }
        };
        xhr.onerror = () => reject(new Error('Error de red'));
        xhr.open('POST', url);
        xhr.send(form);
      });

      toast('Logo actualizado ✓', 'success');
      // Refrescar todas las imágenes de logo de la página con cache-buster
      const bust = '?v=' + Date.now();
      document.querySelectorAll('img[src*="logo.png"]').forEach(img => {
        img.src = img.src.split('?')[0] + bust;
      });
      originalSrc = preview.src;

      setTimeout(() => {
        btnUpload.style.display = 'none';
        btnCancel.style.display = 'none';
        btnSelect.style.display = '';
        progress.style.display = 'none';
        progBar.style.width = '0%';
        fileLabel.textContent = '';
        fileInput.value = '';
        selected = null;
        btnUpload.disabled = false;
      }, 800);

    } catch (e) {
      progress.style.display = 'none';
      btnUpload.disabled = false;
      toast(e.message, 'error');
    }
  };
}

/* ============ PROMOCIONES ============ */
async function initSysPromotions() {
  await loadPromos();
  document.getElementById('prSave').onclick = savePromo;
  // Cuando cambian aplicable_a, recargar target
  document.getElementById('prAplicable').onchange = updatePromoTarget;
}
async function loadPromos() {
  PROMOS = await API.get('admin/promos_list');
  document.getElementById('promosGrid').innerHTML = `<table class="data-table">
    <thead><tr><th>Nombre</th><th>Tipo</th><th>Valor</th><th>Aplica a</th><th>Código</th><th>Vigencia</th><th>Usos</th><th>Activa</th><th></th></tr></thead>
    <tbody>${PROMOS.map(p => `<tr>
      <td><b>${p.nombre}</b></td><td>${p.tipo}</td>
      <td>${p.tipo==='porcentaje'?p.valor+'%':fmt(p.valor)}</td>
      <td>${p.aplicable_a}</td><td>${p.codigo?'<code>'+p.codigo+'</code>':'—'}</td>
      <td>${p.desde||'—'} → ${p.hasta||'—'}</td>
      <td>${p.uso_actual}${p.uso_max?'/'+p.uso_max:''}</td>
      <td>${p.activa?'✓':'✗'}</td>
      <td><button class="link-btn" onclick="editPromo(${p.id})">✏</button>
      <button class="link-btn" onclick="delPromo(${p.id})">🗑</button></td></tr>`).join('')}</tbody></table>`;
}
async function updatePromoTarget() {
  const tipo = document.getElementById('prAplicable').value;
  const sel = document.getElementById('prTarget');
  sel.disabled = tipo === 'todo';
  if (tipo === 'todo') { sel.innerHTML = '<option>--</option>'; return; }
  const list = tipo === 'categoria' ? await API.get('admin/categorias_list') : await API.get('admin/productos_list');
  sel.innerHTML = list.map(x => `<option value="${x.id}">${x.nombre}</option>`).join('');
}
window.openPromoModal = () => editPromo(0);
window.editPromo = async id => {
  const p = id ? PROMOS.find(x => x.id === id) : {};
  ['prId','prNombre','prValor','prCodigo','prUsoMax','prDesde','prHasta','prHoraDesde','prHoraHasta','prDesc'].forEach(f => {
    const el = document.getElementById(f);
    const key = f.replace(/^pr/, '').toLowerCase().replace('horadesde','hora_desde').replace('horahasta','hora_hasta').replace('usomax','uso_max').replace('desc','descripcion');
    el.value = p[key] || '';
  });
  document.getElementById('prId').value = id || '';
  document.getElementById('prTipo').value = p.tipo || 'porcentaje';
  document.getElementById('prAplicable').value = p.aplicable_a || 'todo';
  document.getElementById('prActiva').checked = p.activa == 1 || !id;
  await updatePromoTarget();
  if (p.categoria_id || p.producto_id) document.getElementById('prTarget').value = p.categoria_id || p.producto_id;
  openModal('promoModal');
};
window.delPromo = async id => {
  if (!confirm('¿Eliminar promoción?')) return;
  await API.post('admin/promos_delete', { id });
  toast('Eliminada'); loadPromos();
};
async function savePromo() {
  const id = document.getElementById('prId').value;
  const aplicable = document.getElementById('prAplicable').value;
  const targetVal = document.getElementById('prTarget').value;
  const data = {
    nombre: document.getElementById('prNombre').value.trim(),
    descripcion: document.getElementById('prDesc').value,
    tipo: document.getElementById('prTipo').value,
    valor: +document.getElementById('prValor').value || 0,
    aplicable_a: aplicable,
    categoria_id: aplicable === 'categoria' ? +targetVal : null,
    producto_id: aplicable === 'producto' ? +targetVal : null,
    desde: document.getElementById('prDesde').value || null,
    hasta: document.getElementById('prHasta').value || null,
    hora_desde: document.getElementById('prHoraDesde').value || null,
    hora_hasta: document.getElementById('prHoraHasta').value || null,
    codigo: document.getElementById('prCodigo').value.trim() || null,
    uso_max: +document.getElementById('prUsoMax').value || null,
    activa: document.getElementById('prActiva').checked ? 1 : 0,
  };
  if (id) data.id = +id;
  await API.post('admin/promos_save', data);
  closeModal('promoModal'); toast('Guardada'); loadPromos();
}

/* ============ BITÁCORA ============ */
async function initSysAudit() {
  await loadAudit(100);
  document.getElementById('auditFilter').oninput = filterAudit;
}
// Declaración de función (hoisted) — necesario porque la llamamos desde
// initSysAudit que corre durante el IIFE inicial, antes de cualquier
// asignación a window.* que esté más abajo en el archivo.
async function loadAudit(limit) {
  AUDIT = await API.get('admin/bitacora', { limit });
  document.getElementById('auditSub').textContent = `${AUDIT.length} eventos`;
  filterAudit();
}
// La exponemos en window para los onclick inline de los botones del HTML
window.loadAudit = loadAudit;
function filterAudit() {
  const q = (document.getElementById('auditFilter').value || '').toLowerCase();
  const rows = AUDIT.filter(a =>
    !q || (a.usuario||'').toLowerCase().includes(q) ||
    a.accion.toLowerCase().includes(q) ||
    (a.entidad||'').toLowerCase().includes(q) ||
    (a.descripcion||'').toLowerCase().includes(q));
  document.getElementById('auditTable').innerHTML = `<table class="data-table">
    <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>Descripción</th><th>IP</th></tr></thead>
    <tbody>${rows.map(a => `<tr>
      <td><small>${a.created_at}</small></td>
      <td>${a.usuario||'<i>sistema</i>'}</td>
      <td><code>${a.accion}</code></td>
      <td>${a.entidad||'—'}${a.entidad_id?' #'+a.entidad_id:''}</td>
      <td>${a.descripcion||''}</td>
      <td><small>${a.ip||'—'}</small></td>
    </tr>`).join('')}</tbody></table>`;
}

/* ============ MODIFICADORES ============ */
async function initSysModifiers() {
  await loadModGroups();
  document.getElementById('mgSave').onclick = saveModGroup;
  document.getElementById('moSave').onclick = saveMod;
}
async function loadModGroups() {
  const grupos = await API.get('admin/mods_grupos_list');
  document.getElementById('modGrupos').innerHTML = grupos.map(g => `
    <div class="panel" style="margin-bottom:12px;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <h3>${g.nombre} <small style="color:var(--muted);">(${g.tipo}${g.obligatorio?' · obligatorio':''})</small></h3>
        <div>
          <button class="link-btn" onclick='editModGroup(${JSON.stringify(g)})'>✏ Editar grupo</button>
          <button class="btn btn-primary" style="padding:4px 12px;" onclick="openModOpt(${g.id})">+ Opción</button>
        </div>
      </div>
      <table class="data-table" style="margin-top:8px;">
        <thead><tr><th>Opción</th><th>Precio extra</th><th>Estado</th><th></th></tr></thead>
        <tbody>${g.opciones.map(o => `<tr>
          <td>${o.nombre}</td><td>${fmt(o.precio_extra)}</td><td>${o.activo?'✓':'✗'}</td>
          <td><button class="link-btn" onclick='editMod(${JSON.stringify(o)})'>✏</button>
          <button class="link-btn" onclick="delMod(${o.id})">🗑</button></td>
        </tr>`).join('') || '<tr><td colspan="4"><em>Sin opciones aún.</em></td></tr>'}</tbody>
      </table>
    </div>`).join('');
}
window.openModGroupModal = () => editModGroup({});
window.editModGroup = g => {
  document.getElementById('mgId').value = g.id || '';
  document.getElementById('mgNombre').value = g.nombre || '';
  document.getElementById('mgTipo').value = g.tipo || 'radio';
  document.getElementById('mgMax').value = g.max_selecciones || 1;
  document.getElementById('mgObligatorio').checked = g.obligatorio == 1;
  openModal('modGroupModal');
};
async function saveModGroup() {
  const id = document.getElementById('mgId').value;
  const data = {
    nombre: document.getElementById('mgNombre').value.trim(),
    tipo: document.getElementById('mgTipo').value,
    max_selecciones: +document.getElementById('mgMax').value,
    obligatorio: document.getElementById('mgObligatorio').checked ? 1 : 0,
  };
  if (id) data.id = +id;
  await API.post('admin/mods_grupo_save', data);
  closeModal('modGroupModal'); toast('Guardado'); loadModGroups();
}
window.openModOpt = grupoId => editMod({ grupo_id: grupoId });
window.editMod = o => {
  document.getElementById('moId').value = o.id || '';
  document.getElementById('moGrupoId').value = o.grupo_id;
  document.getElementById('moNombre').value = o.nombre || '';
  document.getElementById('moPrecio').value = o.precio_extra || 0;
  document.getElementById('moActivo').checked = o.activo == 1 || !o.id;
  openModal('modOptModal');
};
window.delMod = async id => {
  if (!confirm('¿Eliminar opción?')) return;
  await API.post('admin/mod_delete', { id });
  toast('Eliminada'); loadModGroups();
};
async function saveMod() {
  const id = document.getElementById('moId').value;
  const data = {
    grupo_id: +document.getElementById('moGrupoId').value,
    nombre: document.getElementById('moNombre').value.trim(),
    precio_extra: +document.getElementById('moPrecio').value || 0,
    activo: document.getElementById('moActivo').checked ? 1 : 0,
  };
  if (id) data.id = +id;
  await API.post('admin/mod_save', data);
  closeModal('modOptModal'); toast('Guardada'); loadModGroups();
}

/* ============ COMBOS ============ */
async function initSysCombos() {
  [COMBOS, ALL_PRODS] = await Promise.all([
    API.get('admin/combos_list'),
    API.get('productos/list'),
  ]);
  renderCombos();
  document.getElementById('cmSave').onclick = saveCombo;
  document.getElementById('cmAddItem').onclick = () => addComboItemRow({});
}
function renderCombos() {
  document.getElementById('combosGrid').innerHTML = `<table class="data-table">
    <thead><tr><th></th><th>Combo</th><th>Items</th><th>Precio</th><th>Estado</th><th></th></tr></thead>
    <tbody>${COMBOS.map(c => `<tr>
      <td style="font-size:24px;">${c.emoji}</td>
      <td><b>${c.nombre}</b><br><small style="color:var(--muted);">${c.descripcion||''}</small></td>
      <td>${c.items.map(i => `${i.cantidad}× ${i.nombre}`).join(', ')}</td>
      <td>${fmt(c.precio)}</td>
      <td>${c.activo?'✓':'✗'}</td>
      <td><button class="link-btn" onclick="editCombo(${c.id})">✏</button>
      <button class="link-btn" onclick="delCombo(${c.id})">🗑</button></td>
    </tr>`).join('')}</tbody></table>`;
}
window.openComboModal = () => editCombo(0);
window.editCombo = id => {
  const c = id ? COMBOS.find(x => x.id === id) : { items: [] };
  document.getElementById('cmId').value = id || '';
  document.getElementById('cmNombre').value = c.nombre || '';
  document.getElementById('cmEmoji').value = c.emoji || '🎁';
  document.getElementById('cmPrecio').value = c.precio || '';
  document.getElementById('cmDesc').value = c.descripcion || '';
  document.getElementById('cmActivo').checked = c.activo == 1 || !id;
  document.getElementById('cmItemsList').innerHTML = '';
  (c.items || []).forEach(it => addComboItemRow(it));
  openModal('comboModal');
};
function addComboItemRow(item) {
  const row = document.createElement('div');
  row.className = 'split-row';
  row.innerHTML = `
    <select class="combo-prod">${ALL_PRODS.map(p => `<option value="${p.id}" ${p.id==item.id?'selected':''}>${p.nombre} (${fmt(p.precio)})</option>`).join('')}</select>
    <input type="number" min="1" value="${item.cantidad||1}" class="combo-qty inv-input">
    <button class="rm">×</button>`;
  row.querySelector('.rm').onclick = () => row.remove();
  document.getElementById('cmItemsList').appendChild(row);
}
window.delCombo = async id => {
  if (!confirm('¿Eliminar combo?')) return;
  await API.post('admin/combos_delete', { id });
  toast('Eliminado'); COMBOS = await API.get('admin/combos_list'); renderCombos();
};
async function saveCombo() {
  const id = document.getElementById('cmId').value;
  const items = Array.from(document.querySelectorAll('#cmItemsList .split-row')).map(r => ({
    producto_id: +r.querySelector('.combo-prod').value,
    cantidad: +r.querySelector('.combo-qty').value,
  }));
  const data = {
    nombre: document.getElementById('cmNombre').value.trim(),
    descripcion: document.getElementById('cmDesc').value,
    precio: +document.getElementById('cmPrecio').value,
    emoji: document.getElementById('cmEmoji').value,
    activo: document.getElementById('cmActivo').checked ? 1 : 0,
    items,
  };
  if (id) data.id = +id;
  await API.post('admin/combos_save', data);
  closeModal('comboModal'); toast('Guardado');
  COMBOS = await API.get('admin/combos_list'); renderCombos();
}

/* ============ CAJA (cortes) ============ */
async function initCash() {
  await loadCash();
  document.getElementById('abrirCorte').onclick = async () => {
    const fondo = +document.getElementById('fondoInicial').value || 0;
    await API.post('caja/abrir', { fondo_inicial: fondo });
    closeModal('openCorteModal'); toast('Caja abierta'); loadCash();
  };
  document.getElementById('cerrarCorte').onclick = async () => {
    const contado = +document.getElementById('efectivoContado').value || 0;
    const obs = document.getElementById('corteObs').value;
    const r = await API.post('caja/cerrar', { efectivo_contado: contado, observaciones: obs });
    closeModal('closeCorteModal');
    toast(`Caja cerrada · Diferencia: ${fmt(r.diferencia)}`);
    loadCash();
  };
}
async function loadCash() {
  const a = await API.get('caja/actual');
  if (!a.abierto) {
    document.getElementById('cashStatus').innerHTML = `
      <div class="panel" style="text-align:center;padding:40px;">
        <h3 style="font-size:20px;color:var(--accent);">💰 No tienes caja abierta</h3>
        <p style="color:var(--muted);margin:10px 0 20px;">Abre la caja para empezar a registrar ventas en tu turno.</p>
        <button class="btn btn-success" onclick="openModal('openCorteModal')">Abrir caja</button>
      </div>`;
  } else {
    const c = a.corte;
    document.getElementById('cashStatus').innerHTML = `
      <div class="panel">
        <h3>Caja abierta desde ${c.abierto_at}</h3>
        <div class="cash-grid">
          <div class="cash-card"><div class="lbl">Fondo inicial</div><div class="val">${fmt(c.fondo_inicial)}</div></div>
          <div class="cash-card"><div class="lbl">Efectivo recibido</div><div class="val">${fmt(a.efectivo)}</div></div>
          <div class="cash-card"><div class="lbl">Tarjeta</div><div class="val">${fmt(a.tarjeta)}</div></div>
          <div class="cash-card"><div class="lbl">Transferencia</div><div class="val">${fmt(a.transfer)}</div></div>
          <div class="cash-card highlight"><div class="lbl">Efectivo esperado en caja</div><div class="val">${fmt(a.esperado)}</div></div>
          <div class="cash-card"><div class="lbl">Total vendido</div><div class="val">${fmt(a.ventas)}</div></div>
          <div class="cash-card"><div class="lbl">Órdenes cobradas</div><div class="val">${a.ordenes}</div></div>
        </div>
        <div style="text-align:right;margin-top:14px;">
          <button class="btn btn-success" onclick="openCloseModal(${JSON.stringify(a).replace(/"/g,'&quot;')})">Cerrar caja</button>
        </div>
      </div>`;
  }
  const hist = await API.get('caja/historial');
  document.getElementById('cashHistory').innerHTML = `<table class="data-table">
    <thead><tr><th>Apertura</th><th>Cierre</th><th>Usuario</th><th>Fondo</th><th>Esperado</th><th>Contado</th><th>Diferencia</th><th>Ventas</th></tr></thead>
    <tbody>${hist.map(h => `<tr>
      <td>${h.abierto_at}</td><td>${h.cerrado_at||'<b style="color:var(--green);">ABIERTO</b>'}</td>
      <td>${h.usuario}</td><td>${fmt(h.fondo_inicial)}</td>
      <td>${fmt(h.efectivo_esperado)}</td><td>${fmt(h.efectivo_contado)}</td>
      <td class="${h.diferencia>0?'pos':(h.diferencia<0?'neg':'')}">${fmt(h.diferencia)}</td>
      <td>${fmt(h.total_ventas)}</td>
    </tr>`).join('')}</tbody></table>`;
}
window.openCloseModal = a => {
  document.getElementById('closeCorteSummary').innerHTML = `
    <div class="row"><span>Fondo inicial</span><span>${fmt(a.corte.fondo_inicial)}</span></div>
    <div class="row"><span>+ Efectivo recibido</span><span>${fmt(a.efectivo)}</span></div>
    <div class="row total"><span>Efectivo esperado</span><span>${fmt(a.esperado)}</span></div>`;
  document.getElementById('efectivoContado').value = a.esperado.toFixed(2);
  openModal('closeCorteModal');
};

/* ============ PROPINAS ============ */
async function initTips() {
  document.getElementById('tApply').onclick = loadTips;
  await loadTips();
}
async function loadTips() {
  const desde = document.getElementById('tDesde').value;
  const hasta = document.getElementById('tHasta').value;
  const d = await API.get('propinas/reporte', { desde, hasta });
  const total = d.por_mesero.reduce((s,r) => s + (+r.total), 0);
  document.getElementById('tipsSub').textContent = `Total: ${fmt(total)} · ${d.detalle.length} propinas`;
  document.getElementById('tByMesero').innerHTML = d.por_mesero.length
    ? `<table class="data-table"><thead><tr><th>Mesero</th><th>Órdenes</th><th>Total</th></tr></thead>
       <tbody>${d.por_mesero.map(r => `<tr><td>${r.mesero||'—'}</td><td>${r.num_ordenes}</td><td>${fmt(r.total)}</td></tr>`).join('')}</tbody></table>`
    : '<em>Sin propinas en el rango.</em>';
  document.getElementById('tDetail').innerHTML = d.detalle.length
    ? `<table class="data-table"><thead><tr><th>Fecha</th><th>Mesa</th><th>Mesero</th><th>%</th><th>Monto</th></tr></thead>
       <tbody>${d.detalle.map(r => `<tr><td><small>${r.created_at}</small></td><td>M${String(r.mesa||0).padStart(2,'0')}</td><td>${r.mesero||'—'}</td><td>${r.porcentaje}%</td><td>${fmt(r.monto)}</td></tr>`).join('')}</tbody></table>`
    : '<em>Sin detalle.</em>';
}

/* ============ RESERVACIONES ============ */
async function initReservations() {
  RES_MESAS = await API.get('mesas/list');
  document.getElementById('rMesa').innerHTML = '<option value="">Cualquiera</option>' +
    RES_MESAS.map(m => `<option value="${m.id}">M${String(m.numero).padStart(2,'0')} (${m.capacidad}p)</option>`).join('');
  document.getElementById('resApply').onclick = loadRes;
  document.getElementById('rSave').onclick = saveRes;
  await loadRes();
}
async function loadRes() {
  const desde = document.getElementById('resDesde').value;
  const hasta = document.getElementById('resHasta').value;
  RES = await API.get('reservaciones/list', { desde, hasta });
  document.getElementById('resSub').textContent = `${RES.length} reservaciones en el rango`;
  document.getElementById('resList').innerHTML = RES.length ? `<table class="data-table">
    <thead><tr><th>Fecha</th><th>Hora</th><th>Cliente</th><th>Teléfono</th><th>#</th><th>Mesa</th><th>Estado</th><th>Notas</th><th></th></tr></thead>
    <tbody>${RES.map(r => `<tr>
      <td>${r.fecha}</td><td>${r.hora.slice(0,5)}</td>
      <td><b>${r.cliente_nombre}</b></td><td>${r.cliente_telefono||'—'}</td>
      <td>${r.personas}</td>
      <td>${r.mesa_numero?'M'+String(r.mesa_numero).padStart(2,'0'):'Cualquiera'}</td>
      <td><span class="res-state res-${r.estado}">${r.estado}</span></td>
      <td><small>${r.notas||''}</small></td>
      <td>
        <select onchange="changeResState(${r.id}, this.value)" class="inv-input" style="padding:4px;font-size:11px;">
          <option>cambiar...</option>
          <option value="confirmada">Confirmar</option>
          <option value="llegada">Marcar llegada</option>
          <option value="no_show">No show</option>
          <option value="cancelada">Cancelar</option>
        </select>
        <button class="link-btn" onclick="editRes(${r.id})">✏</button>
        <button class="link-btn" onclick="delRes(${r.id})">🗑</button>
      </td>
    </tr>`).join('')}</tbody></table>` : '<div class="empty-state">Sin reservaciones en el rango.</div>';
}
window.openResModal = () => editRes(0);
window.editRes = id => {
  const r = id ? RES.find(x => x.id === id) : {};
  document.getElementById('rId').value = id || '';
  document.getElementById('rNombre').value = r.cliente_nombre || '';
  document.getElementById('rTel').value = r.cliente_telefono || '';
  document.getElementById('rPersonas').value = r.personas || 2;
  document.getElementById('rFecha').value = r.fecha || localYMD();
  document.getElementById('rHora').value = r.hora ? r.hora.slice(0,5) : '19:00';
  document.getElementById('rMesa').value = r.mesa_id || '';
  document.getElementById('rNotas').value = r.notas || '';
  openModal('resModal');
};
async function saveRes() {
  const id = document.getElementById('rId').value;
  const data = {
    cliente_nombre: document.getElementById('rNombre').value.trim(),
    cliente_telefono: document.getElementById('rTel').value,
    personas: +document.getElementById('rPersonas').value,
    fecha: document.getElementById('rFecha').value,
    hora: document.getElementById('rHora').value,
    mesa_id: +document.getElementById('rMesa').value || null,
    notas: document.getElementById('rNotas').value,
    estado: 'pendiente',
  };
  if (id) data.id = +id;
  await API.post('reservaciones/save', data);
  closeModal('resModal'); toast('Guardada'); loadRes();
}
window.changeResState = async (id, estado) => {
  if (!estado || estado === 'cambiar...') return;
  await API.post('reservaciones/estado', { id, estado });
  toast('Estado actualizado'); loadRes();
};
window.delRes = async id => {
  if (!confirm('¿Eliminar reservación?')) return;
  await API.post('reservaciones/delete', { id });
  toast('Eliminada'); loadRes();
};

/* ============ MI TURNO ============ */
async function initMyShift() {
  const turno = await loadMyShift();
  // Si el sistema lo redirigió aquí por no tener turno (?abrir=1), avisar y
  // abrir el modal automáticamente para forzar la apertura del turno.
  const params = new URLSearchParams(location.search);
  if (params.get('abrir') === '1' && !turno) {
    const status = document.getElementById('myShiftStatus');
    if (status) status.insertAdjacentHTML('afterbegin',
      `<div class="panel" style="border:2px solid var(--red);background:rgba(232,90,79,.12);text-align:center;padding:16px;margin-bottom:14px;">
         <h3 style="color:var(--red);">🔒 Debes abrir tu turno para usar el sistema</h3>
         <p style="color:var(--muted);margin-top:6px;">No podrás tomar pedidos ni cobrar hasta abrir tu turno.</p>
       </div>`);
    openModal('openShiftModal');
  }
  document.getElementById('msAbrir').onclick = async () => {
    const fondo = +document.getElementById('msFondo').value || 0;
    try {
      await API.post('turnos/abrir', { fondo_inicial: fondo });
      closeModal('openShiftModal');
      toast('Turno abierto');
      // Si venía forzado por no tener turno, mandarlo a operar de una vez
      if (new URLSearchParams(location.search).get('abrir') === '1') {
        location.href = `${APP.sysUrl}/index.php?module=tables`;
      } else {
        loadMyShift();
      }
    } catch (e) { toast(e.message, 'error'); }
  };
  document.getElementById('msCerrar').onclick = async () => {
    const contado = +document.getElementById('msContado').value || 0;
    const obs = document.getElementById('msObs').value;
    try {
      const r = await API.post('turnos/cerrar', { efectivo_contado: contado, observaciones: obs });
      closeModal('closeShiftModal');
      toast(`Turno cerrado · diferencia ${fmt(r.corte.diferencia)}`);
      loadMyShift();
    } catch (e) { toast(e.message, 'error'); }
  };
}
async function loadMyShift() {
  const data = await API.get('turnos/mio');
  const turno = data.turno;
  const metrics = data.metrics || {};
  const sub = document.getElementById('myShiftSub');
  const wrap = document.getElementById('myShiftStatus');

  if (!turno) {
    sub.textContent = 'No tienes turno abierto';
    wrap.innerHTML = `<div class="panel" style="text-align:center;padding:30px;">
      <h3 style="color:var(--accent);font-size:20px;">⏱ Abre tu turno para empezar a vender</h3>
      <p style="color:var(--muted);margin:10px 0 20px;">Esto activa tu corte personal. Al final del día lo cierras y entregas el efectivo.</p>
      <button class="btn btn-success" onclick="openModal('openShiftModal')">Abrir turno</button>
    </div>`;
  } else {
    const esperado = (+turno.fondo_inicial) + (metrics.efectivo);
    sub.textContent = `Turno abierto desde ${turno.abierto_at}`;
    wrap.innerHTML = `<div class="panel">
      <h3>Turno activo · ${turno.fecha} · ${turno.hora_inicio.slice(0,5)}–${turno.hora_fin.slice(0,5)}</h3>
      <div class="cash-grid">
        <div class="cash-card"><div class="lbl">Fondo inicial</div><div class="val">${fmt(turno.fondo_inicial)}</div></div>
        <div class="cash-card"><div class="lbl">Efectivo recibido</div><div class="val">${fmt(metrics.efectivo)}</div></div>
        <div class="cash-card"><div class="lbl">Tarjeta</div><div class="val">${fmt(metrics.tarjeta)}</div></div>
        <div class="cash-card"><div class="lbl">Transferencia</div><div class="val">${fmt(metrics.transfer)}</div></div>
        <div class="cash-card highlight"><div class="lbl">Efectivo esperado a entregar</div><div class="val">${fmt(esperado)}</div></div>
        <div class="cash-card"><div class="lbl">Total vendido</div><div class="val">${fmt(metrics.ventas)}</div></div>
        <div class="cash-card"><div class="lbl">Propinas (te las quedas)</div><div class="val" style="color:var(--green);">${fmt(metrics.propinas)}</div></div>
        <div class="cash-card"><div class="lbl">Órdenes cobradas</div><div class="val">${metrics.num_ordenes}</div></div>
      </div>
      <div style="text-align:right;margin-top:14px;">
        <button class="btn btn-success" onclick="prepareCloseShift(${JSON.stringify({turno, metrics, esperado}).replace(/'/g, '&apos;').replace(/"/g, '&quot;')})">Cerrar turno y entregar</button>
      </div>
    </div>`;
  }

  // Historial
  const cortes = await API.get('turnos/mis_cortes');
  document.getElementById('myShiftHistory').innerHTML = cortes.length ? `<table class="data-table">
    <thead><tr><th>Fecha</th><th>Horario</th><th>Órdenes</th><th>Ventas</th><th>Propinas</th><th>Diferencia</th></tr></thead>
    <tbody>${cortes.map(c => `<tr>
      <td>${c.fecha}</td>
      <td>${c.hora_inicio.slice(0,5)}–${c.hora_fin.slice(0,5)}</td>
      <td>${c.num_ordenes}</td>
      <td>${fmt(c.total_ventas)}</td>
      <td>${fmt(c.total_propinas)}</td>
      <td class="${c.diferencia>0?'pos':(c.diferencia<0?'neg':'')}">${fmt(c.diferencia)}</td>
    </tr>`).join('')}</tbody></table>` : '<em>Sin cortes anteriores.</em>';
  return turno;
}
window.prepareCloseShift = (data) => {
  document.getElementById('msCloseSummary').innerHTML = `
    <div class="row"><span>Fondo inicial</span><span>${fmt(data.turno.fondo_inicial)}</span></div>
    <div class="row"><span>+ Efectivo recibido</span><span>${fmt(data.metrics.efectivo)}</span></div>
    <div class="row total"><span>Efectivo esperado</span><span>${fmt(data.esperado)}</span></div>`;
  document.getElementById('msContado').value = Number(data.esperado).toFixed(2);
  openModal('closeShiftModal');
};

/* ============ CLIENTES ============ */
async function initClients() {
  await loadClients();
  document.getElementById('cliSearch').oninput = e => {
    clearTimeout(window._cliSearchT);
    window._cliSearchT = setTimeout(() => loadClients(e.target.value), 300);
  };
  document.getElementById('cliSave').onclick = saveCliente;
}
async function loadClients(q = '') {
  CLIENTS = q.length >= 3
    ? await API.get('clientes/search', { q })
    : await API.get('clientes/list');
  document.getElementById('clientsSub').textContent = `${CLIENTS.length} clientes`;
  document.getElementById('cliGrid').innerHTML = CLIENTS.length ? `<table class="data-table">
    <thead><tr><th>Teléfono</th><th>Nombre</th><th>Dirección</th><th>Pedidos</th><th>Último</th><th></th></tr></thead>
    <tbody>${CLIENTS.map(c => `<tr>
      <td><code>${c.telefono}</code></td>
      <td><b>${c.nombre}</b></td>
      <td><small>${c.direccion||'—'}</small></td>
      <td>${c.total_pedidos||0}</td>
      <td><small>${c.last_order_at||'—'}</small></td>
      <td>
        <button class="link-btn" onclick='editCli(${JSON.stringify(c).replace(/"/g, "&quot;")})'>✏</button>
      </td>
    </tr>`).join('')}</tbody></table>` : '<div class="empty-state"><div class="ico">👤</div><h3>Sin clientes aún</h3><p>Los clientes se agregan automáticamente al levantar un pedido para llevar o domicilio.</p></div>';
}
window.openCliModal = () => editCli({});
window.editCli = c => {
  document.getElementById('cliTitle').textContent = c.id ? 'Editar cliente' : 'Nuevo cliente';
  setVal('cliId', c.id || '');
  setVal('cliTel', c.telefono);
  setVal('cliNombre', c.nombre);
  setVal('cliDir', c.direccion);
  setVal('cliRef', c.referencias);
  setVal('cliNotas', c.notas);
  openModal('cliModal');
};
async function saveCliente() {
  const data = {
    telefono: document.getElementById('cliTel').value.trim(),
    nombre: document.getElementById('cliNombre').value.trim(),
    direccion: document.getElementById('cliDir').value,
    referencias: document.getElementById('cliRef').value,
    notas: document.getElementById('cliNotas').value,
  };
  const id = document.getElementById('cliId').value;
  if (id) data.id = +id;
  try {
    await API.post('clientes/save', data);
    closeModal('cliModal'); toast('Cliente guardado');
    loadClients();
  } catch (e) { toast(e.message, 'error'); }
}

/* ============ DOMICILIOS ============ */
let DELIVERY_REPARTIDORES = [];

async function initDelivery() {
  // Cargar repartidores activos para asignación
  try { DELIVERY_REPARTIDORES = await API.get('delivery/repartidores'); }
  catch (e) { DELIVERY_REPARTIDORES = []; }

  document.querySelectorAll('[data-filter]').forEach(b => {
    b.onclick = () => {
      document.querySelectorAll('[data-filter]').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      DELIVERY_FILTER = b.dataset.filter;
      loadDelivery();
    };
  });
  await loadDelivery();
  // Auto-refresh cada 15s
  if (window._dlvInterval) clearInterval(window._dlvInterval);
  window._dlvInterval = setInterval(loadDelivery, 15000);
}

async function loadDelivery() {
  try {
    // Refrescar también la disponibilidad de repartidores (libre / en ruta)
    [DELIVERY_ORDERS, DELIVERY_REPARTIDORES] = await Promise.all([
      API.get('delivery/list', { filter: DELIVERY_FILTER }),
      API.get('delivery/repartidores').catch(() => DELIVERY_REPARTIDORES),
    ]);
  } catch (e) { DELIVERY_ORDERS = []; }

  const wrap = document.getElementById('dlvGrid');
  document.getElementById('dlvSub').textContent = `${DELIVERY_ORDERS.length} pedidos en este filtro`;

  renderDeliveryResumen();

  if (!DELIVERY_ORDERS.length) {
    wrap.innerHTML = `<div class="empty-state">
      <div class="ico">🛵</div>
      <h3>Sin pedidos en este filtro</h3>
      <p>Los nuevos pedidos a domicilio aparecerán aquí automáticamente.</p>
    </div>`;
    return;
  }

  wrap.innerHTML = `<div class="dlv-grid">${DELIVERY_ORDERS.map(o => renderDeliveryCard(o)).join('')}</div>`;
  wrap.querySelectorAll('[data-action]').forEach(b => {
    b.onclick = () => deliveryAction(b.dataset.action, +b.dataset.id, b);
  });
}

function renderDeliveryCard(o) {
  const elapsed = Math.floor((Date.now() - new Date(o.abierta_at).getTime()) / 60000);
  const isMine  = APP.user && o.repartidor_id == APP.user.id;
  // ERROR 007: se puede (re)asignar repartidor y cambiar envío mientras el
  // pedido NO esté entregado/cancelado. Meseros (Leo) también pueden.
  const gestionDlv = ['admin','cajero','mesero'].includes(APP.user?.rol);
  const noEntregada = !['entregada','cancelada','no_entregada'].includes(o.estado_entrega);
  const canAssign = gestionDlv && noEntregada;

  let actions = '';
  // (Re)asignar repartidor y editar costo de envío
  if (canAssign) {
    // Ordenar: en turno y con menos pedidos pendientes primero (los más libres)
    const reps = [...DELIVERY_REPARTIDORES].sort((a, b) => {
      const at = a.turno_estado === 'en_curso' ? 0 : 1;
      const bt = b.turno_estado === 'en_curso' ? 0 : 1;
      if (at !== bt) return at - bt;
      return (a.pendientes || 0) - (b.pendientes || 0);
    });
    const opts = reps.map(r => {
      const enTurno = r.turno_estado === 'en_curso';
      const pend = +r.pendientes || 0;
      const dispo = !enTurno ? '⚠ no en turno'
                  : pend === 0 ? '🟢 libre'
                  : `🟠 ${pend} en ruta`;
      return `<option value="${r.id}" ${!enTurno ? 'class="rep-off"' : ''}>${r.nombre} · ${dispo}</option>`;
    }).join('');
    const labelSel = o.repartidor_id ? '🔁 Cambiar repartidor…' : '+ Asignar repartidor…';
    actions += `<select class="dlv-assign-sel" data-id="${o.id}">
      <option value="">${labelSel}</option>${opts}
    </select>`;
    // Editar costo de envío (recalcula el total)
    actions += `<span class="dlv-envio-edit" style="display:inline-flex;gap:4px;align-items:center;">
      <span style="font-size:12px;color:var(--muted);">Envío $</span>
      <input type="number" class="dlv-envio-input" data-id="${o.id}" value="${+o.costo_envio||0}" min="0" step="1" style="width:64px;">
      <button class="btn btn-ghost dlv-envio-save" data-id="${o.id}" style="padding:4px 8px;">💾</button>
    </span>`;
  }
  // En camino: el repartidor o admin/cajero pueden marcar
  if (o.estado_entrega === 'asignada' && (isMine || ['admin','cajero'].includes(APP.user?.rol))) {
    actions += `<button class="btn btn-primary" data-action="en_camino" data-id="${o.id}">🛵 Voy a entregar</button>`;
  }
  // En camino: si YA está cobrada (p.ej. se pagó en caja) solo se cierra la
  // entrega; si no, se entrega y se cobra.
  if (o.estado_entrega === 'en_camino' && (isMine || ['admin','cajero'].includes(APP.user?.rol))) {
    if (o.estado === 'cobrada') {
      actions += `<button class="btn btn-success" data-action="marcar-entregada" data-id="${o.id}">✓ Marcar entregada</button>`;
    } else {
      actions += `<button class="btn btn-success" data-action="entregar" data-id="${o.id}">✓ Entregada · Cobrar</button>`;
      actions += `<button class="btn btn-ghost" data-action="no_entregada" data-id="${o.id}" style="color:var(--red);">✗ No entregada</button>`;
    }
  }
  // Editar items + cambiar tipo (si no está cobrada/en camino/entregada)
  if (['pendiente','asignada'].includes(o.estado_entrega) && o.estado === 'abierta') {
    actions += `<a class="btn btn-ghost" href="${APP.sysUrl}/index.php?module=orders&orden=${o.id}">✏ Editar items</a>`;
    if (['admin','cajero'].includes(APP.user?.rol)) {
      actions += `<button class="btn btn-ghost" data-action="cambiar-llevar" data-id="${o.id}" style="color:var(--accent-2);">🔄 Pasar a "para llevar"</button>`;
      actions += `<button class="btn btn-ghost" data-action="cambiar-local" data-id="${o.id}" style="color:var(--blue);">🔄 Pasar a "comer aquí"</button>`;
    }
  }

  return `<div class="dlv-card dlv-${o.estado_entrega}">
    <div class="dlv-head">
      <div>
        <h4>${o.cliente_nombre || 'Sin nombre'}</h4>
        <small>📞 ${o.cliente_telefono || '—'}</small>
      </div>
      <div class="dlv-status">
        <span class="dlv-tag dlv-${o.estado_entrega}">${stateLabel(o.estado_entrega)}</span>
        <small>#${o.id} · hace ${elapsed}m</small>
      </div>
    </div>
    <div class="dlv-body">
      <div class="dlv-row"><span class="lbl">📍 Dirección</span><span>${o.cliente_direccion || '—'}</span></div>
      ${o.cliente_referencias ? `<div class="dlv-row"><span class="lbl">📝 Ref.</span><span>${o.cliente_referencias}</span></div>` : ''}
      <div class="dlv-row"><span class="lbl">🛵 Repartidor</span><span>${o.repartidor_nombre || '<i>sin asignar</i>'}</span></div>
      <div class="dlv-row"><span class="lbl">🍳 Cocina</span><span>${
        o.comandas_listas > 0 ? '<b style="color:var(--green);">LISTA</b>'
        : o.comandas_en_curso > 0 ? '<span style="color:var(--blue);">preparando…</span>'
        : '<span style="color:var(--muted);">sin enviar</span>'
      }</span></div>
      <div class="dlv-row"><span class="lbl">💰 Total</span><span><b>${fmt(+o.total + +o.costo_envio)}</b> <small>(envío ${fmt(o.costo_envio)})</small></span></div>
      ${o.web_metodo_pago ? `<div class="dlv-row"><span class="lbl">💳 Pago</span><span>${o.web_metodo_pago === 'transferencia' ? '💳 Transferencia <small>(verifica el comprobante)</small>' : '💵 Efectivo'}</span></div>` : ''}
      ${o.paga_con ? `<div class="dlv-row dlv-cambio"><span class="lbl">💵 Paga con</span><span><b>${fmt(o.paga_con)}</b> · lleva cambio: <b style="color:var(--accent-2);">${fmt(Math.max(0, +o.paga_con - (+o.total + +o.costo_envio)))}</b></span></div>` : ''}
    </div>
    <div class="dlv-actions">${actions || '<em style="color:var(--muted);font-size:11px;">Sin acciones disponibles</em>'}</div>
  </div>`;
}

function stateLabel(s) {
  return ({pendiente:'Pendiente',asignada:'Asignada',en_camino:'En camino',entregada:'Entregada',no_entregada:'No entregada',cancelada:'Cancelada'})[s] || s;
}

/**
 * #6 — Resumen por repartidor: muestra de un vistazo qué pedidos están
 * asignados a cada repartidor (y cuáles siguen sin asignar).
 */
function renderDeliveryResumen() {
  const box = document.getElementById('dlvResumen');
  if (!box) return;
  if (!DELIVERY_ORDERS.length) { box.innerHTML = ''; return; }

  const grupos = {};
  DELIVERY_ORDERS.forEach(o => {
    const key = o.repartidor_nombre || '__none__';
    (grupos[key] = grupos[key] || []).push(o);
  });

  // "Sin asignar" primero, luego repartidores por nombre
  const keys = Object.keys(grupos).sort((a, b) => {
    if (a === '__none__') return -1;
    if (b === '__none__') return 1;
    return a.localeCompare(b);
  });

  box.innerHTML = `<div class="dlv-resumen-grid">${keys.map(k => {
    const list = grupos[k];
    const sinAsignar = k === '__none__';
    const enCamino = list.filter(o => o.estado_entrega === 'en_camino').length;
    const entregadas = list.filter(o => o.estado_entrega === 'entregada').length;
    const pend = list.length - enCamino - entregadas;
    return `<div class="dlv-rep-chip ${sinAsignar ? 'sin-asignar' : ''}">
      <div class="rep-name">${sinAsignar ? '⚠ Sin asignar' : '🛵 ' + k}</div>
      <div class="rep-stats">
        <b>${list.length}</b> pedido${list.length !== 1 ? 's' : ''}
        ${enCamino ? ` · <span style="color:var(--blue);">${enCamino} en camino</span>` : ''}
        ${pend && !sinAsignar ? ` · ${pend} por salir` : ''}
        ${entregadas ? ` · <span style="color:var(--green);">${entregadas} entregado${entregadas !== 1 ? 's' : ''}</span>` : ''}
      </div>
    </div>`;
  }).join('')}</div>`;
}

async function deliveryAction(action, id, btn) {
  try {
    if (action === 'cambiar-llevar') {
      if (!confirm('¿El cliente ya no quiere domicilio? Se cambiará a "Para llevar" (sin envío ni repartidor).')) return;
      const o = DELIVERY_ORDERS.find(x => x.id === id);
      const nombre = o?.cliente_nombre || prompt('Nombre del cliente:');
      if (!nombre) return;
      await API.post('ordenes/cambiar_tipo', {
        orden_id: id, tipo: 'llevar',
        cliente_nombre: nombre,
        cliente_telefono: o?.cliente_telefono || '',
      });
      toast('Cambiado a "Para llevar" · cliente lo recogerá');
      loadDelivery();
      return;
    }
    if (action === 'cambiar-local') {
      // Pedir mesa libre
      const mesas = await API.get('mesas/list');
      const libres = mesas.filter(m => m.estado === 'libre');
      if (!libres.length) { toast('No hay mesas libres', 'error'); return; }
      const opts = libres.map(m => `M${String(m.numero).padStart(2,'0')} · ${m.capacidad}p · ${m.zona}`).join('\n');
      const num = prompt(`¿En qué mesa se va a sentar el cliente?\n${opts}`);
      if (!num) return;
      const mesa = libres.find(m => m.numero == +num);
      if (!mesa) { toast('Mesa no encontrada o no libre', 'error'); return; }
      await API.post('ordenes/cambiar_tipo', {
        orden_id: id, tipo: 'local', mesa_id: mesa.id
      });
      toast(`✓ Cliente sentado en Mesa ${num}`, 'success');
      location.href = `${APP.sysUrl}/index.php?module=orders&mesa=${mesa.id}`;
      return;
    }
    if (action === 'en_camino') {
      await API.post('delivery/estado', { orden_id: id, estado: 'en_camino' });
      toast('🛵 Marcado en camino');
      loadDelivery(); refreshKitchenBadge();
    } else if (action === 'marcar-entregada') {
      if (!confirm('¿Marcar esta orden como ENTREGADA?\n\n(Ya fue cobrada; solo se cierra la entrega.)')) return;
      await API.post('delivery/estado', { orden_id: id, estado: 'entregada' });
      toast('✓ Entrega cerrada');
      loadDelivery(); refreshKitchenBadge();
    } else if (action === 'no_entregada') {
      const motivo = prompt('Motivo de NO entrega (cliente no estaba, rechazó, etc.):');
      if (!motivo || motivo.length < 3) return;
      await API.post('delivery/estado', { orden_id: id, estado: 'no_entregada', motivo });
      toast('Marcada no entregada', 'info');
      loadDelivery();
    } else if (action === 'entregar') {
      openDeliveryPayModal(id);
    }
  } catch (e) { toast(e.message, 'error'); }
}

// Bind del select de asignar (delegado)
document.addEventListener('change', async (e) => {
  const sel = e.target.closest?.('.dlv-assign-sel');
  if (!sel) return;
  const orden_id = +sel.dataset.id;
  const repartidor_id = +sel.value;
  if (!repartidor_id) return;
  try {
    await API.post('delivery/asignar', { orden_id, repartidor_id });
    toast('Repartidor asignado');
    loadDelivery();
  } catch (err) { toast(err.message, 'error'); }
});

// Bind del botón de guardar costo de envío (delegado)
document.addEventListener('click', async (e) => {
  const btn = e.target.closest?.('.dlv-envio-save');
  if (!btn) return;
  const orden_id = +btn.dataset.id;
  const input = document.querySelector(`.dlv-envio-input[data-id="${orden_id}"]`);
  const costo_envio = +(input?.value ?? 0);
  try {
    await API.post('delivery/asignar', { orden_id, costo_envio });
    toast('Costo de envío actualizado');
    loadDelivery();
  } catch (err) { toast(err.message, 'error'); }
});

/* Modal de cobro al entregar (reusa lógica del cobro normal) */
async function openDeliveryPayModal(orden_id) {
  const o = await API.get('delivery/detalle', { id: orden_id });
  const METHODS_local = await API.get('reportes/metodos_pago');

  const totalCobrar = +o.total + +o.costo_envio; // comida + envío (lo que se cobra)

  // Propina OPCIONAL para el repartidor. NO mostramos el total aquí para que
  // nadie lo escriba por error en el campo de propina (eso duplicaba el cobro).
  const propStr = prompt(
    `PROPINA para el repartidor (opcional).\n` +
    `Escribe SOLO la propina en pesos, o deja 0 si no hubo.`,
    '0'
  );
  if (propStr === null) return;  // canceló
  let propina = +propStr || 0;

  // Sanity check: si la "propina" es igual o mayor al total, casi seguro fue un
  // error (escribieron el total en el campo de propina).
  if (propina > 0 && propina >= totalCobrar) {
    if (!confirm(`⚠ Escribiste una propina de ${fmt(propina)}, que es igual o MAYOR al total del pedido (${fmt(totalCobrar)}).\n\n¿Es correcto? Si te equivocaste, cancela y pon 0.`)) {
      propina = 0;
    }
  }

  const totalPedido = totalCobrar + propina;

  // Reutilizamos el modal payModal global
  document.getElementById('paySub').textContent = fmt(o.subtotal);
  document.getElementById('payTax').textContent = fmt(o.iva);
  document.getElementById('payTotal').textContent = fmt(totalPedido);
  document.getElementById('payModalSub').textContent =
    `Domicilio · ${o.cliente_nombre} · ${fmt(o.total)} + envío ${fmt(o.costo_envio)}${propina>0?' + propina '+fmt(propina):''}`;

  // IMPORTANTE: SPLIT, METHODS y CURRENT_PAY_TOTAL son `let` en app.js — NO
  // son propiedades de window, así que asignar con `window.X = ...` no afecta
  // a la variable. Hay que asignar SIN el prefijo `window.` para que apunte al
  // mismo binding que usa renderSplit() / updateDiff().
  SPLIT = [{ metodo: 'cash', monto: totalPedido.toFixed(2) }];
  METHODS = METHODS_local;
  CURRENT_PAY_TOTAL = totalPedido;
  renderSplit();

  document.getElementById('payCancel').onclick = () => closeModal('payModal');
  document.getElementById('splitAdd').onclick = () => {
    const paid = SPLIT.reduce((s, p) => s + (+p.monto || 0), 0);
    SPLIT.push({ metodo: 'card', monto: Math.max(0, totalPedido - paid).toFixed(2) });
    renderSplit();
  };
  document.getElementById('payConfirm').onclick = async () => {
    try {
      const r = await API.post('delivery/entregar_y_cobrar', {
        orden_id,
        pagos: SPLIT.map(p => ({ metodo: p.metodo, monto: +p.monto || 0 })),
        propina: propina,
      });
      closeModal('payModal');
      const msg = propina > 0
        ? `✓ Entregada · cambio ${fmt(r.cambio)} · propina ${fmt(propina)} para ti 🎁`
        : `✓ Entregada y cobrada · cambio ${fmt(r.cambio)}`;
      toast(msg, 'success');
      loadDelivery();
    } catch (e) { toast(e.message, 'error'); }
  };
  openModal('payModal');
}

/* ============ DASHBOARD PERSONAL ============ */
async function initDashboard() {
  await renderDashboard();
  if (window._dashInterval) clearInterval(window._dashInterval);
  window._dashInterval = setInterval(renderDashboard, 20000);
}

async function renderDashboard() {
  const { turno, metrics } = await API.get('turnos/mio');
  const rol = window.CURRENT_ROL || APP.user?.rol;
  const card = document.getElementById('dashTurnoCard');

  // Tarjeta de turno
  if (!turno) {
    card.innerHTML = `<div class="panel" style="text-align:center;padding:30px;">
      <h3 style="color:var(--muted);">📅 No tienes turno programado para hoy</h3>
      <p style="color:var(--muted);margin-top:6px;">Avisa al administrador si necesitas trabajar.</p>
    </div>`;
  } else {
    const estado = turno.estado === 'en_curso' ? '✅ Trabajando' : '⏳ Turno por abrir';
    const accion = turno.estado === 'en_curso'
      ? `<a href="${APP.sysUrl}/index.php?module=my_shift" class="btn btn-primary">Ver mi turno →</a>`
      : `<a href="${APP.sysUrl}/index.php?module=my_shift" class="btn btn-success">⏱ Abrir mi turno</a>`;
    card.innerHTML = `<div class="panel" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
      <div>
        <h3>${estado} · ${turno.fecha}</h3>
        <p style="color:var(--muted);font-size:13px;">Horario ${turno.hora_inicio.slice(0,5)}–${turno.hora_fin.slice(0,5)}</p>
      </div>
      ${accion}
    </div>`;
  }

  // Métricas — la primera tarjeta destaca el efectivo a entregar
  const efectivoEntregar = turno ? (+turno.fondo_inicial + +metrics.efectivo) : 0;
  document.getElementById('dashStats').innerHTML = turno ? `
    <div class="cash-card highlight" style="grid-column:span 2;">
      <div class="lbl">💵 EFECTIVO A ENTREGAR AL CERRAR</div>
      <div class="val" style="font-size:30px;">${fmt(efectivoEntregar)}</div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;">Fondo ${fmt(turno.fondo_inicial)} + Cobros ${fmt(metrics.efectivo)}</div>
    </div>
    <div class="cash-card"><div class="lbl">Órdenes cobradas</div><div class="val">${metrics.num_ordenes}</div></div>
    <div class="cash-card"><div class="lbl">Total vendido</div><div class="val">${fmt(metrics.ventas)}</div></div>
    <div class="cash-card"><div class="lbl">Tarjeta</div><div class="val">${fmt(metrics.tarjeta)}</div></div>
    <div class="cash-card"><div class="lbl">Transfer</div><div class="val">${fmt(metrics.transfer)}</div></div>
    <div class="cash-card" style="background:rgba(105,201,123,.1);">
      <div class="lbl">Propinas 🎁 (te las quedas)</div>
      <div class="val" style="color:var(--green);">${fmt(metrics.propinas)}</div>
    </div>
    ${rol === 'repartidor' ? `<div class="cash-card"><div class="lbl">Envíos cobrados</div><div class="val">${fmt(metrics.envios)}</div></div>` : ''}
  ` : '<em style="grid-column:1/-1;color:var(--muted);">Abre tu turno para ver tus métricas en vivo.</em>';

  // Lista de órdenes del día (según rol)
  if (rol === 'repartidor') {
    document.getElementById('dashOrdsTitle').textContent = 'Mis entregas activas';
    try {
      const list = await API.get('delivery/list', { filter: 'mios' });
      const activas = list.filter(o => ['asignada','en_camino'].includes(o.estado_entrega));
      const ddiv = document.getElementById('dashOrders');
      ddiv.innerHTML = activas.length
        ? `<div class="dlv-grid">${activas.map(o => renderDeliveryCard(o)).join('')}</div>`
        : '<em style="color:var(--muted);">Sin entregas activas. Al asignarte un pedido aparecerá aquí.</em>';
      ddiv.querySelectorAll('[data-action]').forEach(b => {
        b.onclick = () => deliveryAction(b.dataset.action, +b.dataset.id, b);
      });
      // Entregas completadas HOY del repartidor — tabla detallada con tiempos
      const hoy = localYMD();
      const entregadas = list.filter(o =>
        o.estado_entrega === 'entregada' && (o.entregada_at || '').startsWith(hoy)
      );
      const dl = document.getElementById('dashDeliveries');
      if (dl) {
        if (!entregadas.length) {
          dl.innerHTML = '<em style="color:var(--muted);">Sin entregas completadas hoy.</em>';
        } else {
          // Calcular totales para el footer
          let sumComida = 0, sumEnvio = 0, sumPropina = 0, sumTotal = 0;
          entregadas.forEach(o => {
            sumComida += +o.total;
            sumEnvio  += +o.costo_envio;
            sumPropina+= +o.propina || 0;
            sumTotal  += (+o.total) + (+o.costo_envio) + (+o.propina || 0);
          });

          dl.innerHTML = `<table class="data-table dlv-table">
            <thead>
              <tr>
                <th>Cliente</th>
                <th>Dirección</th>
                <th>Salió</th>
                <th>Entregada</th>
                <th>Comida</th>
                <th>Envío 💚</th>
                <th>Propina 💚</th>
                <th>Total cobrado</th>
              </tr>
            </thead>
            <tbody>
              ${entregadas.map(o => {
                const salida = o.en_camino_at ? o.en_camino_at.slice(11,16) : '—';
                const llega  = o.entregada_at ? o.entregada_at.slice(11,16) : '—';
                const totalC = (+o.total) + (+o.costo_envio) + (+o.propina || 0);
                return `<tr>
                  <td><b>${o.cliente_nombre || '—'}</b></td>
                  <td><small>${o.cliente_direccion||'—'}</small></td>
                  <td><small>${salida}</small></td>
                  <td><small>${llega}</small></td>
                  <td>${fmt(o.total)}</td>
                  <td style="color:var(--green);">${fmt(o.costo_envio)}</td>
                  <td style="color:var(--green);">${fmt(o.propina || 0)}</td>
                  <td><b>${fmt(totalC)}</b></td>
                </tr>`;
              }).join('')}
            </tbody>
            <tfoot>
              <tr style="border-top:2px solid var(--border);">
                <td colspan="4" style="text-align:right;"><b>SUMA (${entregadas.length} entregas):</b></td>
                <td><b>${fmt(sumComida)}</b></td>
                <td style="color:var(--green);"><b>${fmt(sumEnvio)}</b></td>
                <td style="color:var(--green);"><b>${fmt(sumPropina)}</b></td>
                <td><b>${fmt(sumTotal)}</b></td>
              </tr>
            </tfoot>
          </table>
          <div class="repartidor-resumen">
            <div class="resumen-block">
              <div class="lbl">💚 TUYO (te lo quedas)</div>
              <div class="val">${fmt(sumEnvio + sumPropina)}</div>
              <small>${fmt(sumEnvio)} envíos + ${fmt(sumPropina)} propinas</small>
            </div>
            <div class="resumen-block highlight">
              <div class="lbl">📤 ENTREGAR A CAJA</div>
              <div class="val">${fmt(sumComida)}</div>
              <small>Solo la comida (suma de los totales)</small>
            </div>
            <div class="resumen-block">
              <div class="lbl">💰 TOTAL COBRADO</div>
              <div class="val">${fmt(sumTotal)}</div>
              <small>Lo que recibiste del cliente</small>
            </div>
          </div>`;
        }
      }
    } catch (e) {
      document.getElementById('dashOrders').innerHTML = `<em>Error: ${e.message}</em>`;
    }
  } else if (turno) {
    // Mesero/cajero/cocina: mostrar sus órdenes cobradas en el turno (vía detalle del turno)
    document.getElementById('dashOrdsTitle').textContent = 'Mis órdenes cobradas hoy';
    try {
      const det = await API.get('turnos/detalle', { id: turno.id });
      const ords = det.ordenes || [];
      document.getElementById('dashOrders').innerHTML = ords.length
        ? `<table class="data-table">
            <thead><tr><th>Hora</th><th>Tipo</th><th>Cliente / Mesa</th><th>Total</th><th>Pagos</th></tr></thead>
            <tbody>${ords.map(o => `<tr>
              <td><small>${(o.cerrada_at||'').slice(11,16)}</small></td>
              <td>${({local:'🍽 Local',llevar:'📦 Llevar',domicilio:'🛵 Domicilio',mostrador:'🥤 Mostrador'})[o.tipo] || o.tipo}</td>
              <td>${o.tipo==='local' ? `Mesa ${o.mesa_numero||'?'}` : (o.cliente_nombre||'')}</td>
              <td>${fmt(o.total)}</td>
              <td><small>${o.pagos||''}</small></td>
            </tr>`).join('')}</tbody></table>`
        : '<em style="color:var(--muted);">Aún no cobras órdenes en este turno.</em>';
    } catch (e) {
      document.getElementById('dashOrders').innerHTML = `<em>Error: ${e.message}</em>`;
    }
  }
}

/* ============ SISTEMA · TURNOS / ROSTER ============ */
async function initSysShifts() {
  // Cargar empleados para el selector
  SHIFT_USERS = await API.get('usuarios/list');
  const sel = document.getElementById('shUsuario');
  sel.innerHTML = SHIFT_USERS
    .filter(u => u.activo == 1 && u.rol !== 'admin')
    .map(u => `<option value="${u.id}">${u.nombre} · ${u.rol}</option>`).join('');

  document.getElementById('shApply').onclick = loadShifts;
  document.getElementById('shSave').onclick = saveShift;
  await loadShifts();
}
async function loadShifts() {
  const desde = document.getElementById('shDesde').value;
  const hasta = document.getElementById('shHasta').value;
  const rol = document.getElementById('shRol').value;
  SHIFTS = await API.get('turnos/list', { desde, hasta, rol });
  if (!SHIFTS.length) {
    document.getElementById('shList').innerHTML = '<div class="empty-state"><div class="ico">📅</div><h3>Sin turnos programados</h3><p>Pulsa "+ Programar turno" para asignar.</p></div>';
    return;
  }
  // Agrupar por fecha
  const byDate = {};
  SHIFTS.forEach(s => { (byDate[s.fecha] = byDate[s.fecha] || []).push(s); });
  document.getElementById('shList').innerHTML = Object.keys(byDate).sort().map(date => `
    <div class="panel" style="margin-bottom:12px;">
      <h3 style="font-size:14px;">${date} · ${byDate[date].length} turnos</h3>
      <table class="data-table" style="margin-top:8px;">
        <thead><tr><th>Empleado</th><th>Rol</th><th>Horario</th><th>Estado</th><th>Notas</th><th></th></tr></thead>
        <tbody>${byDate[date].map(s => `<tr>
          <td><b>${s.usuario}</b></td>
          <td><span class="role-tag role-${s.rol}">${s.rol}</span></td>
          <td>${s.hora_inicio.slice(0,5)}–${s.hora_fin.slice(0,5)}</td>
          <td><span class="shift-state shift-${s.estado}">${s.estado.replace('_',' ')}</span></td>
          <td><small>${s.notas||''}</small></td>
          <td>
            ${s.estado==='programado'?`<button class="link-btn" onclick='editShift(${JSON.stringify(s).replace(/"/g,"&quot;")})'>✏</button>
            <button class="link-btn" onclick="cancelShift(${s.id})">🚫</button>`:''}
            ${s.estado!=='cerrado' && s.estado!=='cancelado'?`<button class="link-btn" onclick="ausenteShift(${s.id})">⚠ ausente</button>`:''}
          </td>
        </tr>`).join('')}</tbody>
      </table>
    </div>`).join('');
}
window.openShiftModal = () => editShift({});
window.editShift = s => {
  document.getElementById('shTitle').textContent = s.id ? 'Editar turno' : 'Programar turno';
  setVal('shId', s.id || '');
  setVal('shUsuario', s.usuario_id || (SHIFT_USERS[0]?.id || ''));
  setVal('shFecha', s.fecha || localYMD());
  setVal('shInicio', s.hora_inicio ? s.hora_inicio.slice(0,5) : '18:00');
  setVal('shFin', s.hora_fin ? s.hora_fin.slice(0,5) : '23:00');
  setVal('shNotas', s.notas);
  openModal('shiftModal');
};
async function saveShift() {
  const id = document.getElementById('shId').value;
  const data = {
    usuario_id: +document.getElementById('shUsuario').value,
    fecha: document.getElementById('shFecha').value,
    hora_inicio: document.getElementById('shInicio').value,
    hora_fin: document.getElementById('shFin').value,
    notas: document.getElementById('shNotas').value,
  };
  if (id) data.id = +id;
  try {
    await API.post('turnos/save', data);
    closeModal('shiftModal'); toast('Turno guardado'); loadShifts();
  } catch (e) { toast(e.message, 'error'); }
}
window.cancelShift = async id => {
  if (!confirm('¿Cancelar este turno?')) return;
  try { await API.post('turnos/cancelar', { id }); toast('Cancelado'); loadShifts(); }
  catch (e) { toast(e.message, 'error'); }
};
window.ausenteShift = async id => {
  if (!confirm('¿Marcar al empleado como ausente?')) return;
  await API.post('turnos/ausente', { id });
  toast('Marcado ausente'); loadShifts();
};

/* ============ CIERRES CONSOLIDADOS (Fase F) ============ */
let CLOSURES_DATA = null;
let CLOSURES_TAB = 'empleado';

async function initClosures() {
  // Cargar empleados para el selector
  try {
    const emps = await API.get('reportes/empleados');
    document.getElementById('clsEmpleado').innerHTML =
      '<option value="">Todos</option>' +
      emps.map(e => `<option value="${e.id}">${e.nombre} · ${e.rol}</option>`).join('');
  } catch (e) { /* silent */ }

  document.getElementById('clsApply').onclick = loadClosures;
  const btnCierre = document.getElementById('clsCierreDia');
  if (btnCierre) btnCierre.onclick = doCierreDia;
  const btnZ = document.getElementById('clsPrintZ');
  if (btnZ) btnZ.onclick = printCorteZ;
  const btnCsv = document.getElementById('clsExportCsv');
  if (btnCsv) btnCsv.onclick = exportClosuresCSV;
  document.querySelectorAll('[data-cls-tab]').forEach(b => {
    b.onclick = () => {
      document.querySelectorAll('[data-cls-tab]').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      CLOSURES_TAB = b.dataset.clsTab;
      document.querySelectorAll('.cls-tab').forEach(d => d.style.display = 'none');
      document.getElementById('cls-' + CLOSURES_TAB).style.display = '';
    };
  });
  document.querySelectorAll('.range-presets button[data-preset]').forEach(b => {
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
      document.getElementById('clsDesde').value = desde;
      document.getElementById('clsHasta').value = hasta;
      loadClosures();
    };
  });

  await loadClosures();
}

/**
 * Cierre de día: cierra todos los turnos, libera mesas, finaliza comandas y deja
 * todo en cero. Si hay pedidos sin cobrar, pide confirmación para cancelarlos.
 */
async function doCierreDia() {
  if (!confirm('🔒 CERRAR DÍA\n\nEsto cerrará TODOS los turnos abiertos de todos los empleados, liberará todas las mesas y finalizará las comandas de cocina pendientes.\n\n¿Continuar?')) return;

  // #2 — Conteo de efectivo global (opcional)
  const efRaw = prompt('💵 Efectivo TOTAL contado en caja (opcional)\n\nDéjalo vacío y acepta para omitir la conciliación de efectivo:', '');
  if (efRaw === null) return; // canceló
  const payload = {};
  if (efRaw.trim() !== '') {
    const ef = +efRaw;
    if (isNaN(ef) || ef < 0) { toast('Monto de efectivo inválido', 'error'); return; }
    payload.efectivo_contado = ef;
  }

  let res;
  try { res = await API.post('turnos/cierre_dia', payload); }
  catch (e) { toast(e.message, 'error'); return; }

  // Hay órdenes sin cobrar → confirmar cancelación
  if (res.requiere_confirmacion) {
    let msg = `⚠ Hay ${res.n_abiertas} pedido(s) ABIERTOS SIN COBRAR.\n`;
    if (res.n_en_ruta > 0) {
      // #3 — Aviso de repartidores en ruta
      msg += `\n🛵 OJO: ${res.n_en_ruta} pedido(s) están EN CAMINO (un repartidor sigue en la calle).\n`;
    }
    const lista = res.ordenes_abiertas.map(o => {
      const quien = o.mesa_numero ? `Mesa ${o.mesa_numero}` : (o.cliente_nombre || o.tipo);
      const ruta = o.estado_entrega === 'en_camino' ? ' 🛵EN CAMINO' : '';
      return `• #${o.id} ${quien} · ${fmt(o.total)}${ruta}`;
    }).join('\n');
    msg += `\n${lista}\n\nSi cierras el día, se CANCELARÁN (no se cobran).\n¿Cancelarlos y cerrar el día de todos modos?`;
    if (!confirm(msg)) return;
    payload.forzar = true;
    try { res = await API.post('turnos/cierre_dia', payload); }
    catch (e) { toast(e.message, 'error'); return; }
  }

  const difTxt = (res.efectivo_contado !== null && res.efectivo_contado !== undefined)
    ? ` · efectivo ${fmt(res.efectivo_contado)} (dif ${fmt(res.diferencia)})` : '';
  toast(`✓ Día cerrado · ${res.turnos_cerrados} turno(s) · ${res.mesas_liberadas} mesa(s) · ${res.comandas_cerradas} comanda(s) · ${res.ordenes_canceladas} cancelada(s)${difTxt}`, 'success');
  loadClosures();
  if (typeof refreshKitchenBadge === 'function') refreshKitchenBadge();
}

async function loadClosures() {
  const desde = document.getElementById('clsDesde').value;
  const hasta = document.getElementById('clsHasta').value;
  const rol = document.getElementById('clsRol').value;
  const usuario_id = document.getElementById('clsEmpleado').value;
  const params = { desde, hasta };
  if (rol) params.rol = rol;
  if (usuario_id) params.usuario_id = usuario_id;

  try {
    CLOSURES_DATA = await API.get('reportes/cortes_consolidados', params);
  } catch (e) {
    toast(e.message, 'error');
    return;
  }
  const d = CLOSURES_DATA;
  document.getElementById('clsSub').textContent =
    `${d.rango.desde} → ${d.rango.hasta} · ${d.resumen.num_turnos} turnos cerrados · ${d.resumen.empleados} empleados`;

  renderCierreBanner();
  renderClosuresResumen();
  renderClosuresEmpleado();
  renderClosuresRepartidor();
  renderClosuresDia();
  renderClosuresTurnos();
  renderClosuresTipos();
}

function renderClosuresRepartidor() {
  const list = CLOSURES_DATA.por_repartidor || [];
  const wrap = document.getElementById('cls-repartidor');
  if (!wrap) return;
  if (!list.length) {
    wrap.innerHTML = '<div class="empty-state"><div class="ico">🛵</div>Sin entregas en este rango.</div>';
    return;
  }
  wrap.innerHTML = `<table class="data-table">
    <thead><tr>
      <th>Repartidor</th><th>Entregas</th><th>Monto entregado</th>
      <th>Efectivo cobrado</th><th>Envíos</th><th>Propinas</th><th>En camino ahora</th>
    </tr></thead>
    <tbody>${list.map(r => `<tr>
        <td><b>${r.nombre}</b></td>
        <td>${r.entregas}</td>
        <td><b>${fmt(r.monto)}</b></td>
        <td>${fmt(r.efectivo)}</td>
        <td>${fmt(r.envios)}</td>
        <td style="color:var(--green);">${fmt(r.propinas)}</td>
        <td>${r.en_camino > 0 ? `<span class="ronda-tag">🛵 ${r.en_camino}</span>` : '0'}</td>
      </tr>`).join('')}</tbody></table>`;
}

/** #5 — Banner del último cierre de día */
function renderCierreBanner() {
  const box = document.getElementById('clsBanner');
  if (!box) return;
  const uc = CLOSURES_DATA && CLOSURES_DATA.ultimo_cierre;
  if (!uc || !uc.at) { box.innerHTML = ''; return; }
  const dif = (uc.contado !== null && uc.contado !== undefined)
    ? ` · efectivo contado ${fmt(uc.contado)} <b>(dif ${fmt(uc.diff)})</b>` : '';
  box.innerHTML = `<div class="cierre-banner">🔒 Último cierre de día: <b>${uc.at}</b> por <b>${uc.por}</b> · ${uc.turnos} turno(s)${dif}</div>`;
}

/** #1 — Imprime el Corte Z (reporte consolidado) en formato ticket 80mm */
function printCorteZ() {
  if (!CLOSURES_DATA || !CLOSURES_DATA.resumen) { toast('Aplica un rango primero', 'error'); return; }
  const d = CLOSURES_DATA, r = d.resumen;
  const negocio = (window.APP_CFG && window.APP_CFG.negocio_nombre) || 'JACKS ROCK';
  const tipoLbl = { local: 'Comer aquí', llevar: 'Para llevar', domicilio: 'Domicilio', mostrador: 'Mostrador', web: 'Web' };
  const ahora = new Date().toLocaleString('es-MX');
  const html = `
    <div class="ctr"><h2>${negocio}</h2></div>
    <div class="ctr">*** CORTE Z ***</div>
    <div class="ctr small">${d.rango.desde} a ${d.rango.hasta}</div>
    <hr>
    <div class="li"><span>Turnos cerrados</span><span>${r.num_turnos}</span></div>
    <div class="li"><span>Empleados</span><span>${r.empleados}</span></div>
    <div class="li"><span>Órdenes</span><span>${r.total_ordenes}</span></div>
    <hr>
    <div class="li big"><span>VENTAS</span><span>${fmt(r.total_ventas)}</span></div>
    <div class="li"><span>Efectivo</span><span>${fmt(r.total_efectivo)}</span></div>
    <div class="li"><span>Tarjeta</span><span>${fmt(r.total_tarjeta)}</span></div>
    <div class="li"><span>Transferencia</span><span>${fmt(r.total_transfer)}</span></div>
    <div class="li"><span>Propinas</span><span>${fmt(r.total_propinas)}</span></div>
    <div class="li"><span>Envíos</span><span>${fmt(r.total_envios)}</span></div>
    <div class="li"><span>Diferencia</span><span>${fmt(r.diferencia_acumulada)}</span></div>
    <hr>
    <div class="ctr"><b>POR EMPLEADO</b></div>
    ${d.por_empleado.map(e => `
      <div class="li"><span>${e.nombre} (${e.rol})</span><span>${fmt(e.total_ventas)}</span></div>
      <div class="ticket-extras">ef ${fmt(e.total_efectivo)} · tj ${fmt(e.total_tarjeta)} · tr ${fmt(e.total_transfer)} · prop ${fmt(e.total_propinas)} · dif ${fmt(e.diferencia)}</div>
    `).join('')}
    <hr>
    <div class="ctr"><b>POR TIPO DE PEDIDO</b></div>
    ${(d.por_tipo || []).map(t => `<div class="li"><span>${tipoLbl[t.tipo] || t.tipo}</span><span>${fmt(t.total)} (${t.num})</span></div>`).join('')}
    ${(d.ultimo_cierre && d.ultimo_cierre.contado != null) ? `<hr>
      <div class="ctr"><b>CONCILIACIÓN EFECTIVO</b></div>
      <div class="li"><span>Esperado</span><span>${fmt(d.ultimo_cierre.esperado)}</span></div>
      <div class="li"><span>Contado</span><span>${fmt(d.ultimo_cierre.contado)}</span></div>
      <div class="li big"><span>Diferencia</span><span>${fmt(d.ultimo_cierre.diff)}</span></div>` : ''}
    <hr>
    <div class="ctr small">Generado ${ahora}</div>
    <div class="ctr">_______________________</div>
    <div class="ctr">Firma responsable</div>`;
  document.getElementById('printArea').innerHTML = `<div class="comanda">${html}</div>`;
  window.print();
}

/** #4 — Exporta el consolidado por empleado a CSV (Excel) */
function exportClosuresCSV() {
  if (!CLOSURES_DATA || !CLOSURES_DATA.por_empleado) { toast('Aplica un rango primero', 'error'); return; }
  const d = CLOSURES_DATA, r = d.resumen;
  const rows = [['Empleado','Rol','Turnos','Ordenes','Ventas','Efectivo','Tarjeta','Transfer','Propinas','Envios','Diferencia']];
  d.por_empleado.forEach(e => rows.push([
    e.nombre, e.rol, e.num_turnos, e.num_ordenes,
    e.total_ventas, e.total_efectivo, e.total_tarjeta, e.total_transfer,
    e.total_propinas, e.total_envios, e.diferencia,
  ]));
  rows.push(['TOTAL', '', r.num_turnos, r.total_ordenes, r.total_ventas, r.total_efectivo,
             r.total_tarjeta, r.total_transfer, r.total_propinas, r.total_envios, r.diferencia_acumulada]);
  const csv = rows.map(row => row.map(c => {
    const s = String(c ?? '');
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  }).join(',')).join('\r\n');
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `cierre_${d.rango.desde}_a_${d.rango.hasta}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
  toast('CSV exportado', 'success');
}

function renderClosuresResumen() {
  const r = CLOSURES_DATA.resumen;
  const diffClass = +r.diferencia_acumulada < 0 ? 'neg' : (+r.diferencia_acumulada > 0 ? 'pos' : '');
  document.getElementById('clsResumen').innerHTML = `
    <div class="cash-card highlight">
      <div class="lbl">💰 Ventas totales</div>
      <div class="val">${fmt(r.total_ventas)}</div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;">${r.total_ordenes} órdenes</div>
    </div>
    <div class="cash-card"><div class="lbl">💵 Efectivo neto</div><div class="val">${fmt(r.total_efectivo)}</div></div>
    <div class="cash-card"><div class="lbl">💳 Tarjeta</div><div class="val">${fmt(r.total_tarjeta)}</div></div>
    <div class="cash-card"><div class="lbl">🏦 Transfer</div><div class="val">${fmt(r.total_transfer)}</div></div>
    <div class="cash-card">
      <div class="lbl">🪙 Propinas</div>
      <div class="val" style="color:var(--green);">${fmt(r.total_propinas)}</div>
    </div>
    <div class="cash-card">
      <div class="lbl">🛵 Envíos</div>
      <div class="val">${fmt(r.total_envios)}</div>
    </div>
    <div class="cash-card">
      <div class="lbl">⚖ Diferencia acumulada</div>
      <div class="val ${diffClass}">${fmt(r.diferencia_acumulada)}</div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;">${r.turnos_faltante} faltante · ${r.turnos_sobrante} sobrante</div>
    </div>
  `;
}

function renderClosuresEmpleado() {
  const list = CLOSURES_DATA.por_empleado;
  const wrap = document.getElementById('cls-empleado');
  if (!list.length) {
    wrap.innerHTML = '<div class="empty-state"><div class="ico">👥</div>Sin cortes en este rango.</div>';
    return;
  }
  wrap.innerHTML = `<table class="data-table">
    <thead><tr>
      <th>Empleado</th><th>Rol</th><th>Turnos</th><th>Órdenes</th>
      <th>Ventas</th><th>Efectivo</th><th>Tarjeta</th><th>Transfer</th>
      <th>Propinas</th><th>Diferencia</th>
    </tr></thead>
    <tbody>${list.map(e => {
      const cls = +e.diferencia < 0 ? 'neg' : (+e.diferencia > 0 ? 'pos' : '');
      return `<tr>
        <td><b>${e.nombre}</b></td>
        <td><span class="role-tag role-${e.rol}">${e.rol}</span></td>
        <td>${e.num_turnos}</td>
        <td>${e.num_ordenes}</td>
        <td><b>${fmt(e.total_ventas)}</b></td>
        <td>${fmt(e.total_efectivo)}</td>
        <td>${fmt(e.total_tarjeta)}</td>
        <td>${fmt(e.total_transfer)}</td>
        <td style="color:var(--green);">${fmt(e.total_propinas)}</td>
        <td class="${cls}"><b>${fmt(e.diferencia)}</b></td>
      </tr>`;
    }).join('')}</tbody></table>`;
}

function renderClosuresDia() {
  const list = CLOSURES_DATA.por_dia;
  const wrap = document.getElementById('cls-dia');
  if (!list.length) {
    wrap.innerHTML = '<div class="empty-state"><div class="ico">📅</div>Sin datos.</div>';
    return;
  }
  const max = Math.max(...list.map(x => +x.total_ventas)) || 1;
  wrap.innerHTML = `
    <div class="panel" style="margin-bottom:14px;">
      <h3 style="font-size:14px;">Ventas por día (gráfica)</h3>
      ${list.map(d => {
        const pct = (+d.total_ventas / max) * 100;
        return `<div class="rpt-bar-row">
          <span class="rpt-bar-lbl">${d.fecha}</span>
          <div class="rpt-bar"><div class="rpt-bar-fill" style="width:${pct}%"></div></div>
          <span class="rpt-bar-val"><b>${fmt(d.total_ventas)}</b> · ${d.num_ordenes} ord · ${d.empleados} emp</span>
        </div>`;
      }).join('')}
    </div>
    <table class="data-table">
      <thead><tr><th>Fecha</th><th>Turnos</th><th>Empleados</th><th>Órdenes</th><th>Ventas</th><th>Efectivo</th><th>Propinas</th><th>Diferencia</th></tr></thead>
      <tbody>${list.map(d => {
        const cls = +d.diferencia < 0 ? 'neg' : (+d.diferencia > 0 ? 'pos' : '');
        return `<tr>
          <td><b>${d.fecha}</b></td>
          <td>${d.num_turnos}</td>
          <td>${d.empleados}</td>
          <td>${d.num_ordenes}</td>
          <td><b>${fmt(d.total_ventas)}</b></td>
          <td>${fmt(d.total_efectivo)}</td>
          <td style="color:var(--green);">${fmt(d.total_propinas)}</td>
          <td class="${cls}">${fmt(d.diferencia)}</td>
        </tr>`;
      }).join('')}</tbody>
    </table>`;
}

function renderClosuresTurnos() {
  const list = CLOSURES_DATA.turnos;
  const wrap = document.getElementById('cls-turnos');
  if (!list.length) {
    wrap.innerHTML = '<div class="empty-state"><div class="ico">📋</div>Sin turnos cerrados.</div>';
    return;
  }
  wrap.innerHTML = `<table class="data-table">
    <thead><tr><th>Fecha</th><th>Hora</th><th>Empleado</th><th>Rol</th><th>Órdenes</th><th>Ventas</th><th>Esperado</th><th>Contado</th><th>Diferencia</th><th></th></tr></thead>
    <tbody>${list.map(t => {
      const cls = +t.diferencia < 0 ? 'neg' : (+t.diferencia > 0 ? 'pos' : '');
      return `<tr>
        <td>${t.fecha}</td>
        <td><small>${t.hora_inicio.slice(0,5)}–${t.hora_fin.slice(0,5)}</small></td>
        <td><b>${t.usuario}</b></td>
        <td><span class="role-tag role-${t.rol}">${t.rol}</span></td>
        <td>${t.num_ordenes}</td>
        <td>${fmt(t.total_ventas)}</td>
        <td>${fmt(t.efectivo_esperado)}</td>
        <td>${fmt(t.efectivo_contado)}</td>
        <td class="${cls}"><b>${fmt(t.diferencia)}</b></td>
        <td><button class="link-btn" onclick="showCorteDetalle(${t.id})">👁 Ver</button></td>
      </tr>`;
    }).join('')}</tbody></table>`;
}

function renderClosuresTipos() {
  const list = CLOSURES_DATA.por_tipo;
  const wrap = document.getElementById('cls-tipos');
  if (!list.length) {
    wrap.innerHTML = '<div class="empty-state"><div class="ico">🛒</div>Sin pedidos.</div>';
    return;
  }
  const total = list.reduce((s, x) => s + +x.total, 0) || 1;
  const labels = { local: '🍽 Comer aquí', llevar: '📦 Para llevar', domicilio: '🛵 A domicilio' };
  wrap.innerHTML = `<div class="panel">
    <h3 style="font-size:14px;margin-bottom:10px;">Distribución por tipo</h3>
    ${list.map(t => {
      const pct = (+t.total / total * 100).toFixed(1);
      return `<div class="rpt-bar-row">
        <span class="rpt-bar-lbl">${labels[t.tipo] || t.tipo}</span>
        <div class="rpt-bar"><div class="rpt-bar-fill" style="width:${pct}%"></div></div>
        <span class="rpt-bar-val"><b>${fmt(t.total)}</b> · ${t.num} órdenes · ${pct}%</span>
      </div>`;
    }).join('')}
  </div>`;
}

window.showCorteDetalle = async (id) => {
  try {
    const t = await API.get('reportes/turno_detalle', { id });
    document.getElementById('corteTitle').textContent = `Corte de ${t.usuario}`;
    document.getElementById('corteSub').textContent =
      `${t.fecha} · ${t.hora_inicio.slice(0,5)}–${t.hora_fin.slice(0,5)} · cerrado ${t.cerrado_at||'-'}`;

    const cls = +t.diferencia < 0 ? 'neg' : (+t.diferencia > 0 ? 'pos' : '');
    document.getElementById('corteBody').innerHTML = `
      <div class="cash-grid" style="margin-bottom:14px;">
        <div class="cash-card"><div class="lbl">Fondo inicial</div><div class="val">${fmt(t.fondo_inicial)}</div></div>
        <div class="cash-card"><div class="lbl">Efectivo esperado</div><div class="val">${fmt(t.efectivo_esperado)}</div></div>
        <div class="cash-card"><div class="lbl">Efectivo contado</div><div class="val">${fmt(t.efectivo_contado)}</div></div>
        <div class="cash-card"><div class="lbl">Diferencia</div><div class="val ${cls}"><b>${fmt(t.diferencia)}</b></div></div>
        <div class="cash-card"><div class="lbl">Total vendido</div><div class="val">${fmt(t.total_ventas)}</div></div>
        <div class="cash-card"><div class="lbl">Tarjeta</div><div class="val">${fmt(t.total_tarjeta)}</div></div>
        <div class="cash-card"><div class="lbl">Transferencia</div><div class="val">${fmt(t.total_transfer)}</div></div>
        <div class="cash-card"><div class="lbl">Propinas</div><div class="val" style="color:var(--green);">${fmt(t.total_propinas)}</div></div>
      </div>
      ${t.observaciones_cierre ? `<div class="aviso" style="background:var(--panel-2);padding:10px;border-radius:8px;margin-bottom:14px;font-size:13px;color:var(--muted);">📝 ${t.observaciones_cierre}</div>` : ''}
      <h4 style="font-size:13px;margin-bottom:6px;color:var(--accent-2);">${(t.ordenes||[]).length} órdenes cobradas en este turno</h4>
      ${(t.ordenes||[]).length ? `<table class="data-table">
        <thead><tr><th>Hora</th><th>Tipo</th><th>Cliente/Mesa</th><th>Total</th><th>Pagos</th></tr></thead>
        <tbody>${t.ordenes.map(o => `<tr>
          <td><small>${(o.cerrada_at||'').slice(11,16)}</small></td>
          <td>${({local:'🍽',llevar:'📦',domicilio:'🛵',mostrador:'🥤'})[o.tipo]||o.tipo}</td>
          <td>${o.tipo==='local' ? 'Mesa '+(o.mesa_numero||'?') : (o.cliente_nombre||'-')}</td>
          <td>${fmt(o.total)}</td>
          <td><small>${o.pagos||''}</small></td>
        </tr>`).join('')}</tbody>
      </table>` : '<em>Sin órdenes cobradas.</em>'}`;

    document.getElementById('cortePrint').onclick = () => printCorte(t);
    openModal('corteModal');
  } catch (e) { toast(e.message, 'error'); }
};

/* ============ TICKETS · VENTAS COBRADAS ============ */
let TICKETS_DATA = null;

async function initTickets() {
  // Cargar empleados para selector
  try {
    const emps = await API.get('reportes/empleados');
    document.getElementById('ticEmp').innerHTML = '<option value="">Todos</option>' +
      emps.map(e => `<option value="${e.id}">${e.nombre}</option>`).join('');
  } catch(e) {}

  document.getElementById('ticApply').onclick = loadTickets;
  document.getElementById('ticSearch').oninput = () => {
    clearTimeout(window._ticT);
    window._ticT = setTimeout(loadTickets, 400);
  };
  document.querySelectorAll('.range-presets button[data-preset]').forEach(b => {
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
      document.getElementById('ticDesde').value = desde;
      document.getElementById('ticHasta').value = hasta;
      loadTickets();
    };
  });
  await loadTickets();
}

async function loadTickets() {
  const params = {
    desde: document.getElementById('ticDesde').value,
    hasta: document.getElementById('ticHasta').value,
  };
  const tipo = document.getElementById('ticTipo').value;
  const emp  = document.getElementById('ticEmp').value;
  const q    = document.getElementById('ticSearch').value.trim();
  if (tipo) params.tipo = tipo;
  if (emp)  params.usuario_id = emp;
  if (q)    params.q = q;

  try { TICKETS_DATA = await API.get('tickets/list', params); }
  catch (e) { toast(e.message, 'error'); return; }

  const t = TICKETS_DATA;
  document.getElementById('ticSub').textContent = `${t.totales.n} tickets · ${fmt(t.totales.total)} total`;
  document.querySelector('#ticResumen .val').textContent = fmt(t.totales.total);

  if (!t.tickets.length) {
    document.getElementById('ticList').innerHTML = '<div class="empty-state"><div class="ico">🧾</div>Sin tickets en el rango.</div>';
    return;
  }

  document.getElementById('ticList').innerHTML = `<table class="data-table">
    <thead><tr>
      <th>Ticket</th><th>Fecha/hora</th><th>Tipo</th><th>Cliente/Mesa</th>
      <th>Pagos</th><th>Total</th><th>Atendió</th><th></th>
    </tr></thead>
    <tbody>${t.tickets.map(o => {
      const tipoIcon = ({local:'🍽',llevar:'📦',domicilio:'🛵',mostrador:'🥤'})[o.tipo] || o.tipo;
      const where = o.tipo === 'local'
        ? `Mesa ${String(o.mesa_numero||'?').padStart(2,'0')}`
        : (o.cliente_nombre || '—');
      const pagosTxt = o.pagos_arr.map(p => `${p.metodo} ${fmt(p.monto)}`).join(' · ');
      return `<tr>
        <td><b>V-${String(o.id).padStart(4,'0')}</b></td>
        <td><small>${o.cerrada_at}</small></td>
        <td>${tipoIcon}</td>
        <td>${where}</td>
        <td><small>${pagosTxt}</small></td>
        <td><b>${fmt(o.total)}</b></td>
        <td><small>${o.atendio||'-'}</small></td>
        <td>
          <button class="link-btn" onclick="reprintTicket(${o.id})">🖨 Ticket</button>
          <button class="link-btn" onclick="reprintComandas(${o.id})">🍳 Comanda</button>
          <button class="link-btn" onclick="viewTicket(${o.id})">👁 Ver</button>
        </td>
      </tr>`;
    }).join('')}</tbody></table>`;
}

window.viewTicket = async function(id) {
  try {
    const t = await API.get('tickets/detalle', { id });
    showTicketDetailModal(t);
  } catch (e) { toast(e.message, 'error'); }
};

window.reprintTicket = async function(id) {
  try {
    // Reimprime en la impresora TÉRMICA del servidor (no el diálogo del navegador,
    // que no llega a la impresora de red).
    const r = await API.post('ordenes/reprint_ticket', { orden_id: id });
    if (r && r.impreso) toast('🖨 Ticket reenviado a la impresora de caja', 'success');
    else toast('No se pudo imprimir — revisa la impresora de caja', 'error');
  } catch (e) {
    console.error('reprintTicket error:', e);
    toast('No se pudo reimprimir: ' + e.message, 'error');
  }
};

// Reimprime las COMANDAS de cocina (última ronda) de una orden a las impresoras
// de cada cocina. Reusa el reprint_comanda ya probado.
window.reprintComandas = async function(orden_id) {
  try {
    const r = await API.get('ordenes/orden_comandas', { orden_id });
    const comandas = (r.comandas || []).filter(c => +c.ronda === +r.ultima_ronda);
    if (!comandas.length) { toast('Esta orden no tiene comandas', 'info'); return; }
    let ok = 0, fail = 0;
    for (const c of comandas) {
      try {
        const res = await API.post('ordenes/reprint_comanda', { comanda_id: c.id });
        if (res && res.impreso) ok++; else fail++;
      } catch (e) { fail++; }
    }
    if (fail === 0) toast(`🍳 Comanda(s) reenviada(s) a cocina (${ok})`, 'success');
    else toast(`Reimpreso ${ok}, falló ${fail} — revisa la impresora`, 'error');
  } catch (e) {
    toast('No se pudo reimprimir la comanda: ' + e.message, 'error');
  }
};

function showTicketDetailModal(o) {
  const items = (o.items || []).map(i => {
    const extras = +i.precio_extra || 0;
    const cancQty = +i.cantidad_cancelada || 0;
    const effQty  = Math.max(0, i.cantidad - cancQty);
    const linea = (+i.precio + extras) * effQty;
    let h = '';
    if (effQty > 0) {
      h += `<div class="li">
        <span>${effQty}× ${i.nombre} ${extras > 0 ? `<small>(+${fmt(extras)})</small>` : ''}
          <button class="link-btn tic-cancel-btn" style="font-size:11px;color:var(--red);" data-item="${i.id}" data-disp="${effQty}">✗ cancelar</button>
        </span>
        <span>${fmt(linea)}</span>
      </div>`;
    }
    if (cancQty > 0) {
      h += `<div class="li" style="color:var(--muted);"><span><s>${cancQty}× ${i.nombre} CANCELADO</s>${i.motivo_cancel?` · <small>${i.motivo_cancel}</small>`:''}</span><span><s>—</s></span></div>`;
    }
    return h;
  }).join('');
  const pagos = (o.pagos || []).map(p =>
    `<div class="li"><span>${p.icono||''} ${p.nombre}</span><span>${fmt(p.monto)}</span></div>`).join('');

  const html = `
    <div class="modal-bg open" id="ticDetailModal" style="z-index:200;">
      <div class="modal" style="max-width:500px;">
        <h3>Ticket V-${String(o.id).padStart(4,'0')}</h3>
        <div class="sub">${o.cerrada_at} · ${o.atendio||''}</div>
        <div style="background:var(--panel-2);padding:14px;border-radius:8px;margin:10px 0;font-family:'Courier New',monospace;font-size:13px;">
          ${o.cliente_nombre ? `<div><b>Cliente:</b> ${o.cliente_nombre}</div>` : ''}
          ${o.mesa_numero ? `<div><b>Mesa:</b> ${o.mesa_numero}</div>` : ''}
          <hr style="border:none;border-top:1px dashed var(--border);margin:8px 0;">
          ${items}
          <hr style="border:none;border-top:1px dashed var(--border);margin:8px 0;">
          ${(+o.iva > 0) ? `<div class="li"><span>Subtotal</span><span>${fmt(o.subtotal)}</span></div><div class="li"><span>IVA</span><span>${fmt(o.iva)}</span></div>` : ''}
          ${(+o.costo_envio > 0) ? `<div class="li"><span>Envío</span><span>${fmt(o.costo_envio)}</span></div>` : ''}
          ${(+o.propina > 0) ? `<div class="li"><span>Propina</span><span>${fmt(o.propina)}</span></div>` : ''}
          <div class="li" style="font-size:16px;font-weight:bold;"><span>TOTAL</span><span>${fmt(o.total)}</span></div>
          <hr style="border:none;border-top:1px dashed var(--border);margin:8px 0;">
          <div style="text-align:center;"><b>Formas de pago</b></div>
          ${pagos}
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" onclick="document.getElementById('ticDetailModal').remove()">Cerrar</button>
          <button class="btn btn-primary" onclick="reprintTicket(${o.id})">🖨 Reimprimir</button>
        </div>
      </div>
    </div>`;
  const tmp = document.createElement('div');
  tmp.innerHTML = html;
  const modalEl = tmp.firstElementChild;
  document.body.appendChild(modalEl);

  // Bind de los botones "✗ cancelar" (sin onclick inline para no romper el HTML
  // cuando el nombre del producto trae comillas u otros caracteres).
  modalEl.querySelectorAll('.tic-cancel-btn').forEach(b => {
    b.onclick = () => {
      const itemId = +b.dataset.item;
      const disp   = +b.dataset.disp;
      const it = (o.items || []).find(x => +x.id === itemId);
      cancelTicketItem(itemId, it ? it.nombre : 'producto', disp, o.id);
    };
  });
}

/**
 * Cancela (total o parcial) un item de un ticket YA COBRADO.
 * Requiere admin (o credenciales de admin). El item no desaparece: queda
 * tachado y el total se ajusta. Pensado para devoluciones al cierre de cuenta.
 */
window.cancelTicketItem = async function(itemId, nombre, disp, ordenId) {
  let cantidad = disp;
  if (disp > 1) {
    const r = prompt(`Cancelar "${nombre}" (ya cobrado).\nHay ${disp}. ¿Cuántos cancelar? (1 a ${disp})`, String(disp));
    if (r === null) return;
    cantidad = parseInt(r, 10);
    if (!cantidad || cantidad < 1 || cantidad > disp) { toast(`Cantidad inválida (1 a ${disp})`, 'error'); return; }
  }
  const motivo = prompt(`Motivo de cancelar ${cantidad}× "${nombre}":`);
  if (!motivo || motivo.trim().length < 3) { if (motivo !== null) toast('Motivo requerido', 'error'); return; }

  async function attempt(adminUser, adminPass) {
    const data = { item_id: itemId, motivo: motivo.trim(), cantidad };
    if (adminUser) { data.admin_user = adminUser; data.admin_pass = adminPass; }
    try {
      await API.post('ordenes/cancel_item', data);
      toast('Item cancelado · total ajustado', 'success');
      document.getElementById('ticDetailModal')?.remove();
      viewTicket(ordenId);   // recargar detalle con el ajuste
      loadTickets();         // refrescar la lista/totales
    } catch (e) {
      if ((e.message||'').includes('administrador') || (e.message||'').includes('credenciales')) {
        const u = prompt('Autorización requerida. Usuario admin:');
        if (!u) return;
        const p = prompt('Contraseña admin:');
        if (!p) return;
        attempt(u, p);
      } else { toast(e.message, 'error'); }
    }
  }
  attempt(null, null);
};

function printCorte(t) {
  const html = `
    <div class="ctr"><h2>JACKS ROCK</h2></div>
    <div class="ctr">CORTE DE TURNO</div>
    <hr>
    <div class="li"><b>Empleado:</b><span>${t.usuario}</span></div>
    <div class="li"><b>Rol:</b><span>${t.rol}</span></div>
    <div class="li"><b>Fecha:</b><span>${t.fecha}</span></div>
    <div class="li"><b>Horario:</b><span>${t.hora_inicio.slice(0,5)}–${t.hora_fin.slice(0,5)}</span></div>
    <div class="li"><b>Cerrado:</b><span>${t.cerrado_at||'-'}</span></div>
    <hr>
    <div class="ctr"><b>RESUMEN</b></div>
    <div class="li"><span>Fondo inicial</span><span>${fmt(t.fondo_inicial)}</span></div>
    <div class="li"><span>+ Efectivo recibido</span><span>${fmt(+t.efectivo_esperado - +t.fondo_inicial)}</span></div>
    <div class="li big"><span>Efectivo esperado</span><span>${fmt(t.efectivo_esperado)}</span></div>
    <div class="li"><span>Efectivo contado</span><span>${fmt(t.efectivo_contado)}</span></div>
    <div class="li big"><span>DIFERENCIA</span><span>${fmt(t.diferencia)}</span></div>
    <hr>
    <div class="li"><span>Tarjeta</span><span>${fmt(t.total_tarjeta)}</span></div>
    <div class="li"><span>Transferencia</span><span>${fmt(t.total_transfer)}</span></div>
    <div class="li"><span>Otros</span><span>${fmt(t.total_otros)}</span></div>
    <hr>
    <div class="li big"><span>TOTAL VENDIDO</span><span>${fmt(t.total_ventas)}</span></div>
    <div class="li"><span># Órdenes</span><span>${t.num_ordenes}</span></div>
    <div class="li"><span>Propinas</span><span>${fmt(t.total_propinas)}</span></div>
    ${+t.total_envios > 0 ? `<div class="li"><span>Envíos</span><span>${fmt(t.total_envios)}</span></div>` : ''}
    <hr>
    ${t.observaciones_cierre ? `<div><b>Obs:</b> ${t.observaciones_cierre}</div><hr>` : ''}
    <div class="ctr">_________________</div>
    <div class="ctr">Firma del empleado</div>
    <div class="ctr">${t.usuario}</div>`;
  document.getElementById('printArea').innerHTML = `<div class="comanda">${html}</div>`;
  window.print();
}

/* ============ PEDIDOS PARA LLEVAR ============ */
async function initTakeout() {
  document.querySelectorAll('[data-tout-filter]').forEach(b => {
    b.onclick = () => {
      document.querySelectorAll('[data-tout-filter]').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      TAKEOUT_FILTER = b.dataset.toutFilter;
      loadTakeout();
    };
  });
  await loadTakeout();
  if (window._toutInterval) clearInterval(window._toutInterval);
  window._toutInterval = setInterval(loadTakeout, 15000);
}

async function loadTakeout() {
  try { TAKEOUT_ORDERS = await API.get('takeout/list', { filter: TAKEOUT_FILTER }); }
  catch (e) { TAKEOUT_ORDERS = []; }

  const wrap = document.getElementById('toutGrid');
  document.getElementById('toutSub').textContent = `${TAKEOUT_ORDERS.length} pedidos · cliente pasa a recoger`;

  if (!TAKEOUT_ORDERS.length) {
    wrap.innerHTML = `<div class="empty-state">
      <div class="ico">📦</div>
      <h3>Sin pedidos para llevar en este filtro</h3>
      <p>Crea uno desde <b>Mesas → 📦 + Nuevo pedido para llevar</b></p>
    </div>`;
    return;
  }

  wrap.innerHTML = `<div class="dlv-grid">${TAKEOUT_ORDERS.map(o => renderTakeoutCard(o)).join('')}</div>`;
  wrap.querySelectorAll('[data-tout-action]').forEach(b => {
    b.onclick = () => takeoutAction(b.dataset.toutAction, +b.dataset.id);
  });
}

function renderTakeoutCard(o) {
  const elapsed = Math.floor((Date.now() - new Date(o.abierta_at).getTime()) / 60000);
  const isCobrado = o.estado === 'cobrada';

  let cocinaEstado = '<span style="color:var(--muted);">sin enviar</span>';
  if (o.comandas_listas > 0) cocinaEstado = '<b style="color:var(--green);">🍽 LISTA</b>';
  else if (o.comandas_en_curso > 0) cocinaEstado = '<span style="color:var(--blue);">preparando…</span>';

  let actions = '';
  if (!isCobrado) {
    actions += `<a class="btn btn-ghost" href="${APP.sysUrl}/index.php?module=orders&orden=${o.id}">✏ Editar / Cobrar</a>`;
    if (['admin','cajero','mesero'].includes(APP.user?.rol)) {
      actions += `<button class="btn btn-ghost" data-tout-action="to-domicilio" data-id="${o.id}" style="color:var(--blue);">🔄 Pasar a domicilio</button>`;
      actions += `<button class="btn btn-ghost" data-tout-action="to-local" data-id="${o.id}" style="color:var(--accent-2);">🔄 Pasar a mesa</button>`;
    }
  } else {
    actions += `<small style="color:var(--green);">✓ Cobrado a las ${(o.cerrada_at||'').slice(11,16)}</small>`;
  }

  const estadoColor = isCobrado ? 'entregada' : (o.comandas_listas > 0 ? 'lista' : 'asignada');

  return `<div class="dlv-card dlv-${estadoColor}">
    <div class="dlv-head">
      <div>
        <h4>${o.cliente_nombre || 'Sin nombre'}</h4>
        <small>📞 ${o.cliente_telefono || '—'}</small>
      </div>
      <div class="dlv-status">
        <span class="dlv-tag dlv-${estadoColor}">${isCobrado ? 'Cobrado' : 'En proceso'}</span>
        <small>#${o.id} · hace ${elapsed}m</small>
      </div>
    </div>
    <div class="dlv-body">
      ${o.hora_pickup ? `<div class="dlv-row"><span class="lbl">🕒 Hora recoge</span><span>${o.hora_pickup.slice(0,5)}</span></div>` : ''}
      <div class="dlv-row"><span class="lbl">📋 Items</span><span>${o.num_items || 0}</span></div>
      <div class="dlv-row"><span class="lbl">🍳 Cocina</span><span>${cocinaEstado}</span></div>
      <div class="dlv-row"><span class="lbl">💰 Total</span><span><b>${fmt(o.total)}</b></span></div>
    </div>
    <div class="dlv-actions">${actions}</div>
  </div>`;
}

async function takeoutAction(action, id) {
  try {
    const o = TAKEOUT_ORDERS.find(x => x.id === id);

    if (action === 'to-domicilio') {
      // Pide datos de envío
      const dir = prompt('Dirección de entrega:');
      if (!dir || dir.trim().length < 5) {
        if (dir !== null) toast('Dirección requerida', 'error');
        return;
      }
      const tel = o?.cliente_telefono || prompt('Teléfono del cliente:');
      if (!tel) { toast('Teléfono requerido', 'error'); return; }
      const envio = prompt('Costo de envío:', '30');
      if (envio === null) return;
      await API.post('ordenes/cambiar_tipo', {
        orden_id: id, tipo: 'domicilio',
        cliente_nombre: o?.cliente_nombre || 'Cliente',
        cliente_telefono: tel,
        cliente_direccion: dir.trim(),
        costo_envio: +envio || 30,
      });
      toast('✓ Cambiado a domicilio · ahora aparece en 🛵 Domicilios', 'success');
      loadTakeout();
    }
    else if (action === 'to-local') {
      // Necesita mesa libre
      const mesas = await API.get('mesas/list');
      const libres = mesas.filter(m => m.estado === 'libre');
      if (!libres.length) { toast('No hay mesas libres', 'error'); return; }
      const opts = libres.map(m => `M${String(m.numero).padStart(2,'0')} · ${m.capacidad}p · ${m.zona}`).join('\n');
      const num = prompt(`Mesa libre (escribe el número):\n${opts}`);
      if (!num) return;
      const mesa = libres.find(m => m.numero == +num);
      if (!mesa) { toast('Mesa no encontrada o no libre', 'error'); return; }
      await API.post('ordenes/cambiar_tipo', {
        orden_id: id, tipo: 'local', mesa_id: mesa.id
      });
      toast(`✓ Cliente sentado en Mesa ${num}`, 'success');
      // Redirigir a la orden en su nueva mesa
      location.href = `${APP.sysUrl}/index.php?module=orders&mesa=${mesa.id}`;
    }
  } catch (e) { toast(e.message, 'error'); }
}
