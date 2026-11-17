<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'comprador');
require_once __DIR__ . '/../../config/auth_check.php';
date_default_timezone_set('America/Bogota');


if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'comprador') {
    header("Location: ../auth/login.php"); exit();
}

$usuario_nombre = htmlspecialchars($_SESSION['user_nombre']);
$usuario_id     = intval($_SESSION['user_id']);

$conn->query("UPDATE ordenes SET estado = 'expirado'
              WHERE id_comprador = $usuario_id
                AND estado = 'pendiente'
                AND fecha_expiracion <= NOW()");

$conn->query("UPDATE ordenes o
              INNER JOIN pagos p ON o.id_orden = p.id_orden
              INNER JOIN metodos_pago m ON p.id_metodo_pago = m.id_metodo
              SET o.estado = 'expirado'
              WHERE o.id_comprador = $usuario_id
                AND o.estado = 'pendiente'
                AND p.estado = 'registrado'
                AND m.tipo IN ('billetera','pse')
                AND p.fecha_expiracion IS NOT NULL
                AND p.fecha_expiracion <= NOW()");

function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param($tipos, ...$params);
            $stmt->execute();
            return $stmt->get_result();
        }
        $result = $conn->query($query);
        if (!$result) throw new Exception($conn->error);
        return $result;
    } catch (Exception $e) {
        error_log('Error BD: '.$e->getMessage()); return false;
    }
}

$mensaje = ''; $tipo_mensaje = '';
if (isset($_SESSION['mensaje_temporal'])) {
    $mensaje      = $_SESSION['mensaje_temporal'];
    $tipo_mensaje = $_SESSION['tipo_mensaje_temporal'];
    unset($_SESSION['mensaje_temporal'], $_SESSION['tipo_mensaje_temporal']);
}

$result_stats = ejecutarConsulta($conn, "
    SELECT
        COUNT(DISTINCT ord.id_orden) AS total_ordenes,
        COUNT(DISTINCT CASE
            WHEN pag.estado = 'registrado' AND ord.estado = 'pendiente'
            THEN ord.id_orden END
        ) AS pendientes,
        COUNT(DISTINCT CASE
            WHEN pag.estado = 'confirmado'
            THEN ord.id_orden END
        ) AS confirmadas,
        COUNT(DISTINCT CASE
            WHEN pag.estado = 'confirmado'
             AND NOT EXISTS (
                 SELECT 1 FROM pedidos p2
                 WHERE p2.id_orden = ord.id_orden
                   AND p2.estado != 'entregado'
             )
            THEN ord.id_orden END
        ) AS completadas,
        COUNT(DISTINCT CASE
            WHEN ord.estado = 'expirado'
            THEN ord.id_orden END
        ) AS expiradas,
        COALESCE(SUM(DISTINCT CASE WHEN pag.estado = 'confirmado' THEN ord.total ELSE 0 END), 0) AS total_comprado
    FROM ordenes ord
    INNER JOIN pagos pag ON ord.id_orden = pag.id_orden
    WHERE ord.id_comprador = ?
", "i", [$usuario_id]);

$stats = $result_stats ? $result_stats->fetch_assoc() : [
    'total_ordenes' => 0, 'pendientes' => 0, 'confirmadas' => 0,
    'completadas' => 0, 'expiradas' => 0, 'total_comprado' => 0
];

$busqueda = $_GET['busqueda'] ?? '';
$limite   = isset($_GET['todos']) ? 9999 : 10;

$query_ordenes = "
    SELECT
        ord.id_orden,
        ROW_NUMBER() OVER (PARTITION BY ord.id_comprador ORDER BY ord.fecha) AS numero_orden,
        ord.total        AS total_orden,
        ord.estado       AS estado_orden,
        ord.fecha,
        ord.fecha_expiracion,
        pag.id_pago,
        pag.estado       AS estado_pago,
        pag.valor        AS valor_pago,
        pag.referencia   AS referencia_pago,
        pag.fecha_pago,
        pag.fecha_confirmacion,
        met.nombre_metodo,
        met.icono        AS icono_metodo,
        met.tipo         AS tipo_metodo,
        COUNT(DISTINCT ped.id_pedido)    AS total_productos,
        COUNT(DISTINCT ped.id_productor) AS total_productores,
        SUM(CASE WHEN ped.estado = 'entregado'  THEN 1 ELSE 0 END) AS pedidos_entregados,
        SUM(CASE WHEN ped.estado = 'confirmado' THEN 1 ELSE 0 END) AS pedidos_confirmados,
        COUNT(ped.id_pedido) AS pedidos_total
    FROM ordenes ord
    INNER JOIN pagos pag       ON ord.id_orden       = pag.id_orden
    LEFT  JOIN metodos_pago met ON pag.id_metodo_pago = met.id_metodo
    INNER JOIN pedidos ped      ON ord.id_orden       = ped.id_orden
    WHERE ord.id_comprador = ?
";
$params = [$usuario_id]; $tipos = "i";
if (!empty($busqueda)) {
    $query_ordenes .= " AND (ord.id_orden LIKE ? OR ped.id_pedido LIKE ?)";
    $b = "%$busqueda%"; $params[] = $b; $params[] = $b; $tipos .= "ss";
}
$query_ordenes .= " GROUP BY ord.id_orden ORDER BY ord.fecha DESC LIMIT ?";
$params[] = $limite; $tipos .= "i";
$result_ordenes = ejecutarConsulta($conn, $query_ordenes, $tipos, $params);

$result_count = ejecutarConsulta($conn,
    "SELECT COUNT(DISTINCT ord.id_orden) AS total
     FROM ordenes ord
     INNER JOIN pagos pag ON ord.id_orden = pag.id_orden
     WHERE ord.id_comprador = ?", "i", [$usuario_id]);
$total_ordenes = $result_count ? $result_count->fetch_assoc()['total'] : 0;

function badgeOrden($estado_pago, $estado_orden, $ped_entregados, $ped_total) {
    if ($estado_orden === 'expirado')                                return ['secondary', '⌛ Expirado'];
    if ($ped_total > 0 && $ped_entregados == $ped_total)            return ['success',   '🚚 Todo Entregado'];
    if ($ped_entregados > 0)                                        return ['info',      '📦 Entrega Parcial'];
    if ($estado_pago === 'confirmado')                              return ['primary',   '✅ Pago Confirmado'];
    if ($estado_pago === 'registrado')                              return ['warning',   '⏳ En Revisión'];
    return ['secondary', ucfirst($estado_pago)];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Mis Pedidos - QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .pedido-card {
            background: white; border-radius: var(--radius-lg);
            padding: 25px; margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
            border-left: 5px solid var(--primary);
            transition: var(--transition);
        }
        .pedido-card:hover          { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .pedido-card.expirado       { border-left-color: var(--secondary); opacity: .88; }
        .pedido-card.todo-entregado { border-left-color: var(--success); }
        .pedido-card.parcial        { border-left-color: #17a2b8; }

        .pedido-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 20px; flex-wrap: wrap; gap: 15px;
            padding-bottom: 20px; border-bottom: 2px solid #f0f0f0;
        }
        .pedido-info h3 { font-size: 22px; color: var(--text-dark); margin-bottom: 12px; font-weight: 700; }
        .pedido-total   { font-size: 32px; font-weight: 800; color: var(--primary); margin: 10px 0; }

        .progreso-entrega {
            margin: 10px 0; font-size: 13px;
            color: var(--text-muted); display: flex; align-items: center; gap: 8px;
        }
        .progreso-barra { flex: 1; height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden; }
        .progreso-fill  {
            height: 100%; border-radius: 4px;
            background: linear-gradient(90deg, var(--success), #20c997);
            transition: width .5s ease;
        }

        .productos-lista { margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: var(--radius); }
        .producto-item {
            display: flex; gap: 15px; padding: 15px; margin-bottom: 10px;
            background: white; border-radius: var(--radius-sm); border-left: 3px solid var(--primary);
        }
        .producto-item.entregado  { border-left-color: var(--success); }
        .producto-item:last-child { margin-bottom: 0; }
        .producto-img             { width: 80px; height: 80px; object-fit: cover; border-radius: var(--radius-sm); }
        .producto-detalles        { flex: 1; }
        .producto-detalles h4     { font-size: 16px; margin-bottom: 5px; color: var(--text-dark); }
        .producto-detalles p      { font-size: 13px; color: var(--text-muted); margin: 3px 0; }

        .pago-section {
            margin-top: 20px; padding: 20px;
            background: #f8f9fa; border: 2px solid #e9ecef; border-radius: var(--radius);
        }
        .pago-section.registrado { border-color: var(--warning); background: linear-gradient(135deg,#fff3cd,#ffe69c); }
        .pago-section.confirmado { border-color: var(--success); background: #d4edda; }

        .alerta-vencido {
            background: #f8d7da; border: 1.5px solid rgba(198,40,40,.3);
            border-radius: var(--radius-sm); padding: 12px 16px;
            display: flex; align-items: center; gap: 10px;
            margin-top: 10px; font-size: 13px; color: #721c24;
        }

        .expirado-aviso {
            background: #f8f9fa; border: 2px dashed #adb5bd;
            border-radius: var(--radius); padding: 15px 20px;
            margin-top: 15px; text-align: center; color: var(--text-muted);
        }
        .expirado-aviso i  { font-size: 28px; display: block; margin-bottom: 8px; }
        .expirado-aviso p  { margin: 5px 0; font-size: 14px; }

        .acciones-calificacion {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleMenu()"><i class="bi bi-list"></i></button>

<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header"><img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo"></div>
        <nav class="sidebar-menu">
            <a href="panel_comprador.php"><i class="bi bi-speedometer2"></i> Inicio</a>
            <a href="catalogo_comprador.php"><i class="bi bi-shop"></i> Catálogo</a>
            <a href="pedidos_comprador.php" class="active"><i class="bi bi-clipboard-check"></i> Mis Pedidos</a>
            <a href="productores_comprador.php"><i class="bi bi-people"></i> Productores</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Mis Pedidos</h1>
                <p class="subtitle">Seguimiento y gestión de tus compras</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
            </div>
        </header>

        <div class="content-area">
            <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                <i class="bi bi-<?php echo $tipo_mensaje==='success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill"></i>
                <?php echo $mensaje; ?>
            </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <i class="bi bi-bag card-icon"></i>
                    <h3>Total Pedidos</h3>
                    <div class="number"><?php echo $stats['total_ordenes']; ?></div>
                </div>
                <div class="stat-card warning">
                    <i class="bi bi-clock-history card-icon"></i>
                    <h3>Pendientes</h3>
                    <div class="number"><?php echo $stats['pendientes']; ?></div>
                    <p>Esperando pago</p>
                </div>
                <div class="stat-card primary">
                    <i class="bi bi-check-circle card-icon"></i>
                    <h3>Confirmados</h3>
                    <div class="number"><?php echo $stats['confirmadas']; ?></div>
                    <p>Pago recibido</p>
                </div>
                <div class="stat-card success">
                    <i class="bi bi-truck card-icon"></i>
                    <h3>Completados</h3>
                    <div class="number"><?php echo $stats['completadas']; ?></div>
                    <p>Todo entregado</p>
                </div>
                <div class="stat-card secondary">
                    <i class="bi bi-hourglass-split card-icon"></i>
                    <h3>Expiradas</h3>
                    <div class="number"><?php echo $stats['expiradas']; ?></div>
                    <p>Volver a comprar</p>
                </div>
                <div class="stat-card primary">
                    <i class="bi bi-cash-stack card-icon"></i>
                    <h3>Total Comprado</h3>
                    <div class="number">$<?php echo number_format($stats['total_comprado'], 0); ?></div>
                </div>
            </div>

            <div style="margin-top:30px;">
                <h2 style="margin-bottom:20px;color:var(--primary);">
                    <i class="bi bi-clipboard-check"></i> Historial de Pedidos
                </h2>

                <?php if ($result_ordenes && $result_ordenes->num_rows > 0): ?>
                    <?php while ($orden = $result_ordenes->fetch_assoc()):
                        $id_orden        = $orden['id_orden'];
                        $estado_pago     = $orden['estado_pago'];
                        $estado_orden    = $orden['estado_orden'];
                        $vence_ts        = $orden['fecha_expiracion'] ? strtotime($orden['fecha_expiracion']) : 0;
                        $vencido         = $vence_ts && $vence_ts < time();
                        $es_efectivo     = ($orden['tipo_metodo'] === 'efectivo');
                        $pago_pendiente  = ($estado_pago === 'registrado');
                        $es_expirado     = ($estado_orden === 'expirado');
                        $ya_reintento = false;
                        if ($es_expirado) {
                            $r_posterior = ejecutarConsulta($conn,
                                "SELECT id_orden FROM ordenes
                                WHERE id_comprador = ? AND estado != 'expirado'
                                AND fecha > ?
                                LIMIT 1",
                                "is", [$usuario_id, $orden['fecha']]);
                            $ya_reintento = ($r_posterior && $r_posterior->num_rows > 0);
                        }
                        $ped_entregados  = (int)$orden['pedidos_entregados'];
                        $ped_total       = (int)$orden['pedidos_total'];
                        $todo_entregado  = $ped_total > 0 && $ped_entregados === $ped_total;
                        $entrega_parcial = $ped_entregados > 0 && !$todo_entregado;
                        $pct_entrega     = $ped_total > 0 ? round(($ped_entregados / $ped_total) * 100) : 0;

                        [$badge_clase, $badge_texto] = badgeOrden($estado_pago, $estado_orden, $ped_entregados, $ped_total);

                        $clase_card = 'pedido-card';
                        if ($es_expirado)         $clase_card .= ' expirado';
                        elseif ($todo_entregado)  $clase_card .= ' todo-entregado';
                        elseif ($entrega_parcial) $clase_card .= ' parcial';

                        $result_productos = ejecutarConsulta($conn, "
                            SELECT ped.*,
                                   prod.nombre       AS nombre_producto,
                                    COALESCE((SELECT fp.ruta_foto FROM fotos_productos fp 
                                        WHERE fp.id_producto = prod.id_producto 
                                        ORDER BY fp.orden ASC LIMIT 1),
                                        prod.imagen
                                    ) AS imagen_producto,
                                   prod.unidad_medida,
                                   u.nombre          AS nombre_productor,
                                   u.telefono        AS telefono_productor
                            FROM pedidos ped
                            INNER JOIN productos prod ON ped.id_producto  = prod.id_producto
                            INNER JOIN usuarios u      ON ped.id_productor = u.id_usuario
                            WHERE ped.id_orden = ?
                            ORDER BY ped.id_pedido", "i", [$id_orden]);

                        $productos_arr = [];
                        if ($result_productos) while ($p = $result_productos->fetch_assoc()) $productos_arr[] = $p;
                    ?>
                    <div class="<?php echo $clase_card; ?>">

                        <div class="pedido-header">
                            <div class="pedido-info" style="flex:1;">
                                <h3><i class="bi bi-bag-check"></i> Pedido #<?php echo $orden['numero_orden']; ?></h3>
                                <p style="color:var(--text-muted);margin:5px 0;">
                                    <i class="bi bi-calendar"></i>
                                    <?php echo date('d/m/Y H:i', strtotime($orden['fecha'])); ?>
                                </p>
                                <p style="color:var(--text-muted);margin:5px 0;">
                                    <i class="bi bi-box-seam"></i>
                                    <?php echo $orden['total_productos']; ?> producto(s)
                                    de <?php echo $orden['total_productores']; ?> productor(es)
                                </p>

                                <?php if ($estado_pago === 'confirmado' && !$es_expirado): ?>
                                <div class="progreso-entrega">
                                    <span><?php echo $ped_entregados; ?>/<?php echo $ped_total; ?> entregados</span>
                                    <div class="progreso-barra">
                                        <div class="progreso-fill" style="width:<?php echo $pct_entrega; ?>%;"></div>
                                    </div>
                                    <span><?php echo $pct_entrega; ?>%</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="pedido-status" style="text-align:right;">
                                <span class="badge badge-<?php echo $badge_clase; ?>" style="font-size:15px;padding:10px 18px;">
                                    <?php echo $badge_texto; ?>
                                </span>
                                <div class="pedido-total">$<?php echo number_format($orden['total_orden'], 0); ?></div>
                            </div>
                        </div>

                        <?php if (!$es_expirado && $es_efectivo && $pago_pendiente): ?>
                            <?php if ($vencido): ?>
                            <div class="alerta-vencido">
                                <i class="bi bi-exclamation-triangle-fill" style="font-size:18px;"></i>
                                <span>Tu pago en efectivo <strong>venció el <?php echo date('d/m/Y H:i', $vence_ts); ?></strong>. La orden será marcada como expirada.</span>
                            </div>
                            <?php elseif ($vence_ts): ?>
                            <div style="background:#fff3cd;border:1px solid rgba(230,81,0,.2);border-radius:var(--radius-sm);padding:10px 14px;display:flex;align-items:center;gap:8px;margin-bottom:10px;font-size:13px;color:#856404;">
                                <i class="bi bi-clock-history"></i>
                                Paga antes del <strong><?php echo date('d/m/Y H:i', $vence_ts); ?></strong> en cualquier punto Efecty o Sured.
                            </div>
                            <?php endif; ?>
                        <?php elseif (!$es_expirado && $pago_pendiente && !$es_efectivo): ?>
                        <div style="background:#e3f2fd;border:1px solid rgba(21,101,192,.2);border-radius:var(--radius-sm);padding:10px 14px;display:flex;align-items:center;gap:8px;margin-bottom:10px;font-size:13px;color:#1565c0;">
                            <i class="bi bi-envelope-at-fill"></i>
                            Revisa tu correo para confirmar el pago.
                        </div>
                        <?php endif; ?>

                        <?php if ($es_expirado): ?>
                        <div class="expirado-aviso">
                            <i class="bi bi-hourglass-bottom"></i>
                            <p><strong>Esta orden expiró sin completar el pago.</strong></p>
                            <?php
                            $result_tipo_exp = ejecutarConsulta($conn,
                                "SELECT m.tipo FROM pagos p
                                INNER JOIN metodos_pago m ON p.id_metodo_pago = m.id_metodo
                                WHERE p.id_orden = ? AND m.tipo IN ('billetera','pse')
                                LIMIT 1", "i", [$id_orden]);
                            $expiro_por_codigo = $result_tipo_exp && $result_tipo_exp->num_rows > 0;
                            ?>
                           <?php if ($expiro_por_codigo && !$ya_reintento): ?>
                            <a href="checkout_carrito.php?reintento=<?php echo $id_orden; ?>"
                            class="btn btn-primary btn-sm" style="margin-top:10px;">
                                <i class="bi bi-arrow-clockwise"></i> Reintentar pago
                            </a>
                        <?php elseif ($expiro_por_codigo && $ya_reintento): ?>
                            <p style="font-size:13px;color:var(--success);margin-top:10px;">
                                <i class="bi bi-check-circle-fill"></i> Ya completaste este pago en un pedido posterior.
                            </p>
                        <?php else: ?>
                            <p>Si aún deseas estos productos, puedes volver a agregarlos desde el catálogo.</p>
                            <a href="catalogo_comprador.php" class="btn btn-primary btn-sm" style="margin-top:10px;">
                                <i class="bi bi-shop"></i> Ir al Catálogo
                            </a>
                        <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!$es_expirado): ?>
                        <div class="productos-lista">
                            <h4 style="margin-bottom:15px;color:var(--text-dark);">
                                <i class="bi bi-box-seam"></i> Productos del Pedido
                            </h4>

                            <?php
                            $por_productor = [];
                            foreach ($productos_arr as $prod) {
                                $pid = $prod['id_productor'];
                                if (!isset($por_productor[$pid])) {
                                    $por_productor[$pid] = [
                                        'nombre_productor'   => $prod['nombre_productor'],
                                        'telefono_productor' => $prod['telefono_productor'],
                                        'tiene_entregado'    => false,
                                        'productos'          => []
                                    ];
                                }
                                if ($prod['estado'] === 'entregado') {
                                    $por_productor[$pid]['tiene_entregado'] = true;
                                }
                                $por_productor[$pid]['productos'][] = $prod;
                            }

                            $result_cals = ejecutarConsulta($conn,
                            "SELECT id_productor, id_calificacion, estado, motivo_rechazo
                            FROM calificaciones_resenas
                            WHERE id_orden = ? AND id_comprador = ?",
                            "ii", [$id_orden, $usuario_id]);

                        $calificaciones_map = [];
                        if ($result_cals) {
                            while ($c = $result_cals->fetch_assoc()) {
                                $calificaciones_map[$c['id_productor']] = [
                                    'id_calificacion' => $c['id_calificacion'],
                                    'estado'          => $c['estado'],
                                    'motivo_rechazo'  => $c['motivo_rechazo']
                                ];
                            }
                        }
                            ?>

                            <?php foreach ($por_productor as $id_prod => $grupo): ?>
                            <div style="margin-bottom:20px;">
                                <!-- Cabecera del productor -->
                                <div style="display:flex;justify-content:space-between;align-items:center;
                                            padding:10px 15px;background:var(--bg-light);
                                            border-radius:var(--radius-sm);margin-bottom:10px;flex-wrap:wrap;gap:8px;">
                                    <span style="font-weight:700;color:var(--text-dark);">
                                        <i class="bi bi-person-badge"></i>
                                        <?php echo htmlspecialchars($grupo['nombre_productor']); ?>
                                        <span style="font-weight:400;color:var(--text-muted);font-size:13px;">
                                            · <?php echo htmlspecialchars($grupo['telefono_productor']); ?>
                                        </span>
                                    </span>

                                    <?php if ($grupo['tiene_entregado'] && $estado_pago === 'confirmado'): ?>
                                        <?php if (isset($calificaciones_map[$id_prod])): ?>
                                            <?php
                                            $cal      = $calificaciones_map[$id_prod];
                                            $id_cal   = $cal['id_calificacion'];
                                            $est_cal  = $cal['estado'];
                                            if ($est_cal === 'activa') {
                                                $badge_cal_clase = 'success';
                                                $badge_cal_texto = '<i class="bi bi-star-fill"></i> Reseña publicada';
                                            } elseif ($est_cal === 'pendiente') {
                                                $badge_cal_clase = 'warning';
                                                $badge_cal_texto = '<i class="bi bi-clock-history"></i> Reseña en revisión';
                                           
                                                } else {
                                            $badge_cal_clase = 'danger';
                                            $badge_cal_texto = '<i class="bi bi-x-circle"></i> Reseña rechazada';
                                        }
                                        ?>
                                        <div class="acciones-calificacion">
                                            <span class="badge badge-<?php echo $badge_cal_clase; ?>">
                                                <?php echo $badge_cal_texto; ?>
                                            </span>

                                            <?php if ($est_cal === 'eliminada' && !empty($cal['motivo_rechazo'])): ?>
                                                <span style="font-size:12px;color:#721c24;background:#f8d7da;
                                                            border:1px solid #f5c6cb;border-radius:6px;
                                                            padding:4px 10px;display:inline-flex;align-items:center;gap:5px;">
                                                    <i class="bi bi-info-circle-fill"></i>
                                                    <strong>Motivo:</strong> <?php echo htmlspecialchars($cal['motivo_rechazo']); ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php if (in_array($est_cal, ['activa', 'eliminada'])): ?>
                                                <a href="editar_calificacion.php?id_calificacion=<?php echo $id_cal; ?>"
                                                class="btn btn-sm"
                                                style="background:<?php echo $est_cal === 'eliminada' ? '#dc3545' : '#6c757d'; ?>;color:white;border:none;">
                                                    <i class="bi bi-pencil-square"></i>
                                                    <?php echo $est_cal === 'eliminada' ? 'Corregir y reenviar' : 'Editar'; ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                            
                                        <?php else: ?>
                                            <a href="calificar_productor.php?id_orden=<?php echo $id_orden; ?>&id_productor=<?php echo $id_prod; ?>"
                                               class="btn btn-primary btn-sm">
                                                <i class="bi bi-star"></i> Calificar Productor
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <?php foreach ($grupo['productos'] as $prod):
                                    $item_class = $prod['estado'] === 'entregado' ? 'producto-item entregado' : 'producto-item';
                                ?>
                                <div class="<?php echo $item_class; ?>">
                                    <?php
                                    $img_src = !empty($prod['imagen_producto'])
                                        ? (str_starts_with($prod['imagen_producto'], 'http') 
                                            ? $prod['imagen_producto'] 
                                            : '../../' . ltrim($prod['imagen_producto'], '/'))
                                        : null;
                                    ?>
                                    <?php if ($img_src): ?>
                                    <img src="<?php echo htmlspecialchars($img_src); ?>"
                                        class="producto-img"
                                        alt="<?php echo htmlspecialchars($prod['nombre_producto']); ?>"
                                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="producto-img" style="background:var(--bg-light);display:none;align-items:center;justify-content:center;">
                                        <i class="bi bi-image" style="font-size:32px;color:#ccc;"></i>
                                    </div>
                                    <?php else: ?>
                                    <div class="producto-img" style="background:var(--bg-light);display:flex;align-items:center;justify-content:center;">
                                        <i class="bi bi-image" style="font-size:32px;color:#ccc;"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div class="producto-detalles">
                                        <h4><?php echo htmlspecialchars($prod['nombre_producto']); ?></h4>
                                        <p>
                                            <strong>Cantidad:</strong> <?php echo $prod['cantidad']; ?> <?php echo ucfirst($prod['unidad_medida']); ?>(s)
                                            | <strong>Precio:</strong> $<?php echo number_format($prod['precio_unitario'], 0); ?>
                                            | <strong>Total:</strong> $<?php echo number_format($prod['total'], 0); ?>
                                        </p>
                                        <p><?php
                                            $bp = match($prod['estado']) {
                                                'confirmado' => ['primary', '✅ Confirmado — en camino'],
                                                'entregado'  => ['success', '🚚 Entregado'],
                                                default      => ['warning', '⏳ Pendiente'],
                                            };
                                        ?><span class="badge badge-<?php echo $bp[0]; ?>"><?php echo $bp[1]; ?></span></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>

                        </div>
                        <?php endif; ?>

                        <?php if (!$es_expirado): ?>
                        <div class="pago-section <?php echo htmlspecialchars($estado_pago); ?>">
                            <h4><i class="bi bi-credit-card"></i> Información de Pago</h4>
                            <p>
                                <strong>Método:</strong> <?php echo htmlspecialchars($orden['nombre_metodo'] ?? '—'); ?> |
                                <strong>Referencia:</strong> <code><?php echo htmlspecialchars($orden['referencia_pago'] ?? '—'); ?></code> |
                                <strong>Valor:</strong> $<?php echo number_format($orden['valor_pago'], 0); ?>
                            </p>
                            <?php if ($orden['fecha_confirmacion']): ?>
                            <p style="color:var(--success);font-weight:600;">
                                <i class="bi bi-check-circle-fill"></i>
                                Confirmado el <?php echo date('d/m/Y H:i', strtotime($orden['fecha_confirmacion'])); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                    <?php endwhile; ?>

                <?php else: ?>
                <div style="text-align:center;padding:80px 20px;">
                    <i class="bi bi-inbox" style="font-size:100px;color:#e0e0e0;display:block;margin-bottom:20px;"></i>
                    <h3 style="font-size:28px;margin-bottom:15px;color:var(--text-dark);">No hay pedidos</h3>
                    <p style="font-size:16px;color:var(--text-muted);">Aún no has realizado ningún pedido.</p>
                    <a href="catalogo_comprador.php" class="btn btn-primary" style="margin-top:15px;">
                        <i class="bi bi-shop"></i> Ver Catálogo
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }
document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar');
    const mt = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && !sb.contains(e.target) && !mt.contains(e.target) && sb.classList.contains('open'))
        sb.classList.remove('open');
});
</script>
</body>
</html>