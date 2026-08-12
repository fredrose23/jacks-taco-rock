-- ================================================================
-- Jacks Rock · Recarga total de la carta
-- Limpia categorías, productos, modificadores, combos, promociones
-- y todo lo transaccional. Mantiene usuarios, mesas, métodos de pago,
-- impresoras y configuración (solo actualiza valores específicos).
-- ================================================================
USE jacks_rock;

SET FOREIGN_KEY_CHECKS = 0;

-- Limpiar transacciones y datos generados
TRUNCATE TABLE pagos;
TRUNCATE TABLE propinas;
TRUNCATE TABLE movimientos_stock;
TRUNCATE TABLE comandas;
TRUNCATE TABLE orden_items;
TRUNCATE TABLE ordenes;
TRUNCATE TABLE combo_items;
TRUNCATE TABLE combos;
TRUNCATE TABLE promociones;
TRUNCATE TABLE producto_modificador_grupo;
TRUNCATE TABLE modificadores;
TRUNCATE TABLE modificador_grupos;
TRUNCATE TABLE productos;
TRUNCATE TABLE categorias;
TRUNCATE TABLE cortes_caja;
TRUNCATE TABLE reservaciones;
TRUNCATE TABLE bitacora;
TRUNCATE TABLE errores_log;

UPDATE mesas SET estado = 'libre';

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
-- CATEGORÍAS
-- impresora_id: 1 = Cocina 1 (default), 3 = Barra (bebidas)
-- ================================================================
INSERT INTO categorias (id, nombre, orden, impresora_id, activa) VALUES
  (1, 'Originales',          1, 1, 1),
  (2, 'Hamburguesas',        2, 1, 1),
  (3, 'Especiales',          3, 1, 1),
  (4, 'Hot Dogs',            4, 1, 1),
  (5, 'Tacos y Quesadillas', 5, 1, 1),
  (6, 'Bebidas',             6, 3, 1);

-- ================================================================
-- PRODUCTOS
-- cocina_2 = 1 → se prepara en Cocina 2 (freidora / postres)
-- maneja_stock = 1 → controla inventario (solo bebidas embotelladas)
-- ================================================================
INSERT INTO productos
  (categoria_id, nombre, descripcion, precio, emoji,
   destacado, disponible, stock, stock_minimo, unidad, cocina_2, maneja_stock)
VALUES
  -- ===== 1. ORIGINALES =====
  (1, 'La Papona',
   'La mera receta original: papa aplastada al horno de leña con queso y mantequilla por encima, lleva carne asada de arrachera. Cebolla, cilantro, acompañada de totopos.',
   85.00, '🥔', 1, 1, 50, 5, 'pz', 0, 0),

  (1, 'Salchipapas',
   'Receta mejorada: salchichas ahumadas y después fritas, cortadas en finas tiras acompañadas de papas a la francesa.',
   65.00, '🌭', 1, 1, 50, 5, 'pz', 1, 0),

  (1, 'Rassspas Hielito',
   'Raspados de hielo con esencia natural de fruta. Pregunta sabores y disponibilidad. No disponible a domicilio.',
   35.00, '🍧', 1, 1, 50, 5, 'pz', 1, 0),

  (1, 'Papas a la Francesa',
   'Orden de papas a la francesa. Puedes agregarla como extra a cualquier platillo por $15.',
   50.00, '🍟', 0, 1, 50, 5, 'pz', 1, 0),

  -- ===== 2. HAMBURGUESAS =====
  (2, 'Hamburguesa Black',
   'Hamburguesa dos carnes de res Black Angus, premium al carbón, mayonesa, lechuga, tomate, cebolla, queso y piña. Incluye papas a la francesa.',
   125.00, '🍔', 1, 1, 50, 5, 'pz', 0, 0),

  (2, 'Hamburguesa de Pollo',
   'Hamburguesa jugosa dos carnes fritas de pollo, jamón, queso, lechuga, tomate y cebolla. Incluye papas a la francesa.',
   110.00, '🍔', 1, 1, 50, 5, 'pz', 1, 0),

  (2, 'Hamburguesa La Meche (2 carnes)',
   'Hamburguesa de dos carnes Black Angus premium ahumadas al habanero (no pica, solo sabor). Mayonesa, quesos, cebollita a la parrilla, jamón y chorizo de pavo, tocino vegetal. (NO cocinamos puerco). Incluye papas a la francesa.',
   130.00, '🍔', 1, 1, 50, 5, 'pz', 0, 0),

  (2, 'Hamburguesa La Meche (1 carne)',
   'Hamburguesa de una carne Black Angus premium ahumada al habanero (no pica, solo sabor). Mayonesa, quesos, cebollita a la parrilla, jamón y chorizo de pavo, tocino vegetal. Incluye papas a la francesa.',
   110.00, '🍔', 1, 1, 50, 5, 'pz', 0, 0),

  (2, 'Salchiburguer',
   'PROMO. Hamburguesa una carne Black Angus al carbón, con salchichas finamente cortadas, queso, cebollitas asadas y chipotle (no pica). Incluye papas a la francesa.',
   110.00, '🍔', 1, 1, 50, 5, 'pz', 0, 0),

  -- ===== 3. ESPECIALES =====
  (3, 'Guachipilin Chiken (Compartir)',
   'Platillo pop. Papas a la francesa acompañadas de trozos de pollo frito, por la parte de arriba una mezcla de salsas especiales. Porción para compartir.',
   100.00, '🍗', 0, 1, 50, 5, 'pz', 1, 0),

  (3, 'Guachipilin Chiken (Personal)',
   'Platillo pop. Papas a la francesa acompañadas de trozos de pollo frito, por la parte de arriba una mezcla de salsas especiales. Porción personal.',
   75.00, '🍗', 0, 1, 50, 5, 'pz', 1, 0),

  (3, 'Alitas Fritas',
   'Medio kilo de alas de pollo fritas. Solo se puede elegir un sabor por orden. Incluye papas a la francesa y una porción de papa al horno con quesos (Papona).',
   100.00, '🍗', 1, 1, 50, 5, 'pz', 1, 0),

  (3, 'Pollorock and Roll',
   'Pechuga de pollo al carbón, chorizo de pavo, tocino vegetal, fundido en quesos. Cilantro y cebolla, con salsas especiales que le dan un sabor que uff… ¡vuelas! Incluye papas a la francesa, totopos y porción de papa al horno (Papona).',
   85.00, '🍗', 1, 1, 50, 5, 'pz', 0, 0),

  (3, 'Nuggets de Pollo',
   'Orden de nuggets de pollo con papas a la francesa. Porción para adulto, el favorito de los niños.',
   70.00, '🍗', 1, 1, 50, 5, 'pz', 1, 0),

  -- ===== 4. HOT DOGS =====
  (4, 'Hotdog Jacks Jumbo',
   'En un pan grande, dos salchichas de res, con queso y carne asada de arrachera. Cebollitas a la parrilla y chipotle arriba. Incluye papas a la francesa.',
   120.00, '🌭', 1, 1, 50, 5, 'pz', 0, 0),

  (4, 'Hotdog Jacks Person',
   'Pan mediano, una salchicha de res, con queso y carne asada de arrachera. Cebollitas a la parrilla y chipotle arriba. Incluye papas a la francesa.',
   80.00, '🌭', 1, 1, 50, 5, 'pz', 0, 0),

  (4, 'Hotdog Sirloin',
   'Hotdog de sirloin, con queso y carne asada arrachera. Cebollitas a la parrilla y un adorno de chipotle. Incluye papas a la francesa.',
   75.00, '🌭', 1, 1, 50, 5, 'pz', 0, 0),

  (4, 'Hotdog Franck',
   '¡Realmente monstruoso! En un pan grande, dos tipos de salchicha (sirloin y pavo) con queso y carne asada. Cebollitas a la parrilla y chipotle arriba. Incluye una porción de papa aplastada con mantequilla al horno (Papona), gajos receta casera y papas a la francesa.',
   90.00, '🌭', 1, 1, 50, 5, 'pz', 0, 0),

  (4, 'Hawai 420',
   'Dentro de una gran costra de queso, un hotdog hecho de sirloin con piña y más queso fundido. ¡Fresco padrino! Incluye papas a la francesa.',
   75.00, '🌭', 1, 1, 50, 5, 'pz', 0, 0),

  (4, 'Hotdog Invasor',
   'PROMO. ¡De otro planeta! Tiras finas de salchicha de pavo y pollo asadero, con queso y carne asada de arrachera. Aros de cebolla y chipotle arriba. Incluye porción de papa aplastada al horno con mantequilla y quesos (Papona) y papas a la francesa.',
   75.00, '🌭', 1, 1, 50, 5, 'pz', 0, 0),

  -- ===== 5. TACOS Y QUESADILLAS =====
  (5, 'Tacos de Asada',
   'Tacos de asada de arrachera, ¡carne suave! Todos los tacos llevan cilantro y cebolla.',
   15.00, '🌮', 0, 1, 50, 5, 'pz', 0, 0),

  (5, 'Tacos de Birria',
   'Tacos de birria al horno de leña. ¡Chulada! Todos los tacos llevan cilantro y cebolla.',
   15.00, '🌮', 0, 1, 50, 5, 'pz', 0, 0),

  (5, 'Quesadilla de Arrachera',
   'Hecha con maíz azul bien prieto, cilantro y cebolla. Rellena de asada arrachera.',
   45.00, '🫓', 0, 1, 50, 5, 'pz', 0, 0),

  (5, 'Quesadilla de Birria',
   'Hecha con maíz azul bien prieto, cilantro y cebolla. Rellena de birria.',
   45.00, '🫓', 0, 1, 50, 5, 'pz', 0, 0),

  (5, 'Quesabirria',
   'De harina, queso y birria, cilantro y cebolla. PROMO 3 x $100 pesos.',
   35.00, '🧀', 0, 1, 50, 5, 'pz', 0, 0),

  -- ===== 6. BEBIDAS =====
  (6, 'Bebida del día',
   'Pregunta qué bebidas tenemos disponibles hoy. Refrescos, aguas frescas, jugos según disponibilidad.',
   25.00, '🥤', 0, 1, 100, 10, 'pz', 0, 0),

  (6, 'Agua de sabor',
   'Agua de sabor natural de la casa. Pregunta sabor del día.',
   20.00, '💧', 0, 1, 100, 10, 'pz', 0, 0);

-- ================================================================
-- MODIFICADORES
-- ================================================================
INSERT INTO modificador_grupos (id, nombre, tipo, obligatorio, max_selecciones) VALUES
  (1, 'Extras',                   'check', 0, 5),
  (2, 'Sabor de Alitas',          'radio', 1, 1),
  (3, 'Sin (quitar ingrediente)', 'check', 0, 5),
  (4, 'Tipo de carne',            'radio', 0, 1);

INSERT INTO modificadores (grupo_id, nombre, precio_extra, orden, activo) VALUES
  -- ───── Extras (se cobran) ─────
  (1, 'Chorizo de pavo asado',     15.00, 1, 1),
  (1, 'Extra papas a la francesa', 15.00, 2, 1),
  (1, 'Extra queso',               10.00, 3, 1),
  (1, 'Extra carne de arrachera',  25.00, 4, 1),
  (1, 'Tocino vegetal',            10.00, 5, 1),

  -- ───── Sabores de Alitas (obligatorio, sin costo) ─────
  (2, 'Mango Habanero',  0, 1, 1),
  (2, 'BBQ Pica',        0, 2, 1),
  (2, 'BBQ Dulce',       0, 3, 1),
  (2, 'Piña Hot',        0, 4, 1),
  (2, 'Lemon Pepper Hot',0, 5, 1),

  -- ───── Sin (sin costo) ─────
  (3, 'Sin cebolla',   0, 1, 1),
  (3, 'Sin cilantro',  0, 2, 1),
  (3, 'Sin tomate',    0, 3, 1),
  (3, 'Sin queso',     0, 4, 1),
  (3, 'Sin chipotle',  0, 5, 1),
  (3, 'Sin piña',      0, 6, 1),
  (3, 'Sin mayonesa',  0, 7, 1),

  -- ───── Tipo de carne (para quesadillas / tacos) ─────
  (4, 'Asada Arrachera', 0, 1, 1),
  (4, 'Birria',          0, 2, 1),
  (4, 'Mixta',           0, 3, 1);

-- ================================================================
-- ASIGNACIÓN producto ↔ grupo de modificadores
-- ================================================================
-- Extras → casi todos los platillos (no rassspas ni bebidas)
INSERT INTO producto_modificador_grupo (producto_id, grupo_id)
SELECT id, 1 FROM productos
WHERE categoria_id IN (1,2,3,4,5)
  AND nombre NOT IN ('Rassspas Hielito');

-- Sabor de alitas → solo Alitas Fritas (obligatorio)
INSERT INTO producto_modificador_grupo (producto_id, grupo_id)
SELECT id, 2 FROM productos WHERE nombre = 'Alitas Fritas';

-- Sin (quitar) → casi todos
INSERT INTO producto_modificador_grupo (producto_id, grupo_id)
SELECT id, 3 FROM productos
WHERE categoria_id IN (1,2,3,4,5)
  AND nombre NOT IN ('Rassspas Hielito');

-- Tipo de carne → quesabirria (porque puede llevar combinación)
INSERT INTO producto_modificador_grupo (producto_id, grupo_id)
SELECT id, 4 FROM productos
WHERE nombre IN ('Quesabirria');

-- ================================================================
-- CONFIGURACIÓN (actualizada con datos reales del nuevo menú)
-- ================================================================
UPDATE configuracion SET valor = 'Jacks Rock'                                       WHERE clave = 'negocio_nombre';
UPDATE configuracion SET valor = 'Más rock, mejor sabor · Los originales al carbón'  WHERE clave = 'negocio_eslogan';
UPDATE configuracion SET valor = '962 282 1323'                                      WHERE clave = 'negocio_telefono';
UPDATE configuracion SET valor = 'Jueves a Domingo · 6:00 PM a 10:30 PM'             WHERE clave = 'negocio_horario';
UPDATE configuracion SET valor = '¡DIOS TE BENDIGA! · Calidad, sabor e higiene · WhatsApp para domicilio: 962 282 1323' WHERE clave = 'ticket_pie';

-- Avisos extras
INSERT INTO configuracion (clave, valor, descripcion, tipo) VALUES
  ('aviso_no_alcohol',
   'En Jacks Rock NO se vende alcohol. Favor de no introducir bebidas.',
   'Aviso al cliente sobre alcohol', 'textarea'),
  ('aviso_extras',
   'A todos los platillos puedes agregarle extra papas a la francesa por $15. Promociones disponibles, pregunta al mesero.',
   'Nota sobre extras y promociones', 'textarea'),
  ('aviso_pedido',
   'Te recomendamos que tu pedido sea en una sola exhibición. Esto evita las fallas en tu comida y acorta el tiempo de espera.',
   'Mensaje al cliente sobre el pedido', 'textarea'),
  ('whatsapp_domicilio',
   '962 282 1323',
   'WhatsApp para pedidos a domicilio', 'text')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);

-- ================================================================
-- RESUMEN
-- ================================================================
SELECT 'Carta cargada correctamente' AS resultado;
SELECT
  (SELECT COUNT(*) FROM categorias)       AS categorias,
  (SELECT COUNT(*) FROM productos)        AS productos,
  (SELECT COUNT(*) FROM modificador_grupos) AS grupos_modificadores,
  (SELECT COUNT(*) FROM modificadores)    AS modificadores,
  (SELECT COUNT(*) FROM productos WHERE cocina_2=1) AS a_cocina2,
  (SELECT COUNT(*) FROM productos WHERE destacado=1) AS destacados;
