/**
 * Cliente HTTP del API REST
 */
/**
 * Helper: parsea la respuesta como JSON; si no es JSON válido
 * (típicamente un warning PHP que se coló), muestra el body real en
 * consola y lanza un error legible.
 */
async function _parseResponse(res, route) {
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    console.error(`[API ${route}] Respuesta NO es JSON válido. Status ${res.status}.\nCuerpo recibido:\n`, text);
    // si parece HTML de error PHP, intentar extraer el mensaje
    const m = text.match(/<b>(?:Warning|Notice|Fatal error|Parse error)<\/b>:\s*([^<]+)/i);
    const detail = m ? m[1].trim() : text.slice(0, 200);
    throw new Error(`Respuesta inválida del servidor: ${detail}`);
  }
}

/**
 * Si el servidor responde "requires_turno" (no hay turno abierto), mandamos al
 * usuario a la pantalla de Mi turno para que lo abra.
 */
function _handleNoTurno(data) {
  if (data && data.requires_turno) {
    location.href = window.APP.sysUrl + '/index.php?module=my_shift&abrir=1';
  }
}

const API = {
  async get(route, params = {}) {
    const url = new URL(window.APP.api + 'index.php', window.location.origin);
    url.searchParams.set('r', route);
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
    const res = await fetch(url);
    const data = await _parseResponse(res, route);
    if (!res.ok) { _handleNoTurno(data); throw new Error(data.error || 'Error ' + res.status); }
    return data;
  },
  async post(route, body = {}) {
    const url = new URL(window.APP.api + 'index.php', window.location.origin);
    url.searchParams.set('r', route);
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
    const data = await _parseResponse(res, route);
    if (!res.ok) { _handleNoTurno(data); throw new Error(data.error || 'Error ' + res.status); }
    return data;
  }
};

const fmt = n => '$' + Number(n || 0).toFixed(2);

// Fecha YYYY-MM-DD en la ZONA LOCAL del navegador (México, UTC−6).
// NO usar Date.toISOString() para filtros: eso da la fecha en UTC y, como el
// negocio opera de noche, adelanta un día (p.ej. "Ayer" salía en un día sin datos).
const localYMD = (d = new Date()) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function toast(msg, type = 'success') {
  const t = document.createElement('div');
  t.className = 'toast ' + (type === 'success' ? '' : type);
  t.textContent = msg;
  document.getElementById('toastWrap').appendChild(t);
  setTimeout(() => t.remove(), 3000);
}

function tickClock() {
  const el = document.getElementById('clockNow');
  if (el) el.textContent = new Date().toTimeString().slice(0, 5);
}
setInterval(tickClock, 1000); tickClock();

/* ============ SIDEBAR · grupos colapsables + drawer móvil ============ */
(function setupMobileDrawer() {
  // El drawer móvil debe funcionar SIEMPRE (independiente de si hay grupos),
  // si no, en páginas como login o errores el hamburger no abriría nada.
  const btn      = document.getElementById('mobileMenuBtn');
  const sidebar  = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  if (!btn || !sidebar || !backdrop) return;

  const openSidebar = () => {
    sidebar.classList.add('mobile-open');
    backdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
  };
  const closeSidebar = () => {
    sidebar.classList.remove('mobile-open');
    backdrop.classList.remove('show');
    document.body.style.overflow = '';
  };
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    sidebar.classList.contains('mobile-open') ? closeSidebar() : openSidebar();
  });
  backdrop.addEventListener('click', closeSidebar);
  sidebar.querySelectorAll('a').forEach(a => a.addEventListener('click', closeSidebar));
  window.addEventListener('resize', () => { if (window.innerWidth > 900) closeSidebar(); });
})();

(function setupSidebar() {
  const groups = document.querySelectorAll('.nav-group');
  if (!groups.length) return;

  // 1) Cargar estado guardado y aplicarlo (sobreescribe el default del PHP).
  let stored = {};
  try { stored = JSON.parse(localStorage.getItem('navGroups') || '{}'); }
  catch (e) { stored = {}; }
  if (Object.keys(stored).length) {
    groups.forEach(g => {
      const label = g.dataset.group;
      if (label in stored) g.classList.toggle('open', !!stored[label]);
    });
  }

  // 2) Click en header de grupo · modo accordion: abre el clickeado,
  //    cierra los demás. Click en uno abierto → lo cierra.
  groups.forEach(g => {
    const head = g.querySelector('.nav-group-head');
    if (!head) return;
    head.addEventListener('click', (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      const wasOpen = g.classList.contains('open');
      // Cerrar todos
      groups.forEach(other => other.classList.remove('open'));
      // Si NO estaba abierto, ahora abrirlo
      if (!wasOpen) g.classList.add('open');
      // Persistir estado completo
      stored = {};
      groups.forEach(x => { stored[x.dataset.group] = x.classList.contains('open'); });
      try { localStorage.setItem('navGroups', JSON.stringify(stored)); } catch (e) {}
    });
  });

  // (El drawer móvil ya se configuró en setupMobileDrawer() arriba)
})();

// Badge global de cocina (en sidebar)
async function refreshKitchenBadge() {
  try {
    const { count } = await API.get('cocina/count');
    const b = document.getElementById('kitchenBadge');
    if (!b) return;
    if (count > 0) { b.style.display = 'inline-block'; b.textContent = count; }
    else b.style.display = 'none';
  } catch (e) { /* silencio */ }
}
refreshKitchenBadge();
setInterval(refreshKitchenBadge, 10000);

// Badge global de pedidos web (en sidebar) — suena si llega uno nuevo
// aunque estés en otra pantalla.
let _webLastCount = -1;
function _webBeep() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    [0, 0.18, 0.36].forEach((t, i) => {
      const osc = ctx.createOscillator(), gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = 880 + i * 220;
      gain.gain.setValueAtTime(0.001, ctx.currentTime + t);
      gain.gain.exponentialRampToValueAtTime(0.3, ctx.currentTime + t + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.15);
      osc.connect(gain); gain.connect(ctx.destination);
      osc.start(ctx.currentTime + t); osc.stop(ctx.currentTime + t + 0.15);
    });
  } catch (e) {}
}
async function refreshWebBadge() {
  try {
    const { count } = await API.get('webadmin/count');
    const b = document.getElementById('webBadge');
    // Avisar (badge ya existe en sidebar)
    if (b) {
      if (count > 0) { b.style.display = 'inline-block'; b.textContent = count; b.classList.toggle('pulse', count > 0); }
      else { b.style.display = 'none'; b.classList.remove('pulse'); }
    }
    // Sonar solo si AUMENTÓ y NO estamos ya en el módulo de pedidos web
    if (_webLastCount >= 0 && count > _webLastCount && document.body.dataset.module !== 'web_orders') {
      _webBeep();
      if (typeof toast === 'function') toast('🌐 ¡Nuevo pedido web! Revisa Pedidos web', 'info');
    }
    _webLastCount = count;
  } catch (e) { /* silencio (puede no haber sesión) */ }
}
refreshWebBadge();
setInterval(refreshWebBadge, 12000);

/* ============ ESTADO DEL NEGOCIO · abrir/cerrar jornada (sidebar) ============ */
async function refreshJornada() {
  const box = document.getElementById('jornadaBox');
  if (!box) return;
  try {
    const j = await API.get('jornada/estado');
    box.style.display = '';
    const dot = document.getElementById('jornadaDot');
    const txt = document.getElementById('jornadaTexto');
    const sub = document.getElementById('jornadaSub');
    const btn = document.getElementById('jornadaBtn');

    if (j.abierta) {
      dot.className = 'jornada-dot open';
      txt.textContent = 'Negocio ABIERTO';
      sub.textContent = j.abierta_por ? `por ${j.abierta_por} · ${(j.abierta_at||'').slice(11,16)}` : '';
    } else {
      dot.className = 'jornada-dot closed';
      txt.textContent = 'Negocio CERRADO';
      sub.textContent = j.puede_abrir ? 'Ábrelo para iniciar operaciones' : 'Espera a que el admin lo abra';
    }

    if (j.puede_abrir) {
      btn.style.display = '';
      if (j.abierta) {
        btn.textContent = '🔒 Cerrar negocio';
        btn.className = 'jornada-btn cerrar';
        btn.onclick = async () => {
          if (!confirm('¿Cerrar el negocio? Nadie podrá tomar pedidos hasta reabrirlo.')) return;
          try { await API.post('jornada/cerrar', {}); if (typeof toast==='function') toast('Negocio cerrado'); refreshJornada(); }
          catch (e) { if (typeof toast==='function') toast(e.message, 'error'); }
        };
      } else {
        btn.textContent = '🔓 Abrir negocio';
        btn.className = 'jornada-btn abrir';
        btn.onclick = async () => {
          try {
            await API.post('jornada/abrir', {});
            if (typeof toast==='function') toast('¡Negocio abierto! Ya puedes abrir tu turno.', 'success');
            refreshJornada();
          } catch (e) { if (typeof toast==='function') toast(e.message, 'error'); }
        };
      }
    } else {
      btn.style.display = 'none';
    }
  } catch (e) { /* silencio (puede no haber sesión) */ }
}
refreshJornada();
setInterval(refreshJornada, 30000);
