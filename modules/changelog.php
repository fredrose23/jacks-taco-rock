<?php
/**
 * Historial de versiones (changelog).
 * Para agregar una versión nueva: pon una entrada NUEVA hasta ARRIBA del array
 * y actualiza APP_VERSION en config/config.php al mismo número.
 *
 * Tipos sugeridos: 'Nuevo' (verde), 'Mejora' (azul), 'Arreglo' (naranja).
 */
$CHANGELOG = [
    [
        'v' => '1.0.2', 'fecha' => '2026-08-11', 'cambios' => [
            ['tipo' => 'Arreglo', 'texto' => '<b>Cocina ahora muestra TODOS los pedidos:</b> antes solo se veían los de mesa; los pedidos para <b>llevar, domicilio, web y mostrador</b> quedaban ocultos (no tienen mesa). Ya aparecen todos, con su tipo y cliente.'],
        ],
    ],
    [
        'v' => '1.0.1', 'fecha' => '2026-08-11', 'cambios' => [
            ['tipo' => 'Arreglo', 'texto' => '<b>Cocina ya no acumula órdenes viejas:</b> al cobrar (o cerrar el día) las comandas pasan a "servida" y cocina solo muestra órdenes <b>abiertas</b>. Se limpiaron 946 comandas atoradas.'],
            ['tipo' => 'Mejora', 'texto' => '<b>Alerta de impresión de comandas:</b> al enviar a cocina se muestra si imprimió ✓ o falló ✗ en cada cocina, con botón para <b>reimprimir</b> (útil si se acaba el rollo o sale incompleta).'],
            ['tipo' => 'Mejora', 'texto' => 'Al enviar a cocina, si todo imprimió, <b>regresa solo a Mesas</b> (para todos los tipos: mesa, llevar, domicilio, web).'],
            ['tipo' => 'Mejora', 'texto' => 'Botones del pedido <b>más grandes</b> y agrupados; los checkbox de "sin imprimir" ahora son controles grandes y separados para no picar otro botón por error.'],
        ],
    ],
    [
        'v' => '1.0.0', 'fecha' => '2026-08-11', 'cambios' => [
            ['tipo' => 'Nuevo', 'texto' => 'Se estableció el <b>versionado del sistema</b> (semántico MAYOR.MENOR.PARCHE) con este historial de versiones, accesible desde el menú.'],
            ['tipo' => 'Nuevo', 'texto' => '<b>Respaldos automáticos diarios</b> de la base de datos y las imágenes (con rotación de 14 días).'],
            ['tipo' => 'Nuevo', 'texto' => 'El código del sistema ahora se versiona en <b>Git</b> y se sube a un repositorio privado en GitHub.'],
        ],
    ],
];

$tipoColor = [
    'Nuevo'   => 'var(--green)',
    'Mejora'  => 'var(--blue)',
    'Arreglo' => 'var(--accent-2, #e8b93a)',
];
$actual = defined('APP_VERSION') ? APP_VERSION : ($CHANGELOG[0]['v'] ?? '1.0.0');
?>
<div class="topbar">
  <div>
    <h2>🌿 Historial de versiones</h2>
    <div class="sub">Todos los cambios importantes del sistema se anotan aquí.</div>
  </div>
  <div class="meta">
    <span class="ver-badge">Versión actual: v<?= e($actual) ?></span>
  </div>
</div>

<div class="content">
  <div class="panel" style="max-width:820px;">
    <h3 style="margin-top:0;">Changelog — <?= e(defined('APP_NAME') ? APP_NAME : 'Sistema') ?></h3>
    <div class="sub" style="margin-bottom:10px;">Versionado semántico: <b>MAYOR.MENOR.PARCHE</b></div>
    <ul class="cl-semver">
      <li><b>PARCHE</b> (1.0.<b>X</b>) → arreglos de errores. Ej: 1.0.0 → 1.0.1</li>
      <li><b>MENOR</b> (1.<b>X</b>.0) → funciones nuevas. Ej: 1.0.5 → 1.1.0</li>
      <li><b>MAYOR</b> (<b>X</b>.0.0) → cambios grandes / que rompen lo anterior. Ej: 1.4.0 → 2.0.0</li>
    </ul>
  </div>

  <?php foreach ($CHANGELOG as $rel): ?>
    <div class="panel cl-release" style="max-width:820px;">
      <div class="cl-head">
        <span class="cl-ver">v<?= e($rel['v']) ?></span>
        <span class="cl-fecha"><?= e($rel['fecha']) ?></span>
        <?php if ($rel['v'] === $actual): ?><span class="cl-actual">actual</span><?php endif; ?>
      </div>
      <ul class="cl-list">
        <?php foreach ($rel['cambios'] as $c): ?>
          <li>
            <span class="cl-tag" style="background:<?= $tipoColor[$c['tipo']] ?? 'var(--muted)' ?>;"><?= e($c['tipo']) ?></span>
            <span class="cl-txt"><?= $c['texto'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
</div>

<style>
  .ver-badge{background:var(--accent,#4a9fed);color:#fff;padding:5px 12px;border-radius:20px;font-weight:700;font-size:13px;}
  .cl-semver{margin:0;padding-left:18px;color:var(--muted);font-size:13px;line-height:1.9;}
  .cl-release{margin-top:14px;}
  .cl-head{display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--bd,#e5e7eb);padding-bottom:8px;margin-bottom:10px;}
  .cl-ver{font-size:20px;font-weight:800;color:var(--accent,#4a9fed);}
  .cl-fecha{color:var(--muted);font-size:13px;}
  .cl-actual{margin-left:auto;background:var(--green);color:#fff;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;text-transform:uppercase;}
  .cl-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px;}
  .cl-list li{display:flex;gap:10px;align-items:flex-start;}
  .cl-tag{color:#04120a;font-size:11px;font-weight:700;padding:2px 9px;border-radius:6px;white-space:nowrap;margin-top:2px;}
  .cl-txt{font-size:14px;line-height:1.5;}
</style>
<script>document.body.dataset.module = "changelog";</script>
