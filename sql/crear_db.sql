-- ============================================================
-- Jacks Rock · Script COMPLETO para crear la base de datos
-- ------------------------------------------------------------
-- Incluye: todas las tablas (estructura final con todas las
-- mejoras) + datos de catálogo y configuración (usuarios,
-- menú, mesas, métodos de pago, horarios, config).
-- NO incluye datos operativos (ventas, pagos, turnos, etc.).
--
-- Uso (línea de comandos):
--   mysql -u root -p < crear_db.sql
-- Uso (phpMyAdmin): Importar este archivo.
--
-- ⚠ Si la base 'jacks_rock' ya existe, los DROP TABLE de abajo
--   REEMPLAZAN sus tablas. Haz respaldo antes si tienes datos.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `jacks_rock`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `jacks_rock`;

-- ============================================================
-- 1) ESTRUCTURA (todas las tablas)
-- ============================================================

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `bitacora`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bitacora` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `accion` varchar(60) NOT NULL,
  `entidad` varchar(40) DEFAULT NULL,
  `entidad_id` int(11) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bita_fecha` (`created_at`),
  KEY `idx_bita_user` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=126 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(40) NOT NULL,
  `orden` int(11) DEFAULT 0,
  `impresora_id` int(11) DEFAULT NULL,
  `activa` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `telefono` varchar(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `referencias` varchar(255) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `total_pedidos` int(11) DEFAULT 0,
  `total_gastado` decimal(10,2) DEFAULT 0.00,
  `last_order_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telefono` (`telefono`),
  KEY `idx_clientes_telefono` (`telefono`),
  KEY `idx_clientes_nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comandas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comandas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `orden_id` int(11) NOT NULL,
  `mesa_id` int(11) DEFAULT NULL,
  `cocina` tinyint(4) DEFAULT 1,
  `usuario_id` int(11) DEFAULT NULL,
  `estado` enum('nueva','preparando','lista','servida') NOT NULL DEFAULT 'nueva',
  `impresa` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `servida_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `orden_id` (`orden_id`),
  KEY `mesa_id` (`mesa_id`),
  CONSTRAINT `comandas_ibfk_1` FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`),
  CONSTRAINT `comandas_ibfk_2` FOREIGN KEY (`mesa_id`) REFERENCES `mesas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `combo_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `combo_items` (
  `combo_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` int(11) DEFAULT 1,
  PRIMARY KEY (`combo_id`,`producto_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `combo_items_ibfk_1` FOREIGN KEY (`combo_id`) REFERENCES `combos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `combo_items_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `combos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `combos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL,
  `emoji` varchar(8) DEFAULT '?',
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `configuracion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `configuracion` (
  `clave` varchar(50) NOT NULL,
  `valor` text DEFAULT NULL,
  `descripcion` varchar(150) DEFAULT NULL,
  `tipo` enum('text','number','bool','select','color','textarea') DEFAULT 'text',
  `opciones` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cortes_caja`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cortes_caja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `fondo_inicial` decimal(10,2) DEFAULT 0.00,
  `efectivo_esperado` decimal(10,2) DEFAULT 0.00,
  `efectivo_contado` decimal(10,2) DEFAULT 0.00,
  `diferencia` decimal(10,2) DEFAULT 0.00,
  `total_tarjeta` decimal(10,2) DEFAULT 0.00,
  `total_transfer` decimal(10,2) DEFAULT 0.00,
  `total_otros` decimal(10,2) DEFAULT 0.00,
  `total_ventas` decimal(10,2) DEFAULT 0.00,
  `num_ordenes` int(11) DEFAULT 0,
  `observaciones` text DEFAULT NULL,
  `abierto_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cerrado_at` timestamp NULL DEFAULT NULL,
  `estado` enum('abierto','cerrado') DEFAULT 'abierto',
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `cortes_caja_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `errores_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `errores_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(20) NOT NULL,
  `mensaje` text NOT NULL,
  `archivo` varchar(255) DEFAULT NULL,
  `linea` int(11) DEFAULT NULL,
  `stack` text DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `metodo` varchar(10) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `resuelto` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fecha` (`created_at`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_resuelto` (`resuelto`)
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `horarios_atencion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `horarios_atencion` (
  `dia` tinyint(4) NOT NULL,
  `abierto` tinyint(4) DEFAULT 0,
  `hora_inicio` time DEFAULT '18:00:00',
  `hora_fin` time DEFAULT '22:30:00',
  PRIMARY KEY (`dia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `impresoras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `impresoras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `ubicacion` varchar(30) NOT NULL,
  `tipo` enum('comanda','ticket','ambos') DEFAULT 'comanda',
  `driver` varchar(40) DEFAULT 'navegador',
  `destino` varchar(120) DEFAULT NULL,
  `ancho_mm` int(11) DEFAULT 80,
  `copias` int(11) DEFAULT 1,
  `activa` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `insumos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `insumos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `unidad` varchar(20) NOT NULL DEFAULT 'pieza',
  `stock` decimal(10,3) NOT NULL DEFAULT 0.000,
  `stock_minimo` decimal(10,3) NOT NULL DEFAULT 0.000,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mesas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mesas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `numero` int(11) NOT NULL,
  `capacidad` int(11) NOT NULL DEFAULT 4,
  `estado` enum('libre','ocupada','por_cobrar') NOT NULL DEFAULT 'libre',
  `zona` varchar(30) DEFAULT 'Salón',
  `descripcion` varchar(255) DEFAULT NULL,
  `activa` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero` (`numero`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `metodos_pago`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `metodos_pago` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) NOT NULL,
  `nombre` varchar(40) NOT NULL,
  `icono` varchar(8) NOT NULL,
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modificador_grupos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `modificador_grupos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `tipo` enum('radio','check') DEFAULT 'radio',
  `obligatorio` tinyint(1) DEFAULT 0,
  `max_selecciones` int(11) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modificadores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `modificadores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grupo_id` int(11) NOT NULL,
  `nombre` varchar(60) NOT NULL,
  `precio_extra` decimal(10,2) DEFAULT 0.00,
  `orden` int(11) DEFAULT 0,
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `grupo_id` (`grupo_id`),
  CONSTRAINT `modificadores_ibfk_1` FOREIGN KEY (`grupo_id`) REFERENCES `modificador_grupos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `movimientos_insumo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movimientos_insumo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `insumo_id` int(11) NOT NULL,
  `tipo` enum('entrada','salida','ajuste','merma') NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `stock_resultante` decimal(10,3) NOT NULL,
  `motivo` varchar(120) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `insumo_id` (`insumo_id`),
  CONSTRAINT `movimientos_insumo_ibfk_1` FOREIGN KEY (`insumo_id`) REFERENCES `insumos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `movimientos_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movimientos_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `tipo` enum('entrada','salida','ajuste','merma') NOT NULL,
  `cantidad` int(11) NOT NULL,
  `stock_resultante` int(11) NOT NULL,
  `motivo` varchar(120) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `orden_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `movimientos_stock_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `orden_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orden_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `orden_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `precio` decimal(10,2) NOT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 1,
  `comensal` int(11) DEFAULT 1,
  `enviado` tinyint(1) DEFAULT 0,
  `comanda_id` int(11) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cancelado` tinyint(1) DEFAULT 0,
  `cantidad_cancelada` int(11) NOT NULL DEFAULT 0,
  `motivo_cancel` varchar(255) DEFAULT NULL,
  `cancelado_at` timestamp NULL DEFAULT NULL,
  `cancelado_por` int(11) DEFAULT NULL,
  `modificadores_json` text DEFAULT NULL,
  `precio_extra` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `orden_id` (`orden_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `orden_items_ibfk_1` FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `orden_items_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ordenes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ordenes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mesa_id` int(11) DEFAULT NULL,
  `tipo` enum('local','llevar','domicilio','web','mostrador') NOT NULL DEFAULT 'local',
  `cliente_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `estado` enum('abierta','cobrada','cancelada') NOT NULL DEFAULT 'abierta',
  `subtotal` decimal(10,2) DEFAULT 0.00,
  `iva` decimal(10,2) DEFAULT 0.00,
  `descuento` decimal(10,2) DEFAULT 0.00,
  `promocion_id` int(11) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT 0.00,
  `propina` decimal(10,2) DEFAULT 0.00,
  `abierta_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cerrada_at` timestamp NULL DEFAULT NULL,
  `motivo_cancelacion` varchar(255) DEFAULT NULL,
  `cliente_nombre` varchar(100) DEFAULT NULL,
  `cliente_telefono` varchar(20) DEFAULT NULL,
  `cliente_direccion` varchar(255) DEFAULT NULL,
  `cliente_referencias` varchar(255) DEFAULT NULL,
  `cliente_lat` decimal(10,7) DEFAULT NULL,
  `cliente_lng` decimal(10,7) DEFAULT NULL,
  `repartidor_id` int(11) DEFAULT NULL,
  `costo_envio` decimal(10,2) DEFAULT 0.00,
  `paga_con` decimal(10,2) DEFAULT NULL,
  `web_metodo_pago` enum('efectivo','tarjeta','transferencia') DEFAULT NULL,
  `envio_distancia_km` decimal(6,2) DEFAULT NULL,
  `envio_por_cotizar` tinyint(1) DEFAULT 0,
  `estado_entrega` enum('pendiente','asignada','en_camino','entregada','no_entregada','cancelada') DEFAULT NULL,
  `estado_web` enum('pendiente','aceptado','rechazado') DEFAULT NULL,
  `web_tipo_entrega` enum('llevar','domicilio') DEFAULT NULL,
  `hora_pickup` time DEFAULT NULL,
  `en_camino_at` timestamp NULL DEFAULT NULL,
  `entregada_at` timestamp NULL DEFAULT NULL,
  `turno_id` int(11) DEFAULT NULL,
  `web_motivo_rechazo` varchar(255) DEFAULT NULL,
  `web_creado_at` timestamp NULL DEFAULT NULL,
  `web_aceptado_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mesa_id` (`mesa_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_ordenes_tipo` (`tipo`),
  KEY `idx_ordenes_repartidor` (`repartidor_id`),
  KEY `idx_ordenes_cliente` (`cliente_id`),
  KEY `idx_ordenes_turno` (`turno_id`),
  KEY `idx_estado_web` (`estado_web`),
  CONSTRAINT `fk_ordenes_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_ordenes_repartidor` FOREIGN KEY (`repartidor_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_ordenes_turno` FOREIGN KEY (`turno_id`) REFERENCES `turnos` (`id`),
  CONSTRAINT `ordenes_ibfk_1` FOREIGN KEY (`mesa_id`) REFERENCES `mesas` (`id`),
  CONSTRAINT `ordenes_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `orden_id` int(11) NOT NULL,
  `metodo_id` int(11) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `referencia` varchar(80) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `corte_id` int(11) DEFAULT NULL,
  `turno_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `orden_id` (`orden_id`),
  KEY `metodo_id` (`metodo_id`),
  KEY `idx_pagos_turno` (`turno_id`),
  CONSTRAINT `pagos_ibfk_1` FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`),
  CONSTRAINT `pagos_ibfk_2` FOREIGN KEY (`metodo_id`) REFERENCES `metodos_pago` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `producto_modificador_grupo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `producto_modificador_grupo` (
  `producto_id` int(11) NOT NULL,
  `grupo_id` int(11) NOT NULL,
  PRIMARY KEY (`producto_id`,`grupo_id`),
  KEY `grupo_id` (`grupo_id`),
  CONSTRAINT `producto_modificador_grupo_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `producto_modificador_grupo_ibfk_2` FOREIGN KEY (`grupo_id`) REFERENCES `modificador_grupos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` int(11) NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL,
  `emoji` varchar(8) DEFAULT '?',
  `imagen` varchar(120) DEFAULT NULL,
  `destacado` tinyint(1) DEFAULT 0,
  `disponible` tinyint(1) DEFAULT 1,
  `stock` int(11) NOT NULL DEFAULT 50,
  `stock_minimo` int(11) NOT NULL DEFAULT 5,
  `unidad` varchar(20) DEFAULT 'pz',
  `impresora_id` int(11) DEFAULT NULL,
  `cocina_2` tinyint(1) DEFAULT 0,
  `maneja_stock` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `categoria_id` (`categoria_id`),
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `promociones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promociones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `tipo` enum('porcentaje','monto_fijo','2x1','3x100') NOT NULL,
  `valor` decimal(10,2) DEFAULT 0.00,
  `aplicable_a` enum('todo','categoria','producto') DEFAULT 'todo',
  `categoria_id` int(11) DEFAULT NULL,
  `producto_id` int(11) DEFAULT NULL,
  `desde` date DEFAULT NULL,
  `hasta` date DEFAULT NULL,
  `hora_desde` time DEFAULT NULL,
  `hora_hasta` time DEFAULT NULL,
  `codigo` varchar(30) DEFAULT NULL,
  `uso_max` int(11) DEFAULT NULL,
  `uso_actual` int(11) DEFAULT 0,
  `activa` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `propinas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `propinas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `orden_id` int(11) NOT NULL,
  `mesero_id` int(11) DEFAULT NULL,
  `monto` decimal(10,2) NOT NULL,
  `porcentaje` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `turno_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `orden_id` (`orden_id`),
  KEY `mesero_id` (`mesero_id`),
  KEY `idx_propinas_turno` (`turno_id`),
  CONSTRAINT `propinas_ibfk_1` FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`),
  CONSTRAINT `propinas_ibfk_2` FOREIGN KEY (`mesero_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reservaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mesa_id` int(11) DEFAULT NULL,
  `cliente_nombre` varchar(80) NOT NULL,
  `cliente_telefono` varchar(20) DEFAULT NULL,
  `fecha` date NOT NULL,
  `hora` time NOT NULL,
  `personas` int(11) NOT NULL DEFAULT 2,
  `notas` varchar(255) DEFAULT NULL,
  `estado` enum('pendiente','confirmada','llegada','cancelada','no_show') DEFAULT 'pendiente',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `mesa_id` (`mesa_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_res_fecha` (`fecha`,`hora`),
  CONSTRAINT `reservaciones_ibfk_1` FOREIGN KEY (`mesa_id`) REFERENCES `mesas` (`id`),
  CONSTRAINT `reservaciones_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `turnos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `turnos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL,
  `estado` enum('programado','en_curso','cerrado','ausente','cancelado') NOT NULL DEFAULT 'programado',
  `abierto_at` timestamp NULL DEFAULT NULL,
  `cerrado_at` timestamp NULL DEFAULT NULL,
  `fondo_inicial` decimal(10,2) DEFAULT 0.00,
  `efectivo_esperado` decimal(10,2) DEFAULT 0.00,
  `efectivo_contado` decimal(10,2) DEFAULT 0.00,
  `diferencia` decimal(10,2) DEFAULT 0.00,
  `total_tarjeta` decimal(10,2) DEFAULT 0.00,
  `total_transfer` decimal(10,2) DEFAULT 0.00,
  `total_otros` decimal(10,2) DEFAULT 0.00,
  `total_ventas` decimal(10,2) DEFAULT 0.00,
  `total_propinas` decimal(10,2) DEFAULT 0.00,
  `total_envios` decimal(10,2) DEFAULT 0.00,
  `num_ordenes` int(11) DEFAULT 0,
  `asignado_por` int(11) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `observaciones_cierre` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `asignado_por` (`asignado_por`),
  KEY `idx_turnos_usuario_fecha` (`usuario_id`,`fecha`),
  KEY `idx_turnos_fecha_estado` (`fecha`,`estado`),
  CONSTRAINT `turnos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `turnos_ibfk_2` FOREIGN KEY (`asignado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `usuario` varchar(40) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `rol` enum('admin','mesero','cocina','cajero','repartidor') NOT NULL DEFAULT 'mesero',
  `activo` tinyint(1) DEFAULT 1,
  `horario_activo` tinyint(1) DEFAULT 0,
  `dias_laborales` varchar(20) DEFAULT NULL,
  `hora_entrada` time DEFAULT NULL,
  `hora_salida` time DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `permisos_extra` varchar(255) DEFAULT NULL COMMENT 'M?dulos extra dados a este usuario, CSV',
  `permisos_quitar` varchar(255) DEFAULT NULL COMMENT 'M?dulos quitados a este usuario, CSV',
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario` (`usuario`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- ============================================================
-- 2) DATOS DE CATÁLOGO Y CONFIGURACIÓN
--    (usuarios, menú, mesas, métodos de pago, horarios, config)
-- ============================================================
SET FOREIGN_KEY_CHECKS=0;

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` (`id`, `nombre`, `usuario`, `password_hash`, `rol`, `activo`, `horario_activo`, `dias_laborales`, `hora_entrada`, `hora_salida`, `created_at`, `permisos_extra`, `permisos_quitar`) VALUES (1,'Administrador','admin','$2y$10$6ahMJo8kmlJgfUdYzvHn7OHetPHAqTAr8dizN5P0MQDzp9ChH/rh.','admin',1,0,NULL,NULL,NULL,'2026-05-30 02:59:13',NULL,NULL),(2,'Mesero 1','mesero1','$2y$10$4m4hN6Uw4B45XCWgR6gaP.HauvqbvqjCYWc4xBr7VuPSXKfEjtrCi','mesero',0,0,NULL,NULL,NULL,'2026-05-30 02:59:13',NULL,NULL),(3,'Cocina','cocina','$2y$10$KXQ.yXlGU43DvYB.1NBBNOLd7n.njSPylSU9ozz3/905AhfYIpPTK','cocina',1,0,NULL,NULL,NULL,'2026-05-30 02:59:13',NULL,NULL),(4,'Cajero','cajero','$2y$10$E/HZOf0REuuccqFAbAqbN.J/kbKs72Ej1MqOnbAs2RdUEa7J39pl6','cajero',1,0,NULL,NULL,NULL,'2026-05-30 02:59:13',NULL,NULL),(5,'JOSE ANTONIO RIVERA','tetesk','$2y$10$aQptSOrlNTbBCsy.8ET.E.GOMt2zJpua97YcuEKSOQLqXeFz7UVG6','repartidor',1,0,'4,5,6,7','18:00:00','00:00:00','2026-06-18 16:29:21',NULL,NULL),(6,'Guillermo Bacilio Santos','memo','$2y$10$liNKydqXj3lpNexw9W8LJet2udnVp2PGxCmWBknLRd087BFGT5Bxu','repartidor',1,0,NULL,NULL,NULL,'2026-06-18 16:33:47',NULL,NULL),(7,'Leonardo Marroquin escobar','leo','$2y$10$w60XRywW8OtrC4PT7gxlauHc6/Zd5jhVf5dfyF0a8YjR0.6wKvWmG','mesero',1,0,NULL,NULL,NULL,'2026-06-24 23:56:25',NULL,NULL),(8,'alexandro felipe bielma martinez','felipe','$2y$10$3g/Ck5HahR1eWj107bQzmeXTnct5R0A7zVAIfmQgnNSJsF0kN.FWS','mesero',1,0,NULL,NULL,NULL,'2026-06-24 23:59:01',NULL,NULL),(9,'jaqueline giron','jaqui','$2y$10$CgjzUwFDzyDNdpsC9NVKFOzzflYkdgMd6jt0sFK/3QgP/Iwc05AdK','admin',1,0,NULL,NULL,NULL,'2026-06-25 00:00:15',NULL,NULL),(10,'Alan paolo orellana marroquin','paolo','$2y$10$5SdYF3263rmZxtPdAgy6iOO1Zu0SbWK6y/TBa76nuwJoxYX0NZQ8W','mesero',1,0,NULL,NULL,NULL,'2026-06-25 00:02:06',NULL,NULL),(11,'carlos rafael flores cruz','rafa','$2y$10$X0baAshLAc2UZSd3fjX7quB.1PpmT3ZX/UqxuxG0z1dYa9WqXY3bq','admin',1,0,NULL,NULL,NULL,'2026-06-25 00:03:04',NULL,NULL);
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `mesas` WRITE;
/*!40000 ALTER TABLE `mesas` DISABLE KEYS */;
INSERT INTO `mesas` (`id`, `numero`, `capacidad`, `estado`, `zona`, `descripcion`, `activa`) VALUES (1,1,2,'libre','Salón','barra',1),(2,2,4,'libre','Salón',NULL,1),(3,3,4,'libre','Salón',NULL,1),(4,4,6,'libre','Salón',NULL,1),(5,5,2,'libre','Terraza',NULL,1),(6,6,4,'libre','Terraza',NULL,1),(7,7,8,'libre','Terraza',NULL,1),(8,8,4,'libre','Salón',NULL,1),(9,9,2,'libre','Barra',NULL,1),(10,10,6,'libre','Salón',NULL,1),(11,11,4,'libre','Salón',NULL,1),(12,12,4,'libre','Terraza',NULL,1);
/*!40000 ALTER TABLE `mesas` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `categorias` WRITE;
/*!40000 ALTER TABLE `categorias` DISABLE KEYS */;
INSERT INTO `categorias` (`id`, `nombre`, `orden`, `impresora_id`, `activa`) VALUES (1,'Originales',1,1,1),(2,'Hamburguesas',2,1,1),(3,'Especiales',3,1,1),(4,'Hot Dogs',4,1,1),(5,'Tacos y Quesadillas',5,1,1),(6,'Bebidas',6,3,1);
/*!40000 ALTER TABLE `categorias` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `productos` WRITE;
/*!40000 ALTER TABLE `productos` DISABLE KEYS */;
INSERT INTO `productos` (`id`, `categoria_id`, `nombre`, `descripcion`, `precio`, `emoji`, `imagen`, `destacado`, `disponible`, `stock`, `stock_minimo`, `unidad`, `impresora_id`, `cocina_2`, `maneja_stock`, `created_at`) VALUES (1,1,'La Papona','La mera receta original: papa aplastada al horno de leña con queso y mantequilla por encima, lleva carne asada de arrachera. Cebolla, cilantro, acompañada de totopos.',85.00,'🥔',NULL,0,0,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(2,1,'Salchipapas','Receta mejorada: salchichas ahumadas y después fritas, cortadas en finas tiras acompañadas de papas a la francesa.',65.00,'🌭',NULL,0,1,50,5,'pz',NULL,1,0,'2026-05-31 18:15:15'),(3,1,'Rassspas Hielito','Raspados de hielo con esencia natural de fruta. Pregunta sabores y disponibilidad. No disponible a domicilio.',35.00,'🍧',NULL,0,1,50,5,'pz',NULL,1,0,'2026-05-31 18:15:15'),(4,1,'Papas a la Francesa','Orden de papas a la francesa. Puedes agregarla como extra a cualquier platillo por $15.',50.00,'🍟',NULL,0,1,50,5,'pz',NULL,1,0,'2026-05-31 18:15:15'),(5,2,'Hamburguesa Black','Hamburguesa dos carnes de res Black Angus, premium al carbón, mayonesa, lechuga, tomate, cebolla, queso y piña. Incluye papas a la francesa.',125.00,'🍔',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(6,2,'Hamburguesa de Pollo','Hamburguesa jugosa dos carnes fritas de pollo, jamón, queso, lechuga, tomate y cebolla. Incluye papas a la francesa.',110.00,'🍔',NULL,1,1,50,5,'pz',NULL,1,0,'2026-05-31 18:15:15'),(7,2,'Hamburguesa La Meche (2 carnes)','Hamburguesa de dos carnes Black Angus premium ahumadas al habanero (no pica, solo sabor). Mayonesa, quesos, cebollita a la parrilla, jamón y chorizo de pavo, tocino vegetal. (NO cocinamos puerco). Incluye papas a la francesa.',130.00,'🍔',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(8,2,'Hamburguesa La Meche (1 carne)','Hamburguesa de una carne Black Angus premium ahumada al habanero (no pica, solo sabor). Mayonesa, quesos, cebollita a la parrilla, jamón y chorizo de pavo, tocino vegetal. Incluye papas a la francesa.',110.00,'🍔',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(9,2,'Salchiburguer','PROMO. Hamburguesa una carne Black Angus al carbón, con salchichas finamente cortadas, queso, cebollitas asadas y chipotle (no pica). Incluye papas a la francesa.',110.00,'🍔',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(10,3,'Guachipilin Chiken (Compartir)','Platillo pop. Papas a la francesa acompañadas de trozos de pollo frito, por la parte de arriba una mezcla de salsas especiales. Porción para compartir.',100.00,'🍗',NULL,0,1,50,5,'pz',NULL,1,0,'2026-05-31 18:15:15'),(11,3,'Guachipilin Chiken (Personal)','Platillo pop. Papas a la francesa acompañadas de trozos de pollo frito, por la parte de arriba una mezcla de salsas especiales. Porción personal.',75.00,'🍗',NULL,0,1,50,5,'pz',NULL,1,0,'2026-05-31 18:15:15'),(12,3,'Alitas Fritas','Medio kilo de alas de pollo fritas. Solo se puede elegir un sabor por orden. Incluye papas a la francesa y una porción de papa al horno con quesos (Papona).',100.00,'🍗',NULL,1,1,50,5,'pz',NULL,1,0,'2026-05-31 18:15:15'),(13,3,'Pollorock and Roll','Pechuga de pollo al carbón, chorizo de pavo, tocino vegetal, fundido en quesos. Cilantro y cebolla, con salsas especiales que le dan un sabor que uff… ¡vuelas! Incluye papas a la francesa, totopos y porción de papa al horno (Papona).',85.00,'🍗',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(14,3,'Nuggets de Pollo','Orden de nuggets de pollo con papas a la francesa. Porción para adulto, el favorito de los niños.',70.00,'🍗',NULL,1,1,50,5,'pz',NULL,1,0,'2026-05-31 18:15:15'),(15,4,'Hotdog Jacks Jumbo','En un pan grande, dos salchichas de res, con queso y carne asada de arrachera. Cebollitas a la parrilla y chipotle arriba. Incluye papas a la francesa.',120.00,'🌭',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(16,4,'Hotdog Jacks Person','Pan mediano, una salchicha de res, con queso y carne asada de arrachera. Cebollitas a la parrilla y chipotle arriba. Incluye papas a la francesa.',80.00,'🌭',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(17,4,'Hotdog Sirloin','Hotdog de sirloin, con queso y carne asada arrachera. Cebollitas a la parrilla y un adorno de chipotle. Incluye papas a la francesa.',75.00,'🌭',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(18,4,'Hotdog Franck','¡Realmente monstruoso! En un pan grande, dos tipos de salchicha (sirloin y pavo) con queso y carne asada. Cebollitas a la parrilla y chipotle arriba. Incluye una porción de papa aplastada con mantequilla al horno (Papona), gajos receta casera y papas a la francesa.',90.00,'🌭',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(19,4,'Hawai 420','Dentro de una gran costra de queso, un hotdog hecho de sirloin con piña y más queso fundido. ¡Fresco padrino! Incluye papas a la francesa.',75.00,'🌭',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(20,4,'Hotdog Invasor','PROMO. ¡De otro planeta! Tiras finas de salchicha de pavo y pollo asadero, con queso y carne asada de arrachera. Aros de cebolla y chipotle arriba. Incluye porción de papa aplastada al horno con mantequilla y quesos (Papona) y papas a la francesa.',75.00,'🌭',NULL,1,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(21,5,'Tacos de Asada','Tacos de asada de arrachera, ¡carne suave! Todos los tacos llevan cilantro y cebolla.',15.00,'🌮',NULL,0,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(22,5,'Tacos de Birria','Tacos de birria al horno de leña. ¡Chulada! Todos los tacos llevan cilantro y cebolla.',15.00,'🌮',NULL,0,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(23,5,'Quesadilla de Arrachera','Hecha con maíz azul bien prieto, cilantro y cebolla. Rellena de asada arrachera.',45.00,'🫓',NULL,0,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(24,5,'Quesadilla de Birria','Hecha con maíz azul bien prieto, cilantro y cebolla. Rellena de birria.',45.00,'🫓',NULL,0,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(25,5,'Quesabirria','De harina, queso y birria, cilantro y cebolla. PROMO 3 x $100 pesos.',35.00,'🧀',NULL,0,1,50,5,'pz',NULL,0,0,'2026-05-31 18:15:15'),(26,6,'Bebida del día','Pregunta qué bebidas tenemos disponibles hoy. Refrescos, aguas frescas, jugos según disponibilidad.',25.00,'🥤',NULL,0,1,100,10,'pz',NULL,0,0,'2026-05-31 18:15:15'),(27,6,'Agua de sabor','Agua de sabor natural de la casa. Pregunta sabor del día.',20.00,'💧',NULL,0,1,100,10,'pz',NULL,0,0,'2026-05-31 18:15:15');
/*!40000 ALTER TABLE `productos` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `metodos_pago` WRITE;
/*!40000 ALTER TABLE `metodos_pago` DISABLE KEYS */;
INSERT INTO `metodos_pago` (`id`, `codigo`, `nombre`, `icono`, `activo`) VALUES (1,'cash','Efectivo','💵',1),(2,'card','Tarjeta','💳',1),(3,'transfer','Transferencia','🏦',1),(4,'qr','QR / SPEI','📱',1),(5,'voucher','Vale','🎟',1),(6,'points','Puntos','⭐',1);
/*!40000 ALTER TABLE `metodos_pago` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `configuracion` WRITE;
/*!40000 ALTER TABLE `configuracion` DISABLE KEYS */;
INSERT INTO `configuracion` (`clave`, `valor`, `descripcion`, `tipo`, `opciones`) VALUES ('aviso_extras','A todos los platillos puedes agregarle extra papas a la francesa por $15. Promociones disponibles, pregunta al mesero.','Nota sobre extras y promociones','textarea',NULL),('aviso_no_alcohol','En Jacks Rock NO se vende alcohol. Favor de no introducir bebidas.','Aviso al cliente sobre alcohol','textarea',NULL),('aviso_pedido','Te recomendamos que tu pedido sea en una sola exhibición. Esto evita las fallas en tu comida y acorta el tiempo de espera.','Mensaje al cliente sobre el pedido','textarea',NULL),('horario_tolerancia_min','15','Minutos de tolerancia antes/después del turno para permitir login','number',NULL),('iva_porcentaje','0','Tasa de IVA en %','number',NULL),('moneda','MXN','Código de moneda','text',NULL),('moneda_simbolo','$','Símbolo de moneda','text',NULL),('negocio_direccion','Av. Reforma 123, CDMX','Dirección','textarea',NULL),('negocio_eslogan','Más rock, mejor sabor · Los originales al carbón','Eslogan / tagline','text',NULL),('negocio_horario','Jueves a Domingo · 6:00 PM a 10:30 PM','Horario operativo','text',NULL),('negocio_nombre','Jacks Rock','Nombre comercial','text',NULL),('negocio_telefono','962 282 1323','Teléfono','text',NULL),('propina_sugerida','10,15,20','Porcentajes sugeridos de propina (coma separados)','text',NULL),('restaurante_lat','15.012565','Latitud del restaurante (para calcular env?os)','text',NULL),('restaurante_lng','-92.406631','Longitud del restaurante (para calcular env?os)','text',NULL),('ticket_pie','¡DIOS TE BENDIGA! · Calidad, sabor e higiene · WhatsApp para domicilio: 962 282 1323','Mensaje al pie del ticket','textarea',NULL),('transfer_banco','BBVA','Banco para transferencias','text',NULL),('transfer_clabe','','CLABE interbancaria (18 d?gitos)','text',NULL),('transfer_cuenta','','N?mero de cuenta o tarjeta','text',NULL),('transfer_titular','Jacks Rock','Titular de la cuenta','text',NULL),('ultimo_cierre_dia','{\"at\":\"2026-06-25 09:12\",\"por\":\"Administrador\",\"turnos\":0,\"esperado\":0,\"contado\":null,\"diff\":null}',NULL,'text',NULL),('web_pedidos_activo','1','Permitir pedidos desde la web pública (1=sí, 0=no)','bool',NULL),('whatsapp_domicilio','962 282 1323','WhatsApp para pedidos a domicilio','text',NULL),('whatsapp_pedidos','9622821323','WhatsApp del restaurante para recibir pedidos web (solo números, con lada)','text',NULL);
/*!40000 ALTER TABLE `configuracion` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `horarios_atencion` WRITE;
/*!40000 ALTER TABLE `horarios_atencion` DISABLE KEYS */;
INSERT INTO `horarios_atencion` (`dia`, `abierto`, `hora_inicio`, `hora_fin`) VALUES (1,1,'18:00:00','22:30:00'),(2,1,'06:00:00','22:30:00'),(3,1,'18:00:00','22:30:00'),(4,1,'18:00:00','22:30:00'),(5,1,'18:00:00','23:00:00'),(6,1,'18:00:00','23:00:00'),(7,1,'18:00:00','22:30:00');
/*!40000 ALTER TABLE `horarios_atencion` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `impresoras` WRITE;
/*!40000 ALTER TABLE `impresoras` DISABLE KEYS */;
INSERT INTO `impresoras` (`id`, `nombre`, `ubicacion`, `tipo`, `driver`, `destino`, `ancho_mm`, `copias`, `activa`, `created_at`) VALUES (1,'Cocina 1 (Parrilla)','cocina','comanda','navegador',NULL,80,1,1,'2026-05-30 02:59:13'),(2,'Cocina 2 (Fríos)','cocina','comanda','navegador',NULL,80,1,1,'2026-05-30 02:59:13'),(4,'Caja','caja','ticket','navegador',NULL,80,1,1,'2026-05-30 02:59:13');
/*!40000 ALTER TABLE `impresoras` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `promociones` WRITE;
/*!40000 ALTER TABLE `promociones` DISABLE KEYS */;
INSERT INTO `promociones` (`id`, `nombre`, `descripcion`, `tipo`, `valor`, `aplicable_a`, `categoria_id`, `producto_id`, `desde`, `hasta`, `hora_desde`, `hora_hasta`, `codigo`, `uso_max`, `uso_actual`, `activa`, `created_at`) VALUES (1,'RED HOST',NULL,'monto_fijo',100.00,'todo',NULL,NULL,'2026-06-18','2026-06-19',NULL,NULL,'redhost',2,2,1,'2026-06-18 14:33:55');
/*!40000 ALTER TABLE `promociones` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `modificador_grupos` WRITE;
/*!40000 ALTER TABLE `modificador_grupos` DISABLE KEYS */;
INSERT INTO `modificador_grupos` (`id`, `nombre`, `tipo`, `obligatorio`, `max_selecciones`) VALUES (1,'Extras','check',0,5),(2,'Sabor de Alitas','radio',1,1),(3,'Sin (quitar ingrediente)','check',0,5),(4,'Tipo de carne','radio',0,1);
/*!40000 ALTER TABLE `modificador_grupos` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `modificadores` WRITE;
/*!40000 ALTER TABLE `modificadores` DISABLE KEYS */;
INSERT INTO `modificadores` (`id`, `grupo_id`, `nombre`, `precio_extra`, `orden`, `activo`) VALUES (1,1,'Chorizo de pavo asado',15.00,1,1),(2,1,'Extra papas a la francesa',15.00,2,1),(3,1,'Extra queso',10.00,3,1),(4,1,'Extra carne de arrachera',25.00,4,1),(5,1,'Tocino vegetal',10.00,5,1),(6,2,'Mango Habanero',0.00,1,1),(7,2,'BBQ Pica',0.00,2,1),(8,2,'BBQ Dulce',0.00,3,1),(9,2,'Piña Hot',0.00,4,1),(10,2,'Lemon Pepper Hot',0.00,5,1),(11,3,'NO cebolla',0.00,1,1),(12,3,'NO cilantro',0.00,2,1),(13,3,'NO tomate',0.00,3,1),(14,3,'NO queso',0.00,4,1),(15,3,'NO chipotle',0.00,5,1),(16,3,'NO piña',0.00,6,1),(17,3,'NO mayonesa',0.00,7,1),(18,4,'Asada Arrachera',0.00,1,1),(19,4,'Birria',0.00,2,1),(20,4,'Mixta',0.00,3,1);
/*!40000 ALTER TABLE `modificadores` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `producto_modificador_grupo` WRITE;
/*!40000 ALTER TABLE `producto_modificador_grupo` DISABLE KEYS */;
INSERT INTO `producto_modificador_grupo` (`producto_id`, `grupo_id`) VALUES (1,1),(1,3),(2,1),(2,3),(4,1),(4,3),(5,1),(5,3),(6,1),(6,3),(7,1),(7,3),(8,1),(8,3),(9,1),(9,3),(10,1),(10,3),(11,1),(11,3),(12,1),(12,2),(12,3),(13,1),(13,3),(14,1),(14,3),(15,1),(15,3),(16,1),(16,3),(17,1),(17,3),(18,1),(18,3),(19,1),(19,3),(20,1),(20,3),(21,1),(21,3),(22,1),(22,3),(23,1),(23,3),(24,1),(24,3),(25,1),(25,3),(25,4);
/*!40000 ALTER TABLE `producto_modificador_grupo` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `combos` WRITE;
/*!40000 ALTER TABLE `combos` DISABLE KEYS */;
INSERT INTO `combos` (`id`, `nombre`, `descripcion`, `precio`, `emoji`, `activo`) VALUES (1,'dia del padre','',200.00,'🎁',1);
/*!40000 ALTER TABLE `combos` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `combo_items` WRITE;
/*!40000 ALTER TABLE `combo_items` DISABLE KEYS */;
INSERT INTO `combo_items` (`combo_id`, `producto_id`, `cantidad`) VALUES (1,16,2),(1,26,2);
/*!40000 ALTER TABLE `combo_items` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

SET FOREIGN_KEY_CHECKS=1;

-- ============================================================
-- Fin. La base queda lista con el menú y la configuración,
-- sin ventas ni turnos (todo en cero para empezar a operar).
-- ============================================================
