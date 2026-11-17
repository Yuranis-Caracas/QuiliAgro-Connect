-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 17-11-2026 a las 20:46:47
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `bdquiliagro_connect`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `alertas`
--

CREATE TABLE `alertas` (
  `id_alerta` int(11) NOT NULL,
  `id_comprador` int(11) NOT NULL,
  `tipo_alerta` enum('producto_disponible','nuevo_productor','promocion','nuevo_producto') NOT NULL,
  `mensaje` text NOT NULL,
  `id_producto` int(11) DEFAULT NULL,
  `id_productor` int(11) DEFAULT NULL,
  `leida` tinyint(1) DEFAULT 0,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `auditoria_admin`
--

CREATE TABLE `auditoria_admin` (
  `id_auditoria` int(11) NOT NULL,
  `id_administrador` int(11) NOT NULL,
  `accion` varchar(100) NOT NULL,
  `tabla_afectada` varchar(50) DEFAULT NULL,
  `id_registro_afectado` int(11) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `fecha_accion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `calificaciones_resenas`
--

CREATE TABLE `calificaciones_resenas` (
  `id_calificacion` int(11) NOT NULL,
  `id_productor` int(11) NOT NULL,
  `id_comprador` int(11) NOT NULL,
  `id_pedido` int(11) NOT NULL,
  `id_orden` int(11) DEFAULT NULL,
  `calificacion` tinyint(4) NOT NULL CHECK (`calificacion` between 1 and 5),
  `resena` text DEFAULT NULL,
  `imagenes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`imagenes`)),
  `estado` enum('pendiente','activa','eliminada') DEFAULT 'pendiente',
  `motivo_rechazo` text DEFAULT NULL,
  `fecha_calificacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categorias_productos`
--

CREATE TABLE `categorias_productos` (
  `id_categoria` int(11) NOT NULL,
  `nombre_categoria` varchar(50) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `icono` varchar(255) DEFAULT NULL,
  `activa` tinyint(1) DEFAULT 1,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fotos_finca`
--

CREATE TABLE `fotos_finca` (
  `id_foto` int(11) NOT NULL,
  `id_productor` int(11) NOT NULL,
  `ruta_foto` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `grupo_id` varchar(100) DEFAULT NULL,
  `fecha_subida` timestamp NOT NULL DEFAULT current_timestamp(),
  `id_grupo` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fotos_productos`
--

CREATE TABLE `fotos_productos` (
  `id_foto` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `ruta_foto` varchar(255) NOT NULL,
  `orden` tinyint(2) DEFAULT 0,
  `descripcion` varchar(200) DEFAULT NULL,
  `fecha_subida` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `grupos_finca`
--

CREATE TABLE `grupos_finca` (
  `id_grupo` int(11) NOT NULL,
  `id_productor` int(11) NOT NULL,
  `descripcion` varchar(500) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historial_busquedas`
--

CREATE TABLE `historial_busquedas` (
  `id_busqueda` int(11) NOT NULL,
  `id_comprador` int(11) NOT NULL,
  `termino_busqueda` varchar(200) NOT NULL,
  `id_categoria` int(11) DEFAULT NULL,
  `fecha_busqueda` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `metodos_pago`
--

CREATE TABLE `metodos_pago` (
  `id_metodo` int(11) NOT NULL,
  `nombre_metodo` varchar(50) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `icono` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `tipo` enum('billetera','pse','efectivo','transferencia','tarjeta') NOT NULL DEFAULT 'transferencia'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ordenes`
--

CREATE TABLE `ordenes` (
  `id_orden` int(11) NOT NULL,
  `id_comprador` int(11) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `estado` enum('pendiente','pagado','expirado') NOT NULL DEFAULT 'pendiente',
  `fecha` datetime DEFAULT current_timestamp(),
  `fecha_expiracion` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `ordenes`
--
DELIMITER $$
CREATE TRIGGER `orden_pagada_confirma_pedidos` AFTER UPDATE ON `ordenes` FOR EACH ROW BEGIN
    IF NEW.estado = 'pagado' AND OLD.estado != 'pagado' THEN
        UPDATE `pedidos`
          SET `estado` = 'confirmado',
              `fecha_actualizacion` = CURRENT_TIMESTAMP
          WHERE `id_orden` = NEW.id_orden
            AND `estado` = 'pendiente';

        UPDATE `productos` p
          INNER JOIN `pedidos` pe ON pe.id_producto = p.id_producto
          SET p.cantidad = GREATEST(0, p.cantidad - pe.cantidad)
          WHERE pe.id_orden = NEW.id_orden
            AND pe.estado = 'confirmado';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pagos`
--

CREATE TABLE `pagos` (
  `id_pago` int(11) NOT NULL,
  `id_metodo_pago` int(11) NOT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `valor` decimal(10,2) NOT NULL,
  `estado` enum('registrado','confirmado') DEFAULT 'registrado',
  `detalles_metodo` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`detalles_metodo`)),
  `fecha_pago` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_confirmacion` timestamp NULL DEFAULT NULL,
  `fecha_expiracion` datetime DEFAULT NULL,
  `id_orden` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pedidos`
--

CREATE TABLE `pedidos` (
  `id_pedido` int(11) NOT NULL,
  `id_orden` int(11) DEFAULT NULL,
  `id_producto` int(11) NOT NULL,
  `id_comprador` int(11) NOT NULL,
  `id_productor` int(11) NOT NULL,
  `cantidad` int(11) NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `total` decimal(10,2) GENERATED ALWAYS AS (`cantidad` * `precio_unitario`) STORED,
  `estado` enum('pendiente','confirmado','entregado') DEFAULT 'pendiente',
  `fecha_pedido` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `pedidos`
--
DELIMITER $$
CREATE TRIGGER `actualizar_total_orden_insert` AFTER INSERT ON `pedidos` FOR EACH ROW BEGIN
    IF NEW.id_orden IS NOT NULL THEN
        UPDATE `ordenes`
          SET total = (
            SELECT COALESCE(SUM(total), 0)
            FROM `pedidos`
            WHERE id_orden = NEW.id_orden
          )
          WHERE id_orden = NEW.id_orden;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `actualizar_total_orden_update` AFTER UPDATE ON `pedidos` FOR EACH ROW BEGIN
    IF NEW.id_orden IS NOT NULL
       AND (NEW.cantidad <> OLD.cantidad OR NEW.precio_unitario <> OLD.precio_unitario) THEN
        UPDATE `ordenes`
          SET total = (
            SELECT COALESCE(SUM(total), 0)
            FROM `pedidos`
            WHERE id_orden = NEW.id_orden
          )
          WHERE id_orden = NEW.id_orden;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `perfiles_productor`
--

CREATE TABLE `perfiles_productor` (
  `id_perfil` int(11) NOT NULL,
  `id_productor` int(11) NOT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `nombre_finca` varchar(100) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `ubicacion` varchar(255) DEFAULT NULL,
  `tipo_produccion` varchar(100) DEFAULT NULL,
  `experiencia` text DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos`
--

CREATE TABLE `productos` (
  `id_producto` int(11) NOT NULL,
  `id_productor` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `id_categoria` int(11) NOT NULL,
  `cantidad` int(11) NOT NULL DEFAULT 0,
  `stock_minimo` int(11) DEFAULT 5,
  `alerta_stock_bajo` tinyint(1) DEFAULT 1,
  `precio` decimal(10,2) NOT NULL,
  `precio_mayorista` decimal(10,2) DEFAULT NULL,
  `cantidad_minima_mayorista` int(11) DEFAULT 10,
  `unidad_medida` enum('unidad','libra','kilogramo','canastilla','bulto','arroba','litro','docena') DEFAULT 'unidad',
  `descripcion` text DEFAULT NULL,
  `imagen` varchar(255) DEFAULT NULL,
  `estado` enum('disponible','agotado','stock_bajo') DEFAULT 'disponible' COMMENT 'Estado automático basado en stock: disponible, stock_bajo o agotado',
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `productos`
--
DELIMITER $$
CREATE TRIGGER `actualizar_estado_producto_insert` BEFORE INSERT ON `productos` FOR EACH ROW BEGIN
    IF NEW.cantidad <= 0 THEN
        SET NEW.cantidad = 0;
        SET NEW.estado = 'agotado';
    ELSEIF NEW.cantidad <= NEW.stock_minimo THEN
        SET NEW.estado = 'stock_bajo';
    ELSE
        SET NEW.estado = 'disponible';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `actualizar_estado_producto_update` BEFORE UPDATE ON `productos` FOR EACH ROW BEGIN
    IF NEW.cantidad <= 0 THEN
        SET NEW.cantidad = 0;
        SET NEW.estado = 'agotado';
    ELSEIF NEW.cantidad <= NEW.stock_minimo THEN
        SET NEW.estado = 'stock_bajo';
    ELSE
        SET NEW.estado = 'disponible';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tokens_temp`
--

CREATE TABLE `tokens_temp` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `tipo` enum('confirmacion_pago','recuperacion') NOT NULL,
  `token` varchar(64) NOT NULL,
  `id_pago` int(11) DEFAULT NULL,
  `expira` datetime NOT NULL,
  `usado` tinyint(1) DEFAULT 0,
  `creado` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tutoriales`
--

CREATE TABLE `tutoriales` (
  `id_tutorial` int(11) NOT NULL,
  `titulo` varchar(150) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `video` varchar(255) DEFAULT NULL,
  `seccion` enum('frecuentes','productor','comprador') NOT NULL DEFAULT 'frecuentes',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id_usuario` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `cedula` varchar(20) NOT NULL,
  `telefono` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `rol` enum('administrador','productor','comprador') NOT NULL,
  `estado` enum('activo','inactivo','suspendido') DEFAULT 'activo',
  `eliminado` tinyint(1) DEFAULT 0,
  `fecha_eliminacion` datetime DEFAULT NULL,
  `motivo_eliminacion` varchar(200) DEFAULT NULL,
  `eliminado_por` int(11) DEFAULT NULL,
  `ultima_actualizacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `vista_alertas_stock`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `vista_alertas_stock` (
`id_producto` int(11)
,`nombre` varchar(100)
,`cantidad` int(11)
,`stock_minimo` int(11)
,`estado` enum('disponible','agotado','stock_bajo')
,`id_productor` int(11)
,`nombre_productor` varchar(100)
,`telefono` varchar(20)
,`email` varchar(100)
,`nombre_categoria` varchar(50)
,`nivel_alerta` varchar(25)
);

-- --------------------------------------------------------

--
-- Estructura para la vista `vista_alertas_stock`
--
DROP TABLE IF EXISTS `vista_alertas_stock`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vista_alertas_stock`  AS SELECT `p`.`id_producto` AS `id_producto`, `p`.`nombre` AS `nombre`, `p`.`cantidad` AS `cantidad`, `p`.`stock_minimo` AS `stock_minimo`, `p`.`estado` AS `estado`, `p`.`id_productor` AS `id_productor`, `u`.`nombre` AS `nombre_productor`, `u`.`telefono` AS `telefono`, `u`.`email` AS `email`, `c`.`nombre_categoria` AS `nombre_categoria`, CASE WHEN `p`.`cantidad` = 0 THEN 'CRÍTICO: Producto agotado' WHEN `p`.`cantidad` <= `p`.`stock_minimo` THEN 'ADVERTENCIA: Stock bajo' ELSE 'OK' END AS `nivel_alerta` FROM ((`productos` `p` join `usuarios` `u` on(`p`.`id_productor` = `u`.`id_usuario`)) join `categorias_productos` `c` on(`p`.`id_categoria` = `c`.`id_categoria`)) WHERE `p`.`cantidad` <= `p`.`stock_minimo` ORDER BY `p`.`cantidad` ASC ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `alertas`
--
ALTER TABLE `alertas`
  ADD PRIMARY KEY (`id_alerta`),
  ADD KEY `fk_alertas_producto` (`id_producto`),
  ADD KEY `fk_alertas_productor` (`id_productor`),
  ADD KEY `idx_alertas_comprador` (`id_comprador`,`leida`),
  ADD KEY `idx_alertas_fecha` (`fecha_creacion`),
  ADD KEY `idx_alertas_tipo` (`tipo_alerta`);

--
-- Indices de la tabla `auditoria_admin`
--
ALTER TABLE `auditoria_admin`
  ADD PRIMARY KEY (`id_auditoria`),
  ADD KEY `idx_auditoria_admin` (`id_administrador`),
  ADD KEY `idx_auditoria_fecha` (`fecha_accion`),
  ADD KEY `idx_auditoria_tabla` (`tabla_afectada`);

--
-- Indices de la tabla `calificaciones_resenas`
--
ALTER TABLE `calificaciones_resenas`
  ADD PRIMARY KEY (`id_calificacion`),
  ADD UNIQUE KEY `uk_pedido_calificacion` (`id_pedido`),
  ADD KEY `idx_calificaciones_productor` (`id_productor`,`estado`),
  ADD KEY `idx_calificaciones_comprador` (`id_comprador`),
  ADD KEY `idx_calificaciones_puntuacion` (`calificacion`),
  ADD KEY `idx_calificaciones_orden` (`id_orden`);

--
-- Indices de la tabla `categorias_productos`
--
ALTER TABLE `categorias_productos`
  ADD PRIMARY KEY (`id_categoria`),
  ADD UNIQUE KEY `uk_nombre_categoria` (`nombre_categoria`),
  ADD UNIQUE KEY `nombre_categoria` (`nombre_categoria`);

--
-- Indices de la tabla `fotos_finca`
--
ALTER TABLE `fotos_finca`
  ADD PRIMARY KEY (`id_foto`),
  ADD KEY `id_productor` (`id_productor`),
  ADD KEY `fk_grupo_fotos` (`id_grupo`),
  ADD KEY `idx_grupo_id` (`grupo_id`);

--
-- Indices de la tabla `fotos_productos`
--
ALTER TABLE `fotos_productos`
  ADD PRIMARY KEY (`id_foto`),
  ADD KEY `id_producto` (`id_producto`);

--
-- Indices de la tabla `grupos_finca`
--
ALTER TABLE `grupos_finca`
  ADD PRIMARY KEY (`id_grupo`),
  ADD KEY `id_productor` (`id_productor`);

--
-- Indices de la tabla `historial_busquedas`
--
ALTER TABLE `historial_busquedas`
  ADD PRIMARY KEY (`id_busqueda`),
  ADD KEY `fk_historial_categoria` (`id_categoria`),
  ADD KEY `idx_historial_comprador` (`id_comprador`),
  ADD KEY `idx_historial_fecha` (`fecha_busqueda`),
  ADD KEY `idx_historial_termino` (`termino_busqueda`);

--
-- Indices de la tabla `metodos_pago`
--
ALTER TABLE `metodos_pago`
  ADD PRIMARY KEY (`id_metodo`),
  ADD UNIQUE KEY `uk_nombre_metodo` (`nombre_metodo`);

--
-- Indices de la tabla `ordenes`
--
ALTER TABLE `ordenes`
  ADD PRIMARY KEY (`id_orden`),
  ADD KEY `id_comprador` (`id_comprador`),
  ADD KEY `idx_ordenes_expiracion` (`estado`,`fecha_expiracion`);

--
-- Indices de la tabla `pagos`
--
ALTER TABLE `pagos`
  ADD PRIMARY KEY (`id_pago`),
  ADD KEY `fk_pagos_metodo` (`id_metodo_pago`),
  ADD KEY `idx_pagos_estado` (`estado`),
  ADD KEY `idx_pagos_orden` (`id_orden`);

--
-- Indices de la tabla `pedidos`
--
ALTER TABLE `pedidos`
  ADD PRIMARY KEY (`id_pedido`),
  ADD KEY `fk_pedidos_producto` (`id_producto`),
  ADD KEY `idx_pedidos_estado` (`estado`),
  ADD KEY `idx_pedidos_comprador` (`id_comprador`),
  ADD KEY `idx_pedidos_productor` (`id_productor`),
  ADD KEY `idx_pedidos_fecha` (`fecha_pedido`),
  ADD KEY `fk_pedidos_orden` (`id_orden`);

--
-- Indices de la tabla `perfiles_productor`
--
ALTER TABLE `perfiles_productor`
  ADD PRIMARY KEY (`id_perfil`),
  ADD UNIQUE KEY `uk_productor` (`id_productor`);

--
-- Indices de la tabla `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`id_producto`),
  ADD KEY `idx_productos_categoria` (`id_categoria`),
  ADD KEY `idx_productos_estado` (`estado`),
  ADD KEY `idx_productos_productor` (`id_productor`);

--
-- Indices de la tabla `tokens_temp`
--
ALTER TABLE `tokens_temp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `id_pago` (`id_pago`);

--
-- Indices de la tabla `tutoriales`
--
ALTER TABLE `tutoriales`
  ADD PRIMARY KEY (`id_tutorial`),
  ADD KEY `idx_tutoriales_seccion` (`seccion`,`activo`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `uk_cedula` (`cedula`),
  ADD UNIQUE KEY `uk_email` (`email`),
  ADD KEY `idx_usuarios_eliminado` (`eliminado`),
  ADD KEY `idx_usuarios_estado` (`estado`),
  ADD KEY `idx_usuarios_rol` (`rol`),
  ADD KEY `fk_usuarios_eliminado_por` (`eliminado_por`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `alertas`
--
ALTER TABLE `alertas`
  MODIFY `id_alerta` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `auditoria_admin`
--
ALTER TABLE `auditoria_admin`
  MODIFY `id_auditoria` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `calificaciones_resenas`
--
ALTER TABLE `calificaciones_resenas`
  MODIFY `id_calificacion` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `categorias_productos`
--
ALTER TABLE `categorias_productos`
  MODIFY `id_categoria` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fotos_finca`
--
ALTER TABLE `fotos_finca`
  MODIFY `id_foto` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fotos_productos`
--
ALTER TABLE `fotos_productos`
  MODIFY `id_foto` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `grupos_finca`
--
ALTER TABLE `grupos_finca`
  MODIFY `id_grupo` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `historial_busquedas`
--
ALTER TABLE `historial_busquedas`
  MODIFY `id_busqueda` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `metodos_pago`
--
ALTER TABLE `metodos_pago`
  MODIFY `id_metodo` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ordenes`
--
ALTER TABLE `ordenes`
  MODIFY `id_orden` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `pagos`
--
ALTER TABLE `pagos`
  MODIFY `id_pago` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `pedidos`
--
ALTER TABLE `pedidos`
  MODIFY `id_pedido` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `perfiles_productor`
--
ALTER TABLE `perfiles_productor`
  MODIFY `id_perfil` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `productos`
--
ALTER TABLE `productos`
  MODIFY `id_producto` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tokens_temp`
--
ALTER TABLE `tokens_temp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tutoriales`
--
ALTER TABLE `tutoriales`
  MODIFY `id_tutorial` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `alertas`
--
ALTER TABLE `alertas`
  ADD CONSTRAINT `fk_alertas_comprador` FOREIGN KEY (`id_comprador`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_alertas_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_alertas_productor` FOREIGN KEY (`id_productor`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `auditoria_admin`
--
ALTER TABLE `auditoria_admin`
  ADD CONSTRAINT `fk_auditoria_admin` FOREIGN KEY (`id_administrador`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `calificaciones_resenas`
--
ALTER TABLE `calificaciones_resenas`
  ADD CONSTRAINT `fk_calificaciones_comprador` FOREIGN KEY (`id_comprador`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_calificaciones_orden` FOREIGN KEY (`id_orden`) REFERENCES `ordenes` (`id_orden`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_calificaciones_productor` FOREIGN KEY (`id_productor`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `fotos_finca`
--
ALTER TABLE `fotos_finca`
  ADD CONSTRAINT `fk_grupo_fotos` FOREIGN KEY (`id_grupo`) REFERENCES `grupos_finca` (`id_grupo`) ON DELETE CASCADE,
  ADD CONSTRAINT `fotos_finca_ibfk_1` FOREIGN KEY (`id_productor`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `fotos_productos`
--
ALTER TABLE `fotos_productos`
  ADD CONSTRAINT `fotos_productos_ibfk_1` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE CASCADE;

--
-- Filtros para la tabla `grupos_finca`
--
ALTER TABLE `grupos_finca`
  ADD CONSTRAINT `grupos_finca_ibfk_1` FOREIGN KEY (`id_productor`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `historial_busquedas`
--
ALTER TABLE `historial_busquedas`
  ADD CONSTRAINT `fk_historial_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias_productos` (`id_categoria`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_historial_comprador` FOREIGN KEY (`id_comprador`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `ordenes`
--
ALTER TABLE `ordenes`
  ADD CONSTRAINT `ordenes_ibfk_1` FOREIGN KEY (`id_comprador`) REFERENCES `usuarios` (`id_usuario`);

--
-- Filtros para la tabla `pagos`
--
ALTER TABLE `pagos`
  ADD CONSTRAINT `fk_pagos_metodo` FOREIGN KEY (`id_metodo_pago`) REFERENCES `metodos_pago` (`id_metodo`),
  ADD CONSTRAINT `fk_pagos_orden` FOREIGN KEY (`id_orden`) REFERENCES `ordenes` (`id_orden`) ON DELETE CASCADE;

--
-- Filtros para la tabla `pedidos`
--
ALTER TABLE `pedidos`
  ADD CONSTRAINT `fk_pedidos_comprador` FOREIGN KEY (`id_comprador`) REFERENCES `usuarios` (`id_usuario`),
  ADD CONSTRAINT `fk_pedidos_orden` FOREIGN KEY (`id_orden`) REFERENCES `ordenes` (`id_orden`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pedidos_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`),
  ADD CONSTRAINT `fk_pedidos_productor` FOREIGN KEY (`id_productor`) REFERENCES `usuarios` (`id_usuario`);

--
-- Filtros para la tabla `perfiles_productor`
--
ALTER TABLE `perfiles_productor`
  ADD CONSTRAINT `fk_perfiles_productor` FOREIGN KEY (`id_productor`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `productos`
--
ALTER TABLE `productos`
  ADD CONSTRAINT `fk_productos_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias_productos` (`id_categoria`),
  ADD CONSTRAINT `fk_productos_productor` FOREIGN KEY (`id_productor`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `tokens_temp`
--
ALTER TABLE `tokens_temp`
  ADD CONSTRAINT `tokens_temp_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE,
  ADD CONSTRAINT `tokens_temp_ibfk_2` FOREIGN KEY (`id_pago`) REFERENCES `pagos` (`id_pago`) ON DELETE CASCADE;

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_usuarios_eliminado_por` FOREIGN KEY (`eliminado_por`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
