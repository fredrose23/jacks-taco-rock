<?php require_role('admin'); ?>
<div class="topbar">
  <div>
    <h2>Usuarios del sistema</h2>
    <div class="sub">Administración de cuentas y roles</div>
  </div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <?php
    $rows = db()->query("SELECT id, nombre, usuario, rol, activo, created_at FROM usuarios ORDER BY rol, nombre")->fetchAll();
  ?>
  <table class="data-table">
    <thead>
      <tr><th>#</th><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Estado</th><th>Creado</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $u): ?>
        <tr>
          <td><?= $u['id'] ?></td>
          <td><?= e($u['nombre']) ?></td>
          <td><code><?= e($u['usuario']) ?></code></td>
          <td><span class="role-tag role-<?= $u['rol'] ?>"><?= $u['rol'] ?></span></td>
          <td><?= $u['activo'] ? '✓ Activo' : '✗ Inactivo' ?></td>
          <td><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div style="margin-top:20px;background:var(--panel);padding:18px;border-radius:12px;border:1px solid var(--border);">
    <h3 style="color:var(--accent);font-size:15px;margin-bottom:8px;">Crear nuevo usuario</h3>
    <p style="color:var(--muted);font-size:13px;">
      Por seguridad, los usuarios nuevos se crean por línea de comandos generando primero el hash:<br>
      <code style="color:var(--accent-2);">php -r "echo password_hash('miPassword', PASSWORD_BCRYPT);"</code><br>
      luego insertar con <code>INSERT INTO usuarios …</code>
    </p>
  </div>
</div>
<script>document.body.dataset.module = "users";</script>
