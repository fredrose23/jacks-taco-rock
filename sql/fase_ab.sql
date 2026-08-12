-- ================================================================
-- FASE A + B · Repartidores, turnos/roster, tipos de pedido,
--              clientes frecuentes, cortes por usuario
-- ================================================================
USE jacks_rock;

-- ─── A.1 · Rol repartidor ──────────────────────────────────────
ALTER TABLE usuarios
  MODIFY COLUMN rol ENUM('admin','mesero','cocina','cajero','repartidor')
    NOT NULL DEFAULT 'mesero';

-- ─── A.2 · Tabla TURNOS (roster + datos de corte unificados) ───
CREATE TABLE IF NOT EXISTS turnos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  fecha DATE NOT NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  estado ENUM('programado','en_curso','cerrado','ausente','cancelado')
    NOT NULL DEFAULT 'programado',

  -- Apertura / cierre real (puede diferir del horario programado)
  abierto_at  TIMESTAMP NULL,
  cerrado_at  TIMESTAMP NULL,

  -- Fondo inicial (solo aplica a roles que cobran: mesero/repartidor/cajero)
  fondo_inicial DECIMAL(10,2) DEFAULT 0,

  -- Datos generados al cierre (corte)
  efectivo_esperado DECIMAL(10,2) DEFAULT 0,
  efectivo_contado  DECIMAL(10,2) DEFAULT 0,
  diferencia        DECIMAL(10,2) DEFAULT 0,
  total_tarjeta     DECIMAL(10,2) DEFAULT 0,
  total_transfer    DECIMAL(10,2) DEFAULT 0,
  total_otros       DECIMAL(10,2) DEFAULT 0,
  total_ventas      DECIMAL(10,2) DEFAULT 0,
  total_propinas    DECIMAL(10,2) DEFAULT 0,
  total_envios      DECIMAL(10,2) DEFAULT 0,  -- solo repartidor
  num_ordenes       INT DEFAULT 0,

  asignado_por INT NULL,             -- admin que programó el turno
  notas VARCHAR(255),                -- nota del admin al programar
  observaciones_cierre TEXT,         -- nota del usuario al cerrar

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (usuario_id)   REFERENCES usuarios(id),
  FOREIGN KEY (asignado_por) REFERENCES usuarios(id),
  INDEX idx_turnos_usuario_fecha (usuario_id, fecha),
  INDEX idx_turnos_fecha_estado  (fecha, estado)
) ENGINE=InnoDB;

-- ─── A.3 · Clientes frecuentes ─────────────────────────────────
CREATE TABLE IF NOT EXISTS clientes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  telefono VARCHAR(20) NOT NULL UNIQUE,
  nombre VARCHAR(100) NOT NULL,
  direccion VARCHAR(255),
  referencias VARCHAR(255),
  notas VARCHAR(255),
  total_pedidos INT DEFAULT 0,
  total_gastado DECIMAL(10,2) DEFAULT 0,
  last_order_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_clientes_telefono (telefono),
  INDEX idx_clientes_nombre (nombre)
) ENGINE=InnoDB;

-- ─── A.4 · Ordenes: tipo de pedido + datos cliente + delivery ──
ALTER TABLE ordenes
  MODIFY mesa_id INT NULL,                -- mesa solo aplica a tipo='local'
  ADD COLUMN IF NOT EXISTS tipo
    ENUM('local','llevar','domicilio') NOT NULL DEFAULT 'local' AFTER mesa_id,
  ADD COLUMN IF NOT EXISTS cliente_id INT NULL AFTER tipo,
  ADD COLUMN IF NOT EXISTS cliente_nombre   VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS cliente_telefono VARCHAR(20)  NULL,
  ADD COLUMN IF NOT EXISTS cliente_direccion VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS cliente_referencias VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS repartidor_id INT NULL,
  ADD COLUMN IF NOT EXISTS costo_envio DECIMAL(10,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS estado_entrega
    ENUM('pendiente','asignada','en_camino','entregada','no_entregada','cancelada') NULL,
  ADD COLUMN IF NOT EXISTS hora_pickup TIME NULL,
  ADD COLUMN IF NOT EXISTS entregada_at TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS turno_id INT NULL,         -- turno del que tomó la orden
  ADD INDEX idx_ordenes_tipo (tipo),
  ADD INDEX idx_ordenes_repartidor (repartidor_id),
  ADD INDEX idx_ordenes_cliente (cliente_id),
  ADD INDEX idx_ordenes_turno (turno_id);

-- FKs (separadas porque si ya existen, ADD INDEX no se duplica pero ADD CONSTRAINT sí)
-- Se aplican solo si no existen ya
SET @fk_existe = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA='jacks_rock' AND TABLE_NAME='ordenes'
    AND CONSTRAINT_NAME='fk_ordenes_repartidor');
SET @sql = IF(@fk_existe=0,
  'ALTER TABLE ordenes ADD CONSTRAINT fk_ordenes_repartidor
     FOREIGN KEY (repartidor_id) REFERENCES usuarios(id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_existe = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA='jacks_rock' AND TABLE_NAME='ordenes'
    AND CONSTRAINT_NAME='fk_ordenes_cliente');
SET @sql = IF(@fk_existe=0,
  'ALTER TABLE ordenes ADD CONSTRAINT fk_ordenes_cliente
     FOREIGN KEY (cliente_id) REFERENCES clientes(id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_existe = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA='jacks_rock' AND TABLE_NAME='ordenes'
    AND CONSTRAINT_NAME='fk_ordenes_turno');
SET @sql = IF(@fk_existe=0,
  'ALTER TABLE ordenes ADD CONSTRAINT fk_ordenes_turno
     FOREIGN KEY (turno_id) REFERENCES turnos(id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── B.1 · Pagos y propinas asociados al turno ─────────────────
ALTER TABLE pagos
  ADD COLUMN IF NOT EXISTS turno_id INT NULL,
  ADD INDEX IF NOT EXISTS idx_pagos_turno (turno_id);

ALTER TABLE propinas
  ADD COLUMN IF NOT EXISTS turno_id INT NULL,
  ADD INDEX IF NOT EXISTS idx_propinas_turno (turno_id);

-- ─── B.2 · Aviso de configuración ──────────────────────────────
INSERT INTO configuracion (clave, valor, descripcion, tipo) VALUES
  ('horario_tolerancia_min',
   '15',
   'Minutos de tolerancia antes/después del turno para permitir login',
   'number'),
  ('costo_envio_default',
   '30',
   'Costo de envío default para pedidos a domicilio',
   'number')
ON DUPLICATE KEY UPDATE valor = valor;

SELECT 'Fase A+B aplicadas correctamente' AS resultado;
SELECT
  (SELECT COUNT(*) FROM turnos)   AS turnos,
  (SELECT COUNT(*) FROM clientes) AS clientes;
