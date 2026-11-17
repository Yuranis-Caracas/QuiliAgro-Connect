<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'administrador');
require_once __DIR__ . '/../../config/auth_check.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'administrador') {
    header("Location: ../auth/login.php");
    exit();
}

$usuario_nombre = htmlspecialchars($_SESSION['user_nombre']);
$usuario_id     = intval($_SESSION['user_id']);

function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param($tipos, ...$params);
            $ok = $stmt->execute();
            if (stripos(trim($query), 'select') === 0) return $stmt->get_result();
            return $ok;
        } else {
            $result = $conn->query($query);
            if (!$result) throw new Exception($conn->error);
            return $result;
        }
    } catch (Exception $e) {
        error_log('Error BD: ' . $e->getMessage());
        return false;
    }
}

$mensaje      = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($accion === 'crear_categoria') {
        $nombre      = ucfirst(strtolower(trim($_POST['nombre_categoria'])));
        $descripcion = trim($_POST['descripcion']);
        $icono       = trim($_POST['icono']);

        $check = ejecutarConsulta($conn,
            "SELECT id_categoria FROM categorias_productos WHERE nombre_categoria = ?",
            "s", [$nombre]);

        if ($check && $check->num_rows > 0) {
            $mensaje      = "La categoría \"$nombre\" ya existe";
            $tipo_mensaje = "warning";
        } else {
            if (ejecutarConsulta($conn,
                "INSERT INTO categorias_productos (nombre_categoria, descripcion, icono) VALUES (?, ?, ?)",
                "sss", [$nombre, $descripcion, $icono])) {
                $mensaje      = "Categoría creada exitosamente";
                $tipo_mensaje = "success";
                ejecutarConsulta($conn,
                    "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, descripcion, ip_address)
                    VALUES (?, 'crear_categoria', 'categorias_productos', ?, ?)",
                    "iss", [$usuario_id, "Creó categoría: $nombre", $ip_address]);
            } else {
                $mensaje      = "Error al crear categoría";
                $tipo_mensaje = "danger";
            }
        }
    }

    if ($accion === 'editar_categoria') {
        $id          = intval($_POST['id_categoria']);
        $nombre      = trim($_POST['nombre_categoria']);
        $descripcion = trim($_POST['descripcion']);
        $icono       = trim($_POST['icono']);
        $activa      = isset($_POST['activa']) ? 1 : 0;

        if (ejecutarConsulta($conn,
            "UPDATE categorias_productos SET nombre_categoria=?, descripcion=?, icono=?, activa=? WHERE id_categoria=?",
            "sssii", [$nombre, $descripcion, $icono, $activa, $id])) {
            $mensaje      = "Categoría actualizada exitosamente";
            $tipo_mensaje = "success";
            ejecutarConsulta($conn,
                "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, id_registro_afectado, descripcion, ip_address)
                VALUES (?, 'editar_categoria', 'categorias_productos', ?, ?, ?)",
                "iiss", [$usuario_id, $id, "Editó categoría: $nombre", $ip_address]);
        } else {
            $mensaje      = "Error al actualizar categoría";
            $tipo_mensaje = "danger";
        }
    }

    if ($accion === 'eliminar_categoria') {
        $id    = intval($_POST['id_categoria']);
        $check = ejecutarConsulta($conn,
            "SELECT COUNT(*) as total FROM productos WHERE id_categoria = ?", "i", [$id]);
        if ($check) {
            $row = $check->fetch_assoc();
            if ($row['total'] > 0) {
                $mensaje      = "No se puede eliminar la categoría porque tiene productos asociados";
                $tipo_mensaje = "danger";
            } else {
                if (ejecutarConsulta($conn,
                    "DELETE FROM categorias_productos WHERE id_categoria = ?", "i", [$id])) {
                    $mensaje      = "Categoría eliminada exitosamente";
                    $tipo_mensaje = "success";
                    ejecutarConsulta($conn,
                        "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, id_registro_afectado, descripcion, ip_address)
                        VALUES (?, 'eliminar_categoria', 'categorias_productos', ?, 'Eliminó categoría', ?)",
                        "iis", [$usuario_id, $id, $ip_address]);
                } else {
                    $mensaje      = "Error al eliminar categoría";
                    $tipo_mensaje = "danger";
                }
            }
        }
    }

    if ($accion === 'suspender_producto') {
        $id     = intval($_POST['id_producto']);
        $motivo = trim($_POST['motivo']);

        if (empty($motivo)) {
            $mensaje      = "Debes ingresar un motivo para suspender el producto";
            $tipo_mensaje = "warning";
        } else {
            $prod_actual = ejecutarConsulta($conn,
                "SELECT cantidad, nombre FROM productos WHERE id_producto = ?", "i", [$id]);
            $prod_data   = $prod_actual
                ? $prod_actual->fetch_assoc()
                : ['cantidad' => 0, 'nombre' => 'Desconocido'];

            if (ejecutarConsulta($conn,
                "UPDATE productos SET cantidad = 0 WHERE id_producto = ?", "i", [$id])) {
                $mensaje      = "Producto \"" . htmlspecialchars($prod_data['nombre']) . "\" suspendido correctamente";
                $tipo_mensaje = "success";
                ejecutarConsulta($conn,
                    "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, id_registro_afectado, descripcion, ip_address)
                    VALUES (?, 'suspender_producto', 'productos', ?, ?, ?)",
                    "iiss", [$usuario_id, $id,"Suspendió producto: {$prod_data['nombre']} (stock previo: {$prod_data['cantidad']}). Motivo: $motivo", $ip_address]);
            } else {
                $mensaje      = "Error al suspender el producto";
                $tipo_mensaje = "danger";
            }
        }
    }

    if ($accion === 'restaurar_producto') {
        $id = intval($_POST['id_producto']);

        $audit = ejecutarConsulta($conn,
            "SELECT descripcion FROM auditoria_admin
             WHERE accion = 'suspender_producto' AND id_registro_afectado = ?
             ORDER BY fecha_accion DESC LIMIT 1",
            "i", [$id]);

        $stock_restaurar = 0;
        if ($audit && $row_audit = $audit->fetch_assoc()) {
            if (preg_match('/stock previo:\s*(\d+)/i', $row_audit['descripcion'], $m)) {
                $stock_restaurar = intval($m[1]);
            }
        }

        if ($stock_restaurar === 0) {
            $prod_min = ejecutarConsulta($conn,
                "SELECT stock_minimo FROM productos WHERE id_producto = ?", "i", [$id]);
            if ($prod_min) {
                $r = $prod_min->fetch_assoc();
                $stock_restaurar = ($r['stock_minimo'] ?? 5) + 1;
            }
        }

        $prod_info   = ejecutarConsulta($conn,
            "SELECT nombre FROM productos WHERE id_producto = ?", "i", [$id]);
        $prod_nombre = $prod_info ? $prod_info->fetch_assoc()['nombre'] : 'Desconocido';

        if (ejecutarConsulta($conn,
            "UPDATE productos SET cantidad = ? WHERE id_producto = ?", "ii", [$stock_restaurar, $id])) {
            $mensaje      = "Producto \"" . htmlspecialchars($prod_nombre) . "\" restaurado. Stock: $stock_restaurar";
            $tipo_mensaje = "success";
           ejecutarConsulta($conn,
                "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, id_registro_afectado, descripcion, ip_address)
                VALUES (?, 'restaurar_producto', 'productos', ?, ?, ?)",
                "iiss", [$usuario_id, $id, "Restauró producto: $prod_nombre. Stock recuperado: $stock_restaurar", $ip_address]);
        } else {
            $mensaje      = "Error al restaurar el producto";
            $tipo_mensaje = "danger";
        }
    }
}

$result_categorias = ejecutarConsulta($conn,
    "SELECT c.*, COUNT(p.id_producto) as total_productos
     FROM categorias_productos c
     LEFT JOIN productos p ON c.id_categoria = p.id_categoria
     GROUP BY c.id_categoria
     ORDER BY c.nombre_categoria ASC");

$result_stats = ejecutarConsulta($conn,
    "SELECT
        (SELECT COUNT(*) FROM categorias_productos) as total_categorias,
        (SELECT COUNT(*) FROM productos) as total_productos,
        (SELECT COUNT(*) FROM productos WHERE estado = 'disponible') as productos_disponibles,
        (SELECT COUNT(*) FROM productos WHERE estado = 'stock_bajo') as productos_stock_bajo,
        (SELECT COUNT(*) FROM productos WHERE estado = 'agotado') as productos_agotados,
        (SELECT COUNT(*) FROM usuarios WHERE rol = 'productor' AND estado = 'activo') as total_productores");
$stats = $result_stats->fetch_assoc();

$result_productos = ejecutarConsulta($conn,
    "SELECT p.*,
            c.nombre_categoria,
            c.icono AS categoria_icono,
            u.nombre AS nombre_productor,
            u.telefono AS telefono_productor,
            (SELECT GROUP_CONCAT(fp.ruta_foto ORDER BY fp.orden SEPARATOR '|')
             FROM fotos_productos fp WHERE fp.id_producto = p.id_producto) AS fotos_producto,
            (SELECT COUNT(*) FROM pedidos pd
             INNER JOIN ordenes o ON pd.id_orden = o.id_orden
             WHERE pd.id_producto = p.id_producto
               AND pd.estado = 'confirmado'
               AND o.estado = 'pagado') AS pedidos_activos,
            (SELECT COUNT(*) FROM auditoria_admin aa
             WHERE aa.accion = 'suspender_producto'
               AND aa.id_registro_afectado = p.id_producto
               AND aa.fecha_accion > COALESCE(
                   (SELECT MAX(aa2.fecha_accion) FROM auditoria_admin aa2
                    WHERE aa2.accion = 'restaurar_producto'
                      AND aa2.id_registro_afectado = p.id_producto), '2000-01-01')
            ) AS suspendido_por_admin
     FROM productos p
     LEFT JOIN categorias_productos c ON p.id_categoria = c.id_categoria
     LEFT JOIN usuarios u ON p.id_productor = u.id_usuario
     ORDER BY p.fecha_creacion DESC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo Admin - QuiliAgro Connect</title>

    <link rel="icon"       type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        .badge-suspendido {
            background: #6c757d; color: #fff;
            padding: 4px 10px; border-radius: 20px;
            font-size: 12px; font-weight: 600;
        }

        .row-suspendido td           { opacity: .65; }
        .row-suspendido td:first-child { border-left: 4px solid #6c757d; }

        .tag-admin {
            display: inline-block;
            background: #6c757d22; color: #6c757d;
            border: 1px solid #6c757d55;
            border-radius: 20px; padding: 2px 8px;
            font-size: 11px; margin-top: 4px;
        }

        .acciones-moderacion { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }

        .btn-xs {
            padding: 4px 10px; font-size: 12px; border-radius: 6px;
            border: none; cursor: pointer;
            display: inline-flex; align-items: center; gap: 4px;
            font-weight: 600; transition: opacity .2s;
        }
        .btn-xs:hover           { opacity: .85; }
        .btn-xs.btn-warning     { background: #ffc107; color: #212529; }
        .btn-xs.btn-success     { background: #198754; color: #fff; }
        .btn-xs:disabled        { opacity: .4; cursor: not-allowed; pointer-events: none; }

        #modalModeracion .modal-content { max-width: 490px; }

        #modalSuspender textarea {
            width: 100%; min-height: 90px; padding: 10px;
            border: 1px solid #ccc; border-radius: 8px;
            font-size: 14px; resize: vertical; box-sizing: border-box;
        }

        .alerta-modal {
            padding: 12px 16px; border-radius: 8px;
            margin-bottom: 14px; font-size: 14px; line-height: 1.6;
        }
        .alerta-modal.warning {
            background: #fff3cd; border: 1px solid #ffc107; color: #664d03;
        }

        .foto-carousel {
            position: relative;
            width: 110px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            background: #f1f3f5;
            flex-shrink: 0;
            border: 1px solid #dee2e6;
            margin-top: 6px;
        }
        .foto-carousel .carousel-track {
            display: flex;
            height: 100%;
            width: 100%;
            transition: transform .28s ease;
        }
        .foto-carousel .carousel-track img {
            width: 110px;
            min-width: 110px;
            height: 80px;
            object-fit: cover;
            flex-shrink: 0;
            cursor: pointer;
            display: block;
        }
        .foto-carousel .carousel-track img.suspendida {
            filter: grayscale(100%);
        }
        .foto-carousel .car-btn {
            position: absolute;
            top: 50%; transform: translateY(-50%);
            background: rgba(0,0,0,.45);
            color: #fff;
            border: none; cursor: pointer;
            font-size: 14px; line-height: 1;
            padding: 3px 6px;
            border-radius: 4px;
            z-index: 2;
            transition: background .15s;
        }
        .foto-carousel .car-btn:hover { background: rgba(0,0,0,.72); }
        .foto-carousel .car-prev { left: 3px; }
        .foto-carousel .car-next { right: 3px; }
        .foto-carousel .car-dots {
            position: absolute;
            bottom: 4px; left: 50%; transform: translateX(-50%);
            display: flex; gap: 4px;
        }
        .foto-carousel .car-dot {
            width: 5px; height: 5px;
            border-radius: 50%;
            background: rgba(255,255,255,.55);
            transition: background .2s;
        }
        .foto-carousel .car-dot.active { background: #fff; }
        .foto-carousel .car-counter {
            position: absolute;
            top: 4px; right: 5px;
            background: rgba(0,0,0,.5);
            color: #fff; font-size: 10px;
            padding: 1px 5px; border-radius: 10px;
        }

        .sin-foto {
            width: 110px;
            height: 80px;
            background: #f1f3f5;
            border-radius: 8px;
            border: 1px dashed #ccc;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 6px;
            box-sizing: border-box;
        }

        #lightbox {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.82);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        #lightbox.show { display: flex; }
        #lightbox img {
            max-width: 90vw;
            max-height: 88vh;
            border-radius: 10px;
            box-shadow: 0 8px 40px rgba(0,0,0,.5);
        }
        #lightbox .lb-close {
            position: absolute; top: 18px; right: 24px;
            color: #fff; font-size: 34px; cursor: pointer;
            line-height: 1; font-weight: 700;
        }
        #lightbox .lb-nav {
            position: absolute;
            top: 50%; transform: translateY(-50%);
            color: #fff; font-size: 42px; cursor: pointer;
            user-select: none; padding: 0 16px;
            background: rgba(0,0,0,.3); border-radius: 6px;
        }
        #lightbox .lb-prev { left: 10px; }
        #lightbox .lb-next { right: 10px; }

        [data-tooltip] { position: relative; }
        [data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute; bottom: 130%; left: 50%;
            transform: translateX(-50%);
            background: #333; color: #fff;
            padding: 4px 8px; border-radius: 6px;
            font-size: 11px; white-space: nowrap;
            z-index: 999; pointer-events: none;
        }

        .buscador-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .buscador-wrap .form-control {
            padding-left: 42px;
        }
        .buscador-wrap .search-icon {
            position: absolute;
            left: 14px;
            color: var(--text-muted);
            font-size: 16px;
            pointer-events: none;
        }

        #tablaProductos tbody tr.row-warning {
            background: inherit !important;
        }
        #tablaProductos tbody tr.row-warning:hover {
            background: rgba(40, 167, 69, 0.08) !important;
        }

      
        @media (max-width: 768px) {

            #tablaProductos thead { display: none; }

            #tablaProductos tbody tr {
                display: block;
                background: white;
                border: 1px solid #e9ecef;
                border-radius: 14px;
                margin-bottom: 15px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            }

            #tablaProductos tbody tr.row-warning {
                background: white !important;
                border-color: #e9ecef;
            }
            #tablaProductos tbody tr:hover {
                background: rgba(40, 167, 69, 0.05) !important;
            }

            #tablaProductos tbody tr.row-suspendido {
                background: #f8f9fa !important;
                border-color: #6c757d;
                opacity: 0.85;
            }

            #tablaProductos td { display: none; }

            #tablaProductos td:nth-child(1) {
                display: block;
                padding: 14px 16px 12px;
                border-bottom: 1px solid rgba(0,0,0,0.07);
            }

            #tablaProductos td:nth-child(1) .producto-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            #tablaProductos td:nth-child(1) .foto-carousel {
                width: 100% !important;
                height: 200px !important;
                overflow: hidden;
            }
            #tablaProductos td:nth-child(1) .foto-carousel .carousel-track {
                width: 100%;
                flex-wrap: nowrap;
            }
            #tablaProductos td:nth-child(1) .foto-carousel .carousel-track img {
                width: 100%;
                min-width: 100%;
                height: 200px;
                flex-shrink: 0;
            }

            #tablaProductos td:nth-child(1) .sin-foto,
            #tablaProductos td:nth-child(1) .producto-text .sin-foto,
            #tablaProductos td:nth-child(1) .producto-info .sin-foto {
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
                height: 200px !important;
                flex-shrink: 0 !important;
                box-sizing: border-box !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }

            #tablaProductos td:nth-child(1) .producto-text {
                width: 100%;
                display: block;
            }

            #tablaProductos td:nth-child(1) .producto-text h4 {
                font-size: 16px;
                font-weight: 700;
                margin-bottom: 4px;
            }
            #tablaProductos td:nth-child(1) .producto-text p {
                font-size: 13px;
                color: var(--text-muted);
                margin: 0 0 4px 0;
            }

            #tablaProductos td:nth-child(2),
            #tablaProductos td:nth-child(3),
            #tablaProductos td:nth-child(4),
            #tablaProductos td:nth-child(5),
            #tablaProductos td:nth-child(6),
            #tablaProductos td:nth-child(7),
            #tablaProductos td:nth-child(9) {
                display: block;
                padding: 0;
            }

            #tablaProductos td:nth-child(8) { display: none !important; }

            #tablaProductos td:nth-child(2),
            #tablaProductos td:nth-child(4),
            #tablaProductos td:nth-child(6) {
                float: left;
                width: 50%;
                box-sizing: border-box;
                padding: 10px 16px;
                border-bottom: 1px solid rgba(0,0,0,0.06);
                border-right: 1px solid rgba(0,0,0,0.06);
                clear: left;
            }

            #tablaProductos td:nth-child(3),
            #tablaProductos td:nth-child(5),
            #tablaProductos td:nth-child(7) {
                float: right;
                width: 50%;
                box-sizing: border-box;
                padding: 10px 16px;
                border-bottom: 1px solid rgba(0,0,0,0.06);
                clear: right;
            }

            #tablaProductos td:nth-child(9) {
                float: none;
                clear: both;
                width: 100%;
                padding: 12px 16px;
                border-top: 1px solid rgba(0,0,0,0.07);
                display: block;
            }

            #tablaProductos td:nth-child(2)::before { content: "CATEGORÍA"; }
            #tablaProductos td:nth-child(3)::before { content: "PRODUCTOR"; }
            #tablaProductos td:nth-child(4)::before { content: "STOCK"; }
            #tablaProductos td:nth-child(5)::before { content: "PRECIO DETAL"; }
            #tablaProductos td:nth-child(6)::before { content: "PRECIO MAYORISTA"; }
            #tablaProductos td:nth-child(7)::before { content: "ESTADO"; }
            #tablaProductos td:nth-child(9)::before { content: "ACCIÓN ADMIN"; }

            #tablaProductos td:nth-child(2)::before,
            #tablaProductos td:nth-child(3)::before,
            #tablaProductos td:nth-child(4)::before,
            #tablaProductos td:nth-child(5)::before,
            #tablaProductos td:nth-child(6)::before,
            #tablaProductos td:nth-child(7)::before,
            #tablaProductos td:nth-child(9)::before {
                display: block;
                font-size: 10px;
                font-weight: 800;
                color: var(--primary);
                text-transform: uppercase;
                letter-spacing: 0.6px;
                margin-bottom: 5px;
            }

            #tablaProductos tbody tr::after {
                content: '';
                display: table;
                clear: both;
            }

            .acciones-moderacion { width: 100%; }
            .acciones-moderacion .btn-xs {
                width: 100%;
                justify-content: center;
                padding: 10px;
                font-size: 14px;
                border-radius: 8px;
            }

            #filaVacia td {
                display: block !important;
                border: none;
                float: none;
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            #tablaProductos td:nth-child(1) .foto-carousel {
                height: 180px !important;
            }
            #tablaProductos td:nth-child(1) .foto-carousel .carousel-track img {
                height: 180px;
                min-width: 100%;
            }
            #tablaProductos td:nth-child(1) .sin-foto,
            #tablaProductos td:nth-child(1) .producto-text .sin-foto,
            #tablaProductos td:nth-child(1) .producto-info .sin-foto {
                height: 180px !important;
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
            }
        }

    body.modal-open {
    overflow: hidden !important;
    }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleMenu()">
    <i class="bi bi-list"></i>
</button>

<div id="lightbox">
    <span class="lb-close" onclick="cerrarLightbox()">&times;</span>
    <span class="lb-nav lb-prev" onclick="lbNav(-1)">&#8249;</span>
    <img id="lbImg" src="" alt="">
    <span class="lb-nav lb-next" onclick="lbNav(1)">&#8250;</span>
</div>

<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo">
        </div>
        <nav class="sidebar-menu">
            <a href="panel.php"><i class="bi bi-speedometer2"></i> Inicio</a>
            <a href="usuarios.php"><i class="bi bi-people"></i> Usuarios</a>
            <a href="catalogo_admin.php" class="active"><i class="bi bi-tags"></i> Catálogo</a>
            <a href="pedidos_admin.php"><i class="bi bi-box-seam"></i> Pedidos</a>
            <a href="pagos_admin.php"><i class="bi bi-credit-card"></i> Pagos</a>
            <a href="calificaciones.php"><i class="bi bi-star"></i> Reseñas</a>
            <a href="reportes.php"><i class="bi bi-bar-chart"></i> Reportes</a>
            <a href="auditoria_admin.php"><i class="bi bi-shield-check"></i> Auditoría</a>
            <a href="ayudas_admin.php"><i class="bi bi-question-circle"></i> Ayuda</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Gestión del Catálogo</h1>
                <p class="subtitle">Administra categorías y modera productos</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout">
                    <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                </a>
            </div>
        </header>

        <div class="content-area">

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?>" id="alertaMensaje">
                    <i class="bi bi-<?php echo $tipo_mensaje === 'success'
                        ? 'check-circle'
                        : ($tipo_mensaje === 'warning' ? 'exclamation-triangle' : 'x-circle'); ?>-fill"></i>
                    <?php echo $mensaje; ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card primary">
                    <i class="bi bi-tags card-icon"></i>
                    <h3>Categorías</h3>
                    <div class="number"><?php echo $stats['total_categorias']; ?></div>
                    <p>Total de categorías</p>
                </div>
                <div class="stat-card success">
                    <i class="bi bi-box-seam card-icon"></i>
                    <h3>Productos Totales</h3>
                    <div class="number"><?php echo $stats['total_productos']; ?></div>
                    <p>En catálogo</p>
                </div>
                <div class="stat-card warning">
                    <i class="bi bi-exclamation-triangle card-icon"></i>
                    <h3>Stock Bajo</h3>
                    <div class="number"><?php echo $stats['productos_stock_bajo']; ?></div>
                    <p>Requieren atención</p>
                </div>
                <div class="stat-card danger">
                    <i class="bi bi-x-circle card-icon"></i>
                    <h3>Agotados</h3>
                    <div class="number"><?php echo $stats['productos_agotados']; ?></div>
                    <p>Sin stock</p>
                </div>
            </div>

            <div class="catalogo-container">

                <div class="catalogo-header">
                    <h2><i class="bi bi-tags"></i> Categorías del Catálogo</h2>
                    <div class="catalogo-actions">
                        <button class="btn btn-primary" onclick="abrirModalCategoria('crear')">
                            <i class="bi bi-plus-circle"></i> Nueva Categoría
                        </button>
                    </div>
                </div>

                <div class="categorias-grid">
                    <?php if ($result_categorias && $result_categorias->num_rows > 0): ?>
                        <?php while ($cat = $result_categorias->fetch_assoc()): ?>
                            <div class="categoria-card">
                                <div class="categoria-header">
                                    <div class="categoria-icon">
                                        <i class="<?php echo htmlspecialchars($cat['icono'] ?: 'fa-solid fa-tag'); ?>"></i>
                                    </div>
                                    <div class="categoria-info">
                                        <h3><?php echo htmlspecialchars($cat['nombre_categoria']); ?></h3>
                                        <p><?php echo htmlspecialchars($cat['descripcion'] ?: 'Sin descripción'); ?></p>
                                    </div>
                                </div>
                                <div class="categoria-stats">
                                    <div class="stat-item">
                                        <i class="bi bi-box-seam"></i>
                                        <span><?php echo $cat['total_productos']; ?> productos</span>
                                    </div>
                                    <div class="stat-item">
                                        <span class="badge badge-<?php echo $cat['activa'] ? 'success' : 'danger'; ?>">
                                            <?php echo $cat['activa'] ? 'Activa' : 'Inactiva'; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="categoria-actions">
                                    <button class="btn btn-warning btn-sm"
                                        onclick='editarCategoria(<?php echo json_encode($cat); ?>)'>
                                        <i class="bi bi-pencil"></i> Editar
                                    </button>
                                    <button class="btn btn-danger btn-sm"
                                        onclick="eliminarCategoria(<?php echo $cat['id_categoria']; ?>, '<?php echo htmlspecialchars($cat['nombre_categoria']); ?>')">
                                        <i class="bi bi-trash"></i> Eliminar
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="alert alert-warning" style="grid-column:1/-1; text-align:center; padding:30px;">
                            <i class="bi bi-exclamation-triangle"></i> No hay categorías registradas.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="productos-section">

                    <div style="display:flex; align-items:center; justify-content:space-between;
                                flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                        <h2 style="margin:0;">
                            <i class="bi bi-box-seam"></i> Inventario Completo de Productos
                        </h2>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <select id="filtroEstado" onchange="filtrarTabla()"
                                style="padding:6px 12px; border-radius:8px; border:1px solid #ccc; font-size:13px;">
                                <option value="">Todos los estados</option>
                                <option value="disponible">Disponible</option>
                                <option value="stock_bajo">Stock Bajo</option>
                                <option value="agotado">Agotado</option>
                                <option value="suspendido">Suspendido</option>
                            </select>
                            <div class="buscador-wrap" style="min-width:280px; flex:none;">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" id="buscarProducto" oninput="filtrarTabla()"
                                    class="form-control"
                                    placeholder="Buscar producto o productor...">
                            </div>
                        </div>
                    </div>

                    <div class="productos-table-container">
                        <div id="contadorResultados" style="font-size:14px;color:var(--text-muted);margin:0 0 10px;font-weight:600;">
                            <i class="bi bi-list-ul"></i>
                            <span id="numResultados"><?php echo $result_productos ? $result_productos->num_rows : 0; ?></span>
                            producto<?php echo ($result_productos && $result_productos->num_rows !== 1) ? 's' : ''; ?>
                            encontrado<?php echo ($result_productos && $result_productos->num_rows !== 1) ? 's' : ''; ?>
                        </div>
                        <div class="table-responsive">
                            <table class="productos-table" id="tablaProductos">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Categoría</th>
                                        <th>Productor</th>
                                        <th>Stock</th>
                                        <th>Precio Detal</th>
                                        <th>Precio Mayorista</th>
                                        <th>Estado</th>
                                        <th>Fecha</th>
                                        <th style="min-width:160px;">Acción Admin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($result_productos && $result_productos->num_rows > 0): ?>
                                    <?php while ($prod = $result_productos->fetch_assoc()):
                                        $esSuspendido = $prod['suspendido_por_admin'] > 0 && $prod['estado'] === 'agotado';
                                        $tieneActivos = $prod['pedidos_activos'] > 0;

                                        if ($esSuspendido)                        $rowClass = 'row-suspendido';
                                        elseif ($prod['estado'] === 'stock_bajo') $rowClass = 'row-warning';
                                        else                                       $rowClass = '';

                                        $dataEstado = $esSuspendido ? 'suspendido' : $prod['estado'];

                                        $fotos = !empty($prod['fotos_producto'])
                                            ? explode('|', $prod['fotos_producto'])
                                            : [];
                                        $fotosJson = json_encode(array_map(fn($f) => '../../' . $f, $fotos));
                                    ?>
                                    <tr class="<?php echo $rowClass; ?>"
                                        data-estado="<?php echo $dataEstado; ?>"
                                        data-nombre="<?php echo strtolower(htmlspecialchars($prod['nombre'])); ?>"
                                        data-productor="<?php echo strtolower(htmlspecialchars($prod['nombre_productor'])); ?>">

                                        <td>
                                            <div class="producto-info">
                                                <div class="producto-text">
                                                    <h4><?php echo htmlspecialchars($prod['nombre']); ?></h4>
                                                    <p><?php echo substr(htmlspecialchars($prod['descripcion'] ?: 'Sin descripción'), 0, 50); ?>...</p>
                                                    <?php if ($esSuspendido): ?>
                                                        <span class="tag-admin">
                                                            <i class="bi bi-eye-slash"></i> Suspendido por admin
                                                        </span>
                                                    <?php endif; ?>

                                                    <?php if (!empty($fotos)): ?>
                                                        <?php $carId = 'car_' . $prod['id_producto']; ?>
                                                        <div class="foto-carousel" id="<?php echo $carId; ?>">
                                                            <div class="carousel-track">
                                                                <?php foreach ($fotos as $idx => $foto): ?>
                                                                    <img src="../../<?php echo htmlspecialchars($foto); ?>"
                                                                         alt="Foto <?php echo $idx + 1; ?>"
                                                                         class="<?php echo $esSuspendido ? 'suspendida' : ''; ?>"
                                                                         onclick="abrirLightbox(<?php echo $fotosJson; ?>, <?php echo $idx; ?>)"
                                                                         title="Clic para ampliar">
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <?php if (count($fotos) > 1): ?>
                                                                <button class="car-btn car-prev" onclick="carNav('<?php echo $carId; ?>', -1)">&#8249;</button>
                                                                <button class="car-btn car-next" onclick="carNav('<?php echo $carId; ?>',  1)">&#8250;</button>
                                                                <div class="car-dots">
                                                                    <?php for ($d = 0; $d < count($fotos); $d++): ?>
                                                                        <div class="car-dot <?php echo $d === 0 ? 'active' : ''; ?>"></div>
                                                                    <?php endfor; ?>
                                                                </div>
                                                                <span class="car-counter">1 / <?php echo count($fotos); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="sin-foto" title="Sin fotos registradas">
                                                            <i class="bi bi-image" style="color:#ccc; font-size:22px;"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <i class="<?php echo htmlspecialchars($prod['categoria_icono'] ?? 'fa-solid fa-tag'); ?>"
                                               style="color:var(--primary); margin-right:8px;"></i>
                                            <?php echo htmlspecialchars($prod['nombre_categoria'] ?? '—'); ?>
                                        </td>

                                        <td>
                                            <strong><?php echo htmlspecialchars($prod['nombre_productor']); ?></strong><br>
                                            <small style="color:var(--text-muted);">
                                                <i class="bi bi-telephone"></i>
                                                <?php echo htmlspecialchars($prod['telefono_productor']); ?>
                                            </small>
                                        </td>

                                        <td>
                                            <strong style="font-size:16px;"><?php echo $prod['cantidad']; ?></strong>
                                            <span style="color:var(--text-muted); font-size:13px;">
                                                <?php echo $prod['unidad_medida']; ?>(s)
                                            </span><br>
                                            <small style="color:var(--text-muted);">Mín: <?php echo $prod['stock_minimo']; ?></small>
                                            <?php if ($tieneActivos): ?>
                                                <br><small style="color:#0d6efd;"
                                                    data-tooltip="<?php echo $prod['pedidos_activos']; ?> pedido(s) confirmado(s) y pagado(s)">
                                                    <i class="bi bi-cart-check-fill"></i>
                                                    <?php echo $prod['pedidos_activos']; ?> pedido(s) activo(s)
                                                </small>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <strong style="color:var(--primary); font-size:16px;">
                                                $<?php echo number_format($prod['precio'], 0, ',', '.'); ?>
                                            </strong><br>
                                            <small style="color:var(--text-muted);">
                                                por <?php echo $prod['unidad_medida']; ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?php if ($prod['precio_mayorista']): ?>
                                                <strong style="color:var(--success); font-size:16px;">
                                                    $<?php echo number_format($prod['precio_mayorista'], 0, ',', '.'); ?>
                                                </strong><br>
                                                <small style="color:var(--text-muted);">
                                                    Desde <?php echo $prod['cantidad_minima_mayorista']; ?> unidades
                                                </small>
                                            <?php else: ?>
                                                <span style="color:var(--text-muted);">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($esSuspendido): ?>
                                                <span class="badge badge-suspendido">
                                                    <i class="bi bi-eye-slash"></i> Suspendido
                                                </span>
                                            <?php else:
                                                switch ($prod['estado']) {
                                                    case 'disponible': $bc='badge-success'; $ic='bi-check-circle';        $tx='Disponible'; break;
                                                    case 'stock_bajo': $bc='badge-warning'; $ic='bi-exclamation-triangle'; $tx='Stock Bajo'; break;
                                                    case 'agotado':    $bc='badge-danger';  $ic='bi-x-circle';             $tx='Agotado';    break;
                                                    default:           $bc='badge-secondary'; $ic='bi-question-circle';   $tx='Desconocido';
                                                }
                                            ?>
                                                <span class="badge <?php echo $bc; ?>">
                                                    <i class="<?php echo $ic; ?>"></i> <?php echo $tx; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <small style="color:var(--text-muted);">
                                                <?php echo date('d/m/Y', strtotime($prod['fecha_creacion'])); ?>
                                            </small>
                                        </td>

                                        <td>
                                            <div class="acciones-moderacion">
                                                <?php if ($esSuspendido): ?>
                                                    <button class="btn-xs btn-success"
                                                        onclick="restaurarProducto(<?php echo $prod['id_producto']; ?>, '<?php echo htmlspecialchars(addslashes($prod['nombre'])); ?>')"
                                                        data-tooltip="Recuperar stock previo y volver a mostrar">
                                                        <i class="bi bi-eye"></i> Restaurar
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-xs btn-warning"
                                                        onclick="abrirModalSuspender(<?php echo $prod['id_producto']; ?>, '<?php echo htmlspecialchars(addslashes($prod['nombre'])); ?>')"
                                                        data-tooltip="Ocultar del catálogo (reversible)">
                                                        <i class="bi bi-eye-slash"></i> Suspender
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" style="text-align:center; padding:30px;">
                                            <div class="alert alert-warning" style="margin:0;">
                                                <i class="bi bi-exclamation-triangle"></i>
                                                No hay productos registrados aún.
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr id="filaVacia" style="display:none;">
                                    <td colspan="9">
                                        <div style="text-align:center; padding:60px 20px;">
                                            <i class="bi bi-inbox" style="font-size:48px; color:var(--text-muted); display:block; margin-bottom:15px;"></i>
                                            <p style="color:var(--text-muted); margin:0;">No hay productos que mostrar.<br>Intenta ajustar los filtros de búsqueda.</p>
                                        </div>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>


<div id="modalCategoria" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTituloCategoria">Nueva Categoría</h2>
            <button class="close-modal" onclick="cerrarModalCategoria()">&times;</button>
        </div>
        <form id="formCategoria" method="POST">
            <input type="hidden" name="accion"       id="accionCategoria" value="crear_categoria">
            <input type="hidden" name="id_categoria" id="id_categoria">

            <div class="form-group">
                <label for="nombre_categoria">Nombre de la Categoría *</label>
                <input type="text" name="nombre_categoria" id="nombre_categoria" required>
            </div>
            <div class="form-group">
                <label for="descripcionCategoria">Descripción</label>
                <textarea name="descripcion" id="descripcionCategoria"
                          placeholder="Describe esta categoría"></textarea>
            </div>
            <div class="form-group">
                <label>Ícono de la Categoría *</label>
                <input type="hidden" name="icono" id="icono" required>
                <div class="icon-picker">
                    <div class="icon-grid" id="iconGrid"></div>
                </div>
                <div class="icon-preview">
                    <span>Seleccionado:</span>
                    <i id="iconPreview" class="fa-solid fa-tag"></i>
                </div>
            </div>
            <div class="form-group" id="grupoActivaCategoria" style="display:none;">
                <div class="checkbox-group">
                    <input type="checkbox" name="activa" id="activa" value="1" checked>
                    <label for="activa">Categoría activa (visible para productores)</label>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalCategoria()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>


<div id="modalSuspender" class="modal">
    <div class="modal-content" style="max-width:490px;">
        <div class="modal-header">
            <h2><i class="bi bi-eye-slash"></i> Suspender Producto</h2>
            <button class="close-modal" onclick="cerrarModalSuspender()">&times;</button>
        </div>
        <form method="POST" id="formSuspender">
            <input type="hidden" name="accion"      value="suspender_producto">
            <input type="hidden" name="id_producto" id="idProdSuspender">

            <div class="alerta-modal warning" id="alertaSuspender"></div>

            <div class="form-group">
                <label for="motivoSuspender">
                    Motivo de suspensión <span style="color:var(--danger)">*</span>
                </label>
                <textarea name="motivo" id="motivoSuspender"
                    placeholder="Ej: Producto con información incorrecta, imágenes inapropiadas, precio abusivo..."
                    required></textarea>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalSuspender()">Cancelar</button>
                <button type="submit" class="btn btn-warning" style="color:#212529;">
                    <i class="bi bi-eye-slash"></i> Confirmar Suspensión
                </button>
            </div>
        </form>
    </div>
</div>


<script>
function bloquearScroll() {
    document.body.classList.add('modal-open');
}

function desbloquearScroll() {
    document.body.classList.remove('modal-open');
}

function toggleMenu() {
    document.getElementById('sidebar').classList.toggle('open');
}
document.addEventListener('click', e => {
    const sidebar = document.getElementById('sidebar');
    const toggle  = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 &&
        !sidebar.contains(e.target) &&
        !toggle.contains(e.target) &&
        sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
    }
});

setTimeout(() => {
    const a = document.getElementById('alertaMensaje');
    if (a) {
        a.style.transition = 'opacity .5s';
        a.style.opacity    = '0';
        setTimeout(() => a.remove(), 500);
    }
}, 5000);

let lbFotos = [];
let lbIdx   = 0;

function abrirLightbox(fotos, idx) {
    lbFotos = fotos;
    lbIdx   = idx;
    document.getElementById('lbImg').src = lbFotos[lbIdx];
    document.getElementById('lightbox').classList.add('show');
    document.addEventListener('keydown', lbKey);
}
function cerrarLightbox() {
    document.getElementById('lightbox').classList.remove('show');
    document.removeEventListener('keydown', lbKey);
}
function lbNav(dir) {
    lbIdx = (lbIdx + dir + lbFotos.length) % lbFotos.length;
    document.getElementById('lbImg').src = lbFotos[lbIdx];
}
function lbKey(e) {
    if (e.key === 'ArrowRight') lbNav(1);
    if (e.key === 'ArrowLeft')  lbNav(-1);
    if (e.key === 'Escape')     cerrarLightbox();
}
document.getElementById('lightbox').addEventListener('click', function(e) {
    if (e.target === this) cerrarLightbox();
});

function abrirModalCategoria(accion) {
    document.getElementById('modalCategoria').classList.add('show');
    document.getElementById('formCategoria').reset();
    if (accion === 'crear') {
        document.getElementById('modalTituloCategoria').textContent   = 'Nueva Categoría';
        document.getElementById('accionCategoria').value              = 'crear_categoria';
        document.getElementById('grupoActivaCategoria').style.display = 'none';
        document.getElementById('iconPreview').className              = 'fa-solid fa-tag';
        document.querySelectorAll('.icon-item').forEach(i => i.classList.remove('selected'));
    }
    bloquearScroll();
}

function editarCategoria(cat) {
    document.getElementById('modalCategoria').classList.add('show');
    document.getElementById('modalTituloCategoria').textContent       = 'Editar Categoría';
    document.getElementById('accionCategoria').value                  = 'editar_categoria';
    document.getElementById('id_categoria').value                     = cat.id_categoria;
    document.getElementById('nombre_categoria').value                 = cat.nombre_categoria;
    document.getElementById('descripcionCategoria').value             = cat.descripcion || '';
    document.getElementById('icono').value                            = cat.icono || '';
    document.getElementById('iconPreview').className                  = cat.icono || 'fa-solid fa-tag';
    document.getElementById('activa').checked                         = cat.activa == 1;
    document.getElementById('grupoActivaCategoria').style.display     = 'block';
    document.querySelectorAll('.icon-item').forEach(item => {
        const i = item.querySelector('i');
        item.classList.toggle('selected', i && i.className === cat.icono);
    });
    bloquearScroll();
}

function cerrarModalCategoria() {
    document.getElementById('modalCategoria').classList.remove('show');
    desbloquearScroll();
}

document.getElementById('modalCategoria').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalCategoria();
});

function abrirModalSuspender(idProducto, nombre) {
    document.getElementById('idProdSuspender').value = idProducto;
    document.getElementById('motivoSuspender').value = '';
    document.getElementById('alertaSuspender').innerHTML =
        `<i class="bi bi-eye-slash"></i> Vas a <strong>suspender</strong> el producto <strong>"${nombre}"</strong>.<br>
         Dejará de aparecer en el catálogo. Su stock quedará guardado y podrás restaurarlo cuando quieras.`;
    document.getElementById('modalSuspender').classList.add('show');
    bloquearScroll();
}

function cerrarModalSuspender() {
    document.getElementById('modalSuspender').classList.remove('show');
    document.getElementById('formSuspender').reset();
    desbloquearScroll();
}

document.getElementById('modalSuspender').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalSuspender();
});

const iconosAgricolas = [
    { nombre:'Zanahoria',  clase:'fa-solid fa-carrot' },
    { nombre:'Manzana',    clase:'fa-solid fa-apple-whole' },
    { nombre:'Limón',      clase:'fa-solid fa-lemon' },
    { nombre:'Trigo',      clase:'fa-solid fa-wheat-awn' },
    { nombre:'Semillas',   clase:'fa-solid fa-seedling' },
    { nombre:'Hojas',      clase:'fa-solid fa-leaf' },
    { nombre:'Pescado',    clase:'fa-solid fa-fish' },
    { nombre:'Pollo',      clase:'fa-solid fa-drumstick-bite' },
    { nombre:'Vaca',       clase:'fa-solid fa-cow' },
    { nombre:'Leche',      clase:'fa-solid fa-mug-hot' },
    { nombre:'Queso',      clase:'fa-solid fa-cheese' },
    { nombre:'Canasta',    clase:'fa-solid fa-basket-shopping' },
    { nombre:'Tractor',    clase:'fa-solid fa-tractor' },
    { nombre:'Sol',        clase:'fa-solid fa-sun' },
    { nombre:'Agua',       clase:'fa-solid fa-droplet' }
];
const grid       = document.getElementById('iconGrid');
const inputIcono = document.getElementById('icono');
const preview    = document.getElementById('iconPreview');
iconosAgricolas.forEach(item => {
    const div = document.createElement('div');
    div.className = 'icon-item';
    div.innerHTML = `<i class="${item.clase}"></i><small>${item.nombre}</small>`;
    div.onclick = () => {
        document.querySelectorAll('.icon-item').forEach(i => i.classList.remove('selected'));
        div.classList.add('selected');
        inputIcono.value  = item.clase;
        preview.className = item.clase;
    };
    grid.appendChild(div);
});

function eliminarCategoria(id, nombre) {
    if (!confirm(`¿Estás seguro de eliminar la categoría "${nombre}"?\n\nSi tiene productos asociados, no se podrá eliminar.`)) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="accion"       value="eliminar_categoria">
        <input type="hidden" name="id_categoria" value="${id}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function restaurarProducto(idProducto, nombre) {
    if (!confirm(`¿Restaurar el producto "${nombre}"?\n\nSe recuperará el stock registrado antes de la suspensión.`)) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="accion"      value="restaurar_producto">
        <input type="hidden" name="id_producto" value="${idProducto}">
    `;
    document.body.appendChild(form);
    form.submit();
}

const carState = {};

function carNav(carId, dir) {
    const el    = document.getElementById(carId);
    if (!el) return;
    const imgs  = el.querySelectorAll('.carousel-track img');
    const total = imgs.length;
    if (total <= 1) return;

    let idx = (carState[carId] ?? 0) + dir;
    if (idx < 0)      idx = total - 1;
    if (idx >= total) idx = 0;
    carState[carId] = idx;

    const anchoSlide = el.offsetWidth;
    el.querySelector('.carousel-track').style.transform = `translateX(-${idx * anchoSlide}px)`;
    el.querySelectorAll('.car-dot').forEach((d, i) => d.classList.toggle('active', i === idx));
    const counter = el.querySelector('.car-counter');
    if (counter) counter.textContent = `${idx + 1} / ${total}`;
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.foto-carousel').forEach(function (el) {
        const total = el.querySelectorAll('.carousel-track img').length;
        if (total > 1) {
            setInterval(function () { carNav(el.id, 1); }, 3000);
        }
    });
});

function filtrarTabla() {
    const filtroEstado = document.getElementById('filtroEstado').value.toLowerCase();
    const buscar       = document.getElementById('buscarProducto').value.toLowerCase();
    const filas        = document.querySelectorAll('#tablaProductos tbody tr:not(#filaVacia)');
    let visibles = 0;

    filas.forEach(fila => {
        const estado    = (fila.dataset.estado    || '').toLowerCase();
        const nombre    = (fila.dataset.nombre    || '').toLowerCase();
        const productor = (fila.dataset.productor || '').toLowerCase();
        const ok = (!filtroEstado || estado === filtroEstado) &&
                   (!buscar || nombre.includes(buscar) || productor.includes(buscar));
        fila.style.display = ok ? '' : 'none';
        if (ok) visibles++;
    });

    const numEl = document.getElementById('numResultados');
    if (numEl) numEl.textContent = visibles;

    const filaVacia = document.getElementById('filaVacia');
    if (filaVacia) filaVacia.style.display = visibles === 0 ? '' : 'none';
}
</script>

</body>
</html>