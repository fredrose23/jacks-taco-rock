-- ================================================================
-- SIMPLIFICACIÓN · Modelo "Chulisima" de horario por usuario
-- - Cada usuario tiene UN horario fijo (días + ventana)
-- - Se puede activar o desactivar la restricción
-- - Admin nunca se restringe
-- - Roster pre-programado de turnos: ELIMINADO
-- - Cortes (turnos abiertos/cerrados manualmente) SE MANTIENEN
-- ================================================================
USE jacks_rock;

-- Agregar columnas de horario a usuarios
ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS horario_activo TINYINT(1) DEFAULT 0 AFTER activo,
  ADD COLUMN IF NOT EXISTS dias_laborales VARCHAR(20) DEFAULT NULL AFTER horario_activo,
  ADD COLUMN IF NOT EXISTS hora_entrada TIME NULL AFTER dias_laborales,
  ADD COLUMN IF NOT EXISTS hora_salida  TIME NULL AFTER hora_entrada;

-- Limpiar turnos pre-programados que no se hayan abierto (sin usuario logueado)
DELETE FROM turnos WHERE estado IN ('programado','cancelado','ausente');

-- La tabla turnos sigue existiendo y sirve para el flujo manual:
--   abrir → en_curso → cerrar (con corte de caja del usuario)
-- Pero ya no hay "programado" desde admin: el turno nace cuando el usuario
-- abre su corte manualmente. fecha = hoy automático.

SELECT 'Esquema simplificado a modelo Chulisima' AS resultado;
SELECT COUNT(*) AS turnos_quedaron FROM turnos;
