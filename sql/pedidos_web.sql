-- ================================================================
-- PEDIDOS WEB · carrito público + GPS + flujo de aceptación
-- ================================================================
USE jacks_rock;

-- Agregar 'web' al tipo de pedido
ALTER TABLE ordenes
  MODIFY tipo ENUM('local','llevar','domicilio','web') NOT NULL DEFAULT 'local';

-- Estado del pedido web (aceptación por el restaurante)
ALTER TABLE ordenes
  ADD COLUMN IF NOT EXISTS estado_web ENUM('pendiente','aceptado','rechazado') NULL AFTER estado_entrega,
  ADD COLUMN IF NOT EXISTS web_tipo_entrega ENUM('llevar','domicilio') NULL AFTER estado_web,
  ADD COLUMN IF NOT EXISTS cliente_lat DECIMAL(10,7) NULL AFTER cliente_referencias,
  ADD COLUMN IF NOT EXISTS cliente_lng DECIMAL(10,7) NULL AFTER cliente_lat,
  ADD COLUMN IF NOT EXISTS web_motivo_rechazo VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS web_creado_at TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS web_aceptado_at TIMESTAMP NULL,
  ADD INDEX idx_estado_web (estado_web);

-- Guardar lat/lng también en clientes para reuso
ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS lat DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS lng DECIMAL(10,7) NULL;

-- Configuración: número de WhatsApp del restaurante para confirmar pedidos
INSERT INTO configuracion (clave, valor, descripcion, tipo) VALUES
  ('whatsapp_pedidos', '9622821323', 'WhatsApp del restaurante para recibir pedidos web (solo números, con lada)', 'text'),
  ('web_pedidos_activo', '1', 'Permitir pedidos desde la web pública (1=sí, 0=no)', 'bool'),
  ('web_costo_envio', '30', 'Costo de envío para pedidos web a domicilio', 'number')
ON DUPLICATE KEY UPDATE valor = valor;

SELECT 'Pedidos web · esquema listo' AS resultado;
SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='jacks_rock' AND TABLE_NAME='ordenes'
  AND COLUMN_NAME IN ('tipo','estado_web','cliente_lat','cliente_lng','web_tipo_entrega');
