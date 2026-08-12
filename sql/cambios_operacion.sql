-- ============================================================
-- Cambios operativos (lote #2–#8)
-- Aplicar en local primero; en producción al final de las pruebas.
-- ============================================================

-- ── #3 Cancelación parcial de items (en cualquier estado) ──
-- cantidad_cancelada permite cancelar parte de una línea (ej. 1 de 5 papas).
-- cancelado / motivo_cancel ya existían; agregamos auditoría.
ALTER TABLE orden_items
  ADD COLUMN IF NOT EXISTS cantidad_cancelada INT NOT NULL DEFAULT 0 AFTER cancelado,
  ADD COLUMN IF NOT EXISTS cancelado_at TIMESTAMP NULL AFTER motivo_cancel,
  ADD COLUMN IF NOT EXISTS cancelado_por INT NULL AFTER cancelado_at;

-- ── #8 Venta de mostrador / venta rápida ──
-- Nuevo tipo de orden 'mostrador' (no usa mesa ni cocina).
ALTER TABLE ordenes
  MODIFY COLUMN tipo ENUM('local','llevar','domicilio','web','mostrador')
  NOT NULL DEFAULT 'local';
