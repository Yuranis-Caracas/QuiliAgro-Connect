# QuiliAgro Connect
Prototipo de aplicación web progresiva (PWA) para la comercialización de productos agropecuarios, desarrollado como proyecto de grado.

## Descripción
QuiliAgro Connect es una aplicación web progresiva (PWA) que conecta productores 
y compradores del sector agropecuario del municipio de Santander de Quilichao, Cauca, 
permitiendo la gestión de productos, pedidos y pagos sin intermediarios.

> **Nota:** es un prototipo académico. Los métodos de pago son **simulados**: no se procesan transacciones reales ni se manejan datos bancarios.

## Tecnologías utilizadas
- PHP 8.4
- MySQL 8.x / MariaDB (XAMPP)
- HTML5, CSS3, JavaScript (ECMAScript 2025)
- Bootstrap Icons
- DomPDF (generación de PDF)
- PHPMailer (envío de correos)
- Service Worker (modo offline)

## Roles del sistema
- **Administrador:** gestión general de la plataforma
- **Productor:** gestión de productos, pedidos y perfil
- **Comprador:** catálogo, carrito y pasarela de pagos


## Métodos de pago (simulado)
- Tarjeta de crédito / débito
- PSE
- Billetera digital (Nequi, Daviplata)
- Transferencia bancaria
- Efectivo (Efecty / Sured)

## Aspectos técnicos
- Base de datos relacional con llaves foráneas, vistas y triggers (estado automático de stock, confirmación de pedidos al pagar una orden, recálculo de totales)
- Consultas con sentencias preparadas (PDO / mysqli)
- Control de acceso por roles y registro de auditoría

## Autora
Darly Yuranis Caracas Viveros — Ingeniería de Sistemas — Unicomfacauca — 2026