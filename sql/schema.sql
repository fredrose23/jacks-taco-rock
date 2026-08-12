-- ============================================================
-- Jacks Taco Rock · Base de datos
-- ============================================================
DROP DATABASE IF EXISTS jacks_rock;
CREATE DATABASE jacks_rock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jacks_rock;

-- ------------------------------------------------------------
-- Usuarios (meseros, cocina, admin, cajero)
-- ------------------------------------------------------------
CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  usuario VARCHAR(40) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','mesero','cocina','cajero') NOT NULL DEFAULT 'mesero',
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Mesas
-- ------------------------------------------------------------
CREATE TABLE mesas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  numero INT UNIQUE NOT NULL,
  capacidad INT NOT NULL DEFAULT 4,
  estado ENUM('libre','ocupada','por_cobrar') NOT NULL DEFAULT 'libre',
  zona VARCHAR(30) DEFAULT 'Salón',
  activa TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Categorías del menú
-- ------------------------------------------------------------
CREATE TABLE categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(40) UNIQUE NOT NULL,
  orden INT DEFAULT 0,
  activa TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Productos (platillos)
-- ------------------------------------------------------------
CREATE TABLE productos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categoria_id INT NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  descripcion TEXT,
  precio DECIMAL(10,2) NOT NULL,
  emoji VARCHAR(8) DEFAULT '🍽',
  imagen VARCHAR(120) DEFAULT NULL,
  destacado TINYINT(1) DEFAULT 0,
  disponible TINYINT(1) DEFAULT 1,
  stock INT NOT NULL DEFAULT 50,
  stock_minimo INT NOT NULL DEFAULT 5,
  unidad VARCHAR(20) DEFAULT 'pz',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id)
) ENGINE=InnoDB;

CREATE TABLE movimientos_stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  tipo ENUM('entrada','salida','ajuste','merma') NOT NULL,
  cantidad INT NOT NULL,
  stock_resultante INT NOT NULL,
  motivo VARCHAR(120),
  usuario_id INT NULL,
  orden_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Órdenes (cuenta abierta por mesa)
-- ------------------------------------------------------------
CREATE TABLE ordenes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mesa_id INT NOT NULL,
  usuario_id INT NULL,
  estado ENUM('abierta','cobrada','cancelada') NOT NULL DEFAULT 'abierta',
  subtotal DECIMAL(10,2) DEFAULT 0,
  iva DECIMAL(10,2) DEFAULT 0,
  total DECIMAL(10,2) DEFAULT 0,
  abierta_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  cerrada_at TIMESTAMP NULL,
  FOREIGN KEY (mesa_id) REFERENCES mesas(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Detalle de orden (items)
-- ------------------------------------------------------------
CREATE TABLE orden_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  orden_id INT NOT NULL,
  producto_id INT NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  precio DECIMAL(10,2) NOT NULL,
  cantidad INT NOT NULL DEFAULT 1,
  comensal INT DEFAULT 1,
  enviado TINYINT(1) DEFAULT 0,
  comanda_id INT NULL,
  notas VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (orden_id) REFERENCES ordenes(id) ON DELETE CASCADE,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Comandas (impresas en cocina)
-- ------------------------------------------------------------
CREATE TABLE comandas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  orden_id INT NOT NULL,
  mesa_id INT NOT NULL,
  usuario_id INT NULL,
  estado ENUM('nueva','preparando','lista','servida') NOT NULL DEFAULT 'nueva',
  impresa TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  servida_at TIMESTAMP NULL,
  FOREIGN KEY (orden_id) REFERENCES ordenes(id),
  FOREIGN KEY (mesa_id) REFERENCES mesas(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Métodos de pago
-- ------------------------------------------------------------
CREATE TABLE metodos_pago (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(30) UNIQUE NOT NULL,
  nombre VARCHAR(40) NOT NULL,
  icono VARCHAR(8) NOT NULL,
  activo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Pagos (multi-método por orden)
-- ------------------------------------------------------------
CREATE TABLE pagos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  orden_id INT NOT NULL,
  metodo_id INT NOT NULL,
  monto DECIMAL(10,2) NOT NULL,
  referencia VARCHAR(80) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (orden_id) REFERENCES ordenes(id),
  FOREIGN KEY (metodo_id) REFERENCES metodos_pago(id)
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Usuarios (contraseñas: admin123, mesero123, cocina123, cajero123)
INSERT INTO usuarios (nombre, usuario, password_hash, rol) VALUES
('Administrador', 'admin',   '$2y$10$6ahMJo8kmlJgfUdYzvHn7OHetPHAqTAr8dizN5P0MQDzp9ChH/rh.', 'admin'),
('Mesero 1',      'mesero1', '$2y$10$4m4hN6Uw4B45XCWgR6gaP.HauvqbvqjCYWc4xBr7VuPSXKfEjtrCi', 'mesero'),
('Cocina',        'cocina',  '$2y$10$KXQ.yXlGU43DvYB.1NBBNOLd7n.njSPylSU9ozz3/905AhfYIpPTK', 'cocina'),
('Cajero',        'cajero',  '$2y$10$E/HZOf0REuuccqFAbAqbN.J/kbKs72Ej1MqOnbAs2RdUEa7J39pl6', 'cajero');

-- Mesas
INSERT INTO mesas (numero, capacidad, zona) VALUES
(1,2,'Salón'),(2,4,'Salón'),(3,4,'Salón'),(4,6,'Salón'),
(5,2,'Terraza'),(6,4,'Terraza'),(7,8,'Terraza'),(8,4,'Salón'),
(9,2,'Barra'),(10,6,'Salón'),(11,4,'Salón'),(12,4,'Terraza');

-- Categorías
INSERT INTO categorias (nombre, orden) VALUES
('Al Carbón',1),('Tacos',2),('Quesas',3),('Tortas',4),
('Hot Dogs',5),('Burgers',6),('Extras',7),('Bebidas',8);

-- Productos (menú real)
INSERT INTO productos (categoria_id, nombre, descripcion, precio, emoji, destacado, disponible) VALUES
(1,'Papona','Tatemadas y aplastadas con mantequilla y queso, en aluminio va pal horno. Cebolla, cilantro y chips de plátano.',85.00,'🥔',1,1),
(1,'Burros','Al carbón de harina, frijoles refritos a la leña, jamón y chorizo de pavo. Pregunta por arrachera o birria. Incluye papas.',90.00,'🌯',1,1),

(2,'Tacos Arrachera','Tortilla de maíz taquera con cilantro y cebolla.',15.00,'🌮',0,1),
(2,'Tacos Birria','Tortilla de maíz taquera con cilantro y cebolla.',15.00,'🌮',0,1),

(3,'Quesadilla','Tortilla de maíz a prensa manual con cilantro y cebolla. Birria · Arrachera · Mixta.',40.00,'🫓',0,1),
(3,'Quesa-Birria','Tortilla de harina acompañada con cilantro y cebolla. Birria · Arrachera · Promo 3x$100.',35.00,'🧀',0,1),

(4,'Torta Arrachera','Pan de telera, carne, mayonesa, queso, cilantro y cebolla.',70.00,'🥪',0,1),
(4,'Torta Birria','Pan de telera, carne, mayonesa, queso, cilantro y cebolla.',70.00,'🥪',0,1),

(5,'Premium','2 salchichas premium en pan jumbo, queso, carne arrachera top, cebollas caramelizadas y chipotle. Incluye papas.',95.00,'🌭',1,0),
(5,'Chico','1 salchicha premium en medias noches, queso, carne arrachera top, cebollas caramelizadas y chipotle. Incluye papas.',75.00,'🌭',0,0),
(5,'Sirloin','1 salchicha sirloin en medias noches, queso, carne arrachera top, cebollas caramelizadas y chipotle. Incluye papas.',70.00,'🌭',0,1),
(5,'Pavo','1 salchicha pavo en medias noches, queso, carne arrachera top, cebollas caramelizadas y chipotle. Incluye papas.',65.00,'🌭',0,1),

(6,'Black Angus','2 carnes black angus, pan de hamburguesa, mayonesa, queso, lechuga, tomate, cebolla y piña. Incluye papas.',125.00,'🍔',1,1),
(6,'Chicken Cheese','2 carnes de pollo, pan de hamburguesa, jamón y queso. Incluye papas.',100.00,'🍗',0,1),

(7,'Nuggets de Pollo','Crujientes nuggets de pollo.',65.00,'🍗',0,1),
(7,'Salchi-Papas','Salchicha en trozos con papas a la francesa.',65.00,'🍟',0,1),
(7,'Papas Francesas','Orden de papas a la francesa.',50.00,'🍟',0,1),

(8,'Coca-Cola','Refresco frío.',25.00,'🥤',0,1),
(8,'Agua Litro','Agua de sabor 1 litro.',25.00,'💧',0,1),
(8,'Agua Vaso','Agua de sabor por vaso.',15.00,'🥛',0,1);

-- Métodos de pago
INSERT INTO metodos_pago (codigo, nombre, icono) VALUES
('cash','Efectivo','💵'),
('card','Tarjeta','💳'),
('transfer','Transferencia','🏦'),
('qr','QR / SPEI','📱'),
('voucher','Vale','🎟'),
('points','Puntos','⭐');
