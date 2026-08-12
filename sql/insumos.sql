-- ============================================================
-- Insumos (materia prima que NO se vende, se consume al cocinar)
-- Ej.: bolsa de papas, panes de hamburguesa, carne, vasos…
-- Control de stock con movimientos MANUALES (entrada/salida/merma/ajuste).
-- Usa DECIMAL para permitir cantidades fraccionarias (2.5 kg, 0.75 bolsa).
-- ============================================================

CREATE TABLE IF NOT EXISTS insumos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(80) NOT NULL,
  unidad        VARCHAR(20) NOT NULL DEFAULT 'pieza',   -- pieza, kg, g, litro, ml, bolsa, paquete…
  stock         DECIMAL(10,3) NOT NULL DEFAULT 0,
  stock_minimo  DECIMAL(10,3) NOT NULL DEFAULT 0,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS movimientos_insumo (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  insumo_id         INT NOT NULL,
  tipo              ENUM('entrada','salida','ajuste','merma') NOT NULL,
  cantidad          DECIMAL(10,3) NOT NULL,
  stock_resultante  DECIMAL(10,3) NOT NULL,
  motivo            VARCHAR(120) DEFAULT NULL,
  usuario_id        INT NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (insumo_id) REFERENCES insumos(id) ON DELETE CASCADE
) ENGINE=InnoDB;
