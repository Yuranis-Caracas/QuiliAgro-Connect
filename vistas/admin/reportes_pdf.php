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

function imageToBase64($path) {
    $realPath = realpath($path);
    if ($realPath && file_exists($realPath)) {
        $imageData = base64_encode(file_get_contents($realPath));
        $type = pathinfo($realPath, PATHINFO_EXTENSION);
        return 'data:image/' . $type . ';base64,' . $imageData;
    }
    return '';
}
$logo_base64 = imageToBase64(__DIR__ . '/../../imagenes/logo.png');

function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception("Error en prepare: " . $conn->error);
            $stmt->bind_param($tipos, ...$params);
            $stmt->execute();
            return stripos(trim($query), 'select') === 0 ? $stmt->get_result() : true;
        }
        $result = $conn->query($query);
        if (!$result) throw new Exception("Error en query: " . $conn->error);
        return $result;
    } catch (Exception $e) {
        error_log('Error BD: ' . $e->getMessage());
        return false;
    }
}

$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

$result_general = ejecutarConsulta($conn, "
    SELECT
        (SELECT COUNT(*) FROM usuarios WHERE rol='productor' AND eliminado=0) as total_productores,
        (SELECT COUNT(*) FROM usuarios WHERE rol='comprador' AND eliminado=0) as total_compradores,
        (SELECT COUNT(*) FROM productos)                                       as total_productos,
        (SELECT COUNT(*) FROM categorias_productos WHERE activa=1)             as total_categorias,
        (SELECT COUNT(*) FROM ordenes)                                         as total_pedidos,
        (SELECT COALESCE(SUM(o.total),0)
         FROM ordenes o INNER JOIN pagos pag ON o.id_orden=pag.id_orden
         WHERE pag.estado='confirmado')                                        as ventas_completadas,
        (SELECT COUNT(*) FROM calificaciones_resenas WHERE estado='activa')    as total_resenas
");
$stats_general = ($result_general && $result_general !== true) ? $result_general->fetch_assoc() : [];
$stats_general = array_merge([
    'total_productores'=>0,'total_compradores'=>0,'total_productos'=>0,
    'total_categorias'=>0,'total_pedidos'=>0,'ventas_completadas'=>0,'total_resenas'=>0
], $stats_general ?: []);

$result_periodo = ejecutarConsulta($conn, "
    SELECT
        COUNT(DISTINCT o.id_orden)                                                                          AS ordenes_periodo,
        COALESCE(SUM(CASE WHEN pag.estado='confirmado' THEN o.total ELSE 0 END),0)                         AS ventas_periodo,
        COUNT(DISTINCT CASE WHEN pag.estado='confirmado' AND o.estado='pagado' THEN o.id_orden END)         AS ordenes_entregadas,
        COUNT(DISTINCT CASE WHEN pag.estado IN ('en_revision','registrado') THEN o.id_orden END)            AS ordenes_pendientes,
        COUNT(DISTINCT CASE WHEN o.estado='expirado' THEN o.id_orden END)                                   AS ordenes_expiradas,
        COALESCE(AVG(CASE WHEN pag.estado='confirmado' THEN o.total END),0)                                 AS ticket_promedio
    FROM ordenes o
    LEFT JOIN pagos pag ON o.id_orden=pag.id_orden
    WHERE DATE(o.fecha) BETWEEN ? AND ?
", "ss", [$fecha_desde, $fecha_hasta]);
$stats_periodo = ($result_periodo && $result_periodo !== true) ? $result_periodo->fetch_assoc() : [];
$stats_periodo = array_merge([
    'ordenes_periodo'=>0,'ventas_periodo'=>0,'ordenes_entregadas'=>0,
    'ordenes_pendientes'=>0,'ordenes_expiradas'=>0,'ticket_promedio'=>0
], $stats_periodo ?: []);

$result_productos_top = ejecutarConsulta($conn, "
    SELECT
        p.nombre, p.precio,
        COUNT(DISTINCT ped.id_pedido) as total_ventas,
        SUM(ped.cantidad)             as unidades_vendidas,
        SUM(ped.total)                as ingresos_generados,
        u.nombre                      as nombre_productor
    FROM pedidos ped
    INNER JOIN productos p   ON ped.id_producto = p.id_producto
    INNER JOIN usuarios u    ON p.id_productor  = u.id_usuario
    INNER JOIN ordenes o     ON ped.id_orden     = o.id_orden
    INNER JOIN pagos pag     ON o.id_orden       = pag.id_orden
    WHERE pag.estado='confirmado' AND DATE(ped.fecha_pedido) BETWEEN ? AND ?
    GROUP BY p.id_producto
    ORDER BY total_ventas DESC
    LIMIT 10
", "ss", [$fecha_desde, $fecha_hasta]);
if ($result_productos_top === false) $result_productos_top = null;

$result_productores = ejecutarConsulta($conn, "
    SELECT
        u.nombre, u.email,
        COUNT(DISTINCT p.id_producto)   as productos_publicados,
        COUNT(DISTINCT ped.id_pedido)   as total_ventas,
        COALESCE(SUM(ped.total),0)      as ingresos_generados,
        COALESCE(AVG(cr.calificacion),0) as calificacion_promedio
    FROM usuarios u
    LEFT JOIN productos p ON u.id_usuario = p.id_productor
    LEFT JOIN pedidos ped ON p.id_producto = ped.id_producto
        AND DATE(ped.fecha_pedido) BETWEEN ? AND ?
        AND ped.id_orden IN (SELECT id_orden FROM pagos WHERE estado='confirmado')
    LEFT JOIN calificaciones_resenas cr ON u.id_usuario=cr.id_productor AND cr.estado='activa'
    WHERE u.rol='productor' AND u.eliminado=0
    GROUP BY u.id_usuario
    ORDER BY total_ventas DESC
    LIMIT 10
", "ss", [$fecha_desde, $fecha_hasta]);
if ($result_productores === false) $result_productores = null;

$result_categorias = ejecutarConsulta($conn, "
    SELECT
        c.nombre_categoria,
        COUNT(DISTINCT p.id_producto)  as total_productos,
        COUNT(DISTINCT ped.id_pedido)  as total_pedidos,
        COALESCE(SUM(ped.total),0)     as ingresos_generados
    FROM categorias_productos c
    LEFT JOIN productos p ON c.id_categoria=p.id_categoria
    LEFT JOIN pedidos ped ON p.id_producto=ped.id_producto
        AND DATE(ped.fecha_pedido) BETWEEN ? AND ?
        AND ped.id_orden IN (SELECT id_orden FROM pagos WHERE estado='confirmado')
    WHERE c.activa=1
    GROUP BY c.id_categoria
    ORDER BY total_pedidos DESC
    LIMIT 5
", "ss", [$fecha_desde, $fecha_hasta]);
if ($result_categorias === false) $result_categorias = null;

ejecutarConsulta($conn,
    "INSERT INTO auditoria_admin (id_administrador,accion,ip_address,descripcion) VALUES (?,'exportar_reporte_pdf',?,'Exportó reporte estadístico a PDF')",
    "is", [$usuario_id, $_SERVER['REMOTE_ADDR'] ?? 'unknown']
);


require_once realpath(__DIR__ . '/../../dompdf/autoload.inc.php');
use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Reportes Estadísticos - QuiliAgro Connect</title>
<style>
@page { margin: 40px 25px 70px 25px; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; padding: 0; margin: 0; color: #2c3e50; }

.header { text-align: center; margin-bottom: 30px; padding-bottom: 15px; border-bottom: 3px solid #28a745; }
.header h1 { color: #28a745; font-size: 24px; margin: 10px 0 5px 0; }
.header .subtitle { color: #6c757d; font-size: 12px; margin: 5px 0; }

.periodo-info { background: #f0f8f4; padding: 12px; border-left: 4px solid #28a745; margin-bottom: 20px; font-size: 11px; }
.periodo-info strong { color: #28a745; }

.seccion-titulo { background: linear-gradient(135deg,#28a745 0%,#218838 100%); color: white; padding: 10px 15px; font-size: 14px; font-weight: bold; margin: 25px 0 15px 0; border-radius: 5px; }

.stats-grid { display: table; width: 100%; margin-bottom: 20px; }
.stat-row   { display: table-row; }
.stat-cell  { display: table-cell; width: 25%; padding: 5px; }
.stat-card  { background: #fff; border: 2px solid #e0e0e0; border-top: 4px solid #28a745; padding: 15px; text-align: center; border-radius: 8px; }
.stat-card h3 { font-size: 10px; color: #6c757d; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: .5px; }
.stat-card .number { font-size: 24px; font-weight: bold; color: #28a745; margin: 5px 0; }
.stat-card p { font-size: 9px; color: #6c757d; margin: 5px 0 0 0; }

table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 10px; }
th, td { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
th { background: #f0f8f4; font-weight: bold; color: #28a745; text-transform: uppercase; font-size: 9px; letter-spacing: .5px; }
tbody tr:nth-child(even) { background: #f8f9fa; }

.ranking-badge { display: inline-block; width: 25px; height: 25px; border-radius: 50%; text-align: center; line-height: 25px; font-weight: bold; color: white; font-size: 11px; }
.ranking-badge.gold   { background: #FFD700; }
.ranking-badge.silver { background: #C0C0C0; }
.ranking-badge.bronze { background: #CD7F32; }
.ranking-badge.normal { background: #e0e0e0; color: #2c3e50; }

.mini-stats-row  { display: table; width: 100%; margin-bottom: 15px; }
.mini-stat-cell  { display: table-cell; padding: 5px; }
.mini-stat-box   { background: #f8f9fa; border-left: 3px solid #28a745; padding: 10px; text-align: center; }
.mini-stat-box h4 { font-size: 9px; color: #6c757d; margin: 0 0 5px 0; text-transform: uppercase; }
.mini-stat-box .value { font-size: 18px; font-weight: bold; color: #28a745; }
.mini-stat-box.warning { border-left-color: #ffc107; }
.mini-stat-box.warning .value { color: #e65100; }

.empty-message { text-align: center; padding: 30px; color: #6c757d; font-style: italic; background: #f8f9fa; border-radius: 5px; }
.footer { position: fixed; bottom: -50px; left: 0; width: 100%; text-align: center; font-size: 9px; color: #6c757d; border-top: 1px solid #dee2e6; padding-top: 10px; }
.text-center { text-align: center; }
.text-right  { text-align: right; }
.text-success { color: #28a745; font-weight: bold; }
</style>
</head>
<body>

<div class="header">
  <?php if ($logo_base64): ?>
  <img src="<?= $logo_base64 ?>" style="width:90px;margin-bottom:15px;">
  <?php endif; ?>
  <h1>REPORTES ESTADÍSTICOS</h1>
  <div class="subtitle">QuiliAgro Connect - Sistema de Gestión Agropecuaria</div>
  <div class="subtitle">Generado el <?= date('d/m/Y') ?></div>
</div>

<div class="periodo-info">
  <strong>Período analizado:</strong> <?= date('d/m/Y', strtotime($fecha_desde)) ?> – <?= date('d/m/Y', strtotime($fecha_hasta)) ?><br>
  <strong>Generado por:</strong> <?= htmlspecialchars($usuario_nombre) ?><br>
</div>

<div class="seccion-titulo">RESUMEN GENERAL DEL SISTEMA</div>
<div class="stats-grid">
  <div class="stat-row">
    <div class="stat-cell"><div class="stat-card"><h3>Productores</h3><div class="number"><?= number_format($stats_general['total_productores']) ?></div><p>Registrados</p></div></div>
    <div class="stat-cell"><div class="stat-card"><h3>Compradores</h3><div class="number"><?= number_format($stats_general['total_compradores']) ?></div><p>Registrados</p></div></div>
    <div class="stat-cell"><div class="stat-card"><h3>Productos</h3><div class="number"><?= number_format($stats_general['total_productos']) ?></div><p>Publicados</p></div></div>
    <div class="stat-cell"><div class="stat-card"><h3>Órdenes</h3><div class="number"><?= number_format($stats_general['total_pedidos']) ?></div><p>Histórico</p></div></div>
  </div>
</div>

<div class="seccion-titulo">MÉTRICAS DEL PERÍODO SELECCIONADO</div>
<div class="mini-stats-row">
  <div class="mini-stat-cell" style="width:20%;"><div class="mini-stat-box"><h4>Ventas</h4><div class="value">$<?= number_format($stats_periodo['ventas_periodo'],2) ?></div></div></div>
  <div class="mini-stat-cell" style="width:20%;"><div class="mini-stat-box"><h4>Entregados</h4><div class="value"><?= number_format($stats_periodo['ordenes_entregadas']) ?></div></div></div>
  <div class="mini-stat-cell" style="width:20%;"><div class="mini-stat-box warning"><h4>Pendientes pago</h4><div class="value"><?= number_format($stats_periodo['ordenes_pendientes']) ?></div></div></div>
  <div class="mini-stat-cell" style="width:20%;"><div class="mini-stat-box"><h4>Ticket Promedio</h4><div class="value">$<?= number_format($stats_periodo['ticket_promedio'],2) ?></div></div></div>
  <div class="mini-stat-cell" style="width:20%;"><div class="mini-stat-box warning"><h4>Expirados</h4><div class="value"><?= number_format($stats_periodo['ordenes_expiradas']) ?></div></div></div>
</div>

<div class="seccion-titulo">TOP 10 PRODUCTOS MÁS VENDIDOS (Pagos confirmados)</div>
<?php if ($result_productos_top && $result_productos_top->num_rows > 0): ?>
<table>
  <thead><tr><th style="width:8%;">#</th><th style="width:30%;">Producto</th><th style="width:22%;">Productor</th><th style="width:10%;">Ventas</th><th style="width:12%;">Unidades</th><th style="width:18%;">Ingresos</th></tr></thead>
  <tbody>
    <?php $pos=1; while ($p = $result_productos_top->fetch_assoc()):
      $mc = $pos<=3 ? ($pos==1?'gold':($pos==2?'silver':'bronze')) : 'normal';
    ?>
    <tr>
      <td class="text-center"><span class="ranking-badge <?= $mc ?>"><?= $pos ?></span></td>
      <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
      <td><?= htmlspecialchars($p['nombre_productor']) ?></td>
      <td class="text-center"><?= number_format($p['total_ventas']) ?></td>
      <td class="text-center"><?= number_format($p['unidades_vendidas']) ?></td>
      <td class="text-right text-success">$<?= number_format($p['ingresos_generados'],2) ?></td>
    </tr>
    <?php $pos++; endwhile; ?>
  </tbody>
</table>
<?php else: ?>
<div class="empty-message">No hay datos de productos con pagos confirmados en el período seleccionado</div>
<?php endif; ?>

<div class="seccion-titulo">TOP 10 PRODUCTORES MÁS ACTIVOS (Pagos confirmados)</div>
<?php if ($result_productores && $result_productores->num_rows > 0): ?>
<table>
  <thead><tr><th style="width:8%;">#</th><th style="width:30%;">Productor</th><th style="width:14%;">Productos</th><th style="width:12%;">Ventas</th><th style="width:20%;">Ingresos</th><th style="width:16%;">Calificación</th></tr></thead>
  <tbody>
    <?php $pos=1; while ($pr = $result_productores->fetch_assoc()):
      $mc = $pos<=3 ? ($pos==1?'gold':($pos==2?'silver':'bronze')) : 'normal';
      $rating = round($pr['calificacion_promedio']);
      $estrellas = str_repeat('★',$rating).str_repeat('☆',5-$rating);
    ?>
    <tr>
      <td class="text-center"><span class="ranking-badge <?= $mc ?>"><?= $pos ?></span></td>
      <td><strong><?= htmlspecialchars($pr['nombre']) ?></strong><br><span style="font-size:9px;color:#6c757d;"><?= htmlspecialchars($pr['email']) ?></span></td>
      <td class="text-center"><?= number_format($pr['productos_publicados']) ?></td>
      <td class="text-center"><?= number_format($pr['total_ventas']) ?></td>
      <td class="text-right text-success">$<?= number_format($pr['ingresos_generados'],2) ?></td>
      <td class="text-center"><span style="color:#ffc107;"><?= $estrellas ?></span><br><span style="font-size:9px;"><?= number_format($pr['calificacion_promedio'],1) ?></span></td>
    </tr>
    <?php $pos++; endwhile; ?>
  </tbody>
</table>
<?php else: ?>
<div class="empty-message">No hay datos de productores con ventas confirmadas en el período seleccionado</div>
<?php endif; ?>

<div class="seccion-titulo">CATEGORÍAS MÁS POPULARES (Pagos confirmados)</div>
<?php if ($result_categorias && $result_categorias->num_rows > 0): ?>
<table>
  <thead><tr><th style="width:40%;">Categoría</th><th style="width:20%;">Productos</th><th style="width:20%;">Pedidos</th><th style="width:20%;">Ingresos</th></tr></thead>
  <tbody>
    <?php while ($cat = $result_categorias->fetch_assoc()): ?>
    <tr>
      <td><strong><?= htmlspecialchars($cat['nombre_categoria']) ?></strong></td>
      <td class="text-center"><?= number_format($cat['total_productos']) ?></td>
      <td class="text-center"><?= number_format($cat['total_pedidos']) ?></td>
      <td class="text-right text-success">$<?= number_format($cat['ingresos_generados'],2) ?></td>
    </tr>
    <?php endwhile; ?>
  </tbody>
</table>
<?php else: ?>
<div class="empty-message">No hay datos de categorías con pedidos confirmados</div>
<?php endif; ?>

<div class="footer">
  <div>Documento generado automáticamente por el sistema de QuiliAgro Connect</div>
  <div class="page-number" style="font-size:9px;color:#6c757d;"></div>
</div>
</body>
</html>
<?php
$html = ob_get_clean();
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('Reporte_Estadistico_QuiliAgro_'.date('Y-m-d_His').'.pdf', ['Attachment' => 1]);