-- ============================================================
-- MIGRACIONES · Apartado Sistema + módulos nuevos
-- ============================================================
USE jacks_rock;

-- ----- IMPRESORAS -----
CREATE TABLE IF NOT EXISTS impresoras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL,
  ubicacion VARCHAR(30) NOT NULL,      -- 'cocina','barra','caja'
  tipo ENUM('comanda','ticket','ambos') DEFAULT 'comanda',
  driver VARCHAR(40) DEFAULT 'navegador',  -- navegador|escpos_usb|escpos_red
  destino VARCHAR(120) NULL,           -- IP:puerto o ruta USB
  ancho_mm INT DEFAULT 80,
  copias INT DEFAULT 1,
  activa TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO impresoras (nombre, ubicacion, tipo) VALUES
  ('Cocina 1 (Parrilla)', 'cocina', 'comanda'),
  ('Cocina 2 (Fríos)',    'cocina', 'comanda'),
  ('Barra',               'barra',  'comanda'),
  ('Caja',                'caja',   'ticket');

-- Routing de productos/categorías a impresoras
ALTER TABLE categorias ADD COLUMN IF NOT EXISTS impresora_id INT NULL AFTER orden;
ALTER TABLE productos  ADD COLUMN IF NOT EXISTS impresora_id INT NULL AFTER unidad;

-- Asignación default: Bebidas→Barra (3), Burgers/HotDogs→Cocina 1 (1), Tacos/Quesas/Tortas→Cocina 2 (2)
UPDATE categorias SET impresora_id=3 WHERE nombre='Bebidas';
UPDATE categorias SET impresora_id=1 WHERE nombre IN ('Burgers','Hot Dogs','Al Carbón');
UPDATE categorias SET impresora_id=2 WHERE nombre IN ('Tacos','Quesas','Tortas','Extras');

-- ----- CONFIGURACIÓN GENERAL -----
CREATE TABLE IF NOT EXISTS configuracion (
  clave VARCHAR(50) PRIMARY KEY,
  valor TEXT,
  descripcion VARCHAR(150),
  tipo ENUM('text','number','bool','select','color','textarea') DEFAULT 'text',
  opciones VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

INSERT INTO configuracion (clave, valor, descripcion, tipo) VALUES
  ('negocio_nombre', 'Jacks Taco Rock', 'Nombre comercial', 'text'),
  ('negocio_eslogan','Todo al carbón',   'Eslogan / tagline', 'text'),
  ('negocio_rfc',    'JTR250101AAA',     'RFC fiscal', 'text'),
  ('negocio_razon',  'Jacks Taco Rock S.A. de C.V.', 'Razón social', 'text'),
  ('negocio_direccion', 'Av. Reforma 123, CDMX', 'Dirección', 'textarea'),
  ('negocio_telefono','55 1234 5678',    'Teléfono', 'text'),
  ('negocio_horario','Jueves a Domingo · 6:00 a 11:00 PM', 'Horario operativo', 'text'),
  ('iva_porcentaje', '16', 'Tasa de IVA en %', 'number'),
  ('moneda',         'MXN', 'Código de moneda', 'text'),
  ('moneda_simbolo', '$',   'Símbolo de moneda', 'text'),
  ('propina_sugerida','10,15,20', 'Porcentajes sugeridos de propina (coma separados)', 'text'),
  ('ticket_pie','¡GRACIAS POR TU COMPRA · DIOS TE BENDIGA!', 'Mensaje al pie del ticket', 'textarea')
ON DUPLICATE KEY UPDATE valor=valor;

-- ----- PROMOCIONES -----
CREATE TABLE IF NOT EXISTS promociones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  descripcion VARCHAR(255),
  tipo ENUM('porcentaje','monto_fijo','2x1','3x100') NOT NULL,
  valor DECIMAL(10,2) DEFAULT 0,
  aplicable_a ENUM('todo','categoria','producto') DEFAULT 'todo',
  categoria_id INT NULL,
  producto_id INT NULL,
  desde DATE NULL,
  hasta DATE NULL,
  hora_desde TIME NULL,
  hora_hasta TIME NULL,
  codigo VARCHAR(30) NULL,
  uso_max INT NULL,
  uso_actual INT DEFAULT 0,
  activa TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE ordenes ADD COLUMN IF NOT EXISTS descuento DECIMAL(10,2) DEFAULT 0 AFTER iva;
ALTER TABLE ordenes ADD COLUMN IF NOT EXISTS promocion_id INT NULL AFTER descuento;
ALTER TABLE ordenes ADD COLUMN IF NOT EXISTS propina DECIMAL(10,2) DEFAULT 0 AFTER total;
ALTER TABLE ordenes ADD COLUMN IF NOT EXISTS motivo_cancelacion VARCHAR(255) NULL;

-- ----- CANCELACIONES Y AUDITORÍA -----
ALTER TABLE orden_items ADD COLUMN IF NOT EXISTS cancelado TINYINT(1) DEFAULT 0;
ALTER TABLE orden_items ADD COLUMN IF NOT EXISTS motivo_cancel VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS bitacora (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NULL,
  accion VARCHAR(60) NOT NULL,         -- login,logout,cancel_item,cancel_order,price_change,user_create,etc
  entidad VARCHAR(40) NULL,            -- usuario,producto,orden,mesa,promocion...
  entidad_id INT NULL,
  descripcion VARCHAR(255),
  ip VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_bita_fecha (created_at),
  INDEX idx_bita_user (usuario_id)
) ENGINE=InnoDB;

-- ----- CORTES DE CAJA -----
CREATE TABLE IF NOT EXISTS cortes_caja (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  fondo_inicial DECIMAL(10,2) DEFAULT 0,
  efectivo_esperado DECIMAL(10,2) DEFAULT 0,
  efectivo_contado DECIMAL(10,2) DEFAULT 0,
  diferencia DECIMAL(10,2) DEFAULT 0,
  total_tarjeta DECIMAL(10,2) DEFAULT 0,
  total_transfer DECIMAL(10,2) DEFAULT 0,
  total_otros DECIMAL(10,2) DEFAULT 0,
  total_ventas DECIMAL(10,2) DEFAULT 0,
  num_ordenes INT DEFAULT 0,
  observaciones TEXT,
  abierto_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  cerrado_at TIMESTAMP NULL,
  estado ENUM('abierto','cerrado') DEFAULT 'abierto',
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

ALTER TABLE pagos ADD COLUMN IF NOT EXISTS corte_id INT NULL;

-- ----- MODIFICADORES -----
CREATE TABLE IF NOT EXISTS modificador_grupos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL,
  tipo ENUM('radio','check') DEFAULT 'radio',
  obligatorio TINYINT(1) DEFAULT 0,
  max_selecciones INT DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS modificadores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo_id INT NOT NULL,
  nombre VARCHAR(60) NOT NULL,
  precio_extra DECIMAL(10,2) DEFAULT 0,
  orden INT DEFAULT 0,
  activo TINYINT(1) DEFAULT 1,
  FOREIGN KEY (grupo_id) REFERENCES modificador_grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS producto_modificador_grupo (
  producto_id INT NOT NULL,
  grupo_id INT NOT NULL,
  PRIMARY KEY (producto_id, grupo_id),
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  FOREIGN KEY (grupo_id) REFERENCES modificador_grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE orden_items ADD COLUMN IF NOT EXISTS modificadores_json TEXT NULL;
ALTER TABLE orden_items ADD COLUMN IF NOT EXISTS precio_extra DECIMAL(10,2) DEFAULT 0;

-- Seed: grupos comunes
INSERT INTO modificador_grupos (nombre, tipo, obligatorio) VALUES
  ('Término de la carne', 'radio', 0),
  ('Extras', 'check', 0),
  ('Sin (quitar ingrediente)', 'check', 0);

INSERT INTO modificadores (grupo_id, nombre, precio_extra) VALUES
  (1,'Tres cuartos',0),(1,'Medio',0),(1,'Bien cocido',0),
  (2,'Extra queso',10),(2,'Extra tocino',15),(2,'Aguacate',12),(2,'Jalapeños',5),
  (3,'Sin cebolla',0),(3,'Sin cilantro',0),(3,'Sin picante',0);

-- Asignar grupos a productos (todos los principales)
INSERT IGNORE INTO producto_modificador_grupo (producto_id, grupo_id)
SELECT p.id, 2 FROM productos p WHERE p.categoria_id IN (1,2,3,4,6);
INSERT IGNORE INTO producto_modificador_grupo (producto_id, grupo_id)
SELECT p.id, 3 FROM productos p WHERE p.categoria_id IN (1,2,3,4,5,6);
INSERT IGNORE INTO producto_modificador_grupo (producto_id, grupo_id)
SELECT p.id, 1 FROM productos p WHERE p.categoria_id IN (1,6);

-- ----- COMBOS -----
CREATE TABLE IF NOT EXISTS combos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  descripcion TEXT,
  precio DECIMAL(10,2) NOT NULL,
  emoji VARCHAR(8) DEFAULT '🎁',
  activo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS combo_items (
  combo_id INT NOT NULL,
  producto_id INT NOT NULL,
  cantidad INT DEFAULT 1,
  PRIMARY KEY (combo_id, producto_id),
  FOREIGN KEY (combo_id) REFERENCES combos(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB;

-- Combos de ejemplo
INSERT INTO combos (nombre, descripcion, precio, emoji) VALUES
  ('Combo Parrillero',  'Burros + Tacos Arrachera (3) + Coca', 150, '🔥'),
  ('Combo Familiar',    'Black Angus + Papas + 2 Cocas',       220, '👨‍👩‍👧');

INSERT INTO combo_items (combo_id, producto_id, cantidad) VALUES
  (1, 2, 1),(1, 3, 3),(1, 18, 1),
  (2, 13, 1),(2, 17, 1),(2, 18, 2);

-- ----- PROPINAS -----
CREATE TABLE IF NOT EXISTS propinas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  orden_id INT NOT NULL,
  mesero_id INT NULL,
  monto DECIMAL(10,2) NOT NULL,
  porcentaje INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (orden_id) REFERENCES ordenes(id),
  FOREIGN KEY (mesero_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ----- RESERVACIONES -----
CREATE TABLE IF NOT EXISTS reservaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mesa_id INT NULL,
  cliente_nombre VARCHAR(80) NOT NULL,
  cliente_telefono VARCHAR(20),
  fecha DATE NOT NULL,
  hora TIME NOT NULL,
  personas INT NOT NULL DEFAULT 2,
  notas VARCHAR(255),
  estado ENUM('pendiente','confirmada','llegada','cancelada','no_show') DEFAULT 'pendiente',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mesa_id) REFERENCES mesas(id),
  FOREIGN KEY (created_by) REFERENCES usuarios(id),
  INDEX idx_res_fecha (fecha, hora)
) ENGINE=InnoDB;

-- ----- DESCUENTO MANUAL AL COBRAR (jul 2026) -----
-- Descuento capturado en el momento del cobro (monto + motivo). Se guarda
-- aparte del `descuento` de promociones para no pisarlo y mantener reportes.
-- NOTA: MySQL 8 no soporta "ADD COLUMN IF NOT EXISTS"; correr solo una vez.
ALTER TABLE ordenes
  ADD COLUMN descuento_manual DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER descuento,
  ADD COLUMN descuento_motivo VARCHAR(255) NULL AFTER descuento_manual;

-- ----- AVISOS DE COCINA (recordatorios sueltos, jul 2026) -----
-- Mensajes rápidos a una estación de cocina (ej. "freír bolsas de papas"),
-- no ligados a ninguna comanda/orden. Se muestran en la pantalla de cocina.
CREATE TABLE IF NOT EXISTS avisos_cocina (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cocina TINYINT NOT NULL DEFAULT 2,
  mensaje VARCHAR(160) NOT NULL,
  usuario_id INT NULL,
  estado ENUM('pendiente','atendido') NOT NULL DEFAULT 'pendiente',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atendido_at TIMESTAMP NULL,
  INDEX idx_estado (estado, cocina),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SELECT 'Migraciones aplicadas correctamente' AS resultado;

-- ----- NOMBRE PERSONALIZADO DE MESA (jul 2026) -----
-- Etiqueta opcional para distinguir mesas (ej. "Terraza 1", "Barra K").
-- Si está vacía, la operación muestra "Mesa 05" (el número). MySQL 8: correr una vez.
ALTER TABLE mesas ADD COLUMN nombre VARCHAR(40) NULL AFTER numero;

-- ----- PRODUCTO "PARA LLEVAR" dentro de una cuenta de mesa (jul 2026) -----
-- Marca por producto: en una mesa, un item puede ser "para llevar" (la cocina
-- lo empaca) aunque se cobre junto con la cuenta. MySQL 8: correr una vez.
ALTER TABLE orden_items ADD COLUMN para_llevar TINYINT(1) NOT NULL DEFAULT 0 AFTER comensal;
