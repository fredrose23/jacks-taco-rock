<?php
$active = $_GET['module'] ?? 'tables';
$activeSub = $_GET['sub'] ?? '';
$user = current_user();
$rolBadge = ['admin'=>'#e8743a','mesero'=>'#5bb4e0','cocina'=>'#f0b042','cajero'=>'#69c97b','repartidor'=>'#c89cff'][$user['rol']] ?? '#9bb8b1';

/**
 * Sidebar agrupado por categoría — grupos colapsables, mejor para escalar
 * con muchos módulos y para móviles.
 *
 * Cada grupo se oculta automáticamente si el usuario no tiene acceso a ningún
 * módulo dentro de él.
 */
$nav_groups = [
  'Operación' => [
    ['key'=>'tables',       'label'=>'Mesas',         'icon'=>'▦'],
    ['key'=>'orders',       'label'=>'Pedido',        'icon'=>'🛒', 'hideFromMenu'=>true],
    ['key'=>'web_orders',   'label'=>'Pedidos web',   'icon'=>'🌐', 'badge'=>'webBadge'],
    ['key'=>'takeout',      'label'=>'Para llevar',   'icon'=>'📦'],
    ['key'=>'delivery',     'label'=>'Domicilios',    'icon'=>'🛵'],
    ['key'=>'kitchen',      'label'=>'Cocina',        'icon'=>'♨', 'badge'=>'kitchenBadge'],
    ['key'=>'reservations', 'label'=>'Reservaciones', 'icon'=>'📅'],
  ],
  'Personal' => [
    ['key'=>'my_shift',     'label'=>'Mi turno',      'icon'=>'⏱'],
    ['key'=>'dashboard',    'label'=>'Mi dashboard',  'icon'=>'📊'],
  ],
  'Consulta' => [
    ['key'=>'menu',         'label'=>'Menú',          'icon'=>'☰'],
    ['key'=>'clients',      'label'=>'Clientes',      'icon'=>'👤'],
  ],
  'Administración' => [
    ['key'=>'cash',         'label'=>'Caja · Cortes', 'icon'=>'💰'],
    ['key'=>'tips',         'label'=>'Propinas',      'icon'=>'🪙'],
    ['key'=>'tickets',      'label'=>'Tickets · Ventas','icon'=>'🧾'],
    ['key'=>'reports',      'label'=>'Reportes',      'icon'=>'▤'],
    ['key'=>'closures',     'label'=>'Cierres',       'icon'=>'📋'],
    ['key'=>'inventory',    'label'=>'Inventario',    'icon'=>'📦'],
  ],
  'Configuración' => [
    ['key'=>'system',       'label'=>'Sistema',       'icon'=>'⚙', 'submenu'=>SYSTEM_MODULES],
  ],
];

/**
 * Determina si el grupo que contiene el módulo activo debe abrirse por default.
 */
function group_contains_active(array $items, string $active): bool {
    foreach ($items as $item) {
        if ($item['key'] === $active) return true;
    }
    return false;
}
?>
<button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Abrir menú">☰</button>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<aside id="sidebar">
  <div class="brand">
    <div class="logo-wrap">
      <img src="<?= logo_url() ?>" alt="<?= e(APP_NAME) ?>"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
      <div class="logo-fallback" style="display:none;">
        <span class="jacks">Jacks</span>
        <span class="taco">TACO ROCK</span>
      </div>
    </div>
    <h1><?= e(cfg('negocio_nombre', APP_NAME)) ?></h1>
    <p><?= e(cfg('negocio_eslogan', APP_SLOGAN)) ?></p>
  </div>

  <nav class="nav-list">
    <?php foreach ($nav_groups as $groupLabel => $groupItems):
      // Filtrar items por permisos
      $visibles = array_filter($groupItems, fn($it) => empty($it['hideFromMenu']) && can_access($it['key']));
      if (!$visibles) continue;
      $isActiveGroup = group_contains_active($groupItems, $active);
    ?>
      <div class="nav-group <?= $isActiveGroup ? 'open' : '' ?>" data-group="<?= e($groupLabel) ?>">
        <button class="nav-group-head" type="button">
          <span><?= e($groupLabel) ?></span>
          <span class="caret">▾</span>
        </button>
        <div class="nav-group-items">
          <?php foreach ($visibles as $item):
            $hasSub = !empty($item['submenu']);
            $isActive = $active === $item['key'];
          ?>
            <?php if ($hasSub): ?>
              <button class="nav-item nav-parent <?= $isActive ? 'active expanded' : '' ?>"
                      onclick="this.classList.toggle('expanded')">
                <span class="ico"><?= $item['icon'] ?></span>
                <?= e($item['label']) ?>
                <span class="caret">▾</span>
              </button>
              <div class="nav-submenu">
                <?php foreach ($item['submenu'] as $sk => [$slabel, $sicon]): ?>
                  <a class="nav-sub-item <?= ($isActive && $activeSub === $sk) ? 'active' : '' ?>"
                     href="<?= SYS_URL ?>/index.php?module=<?= $item['key'] ?>&sub=<?= $sk ?>">
                    <span class="ico"><?= $sicon ?></span> <?= e($slabel) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <a class="nav-item <?= $isActive ? 'active' : '' ?>"
                 href="<?= SYS_URL ?>/index.php?module=<?= $item['key'] ?>">
                <span class="ico"><?= $item['icon'] ?></span> <?= e($item['label']) ?>
                <?php if (!empty($item['badge'])): ?>
                  <span id="<?= $item['badge'] ?>" class="nav-badge" style="display:none;"></span>
                <?php endif; ?>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-foot">
    <!-- Estado del negocio (apertura de jornada) -->
    <div id="jornadaBox" class="jornada-box" style="display:none;">
      <div class="jornada-estado">
        <span class="jornada-dot" id="jornadaDot"></span>
        <span id="jornadaTexto">…</span>
      </div>
      <div class="jornada-sub" id="jornadaSub"></div>
      <button class="jornada-btn" id="jornadaBtn" style="display:none;"></button>
    </div>

    <div class="user-chip">
      <div class="user-avatar" style="background:<?= $rolBadge ?>;"><?= strtoupper(substr($user['nombre'],0,1)) ?></div>
      <div class="user-info">
        <b><?= e($user['nombre']) ?></b>
        <span class="role-tag role-<?= $user['rol'] ?>"><?= e($user['rol']) ?></span>
      </div>
    </div>
    <a href="<?= SYS_URL ?>/logout.php" class="logout-link">⎋ Cerrar sesión</a>
  </div>
</aside>

<main>
