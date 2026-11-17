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
$usuario_id = intval($_SESSION['user_id']);

function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Error preparando consulta: " . $conn->error);
            }
            $stmt->bind_param($tipos, ...$params);
            $stmt->execute();
            return $stmt->get_result();
        } else {
            $result = $conn->query($query);
            if (!$result) {
                throw new Exception("Error en consulta: " . $conn->error);
            }
            return $result;
        }
    } catch (Exception $e) {
        error_log("Error BD: " . $e->getMessage());
        return false;
    }
}

$query_stats = "
    SELECT 
        -- Usuarios
        (SELECT COUNT(*) FROM usuarios WHERE rol = 'productor') as total_productores,
        (SELECT COUNT(*) FROM usuarios WHERE rol = 'comprador') as total_compradores,
        (SELECT COUNT(*) FROM productos) as total_productos,
        
        -- Órdenes con pago (totales)
        (SELECT COUNT(DISTINCT id_orden) FROM ordenes) as total_ordenes,
        
        -- Órdenes con pago pendiente (registrado o en revisión)
        (SELECT COUNT(DISTINCT ord.id_orden) 
         FROM ordenes ord
         INNER JOIN pagos pag ON ord.id_orden = pag.id_orden
         WHERE pag.estado IN ('registrado', 'en_revision')) as ordenes_pendientes,
        
        -- Órdenes con pago confirmado
        (SELECT COUNT(DISTINCT ord.id_orden) 
         FROM ordenes ord
         INNER JOIN pagos pag ON ord.id_orden = pag.id_orden
         WHERE pag.estado = 'confirmado') as ordenes_confirmadas,
        
        -- Ventas totales (suma de órdenes con pago confirmado)
        (SELECT COALESCE(SUM(ord.total), 0) 
         FROM ordenes ord
         INNER JOIN pagos pag ON ord.id_orden = pag.id_orden
         WHERE pag.estado = 'confirmado') as ventas_totales
";

$result_stats = ejecutarConsulta($conn, $query_stats);
$estadisticas = $result_stats ? $result_stats->fetch_assoc() : [
    'total_productores' => 0,
    'total_compradores' => 0,
    'total_productos' => 0,
    'total_ordenes' => 0,
    'ordenes_pendientes' => 0,
    'ordenes_confirmadas' => 0,
    'ventas_totales' => 0
];

$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$query_audit = "INSERT INTO auditoria_admin (id_administrador, accion, ip_address) VALUES (?, 'acceso_panel', ?)";
ejecutarConsulta($conn, $query_audit, "is", [$usuario_id, $ip_address]);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administrador - QuiliAgro Connect</title>
    
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/quiliagro_connect/config/pwa_head.php'; ?>
    
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="apple-touch-icon" href="../../imagenes/icon-192x192.png">
    
    
    <link rel="stylesheet" href="../../css/styles.css">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    
    <style>
        .connection-status {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            z-index: 9999;
            display: none;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .connection-status.online {
            background: #28a745;
            color: white;
        }
        
        .connection-status.offline {
            background: #dc3545;
            color: white;
            display: flex;
        }
        
        .pulse {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: white;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        
    </style>
</head>
<body>

<div class="connection-status offline">
    <span class="pulse"></span>
    <span>Sin conexión</span>
</div>

<button class="menu-toggle" onclick="toggleMenu()">
    <i class="bi bi-list"></i>
</button>

<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo">
        </div>

        <nav class="sidebar-menu">
            <a href="panel.php" class="active">
                <i class="bi bi-speedometer2"></i> Inicio
            </a>
            <a href="usuarios.php">
                <i class="bi bi-people"></i> Usuarios
            </a>
            <a href="catalogo_admin.php">
                <i class="bi bi-tags"></i> Catálogo
            </a>
            <a href="pedidos_admin.php">
                <i class="bi bi-box-seam"></i> Pedidos
            </a>
            <a href="pagos_admin.php">
                <i class="bi bi-credit-card"></i> Pagos
            </a>
            <a href="calificaciones.php">
                <i class="bi bi-star"></i> Reseñas
            </a>
            <a href="reportes.php">
                <i class="bi bi-bar-chart"></i> Reportes
            </a>
            <a href="auditoria_admin.php">
                <i class="bi bi-shield-check"></i> Auditoría
            </a>
            <a href="ayudas_admin.php">
                <i class="bi bi-question-circle"></i> Ayuda
            </a>
        </nav>
    </aside>

    <main class="main-content">

        <header class="topbar">
            <div class="topbar-left">
                <div class="topbar-info">
                    <h1>Panel de Administración QuiliAgro Connect</h1>
                    <p class="subtitle">Bienvenid@, <?php echo $usuario_nombre; ?></p>
                </div>
            </div>

            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout" title="Cerrar Sesión">
                    <i class="bi bi-box-arrow-right"></i>Cerrar Sesión
                </a>
            </div>

            <div class="topbar-right">
                <a href="../auth/cambiar_password.php "  class="btn-logout">
                    <i class="fas fa-key"></i> Cambiar contraseña
                </a>
            </div>
        </header>

        <div class="content-area">
            <section class="stats-grid">
                <div class="stat-card">
                    <i class="bi bi-person-badge card-icon"></i>
                    <h3>Productores</h3>
                    <div class="number"><?php echo $estadisticas['total_productores']; ?></div>
                </div>

                <div class="stat-card">
                    <i class="bi bi-cart-check card-icon"></i>
                    <h3>Compradores</h3>
                    <div class="number"><?php echo $estadisticas['total_compradores']; ?></div>
                </div>

                <div class="stat-card">
                    <i class="bi bi-box-seam card-icon"></i>
                    <h3>Productos</h3>
                    <div class="number"><?php echo $estadisticas['total_productos']; ?></div>
                </div>

                <div class="stat-card">
                    <i class="bi bi-receipt card-icon"></i>
                    <h3>Total Compras</h3>
                    <div class="number"><?php echo $estadisticas['total_ordenes']; ?></div>
                    <p>Registradas en la plataforma</p>
                </div>

                <div class="stat-card warning">
                    <i class="bi bi-clock-history card-icon"></i>
                    <h3>Pendientes</h3>
                    <div class="number"><?php echo $estadisticas['ordenes_pendientes']; ?></div>
                    <small>Pago no confirmado</small>
                </div>

                <div class="stat-card success">
                    <i class="bi bi-check-circle card-icon"></i>
                    <h3>Confirmadas</h3>
                    <div class="number"><?php echo $estadisticas['ordenes_confirmadas']; ?></div>
                    <small>Pago confirmado</small>
                </div>

                <div class="stat-card primary">
                    <i class="bi bi-cash-stack card-icon"></i>
                    <h3>Ventas Totales</h3>
                    <div class="number">$<?php echo number_format($estadisticas['ventas_totales'], 0); ?></div>
                    <small>Ingresos reales</small>
                </div>
            </section>
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
        setTimeout(() => {
            connectionStatus.style.display = 'none';
        }, 3000);
    } else {
        connectionStatus.classList.remove('online');
        connectionStatus.classList.add('offline');
        connectionStatus.innerHTML = '<span class="pulse"></span><span>Sin conexión</span>';
        connectionStatus.style.display = 'flex';
    }
}

window.addEventListener('online', updateConnectionStatus);
window.addEventListener('offline', updateConnectionStatus);
updateConnectionStatus();

function toggleMenu() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('open');
}

document.addEventListener('click', (e) => {
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.querySelector('.menu-toggle');
    
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