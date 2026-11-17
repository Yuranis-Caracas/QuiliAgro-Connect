<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

$rol      = $_SESSION['user_rol'] ?? null;
$destinos = [
    'administrador' => '../admin/panel.php',
    'productor'     => '../productor/panel_productor.php',
    'comprador'     => '../comprador/panel_comprador.php',
];
$volver_url   = $destinos[$rol] ?? '../auth/login.php';
$volver_texto = $rol ? 'Volver al panel' : 'Regresar al inicio de sesión';

$stream_base = '/quiliagro_connect/vistas/ayuda/video_stream.php?f=';

try {
    $database = new Database();
    $pdo      = $database->getConnection();
    $stmt     = $pdo->query("SELECT * FROM tutoriales WHERE activo = 1 ORDER BY seccion, fecha_creacion ASC");
    $filas    = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $filas = [];
}

$meta = [
    'frecuentes' => ['titulo' => 'Preguntas Frecuentes', 'icono' => 'fa-circle-question', 'color' => 'linear-gradient(135deg,#20c997,#17a589)'],
    'productor'  => ['titulo' => 'Para Productores',     'icono' => 'fa-tractor',          'color' => 'linear-gradient(135deg,#28a745,#1e7e34)'],
    'comprador'  => ['titulo' => 'Para Compradores',     'icono' => 'fa-basket-shopping',  'color' => 'linear-gradient(135deg,#d4ac0d,#b7950b)'],
];

$secciones = ['frecuentes' => [], 'productor' => [], 'comprador' => []];
foreach ($filas as $f) {
    if (isset($secciones[$f['seccion']])) $secciones[$f['seccion']][] = $f;
}

$hay_contenido           = count(array_merge(...array_values($secciones))) > 0;
$secciones_con_contenido = array_keys(array_filter($secciones, fn($v) => !empty($v)));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#28a745">
    <link rel="icon" type="image/png" sizes="192x192" href="/quiliagro_connect/imagenes/icon-192x192.png">
    <link rel="apple-touch-icon" href="/quiliagro_connect/imagenes/apple-touch-icon.png">
    <title>Centro de Ayuda — QuiliAgro Connect</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:linear-gradient(135deg,#e4e5e9 0%,#edebf0 100%);
            min-height:100vh; color:#2c3e50;
        }

        .page-header { background:linear-gradient(135deg,#28a745 0%,#20c997 100%); color:white; padding:30px 20px 28px; text-align:center; }
        .header-logo { width:90px; height:90px; object-fit:contain; border-radius:50%; background:white; padding:8px; box-shadow:0 5px 15px rgba(0,0,0,.2); border:4px solid rgba(255,255,255,.35); margin:0 auto 14px; display:block; }
        .page-header h1 { font-size:clamp(22px,5vw,30px); font-weight:700; margin-bottom:6px; }
        .page-header p  { font-size:14px; opacity:.88; }

        .filtros-wrap { max-width:900px; margin:0 auto; padding:20px 16px 0; }

        .filtro-buscar { position:relative; margin-bottom:12px; }
        .filtro-buscar i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#adb5bd; font-size:14px; pointer-events:none; }
        .filtro-buscar input { width:100%; padding:11px 14px 11px 38px; border:2px solid #e0e0e0; border-radius:10px; font-size:14px; outline:none; transition:border-color .2s; background:white; }
        .filtro-buscar input:focus { border-color:#28a745; }

        .chips-wrap { display:flex; gap:8px; flex-wrap:wrap; }
        .chip { padding:7px 16px; border-radius:20px; font-size:13px; font-weight:600; cursor:pointer; border:2px solid #e0e0e0; background:white; color:#666; transition:all .2s; white-space:nowrap; user-select:none; }
        .chip.active { background:#28a745; color:white; border-color:#28a745; }
        .chip:hover:not(.active) { border-color:#28a745; color:#28a745; }

        .contador-resultados { max-width:900px; margin:10px auto 0; padding:0 16px; font-size:13px; color:#6c757d; min-height:20px; }

        .main { max-width:900px; margin:0 auto; padding:18px 16px 60px; }

        .seccion { background:white; border-radius:15px; box-shadow:0 8px 25px rgba(0,0,0,.10); margin-bottom:28px; overflow:hidden; }
        .seccion.oculta { display:none; }
        .seccion-header { padding:16px 22px; display:flex; align-items:center; gap:12px; }
        .sec-icon { width:42px; height:42px; border-radius:10px; background:rgba(255,255,255,.25); display:flex; align-items:center; justify-content:center; font-size:18px; color:white; flex-shrink:0; }
        .seccion-header h2 { font-size:17px; font-weight:700; color:white; margin:0; }
        .badge-sec { margin-left:auto; background:rgba(255,255,255,.25); color:white; font-size:12px; font-weight:600; padding:3px 12px; border-radius:20px; white-space:nowrap; }

        .videos-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:18px; padding:20px; }

        .video-card { border-radius:12px; border:2px solid #f0f0f0; overflow:hidden; cursor:pointer; transition:transform .25s,box-shadow .25s,border-color .25s; background:#fafafa; }
        .video-card:hover { transform:translateY(-4px); box-shadow:0 10px 28px rgba(40,167,69,.18); border-color:#28a745; }
        .video-card.oculta { display:none; }

        .thumb { position:relative; aspect-ratio:16/9; background:#e8f5e9; overflow:hidden; }
        .thumb .thumb-video { width:100%; height:100%; object-fit:cover; display:block; pointer-events:none; }
        .play-overlay { position:absolute; inset:0; background:rgba(0,0,0,.20); display:flex; align-items:center; justify-content:center; transition:background .2s; }
        .video-card:hover .play-overlay { background:rgba(0,0,0,.38); }
        .play-overlay i { font-size:42px; color:rgba(255,255,255,.95); filter:drop-shadow(0 2px 6px rgba(0,0,0,.5)); transition:transform .2s; }
        .video-card:hover .play-overlay i { transform:scale(1.15); }
        .duration-badge { position:absolute; bottom:6px; right:8px; background:rgba(0,0,0,.72); color:white; font-size:11px; font-weight:700; padding:2px 7px; border-radius:4px; font-variant-numeric:tabular-nums; }

        .card-body { padding:10px 12px 12px; display:flex; align-items:flex-start; gap:8px; }
        .play-btn { width:28px; height:28px; border-radius:50%; flex-shrink:0; margin-top:1px; background:linear-gradient(135deg,#28a745,#20c997); display:flex; align-items:center; justify-content:center; }
        .play-btn i { font-size:11px; color:white; margin-left:2px; }
        .card-titulo { font-size:12.5px; font-weight:600; color:#2c3e50; line-height:1.35; }

        .modal-video { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85); z-index:9999; align-items:center; justify-content:center; padding:16px; }
        .modal-video.show { display:flex; }
        .modal-inner { background:#111; border-radius:16px; width:100%; max-width:820px; overflow:hidden; position:relative; box-shadow:0 30px 70px rgba(0,0,0,.6); animation:modalIn .25s ease; }
        @keyframes modalIn { from{opacity:0;transform:scale(.94)} to{opacity:1;transform:scale(1)} }
        .modal-inner video { width:100%; display:block; max-height:65vh; background:#000; }
        .modal-info { background:white; padding:16px 20px; }
        .modal-info h3 { font-size:16px; font-weight:700; color:#28a745; margin-bottom:4px; }
        .modal-info p  { font-size:13px; color:#6c757d; line-height:1.5; }
        .btn-cerrar { position:absolute; top:10px; right:12px; background:rgba(0,0,0,.55); color:white; border:none; border-radius:50%; width:36px; height:36px; font-size:20px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .2s; z-index:2; }
        .btn-cerrar:hover { background:rgba(0,0,0,.85); }
        .video-loading { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:#000; z-index:1; }
        .video-loading.oculto { display:none; }
        .spinner { width:44px; height:44px; border-radius:50%; border:4px solid rgba(255,255,255,.15); border-top-color:#28a745; animation:spin .8s linear infinite; }
        @keyframes spin { to{transform:rotate(360deg)} }

        .sin-resultados { display:none; background:white; border-radius:15px; padding:60px 30px; text-align:center; color:#adb5bd; box-shadow:0 8px 25px rgba(0,0,0,.08); margin-bottom:28px; }
        .sin-resultados.show { display:block; }
        .sin-resultados i { font-size:48px; display:block; margin-bottom:12px; opacity:.3; }
        .sin-resultados h3 { font-size:18px; font-weight:700; color:#6c757d; margin-bottom:6px; }

        .empty-state { background:white; border-radius:15px; text-align:center; padding:60px 30px; color:#adb5bd; }
        .empty-state i { font-size:56px; display:block; margin-bottom:16px; opacity:.3; }
        .empty-state h3 { font-weight:700; font-size:18px; margin-bottom:8px; color:#6c757d; }

        .page-footer { text-align:center; padding:20px; }
        .btn-volver { background:linear-gradient(135deg,#28a745,#20c997); color:white; border:none; padding:13px 28px; border-radius:10px; font-size:15px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:8px; cursor:pointer; transition:transform .2s,box-shadow .2s; box-shadow:0 4px 14px rgba(40,167,69,.35); }
        .btn-volver:hover { transform:translateY(-2px); box-shadow:0 8px 22px rgba(40,167,69,.45); }

        @media (max-width:500px) {
            .videos-grid { grid-template-columns:1fr 1fr; gap:12px; padding:14px; }
            .chips-wrap { gap:6px; }
        }
        @media (max-width:320px) { .videos-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<header class="page-header">
    <img src="/quiliagro_connect/imagenes/logo.png" alt="QuiliAgro Connect" class="header-logo">
    <h1><i class="fas fa-circle-question" style="margin-right:8px;opacity:.9;"></i>Centro de Ayuda</h1>
    <p>Bienvenid@. Aquí encontrarás tutoriales para guiarte en cada paso</p>
</header>

<?php if ($hay_contenido): ?>
<div class="filtros-wrap">
    <div class="filtro-buscar">
        <i class="fas fa-search"></i>
        <input type="text" id="buscadorAyuda" placeholder="Buscar tutorial..." oninput="filtrarAyuda()">
    </div>
    <div class="chips-wrap">
        <span class="chip active" data-sec="todas" onclick="filtrarChip(this)">
            <i class="fas fa-border-all" style="margin-right:5px;"></i>Todos
        </span>
        <?php foreach ($secciones_con_contenido as $sk): ?>
        <span class="chip" data-sec="<?= $sk ?>" onclick="filtrarChip(this)">
            <i class="fas <?= $meta[$sk]['icono'] ?>" style="margin-right:5px;"></i>
            <?= htmlspecialchars($meta[$sk]['titulo']) ?>
        </span>
        <?php endforeach; ?>
    </div>
</div>
<div class="contador-resultados"><span id="contadorRes"></span></div>
<?php endif; ?>

<main class="main">

<?php if (!$hay_contenido): ?>
    <div class="empty-state">
        <i class="fas fa-play-circle"></i>
        <h3>Los tutoriales estarán disponibles pronto</h3>
        <p>El administrador está preparando el contenido de ayuda.</p>
    </div>
<?php else: ?>

    <div class="sin-resultados" id="sinResultados">
        <i class="fas fa-magnifying-glass"></i>
        <h3>Sin resultados</h3>
        <p>No se encontraron tutoriales con esos criterios.</p>
    </div>

    <?php foreach ($secciones as $sec_key => $tutoriales):
        if (empty($tutoriales)) continue;
        $m = $meta[$sec_key];
    ?>
    <div class="seccion" data-seccion="<?= $sec_key ?>" id="seccion-<?= $sec_key ?>">
        <div class="seccion-header" style="background:<?= $m['color'] ?>;">
            <div class="sec-icon"><i class="fas <?= $m['icono'] ?>"></i></div>
            <h2><?= htmlspecialchars($m['titulo']) ?></h2>
            <span class="badge-sec" id="badge-<?= $sec_key ?>">
                <?= count($tutoriales) ?> tutorial<?= count($tutoriales) !== 1 ? 'es' : '' ?>
            </span>
        </div>

        <div class="videos-grid" id="grid-<?= $sec_key ?>">
            <?php foreach ($tutoriales as $t):
                $streamUrl = htmlspecialchars($stream_base . urlencode($t['video']));
                $modalId   = 'modal-' . $t['id_tutorial'];
            ?>
            <div class="video-card"
                 id="card-<?= $t['id_tutorial'] ?>"
                 data-titulo="<?= strtolower(htmlspecialchars($t['titulo'])) ?>"
                 data-seccion="<?= $sec_key ?>"
                 onclick="abrirModal('<?= $modalId ?>')">

                <div class="thumb">
                    <video class="thumb-video" preload="metadata" muted playsinline
                           id="thumb-<?= $t['id_tutorial'] ?>">
                        <source src="<?= $streamUrl ?>#t=0.1" type="video/mp4">
                    </video>
                    <div class="play-overlay"><i class="fas fa-circle-play"></i></div>
                    <span class="duration-badge" id="dur-<?= $t['id_tutorial'] ?>">--:--</span>
                </div>

                <div class="card-body">
                    <div class="play-btn"><i class="fas fa-play"></i></div>
                    <span class="card-titulo"><?= htmlspecialchars($t['titulo']) ?></span>
                </div>
            </div>

            <div class="modal-video" id="<?= $modalId ?>">
                <div class="modal-inner">
                    <button class="btn-cerrar" onclick="cerrarModal('<?= $modalId ?>')">&times;</button>
                    <div class="video-loading" id="loading-<?= $t['id_tutorial'] ?>"><div class="spinner"></div></div>
                    <video controls preload="metadata"
                           id="vid-<?= $t['id_tutorial'] ?>"
                           style="position:relative;z-index:2;"
                           oncanplay="ocultarSpinner(<?= $t['id_tutorial'] ?>)"
                           onloadstart="mostrarSpinner(<?= $t['id_tutorial'] ?>)">
                        <source src="<?= $streamUrl ?>" type="video/mp4">
                        Tu navegador no soporta video HTML5.
                    </video>
                    <div class="modal-info">
                        <h3><i class="fas fa-circle-play" style="margin-right:6px;font-size:14px;"></i><?= htmlspecialchars($t['titulo']) ?></h3>
                        <?php if (!empty($t['descripcion'])): ?>
                            <p><?= htmlspecialchars($t['descripcion']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

<?php endif; ?>

    <div class="page-footer">
        <a href="<?= htmlspecialchars($volver_url) ?>" class="btn-volver">
            <i class="fas fa-arrow-left"></i>
            <?= htmlspecialchars($volver_texto) ?>
        </a>
    </div>

    <footer style="text-align: center; padding: 14px 20px; font-size: 12px; color: #888; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; border-top: 1px solid #eee;">
            &copy; <?php echo date('Y'); ?> QuiliAgro Connect &mdash; Todos los derechos reservados &mdash; NIT: 900.123.456-7
    </footer>
</main>

<script>
document.querySelectorAll('.thumb-video').forEach(vid => {
    function capturar() { vid.currentTime = 0.5; }
    if (vid.readyState >= 1) capturar();
    else vid.addEventListener('loadedmetadata', capturar, {once:true});
    vid.addEventListener('loadedmetadata', () => {
        const badge = vid.closest('.thumb')?.querySelector('.duration-badge');
        if (badge) badge.textContent = formatDur(vid.duration);
    }, {once:true});
});

function formatDur(s) {
    if (!s || isNaN(s)) return '';
    return Math.floor(s/60) + ':' + Math.floor(s%60).toString().padStart(2,'0');
}

function mostrarSpinner(id) { const e=document.getElementById('loading-'+id); if(e) e.classList.remove('oculto'); }
function ocultarSpinner(id) { const e=document.getElementById('loading-'+id); if(e) e.classList.add('oculto'); }

function abrirModal(id)  { document.getElementById(id).classList.add('show'); document.body.style.overflow='hidden'; }
function cerrarModal(id) {
    const m=document.getElementById(id); m.classList.remove('show');
    document.body.style.overflow='';
    const vid=m.querySelector('video'); if(vid){ vid.pause(); vid.currentTime=0; }
}
document.querySelectorAll('.modal-video').forEach(m => {
    m.addEventListener('click', e => { if(e.target===m) cerrarModal(m.id); });
});
document.addEventListener('keydown', e => {
    if(e.key==='Escape') document.querySelectorAll('.modal-video.show').forEach(m=>cerrarModal(m.id));
});

let _secActiva = 'todas';
let _busqueda  = '';

function filtrarAyuda() {
    _busqueda = document.getElementById('buscadorAyuda').value.toLowerCase().trim();
    aplicarFiltros();
}

function filtrarChip(chip) {
    _secActiva = chip.dataset.sec;
    document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    aplicarFiltros();
}

function aplicarFiltros() {
    let totalVisible = 0;

    document.querySelectorAll('.seccion[data-seccion]').forEach(secBlock => {
        const sec      = secBlock.dataset.seccion;
        const secMatch = _secActiva === 'todas' || _secActiva === sec;
        let countEnSec = 0;

        secBlock.querySelectorAll('.video-card').forEach(card => {
            const matchBusq = !_busqueda || (card.dataset.titulo||'').includes(_busqueda);
            const visible   = secMatch && matchBusq;
            card.classList.toggle('oculta', !visible);
            if (visible) { countEnSec++; totalVisible++; }
        });

        secBlock.classList.toggle('oculta', countEnSec === 0);
        const badge = document.getElementById('badge-' + sec);
        if (badge) badge.textContent = countEnSec + ' tutorial' + (countEnSec !== 1 ? 'es' : '');
    });

    const sinRes = document.getElementById('sinResultados');
    if (sinRes) sinRes.classList.toggle('show', totalVisible === 0);

    const contador = document.getElementById('contadorRes');
    if (contador) {
        contador.textContent = totalVisible > 0
            ? totalVisible + ' tutorial' + (totalVisible !== 1 ? 'es' : '') + ' encontrado' + (totalVisible !== 1 ? 's' : '')
            : '';
    }
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/quiliagro_connect/js/service-worker.js').catch(()=>{});
}
</script>
</body>
</html>