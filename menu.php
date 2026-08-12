<?php
/**
 * MENÚ PÚBLICO · accesible sin sesión
 * Los clientes escanean un QR en la mesa y llegan aquí.
 * Lee la carta directo de la BD, así que cualquier cambio del admin
 * se ve en vivo sin tener que actualizar el QR.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Cache del navegador 5 min (se renueva sin esfuerzo si cambian precios)
header('Cache-Control: public, max-age=300');
header('Content-Type: text/html; charset=utf-8');

$categorias = db()->query("SELECT * FROM categorias WHERE activa=1 ORDER BY orden")->fetchAll();
// Mostramos TODOS los productos de categorías activas; los que no están
// disponibles (apagados o sin stock) salen marcados como "No disponible" para
// que el cliente sepa que existen pero no se pueden pedir en este momento.
$productos  = db()->query("
    SELECT p.*, c.id AS cat_id, c.nombre AS cat,
           (p.disponible = 0 OR (p.maneja_stock = 1 AND p.stock <= 0)) AS agotado
    FROM productos p
    JOIN categorias c ON c.id = p.categoria_id
    WHERE c.activa = 1
    ORDER BY c.orden, p.destacado DESC, p.nombre
")->fetchAll();

$cfg = db()->query("SELECT clave, valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);

// Si el restaurante está cerrado (fuera de horario o cierre manual), TODO el
// apartado del cliente (menú y pedidos) queda offline. Solo /sistema/ sigue.
$estado = restaurante_abierto();
if (!$estado['abierto']) {
    include __DIR__ . '/includes/cerrado.php'; // hace exit
}

$nombre   = $cfg['negocio_nombre']   ?? 'Jacks Rock';
$eslogan  = $cfg['negocio_eslogan']  ?? 'Más rock, mejor sabor';
$horario  = $cfg['negocio_horario']  ?? 'Jueves a Domingo · 6:00 PM a 10:30 PM';
$telefono = $cfg['negocio_telefono'] ?? '';
$wsTel    = preg_replace('/\D/', '', $cfg['whatsapp_domicilio'] ?? $telefono);

// Pedidos en línea (carrito web)
// El carrito SOLO aparece si: (1) lo activó el admin Y (2) se entró por la
// landing pública (index.php define $conCarrito=true). En /menu.php directo
// (QR de mesa) no hay carrito, es solo consulta.
$conCarrito  = $conCarrito ?? false;
$webActivo   = $conCarrito && (int)($cfg['web_pedidos_activo'] ?? 0);
$webWhatsapp = preg_replace('/\D/', '', $cfg['whatsapp_pedidos'] ?? $wsTel);
$webEnvio    = (float)($cfg['web_costo_envio'] ?? 30);
$avisos   = array_filter([
    $cfg['aviso_no_alcohol'] ?? null,
    $cfg['aviso_extras']     ?? null,
    $cfg['aviso_pedido']     ?? null,
]);

// Agrupar productos por categoría
$porCat = [];
foreach ($productos as $p) $porCat[$p['cat_id']][] = $p;

// Productos destacados (favoritos) para mostrar al inicio
$destacados = array_filter($productos, fn($p) => $p['destacado']);

// Modificadores por producto (solo si hay carrito, para el modal de personalización)
$prodMods = [];
if ($webActivo) {
    $gStmt = db()->prepare("SELECT g.id, g.nombre, g.tipo, g.obligatorio, g.max_selecciones
        FROM producto_modificador_grupo pmg JOIN modificador_grupos g ON g.id=pmg.grupo_id WHERE pmg.producto_id=?");
    $oStmt = db()->prepare("SELECT id, nombre, precio_extra FROM modificadores WHERE grupo_id=? AND activo=1 ORDER BY orden, nombre");
    foreach ($productos as $p) {
        $gStmt->execute([$p['id']]);
        $gs = $gStmt->fetchAll();
        if (!$gs) continue;
        foreach ($gs as &$g) {
            $oStmt->execute([$g['id']]);
            $g['opciones'] = array_map(fn($o) => ['id'=>(int)$o['id'],'nombre'=>$o['nombre'],'precio_extra'=>(float)$o['precio_extra']], $oStmt->fetchAll());
        }
        $prodMods[(int)$p['id']] = $gs;
    }
}

/** Renderiza una tarjeta de producto (reutilizable: destacados y categorías) */
function render_producto(array $p, bool $webActivo): void {
    $tieneMods = isset($GLOBALS['prodMods'][(int)$p['id']]);
    $agotado   = !empty($p['agotado']);
    ?>
    <article class="product <?= $p['destacado']?'destacado':'' ?> <?= $agotado?'agotado':'' ?>">
      <?php if (!empty($p['imagen'])):
        $base = preg_replace('/\.png$/', '', $p['imagen']);
        $imgUrl   = APP_URL . '/assets/img/products/' . $p['imagen'];
        $thumbUrl = APP_URL . '/assets/img/products/' . $base . '-thumb.png';
      ?>
        <div class="emoji has-image" onclick="openLightbox('<?= e($imgUrl) ?>','<?= e($p['nombre']) ?>')">
          <img src="<?= e($thumbUrl) ?>" alt="<?= e($p['nombre']) ?>" loading="lazy">
        </div>
      <?php else: ?>
        <div class="emoji"><?= $p['emoji'] ?: '🍽' ?></div>
      <?php endif; ?>
      <div class="product-body">
        <div class="product-head">
          <h3>
            <?php if (stripos($p['descripcion'], 'PROMO') === 0): ?><span class="promo-tag">PROMO</span><?php endif; ?>
            <?= e($p['nombre']) ?>
            <?php if ($p['destacado'] && !$agotado): ?> <span class="star">⭐</span><?php endif; ?>
            <?php if ($agotado): ?> <span class="agotado-tag">No disponible</span><?php endif; ?>
          </h3>
          <span class="price">$<?= number_format($p['precio'], 0) ?></span>
        </div>
        <p><?= e($p['descripcion']) ?></p>
        <?php if ($webActivo && !$agotado): ?>
          <button class="add-cart-btn"
                  data-id="<?= (int)$p['id'] ?>"
                  data-nombre="<?= e($p['nombre']) ?>"
                  data-precio="<?= (float)$p['precio'] ?>"
                  data-emoji="<?= e($p['emoji'] ?: '🍽') ?>"
                  data-mods="<?= $tieneMods ? '1' : '0' ?>">
            <?= $tieneMods ? '⚙ Personalizar' : '+ Agregar' ?>
          </button>
        <?php elseif ($agotado): ?>
          <div class="agotado-aviso">😔 Se agotó por ahora · vuelve pronto</div>
        <?php endif; ?>
      </div>
    </article>
    <?php
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0e2e2a">
<title><?= e($nombre) ?> · Menú</title>
<meta name="description" content="<?= e($eslogan) ?> · Carta digital de <?= e($nombre) ?>">
<link rel="icon" href="<?= logo_url() ?>">
<link href="https://fonts.googleapis.com/css2?family=Bungee&family=Fredoka:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0e2e2a;
    --bg-soft: #14403a;
    --panel: #1a4f47;
    --border: rgba(255,255,255,0.08);
    --accent: #e8743a;
    --accent-2: #f0b042;
    --text: #f5ead0;
    --muted: #9bb8b1;
    --teal: #216c61;
    --shadow: 0 10px 30px rgba(0,0,0,.35);
  }
  * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
  html {
    scroll-behavior: smooth;
    scroll-padding-top: 64px;
    /* Evita que iOS Safari haga zoom al double-tap accidental */
    touch-action: manipulation;
  }
  body {
    font-family: 'Fredoka', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
    line-height: 1.4;
    /* Evita rubber-banding raro en iOS que confunde al observer */
    overscroll-behavior-y: contain;
  }
  img { display: block; max-width: 100%; }

  /* HERO */
  .hero {
    background: linear-gradient(180deg, var(--teal) 0%, var(--bg) 100%);
    padding: 24px 16px 20px;
    padding-top: max(24px, env(safe-area-inset-top));
    text-align: center;
    position: relative;
    border-bottom: 1px solid var(--border);
  }
  @media (min-width: 600px) {
    .hero { padding: 36px 24px 28px; }
  }
  .hero::before {
    content: '';
    position: absolute; inset: 0;
    background-image:
      radial-gradient(circle at 20% 30%, rgba(232,116,58,.15) 0%, transparent 40%),
      radial-gradient(circle at 80% 70%, rgba(240,176,66,.1) 0%, transparent 40%);
    pointer-events: none;
  }
  .hero-content { position: relative; max-width: 720px; margin: 0 auto; }
  .hero img.logo {
    width: 90px; height: 90px; margin: 0 auto 10px;
    border-radius: 50%;
    background: var(--teal);
    box-shadow: var(--shadow);
    object-fit: cover;
  }
  @media (min-width: 600px) {
    .hero img.logo { width: 110px; height: 110px; }
  }
  .hero h1 {
    font-family: 'Bungee', sans-serif;
    font-size: clamp(28px, 7vw, 42px);
    color: var(--accent);
    text-shadow: 3px 3px 0 #000;
    letter-spacing: 1px;
    margin-bottom: 6px;
  }
  .hero .tagline {
    color: var(--accent-2);
    font-size: clamp(13px, 3.5vw, 15px);
    font-weight: 600;
    letter-spacing: 0.5px;
    margin-bottom: 18px;
  }
  .hero-meta {
    display: flex; flex-direction: column; gap: 6px;
    font-size: 13px;
    color: var(--muted);
  }
  .hero-meta a {
    color: var(--accent-2);
    text-decoration: none;
    font-weight: 600;
  }
  @media (min-width: 600px) {
    .hero-meta { flex-direction: row; justify-content: center; gap: 24px; }
  }

  /* TABS DE CATEGORÍAS */
  .cat-nav {
    position: sticky; top: 0; z-index: 10;
    background: rgba(14, 46, 42, 0.92);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--border);
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
  }
  .cat-nav::-webkit-scrollbar { display: none; }
  .cat-tabs {
    display: flex;
    gap: 4px;
    padding: 10px 12px;
    min-width: max-content;
    padding-left: max(12px, env(safe-area-inset-left));
    padding-right: max(12px, env(safe-area-inset-right));
  }
  .cat-tab {
    display: inline-flex;
    align-items: center;
    padding: 10px 16px;
    min-height: 42px;        /* tap target accesible */
    font-size: 13px;
    font-weight: 600;
    color: var(--muted);
    text-decoration: none;
    border-radius: 999px;
    white-space: nowrap;
    transition: background-color .15s, color .15s;
  }
  .cat-tab:hover { color: var(--text); background: var(--bg-soft); }
  .cat-tab.active { background: var(--accent); color: #fff; box-shadow: 0 2px 8px rgba(232,116,58,.4); }

  /* CONTENIDO */
  main { max-width: 800px; margin: 0 auto; padding: 8px 0 40px; }

  .category {
    padding: 22px 12px 6px;
    scroll-margin-top: 64px;
  }
  /* Tabs: mostrar una categoría a la vez */
  .cat-pane { display: none; }
  .cat-pane.active { display: block; animation: paneIn .2s ease; }
  @keyframes paneIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
  @media (min-width: 600px) {
    .category { padding: 28px 16px 8px; }
  }
  .category h2 {
    font-family: 'Bungee', sans-serif;
    color: var(--accent-2);
    font-size: clamp(20px, 5.5vw, 28px);
    letter-spacing: 0.5px;
    margin-bottom: 12px;
    text-shadow: 2px 2px 0 #000;
    border-bottom: 2px dashed var(--border);
    padding-bottom: 8px;
  }
  .products { display: flex; flex-direction: column; gap: 10px; }

  .product {
    background: var(--bg-soft);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
  }
  @media (min-width: 600px) {
    .product { padding: 14px; gap: 14px; border-radius: 14px; }
  }
  .product.destacado {
    border-left: 4px solid var(--accent-2);
    background: linear-gradient(135deg, var(--bg-soft) 0%, rgba(240,176,66,.04) 100%);
  }
  /* Producto agotado / no disponible — visible pero atenuado */
  .product.agotado { opacity: .55; filter: grayscale(.4); }
  .product.agotado .emoji img { filter: grayscale(1); }
  .agotado-tag {
    display: inline-block; font-size: 11px; font-weight: 700;
    background: rgba(232,90,79,.18); color: #ff8b80;
    padding: 2px 8px; border-radius: 999px; vertical-align: middle;
  }
  .agotado-aviso {
    margin-top: 6px; font-size: 12px; color: var(--muted); font-style: italic;
  }
  .product .emoji {
    font-size: 36px;
    flex-shrink: 0;
    line-height: 1;
    width: 72px;
    height: 72px;
    background: var(--panel);
    border-radius: 10px;
    display: grid;
    place-items: center;
    box-shadow: inset 0 0 0 1px var(--border);
    overflow: hidden;
  }
  @media (min-width: 600px) {
    .product .emoji { width: 92px; height: 92px; font-size: 48px; border-radius: 12px; }
  }
  .product .emoji.has-image {
    background: transparent;
    cursor: zoom-in;
    box-shadow: 0 4px 12px rgba(0,0,0,.3);
  }
  .product .emoji img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .2s;
  }
  .product .emoji.has-image:active img { transform: scale(0.95); }

  /* Lightbox para ver imagen grande */
  .lightbox {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.92);
    display: none;
    align-items: center; justify-content: center;
    z-index: 100;
    padding: 20px;
    cursor: zoom-out;
  }
  .lightbox.open { display: flex; }
  .lightbox img {
    max-width: 100%;
    max-height: 85vh;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0,0,0,.5);
  }
  .lightbox-caption {
    position: absolute;
    bottom: 30px; left: 0; right: 0;
    text-align: center;
    color: #fff;
    font-family: 'Bungee', sans-serif;
    font-size: 18px;
    text-shadow: 2px 2px 0 #000;
  }
  .lightbox-close {
    position: absolute;
    top: 20px; right: 20px;
    color: #fff;
    background: rgba(255,255,255,0.1);
    border: none;
    width: 44px; height: 44px;
    border-radius: 50%;
    font-size: 24px;
    cursor: pointer;
  }
  .product-body { flex: 1; min-width: 0; }
  .product-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 4px;
  }
  .product h3 {
    font-family: 'Bungee', sans-serif;
    color: var(--accent);
    font-size: clamp(14px, 4.2vw, 18px);
    font-weight: 400;
    letter-spacing: 0.3px;
    line-height: 1.25;
  }
  .product h3 .star { color: var(--accent-2); font-size: 0.8em; }
  .product .price {
    font-family: 'Bungee', sans-serif;
    color: var(--accent-2);
    font-size: clamp(17px, 5.2vw, 22px);
    text-shadow: 1px 1px 0 #000;
    flex-shrink: 0;
  }
  .product p {
    color: var(--muted);
    font-size: 12.5px;
    line-height: 1.5;
  }
  @media (min-width: 600px) {
    .product p { font-size: 13px; }
  }
  .product .promo-tag {
    display: inline-block;
    background: var(--accent);
    color: #fff;
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 4px;
    font-weight: 700;
    letter-spacing: 0.5px;
    margin-right: 6px;
    vertical-align: middle;
  }

  /* AVISOS */
  .avisos {
    padding: 24px 16px 8px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .aviso {
    background: var(--bg-soft);
    border-left: 3px solid var(--accent);
    padding: 12px 14px;
    border-radius: 8px;
    font-size: 13px;
    color: var(--muted);
    line-height: 1.6;
  }

  /* FOOTER */
  footer {
    background: var(--bg-soft);
    margin-top: 20px;
    padding: 28px 20px;
    padding-bottom: max(28px, env(safe-area-inset-bottom));
    text-align: center;
    border-top: 1px solid var(--border);
  }
  footer h3 {
    font-family: 'Bungee', sans-serif;
    color: var(--accent);
    margin-bottom: 10px;
    font-size: 18px;
  }
  footer p { color: var(--muted); font-size: 14px; margin-bottom: 8px; }
  .wa-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #25d366;
    color: #fff;
    padding: 12px 22px;
    border-radius: 999px;
    text-decoration: none;
    font-weight: 700;
    font-size: 15px;
    margin: 12px 0;
    box-shadow: var(--shadow);
  }
  .copy {
    color: var(--muted);
    font-size: 11px;
    opacity: 0.6;
    margin-top: 16px;
  }

  /* Scroll-to-top */
  .top-btn {
    position: fixed;
    bottom: max(20px, env(safe-area-inset-bottom));
    right: 20px;
    width: 48px; height: 48px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    border: none;
    font-size: 22px;
    cursor: pointer;
    box-shadow: var(--shadow);
    z-index: 5;
    opacity: 0;
    transform: translateY(10px);
    pointer-events: none;
    transition: opacity .2s, transform .2s;
  }
  .top-btn.show { opacity: 1; transform: translateY(0); pointer-events: auto; }

  /* ════════════ CARRITO WEB ════════════ */
  .add-cart-btn {
    margin-top: 10px;
    width: 100%;
    background: var(--accent);
    color: #fff;
    border: none;
    padding: 9px;
    border-radius: 8px;
    font-family: inherit;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition: background .15s, transform .1s;
  }
  .add-cart-btn:active { transform: scale(0.97); }
  .add-cart-btn.added { background: #3fb950; }

  /* Botón flotante */
  .cart-fab {
    position: fixed;
    bottom: max(20px, env(safe-area-inset-bottom));
    left: 50%;
    transform: translateX(-50%) translateY(120px);
    background: var(--accent);
    color: #fff;
    border: none;
    padding: 14px 24px;
    border-radius: 999px;
    font-family: 'Fredoka', sans-serif;
    font-weight: 700;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,.5);
    cursor: pointer;
    z-index: 50;
    transition: transform .3s cubic-bezier(.2,1.2,.4,1);
  }
  .cart-fab.show { transform: translateX(-50%) translateY(0); }
  .cart-fab.bump { animation: fabBump .3s; }
  @keyframes fabBump { 0%,100%{ } 40%{ transform: translateX(-50%) translateY(0) scale(1.12); } }
  .cart-fab-count {
    background: #fff; color: var(--accent);
    border-radius: 999px; min-width: 24px; height: 24px;
    display: grid; place-items: center; font-size: 13px; font-weight: 800;
  }
  .cart-fab-total { font-family: 'Bungee', sans-serif; }

  /* Overlay genérico */
  .cart-overlay, .checkout-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.6);
    backdrop-filter: blur(2px);
    z-index: 60; opacity: 0;
    pointer-events: none;
    transition: opacity .25s;
  }
  .cart-overlay.show, .checkout-overlay.show { opacity: 1; pointer-events: auto; }

  /* Drawer del carrito */
  .cart-drawer {
    position: fixed;
    top: 0; right: 0; bottom: 0;
    width: 400px; max-width: 90vw;
    background: var(--bg-soft);
    z-index: 65;
    transform: translateX(100%);
    transition: transform .3s ease;
    display: flex; flex-direction: column;
    box-shadow: -8px 0 30px rgba(0,0,0,.5);
  }
  .cart-drawer.open { transform: translateX(0); }
  .cart-drawer-head, .checkout-head {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 20px;
    border-bottom: 1px solid var(--border);
  }
  .cart-drawer-head h3, .checkout-head h3 {
    font-family: 'Bungee', sans-serif; color: var(--accent); font-size: 18px;
  }
  .cart-close {
    background: none; border: none; color: var(--muted);
    font-size: 28px; cursor: pointer; line-height: 1; width: 36px; height: 36px;
  }
  .cart-close:hover { color: var(--accent); }
  .cart-items { flex: 1; overflow-y: auto; padding: 12px 16px; }
  .cart-empty { text-align: center; color: var(--muted); padding: 50px 20px; line-height: 1.7; }
  .cart-item {
    display: grid;
    grid-template-columns: auto 1fr auto auto;
    gap: 10px; align-items: center;
    padding: 12px 0; border-bottom: 1px solid var(--border);
  }
  .ci-emoji { font-size: 28px; }
  .ci-name { font-weight: 600; font-size: 14px; }
  .ci-price { font-size: 12px; color: var(--muted); }
  .ci-qty { display: flex; align-items: center; gap: 8px; }
  .ci-btn {
    width: 28px; height: 28px; border-radius: 6px;
    background: var(--panel); border: 1px solid var(--border);
    color: var(--text); font-size: 16px; font-weight: 700; cursor: pointer;
  }
  .ci-btn:hover { background: var(--accent); color: #fff; }
  .ci-total { font-weight: 700; color: var(--accent-2); font-size: 14px; min-width: 56px; text-align: right; }
  .cart-foot { padding: 16px 20px; border-top: 1px solid var(--border); }
  .cart-total-row {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 12px; font-size: 16px;
  }
  .cart-total-row b { font-family: 'Bungee', sans-serif; color: var(--accent-2); font-size: 22px; }
  .cart-checkout-btn {
    width: 100%; background: var(--accent); color: #fff; border: none;
    padding: 14px; border-radius: 10px; font-family: inherit;
    font-weight: 700; font-size: 16px; cursor: pointer;
  }
  .cart-checkout-btn:disabled { opacity: .4; cursor: not-allowed; }

  /* Modal de checkout */
  .checkout-modal {
    position: fixed;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%) scale(.95);
    width: 460px; max-width: 94vw; max-height: 90vh;
    background: var(--bg-soft);
    border-radius: 16px;
    z-index: 70; opacity: 0; pointer-events: none;
    transition: opacity .25s, transform .25s;
    display: flex; flex-direction: column;
    overflow: hidden;
  }
  .checkout-modal.open { opacity: 1; pointer-events: auto; transform: translate(-50%, -50%) scale(1); }
  .checkout-body { padding: 20px; overflow-y: auto; }
  .check-label { display: block; font-size: 13px; font-weight: 600; color: var(--text); margin: 12px 0 5px; }
  .check-label small { color: var(--muted); font-weight: 400; }
  .check-input {
    width: 100%; background: var(--panel); border: 1px solid var(--border);
    color: var(--text); padding: 12px 14px; border-radius: 8px;
    font-size: 15px; font-family: inherit;
  }
  .check-input:focus { outline: none; border-color: var(--accent); }
  .check-back { background: none; border: none; color: var(--accent-2); font-size: 13px; cursor: pointer; margin-bottom: 8px; padding: 0; }
  .check-back-link { background: none; border: none; color: var(--muted); cursor: pointer; margin-top: 14px; font-size: 13px; }

  /* Forma de pago */
  .pago-options { display: flex; gap: 8px; margin: 6px 0; }
  .pago-opt {
    flex: 1; background: var(--panel); border: 2px solid var(--border);
    color: var(--text); padding: 12px; border-radius: 10px; cursor: pointer;
    font-family: inherit; font-weight: 600; font-size: 14px; transition: all .15s;
  }
  .pago-opt:hover { border-color: var(--accent); }
  .pago-opt.selected { border-color: var(--accent); background: rgba(232,116,58,.15); }
  .transfer-box {
    background: var(--panel); border: 1px solid var(--accent-2);
    border-radius: 10px; padding: 12px 14px; font-size: 13px; line-height: 1.7;
    margin-top: 6px;
  }
  .transfer-box .tb-row { display: flex; justify-content: space-between; gap: 10px; }
  .transfer-box .tb-row b { color: var(--accent-2); }
  .transfer-box .tb-copy {
    background: var(--bg); border: none; color: var(--accent); cursor: pointer;
    font-size: 11px; padding: 2px 8px; border-radius: 4px;
  }
  .transfer-box .tb-aviso { color: var(--muted); font-size: 11px; margin-top: 8px; line-height: 1.5; }

  .entrega-options { display: grid; gap: 12px; margin-top: 8px; }
  .entrega-opt {
    display: flex; flex-direction: column; gap: 4px;
    background: var(--panel); border: 2px solid var(--border);
    border-radius: 12px; padding: 18px; cursor: pointer;
    transition: all .15s; font-family: inherit; text-align: left;
  }
  .entrega-opt:hover { border-color: var(--accent); background: rgba(232,116,58,.08); }
  .eo-icon { font-size: 32px; }
  .eo-name { font-weight: 700; font-size: 16px; color: var(--text); }
  .eo-desc { font-size: 12px; color: var(--muted); }

  .gps-btn {
    width: 100%; background: var(--panel); border: 1px dashed var(--accent);
    color: var(--accent-2); padding: 12px; border-radius: 8px;
    font-family: inherit; font-weight: 600; cursor: pointer; font-size: 14px;
  }
  .gps-status { font-size: 12px; color: var(--muted); margin-top: 6px; min-height: 16px; }
  .ck-map { height: 220px; border-radius: 10px; margin-top: 8px; border: 1px solid var(--border); }
  .map-hint { display: block; font-size: 11px; color: var(--muted); margin-top: 4px; }

  .check-resumen {
    background: var(--panel); border-radius: 10px; padding: 12px 14px;
    margin: 16px 0; font-size: 13px;
  }
  .cr-line { display: flex; justify-content: space-between; padding: 3px 0; color: var(--muted); }
  .cr-line.cr-cotizar { color: var(--accent-2); font-weight: 600; }
  .cr-aviso-envio {
    margin-top: 10px;
    background: rgba(232,176,66,.12);
    border: 1px solid var(--accent-2);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 12px;
    color: var(--accent-2);
    line-height: 1.5;
  }
  .cr-line.cr-total {
    border-top: 1px dashed var(--border); margin-top: 6px; padding-top: 8px;
    font-family: 'Bungee', sans-serif; color: var(--accent-2); font-size: 16px;
  }

  /* Paso éxito */
  #step-exito { text-align: center; padding: 20px 0; }
  .exito-icon { font-size: 56px; }
  .exito-title { font-family: 'Bungee', sans-serif; color: var(--accent); margin: 10px 0; }
  .exito-msg { color: var(--muted); font-size: 14px; line-height: 1.6; margin-bottom: 18px; }
  .wa-confirm-btn {
    display: inline-flex; align-items: center; gap: 8px;
    background: #25d366; color: #fff; text-decoration: none;
    padding: 16px 28px; border-radius: 999px; font-weight: 800; font-size: 16px;
    box-shadow: 0 6px 20px rgba(37,211,102,.4);
  }
  .exito-note { color: var(--accent-2); font-size: 12px; margin-top: 16px; line-height: 1.5; }

  /* ════════════ MODAL DE PERSONALIZACIÓN (modificadores) ════════════ */
  .mods-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.6);
    backdrop-filter: blur(2px);
    z-index: 75; opacity: 0; pointer-events: none;
    transition: opacity .25s;
  }
  .mods-overlay.show { opacity: 1; pointer-events: auto; }
  .mods-modal {
    position: fixed;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%) scale(.95);
    width: 460px; max-width: 94vw; max-height: 90vh;
    background: var(--bg-soft);
    border-radius: 16px;
    z-index: 80; opacity: 0; pointer-events: none;
    transition: opacity .25s, transform .25s;
    display: flex; flex-direction: column;
    overflow: hidden;
  }
  .mods-modal.open { opacity: 1; pointer-events: auto; transform: translate(-50%, -50%) scale(1); }
  .mods-head {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 20px; border-bottom: 1px solid var(--border);
  }
  .mods-head h3 { font-family: 'Bungee', sans-serif; color: var(--accent); font-size: 18px; }
  .mods-body { padding: 16px 20px; overflow-y: auto; }
  .mods-dish-head {
    display: flex; gap: 12px; align-items: center; margin-bottom: 14px;
    padding-bottom: 14px; border-bottom: 1px dashed var(--border);
  }
  .mods-dish-head .em { font-size: 34px; }
  .mods-dish-head h4 { font-size: 17px; color: var(--text); }
  .mods-dish-head .pr { color: var(--accent-2); font-weight: 700; font-size: 14px; }
  .mods-group { margin-bottom: 16px; }
  .mods-group h5 {
    font-size: 14px; color: var(--text); margin-bottom: 8px;
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  }
  .mods-group h5 .req { color: var(--accent); font-size: 11px; font-weight: 700; }
  .mods-group h5 .hint { color: var(--muted); font-size: 11px; font-weight: 400; }
  .mods-options { display: flex; flex-wrap: wrap; gap: 8px; }
  .mod-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--panel); border: 1.5px solid var(--border);
    color: var(--text); padding: 9px 14px; border-radius: 999px;
    font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
    transition: border-color .15s, background .15s, transform .1s;
  }
  .mod-chip:active { transform: scale(.96); }
  .mod-chip .price { color: var(--accent-2); font-size: 12px; font-weight: 700; }
  .mod-chip.selected {
    background: rgba(232,116,58,.18);
    border-color: var(--accent);
    color: var(--accent);
  }
  .mod-chip.selected .price { color: var(--accent); }
  .mods-notas-label { display: block; font-size: 13px; font-weight: 600; color: var(--text); margin: 6px 0 6px; }
  #modsNotas {
    width: 100%; background: var(--panel); border: 1px solid var(--border);
    color: var(--text); padding: 10px 12px; border-radius: 8px;
    font-size: 14px; font-family: inherit; resize: vertical;
  }
  .mods-foot { padding: 14px 20px; border-top: 1px solid var(--border); }
  .mods-total-row {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;
  }
  .mods-total-row b { font-family: 'Bungee', sans-serif; color: var(--accent-2); font-size: 20px; }

  /* Modificadores y notas en el ítem del carrito */
  .ci-mods { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
  .ci-mod-pill {
    font-size: 10px; background: var(--panel); border: 1px solid var(--border);
    color: var(--accent-2); padding: 2px 7px; border-radius: 999px;
  }
  .ci-note { font-size: 11px; color: var(--muted); font-style: italic; margin-top: 3px; }

  @media (max-width: 600px) {
    .cart-drawer { width: 100%; max-width: 100%; }
  }
</style>
</head>
<body>

<header class="hero">
  <div class="hero-content">
    <img src="<?= logo_url() ?>" alt="<?= e($nombre) ?>" class="logo" onerror="this.style.display='none'">
    <h1><?= e($nombre) ?></h1>
    <p class="tagline"><?= e($eslogan) ?></p>
    <div class="hero-meta">
      <span>🕐 <?= e($horario) ?></span>
      <?php if ($telefono): ?>
        <span>📞 <a href="tel:<?= e($wsTel) ?>"><?= e($telefono) ?></a></span>
      <?php endif; ?>
    </div>
  </div>
</header>

<nav class="cat-nav">
  <div class="cat-tabs">
    <?php if (!empty($destacados)): ?>
      <button class="cat-tab active" data-cat="destacados">⭐ Favoritos</button>
    <?php endif; ?>
    <?php foreach ($categorias as $c): ?>
      <?php if (empty($porCat[$c['id']])) continue; ?>
      <button class="cat-tab <?= empty($destacados) && $c === reset($categorias) ? 'active' : '' ?>" data-cat="<?= $c['id'] ?>"><?= e($c['nombre']) ?></button>
    <?php endforeach; ?>
  </div>
</nav>

<main>
  <?php if (!empty($destacados)): ?>
    <section class="category cat-pane active" data-pane="destacados">
      <h2>⭐ Los favoritos</h2>
      <div class="products">
        <?php foreach ($destacados as $p): ?>
          <?php render_producto($p, $webActivo); ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php foreach ($categorias as $c): ?>
    <?php if (empty($porCat[$c['id']])) continue; ?>
    <section class="category cat-pane <?= empty($destacados) && $c === reset($categorias) ? 'active' : '' ?>" data-pane="<?= $c['id'] ?>" id="cat-<?= $c['id'] ?>">
      <h2><?= e($c['nombre']) ?></h2>
      <div class="products">
        <?php foreach ($porCat[$c['id']] as $p): ?>
          <?php render_producto($p, $webActivo); ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <?php if ($avisos): ?>
    <div class="avisos">
      <?php foreach ($avisos as $a): ?>
        <div class="aviso">⚠ <?= e($a) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<footer>
  <h3>¿Te lo llevamos?</h3>
  <p>Pídenos a domicilio por WhatsApp</p>
  <?php if ($wsTel): ?>
    <a class="wa-btn" href="https://wa.me/52<?= e($wsTel) ?>" target="_blank">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.5 14.4c-.3-.1-1.7-.8-2-.9-.3-.1-.4-.1-.6.1-.2.3-.7.9-.8 1-.2.2-.3.2-.6.1-1.7-.8-2.8-1.5-3.9-3.5-.3-.5.3-.5.8-1.5.1-.2 0-.4 0-.5 0-.1-.6-1.5-.9-2-.2-.5-.5-.5-.6-.5h-.5c-.2 0-.5.1-.7.4-.3.3-1 1-1 2.4s1 2.8 1.1 3c.1.2 2 3 4.8 4.2 1.8.7 2.5.8 3.3.7.5-.1 1.7-.7 1.9-1.4.2-.7.2-1.3.2-1.4-.1-.1-.2-.2-.5-.2zM12 2C6.5 2 2 6.5 2 12c0 1.7.5 3.4 1.3 4.9L2 22l5.3-1.3C8.7 21.5 10.3 22 12 22c5.5 0 10-4.5 10-10S17.5 2 12 2zm0 18c-1.5 0-3-.4-4.3-1.2l-.3-.2-3.2.8.8-3.1-.2-.3C4 14.7 3.5 13.4 3.5 12c0-4.7 3.8-8.5 8.5-8.5s8.5 3.8 8.5 8.5-3.8 8.5-8.5 8.5z"/></svg>
      WhatsApp <?= e($telefono) ?>
    </a>
  <?php endif; ?>
  <p style="margin-top:16px;"><b style="color:var(--accent-2);">🕐 <?= e($horario) ?></b></p>
  <p class="copy">© <?= date('Y') ?> <?= e($nombre) ?> · <?= e($eslogan) ?></p>
</footer>

<button class="top-btn" id="topBtn" aria-label="Volver arriba">↑</button>

<!-- Lightbox para imágenes de platillos -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" aria-label="Cerrar">×</button>
  <img id="lightboxImg" alt="">
  <div class="lightbox-caption" id="lightboxCap"></div>
</div>

<?php if ($webActivo): ?>
<!-- ════════════ CARRITO WEB ════════════ -->
<!-- Botón flotante del carrito -->
<button class="cart-fab" id="cartFab" aria-label="Ver carrito">
  🛒 <span class="cart-fab-count" id="cartFabCount">0</span>
  <span class="cart-fab-total" id="cartFabTotal">$0</span>
</button>

<!-- Drawer del carrito -->
<div class="cart-overlay" id="cartOverlay"></div>
<aside class="cart-drawer" id="cartDrawer">
  <div class="cart-drawer-head">
    <h3>🛒 Tu pedido</h3>
    <button class="cart-close" id="cartClose">×</button>
  </div>
  <div class="cart-items" id="cartItems"></div>
  <div class="cart-foot">
    <div class="cart-total-row">
      <span>Total productos</span>
      <b id="cartTotal">$0.00</b>
    </div>
    <button class="cart-checkout-btn" id="cartCheckout" disabled>Continuar pedido →</button>
  </div>
</aside>

<!-- Modal de personalización (modificadores) -->
<div class="mods-overlay" id="modsOverlay"></div>
<div class="mods-modal" id="modsModal">
  <div class="mods-head">
    <h3>Personalizar</h3>
    <button class="cart-close" id="modsClose">×</button>
  </div>
  <div class="mods-body" id="modsBody"></div>
  <div class="mods-foot">
    <div class="mods-total-row"><span>Total del platillo</span><b id="modsTotal">$0.00</b></div>
    <button class="cart-checkout-btn" id="modsAdd">Agregar al pedido</button>
  </div>
</div>

<!-- Modal de checkout (datos + entrega) -->
<div class="checkout-overlay" id="checkoutOverlay"></div>
<div class="checkout-modal" id="checkoutModal">
  <div class="checkout-head">
    <h3 id="checkoutTitle">Finalizar pedido</h3>
    <button class="cart-close" id="checkoutClose">×</button>
  </div>
  <div class="checkout-body" id="checkoutBody">

    <!-- Paso 1: Tipo de entrega -->
    <div class="checkout-step" id="step-tipo">
      <label class="check-label">¿Cómo quieres tu pedido?</label>
      <div class="entrega-options">
        <button class="entrega-opt" data-entrega="llevar">
          <span class="eo-icon">📦</span>
          <span class="eo-name">Paso a recoger</span>
          <span class="eo-desc">Lo recoges en el local</span>
        </button>
        <button class="entrega-opt" data-entrega="domicilio">
          <span class="eo-icon">🛵</span>
          <span class="eo-name">A domicilio</span>
          <span class="eo-desc">Te lo llevamos (+envío)</span>
        </button>
      </div>
    </div>

    <!-- Paso 2: Datos del cliente -->
    <div class="checkout-step" id="step-datos" style="display:none;">
      <button class="check-back" id="checkBack">← Cambiar tipo</button>

      <label class="check-label">Tu nombre *</label>
      <input class="check-input" id="ckNombre" placeholder="Nombre completo">

      <label class="check-label">Teléfono (WhatsApp) *</label>
      <input class="check-input" id="ckTel" inputmode="tel" placeholder="10 dígitos" maxlength="14">

      <div id="domicilioFields" style="display:none;">
        <label class="check-label">Dirección *</label>
        <input class="check-input" id="ckDir" placeholder="Calle, número, colonia">

        <label class="check-label">Referencias</label>
        <input class="check-input" id="ckRef" placeholder="Casa azul, portón negro, frente a…">

        <label class="check-label">📍 Ubicación GPS <small>(recomendado · ayuda al repartidor a llegar más rápido)</small></label>
        <button class="gps-btn" id="ckGpsBtn" type="button">📍 Compartir mi ubicación</button>
        <div class="gps-status" id="ckGpsStatus"></div>
        <div id="ckMap" class="ck-map" style="display:none;"></div>
        <small class="map-hint" id="mapHint" style="display:none;">Arrastra el marcador para ajustar la ubicación exacta.</small>
      </div>

      <!-- Forma de pago -->
      <label class="check-label" style="margin-top:14px;">💳 Forma de pago</label>
      <div class="pago-options">
        <button type="button" class="pago-opt selected" data-pago="efectivo">💵 Efectivo</button>
        <button type="button" class="pago-opt" data-pago="transferencia">💳 Transferencia</button>
      </div>

      <div id="pagoEfectivo">
        <label class="check-label">¿Con cuánto pagas? <small>(para llevarte cambio)</small></label>
        <input class="check-input" id="ckPagaCon" inputmode="numeric" placeholder="Ej. 500">
      </div>

      <div id="pagoTransfer" style="display:none;">
        <div class="transfer-box" id="transferBox"></div>
        <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-size:13px;color:var(--text);">
          <input type="checkbox" id="ckTransferOk"> Ya transferí / transferiré antes de que preparen
        </label>
      </div>

      <div class="check-resumen" id="checkResumen"></div>

      <button class="cart-checkout-btn" id="checkConfirm">Enviar pedido</button>
    </div>

    <!-- Paso 3: Éxito + WhatsApp -->
    <div class="checkout-step" id="step-exito" style="display:none;">
      <div class="exito-icon">📲</div>
      <h3 class="exito-title">¡Casi listo!</h3>
      <p class="exito-msg" id="exitoMsg"></p>
      <a class="wa-confirm-btn" id="waConfirmBtn" href="#" target="_blank">
        📲 Confirmar pedido por WhatsApp
      </a>
      <p class="exito-note">⚠ <b>Tu pedido NO se prepara todavía.</b> Al confirmar por WhatsApp te decimos el <b>total final con el costo de envío</b> y tú decides si continúas o cancelas.</p>
      <button class="check-back-link" id="exitoClose">Cerrar</button>
    </div>

  </div>
</div>

<!-- Leaflet (mapa gratuito) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  window.WEB_CFG = {
    apiBase:  "<?= APP_URL ?>/api/index.php",
    whatsapp: "<?= e($webWhatsapp) ?>",
    envio:    <?= $webEnvio ?>,
    negocio:  "<?= e($nombre) ?>"
  };
  window.PROD_MODS = <?= json_encode($prodMods, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= APP_URL ?>/assets/js/cart.js?v=<?= asset_v('assets/js/cart.js') ?>"></script>
<?php endif; ?>

<script>
(function() {
  const tabs     = document.querySelectorAll('.cat-tab');
  const panes    = document.querySelectorAll('.cat-pane');
  const navWrap  = document.querySelector('.cat-nav');
  const topBtn   = document.getElementById('topBtn');

  /**
   * Mueve SOLO horizontalmente el contenedor de tabs para centrar el activo.
   * Usa scrollLeft del contenedor, NO scrollIntoView, que afectaría el scroll
   * vertical de la página.
   */
  function centerActiveTab(activeTab) {
    if (!activeTab || !navWrap) return;
    const wrapRect = navWrap.getBoundingClientRect();
    const tabRect  = activeTab.getBoundingClientRect();
    const targetLeft = navWrap.scrollLeft + (tabRect.left - wrapRect.left)
                       - (wrapRect.width / 2) + (tabRect.width / 2);
    navWrap.scrollTo({ left: Math.max(0, targetLeft), behavior: 'smooth' });
  }

  /**
   * Tabs tipo "pestaña": muestra una sola categoría (o Favoritos) a la vez.
   * El menú deja de ser un scroll infinito.
   */
  function showCat(cat, activeTab) {
    tabs.forEach(t => t.classList.toggle('active', t === activeTab));
    panes.forEach(p => p.classList.toggle('active', p.dataset.pane === String(cat)));
    centerActiveTab(activeTab);
    // Subir al inicio del listado al cambiar de categoría
    const main = document.querySelector('main');
    if (main) {
      const navH = navWrap ? navWrap.offsetHeight : 0;
      const y = main.getBoundingClientRect().top + window.pageYOffset - navH - 4;
      if (window.pageYOffset > y) window.scrollTo({ top: y, behavior: 'smooth' });
    }
  }

  tabs.forEach(t => {
    t.addEventListener('click', (ev) => {
      ev.preventDefault();
      showCat(t.dataset.cat, t);
    });
  });

  // Red de seguridad: si por algún motivo ninguna pestaña quedó activa
  // (p. ej. sin favoritos y primera categoría vacía), activa la primera.
  if (tabs.length && !document.querySelector('.cat-tab.active')) {
    tabs[0].classList.add('active');
    const firstPane = document.querySelector('.cat-pane[data-pane="' + tabs[0].dataset.cat + '"]');
    if (firstPane) firstPane.classList.add('active');
  }

  /**
   * Botón "↑" para volver arriba, listener pasivo para no bloquear el scroll.
   */
  let ticking = false;
  window.addEventListener('scroll', () => {
    if (!ticking) {
      requestAnimationFrame(() => {
        topBtn.classList.toggle('show', window.scrollY > 600);
        ticking = false;
      });
      ticking = true;
    }
  }, { passive: true });

  topBtn.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  // Lightbox: ver imagen grande del platillo
  window.openLightbox = (src, caption) => {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightboxCap').textContent = caption || '';
    document.getElementById('lightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
  };
  window.closeLightbox = () => {
    document.getElementById('lightbox').classList.remove('open');
    document.body.style.overflow = '';
  };
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeLightbox();
  });
})();
</script>
</body>
</html>
