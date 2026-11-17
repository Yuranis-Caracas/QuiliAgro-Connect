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
$usuario_id = intval($_SESSION['user_id']);

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

$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

$query_general = "
    SELECT 
        (SELECT COUNT(*) FROM productos WHERE id_productor = ?) AS total_productos,
        (SELECT COUNT(*) FROM productos WHERE id_productor = ? AND estado = 'disponible') AS productos_disponibles,
        COUNT(DISTINCT ped.id_pedido) AS total_pedidos,
        COUNT(DISTINCT CASE WHEN ped.estado = 'entregado' THEN ped.id_pedido END) AS pedidos_entregados,
        COALESCE(SUM(CASE WHEN ped.estado IN ('confirmado','entregado') THEN ped.total ELSE 0 END), 0) AS ingresos_totales,
        (SELECT COALESCE(AVG(calificacion), 0) FROM calificaciones_resenas WHERE id_productor = ? AND estado = 'activa') AS calificacion_promedio,
        (SELECT COUNT(*) FROM calificaciones_resenas WHERE id_productor = ? AND estado = 'activa') AS total_resenas
    FROM pedidos ped
    WHERE ped.id_productor = ?
      AND ped.estado IN ('confirmado','entregado')
";
$result_general = ejecutarConsulta($conn, $query_general, "iiiii", [
    $usuario_id, $usuario_id, $usuario_id, $usuario_id, $usuario_id
]);
$stats_general = $result_general->fetch_assoc();

$query_periodo = "
    SELECT 
        COUNT(DISTINCT ped.id_pedido) AS pedidos_periodo,
        COALESCE(SUM(CASE WHEN ped.estado IN ('confirmado','entregado') THEN ped.total ELSE 0 END), 0) AS ventas_periodo,
        COUNT(DISTINCT CASE WHEN ped.estado = 'entregado' THEN ped.id_pedido END) AS pedidos_entregados,
        COUNT(DISTINCT CASE WHEN ped.estado = 'confirmado' THEN ped.id_pedido END) AS pedidos_confirmados,
        COALESCE(AVG(CASE WHEN ped.estado IN ('confirmado','entregado') THEN ped.total END), 0) AS ticket_promedio
    FROM pedidos ped
    WHERE ped.id_productor = ?
      AND DATE(ped.fecha_pedido) BETWEEN ? AND ?
      AND ped.estado IN ('confirmado','entregado')
";
$result_periodo = ejecutarConsulta($conn, $query_periodo, "iss", [
    $usuario_id, $fecha_desde, $fecha_hasta
]);
$stats_periodo = $result_periodo->fetch_assoc();

$query_productos_top = "
    SELECT 
        p.id_producto,
        p.nombre,
        p.precio,
        p.cantidad AS stock_actual,
        COUNT(DISTINCT ped.id_pedido) AS total_ventas,
        COALESCE(SUM(ped.cantidad), 0) AS unidades_vendidas,
        COALESCE(SUM(ped.total), 0) AS ingresos_generados,
        c.nombre_categoria
    FROM productos p
    LEFT JOIN pedidos ped ON p.id_producto = ped.id_producto
        AND DATE(ped.fecha_pedido) BETWEEN ? AND ?
        AND ped.estado IN ('confirmado','entregado')
    LEFT JOIN categorias_productos c ON p.id_categoria = c.id_categoria
    WHERE p.id_productor = ?
    GROUP BY p.id_producto
    ORDER BY total_ventas DESC, ingresos_generados DESC
    LIMIT 10
";
$result_productos_top = ejecutarConsulta($conn, $query_productos_top, "ssi", [
    $fecha_desde, $fecha_hasta, $usuario_id
]);

$query_pedidos_estado = "
    SELECT 
        ped.estado,
        COUNT(DISTINCT ped.id_pedido) AS cantidad,
        COALESCE(SUM(ped.total), 0) AS monto_total
    FROM pedidos ped
    WHERE ped.id_productor = ?
      AND DATE(ped.fecha_pedido) BETWEEN ? AND ?
      AND ped.estado IN ('confirmado','entregado')
    GROUP BY ped.estado
    ORDER BY cantidad DESC
";
$result_pedidos_estado = ejecutarConsulta($conn, $query_pedidos_estado, "iss", [
    $usuario_id, $fecha_desde, $fecha_hasta
]);

$query_categorias = "
    SELECT 
        c.nombre_categoria,
        c.icono,
        COUNT(DISTINCT p.id_producto) AS total_productos,
        COUNT(DISTINCT ped.id_pedido) AS total_pedidos,
        COALESCE(SUM(ped.cantidad), 0) AS unidades_vendidas,
        COALESCE(SUM(ped.total), 0) AS ingresos_generados
    FROM categorias_productos c
    LEFT JOIN productos p ON c.id_categoria = p.id_categoria AND p.id_productor = ?
    LEFT JOIN pedidos ped ON p.id_producto = ped.id_producto
        AND DATE(ped.fecha_pedido) BETWEEN ? AND ?
        AND ped.estado IN ('confirmado','entregado')
    WHERE c.activa = 1 AND p.id_producto IS NOT NULL
    GROUP BY c.id_categoria
    ORDER BY ingresos_generados DESC
";
$result_categorias = ejecutarConsulta($conn, $query_categorias, "iss", [
    $usuario_id, $fecha_desde, $fecha_hasta
]);

$query_compradores = "
    SELECT 
        u.nombre,
        u.telefono,
        COUNT(DISTINCT ped.id_pedido) AS total_compras,
        COALESCE(SUM(ped.total), 0) AS total_gastado,
        MAX(ped.fecha_pedido) AS ultima_compra
    FROM pedidos ped
    INNER JOIN usuarios u ON ped.id_comprador = u.id_usuario
    WHERE ped.id_productor = ?
      AND DATE(ped.fecha_pedido) BETWEEN ? AND ?
      AND ped.estado IN ('confirmado','entregado')
    GROUP BY u.id_usuario
    ORDER BY total_compras DESC
    LIMIT 10
";
$result_compradores = ejecutarConsulta($conn, $query_compradores, "iss", [
    $usuario_id, $fecha_desde, $fecha_hasta
]);

$query_alertas_stock = "
    SELECT p.id_producto, p.nombre, p.cantidad, p.stock_minimo, p.estado, c.nombre_categoria
    FROM productos p
    LEFT JOIN categorias_productos c ON p.id_categoria = c.id_categoria
    WHERE p.id_productor = ? AND (p.estado = 'stock_bajo' OR p.estado = 'agotado')
    ORDER BY p.cantidad ASC
    LIMIT 10
";
$result_alertas = ejecutarConsulta($conn, $query_alertas_stock, "i", [$usuario_id]);

$query_calificaciones = "
    SELECT 
        COUNT(*) AS total_resenas,
        ROUND(AVG(calificacion), 1) AS promedio_general,
        COUNT(CASE WHEN calificacion = 5 THEN 1 END) AS cinco_estrellas,
        COUNT(CASE WHEN calificacion = 4 THEN 1 END) AS cuatro_estrellas,
        COUNT(CASE WHEN calificacion = 3 THEN 1 END) AS tres_estrellas,
        COUNT(CASE WHEN calificacion = 2 THEN 1 END) AS dos_estrellas,
        COUNT(CASE WHEN calificacion = 1 THEN 1 END) AS una_estrella
    FROM calificaciones_resenas
    WHERE id_productor = ? AND estado = 'activa'
      AND DATE(fecha_calificacion) BETWEEN ? AND ?
";
$result_calificaciones = ejecutarConsulta($conn, $query_calificaciones, "iss", [
    $usuario_id, $fecha_desde, $fecha_hasta
]);
$stats_calificaciones = $result_calificaciones->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Estadísticas - QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .report-section {
            background: var(--bg-card); padding: 25px;
            border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); margin-bottom: 25px;
        }
        .report-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--bg-light);
        }
        .report-header h3 { color: var(--primary); font-size: 20px; margin: 0; display: flex; align-items: center; gap: 10px; }
        .mini-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .mini-stat {
            background: linear-gradient(135deg, var(--bg-light) 0%, #fff 100%);
            padding: 20px; border-radius: var(--radius); border-left: 4px solid var(--primary); transition: var(--transition);
        }
        .mini-stat:hover { transform: translateY(-3px); box-shadow: var(--shadow-sm); }
        .mini-stat h4 { font-size: 13px; color: var(--text-muted); margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .mini-stat .value { font-size: 28px; font-weight: 700; color: var(--primary); }
        .ranking-table { width: 100%; border-collapse: collapse; }
        .ranking-table thead { background: var(--bg-light); }
        .ranking-table th { padding: 12px 15px; text-align: left; font-weight: 600; color: var(--text-dark); font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .ranking-table td { padding: 12px 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .ranking-table tbody tr:hover { background: rgba(40,167,69,0.05); }
        .ranking-position { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; font-weight: 700; font-size: 14px; }
        .ranking-position.gold   { background: linear-gradient(135deg,#FFD700,#FFA500); color: white; }
        .ranking-position.silver { background: linear-gradient(135deg,#C0C0C0,#A9A9A9); color: white; }
        .ranking-position.bronze { background: linear-gradient(135deg,#CD7F32,#8B4513); color: white; }
        .ranking-position.normal { background: var(--bg-light); color: var(--text-dark); }
        .chart-container { background: white; padding: 20px; border-radius: var(--radius); margin-top: 15px; }
        .bar-chart { display: flex; flex-direction: column; gap: 15px; }
        .bar-item { display: flex; align-items: center; gap: 15px; }
        .bar-label { min-width: 120px; font-size: 14px; font-weight: 600; color: var(--text-dark); }
        .bar-wrapper { flex: 1; height: 30px; background: #e9ecef; border-radius: 15px; overflow: hidden; }
        .bar-fill {
            height: 100%; background: linear-gradient(90deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 15px; transition: width 0.8s ease;
            display: flex; align-items: center; justify-content: flex-end;
            padding-right: 10px; color: white; font-size: 12px; font-weight: 600;
        }
        .bar-value { min-width: 80px; text-align: right; font-size: 14px; font-weight: 700; color: var(--primary); }
        .empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 48px; margin-bottom: 15px; display: block; opacity: 0.5; }
        .filter-bar { background: white; padding: 20px; border-radius: var(--radius); box-shadow: var(--shadow-sm); margin-bottom: 25px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .star-rating { color: #ffc107; font-size: 16px; }
        .alert-stock { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; border-radius: var(--radius); margin-bottom: 20px; }
        .alert-stock h4 { margin: 0 0 10px 0; color: #856404; display: flex; align-items: center; gap: 8px; }

        @media (max-width: 768px) {

            .ranking-table thead {
                display: none;
            }

            .ranking-table tbody tr {
                display: block;
                background: var(--bg-card);
                border: 1px solid #e8f5e9;
                border-radius: 12px;
                padding: 15px;
                margin-bottom: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            }

            .ranking-table td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 6px 0;
                border-bottom: 1px solid #f0f0f0;
                font-size: 14px;
            }

            .ranking-table td:last-child {
                border-bottom: none;
                padding-bottom: 0;
            }

            .ranking-table td::before {
                font-weight: 600;
                font-size: 12px;
                color: var(--text-muted);
                text-transform: uppercase;
                letter-spacing: 0.4px;
            }

            .tabla-productos td:nth-child(1)::before { content: "Posición"; }
            .tabla-productos td:nth-child(2)::before { content: "Producto"; }
            .tabla-productos td:nth-child(3)::before { content: "Categoría"; }
            .tabla-productos td:nth-child(4)::before { content: "Stock"; }
            .tabla-productos td:nth-child(5)::before { content: "Ventas"; }
            .tabla-productos td:nth-child(6)::before { content: "Unidades"; }
            .tabla-productos td:nth-child(7)::before { content: "Ingresos"; }

            .tabla-clientes td:nth-child(1)::before { content: "Posición"; }
            .tabla-clientes td:nth-child(2)::before { content: "Cliente"; }
            .tabla-clientes td:nth-child(3)::before { content: "Teléfono"; }
            .tabla-clientes td:nth-child(4)::before { content: "Compras"; }
            .tabla-clientes td:nth-child(5)::before { content: "Total gastado"; }
            .tabla-clientes td:nth-child(6)::before { content: "Última compra"; }

            .mini-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .bar-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }

            .bar-label {
                min-width: unset;
                width: 100%;
                font-size: 13px;
            }

            .bar-wrapper {
                width: 100%;
            }

            .bar-value {
                width: 100%;
                text-align: left;
                font-size: 13px;
            }

            .mini-stats { grid-template-columns: 1fr; }
            .ranking-table { font-size: 12px; }
            .ranking-table th, .ranking-table td { padding: 8px 10px; }
            .bar-label { min-width: 80px; font-size: 12px; }
            .filter-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleMenu()"><i class="bi bi-list"></i></button>

<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo">
        </div>
        <nav class="sidebar-menu">
            <a href="panel_productor.php"><i class="bi bi-speedometer2"></i> Inicio</a>
            <a href="productos_productor.php"><i class="bi bi-box-seam"></i> Mis Productos</a>
            <a href="pedidos_productor.php"><i class="bi bi-clipboard-check"></i> Pedidos</a>
            <a href="calificaciones_productor.php"><i class="bi bi-star"></i> Reseñas</a>
            <a href="perfil.php"><i class="bi bi-person"></i> Mi Perfil</a>
            <a href="estadisticas.php" class="active"><i class="bi bi-bar-chart"></i> Estadísticas</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Mis Estadísticas</h1>
                <p class="subtitle">Análisis de tu desempeño y ventas</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
            </div>
        </header>

        <div class="content-area">

            <div class="filter-bar">
                <form method="GET" class="filter-grid">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Fecha Desde</label>
                        <input type="date" name="fecha_desde" class="form-control" value="<?php echo $fecha_desde; ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Fecha Hasta</label>
                        <input type="date" name="fecha_hasta" class="form-control" value="<?php echo $fecha_hasta; ?>">
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            <i class="bi bi-funnel"></i> Filtrar
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($result_alertas && $result_alertas->num_rows > 0): ?>
            <div class="alert-stock">
                <h4><i class="bi bi-exclamation-triangle"></i> Alertas de Stock</h4>
                <p style="margin:0;color:#856404;">
                    Tienes <?php echo $result_alertas->num_rows; ?> producto(s) con stock bajo o agotado.
                    <a href="productos_productor.php" style="color:#856404;text-decoration:underline;">Ver productos</a>
                </p>
            </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card primary">
                    <i class="bi bi-box-seam card-icon"></i>
                    <h3>Mis Productos</h3>
                    <div class="number"><?php echo number_format($stats_general['total_productos']); ?></div>
                    <p><?php echo $stats_general['productos_disponibles']; ?> disponibles</p>
                </div>
                <div class="stat-card success">
                    <i class="bi bi-cart-check card-icon"></i>
                    <h3>Pedidos Totales</h3>
                    <div class="number"><?php echo number_format($stats_general['total_pedidos']); ?></div>
                    <p><?php echo $stats_general['pedidos_entregados']; ?> entregados</p>
                </div>
                <div class="stat-card warning">
                    <i class="bi bi-currency-dollar card-icon"></i>
                    <h3>Ingresos Totales</h3>
                    <div class="number">$<?php echo number_format($stats_general['ingresos_totales'], 0); ?></div>
                    <p>Confirmados + entregados</p>
                </div>
                <div class="stat-card info">
                    <i class="bi bi-star card-icon"></i>
                    <h3>Calificación</h3>
                    <div class="number"><?php echo number_format($stats_general['calificacion_promedio'], 1); ?> ★</div>
                    <p><?php echo $stats_general['total_resenas']; ?> reseñas</p>
                </div>
            </div>

            <div class="report-section">
                <div class="report-header">
                    <h3><i class="bi bi-calendar-range"></i> Métricas del Período</h3>
                    <span style="color:var(--text-muted);font-size:14px;">
                        <?php echo date('d/m/Y', strtotime($fecha_desde)); ?> — <?php echo date('d/m/Y', strtotime($fecha_hasta)); ?>
                    </span>
                </div>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <h4><i class="bi bi-cart"></i> Total Pedidos</h4>
                        <div class="value"><?php echo number_format($stats_periodo['pedidos_periodo']); ?></div>
                    </div>
                    <div class="mini-stat">
                        <h4><i class="bi bi-currency-dollar"></i> Ventas</h4>
                        <div class="value">$<?php echo number_format($stats_periodo['ventas_periodo'], 2); ?></div>
                    </div>
                    <div class="mini-stat">
                        <h4><i class="bi bi-check-circle"></i> Confirmados</h4>
                        <div class="value"><?php echo number_format($stats_periodo['pedidos_confirmados']); ?></div>
                    </div>
                    <div class="mini-stat">
                        <h4><i class="bi bi-truck"></i> Entregados</h4>
                        <div class="value"><?php echo number_format($stats_periodo['pedidos_entregados']); ?></div>
                    </div>
                    <div class="mini-stat">
                        <h4><i class="bi bi-receipt"></i> Ticket Promedio</h4>
                        <div class="value">$<?php echo number_format($stats_periodo['ticket_promedio'], 2); ?></div>
                    </div>
                </div>
            </div>

            <div class="report-section">
                <div class="report-header">
                    <h3><i class="bi bi-trophy"></i> Mis Productos Más Vendidos</h3>
                </div>
                <?php if ($result_productos_top && $result_productos_top->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="ranking-table tabla-productos">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th>Stock</th>
                                <th>Ventas</th>
                                <th>Unidades</th>
                                <th>Ingresos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $posicion = 1; while ($producto = $result_productos_top->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="ranking-position <?php echo $posicion == 1 ? 'gold' : ($posicion == 2 ? 'silver' : ($posicion == 3 ? 'bronze' : 'normal')); ?>">
                                        <?php echo $posicion; ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($producto['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($producto['nombre_categoria'] ?? 'Sin categoría'); ?></td>
                                <td><?php echo number_format($producto['stock_actual']); ?></td>
                                <td><?php echo number_format($producto['total_ventas']); ?></td>
                                <td><?php echo number_format($producto['unidades_vendidas']); ?></td>
                                <td><strong style="color:var(--primary);">$<?php echo number_format($producto['ingresos_generados'], 2); ?></strong></td>
                            </tr>
                            <?php $posicion++; endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No hay productos con ventas en el período seleccionado</p>
                </div>
                <?php endif; ?>
            </div>

            <div class="report-section">
                <div class="report-header">
                    <h3><i class="bi bi-tags"></i> Ventas por Categoría</h3>
                </div>
                <?php if ($result_categorias && $result_categorias->num_rows > 0): ?>
                <div class="chart-container">
                    <div class="bar-chart">
                        <?php
                        $categorias_array = [];
                        while ($cat = $result_categorias->fetch_assoc()) $categorias_array[] = $cat;
                        $max_ingresos = max(array_column($categorias_array, 'ingresos_generados'));
                        $max_ingresos = $max_ingresos > 0 ? $max_ingresos : 1;
                        foreach ($categorias_array as $categoria):
                            $porcentaje = ($categoria['ingresos_generados'] / $max_ingresos) * 100;
                        ?>
                        <div class="bar-item">
                            <div class="bar-label">
                                <?php if ($categoria['icono']): ?><i class="<?php echo htmlspecialchars($categoria['icono']); ?>"></i><?php endif; ?>
                                <?php echo htmlspecialchars($categoria['nombre_categoria']); ?>
                            </div>
                            <div class="bar-wrapper">
                                <div class="bar-fill" style="width:<?php echo $porcentaje; ?>%;">
                                    <?php if ($porcentaje > 15 && $categoria['total_pedidos'] > 0): ?>
                                        <?php echo number_format($categoria['total_pedidos']); ?> pedidos
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="bar-value">$<?php echo number_format($categoria['ingresos_generados'], 0); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No hay ventas por categoría en el período seleccionado</p>
                </div>
                <?php endif; ?>
            </div>

            <div class="report-section">
                <div class="report-header">
                    <h3><i class="bi bi-people"></i> Mis Clientes Frecuentes</h3>
                </div>
                <?php if ($result_compradores && $result_compradores->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="ranking-table tabla-clientes">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Cliente</th>
                                <th>Teléfono</th>
                                <th>Compras</th>
                                <th>Total Gastado</th>
                                <th>Última Compra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $posicion = 1; while ($comprador = $result_compradores->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="ranking-position <?php echo $posicion == 1 ? 'gold' : ($posicion == 2 ? 'silver' : ($posicion == 3 ? 'bronze' : 'normal')); ?>">
                                        <?php echo $posicion; ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($comprador['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($comprador['telefono']); ?></td>
                                <td><?php echo number_format($comprador['total_compras']); ?></td>
                                <td><strong style="color:var(--primary);">$<?php echo number_format($comprador['total_gastado'], 2); ?></strong></td>
                                <td><?php echo $comprador['ultima_compra'] ? date('d/m/Y', strtotime($comprador['ultima_compra'])) : 'N/A'; ?></td>
                            </tr>
                            <?php $posicion++; endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No hay clientes con pedidos en el período seleccionado</p>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($stats_calificaciones['total_resenas'] > 0): ?>
            <div class="report-section">
                <div class="report-header">
                    <h3><i class="bi bi-star"></i> Mis Calificaciones</h3>
                </div>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <h4><i class="bi bi-chat-left-text"></i> Total Reseñas</h4>
                        <div class="value"><?php echo number_format($stats_calificaciones['total_resenas']); ?></div>
                    </div>
                    <div class="mini-stat">
                        <h4><i class="bi bi-star-fill"></i> Promedio</h4>
                        <div class="value" style="display:flex;align-items:center;gap:8px;">
                            <span class="star-rating" style="font-size:24px;">★</span>
                            <?php echo number_format($stats_calificaciones['promedio_general'], 1); ?>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <h4><i class="bi bi-emoji-smile"></i> 5 Estrellas</h4>
                        <div class="value"><?php echo number_format($stats_calificaciones['cinco_estrellas']); ?></div>
                    </div>
                    <div class="mini-stat">
                        <h4><i class="bi bi-emoji-neutral"></i> 4 Estrellas</h4>
                        <div class="value"><?php echo number_format($stats_calificaciones['cuatro_estrellas']); ?></div>
                    </div>
                    <div class="mini-stat">
                        <h4><i class="bi bi-emoji-frown"></i> ≤3 Estrellas</h4>
                        <div class="value"><?php echo number_format($stats_calificaciones['tres_estrellas'] + $stats_calificaciones['dos_estrellas'] + $stats_calificaciones['una_estrella']); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="report-section">
                <div class="report-header">
                    <h3><i class="bi bi-pie-chart"></i> Estado de Pedidos</h3>
                </div>
                <?php if ($result_pedidos_estado && $result_pedidos_estado->num_rows > 0): ?>
                <div class="chart-container">
                    <div class="bar-chart">
                        <?php
                        $estados_array = [];
                        while ($estado = $result_pedidos_estado->fetch_assoc()) $estados_array[] = $estado;
                        $max_cantidad = max(array_column($estados_array, 'cantidad'));
                        $max_cantidad = $max_cantidad > 0 ? $max_cantidad : 1;
                        $estado_iconos  = ['confirmado' => 'bi-check-circle', 'entregado' => 'bi-truck'];
                        $estado_nombres = ['confirmado' => 'Confirmados',    'entregado' => 'Entregados'];
                        foreach ($estados_array as $estado):
                            $porcentaje = ($estado['cantidad'] / $max_cantidad) * 100;
                            $icono  = $estado_iconos[$estado['estado']]  ?? 'bi-circle';
                            $nombre = $estado_nombres[$estado['estado']] ?? ucfirst($estado['estado']);
                        ?>
                        <div class="bar-item">
                            <div class="bar-label">
                                <i class="bi <?php echo $icono; ?>"></i> <?php echo $nombre; ?>
                            </div>
                            <div class="bar-wrapper">
                                <div class="bar-fill" style="width:<?php echo $porcentaje; ?>%;">
                                    <?php if ($porcentaje > 20): echo number_format($estado['cantidad']); endif; ?>
                                </div>
                            </div>
                            <div class="bar-value">$<?php echo number_format($estado['monto_total'], 0); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No hay pedidos en el período seleccionado</p>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<script>
function toggleMenu() {
    document.getElementById('sidebar').classList.toggle('open');
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

const inputDesde = document.querySelector('input[name="fecha_desde"]');
const inputHasta = document.querySelector('input[name="fecha_hasta"]');

function siguienteDia(fecha) {
    const d = new Date(fecha + 'T00:00:00');
    d.setDate(d.getDate() + 1);
    return d.toISOString().split('T')[0];
}

inputDesde.addEventListener('change', function() {
    const minHasta = siguienteDia(this.value);
    inputHasta.min = minHasta;
    if (inputHasta.value && inputHasta.value <= this.value) {
        inputHasta.value = minHasta;
    }
});

document.addEventListener('DOMContentLoaded', function() {
    inputHasta.min = siguienteDia(inputDesde.value);

    document.querySelectorAll('.bar-fill').forEach(bar => {
        const w = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => { bar.style.width = w; }, 100);
    });
});
</script>
</body>
</html>