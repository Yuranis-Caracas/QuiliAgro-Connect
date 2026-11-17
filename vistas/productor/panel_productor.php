<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'productor');
require_once __DIR__ . '/../../config/auth_check.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'productor') {
    header("Location: ../auth/login.php");
    exit();
}

$usuario_nombre = htmlspecialchars($_SESSION['user_nombre']);
$usuario_id     = intval($_SESSION['user_id']);

function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception("Error preparando consulta: " . $conn->error);
            $stmt->bind_param($tipos, ...$params);
            $stmt->execute();
            return $stmt->get_result();
        } else {
            $result = $conn->query($query);
            if (!$result) throw new Exception("Error en consulta: " . $conn->error);
            return $result;
        }
    } catch (Exception $e) {
        error_log("Error BD: " . $e->getMessage());
        return false;
    }
}


$r = ejecutarConsulta($conn, "
    SELECT
        COUNT(*) as total_productos,
        COUNT(CASE WHEN estado = 'disponible' THEN 1 END) as productos_disponibles,
        COUNT(CASE WHEN estado = 'agotado'    THEN 1 END) as productos_agotados,
        COUNT(CASE WHEN estado = 'stock_bajo' THEN 1 END) as productos_stock_bajo
    FROM productos WHERE id_productor = ?
", "i", [$usuario_id]);
$stats_prod = $r ? $r->fetch_assoc() : ['total_productos'=>0,'productos_disponibles'=>0,'productos_agotados'=>0,'productos_stock_bajo'=>0];

$r2 = ejecutarConsulta($conn, "
    SELECT
        COUNT(DISTINCT CASE
            WHEN pe.estado = 'pendiente'
             AND ord.estado = 'pagado'
            THEN pe.id_pedido END
        ) as pedidos_por_entregar,

        COUNT(DISTINCT CASE
            WHEN pe.estado = 'confirmado'
            THEN pe.id_pedido END
        ) as pedidos_confirmados,

        COUNT(DISTINCT CASE
            WHEN pe.estado = 'entregado'
            THEN pe.id_pedido END
        ) as pedidos_entregados,

        COALESCE(SUM(
            CASE WHEN pe.estado IN ('confirmado','entregado')
            THEN pe.total ELSE 0 END
        ), 0) as ventas_totales

    FROM pedidos pe
    INNER JOIN ordenes ord ON pe.id_orden = ord.id_orden
    WHERE pe.id_productor = ?
    ", "i", [$usuario_id]);
$stats_ped = $r2 ? $r2->fetch_assoc() : ['pedidos_por_entregar'=>0,'pedidos_confirmados'=>0,'pedidos_entregados'=>0,'ventas_totales'=>0];

$r3 = ejecutarConsulta($conn, "
    SELECT
        COALESCE(AVG(calificacion), 0) as promedio_calificaciones,
        COUNT(*) as total_resenas
    FROM calificaciones_resenas
    WHERE id_productor = ? AND estado = 'activa'
", "i", [$usuario_id]);
$stats_cal = $r3 ? $r3->fetch_assoc() : ['promedio_calificaciones'=>0,'total_resenas'=>0];

$estadisticas = array_merge($stats_prod, $stats_ped, $stats_cal);

$result_perfil = ejecutarConsulta($conn, "SELECT * FROM perfiles_productor WHERE id_productor = ?", "i", [$usuario_id]);
$perfil = $result_perfil ? $result_perfil->fetch_assoc() : null;

$result_productos_recientes = ejecutarConsulta($conn, "
    SELECT p.*, c.nombre_categoria
    FROM productos p
    LEFT JOIN categorias_productos c ON p.id_categoria = c.id_categoria
    WHERE p.id_productor = ?
    ORDER BY p.fecha_creacion DESC
    LIMIT 3
", "i", [$usuario_id]);

$result_pedidos_recientes = ejecutarConsulta($conn, "
    SELECT pe.*,
           pr.nombre  as producto_nombre,
           u.nombre   as comprador_nombre,
           pag.estado as estado_pago,
           mp.tipo    as tipo_metodo,
           mp.nombre_metodo,
           ord.estado as estado_orden
    FROM pedidos pe
    INNER JOIN productos pr  ON pe.id_producto  = pr.id_producto
    INNER JOIN usuarios u    ON pe.id_comprador  = u.id_usuario
    INNER JOIN ordenes ord   ON pe.id_orden      = ord.id_orden
    LEFT JOIN  pagos pag     ON ord.id_orden     = pag.id_orden
    LEFT JOIN  metodos_pago mp ON pag.id_metodo_pago = mp.id_metodo
    WHERE pe.id_productor = ?
      AND (
          pe.estado IN ('confirmado','entregado')
          OR (pe.estado = 'pendiente' AND ord.estado = 'pagado')
      )
    ORDER BY pe.fecha_pedido DESC
    LIMIT 3
", "i", [$usuario_id]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Productor — QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/quiliagro_connect/config/pwa_head.php'; ?>
    <style>
        .welcome-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white; padding: 30px; border-radius: var(--radius-lg);
            margin-bottom: 30px; box-shadow: var(--shadow-lg);
        }
        .welcome-card h2 { font-size: 28px; margin-bottom: 10px; }
        .welcome-card p  { font-size: 16px; opacity: .9; }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px; margin-bottom: 30px;
        }
        .quick-action-btn {
            background: white; padding: 25px; border-radius: var(--radius);
            text-align: center; text-decoration: none; color: var(--text-dark);
            transition: var(--transition); box-shadow: var(--shadow-sm);
            border: 2px solid transparent;
        }
        .quick-action-btn:hover { transform: translateY(-5px); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .quick-action-btn i    { font-size: 36px; color: var(--primary); margin-bottom: 10px; display: block; }
        .quick-action-btn span { display: block; font-weight: 600; font-size: 15px; }

        .recent-section {
            background: white; padding: 25px; border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm); margin-bottom: 25px;
        }
        .recent-section h3 {
            color: var(--primary); margin-bottom: 20px; font-size: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .recent-item {
            padding: 15px; border-bottom: 1px solid #f0f0f0;
            display: flex; justify-content: space-between;
            align-items: center; transition: var(--transition);
        }
        .recent-item:last-child { border-bottom: none; }
        .recent-item:hover { background: var(--bg-light); border-radius: var(--radius-sm); }
        .recent-item-info h4 { font-size: 15px; margin-bottom: 5px; color: var(--text-dark); }
        .recent-item-info p  { font-size: 13px; color: var(--text-muted); margin: 0; }

        .perfil-incompleto {
            background: #fff3cd; border-left: 5px solid var(--warning);
            padding: 20px; border-radius: var(--radius); margin-bottom: 25px;
            display: flex; align-items: center; gap: 15px;
        }
        .perfil-incompleto i { font-size: 32px; color: var(--warning); }
        .perfil-incompleto-text h3 { font-size: 18px; color: var(--text-dark); margin-bottom: 5px; }
        .perfil-incompleto-text p  { font-size: 14px; color: var(--text-muted); margin: 0; }

        .rating-display { display: flex; align-items: center; gap: 8px; }
        .rating-display i { color: #ffc107; }
        .rating-display span { font-weight: 700; color: var(--text-dark); }

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

        @media (max-width: 768px) {
            .welcome-card h2 { font-size: 22px; }
            .quick-actions   { grid-template-columns: repeat(2,1fr); }
        }
    </style>
</head>
<body>

<div class="connection-status offline">
    <span class="pulse"></span><span>Sin conexión</span>
</div>

<button class="menu-toggle" onclick="toggleMenu()"><i class="bi bi-list"></i></button>

<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo">
        </div>
        <nav class="sidebar-menu">
            <a href="panel_productor.php" class="active"><i class="bi bi-speedometer2"></i> Inicio</a>
            <a href="productos_productor.php"><i class="bi bi-box-seam"></i> Mis Productos</a>
            <a href="pedidos_productor.php"><i class="bi bi-clipboard-check"></i> Pedidos</a>
            <a href="calificaciones_productor.php"><i class="bi bi-star"></i> Reseñas</a>
            <a href="perfil.php"><i class="bi bi-person-circle"></i> Mi Perfil</a>
            <a href="estadisticas.php"><i class="bi bi-bar-chart"></i> Estadísticas</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <div class="topbar-info">
                    <h1>Panel del Productor</h1>
                    <p class="subtitle">Bienvenid@, <?php echo $usuario_nombre; ?></p>
                </div>
            </div>
            <div class="topbar-right">
            <a href="../ayuda/ayuda.php" class="btn-logout">
                    <i class="fas fa-circle-question"></i> Ayuda
                </a>
                <a href="../auth/cambiar_password.php" class="btn-logout">
                    <i class="fas fa-key"></i> Cambiar contraseña
                </a>    
            <a href="../../logout.php" class="btn-logout" title="Cerrar Sesión">
                    <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                </a>
            </div>
        </header>

        <div class="content-area">

            <?php if (!$perfil): ?>
            <div class="perfil-incompleto">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div class="perfil-incompleto-text">
                    <h3>¡Completa tu perfil!</h3>
                    <p>Agrega información sobre tu finca para generar más confianza con los compradores.</p>
                    <a href="perfil.php" class="btn btn-warning btn-sm" style="margin-top:10px;">
                        <i class="bi bi-pencil"></i> Completar Perfil
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($estadisticas['pedidos_por_entregar'] > 0): ?>
            <div class="alert alert-info">
                <i class="bi bi-clock-history"></i>
                Tienes <strong><?php echo $estadisticas['pedidos_por_entregar']; ?></strong>
                pedido(s) pagados esperando ser entregados.
                <a href="pedidos_productor.php" class="btn btn-sm btn-primary" style="margin-left:10px;">
                    Ver Pedidos
                </a>
            </div>
            <?php endif; ?>

            <?php if ($estadisticas['productos_agotados'] > 0): ?>
            <div class="alert alert-warning">
                <i class="bi bi-box-seam"></i>
                <strong><?php echo $estadisticas['productos_agotados']; ?></strong> producto(s) agotado(s).
                <a href="productos_productor.php?estado=agotado" class="btn btn-sm btn-warning" style="margin-left:10px;">
                    Ver Productos
                </a>

            </div>
            <?php endif; ?>

            <?php if ($estadisticas['productos_stock_bajo'] > 0): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                <strong><?php echo $estadisticas['productos_stock_bajo']; ?></strong> producto(s) con stock bajo.
                <a href="productos_productor.php?estado=stock_bajo" class="btn btn-sm btn-warning" style="margin-left:10px;">
                    Revisar Stock
                </a>
            </div>
            <?php endif; ?>

            <section class="stats-grid">

                <div class="stat-card">
                    <i class="bi bi-box-seam card-icon"></i>
                    <h3>Total Productos</h3>
                    <div class="number"><?php echo $estadisticas['total_productos']; ?></div>
                </div>

                <div class="stat-card primary">
                    <i class="bi bi-check-all card-icon"></i>
                    <h3>Confirmados</h3>
                    <div class="number"><?php echo $estadisticas['pedidos_confirmados']; ?></div>
                </div>

                <div class="stat-card primary">
                    <i class="bi bi-truck card-icon"></i>
                    <h3>Entregados</h3>
                    <div class="number"><?php echo $estadisticas['pedidos_entregados']; ?></div>
                </div>

                <div class="stat-card">
                    <i class="bi bi-cash-stack card-icon"></i>
                    <h3>Ventas Totales</h3>
                    <div class="number">$<?php echo number_format($estadisticas['ventas_totales'], 0); ?></div>
                </div>

                <div class="stat-card">
                    <i class="bi bi-star-fill card-icon"></i>
                    <h3>Calificación</h3>
                    <div class="rating-display" style="justify-content:center;margin-top:10px;">
                        <i class="bi bi-star-fill"></i>
                        <span style="font-size:32px;font-weight:800;color:var(--primary);">
                            <?php echo number_format($estadisticas['promedio_calificaciones'], 1); ?>
                        </span>
                        <span style="color:var(--text-muted);font-size:14px;">
                            (<?php echo $estadisticas['total_resenas']; ?> reseñas)
                        </span>
                    </div>
                </div>

            </section>

            <div class="recent-grid">
                <div class="recent-section">
                    <h3><i class="bi bi-box-seam"></i> Productos Recientes</h3>
                    <?php if ($result_productos_recientes && $result_productos_recientes->num_rows > 0): ?>
                        <?php while ($producto = $result_productos_recientes->fetch_assoc()): ?>
                        <div class="recent-item">
                            <div class="recent-item-info">
                                <h4><?php echo htmlspecialchars($producto['nombre']); ?></h4>
                                <p>
                                    <?php echo htmlspecialchars($producto['nombre_categoria']); ?> —
                                    $<?php echo number_format($producto['precio'], 0); ?> —
                                    Stock: <?php echo $producto['cantidad']; ?>
                                </p>
                            </div>
                            <span class="badge badge-<?php
                                echo $producto['estado'] === 'disponible' ? 'success' :
                                    ($producto['estado'] === 'stock_bajo'  ? 'warning' : 'danger');
                            ?>">
                                <?php echo ucfirst(str_replace('_',' ',$producto['estado'])); ?>
                            </span>
                        </div>
                        <?php endwhile; ?>
                        <a href="productos_productor.php" class="btn btn-primary btn-sm" style="width:100%;margin-top:15px;">
                            Ver Todos los Productos
                        </a>
                    <?php else: ?>
                        <p style="text-align:center;color:var(--text-muted);padding:20px;">No tienes productos registrados</p>
                        <a href="productos_productor.php" class="btn btn-primary btn-sm" style="width:100%;">
                            <i class="bi bi-plus-circle"></i> Agregar Producto
                        </a>
                    <?php endif; ?>
                </div>

                <div class="recent-section">
                    <h3><i class="bi bi-clipboard-check"></i> Pedidos Recientes</h3>
                    <?php if ($result_pedidos_recientes && $result_pedidos_recientes->num_rows > 0): ?>
                        <?php while ($pedido = $result_pedidos_recientes->fetch_assoc()): ?>
                        <div class="recent-item">
                            <div class="recent-item-info">
                                <h4><?php echo htmlspecialchars($pedido['producto_nombre']); ?></h4>
                                <p>
                                    <?php echo htmlspecialchars($pedido['comprador_nombre']); ?> —
                                    Cant: <?php echo $pedido['cantidad']; ?> —
                                    $<?php echo number_format($pedido['total'], 0); ?>
                                    <?php if ($pedido['nombre_metodo']): ?>
                                        <br>
                                        <?php echo htmlspecialchars($pedido['nombre_metodo']); ?>
                                        <?php if ($pedido['estado_orden'] === 'pagado' && $pedido['estado'] === 'pendiente'): ?>
                                            <span style="color:var(--success);font-weight:600;"> ✓ Listo para entregar</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <span class="badge badge-<?php
                                echo $pedido['estado'] === 'confirmado' ? 'success'  :
                                    ($pedido['estado'] === 'entregado'  ? 'success'  :
                                    ($pedido['estado'] === 'pendiente'  ? 'warning'  : 'danger'));
                            ?>">
                                <?php echo ucfirst($pedido['estado']); ?>
                            </span>
                        </div>
                        <?php endwhile; ?>
                        <a href="pedidos_productor.php" class="btn btn-primary btn-sm" style="width:100%;margin-top:15px;">
                            Ver Todos los Pedidos
                        </a>
                    <?php else: ?>
                        <p style="text-align:center;color:var(--text-muted);padding:20px;">No tienes pedidos registrados</p>
                    <?php endif; ?>
                </div>

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
        connectionStatus.classList.remove('offline');
        connectionStatus.classList.add('online');
        connectionStatus.innerHTML = '<span class="pulse"></span><span>En línea</span>';
        setTimeout(() => { connectionStatus.style.display = 'none'; }, 3000);
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

function toggleMenu() {
    document.getElementById('sidebar').classList.toggle('open');
}
document.addEventListener('click', (e) => {
    const sidebar     = document.getElementById('sidebar');
    const menuToggle  = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 &&
        !sidebar.contains(e.target) &&
        !menuToggle.contains(e.target) &&
        sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
    }
});

</script>
</body>
</html>