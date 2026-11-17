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

$query_stats = "
    SELECT 
        COUNT(*) as total_activas,
        ROUND(AVG(calificacion), 1) as promedio_calificacion,
        COUNT(CASE WHEN calificacion = 5 THEN 1 END) as cinco_estrellas,
        COUNT(CASE WHEN calificacion = 4 THEN 1 END) as cuatro_estrellas,
        COUNT(CASE WHEN calificacion = 3 THEN 1 END) as tres_estrellas,
        COUNT(CASE WHEN calificacion = 2 THEN 1 END) as dos_estrellas,
        COUNT(CASE WHEN calificacion = 1 THEN 1 END) as una_estrella
    FROM calificaciones_resenas
    WHERE id_productor = ? AND estado = 'activa'
";
$result_stats = ejecutarConsulta($conn, $query_stats, "i", [$usuario_id]);
$stats = $result_stats ? $result_stats->fetch_assoc() : [];
$total_activas = intval($stats['total_activas'] ?? 0);

$query_calificaciones = "
    SELECT 
        c.id_calificacion,
        c.calificacion,
        c.resena,
        c.imagenes,
        c.fecha_calificacion,
        comp.nombre AS nombre_comprador,
        c.id_orden,
        o.fecha AS fecha_orden,
        GROUP_CONCAT(DISTINCT p.nombre SEPARATOR ', ') AS nombre_producto
    FROM calificaciones_resenas c
    INNER JOIN usuarios comp ON c.id_comprador = comp.id_usuario
    INNER JOIN ordenes o ON c.id_orden = o.id_orden
    LEFT JOIN pedidos ped 
        ON ped.id_orden = c.id_orden 
        AND ped.id_productor = c.id_productor
        AND ped.id_comprador = c.id_comprador
    LEFT JOIN productos p ON ped.id_producto = p.id_producto
    WHERE c.id_productor = ? AND c.estado = 'activa'
    GROUP BY c.id_calificacion, comp.nombre, c.id_orden, o.fecha
    ORDER BY c.fecha_calificacion DESC
";
$result_calificaciones = ejecutarConsulta($conn, $query_calificaciones, "i", [$usuario_id]);

function generarEstrellas($calificacion) {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $calificacion
            ? '<i class="bi bi-star-fill" style="color:#ffc107;"></i>'
            : '<i class="bi bi-star" style="color:#ddd;"></i>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Reseñas - QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .review-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--success);
            transition: var(--transition);
        }
        .review-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }

        .review-rating    { font-size: 20px; margin-bottom: 8px; }
        .review-meta      { display: flex; gap: 20px; flex-wrap: wrap; font-size: 14px; color: var(--text-muted); margin-top: 8px; }
        .review-meta-item { display: flex; align-items: center; gap: 5px; }

        .review-content {
            background: #f8f9fa; padding: 15px;
            border-radius: var(--radius-sm);
            margin: 15px 0 10px; line-height: 1.6;
        }
        .review-content.no-review { font-style: italic; color: #999; }

        .review-fotos { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        .review-fotos a img {
            width: 90px; height: 90px; object-fit: cover;
            border-radius: 8px; border: 2px solid #e0e0e0; cursor: pointer;
            transition: transform .2s, border-color .2s;
        }
        .review-fotos a img:hover { transform: scale(1.05); border-color: var(--primary); }

        .star-bar          { display: flex; align-items: center; gap: 10px; font-size: 14px; margin-bottom: 8px; }
        .star-bar-fill     { flex: 1; height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden; }
        .star-bar-progress { height: 100%; background: var(--warning); transition: width .3s; }

        .rating-summary { display: flex; gap: 40px; align-items: center; margin-bottom: 30px; flex-wrap: wrap; }
        .rating-average {
            text-align: center; padding: 20px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius-lg); color: white;
        }
        .rating-number { font-size: 56px; font-weight: 800; line-height: 1; }
        .rating-stars  { font-size: 24px; margin: 10px 0; }
        .rating-count  { font-size: 14px; opacity: .9; }

        .no-reviews   { text-align: center; padding: 60px 20px; background: #f8f9fa; border-radius: var(--radius-lg); }
        .no-reviews i { font-size: 64px; color: var(--text-muted); display: block; margin-bottom: 20px; }

        .buscador-wrap { position: relative; display: flex; align-items: center; }
        .buscador-wrap .form-control { padding-left: 42px; }
        .buscador-wrap .search-icon {
            position: absolute; left: 14px;
            color: var(--text-muted); font-size: 16px; pointer-events: none;
        }

        @media (max-width: 640px) {
            .catalogo-actions { flex-direction: column; }
            .catalogo-actions .buscador-wrap,
            .catalogo-actions select,
            .catalogo-actions .btn { width: 100%; max-width: none !important; }
            .rating-summary { flex-direction: column; }
            .rating-average { width: 100%; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleMenu()">
    <i class="bi bi-list"></i>
</button>

<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo">
        </div>
        <nav class="sidebar-menu">
            <a href="panel_productor.php"><i class="bi bi-speedometer2"></i> Inicio</a>
            <a href="productos_productor.php"><i class="bi bi-box-seam"></i> Mis Productos</a>
            <a href="pedidos_productor.php"><i class="bi bi-clipboard-check"></i> Pedidos</a>
            <a href="calificaciones_productor.php" class="active"><i class="bi bi-star"></i> Reseñas</a>
            <a href="perfil.php"><i class="bi bi-person-circle"></i> Mi Perfil</a>
            <a href="estadisticas.php"><i class="bi bi-bar-chart"></i> Estadísticas</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Mis Reseñas y Calificaciones</h1>
                <p class="subtitle">Lo que opinan tus clientes sobre tus productos</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
            </div>
        </header>

        <div class="content-area">

            <div class="stats-grid">
                <div class="stat-card success">
                    <i class="bi bi-star-fill card-icon"></i>
                    <h3>Reseñas</h3>
                    <div class="number"><?php echo number_format($total_activas); ?></div>
                    <p>Publicadas por tus clientes</p>
                </div>
                <div class="stat-card" style="grid-column: span 3;">
                    <i class="bi bi-star-fill card-icon"></i>
                    <h3>Calificación Promedio</h3>
                    <div style="display:flex; align-items:center; justify-content:center; gap:8px; margin-top:10px; flex-wrap:wrap;">
                        <i class="bi bi-star-fill" style="color:#ffc107; font-size:24px;"></i>
                        <span style="font-size:36px; font-weight:800; color:var(--primary);">
                            <?php echo $stats['promedio_calificacion'] ?: '0.0'; ?>
                        </span>
                        <span style="color:var(--text-muted); font-size:14px;">
                            /5 · basado en <?php echo $total_activas; ?> reseña<?php echo $total_activas != 1 ? 's' : ''; ?> publicada<?php echo $total_activas != 1 ? 's' : ''; ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="catalogo-container">
                <div class="catalogo-header">
                    <h2><i class="bi bi-bar-chart"></i> Distribución de Calificaciones</h2>
                </div>
                <div class="rating-summary">
                    <div class="rating-average">
                        <div class="rating-number"><?php echo $stats['promedio_calificacion'] ?: '0.0'; ?></div>
                        <div class="rating-stars"><?php echo generarEstrellas(round($stats['promedio_calificacion'] ?: 0)); ?></div>
                        <div class="rating-count">
                            <?php echo $total_activas; ?> reseña<?php echo $total_activas != 1 ? 's' : ''; ?> publicada<?php echo $total_activas != 1 ? 's' : ''; ?>
                        </div>
                    </div>
                    <div style="flex:1; min-width:300px;">
                        <?php
                        $base = $total_activas > 0 ? $total_activas : 1;
                        $distribuciones = [
                            5 => $stats['cinco_estrellas']  ?? 0,
                            4 => $stats['cuatro_estrellas'] ?? 0,
                            3 => $stats['tres_estrellas']   ?? 0,
                            2 => $stats['dos_estrellas']    ?? 0,
                            1 => $stats['una_estrella']     ?? 0,
                        ];
                        foreach ($distribuciones as $estrella => $cantidad):
                            $percentage = ($cantidad / $base) * 100;
                        ?>
                        <div class="star-bar">
                            <div style="width:100px; display:flex; align-items:center; gap:4px;">
                                <?php echo generarEstrellas($estrella); ?>
                            </div>
                            <div class="star-bar-fill">
                                <div class="star-bar-progress" style="width:<?php echo $percentage; ?>%"></div>
                            </div>
                            <span style="min-width:80px; text-align:right;">
                                <?php echo $cantidad; ?> (<?php echo number_format($percentage, 1); ?>%)
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="catalogo-container">
                <div class="catalogo-actions">
                    <div class="buscador-wrap" style="flex:1;">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" id="f_busqueda" class="form-control"
                               placeholder="Buscar por cliente o producto..."
                               oninput="filtrarResenas()">
                    </div>
                    <select id="f_calificacion" class="form-control" style="max-width:200px;" onchange="filtrarResenas()">
                        <option value="todas">Todas las calificaciones</option>
                        <option value="5">⭐⭐⭐⭐⭐ 5 estrellas</option>
                        <option value="4">⭐⭐⭐⭐ 4 estrellas</option>
                        <option value="3">⭐⭐⭐ 3 estrellas</option>
                        <option value="2">⭐⭐ 2 estrellas</option>
                        <option value="1">⭐ 1 estrella</option>
                    </select>
                    <button type="button" class="btn btn-secondary" onclick="limpiarFiltros()">
                        <i class="bi bi-x-circle"></i> Limpiar
                    </button>
                </div>
            </div>

            <div class="catalogo-container">
                <div class="catalogo-header">
                    <h2><i class="bi bi-chat-left-text"></i> Mis Reseñas</h2>
                    <span style="font-size:14px;color:var(--text-muted);font-weight:600;">
                        <i class="bi bi-list-ul"></i>
                        <span id="numResenas"><?php echo $result_calificaciones ? $result_calificaciones->num_rows : 0; ?></span> encontrada<?php echo ($result_calificaciones && $result_calificaciones->num_rows !== 1) ? 's' : ''; ?>
                    </span>
                </div>

                <?php if ($result_calificaciones && $result_calificaciones->num_rows > 0): ?>
                    <?php while ($resena = $result_calificaciones->fetch_assoc()): ?>

                    <div class="review-card"
                         data-calificacion="<?php echo intval($resena['calificacion']); ?>"
                         data-comprador="<?php echo strtolower(htmlspecialchars($resena['nombre_comprador'])); ?>"
                         data-producto="<?php echo strtolower(htmlspecialchars($resena['nombre_producto'] ?? '')); ?>"
                         data-resena="<?php echo strtolower(htmlspecialchars($resena['resena'] ?? '')); ?>">

                        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
                            <div>
                                <div class="review-rating">
                                    <?php echo generarEstrellas($resena['calificacion']); ?>
                                    <span style="font-size:15px; margin-left:8px; color:#666;">
                                        <?php echo $resena['calificacion']; ?>/5
                                    </span>
                                </div>
                                <div class="review-meta">
                                    <div class="review-meta-item">
                                        <i class="bi bi-person"></i>
                                        <strong>Cliente:</strong>
                                        <?php echo htmlspecialchars($resena['nombre_comprador']); ?>
                                    </div>
                                    <?php if (!empty($resena['nombre_producto'])): ?>
                                    <div class="review-meta-item">
                                        <i class="bi bi-box"></i>
                                        <strong>Producto:</strong>
                                        <?php echo htmlspecialchars($resena['nombre_producto']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="review-meta-item">
                                        <i class="bi bi-calendar"></i>
                                        <?php echo date('d/m/Y', strtotime($resena['fecha_orden'])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty(trim($resena['resena']))): ?>
                            <div class="review-content">
                                <?php echo nl2br(htmlspecialchars($resena['resena'])); ?>
                            </div>
                        <?php else: ?>
                            <div class="review-content no-review">
                                <i class="bi bi-chat-left-dots"></i> Sin comentario.
                            </div>
                        <?php endif; ?>

                        <?php
                        $imgs = !empty($resena['imagenes']) ? json_decode($resena['imagenes'], true) : [];
                        if (!empty($imgs)): ?>
                            <div class="review-fotos">
                                <?php foreach ($imgs as $img): ?>
                                    <a href="../../<?php echo htmlspecialchars($img); ?>" target="_blank">
                                        <img src="../../<?php echo htmlspecialchars($img); ?>" alt="Foto de reseña">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    </div>
                    <?php endwhile; ?>

                    <div id="sinResultadosResenas" style="display:none;">
                        <div class="no-reviews">
                            <i class="bi bi-inbox"></i>
                            <h3 style="color:var(--text-muted); margin-bottom:10px;">No se encontraron reseñas con los filtros aplicados</h3>
                            <p style="color:var(--text-muted);">Intenta con otros criterios de búsqueda.</p>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="no-reviews">
                        <i class="bi bi-inbox"></i>
                        <h3 style="color:var(--text-muted); margin-bottom:10px;">Aún no tienes reseñas</h3>
                        <p style="color:var(--text-muted);">Cuando tus clientes te califiquen, aparecerán aquí.</p>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<script>
function filtrarResenas() {
    const buscar       = (document.getElementById('f_busqueda')?.value    || '').toLowerCase().trim();
    const calificacion = document.getElementById('f_calificacion')?.value || 'todas';

    const tarjetas = document.querySelectorAll('.review-card');
    let visibles = 0;

    tarjetas.forEach(card => {
        const cardCalificacion = card.dataset.calificacion || '';
        const cardComprador    = card.dataset.comprador    || '';
        const cardProducto     = card.dataset.producto     || '';
        const cardResena       = card.dataset.resena       || '';

        const okCalificacion = (calificacion === 'todas') || (cardCalificacion === calificacion);
        const okBuscar = !buscar ||
            cardComprador.includes(buscar) ||
            cardProducto.includes(buscar)  ||
            cardResena.includes(buscar);

        const visible = okCalificacion && okBuscar;
        card.style.display = visible ? '' : 'none';
        if (visible) visibles++;
    });

    const numEl = document.getElementById('numResenas');
    if (numEl) numEl.textContent = visibles.toLocaleString('es-CO');

    const vacio = document.getElementById('sinResultadosResenas');
    if (vacio) vacio.style.display = visibles === 0 ? '' : 'none';
}

function limpiarFiltros() {
    document.getElementById('f_busqueda').value     = '';
    document.getElementById('f_calificacion').value = 'todas';
    filtrarResenas();
}

function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }
document.addEventListener('click', (e) => {
    const sidebar    = document.getElementById('sidebar');
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
<?php $conn->close(); ?>