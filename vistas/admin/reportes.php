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
            $stmt->execute();
            return stripos(trim($query), 'select') === 0 ? $stmt->get_result() : $stmt;
        }
        $result = $conn->query($query);
        if (!$result) throw new Exception($conn->error);
        return $result;
    } catch (Exception $e) {
        error_log('Error BD: ' . $e->getMessage());
        return false;
    }
}

$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

if ($fecha_desde > $fecha_hasta) {
    $fecha_desde = date('Y-m-d', strtotime('-30 days'));
    $fecha_hasta = date('Y-m-d');
}

$result_general = ejecutarConsulta($conn, "
    SELECT
        (SELECT COUNT(*) FROM usuarios WHERE rol='productor' AND eliminado=0) as total_productores,
        (SELECT COUNT(*) FROM usuarios WHERE rol='comprador' AND eliminado=0) as total_compradores,
        (SELECT COUNT(*) FROM productos)                                       as total_productos,
        (SELECT COUNT(*) FROM categorias_productos WHERE activa=1)             as total_categorias,
        (SELECT COUNT(*) FROM pedidos)                                         as total_pedidos,
        (SELECT COALESCE(SUM(o.total),0)
         FROM ordenes o INNER JOIN pagos p ON o.id_orden=p.id_orden
         WHERE p.estado='confirmado')                                          as ventas_completadas,
        (SELECT COUNT(*) FROM calificaciones_resenas WHERE estado='activa')    as total_resenas
");
$stats_general = $result_general ? $result_general->fetch_assoc() : [];

$result_periodo = ejecutarConsulta($conn, "
    SELECT
        COUNT(DISTINCT o.id_orden)                                                                   AS pedidos_periodo,
        COALESCE(SUM(CASE WHEN p.estado='confirmado' THEN o.total ELSE 0 END),0)                     AS ventas_periodo,
        COUNT(DISTINCT CASE WHEN p.estado='confirmado' THEN o.id_orden END)                          AS pedidos_entregados,
        COUNT(DISTINCT CASE WHEN p.estado IN ('en_revision','registrado') THEN o.id_orden END)       AS pedidos_pendientes,
        COUNT(DISTINCT CASE WHEN o.estado='expirado' THEN o.id_orden END)                            AS pedidos_expirados,
        COALESCE(AVG(CASE WHEN p.estado='confirmado' THEN o.total END),0)                            AS ticket_promedio
    FROM ordenes o
    LEFT JOIN pagos p ON o.id_orden=p.id_orden
    WHERE DATE(o.fecha) BETWEEN ? AND ?
", "ss", [$fecha_desde, $fecha_hasta]);
$stats_periodo = $result_periodo ? $result_periodo->fetch_assoc() : [];

$result_productos_top = ejecutarConsulta($conn, "
    SELECT
        prod.nombre, prod.precio,
        COUNT(ped.id_pedido)  as total_ventas,
        SUM(ped.cantidad)     as unidades_vendidas,
        SUM(ped.total)        as ingresos_generados,
        u.nombre              as nombre_productor
    FROM pedidos ped
    INNER JOIN ordenes o  ON ped.id_orden    = o.id_orden
    INNER JOIN pagos p    ON o.id_orden      = p.id_orden AND p.estado='confirmado'
    INNER JOIN productos prod ON ped.id_producto = prod.id_producto
    INNER JOIN usuarios u ON prod.id_productor   = u.id_usuario
    WHERE DATE(o.fecha) BETWEEN ? AND ?
    GROUP BY prod.id_producto
    ORDER BY total_ventas DESC
    LIMIT 10
", "ss", [$fecha_desde, $fecha_hasta]);

$result_productores = ejecutarConsulta($conn, "
    SELECT
        u.nombre, u.email,
        COUNT(DISTINCT prod.id_producto) as productos_publicados,
        COUNT(DISTINCT ped.id_pedido)    as total_ventas,
        COALESCE(SUM(ped.total),0)       as ingresos_generados,
        COALESCE(AVG(cr.calificacion),0) as calificacion_promedio
    FROM usuarios u
    LEFT JOIN productos prod ON u.id_usuario = prod.id_productor
    LEFT JOIN (
        SELECT ped.id_pedido, ped.id_producto, ped.total
        FROM pedidos ped
        INNER JOIN ordenes o ON ped.id_orden = o.id_orden
        INNER JOIN pagos p   ON o.id_orden   = p.id_orden AND p.estado='confirmado'
        WHERE DATE(o.fecha) BETWEEN ? AND ?
    ) ped ON prod.id_producto = ped.id_producto
    LEFT JOIN calificaciones_resenas cr ON u.id_usuario=cr.id_productor AND cr.estado='activa'
    WHERE u.rol='productor' AND u.eliminado=0
    GROUP BY u.id_usuario
    ORDER BY total_ventas DESC
    LIMIT 10
", "ss", [$fecha_desde, $fecha_hasta]);

$result_compradores = ejecutarConsulta($conn, "
    SELECT
        u.nombre, u.email,
        COUNT(DISTINCT o.id_orden) as total_compras,
        COALESCE(SUM(o.total),0)   as total_gastado,
        COALESCE(AVG(o.total),0)   as ticket_promedio,
        MAX(o.fecha)               as ultima_compra
    FROM usuarios u
    INNER JOIN ordenes o ON u.id_usuario = o.id_comprador
    INNER JOIN pagos p   ON o.id_orden   = p.id_orden AND p.estado='confirmado'
    WHERE DATE(o.fecha) BETWEEN ? AND ? AND u.eliminado=0
    GROUP BY u.id_usuario
    ORDER BY total_compras DESC
    LIMIT 10
", "ss", [$fecha_desde, $fecha_hasta]);

$result_categorias = ejecutarConsulta($conn, "
    SELECT
        c.nombre_categoria, c.icono,
        COUNT(DISTINCT prod.id_producto) as total_productos,
        COUNT(DISTINCT ped.id_pedido)    as total_pedidos,
        COALESCE(SUM(ped.total),0)       as ingresos_generados
    FROM categorias_productos c
    LEFT JOIN productos prod ON c.id_categoria = prod.id_categoria
    LEFT JOIN (
        SELECT ped.id_pedido, ped.id_producto, ped.total
        FROM pedidos ped
        INNER JOIN ordenes o ON ped.id_orden = o.id_orden
        INNER JOIN pagos p   ON o.id_orden   = p.id_orden AND p.estado='confirmado'
        WHERE DATE(o.fecha) BETWEEN ? AND ?
    ) ped ON prod.id_producto = ped.id_producto
    WHERE c.activa=1
    GROUP BY c.id_categoria
    ORDER BY total_pedidos DESC
", "ss", [$fecha_desde, $fecha_hasta]);

$result_calificaciones = ejecutarConsulta($conn, "
    SELECT
        COUNT(*)                                                            as total_resenas,
        COUNT(CASE WHEN estado='pendiente' THEN 1 END)                     as pendientes,
        COUNT(CASE WHEN estado='activa'    THEN 1 END)                     as activas,
        ROUND(AVG(CASE WHEN estado='activa' THEN calificacion END),1)      as promedio_general,
        COUNT(CASE WHEN calificacion=5 AND estado='activa' THEN 1 END)     as cinco_estrellas
    FROM calificaciones_resenas
    WHERE DATE(fecha_calificacion) BETWEEN ? AND ?
", "ss", [$fecha_desde, $fecha_hasta]);
$stats_calificaciones = $result_calificaciones ? $result_calificaciones->fetch_assoc() : [];

ejecutarConsulta($conn,
    "INSERT INTO auditoria_admin (id_administrador,accion,ip_address,descripcion) VALUES (?,'acceso_reportes',?,'Consultó reportes estadísticos')",
    "is", [$usuario_id, $_SERVER['REMOTE_ADDR'] ?? 'unknown']
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reportes — QuiliAgro Connect</title>
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

.export-btn {
    padding: 8px 16px; height: 42px; background: var(--primary); color: white; border: none;
    border-radius: var(--radius-sm); cursor: pointer; font-size: 13px;
    display: flex; align-items: center; gap: 8px; transition: var(--transition);
}
.export-btn:hover { background: var(--primary-dark); transform: translateY(-2px); }

.mini-stats { display: grid; grid-template-columns: repeat(auto-fit,minmax(180px,1fr)); gap: 15px; margin-bottom: 20px; }
.mini-stat { background: linear-gradient(135deg,var(--bg-light) 0%,#fff 100%); padding: 20px; border-radius: var(--radius); border-left: 4px solid var(--primary); transition: var(--transition); }
.mini-stat:hover { transform: translateY(-3px); box-shadow: var(--shadow-sm); }
.mini-stat h4 { font-size: 13px; color: var(--text-muted); margin-bottom: 10px; text-transform: uppercase; letter-spacing: .5px; }
.mini-stat .value { font-size: 28px; font-weight: 700; color: var(--primary); }

.ranking-table { width: 100%; border-collapse: collapse; }
.ranking-table thead { background: var(--bg-light); }
.ranking-table th { padding: 12px 15px; text-align: left; font-weight: 600; color: var(--text-dark); font-size: 13px; text-transform: uppercase; letter-spacing: .5px; }
.ranking-table td { padding: 12px 15px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
.ranking-table tbody tr:hover { background: rgba(40,167,69,.05); }

.ranking-position { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; font-weight: 700; font-size: 14px; }
.ranking-position.gold   { background: linear-gradient(135deg,#FFD700,#FFA500); color: white; }
.ranking-position.silver { background: linear-gradient(135deg,#C0C0C0,#A9A9A9); color: white; }
.ranking-position.bronze { background: linear-gradient(135deg,#CD7F32,#8B4513); color: white; }
.ranking-position.normal { background: var(--bg-light); color: var(--text-dark); }

.chart-container { background: white; padding: 20px; border-radius: var(--radius); margin-top: 15px; }
.bar-chart { display: flex; flex-direction: column; gap: 15px; }
.bar-item  { display: flex; align-items: center; gap: 15px; }
.bar-label { min-width: 120px; font-size: 14px; font-weight: 600; color: var(--text-dark); }
.bar-wrapper { flex: 1; height: 30px; background: #e9ecef; border-radius: 15px; overflow: hidden; }
.bar-fill { height: 100%; background: linear-gradient(90deg,var(--primary),var(--primary-dark)); border-radius: 15px; transition: width .8s ease; display: flex; align-items: center; justify-content: flex-end; padding-right: 10px; color: white; font-size: 12px; font-weight: 600; }
.bar-value { min-width: 80px; text-align: right; font-size: 14px; font-weight: 700; color: var(--primary); }

.empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
.empty-state i { font-size: 48px; margin-bottom: 15px; display: block; opacity: .5; }

.filter-bar { background: white; padding: 20px; border-radius: var(--radius); box-shadow: var(--shadow-sm); margin-bottom: 25px; }
.filter-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); gap: 15px; align-items: end; }
.star-rating { color: #ffc107; font-size: 16px; }

@media (max-width:768px) {
    .mini-stats { grid-template-columns: 1fr; }
    .ranking-table th, .ranking-table td { padding: 8px 10px; }
    .bar-label { min-width: 80px; font-size: 12px; }
    .filter-grid { grid-template-columns: 1fr; }
}

@media (max-width: 600px) {
    .ranking-table thead { display: none; }

    .ranking-table tbody tr {
        display: block;
        border: 1px solid #e0e0e0;
        border-radius: var(--radius);
        margin-bottom: 12px;
        padding: 12px;
        background: white;
    }

    .ranking-table tbody td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 6px 4px;
        border-bottom: 1px solid #f5f5f5;
        font-size: 13px;
    }

    .ranking-table tbody td:last-child {
        border-bottom: none;
    }

    .ranking-table tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--text-muted);
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .5px;
        flex-shrink: 0;
        margin-right: 8px;
    }

    .ranking-table tbody td[data-label="Productor"],
    .ranking-table tbody td[data-label="Comprador"] {
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
    }

    .ranking-table tbody td[data-label="Productor"]::before,
    .ranking-table tbody td[data-label="Comprador"]::before {
        margin-bottom: 4px;
    }

    .table-responsive { overflow-x: unset; }
}
</style>
</head>
<body>

<button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
  <i class="bi bi-list"></i>
</button>

<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header"><img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo"></div>
    <nav class="sidebar-menu">
      <a href="panel.php"><i class="bi bi-speedometer2"></i> Inicio</a>
      <a href="usuarios.php"><i class="bi bi-people"></i> Usuarios</a>
      <a href="catalogo_admin.php"><i class="bi bi-tags"></i> Catálogo</a>
      <a href="pedidos_admin.php"><i class="bi bi-box-seam"></i> Pedidos</a>
      <a href="pagos_admin.php"><i class="bi bi-credit-card"></i> Pagos</a>
      <a href="calificaciones.php"><i class="bi bi-star"></i> Reseñas</a>
      <a href="reportes.php" class="active"><i class="bi bi-bar-chart"></i> Reportes</a>
      <a href="auditoria_admin.php"><i class="bi bi-shield-check"></i> Auditoría</a>
      <a href="ayudas_admin.php"><i class="bi bi-question-circle"></i> Ayuda</a>
    </nav>
  </aside>

  <main class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <h1>Reportes Estadísticos</h1>
        <p class="subtitle">Análisis y métricas del sistema</p>
      </div>
      <div class="topbar-right">
        <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
      </div>
    </header>

    <div class="content-area">

      <div class="filter-bar">
        <form method="GET" class="filter-grid" id="formFiltros">
          <div class="form-group" style="margin-bottom:0;">
            <label>Fecha Desde</label>
            <input type="date" name="fecha_desde" class="form-control" value="<?php echo $fecha_desde; ?>">
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label>Fecha Hasta</label>
            <input type="date" name="fecha_hasta" class="form-control" value="<?php echo $fecha_hasta; ?>">
          </div>
          <div>
            <button type="submit" class="btn btn-primary" style="width:100%;"><i class="bi bi-funnel"></i> Filtrar</button>
          </div>
          <div>
            <button type="button" class="export-btn" onclick="exportarReporte()" style="width:100%;justify-content:center;">
              <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
            </button>
          </div>
        </form>
      </div>

      <div class="stats-grid">
        <div class="stat-card primary">
          <i class="bi bi-people card-icon"></i><h3>Productores</h3>
          <div class="number"><?php echo number_format($stats_general['total_productores']); ?></div><p>Registrados</p>
        </div>
        <div class="stat-card primary">
          <i class="bi bi-cart-check card-icon"></i><h3>Compradores</h3>
          <div class="number"><?php echo number_format($stats_general['total_compradores']); ?></div><p>Registrados</p>
        </div>
        <div class="stat-card success">
          <i class="bi bi-box-seam card-icon"></i><h3>Productos</h3>
          <div class="number"><?php echo number_format($stats_general['total_productos']); ?></div><p>Publicados</p>
        </div>
        <div class="stat-card warning">
          <i class="bi bi-currency-dollar card-icon"></i><h3>Ventas Totales</h3>
          <div class="number">$<?php echo number_format($stats_general['ventas_completadas'],0); ?></div>
          <p>Pagos confirmados</p>
        </div>
      </div>

      <div class="report-section">
        <div class="report-header">
          <h3><i class="bi bi-calendar-range"></i> Métricas del Período Seleccionado</h3>
          <span style="color:var(--text-muted);font-size:14px;">
            <?php echo date('d/m/Y', strtotime($fecha_desde)); ?> – <?php echo date('d/m/Y', strtotime($fecha_hasta)); ?>
          </span>
        </div>
        <div class="mini-stats">
          <div class="mini-stat">
            <h4><i class="bi bi-cart"></i> Pedidos</h4>
            <div class="value"><?php echo number_format($stats_periodo['pedidos_periodo']); ?></div>
            <small style="color:#6c757d;font-size:11px;">Total compras realizadas</small>
          </div>
          <div class="mini-stat">
            <h4><i class="bi bi-currency-dollar"></i> Ventas Efectivas</h4>
            <div class="value">$<?php echo number_format($stats_periodo['ventas_periodo'],2); ?></div>
            <small style="color:#6c757d;font-size:11px;">Pagos confirmados</small>
          </div>
          <div class="mini-stat">
            <h4><i class="bi bi-check-circle"></i> Pagadas</h4>
            <div class="value"><?php echo number_format($stats_periodo['pedidos_entregados']); ?></div>
            <small style="color:#6c757d;font-size:11px;">Con pago confirmado</small>
          </div>
          <div class="mini-stat">
            <h4><i class="bi bi-clock-history"></i> Pendientes</h4>
            <div class="value" style="color:var(--warning);"><?php echo number_format($stats_periodo['pedidos_pendientes']); ?></div>
            <small style="color:#6c757d;font-size:11px;">Pagos en revisión</small>
          </div>
          <div class="mini-stat">
            <h4><i class="bi bi-receipt"></i> Ticket Promedio</h4>
            <div class="value">$<?php echo number_format($stats_periodo['ticket_promedio'],2); ?></div>
            <small style="color:#6c757d;font-size:11px;">Por compra pagada</small>
          </div>
          <div class="mini-stat">
            <h4><i class="bi bi-hourglass-split"></i> Expirados</h4>
            <div class="value" style="color:#6c757d;"><?php echo number_format($stats_periodo['pedidos_expirados']); ?></div>
            <small style="color:#6c757d;font-size:11px;">No pagadas a tiempo</small>
          </div>
        </div>
      </div>

      <div class="report-section">
        <div class="report-header"><h3><i class="bi bi-trophy"></i> Top 10 Productos Más Vendidos</h3></div>
        <?php if ($result_productos_top && $result_productos_top->num_rows > 0): ?>
        <div class="table-responsive">
          <table class="ranking-table">
            <thead><tr><th>#</th><th>Producto</th><th>Productor</th><th>Ventas</th><th>Unidades</th><th>Ingresos</th></tr></thead>
            <tbody>
              <?php $pos=1; while ($p = $result_productos_top->fetch_assoc()):
                $mc = $pos<=3 ? ($pos==1?'gold':($pos==2?'silver':'bronze')) : 'normal';
              ?>
              <tr>
                <td data-label="#"><span class="ranking-position <?php echo $mc; ?>"><?php echo $pos; ?></span></td>
                <td data-label="Producto"><strong><?php echo htmlspecialchars($p['nombre']); ?></strong></td>
                <td data-label="Productor"><?php echo htmlspecialchars($p['nombre_productor']); ?></td>
                <td data-label="Ventas"><?php echo number_format($p['total_ventas']); ?></td>
                <td data-label="Unidades"><?php echo number_format($p['unidades_vendidas']); ?></td>
                <td data-label="Ingresos"><strong style="color:var(--primary);">$<?php echo number_format($p['ingresos_generados'],2); ?></strong></td>
              </tr>
              <?php $pos++; endwhile; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-inbox"></i><p>No hay datos en el período seleccionado</p></div>
        <?php endif; ?>
      </div>

      <div class="report-section">
        <div class="report-header"><h3><i class="bi bi-shop"></i> Productores Más Activos</h3></div>
        <?php if ($result_productores && $result_productores->num_rows > 0): ?>
        <div class="table-responsive">
          <table class="ranking-table">
            <thead><tr><th>#</th><th>Productor</th><th>Productos</th><th>Ventas</th><th>Ingresos</th><th>Calificación</th></tr></thead>
            <tbody>
              <?php $pos=1; while ($pr = $result_productores->fetch_assoc()):
                $mc = $pos<=3 ? ($pos==1?'gold':($pos==2?'silver':'bronze')) : 'normal';
              ?>
              <tr>
                <td data-label="#"><span class="ranking-position <?php echo $mc; ?>"><?php echo $pos; ?></span></td>
                <td data-label="Productor">
                  <strong><?php echo htmlspecialchars($pr['nombre']); ?></strong>
                  <div style="font-size:12px;color:#999;"><?php echo htmlspecialchars($pr['email']); ?></div>
                </td>
                <td data-label="Productos"><?php echo number_format($pr['productos_publicados']); ?></td>
                <td data-label="Ventas"><?php echo number_format($pr['total_ventas']); ?></td>
                <td data-label="Ingresos"><strong style="color:var(--primary);">$<?php echo number_format($pr['ingresos_generados'],2); ?></strong></td>
                <td data-label="Calificación">
                  <div class="star-rating">
                    <?php $r = round($pr['calificacion_promedio']);
                    for ($i=1;$i<=5;$i++) echo $i<=$r?'★':'☆'; ?>
                    <span style="color:#666;font-size:12px;margin-left:5px;"><?php echo number_format($pr['calificacion_promedio'],1); ?></span>
                  </div>
                </td>
              </tr>
              <?php $pos++; endwhile; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-inbox"></i><p>No hay datos en el período seleccionado</p></div>
        <?php endif; ?>
      </div>

      <div class="report-section">
        <div class="report-header"><h3><i class="bi bi-people"></i> Compradores Más Activos</h3></div>
        <?php if ($result_compradores && $result_compradores->num_rows > 0): ?>
        <div class="table-responsive">
          <table class="ranking-table">
            <thead><tr><th>#</th><th>Comprador</th><th>Compras</th><th>Total Gastado</th><th>Ticket Promedio</th><th>Última Compra</th></tr></thead>
            <tbody>
              <?php $pos=1; while ($c = $result_compradores->fetch_assoc()):
                $mc = $pos<=3 ? ($pos==1?'gold':($pos==2?'silver':'bronze')) : 'normal';
              ?>
              <tr>
                <td data-label="#"><span class="ranking-position <?php echo $mc; ?>"><?php echo $pos; ?></span></td>
                <td data-label="Comprador">
                  <strong><?php echo htmlspecialchars($c['nombre']); ?></strong>
                  <div style="font-size:12px;color:#999;"><?php echo htmlspecialchars($c['email']); ?></div>
                </td>
                <td data-label="Compras"><?php echo number_format($c['total_compras']); ?></td>
                <td data-label="Total Gastado"><strong style="color:var(--primary);">$<?php echo number_format($c['total_gastado'],2); ?></strong></td>
                <td data-label="Ticket Promedio">$<?php echo number_format($c['ticket_promedio'],2); ?></td>
                <td data-label="Última Compra"><?php echo $c['ultima_compra'] ? date('d/m/Y', strtotime($c['ultima_compra'])) : 'N/A'; ?></td>
              </tr>
              <?php $pos++; endwhile; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-inbox"></i><p>No hay compradores con compras pagadas en el período</p></div>
        <?php endif; ?>
      </div>

      <div class="report-section">
        <div class="report-header"><h3><i class="bi bi-tags"></i> Categorías Más Populares</h3></div>
        <?php if ($result_categorias && $result_categorias->num_rows > 0):
          $cats = [];
          while ($cat = $result_categorias->fetch_assoc()) $cats[] = $cat;
          $max = max(array_column($cats, 'total_pedidos')) ?: 1;
        ?>
        <div class="chart-container">
          <div class="bar-chart">
            <?php foreach ($cats as $cat):
              $pct = ($cat['total_pedidos'] / $max) * 100;
            ?>
            <div class="bar-item">
              <div class="bar-label">
                <?php if ($cat['icono']): ?><i class="<?php echo htmlspecialchars($cat['icono']); ?>"></i><?php endif; ?>
                <?php echo htmlspecialchars($cat['nombre_categoria']); ?>
              </div>
              <div class="bar-wrapper">
                <div class="bar-fill" style="width:<?php echo $pct; ?>%;">
                  <?php if ($pct > 15 && $cat['total_pedidos'] > 0): ?>
                    <?php echo number_format($cat['total_pedidos']); ?> pedidos
                  <?php endif; ?>
                </div>
              </div>
              <div class="bar-value">$<?php echo number_format($cat['ingresos_generados'],0); ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-inbox"></i><p>No hay datos de categorías en el período seleccionado</p></div>
        <?php endif; ?>
      </div>

      <div class="report-section">
        <div class="report-header"><h3><i class="bi bi-star"></i> Calificaciones y Reseñas</h3></div>
        <div class="mini-stats">
          <div class="mini-stat">
            <h4><i class="bi bi-chat-left-text"></i> Total Reseñas</h4>
            <div class="value"><?php echo number_format($stats_calificaciones['total_resenas'] ?? 0); ?></div>
          </div>
          <div class="mini-stat">
            <h4><i class="bi bi-check-circle"></i> Activas</h4>
            <div class="value"><?php echo number_format($stats_calificaciones['activas'] ?? 0); ?></div>
          </div>
          <div class="mini-stat">
            <h4><i class="bi bi-clock-history"></i> Pendientes</h4>
            <div class="value"><?php echo number_format($stats_calificaciones['pendientes'] ?? 0); ?></div>
          </div>
          <div class="mini-stat">
            <h4><i class="bi bi-star-fill"></i> Promedio</h4>
            <div class="value" style="display:flex;align-items:center;gap:8px;">
              <span class="star-rating" style="font-size:24px;">★</span>
              <?php echo number_format($stats_calificaciones['promedio_general'] ?? 0, 1); ?>
            </div>
          </div>
          <div class="mini-stat">
            <h4><i class="bi bi-emoji-smile"></i> 5 Estrellas</h4>
            <div class="value"><?php echo number_format($stats_calificaciones['cinco_estrellas'] ?? 0); ?></div>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<script>
document.addEventListener('click', e => {
  const sb = document.getElementById('sidebar');
  const mt = document.querySelector('.menu-toggle');
  if (window.innerWidth<=768 && sb && !sb.contains(e.target) && mt && !mt.contains(e.target) && sb.classList.contains('open'))
    sb.classList.remove('open');
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

function exportarReporte() {
  const desde = document.querySelector('input[name="fecha_desde"]').value;
  const hasta  = document.querySelector('input[name="fecha_hasta"]').value;
  window.open(`reportes_pdf.php?fecha_desde=${desde}&fecha_hasta=${hasta}`, '_blank');
}

document.addEventListener('DOMContentLoaded', () => {
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