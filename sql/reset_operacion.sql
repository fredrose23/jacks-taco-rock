-- ============================================================
-- Reset de datos de OPERACIÓN (pre-producción)
-- ------------------------------------------------------------
-- Vacía: pedidos, pagos, comandas, propinas, movimientos de stock,
--        cortes de caja, turnos, clientes y reservaciones.
-- Conserva: menú (categorías, productos, modificadores, combos),
--           usuarios, mesas (definiciones), métodos de pago,
--           impresoras, configuración y promociones.
-- Mesas: se liberan todas (estado = 'libre'), NO se borran.
--
-- ⚠ Destructivo e irreversible. Haz respaldo antes:
--    mysqldump -u root jacks_rock > backup_antes_reset.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Cadena de pedidos / pagos ──
TRUNCATE TABLE pagos;
TRUNCATE TABLE propinas;
TRUNCATE TABLE comandas;
TRUNCATE TABLE orden_items;
TRUNCATE TABLE ordenes;

-- ── Inventario (historial de movimientos por pedidos de prueba) ──
TRUNCATE TABLE movimientos_stock;

-- ── Caja y turnos ──
TRUNCATE TABLE cortes_caja;
TRUNCATE TABLE turnos;

-- ── Clientes y reservaciones ──
TRUNCATE TABLE clientes;
TRUNCATE TABLE reservaciones;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Liberar todas las mesas (conservando sus definiciones) ──
UPDATE mesas SET estado = 'libre';
