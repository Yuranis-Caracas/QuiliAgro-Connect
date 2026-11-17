<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'comprador');
require_once __DIR__ . '/../../config/auth_check.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'comprador') {
    header("Location: ../auth/login.php");
    exit();
}

$usuario_nombre = htmlspecialchars($_SESSION['user_nombre']);
$usuario_id     = intval($_SESSION['user_id']);

$conn->query("UPDATE ordenes SET estado = 'expirado'
              WHERE id_comprador = $usuario_id
                AND estado = 'pendiente'
                AND fecha_expiracion <= NOW()");

function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception("Error preparando consulta: " . $conn->error);
            $stmt->bind_param($tipos, ...$params);
            $stmt->execute();
            return $stmt->get_result();
        }
        $result = $conn->query($query);
        if (!$result) throw new Exception("Error en consulta: " . $conn->error);
        return $result;
    } catch (Exception $e) {
        error_log("Error BD: " . $e->getMessage());
        return false;
    }
}

$stmt_al = $conn->prepare("SELECT COUNT(*) FROM alertas WHERE id_comprador = ? AND leida = 0");
$stmt_al->bind_param("i", $usuario_id);
$stmt_al->execute();
$alertas_no_leidas = (int)$stmt_al->get_result()->fetch_row()[0];

$query_stats = "
    SELECT
        (SELECT COUNT(DISTINCT o.id_orden)
         FROM ordenes o INNER JOIN pagos pag ON o.id_orden = pag.id_orden
         WHERE o.id_comprador = ?) as total_pedidos,

        (SELECT COUNT(DISTINCT o.id_orden)
         FROM ordenes o INNER JOIN pagos pag ON o.id_orden = pag.id_orden
         WHERE o.id_comprador = ?
           AND o.estado = 'pendiente'
           AND pag.estado = 'registrado') as pedidos_pendientes,

        (SELECT COUNT(DISTINCT o.id_orden)
         FROM ordenes o
         INNER JOIN pagos pag ON o.id_orden = pag.id_orden
         INNER JOIN pedidos ped ON o.id_orden = ped.id_orden
         WHERE o.id_comprador = ?
           AND ped.estado = 'confirmado'
           AND pag.estado = 'confirmado') as pedidos_confirmados,

        (SELECT COUNT(DISTINCT o.id_orden)
         FROM ordenes o
         WHERE o.id_comprador = ?
           AND NOT EXISTS (
               SELECT 1 FROM pedidos ped
               WHERE ped.id_orden = o.id_orden AND ped.estado != 'entregado'
           )
           AND EXISTS (SELECT 1 FROM pedidos ped WHERE ped.id_orden = o.id_orden)
        ) as pedidos_entregados,

        (SELECT COALESCE(SUM(o.total), 0)
         FROM ordenes o INNER JOIN pagos pag ON o.id_orden = pag.id_orden
         WHERE o.id_comprador = ? AND pag.estado = 'confirmado') as total_gastado,

        (SELECT COUNT(DISTINCT ped.id_productor)
         FROM pedidos ped
         INNER JOIN ordenes o ON ped.id_orden = o.id_orden
         INNER JOIN pagos pag ON o.id_orden = pag.id_orden
         WHERE o.id_comprador = ? AND pag.estado = 'confirmado') as productores_diferentes,

        (SELECT COUNT(*) FROM calificaciones_resenas WHERE id_comprador = ?) as total_calificaciones
";
$result_stats = ejecutarConsulta($conn, $query_stats, "iiiiiii", [
    $usuario_id, $usuario_id, $usuario_id, $usuario_id,
    $usuario_id, $usuario_id, $usuario_id
]);
$estadisticas = $result_stats ? $result_stats->fetch_assoc() : [
    'total_pedidos' => 0, 'pedidos_pendientes' => 0, 'pedidos_confirmados' => 0,
    'pedidos_entregados' => 0, 'total_gastado' => 0, 'productores_diferentes' => 0,
    'total_calificaciones' => 0
];

$query_pedidos_recientes = "
    SELECT
        o.id_orden, o.total as total_orden, o.fecha as fecha_orden,
        o.estado as estado_orden, o.fecha_expiracion,
        pag.estado as estado_pago,
        COUNT(ped.id_pedido) as num_productos,
        GROUP_CONCAT(DISTINCT pr.nombre SEPARATOR ' + ') as productos,
        MIN(ped.estado) as estado_min_pedido
    FROM ordenes o
    INNER JOIN pagos pag ON o.id_orden = pag.id_orden
    INNER JOIN pedidos ped ON o.id_orden = ped.id_orden
    INNER JOIN productos pr ON ped.id_producto = pr.id_producto
    WHERE o.id_comprador = ?
    GROUP BY o.id_orden
    ORDER BY o.fecha DESC
    LIMIT 3
";
$result_pedidos_recientes = ejecutarConsulta($conn, $query_pedidos_recientes, "i", [$usuario_id]);

$query_productos_recomendados = "
    SELECT
        p.id_producto, p.nombre, p.precio, p.unidad_medida,
        p.imagen as imagen_principal, p.estado, p.cantidad as stock,
        u.id_usuario as productor_id, u.nombre as productor_nombre, u.telefono as productor_telefono,
        c.nombre_categoria, c.icono,
        COUNT(DISTINCT ped.id_pedido) as total_ventas,
        COALESCE(AVG(cal.calificacion), 0) as promedio_calificacion,
        COUNT(DISTINCT cal.id_calificacion) as total_resenas,
        GROUP_CONCAT(DISTINCT fp.ruta_foto ORDER BY fp.orden SEPARATOR '|') as fotos_adicionales
    FROM productos p
    INNER JOIN usuarios u ON p.id_productor = u.id_usuario
    INNER JOIN categorias_productos c ON p.id_categoria = c.id_categoria
    LEFT JOIN pedidos ped ON p.id_producto = ped.id_producto
    LEFT JOIN calificaciones_resenas cal ON p.id_producto = cal.id_pedido AND cal.estado = 'activa'
    LEFT JOIN fotos_productos fp ON p.id_producto = fp.id_producto
    WHERE p.estado IN ('disponible', 'stock_bajo') AND p.cantidad > 0 AND c.activa = 1
    GROUP BY p.id_producto
    ORDER BY total_ventas DESC, p.fecha_creacion DESC
    LIMIT 4
";
$result_productos_recomendados = ejecutarConsulta($conn, $query_productos_recomendados);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Comprador - QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/quiliagro_connect/config/pwa_head.php'; ?>
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .connection-status {
            position: fixed; top: 10px; right: 10px; padding: 8px 15px;
            border-radius: 20px; font-size: 12px; font-weight: 600;
            z-index: 9999; display: none; align-items: center; gap: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
        }
        .connection-status.online  { background: #28a745; color: white; }
        .connection-status.offline { background: #dc3545; color: white; display: flex; }
        .pulse { width: 8px; height: 8px; border-radius: 50%; background: white; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

        .bell-link {
            position: relative;
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; border-radius: 50%;
            background: rgba(255,255,255,.15);
            color: white; text-decoration: none; font-size: 18px;
            transition: background .2s;
        }
        .bell-link:hover { background: rgba(255,255,255,.3); }
        .bell-badge {
            position: absolute; top: -3px; right: -3px;
            background: #ff3d3d; color: white;
            font-size: 10px; font-weight: 700;
            min-width: 18px; height: 18px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px; border: 2px solid white; line-height: 1;
        }

        .productos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px; margin-bottom: 30px;
        }
        .producto-card {
            background: white; border-radius: var(--radius-lg); overflow: hidden;
            box-shadow: var(--shadow-sm); transition: var(--transition);
            border: 2px solid transparent; display: flex; flex-direction: column; position: relative;
        }
        .producto-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-lg); border-color: var(--primary); }
        .producto-badge {
            position: absolute; top: 12px; right: 12px;
            background: var(--primary); color: white; padding: 6px 14px;
            border-radius: 20px; font-size: 12px; font-weight: 700; z-index: 10;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .producto-imagenes { position: relative; width: 100%; height: 240px; background: var(--bg-light); overflow: hidden; }
        .carrusel-container { position: relative; width: 100%; height: 100%; }
        .carrusel-imagenes { display: flex; width: 100%; height: 100%; transition: transform 0.4s ease; }
        .carrusel-imagen { min-width: 100%; height: 100%; object-fit: cover; }
        .carrusel-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #f0f0f0, #e0e0e0); color: #999; }
        .carrusel-placeholder i { font-size: 64px; }
        .carrusel-nav {
            position: absolute; top: 50%; transform: translateY(-50%);
            background: rgba(0,0,0,0.5); color: white; border: none;
            width: 36px; height: 36px; border-radius: 50%; cursor: pointer;
            display: flex; align-items: center; justify-content: center; transition: var(--transition); z-index: 5;
        }
        .carrusel-nav:hover { background: rgba(0,0,0,0.8); transform: translateY(-50%) scale(1.1); }
        .carrusel-nav.prev { left: 10px; }
        .carrusel-nav.next { right: 10px; }
        .carrusel-indicadores { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); display: flex; gap: 6px; z-index: 5; }
        .carrusel-indicador { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.5); cursor: pointer; transition: var(--transition); }
        .carrusel-indicador.active { background: white; width: 24px; border-radius: 4px; }
        .producto-info { padding: 20px; flex: 1; display: flex; flex-direction: column; }
        .producto-nombre { font-size: 18px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; min-height: 48px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .producto-categoria { font-size: 13px; color: var(--text-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
        .producto-precio { font-size: 28px; font-weight: 800; color: var(--primary); margin-bottom: 12px; }
        .producto-rating { display: flex; align-items: center; gap: 8px; margin-bottom: 15px; font-size: 14px; }
        .producto-rating i { color: #ffc107; font-size: 16px; }
        .producto-acciones { display: flex; gap: 8px; margin-top: auto; }
        .btn-producto { flex: 1; padding: 12px; border: none; border-radius: var(--radius); font-size: 14px; font-weight: 600; cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; }
        .btn-ver { background: var(--primary); color: white; }
        .btn-ver:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-whatsapp { background: #25D366; color: white; flex: 0 0 50px; }
        .btn-whatsapp:hover { background: #128C7E; transform: translateY(-2px); }

        .recent-section { background: white; padding: 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); margin-bottom: 25px; }
        .recent-section h3 { color: var(--primary); margin-bottom: 20px; font-size: 20px; display: flex; align-items: center; gap: 10px; }
        .recent-item { padding: 15px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; transition: var(--transition); }
        .recent-item:last-child { border-bottom: none; }
        .recent-item:hover { background: var(--bg-light); border-radius: var(--radius-sm); }

        @media (max-width: 768px) { .productos-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div class="connection-status">
    <span class="pulse"></span><span>Sin conexión</span>
</div>

<button class="menu-toggle" onclick="toggleMenu()"><i class="bi bi-list"></i></button>

<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header"><img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo"></div>
        <nav class="sidebar-menu">
            <a href="panel_comprador.php" class="active"><i class="bi bi-speedometer2"></i> Inicio</a>
            <a href="catalogo_comprador.php"><i class="bi bi-shop"></i> Catálogo</a>
            <a href="pedidos_comprador.php"><i class="bi bi-clipboard-check"></i> Mis Pedidos</a>
            <a href="productores_comprador.php"><i class="bi bi-people"></i> Productores</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Panel del Comprador</h1>
                <p class="subtitle">Bienvenid@, <?php echo $usuario_nombre; ?></p>
            </div>
            <div class="topbar-right" style="display:flex;gap:10px;align-items:center;">
                <a href="alertas_comprador.php" class="bell-link" title="Mis alertas">
                    <i class="bi bi-bell<?= $alertas_no_leidas > 0 ? '-fill' : '' ?>"></i>
                    <?php if ($alertas_no_leidas > 0): ?>
                        <span class="bell-badge">
                            <?= $alertas_no_leidas > 99 ? '99+' : $alertas_no_leidas ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="../ayuda/ayuda.php" class="btn-logout">
                    <i class="fas fa-circle-question"></i> Ayuda
                </a>
                <a href="../auth/cambiar_password.php" class="btn-logout">
                    <i class="fas fa-key"></i> Cambiar contraseña
                </a>
                <a href="../../logout.php" class="btn-logout">
                    <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                </a>
            </div>
        </header>

        <div class="content-area">

            <?php if ($estadisticas['pedidos_pendientes'] > 0): ?>
            <div class="alert alert-warning">
                <i class="bi bi-clock-history"></i>
                Tienes <strong><?php echo $estadisticas['pedidos_pendientes']; ?></strong>
                <?php echo $estadisticas['pedidos_pendientes'] == 1 ? 'pedido pendiente' : 'pedidos pendientes'; ?>
                de pago o verificación.
            </div>
            <?php endif; ?>

            <?php if ($alertas_no_leidas > 0): ?>
            <div class="alert alert-info" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <span>
                    <i class="bi bi-bell-fill"></i>
                    Tienes <strong><?= $alertas_no_leidas ?></strong>
                    <?= $alertas_no_leidas == 1 ? 'alerta nueva' : 'alertas nuevas' ?>
                    — novedades en productos de tu interés.
                </span>
                <a href="alertas_comprador.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-bell"></i> Ver alertas
                </a>
            </div>
            <?php endif; ?>

            <section class="stats-grid">
                <div class="stat-card">
                    <i class="bi bi-clipboard-check card-icon"></i>
                    <h3>Total Pedidos</h3>
                    <div class="number"><?php echo $estadisticas['total_pedidos']; ?></div>
                </div>
                <div class="stat-card warning">
                    <i class="bi bi-clock-history card-icon"></i>
                    <h3>Pendientes</h3>
                    <div class="number"><?php echo $estadisticas['pedidos_pendientes']; ?></div>
                </div>
                <div class="stat-card primary">
                    <i class="bi bi-check-circle card-icon"></i>
                    <h3>Confirmados</h3>
                    <div class="number"><?php echo $estadisticas['pedidos_confirmados']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="bi bi-box-seam card-icon"></i>
                    <h3>Entregados</h3>
                    <div class="number"><?php echo $estadisticas['pedidos_entregados']; ?></div>
                </div>
                <div class="stat-card">
                    <i class="bi bi-cash-stack card-icon"></i>
                    <h3>Total Gastado</h3>
                    <div class="number">$<?php echo number_format($estadisticas['total_gastado'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <i class="bi bi-people card-icon"></i>
                    <h3>Productores</h3>
                    <div class="number"><?php echo $estadisticas['productores_diferentes']; ?></div>
                </div>
            </section>

            <h2 style="color:var(--primary);margin:30px 0 20px 0;">
                <i class="bi bi-star"></i> Productos Destacados
            </h2>
            <div class="productos-grid">
                <?php if ($result_productos_recomendados && $result_productos_recomendados->num_rows > 0): ?>
                    <?php while ($producto = $result_productos_recomendados->fetch_assoc()):
                        $imagenes = [];
                        if ($producto['imagen_principal']) $imagenes[] = $producto['imagen_principal'];
                        if ($producto['fotos_adicionales']) {
                            foreach (explode('|', $producto['fotos_adicionales']) as $foto) {
                                if ($foto && $foto != $producto['imagen_principal']) $imagenes[] = $foto;
                            }
                        }
                        $total_imagenes = count($imagenes);
                    ?>
                    <div class="producto-card">
                        <?php if ($producto['total_ventas'] > 5): ?>
                        <div class="producto-badge">🔥 Popular</div>
                        <?php endif; ?>
                        <div class="producto-imagenes">
                            <?php if ($total_imagenes > 0): ?>
                            <div class="carrusel-container">
                                <div class="carrusel-imagenes" data-current="0">
                                    <?php foreach ($imagenes as $imagen): ?>
                                    <img src="../../<?php echo htmlspecialchars($imagen); ?>"
                                         alt="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                         class="carrusel-imagen">
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($total_imagenes > 1): ?>
                                <button class="carrusel-nav prev" onclick="cambiarImagen(this, -1)"><i class="bi bi-chevron-left"></i></button>
                                <button class="carrusel-nav next" onclick="cambiarImagen(this, 1)"><i class="bi bi-chevron-right"></i></button>
                                <div class="carrusel-indicadores">
                                    <?php for ($i = 0; $i < $total_imagenes; $i++): ?>
                                    <div class="carrusel-indicador <?php echo $i === 0 ? 'active' : ''; ?>"
                                         onclick="irAImagen(this, <?php echo $i; ?>)"></div>
                                    <?php endfor; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="carrusel-placeholder"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="producto-info">
                            <div class="producto-nombre"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:13px;color:var(--text-dark);">
                                <i class="bi bi-person-badge" style="color:var(--primary);"></i>
                                <strong><?php echo htmlspecialchars($producto['productor_nombre']); ?></strong>
                            </div>
                            <div class="producto-categoria">
                                <i class="<?php echo $producto['icono'] ?: 'bi bi-tag'; ?>"></i>
                                <?php echo htmlspecialchars($producto['nombre_categoria']); ?>
                            </div>
                            <div class="producto-precio">
                                $<?php echo number_format($producto['precio'], 0); ?>
                                <span style="font-size:14px;font-weight:500;color:var(--text-muted);margin-left:4px;">
                                    /<?php echo ucfirst($producto['unidad_medida']); ?>
                                </span>
                            </div>
                            <?php if ($producto['total_resenas'] > 0): ?>
                            <div class="producto-rating">
                                <i class="bi bi-star-fill"></i>
                                <span><?php echo number_format($producto['promedio_calificacion'], 1); ?></span>
                                <span style="color:var(--text-muted);">(<?php echo $producto['total_resenas']; ?>)</span>
                            </div>
                            <?php endif; ?>
                            <div class="producto-acciones">
                                <a href="catalogo_comprador.php?producto=<?php echo $producto['id_producto']; ?>" class="btn-producto btn-ver">
                                    <i class="bi bi-eye"></i> Ver Detalles
                                </a>
                                <a href="https://wa.me/57<?php echo preg_replace('/[^0-9]/', '', $producto['productor_telefono']); ?>?text=Hola,%20me%20interesa:%20<?php echo urlencode($producto['nombre']); ?>"
                                   target="_blank" class="btn-producto btn-whatsapp" title="WhatsApp">
                                    <i class="bi bi-whatsapp"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="grid-column:1/-1;text-align:center;color:var(--text-muted);padding:40px;">
                        No hay productos disponibles en este momento
                    </p>
                <?php endif; ?>
            </div>

            <div class="recent-section">
                <h3><i class="bi bi-clipboard-check"></i> Mis Pedidos Recientes</h3>
                <?php if ($result_pedidos_recientes && $result_pedidos_recientes->num_rows > 0): ?>
                    <?php while ($pedido = $result_pedidos_recientes->fetch_assoc()):
                        if ($pedido['estado_orden'] == 'expirado') {
                            $badge_class = 'secondary'; $badge_text = '⌛ Expirado';
                        } elseif ($pedido['estado_pago'] == 'confirmado' && $pedido['estado_min_pedido'] == 'entregado') {
                            $badge_class = 'success'; $badge_text = '🚚 Entregado';
                        } elseif ($pedido['estado_pago'] == 'confirmado') {
                            $badge_class = 'primary'; $badge_text = '✅ Confirmado';
                        } elseif ($pedido['estado_pago'] == 'registrado') {
                            $badge_class = 'warning'; $badge_text = '⏳ Pendiente pago';
                        } else {
                            $badge_class = 'secondary'; $badge_text = ucfirst($pedido['estado_orden']);
                        }
                    ?>
                    <div class="recent-item">
                        <div>
                            <h4 style="margin-bottom:5px;"><?php echo htmlspecialchars($pedido['productos']); ?></h4>
                            <p style="font-size:13px;color:var(--text-muted);margin:0;">
                                <?php echo $pedido['num_productos']; ?> producto(s) —
                                Total: $<?php echo number_format($pedido['total_orden'], 0); ?> —
                                <?php echo date('d/m/Y H:i', strtotime($pedido['fecha_orden'])); ?>
                            </p>
                        </div>
                        <span class="badge badge-<?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                    </div>
                    <?php endwhile; ?>
                    <a href="pedidos_comprador.php" class="btn btn-primary btn-sm" style="width:100%;margin-top:15px;">
                        <i class="bi bi-clipboard-check"></i> Ver todos mis pedidos
                    </a>
                <?php else: ?>
                    <div style="text-align:center;padding:80px 20px;">
                        <i class="bi bi-inbox" style="font-size:100px;color:#e0e0e0;display:block;margin-bottom:20px;"></i>
                        <h3 style="font-size:28px;margin-bottom:15px;color:var(--text-dark);display:block;">No hay pedidos registrados</h3>
                        <p style="font-size:16px;color:var(--text-muted);">¡Explora nuestro catálogo y realiza tu primera compra!</p>
                        <a href="catalogo_comprador.php" class="btn btn-primary" style="margin-top:15px;">
                            <i class="bi bi-shop"></i> Ver Catálogo
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <footer style="text-align: center; padding: 14px 20px; font-size: 12px; color: #888; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; border-top: 1px solid #eee;">
            &copy; <?php echo date('Y'); ?> QuiliAgro Connect &mdash; Todos los derechos reservados &mdash; NIT: 900.123.456-7
        </footer>
    </main>
</div>

<script>
const connectionStatus = document.querySelector('.connection-status');
function updateConnectionStatus() {
    if (navigator.onLine) {
        connectionStatus.style.display = 'none';
    } else {
        connectionStatus.classList.remove('online');
        connectionStatus.classList.add('offline');
        connectionStatus.innerHTML = '<span class="pulse"></span><span>Sin conexión</span>';
        connectionStatus.style.display = 'flex';
    }
}
window.addEventListener('online',  updateConnectionStatus);
window.addEventListener('offline', updateConnectionStatus);
updateConnectionStatus();

(function () {
    const KEY  = 'qa_alertas_last';
    const SEIS = 6 * 60 * 60 * 1000;
    const last = parseInt(localStorage.getItem(KEY) || '0');
    if (Date.now() - last < SEIS) return;
    fetch('../../scripts/generar_alertas_automaticas.php', { keepalive: true })
        .then(() => localStorage.setItem(KEY, Date.now().toString()))
        .catch(() => {});
})();

function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }

function cambiarImagen(btn, direccion) {
    const container   = btn.closest('.carrusel-container');
    const carrusel    = container.querySelector('.carrusel-imagenes');
    const total       = carrusel.querySelectorAll('.carrusel-imagen').length;
    const indicadores = container.querySelectorAll('.carrusel-indicador');
    let current = (parseInt(carrusel.dataset.current) + direccion + total) % total;
    carrusel.style.transform = `translateX(-${current * 100}%)`;
    carrusel.dataset.current = current;
    indicadores.forEach((ind, idx) => ind.classList.toggle('active', idx === current));
}

function irAImagen(indicador, index) {
    const container   = indicador.closest('.carrusel-container');
    const carrusel    = container.querySelector('.carrusel-imagenes');
    const indicadores = container.querySelectorAll('.carrusel-indicador');
    carrusel.style.transform = `translateX(-${index * 100}%)`;
    carrusel.dataset.current = index;
    indicadores.forEach((ind, idx) => ind.classList.toggle('active', idx === index));
}

setInterval(() => {
    document.querySelectorAll('.carrusel-nav.next').forEach(btn => {
        if (!btn.closest('.producto-card:hover')) cambiarImagen(btn, 1);
    });
}, 5000);

document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar');
    const mt = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && !sb.contains(e.target) && !mt.contains(e.target) && sb.classList.contains('open'))
        sb.classList.remove('open');
});
</script>
</body>
</html>