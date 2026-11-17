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

if (isset($_GET['ajax_productor'])) {
    $id_p = intval($_GET['ajax_productor']);
    $data = [];

    $s = $conn->prepare("
        SELECT u.id_usuario, u.nombre, u.email, u.telefono,
               p.foto_perfil, p.nombre_finca, p.descripcion,
               p.ubicacion, p.tipo_produccion, p.experiencia,
               COALESCE(AVG(c.calificacion),0) as calificacion_promedio,
               COUNT(DISTINCT c.id_calificacion) as total_resenas,
               COUNT(DISTINCT pr.id_producto) as total_productos
        FROM usuarios u
        INNER JOIN perfiles_productor p ON u.id_usuario = p.id_productor
        LEFT JOIN calificaciones_resenas c ON u.id_usuario = c.id_productor AND c.estado = 'activa'
        LEFT JOIN productos pr ON u.id_usuario = pr.id_productor AND pr.estado IN ('disponible','stock_bajo') AND pr.cantidad > 0
        WHERE u.id_usuario = ? AND u.rol = 'productor' AND u.eliminado = 0 AND u.estado = 'activo'
        GROUP BY u.id_usuario
    ");
    $s->bind_param("i", $id_p);
    $s->execute();
    $data['productor'] = $s->get_result()->fetch_assoc();

    $sc = $conn->prepare("SELECT COUNT(*) as total FROM productos WHERE id_productor = ? AND estado IN ('disponible','stock_bajo') AND cantidad > 0");
    $sc->bind_param("i", $id_p);
    $sc->execute();
    $data['total_productos_reales'] = $sc->get_result()->fetch_assoc()['total'];

    $s2 = $conn->prepare("
        SELECT p.id_producto, p.nombre, p.precio, p.precio_mayorista,
               p.cantidad_minima_mayorista, p.unidad_medida, p.cantidad, p.estado, p.imagen,
               c.nombre_categoria, c.icono as icono_categoria,
               (SELECT ruta_foto FROM fotos_productos fp WHERE fp.id_producto = p.id_producto ORDER BY fp.orden LIMIT 1) as foto_producto
        FROM productos p
        LEFT JOIN categorias_productos c ON p.id_categoria = c.id_categoria
        WHERE p.id_productor = ? AND p.estado IN ('disponible','stock_bajo') AND p.cantidad > 0
        ORDER BY p.fecha_creacion DESC
        LIMIT 3
    ");
    $s2->bind_param("i", $id_p);
    $s2->execute();
    $data['productos'] = $s2->get_result()->fetch_all(MYSQLI_ASSOC);

    $s3 = $conn->prepare("SELECT ruta_foto, descripcion FROM fotos_finca WHERE id_productor = ? ORDER BY fecha_subida DESC LIMIT 9");
    $s3->bind_param("i", $id_p);
    $s3->execute();
    $data['fotos'] = $s3->get_result()->fetch_all(MYSQLI_ASSOC);

    $s4 = $conn->prepare("
        SELECT cr.calificacion, cr.resena, cr.fecha_calificacion, u.nombre as comprador_nombre
        FROM calificaciones_resenas cr
        INNER JOIN usuarios u ON cr.id_comprador = u.id_usuario
        WHERE cr.id_productor = ? AND cr.estado = 'activa'
        ORDER BY cr.fecha_calificacion DESC LIMIT 10
    ");
    $s4->bind_param("i", $id_p);
    $s4->execute();
    $data['resenas'] = $s4->get_result()->fetch_all(MYSQLI_ASSOC);

    $s5 = $conn->prepare("SELECT calificacion, COUNT(*) as cantidad FROM calificaciones_resenas WHERE id_productor = ? AND estado='activa' GROUP BY calificacion");
    $s5->bind_param("i", $id_p);
    $s5->execute();
    $dist = [];
    foreach ($s5->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $dist[$row['calificacion']] = $row['cantidad'];
    }
    $data['distribucion'] = $dist;

    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

$sql = "
    SELECT u.id_usuario, u.nombre, u.email, u.telefono,
           p.foto_perfil, p.nombre_finca, p.descripcion, p.ubicacion, p.tipo_produccion,
           COALESCE(AVG(c.calificacion),0) as calificacion_promedio,
           COUNT(DISTINCT c.id_calificacion) as total_resenas,
           COUNT(DISTINCT prod.id_producto) as total_productos,
           (
               SELECT GROUP_CONCAT(
                   CONCAT(pr2.nombre, ':::', COALESCE(cat2.icono,''))
                   ORDER BY pr2.fecha_creacion DESC SEPARATOR ';;;'
               )
               FROM productos pr2
               LEFT JOIN categorias_productos cat2 ON pr2.id_categoria = cat2.id_categoria
               WHERE pr2.id_productor = u.id_usuario
                 AND pr2.estado IN ('disponible','stock_bajo')
                 AND pr2.cantidad > 0
               LIMIT 3
           ) as productos_preview
    FROM usuarios u
    INNER JOIN perfiles_productor p ON u.id_usuario = p.id_productor
    LEFT JOIN calificaciones_resenas c ON u.id_usuario = c.id_productor AND c.estado = 'activa'
    LEFT JOIN productos prod ON u.id_usuario = prod.id_productor AND prod.estado IN ('disponible','stock_bajo') AND prod.cantidad > 0
    WHERE u.rol = 'productor' AND u.eliminado = 0 AND u.estado = 'activo'
    GROUP BY u.id_usuario
    ORDER BY calificacion_promedio DESC, total_productos DESC
";

$result_productores = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productores - QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        .productor-card {
            background: white; border-radius: var(--radius-lg); overflow: hidden;
            box-shadow: var(--shadow-sm); transition: var(--transition);
            border: 2px solid transparent; display: flex; flex-direction: column; height: 100%;
        }
        .productor-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-md); border-color: var(--primary); }

        .productor-header {
            position: relative; height: 80px;
            background: linear-gradient(135deg, #2d6a4f 0%, #52b788 100%);
        }
        .productor-foto-perfil,
        .productor-foto-placeholder {
            position: absolute; bottom: -38px; left: 18px;
            width: 90px; height: 90px; border-radius: 50%;
            border: 4px solid white; box-shadow: var(--shadow-md);
            object-fit: cover; background: white; z-index: 5;
        }
        .productor-foto-placeholder {
            display: flex; align-items: center; justify-content: center;
            font-size: 36px; color: var(--primary); background: #f0f0f0;
        }

        .productor-info        { padding: 50px 18px 18px; flex: 1; }
        .productor-nombre      { font-size: 18px; font-weight: 700; color: var(--text-dark); margin-bottom: 3px; }
        .productor-finca       { font-size: 14px; color: var(--primary); font-weight: 600; margin-bottom: 6px; display: flex; align-items: center; gap: 5px; }
        .productor-ubicacion   { font-size: 13px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; margin-bottom: 10px; }
        .productor-rating      { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
        .productor-rating i    { color: #ffc107; }
        .productor-descripcion {
            font-size: 13px; color: var(--text-muted); line-height: 1.5; margin-bottom: 12px;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .prod-tags  { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
        .prod-tag   { background: var(--bg-light); border-radius: 20px; padding: 3px 10px; font-size: 12px; color: var(--text-dark); }
        .productor-actions      { display: flex; gap: 8px; }
        .productor-actions .btn { flex: 1; font-size: 13px; }

        .btn-whatsapp {
            background: #25D366 !important; color: white !important; border: none !important;
            display: inline-flex !important; align-items: center !important;
            justify-content: center !important; gap: 5px !important;
        }
        .btn-whatsapp:hover { background: #1da851 !important; }

        .buscador-wrap {
            position: relative; display: flex; align-items: center; flex: 1;
        }
        .buscador-wrap .form-control { padding-left: 42px; }
        .buscador-wrap .search-icon {
            position: absolute; left: 14px;
            color: var(--text-muted); font-size: 16px; pointer-events: none;
        }
        .buscador-wrap .spinner-sm {
            position: absolute; right: 14px;
            width: 18px; height: 18px;
            border: 2px solid #ddd; border-top-color: var(--primary);
            border-radius: 50%; animation: spin .6s linear infinite; display: none;
        }
        .buscador-wrap.cargando .spinner-sm { display: block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.55); z-index: 2000;
            overflow-y: auto; padding: 30px 16px;
        }
        .modal-overlay.active { display: flex; align-items: flex-start; justify-content: center; }
        .modal-box {
            background: white; border-radius: 16px; width: 100%; max-width: 860px;
            box-shadow: 0 20px 60px rgba(0,0,0,.25); overflow: hidden; position: relative;
        }
        .modal-portada {
            height: 110px;
            background: linear-gradient(135deg, #2d6a4f 0%, #52b788 100%);
            position: relative;
        }
        .modal-foto-wrap { position: absolute; bottom: -44px; left: 32px; }
        .modal-foto {
            width: 90px; height: 90px; border-radius: 50%;
            border: 4px solid white; box-shadow: 0 4px 16px rgba(0,0,0,.15);
            object-fit: cover; display: block;
        }
        .modal-foto-placeholder {
            width: 90px; height: 90px; border-radius: 50%;
            border: 4px solid white; box-shadow: 0 4px 16px rgba(0,0,0,.15);
            background: #f0f0f0; display: flex; align-items: center; justify-content: center;
            font-size: 44px; color: var(--primary);
        }
        .modal-close {
            position: absolute; top: 14px; right: 18px;
            background: rgba(0,0,0,.3); border: none; border-radius: 50%;
            width: 34px; height: 34px; cursor: pointer;
            color: white; font-size: 18px; display: flex; align-items: center; justify-content: center;
        }
        .modal-close:hover { background: rgba(0,0,0,.5); }
        .modal-body      { padding: 56px 32px 32px; }
        .modal-nombre    { font-size: 22px; font-weight: 700; color: var(--text-dark); margin-bottom: 3px; }
        .modal-finca     { font-size: 15px; color: var(--primary); font-weight: 600; display: flex; align-items: center; gap: 5px; margin-bottom: 4px; }
        .modal-ubicacion { font-size: 13px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; margin-bottom: 16px; }

        .m-stats { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 24px; }
        .m-stat  { background: #f8f9fa; border-radius: 10px; padding: 10px 16px; display: flex; align-items: center; gap: 8px; min-width: 120px; }
        .m-stat i   { font-size: 20px; color: var(--primary); }
        .m-stat .v  { font-size: 17px; font-weight: 700; color: var(--text-dark); }
        .m-stat .l  { font-size: 11px; color: var(--text-muted); }

        .m-seccion { margin-bottom: 26px; }
        .m-seccion h4 {
            font-size: 15px; font-weight: 700; color: var(--text-dark);
            display: flex; align-items: center; gap: 6px;
            border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; margin-bottom: 14px;
        }
        .m-seccion h4 i { color: var(--primary); }
        .m-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 14px; }
        .m-info-item label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 3px; }
        .m-info-item span  { font-size: 14px; color: var(--text-dark); }

        .m-prods { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px,1fr)); gap: 12px; }
        .m-prod-card { border: 1px solid #eee; border-radius: 10px; overflow: hidden; transition: box-shadow .2s; }
        .m-prod-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); border-color: var(--primary); }
        .m-prod-img  { height: 110px; background: #f8f8f8; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .m-prod-img img { width: 100%; height: 100%; object-fit: cover; }
        .m-prod-img i   { font-size: 32px; color: #ccc; }
        .m-prod-body    { padding: 10px; }
        .m-prod-nombre  { font-weight: 600; font-size: 13px; color: var(--text-dark); margin-bottom: 2px; }
        .m-prod-cat     { font-size: 11px; color: var(--text-muted); margin-bottom: 6px; }
        .m-prod-precio  { font-size: 15px; font-weight: 700; color: var(--primary); }
        .m-prod-unidad  { font-size: 11px; color: var(--text-muted); }
        .m-prod-mayorista { font-size: 11px; color: var(--primary); margin-top: 3px; }
        .m-prod-estado  { display: inline-block; font-size: 10px; padding: 2px 7px; border-radius: 20px; margin-top: 5px; }
        .estado-disponible { background: #d1fae5; color: #065f46; }
        .estado-stock_bajo  { background: #fef3c7; color: #92400e; }

        .m-fotos { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px,1fr)); gap: 8px; }
        .m-foto  { height: 110px; border-radius: 8px; overflow: hidden; cursor: pointer; }
        .m-foto img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s; }
        .m-foto:hover img { transform: scale(1.07); }

        .m-rating-wrap      { display: flex; gap: 24px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; }
        .m-rating-num       { text-align: center; }
        .m-rating-num .big  { font-size: 48px; font-weight: 800; color: var(--text-dark); line-height: 1; }
        .m-rating-num .stars{ color: #ffc107; font-size: 16px; margin: 5px 0; }
        .m-rating-num small { color: var(--text-muted); font-size: 12px; }
        .m-barras { flex: 1; min-width: 180px; }
        .m-barra-row { display: flex; align-items: center; gap: 6px; margin-bottom: 5px; font-size: 12px; }
        .m-barra-row .lbl  { width: 45px; text-align: right; color: var(--text-muted); }
        .m-barra-bg   { flex: 1; background: #f0f0f0; border-radius: 8px; height: 7px; overflow: hidden; }
        .m-barra-fill { height: 100%; background: #ffc107; border-radius: 8px; }
        .m-barra-row .cnt  { width: 24px; color: var(--text-muted); }

        .m-resena { border-bottom: 1px solid #f5f5f5; padding: 13px 0; }
        .m-resena:last-child { border-bottom: none; }
        .m-resena-header { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px; margin-bottom: 5px; }
        .m-resena-autor  { font-weight: 600; font-size: 14px; color: var(--text-dark); }
        .m-resena-fecha  { font-size: 12px; color: var(--text-muted); }
        .m-resena-stars  { color: #ffc107; font-size: 13px; }
        .m-resena-texto  { font-size: 13px; color: #555; line-height: 1.6; }

        .lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.88); z-index: 9999; align-items: center; justify-content: center; }
        .lightbox.active { display: flex; }
        .lightbox img    { max-width: 90vw; max-height: 90vh; border-radius: 8px; }
        .lightbox-close  { position: fixed; top: 18px; right: 24px; color: white; font-size: 34px; cursor: pointer; line-height: 1; }

        .modal-loading { padding: 80px; text-align: center; color: var(--text-muted); }
        .spinner { width: 40px; height: 40px; border: 4px solid #eee; border-top-color: var(--primary); border-radius: 50%; animation: spin .7s linear infinite; margin: 0 auto 14px; }

        @media (max-width:600px) {
            .modal-body { padding: 52px 18px 24px; }
            .m-prods    { grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); }
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
            <a href="panel_comprador.php"><i class="bi bi-speedometer2"></i> Inicio</a>
            <a href="catalogo_comprador.php"><i class="bi bi-shop"></i> Catálogo</a>
            <a href="pedidos_comprador.php"><i class="bi bi-clipboard-check"></i> Mis Pedidos</a>
            <a href="productores_comprador.php" class="active"><i class="bi bi-people"></i> Productores</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Productores</h1>
                <p class="subtitle">Conoce a los productores de nuestra comunidad</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
            </div>
        </header>

        <div class="content-area">

            <div class="catalogo-container" style="margin-bottom:25px;">
                <div class="catalogo-actions">
                    <div class="buscador-wrap" id="buscadorWrap">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" id="inputBusqueda" class="form-control"
                               placeholder="Buscar productor o finca..."
                               autocomplete="off">
                        <div class="spinner-sm"></div>
                    </div>
                </div>
            </div>

            <div class="categorias-grid" id="productoresGrid"
                 style="grid-template-columns: repeat(auto-fill, minmax(320px,1fr));">

                <?php if ($result_productores && $result_productores->num_rows > 0):
                    while ($p = $result_productores->fetch_assoc()): ?>

                <div class="productor-card"
                     data-nombre="<?php echo strtolower(htmlspecialchars($p['nombre'])); ?>"
                     data-finca="<?php echo strtolower(htmlspecialchars($p['nombre_finca'] ?? '')); ?>">

                    <div class="productor-header">
                        <?php if (!empty($p['foto_perfil']) && file_exists('../../'.$p['foto_perfil'])): ?>
                            <img src="../../<?php echo htmlspecialchars($p['foto_perfil']); ?>" class="productor-foto-perfil" alt="">
                        <?php else: ?>
                            <div class="productor-foto-placeholder"><i class="bi bi-person-circle"></i></div>
                        <?php endif; ?>
                    </div>

                    <div class="productor-info">
                        <div class="productor-nombre"><?php echo htmlspecialchars($p['nombre']); ?></div>
                        <div class="productor-finca"><i class="bi bi-house-door"></i> <?php echo htmlspecialchars($p['nombre_finca'] ?? 'Sin nombre'); ?></div>
                        <?php if (!empty($p['ubicacion'])): ?>
                            <div class="productor-ubicacion"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($p['ubicacion']); ?></div>
                        <?php endif; ?>

                        <div class="productor-rating">
                            <i class="bi bi-star-fill"></i>
                            <span style="font-weight:600;"><?php echo number_format($p['calificacion_promedio'],1); ?></span>
                            <span style="color:var(--text-muted); font-size:13px;">(<?php echo $p['total_resenas']; ?> reseñas)</span>
                        </div>

                        <?php if (!empty($p['descripcion'])): ?>
                            <div class="productor-descripcion"><?php echo htmlspecialchars($p['descripcion']); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($p['productos_preview'])): ?>
                            <div class="prod-tags">
                                <?php
                                $items = explode(';;;', $p['productos_preview']);
                                foreach (array_slice($items, 0, 3) as $item):
                                    $partes      = explode(':::', trim($item), 2);
                                    $nombre_prod = trim($partes[0]);
                                    $icono_prod  = trim($partes[1] ?? '');
                                ?>
                                    <span class="prod-tag">
                                        <?php if (!empty($icono_prod)): ?>
                                            <i class="<?php echo htmlspecialchars($icono_prod); ?>" style="color:var(--primary);font-size:11px;"></i>
                                        <?php else: ?>
                                            <i class="bi bi-tag-fill" style="color:var(--primary);font-size:10px;"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($nombre_prod); ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if ($p['total_productos'] > 3): ?>
                                    <span class="prod-tag">+<?php echo $p['total_productos'] - 3; ?> más</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="productor-actions">
                            <button class="btn btn-primary" onclick="verProductor(<?php echo $p['id_usuario']; ?>)">
                                <i class="bi bi-eye"></i> Ver perfil
                            </button>
                            <a href="https://wa.me/57<?php echo preg_replace('/[^0-9]/','', $p['telefono']); ?>?text=<?php echo urlencode('Hola, vi tu perfil en QuiliAgro Connect y me gustaría saber más sobre tus productos 🌱'); ?>"
                               target="_blank" class="btn btn-whatsapp">
                                <i class="bi bi-whatsapp"></i> Contactar
                            </a>
                        </div>
                    </div>
                </div>

                <?php endwhile; else: ?>
                    <div style="grid-column:1/-1; text-align:center; padding:60px; color:var(--text-muted);">
                        <i class="bi bi-inbox" style="font-size:48px; display:block; margin-bottom:15px;"></i>
                        <h3>No hay productores registrados</h3>
                        <p>Pronto se unirán productores a nuestra comunidad.</p>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<div class="modal-overlay" id="modalOverlay" onclick="cerrarModal(event)">
    <div class="modal-box" id="modalBox">
        <div id="modalContenido">
            <div class="modal-loading">
                <div class="spinner"></div>
                <p>Cargando información...</p>
            </div>
        </div>
    </div>
</div>

<div class="lightbox" id="lightbox" onclick="cerrarLightbox()">
    <span class="lightbox-close">&times;</span>
    <img id="lightboxImg" src="" alt="">
</div>

<script>
function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }
document.addEventListener('click', e => {
    const s = document.getElementById('sidebar');
    const m = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && !s.contains(e.target) && !m.contains(e.target) && s.classList.contains('open'))
        s.classList.remove('open');
});

(function () {
    const input = document.getElementById('inputBusqueda');
    const wrap  = document.getElementById('buscadorWrap');
    let timer;

    input.addEventListener('input', function () {
        clearTimeout(timer);
        const q = this.value.trim().toLowerCase();
        wrap.classList.add('cargando');
        timer = setTimeout(() => {
            filtrarCards(q);
            wrap.classList.remove('cargando');
        }, 200);
    });

    function filtrarCards(q) {
        const cards  = document.querySelectorAll('.productor-card');
        let visibles = 0;

        cards.forEach(card => {
            const nombre  = card.dataset.nombre || '';
            const finca   = card.dataset.finca  || '';
            const coincide = !q || nombre.includes(q) || finca.includes(q);
            card.style.display = coincide ? '' : 'none';
            if (coincide) visibles++;
        });

        let aviso = document.getElementById('sinResultadosLive');
        if (!aviso) {
            aviso = document.createElement('div');
            aviso.id = 'sinResultadosLive';
            aviso.style.cssText = 'grid-column:1/-1;text-align:center;padding:60px;color:var(--text-muted);display:none;';
            aviso.innerHTML = `
                <i class="bi bi-inbox" style="font-size:48px;display:block;margin-bottom:15px;"></i>
                <h3>No se encontraron productores</h3>
                <p>Intenta con otro nombre o finca.</p>`;
            document.getElementById('productoresGrid').appendChild(aviso);
        }
        aviso.style.display = (visibles === 0 && q) ? 'block' : 'none';
    }
})();

function verProductor(id) {
    document.getElementById('modalContenido').innerHTML =
        `<div class="modal-loading"><div class="spinner"></div><p>Cargando información...</p></div>`;
    document.getElementById('modalOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';

    fetch('?ajax_productor=' + id)
        .then(r => r.json())
        .then(data => renderModal(data))
        .catch(() => {
            document.getElementById('modalContenido').innerHTML =
                `<div class="modal-loading"><p>Error al cargar. Intenta de nuevo.</p></div>`;
        });
}

function cerrarModal(e) {
    if (e && e.target !== document.getElementById('modalOverlay')) return;
    document.getElementById('modalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
function cerrarModalBtn() {
    document.getElementById('modalOverlay').classList.remove('active');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { cerrarLightbox(); cerrarModalBtn(); }
});

function estrellas(n, size) {
    let html = '';
    for (let i = 1; i <= 5; i++)
        html += `<i class="bi bi-star${i <= Math.round(n) ? '-fill' : ''}" style="color:#ffc107;font-size:${size||14}px;"></i>`;
    return html;
}

function renderModal(d) {
    const p   = d.productor;
    const tel = p.telefono.replace(/[^0-9]/g, '');
    const cal = parseFloat(p.calificacion_promedio).toFixed(1);

    const fotoHtml = p.foto_perfil
        ? `<img src="../../${p.foto_perfil}" class="modal-foto" alt="foto">`
        : `<div class="modal-foto-placeholder"><i class="bi bi-person-circle"></i></div>`;

    const statsHtml = `
        <div class="m-stats">
            <div class="m-stat"><i class="bi bi-star-fill" style="color:#ffc107;"></i><div><div class="v">${cal}</div><div class="l">Calificación</div></div></div>
            <div class="m-stat"><i class="bi bi-chat-square-text"></i><div><div class="v">${p.total_resenas}</div><div class="l">Reseñas</div></div></div>
            <div class="m-stat"><i class="bi bi-box-seam"></i><div><div class="v">${p.total_productos}</div><div class="l">Productos</div></div></div>
            ${p.tipo_produccion ? `<div class="m-stat"><i class="bi bi-leaf"></i><div><div class="v" style="font-size:13px;">${escHtml(p.tipo_produccion)}</div><div class="l">Tipo producción</div></div></div>` : ''}
        </div>`;

    let infoHtml = `<div class="m-info-grid">`;
    if (p.ubicacion)       infoHtml += `<div class="m-info-item"><label>📍 Ubicación</label><span>${escHtml(p.ubicacion)}</span></div>`;
    if (p.tipo_produccion) infoHtml += `<div class="m-info-item"><label>🌱 Producción</label><span>${escHtml(p.tipo_produccion)}</span></div>`;
    infoHtml += `<div class="m-info-item"><label>✉️ Correo</label><span>${escHtml(p.email)}</span></div>`;
    infoHtml += `<div class="m-info-item"><label>📞 Teléfono</label><span>${escHtml(p.telefono)}</span></div>`;
    infoHtml += `</div>`;
    if (p.experiencia) infoHtml += `<div style="margin-top:14px;"><label style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:5px;">🏆 Experiencia</label><p style="color:#555;line-height:1.7;margin:0;">${escHtml(p.experiencia)}</p></div>`;

    let prodsHtml = '';
    if (d.productos.length > 0) {
        const totalReal = parseInt(d.total_productos_reales) || d.productos.length;
        prodsHtml = `
        <div class="m-seccion">
            <h4 style="justify-content:space-between;">
                <span><i class="bi bi-box-seam"></i> Productos destacados</span>
                <span style="font-size:12px;color:var(--text-muted);font-weight:400;">${totalReal} producto${totalReal !== 1 ? 's' : ''} en total</span>
            </h4>
            <div class="m-prods">`;
        d.productos.forEach(pr => {
            let imgHtml = '';
            if (pr.foto_producto)  imgHtml = `<img src="../../${pr.foto_producto}" alt="">`;
            else if (pr.imagen)    imgHtml = `<img src="../../${pr.imagen}" alt="">`;
            else                   imgHtml = `<i class="bi bi-image"></i>`;
            const mayorista = pr.precio_mayorista
                ? `<div class="m-prod-mayorista"><i class="bi bi-tag-fill"></i> Mayorista: $${fmtNum(pr.precio_mayorista)} (mín. ${pr.cantidad_minima_mayorista})</div>` : '';
            const estadoLbl = pr.estado === 'stock_bajo' ? '⚠️ Stock bajo' : '✅ Disponible';
            prodsHtml += `
            <div class="m-prod-card">
                <div class="m-prod-img">${imgHtml}</div>
                <div class="m-prod-body">
                    <div class="m-prod-nombre">${escHtml(pr.nombre)}</div>
                    <div class="m-prod-cat">${pr.icono_categoria ? `<i class="${pr.icono_categoria}" style="color:var(--primary);margin-right:3px;"></i>` : ''}${escHtml(pr.nombre_categoria || '')}</div>
                    <div class="m-prod-precio">$${fmtNum(pr.precio)}</div>
                    <div class="m-prod-unidad">por ${pr.unidad_medida} · ${pr.cantidad} disp.</div>
                    ${mayorista}
                    <span class="m-prod-estado estado-${pr.estado}">${estadoLbl}</span>
                </div>
            </div>`;
        });
        prodsHtml += `</div>
        <div style="margin-top:14px;text-align:center;">
            <a href="catalogo_comprador.php?productor=${p.id_usuario}" class="btn btn-primary"
               style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;">
                <i class="bi bi-shop"></i>
                Ver todos los productos de ${escHtml(p.nombre)}
                ${totalReal > 3 ? `<span style="background:rgba(255,255,255,.25);border-radius:20px;padding:1px 8px;font-size:12px;">+${totalReal - 3} más</span>` : ''}
            </a>
        </div></div>`;
    }

    let fotosHtml = '';
    if (d.fotos.length > 0) {
        fotosHtml = `<div class="m-seccion"><h4><i class="bi bi-images"></i> Fotos de la finca</h4><div class="m-fotos">`;
        d.fotos.forEach(f => {
            fotosHtml += `<div class="m-foto" onclick="abrirLightbox('../../${f.ruta_foto}')"><img src="../../${f.ruta_foto}" alt="${escHtml(f.descripcion||'')}"></div>`;
        });
        fotosHtml += `</div></div>`;
    }

    let resenasHtml = `<div class="m-seccion"><h4><i class="bi bi-star-half"></i> Calificaciones y reseñas</h4>`;
    if (d.resenas.length > 0) {
        const total = parseInt(p.total_resenas);
        let barras  = '';
        for (let estrella = 5; estrella >= 1; estrella--) {
            const cnt = d.distribucion[estrella] || 0;
            const pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
            barras += `<div class="m-barra-row">
                <span class="lbl">${estrella} <i class="bi bi-star-fill" style="color:#ffc107;font-size:10px;"></i></span>
                <div class="m-barra-bg"><div class="m-barra-fill" style="width:${pct}%;"></div></div>
                <span class="cnt">${cnt}</span>
            </div>`;
        }
        resenasHtml += `
        <div class="m-rating-wrap">
            <div class="m-rating-num">
                <div class="big">${cal}</div>
                <div class="stars">${estrellas(cal)}</div>
                <small>${total} reseñas</small>
            </div>
            <div class="m-barras">${barras}</div>
        </div>`;
        d.resenas.forEach(r => {
            const fecha = new Date(r.fecha_calificacion).toLocaleDateString('es-CO');
            resenasHtml += `
            <div class="m-resena">
                <div class="m-resena-header">
                    <div>
                        <div class="m-resena-autor"><i class="bi bi-person-circle" style="color:var(--primary);"></i> ${escHtml(r.comprador_nombre)}</div>
                        <div class="m-resena-stars">${estrellas(r.calificacion, 13)} <span style="color:var(--text-muted);font-size:12px;">${r.calificacion}/5</span></div>
                    </div>
                    <div class="m-resena-fecha">${fecha}</div>
                </div>
                <div class="m-resena-texto">${r.resena ? escHtml(r.resena) : '<em style="color:var(--text-muted);">Sin comentario.</em>'}</div>
            </div>`;
        });
    } else {
        resenasHtml += `<div style="text-align:center;padding:30px;color:var(--text-muted);"><i class="bi bi-chat-square" style="font-size:36px;display:block;margin-bottom:10px;"></i><p>Aún no tiene reseñas.</p></div>`;
    }
    resenasHtml += `</div>`;

    document.getElementById('modalContenido').innerHTML = `
        <div class="modal-portada">
            <div class="modal-foto-wrap">${fotoHtml}</div>
            <button class="modal-close" onclick="cerrarModalBtn()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
                <div>
                    <div class="modal-nombre">${escHtml(p.nombre)}</div>
                    <div class="modal-finca"><i class="bi bi-house-door-fill"></i> ${escHtml(p.nombre_finca || 'Sin nombre')}</div>
                    ${p.ubicacion ? `<div class="modal-ubicacion"><i class="bi bi-geo-alt-fill"></i> ${escHtml(p.ubicacion)}</div>` : ''}
                </div>
                <a href="https://wa.me/57${tel}?text=${encodeURIComponent('Hola, vi tu perfil en QuiliAgro y me gustaría saber más sobre tus productos 🌱')}"
                   target="_blank"
                   style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:8px;background:#25D366;color:white;text-decoration:none;font-weight:600;font-size:14px;flex-shrink:0;">
                    <i class="bi bi-whatsapp"></i> Contactar
                </a>
            </div>
            ${statsHtml}
            <div class="m-seccion">
                <h4><i class="bi bi-person-lines-fill"></i> Información</h4>
                ${p.descripcion ? `<p style="color:#555;line-height:1.7;margin-bottom:16px;">${escHtml(p.descripcion)}</p>` : ''}
                ${infoHtml}
            </div>
            ${prodsHtml}
            ${fotosHtml}
            ${resenasHtml}
        </div>`;
}

function escHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtNum(n) { return parseFloat(n).toLocaleString('es-CO'); }

function abrirLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('active');
}
function cerrarLightbox() {
    document.getElementById('lightbox').classList.remove('active');
}
</script>
</body>
</html>