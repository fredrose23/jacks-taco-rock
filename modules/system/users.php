<?php require_role('admin'); ?>
<div class="topbar">
  <div><h2>Sistema · Usuarios</h2><div class="sub">Cuentas, roles y horarios</div></div>
  <div class="meta"><span id="clockNow">--:--</span></div>
</div>

<div class="content">
  <div class="toolbar">
    <button class="btn btn-primary" onclick="openUserModal()">+ Nuevo usuario</button>
  </div>
  <div id="usersGrid" class="data-wrap"></div>
</div>

<div class="modal-bg" id="userModal">
  <div class="modal" style="max-width:540px;">
    <h3 id="userModalTitle">Nuevo usuario</h3>
    <input type="hidden" id="uId">

    <label class="inv-label">Nombre completo</label>
    <input class="inv-input" id="uNombre">

    <label class="inv-label">Usuario (login)</label>
    <input class="inv-input" id="uUsuario">

    <label class="inv-label">Contraseña <small style="color:var(--muted);">(dejar vacío si no se cambia)</small></label>
    <input type="password" class="inv-input" id="uPassword" placeholder="min. 6 caracteres">

    <div class="grid-2">
      <div>
        <label class="inv-label">Rol</label>
        <select class="inv-input" id="uRol">
          <option value="admin">Administrador</option>
          <option value="mesero">Mesero</option>
          <option value="cocina">Cocina</option>
          <option value="cajero">Cajero</option>
          <option value="repartidor">Repartidor</option>
        </select>
      </div>
      <div>
        <label class="inv-label">Estado de la cuenta</label>
        <label style="display:flex;align-items:center;gap:8px;padding:9px 12px;background:var(--panel-2);border-radius:8px;border:1px solid var(--border);">
          <input type="checkbox" id="uActivo" checked> Habilitada
        </label>
      </div>
    </div>

    <!-- ─────── Permisos personalizados ─────── -->
    <hr style="margin:18px 0;border:none;border-top:1px solid var(--border);">
    <h4 style="font-size:14px;color:var(--accent);">🔐 Permisos del usuario</h4>
    <p style="font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:10px;">
      Por default cada rol ve un conjunto fijo de módulos. Aquí puedes
      <b>agregar o quitar accesos específicos a este usuario</b> (útil para empleados
      de confianza).
    </p>

    <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
      <button type="button" class="btn btn-ghost" id="uResetPerms" style="font-size:12px;">↻ Restablecer al default del rol</button>
      <small style="color:var(--muted);align-self:center;">Tilda los módulos que verá este usuario.</small>
    </div>

    <div id="uPermisosGrid" class="permisos-grid"></div>

    <!-- ─────── Restricción por horario ─────── -->
    <hr style="margin:18px 0;border:none;border-top:1px solid var(--border);">
    <h4 style="font-size:14px;color:var(--accent);">⏱ Restricción por horario</h4>
    <p style="font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:10px;">
      Cuando está activa, el usuario solo puede entrar dentro de su horario; fuera de él,
      la sesión se cierra sola. <b>Los administradores nunca se restringen.</b>
    </p>

    <label style="display:flex;align-items:center;gap:8px;padding:9px 12px;background:rgba(232,116,58,.1);border-radius:8px;border:1px solid var(--accent);margin-bottom:14px;">
      <input type="checkbox" id="uHorarioActivo"> <b>Activar restricción por horario</b>
    </label>

    <label class="inv-label">Días laborales</label>
    <div class="dias-grid">
      <?php
        $dias = ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
        foreach ($dias as $i => $d) {
          $num = $i + 1; // 1..7
          echo "<label class='dia-box'><input type='checkbox' class='uDia' value='$num'><span>$d</span></label>";
        }
      ?>
    </div>
    <small style="color:var(--muted);">Sin marcar = todos los días.</small>

    <div class="grid-2" style="margin-top:14px;">
      <div>
        <label class="inv-label">Hora de entrada</label>
        <input type="time" class="inv-input" id="uHoraEntrada">
      </div>
      <div>
        <label class="inv-label">Hora de salida</label>
        <input type="time" class="inv-input" id="uHoraSalida">
      </div>
    </div>

    <div class="modal-actions" style="margin-top:18px;">
      <button class="btn btn-ghost" onclick="closeModal('userModal')">Cancelar</button>
      <button class="btn btn-primary" id="uSave">Guardar cambios</button>
    </div>
  </div>
</div>

<script>document.body.dataset.module = "sys-users";</script>
