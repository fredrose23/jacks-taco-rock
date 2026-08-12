-- ============================================================
-- Corrige pagos viejos que guardaron el efectivo ENTREGADO (con cambio)
-- en lugar del monto aplicado a la cuenta. Recorta cada pago para que la
-- suma de pagos de una orden no exceda su total cobrable (total + envío).
--
-- Solo afecta datos YA registrados (las nuevas ventas ya se guardan bien).
-- Haz respaldo antes:  mysqldump -u root jacks_rock > backup.sql
-- ============================================================

-- Recorta el pago al total cobrable de su orden cuando lo excede.
-- (Válido para órdenes con un solo pago, que es el caso de los datos de prueba.)
UPDATE pagos p
JOIN ordenes o ON o.id = p.orden_id
JOIN (
    SELECT orden_id, COUNT(*) AS n_pagos, SUM(monto) AS suma
    FROM pagos GROUP BY orden_id
) agg ON agg.orden_id = p.orden_id
SET p.monto = o.total + COALESCE(o.costo_envio,0) + COALESCE(o.propina,0)
WHERE agg.n_pagos = 1
  AND p.monto > o.total + COALESCE(o.costo_envio,0) + COALESCE(o.propina,0);
