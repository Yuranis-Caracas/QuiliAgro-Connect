<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'administrador') {
    header("Location: ../auth/login.php");
    exit();
}

$usuario_id     = intval($_SESSION['user_id']);
$usuario_nombre = htmlspecialchars($_SESSION['user_nombre']);

$stream_base = '/quiliagro_connect/vistas/ayuda/video_stream.php?f=';
$ruta_fisica = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/quiliagro_connect/uploads/tutoriales/';

$mensaje      = '';
$tipo_mensaje = '';

try {
    $database = new Database();
    $pdo      = $database->getConnection();
} catch (Exception $e) {
    die('Error de conexión a la base de datos.');
}

function slugify(string $titulo, string $ext): string {
    $s = mb_strtolower($titulo, 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    $s = substr($s, 0, 60);
    return $s . '_' . time() . '.' . $ext;
}

function registrarAuditoria($pdo, $id_admin, $accion, $tabla, $id_reg, $desc) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $pdo->prepare("INSERT INTO auditoria_admin (id_administrador,accion,tabla_afectada,id_registro_afectado,descripcion,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$id_admin, $accion, $tabla, $id_reg, $desc, $ip]);
    } catch (Exception $e) { error_log('Auditoría: ' . $e->getMessage()); }
}

function videoExiste(string $base, string $archivo): bool {
    return !empty($archivo) && file_exists(rtrim($base, '/') . '/' . $archivo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_tutorial') {
        $titulo      = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $seccion     = $_POST['seccion'] ?? 'frecuentes';
        $video_nombre = '';

        if (empty($titulo)) {
            $mensaje = 'El título es obligatorio.'; $tipo_mensaje = 'danger';
        } elseif (!in_array($seccion, ['frecuentes', 'productor', 'comprador'])) {
            $mensaje = 'Sección no válida.'; $tipo_mensaje = 'danger';
        } elseif (empty($_FILES['video']['name'])) {
            $mensaje = 'El video es obligatorio.'; $tipo_mensaje = 'danger';
        } else {
            $ext        = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
            $permitidos = ['mp4', 'webm', 'ogg'];
            if (!in_array($ext, $permitidos)) {
                $mensaje = 'Solo se permiten MP4, WEBM u OGG.'; $tipo_mensaje = 'danger';
            } elseif ($_FILES['video']['size'] > 100 * 1024 * 1024) {
                $mensaje = 'El video no puede superar 100 MB.'; $tipo_mensaje = 'danger';
            } elseif ($_FILES['video']['error'] !== UPLOAD_ERR_OK) {
                $errores = [
                    UPLOAD_ERR_INI_SIZE   => 'El archivo supera upload_max_filesize.',
                    UPLOAD_ERR_FORM_SIZE  => 'El archivo supera MAX_FILE_SIZE.',
                    UPLOAD_ERR_PARTIAL    => 'Se subió parcialmente.',
                    UPLOAD_ERR_NO_FILE    => 'No se subió ningún archivo.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal.',
                    UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir en disco.',
                ];
                $mensaje = $errores[$_FILES['video']['error']] ?? 'Error desconocido.';
                $tipo_mensaje = 'danger';
            } else {
                if (!is_dir($ruta_fisica)) mkdir($ruta_fisica, 0755, true);
                $video_nombre = slugify($titulo, $ext);
                if (!move_uploaded_file($_FILES['video']['tmp_name'], $ruta_fisica . $video_nombre)) {
                    $mensaje = 'Error al mover el video. Verifica permisos de uploads/tutoriales/';
                    $tipo_mensaje = 'danger'; $video_nombre = '';
                }
            }
            if (empty($mensaje)) {
                $stmt = $pdo->prepare("INSERT INTO tutoriales (titulo, descripcion, video, seccion, activo) VALUES (?, ?, ?, ?, 1)");
                $stmt->execute([$titulo, $descripcion, $video_nombre, $seccion]);
                $mensaje = 'Tutorial creado exitosamente.'; $tipo_mensaje = 'success';
                registrarAuditoria($pdo, $usuario_id, 'crear_tutorial', 'tutoriales', null, "Creó tutorial: $titulo");
            }
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => ($tipo_mensaje === 'success'), 'mensaje' => $mensaje]);
            exit();
        }
    }

    if ($accion === 'editar_tutorial') {
        $id          = intval($_POST['id_tutorial'] ?? 0);
        $titulo      = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $seccion     = $_POST['seccion'] ?? 'frecuentes';
        $activo      = isset($_POST['activo']) ? 1 : 0;

        if (empty($titulo) || $id <= 0) {
            $mensaje = 'Datos incompletos.'; $tipo_mensaje = 'danger';
        } elseif (!in_array($seccion, ['frecuentes', 'productor', 'comprador'])) {
            $mensaje = 'Sección no válida.'; $tipo_mensaje = 'danger';
        } else {
            $video_nombre_nuevo = null;
            if (!empty($_FILES['video_edit']['name'])) {
                $ext        = strtolower(pathinfo($_FILES['video_edit']['name'], PATHINFO_EXTENSION));
                $permitidos = ['mp4', 'webm', 'ogg'];
                if (!in_array($ext, $permitidos)) {
                    $mensaje = 'Solo MP4, WEBM u OGG.'; $tipo_mensaje = 'danger';
                } elseif ($_FILES['video_edit']['size'] > 100 * 1024 * 1024) {
                    $mensaje = 'El video no puede superar 100 MB.'; $tipo_mensaje = 'danger';
                } elseif ($_FILES['video_edit']['error'] !== UPLOAD_ERR_OK) {
                    $mensaje = 'Error al subir.'; $tipo_mensaje = 'danger';
                } else {
                    if (!is_dir($ruta_fisica)) mkdir($ruta_fisica, 0755, true);
                    $video_nombre_nuevo = slugify($titulo, $ext);
                    if (!move_uploaded_file($_FILES['video_edit']['tmp_name'], $ruta_fisica . $video_nombre_nuevo)) {
                        $mensaje = 'Error al mover el video.'; $tipo_mensaje = 'danger'; $video_nombre_nuevo = null;
                    }
                }
            }
            if (empty($mensaje)) {
                if ($video_nombre_nuevo !== null) {
                    $old = $pdo->prepare("SELECT video FROM tutoriales WHERE id_tutorial=?");
                    $old->execute([$id]);
                    $row_old = $old->fetch(PDO::FETCH_ASSOC);
                    if ($row_old && !empty($row_old['video'])) {
                        $archivoViejo = $ruta_fisica . $row_old['video'];
                        if (file_exists($archivoViejo)) unlink($archivoViejo);
                    }
                    $stmt = $pdo->prepare("UPDATE tutoriales SET titulo=?, descripcion=?, seccion=?, activo=?, video=? WHERE id_tutorial=?");
                    $stmt->execute([$titulo, $descripcion, $seccion, $activo, $video_nombre_nuevo, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE tutoriales SET titulo=?, descripcion=?, seccion=?, activo=? WHERE id_tutorial=?");
                    $stmt->execute([$titulo, $descripcion, $seccion, $activo, $id]);
                }
                $mensaje = 'Tutorial actualizado.'; $tipo_mensaje = 'success';
                registrarAuditoria($pdo, $usuario_id, 'editar_tutorial', 'tutoriales', $id, "Editó tutorial: $titulo");
            }
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => ($tipo_mensaje === 'success'), 'mensaje' => $mensaje]);
            exit();
        }
    }

    if ($accion === 'eliminar_tutorial') {
        $id = intval($_POST['id_tutorial'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT titulo, video FROM tutoriales WHERE id_tutorial=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!empty($row['video'])) {
                    $archivo = $ruta_fisica . $row['video'];
                    if (file_exists($archivo)) unlink($archivo);
                }
                $pdo->prepare("DELETE FROM tutoriales WHERE id_tutorial=?")->execute([$id]);
                $mensaje = 'Tutorial eliminado.'; $tipo_mensaje = 'success';
                registrarAuditoria($pdo, $usuario_id, 'eliminar_tutorial', 'tutoriales', $id, "Eliminó: {$row['titulo']}");
            } else {
                $mensaje = 'Tutorial no encontrado.'; $tipo_mensaje = 'danger';
            }
        }
    }

    if ($accion === 'toggle_activo') {
        $id     = intval($_POST['id_tutorial'] ?? 0);
        $activo = intval($_POST['activo_actual'] ?? 1) === 1 ? 0 : 1;
        if ($id > 0) {
            $pdo->prepare("UPDATE tutoriales SET activo=? WHERE id_tutorial=?")->execute([$activo, $id]);
            $estado = $activo ? 'activado' : 'desactivado';
            $mensaje = "Tutorial $estado."; $tipo_mensaje = 'success';
            registrarAuditoria($pdo, $usuario_id, 'toggle_tutorial', 'tutoriales', $id, "Estado: $estado");
        }
    }
}

$stmt       = $pdo->query("SELECT * FROM tutoriales ORDER BY seccion, fecha_creacion ASC");
$tutoriales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total     = count($tutoriales);
$activos   = count(array_filter($tutoriales, fn($t) => $t['activo'] == 1));
$inactivos = $total - $activos;

$meta = [
    'frecuentes' => ['titulo' => 'Preguntas Frecuentes', 'icono' => 'bi-question-circle'],
    'productor'  => ['titulo' => 'Productor',            'icono' => 'bi-person-badge'],
    'comprador'  => ['titulo' => 'Comprador',            'icono' => 'bi-bag'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Ayuda — QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .seccion-bloque { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(40,167,69,.08); margin-bottom:28px; overflow:hidden; }
        .seccion-bloque-header { background:var(--primary,#28a745); color:#fff; padding:14px 22px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .seccion-bloque-header h3 { font-size:17px; font-weight:700; margin:0; display:flex; align-items:center; gap:8px; }
        .badge-count { background:rgba(255,255,255,.25); border-radius:20px; padding:2px 10px; font-size:13px; font-weight:600; }

        .filtros-bar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:24px; margin-top:20px; }
        .filtros-bar-left { display:flex; gap:10px; flex:1; flex-wrap:wrap; min-width:0; }
        .buscador-wrap { position:relative; flex:1; min-width:200px; max-width:320px; }
        .buscador-wrap .bi-search { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#adb5bd; font-size:15px; pointer-events:none; }
        .buscador-wrap input { padding-left:36px; }

        .chips-seccion { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .chip-sec { padding:6px 14px; border-radius:20px; font-size:13px; font-weight:600; cursor:pointer; border:2px solid #e0e0e0; background:#fff; color:#666; transition:all .2s; white-space:nowrap; }
        .chip-sec.active { background:#28a745; color:#fff; border-color:#28a745; }
        .chip-sec:hover:not(.active) { border-color:#28a745; color:#28a745; }

        .tutoriales-table { width:100%; border-collapse:collapse; font-size:14px; }
        .tutoriales-table thead th { background:#f8fdf9; color:var(--primary,#28a745); font-weight:700; padding:12px 16px; text-align:left; border-bottom:2px solid #e8f5e9; font-size:12px; text-transform:uppercase; letter-spacing:.5px; }
        .tutoriales-table tbody tr { border-bottom:1px solid #f0f7f0; transition:background .15s; }
        .tutoriales-table tbody tr:hover { background:rgba(40,167,69,.05); }
        .tutoriales-table tbody tr.inactivo { opacity:.55; }
        .tutoriales-table td { padding:14px 16px; vertical-align:middle; }

        .video-thumb { width:110px; height:66px; border-radius:8px; background:#e8f5e9; display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; border:1px solid #c8e6c9; cursor:pointer; position:relative; }
        .video-thumb .thumb-vid { width:100%; height:100%; object-fit:cover; pointer-events:none; }
        .video-thumb .play-over { position:absolute; inset:0; background:rgba(0,0,0,.22); display:flex; align-items:center; justify-content:center; transition:background .15s; }
        .video-thumb:hover .play-over { background:rgba(0,0,0,.38); }
        .video-thumb .play-over i { font-size:24px; color:rgba(255,255,255,.95); }
        .video-thumb .dur-badge { position:absolute; bottom:4px; right:5px; background:rgba(0,0,0,.7); color:#fff; font-size:10px; font-weight:700; padding:1px 5px; border-radius:3px; }
        .sin-video-thumb { width:110px; height:66px; border-radius:8px; background:#f1f3f5; border:1px dashed #ccc; display:flex; align-items:center; justify-content:center; flex-shrink:0; }

        .estado-toggle { display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; cursor:pointer; padding:4px 10px; border-radius:20px; border:none; transition:background .2s; }
        .estado-toggle.activo   { background:#d4edda; color:#155724; }
        .estado-toggle.inactivo { background:#f8d7da; color:#721c24; }
        .estado-toggle:hover { filter:brightness(.92); }

        .acciones-td { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        .btn-icon { width:34px; height:34px; border:none; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:15px; transition:opacity .2s, transform .15s; }
        .btn-icon:hover { opacity:.82; transform:translateY(-1px); }
        .btn-icon.edit   { background:#fff3cd; color:#856404; }
        .btn-icon.delete { background:#f8d7da; color:#842029; }

        .dropzone-video { border:2px dashed var(--primary,#28a745); border-radius:var(--radius,10px); padding:30px 20px; text-align:center; background:var(--bg-light,#f8fdf9); cursor:pointer; transition:background .2s,border-color .2s; position:relative; display:block; }
        .dropzone-video:hover { background:rgba(40,167,69,.10); border-color:#1e7e34; }
        .dropzone-video.error-border { border-color:#dc3545; background:#fff5f5; }
        .dropzone-video input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
        .dropzone-video i { font-size:44px; color:var(--primary,#28a745); display:block; margin-bottom:10px; pointer-events:none; }
        .dropzone-video strong { display:block; font-size:15px; margin-bottom:6px; color:#2c3e50; pointer-events:none; }
        .dropzone-video p { margin:0; font-size:13px; color:#6c757d; pointer-events:none; }

        .peso-indicador { display:none; margin-top:10px; padding:8px 14px; border-radius:8px; font-size:13px; font-weight:600; align-items:center; gap:8px; }
        .peso-indicador.ok    { background:#d4edda; color:#155724; display:flex; }
        .peso-indicador.error { background:#f8d7da; color:#842029; display:flex; }

        #uploadProgressWrap, #uploadProgressWrapEdit { display:none; margin:12px 0 6px; background:#f8fdf9; border:1px solid #c8e6c9; border-radius:10px; padding:14px 16px; }
        .progress-header { display:flex; justify-content:space-between; align-items:center; font-size:13px; font-weight:600; color:#155724; margin-bottom:8px; }
        .progress-track { background:#e8f5e9; border-radius:20px; height:12px; overflow:hidden; }
        .progress-fill { height:100%; border-radius:20px; width:0%; background:linear-gradient(90deg,#28a745,#20c95a); transition:width .4s ease; }
        .progress-sub { font-size:11px; color:#6c757d; margin-top:5px; text-align:center; }
        .upload-success-msg { display:none; background:#d4edda; border-left:4px solid #28a745; border-radius:8px; padding:10px 14px; font-size:13px; color:#155724; align-items:center; gap:8px; margin-top:8px; }
        .upload-error-msg   { display:none; background:#f8d7da; border-left:4px solid #dc3545; border-radius:8px; padding:10px 14px; font-size:13px; color:#842029; align-items:center; gap:8px; margin-top:8px; }

        .video-actual-wrap { background:#f8fdf9; border:1px solid #c8e6c9; border-radius:10px; padding:12px 16px; margin-bottom:12px; display:flex; align-items:center; gap:14px; }
        .video-actual-wrap video { width:130px; height:76px; border-radius:8px; object-fit:cover; border:1px solid #c8e6c9; flex-shrink:0; }
        .video-actual-info { font-size:13px; color:#155724; }
        .video-actual-info strong { display:block; margin-bottom:4px; }
        .video-actual-info .dur-info { font-weight:700; color:#28a745; }

        @keyframes spin { to { transform:rotate(360deg); } }
        .spinner-btn { display:inline-block; width:14px; height:14px; border:2px solid rgba(255,255,255,.35); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; margin-right:6px; vertical-align:middle; }

        .alert { padding:13px 18px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; }
        .alert-success { background:#d4edda; color:#155724; border-left:4px solid #28a745; }
        .alert-danger  { background:#f8d7da; color:#842029; border-left:4px solid #dc3545; }

        .btn { padding:10px 20px; border-radius:8px; border:none; font-size:14px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:7px; transition:opacity .2s, transform .15s; text-decoration:none; }
        .btn:hover { opacity:.88; transform:translateY(-1px); }
        .btn-primary   { background:var(--primary,#28a745); color:#fff; }
        .btn-secondary { background:#e9ecef; color:#495057; }
        .btn:disabled { opacity:.65; cursor:not-allowed; transform:none; }

        .modal-video-preview { display:none; position:fixed; inset:0; background:rgba(0,0,0,.82); z-index:9999; align-items:center; justify-content:center; padding:20px; }
        .modal-video-preview.show { display:flex; }
        .modal-video-inner { background:#111; border-radius:14px; width:100%; max-width:800px; overflow:hidden; position:relative; }
        .modal-video-inner video { width:100%; display:block; max-height:72vh; }
        .btn-close-video { position:absolute; top:10px; right:12px; background:rgba(0,0,0,.5); border:none; color:#fff; width:34px; height:34px; border-radius:50%; font-size:20px; cursor:pointer; display:flex; align-items:center; justify-content:center; z-index:2; }
        .preview-spinner { position:absolute; inset:0; background:#000; display:flex; align-items:center; justify-content:center; z-index:1; }
        .preview-spinner.oculto { display:none; }
        .spin-ring { width:44px; height:44px; border-radius:50%; border:4px solid rgba(255,255,255,.15); border-top-color:#28a745; animation:spin .8s linear infinite; }
        .modal-video-preview-info { background:white; padding:12px 18px; }
        .modal-video-preview-info .dur-text { font-size:13px; color:#28a745; font-weight:700; }

        .empty-seccion { padding:40px 20px; text-align:center; color:#adb5bd; }
        .empty-seccion i { font-size:40px; display:block; margin-bottom:10px; }

        @media (max-width:768px) {
            .tutoriales-table thead { display:none; }
            .tutoriales-table tbody tr { display:block; background:#fff; border:1px solid #e8f5e9; border-radius:12px; margin:0 12px 14px; overflow:hidden; box-shadow:0 2px 8px rgba(40,167,69,.07); }
            .tutoriales-table td { display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border-bottom:1px solid #f0f7f0; font-size:13px; }
            .tutoriales-table td::before { content:attr(data-label); font-weight:700; color:#28a745; font-size:11px; text-transform:uppercase; min-width:90px; flex-shrink:0; padding-top:2px; }
            .stats-grid { grid-template-columns:repeat(2,1fr); }
            .filtros-bar { flex-direction:column; align-items:stretch; }
            .filtros-bar-left { flex-direction:column; }
            .buscador-wrap { max-width:100%; }
            .filtros-bar .btn { width:100%; justify-content:center; }
        }

         body.modal-open { overflow:hidden; }
        .modal-upload-lock { position:absolute; inset:0; background:rgba(255,255,255,.6); z-index:10; border-radius:inherit; display:none; align-items:center; justify-content:center; font-size:14px; font-weight:600; color:#155724; gap:10px; flex-direction:column; }
        .modal-upload-lock.active { display:flex; }
        .modal-content { position:relative; }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleMenu()"><i class="bi bi-list"></i></button>

<div class="modal-video-preview" id="modalVideoPreview">
    <div class="modal-video-inner">
        <button class="btn-close-video" onclick="cerrarPreviewVideo()">&times;</button>
        <div class="preview-spinner" id="previewSpinner"><div class="spin-ring"></div></div>
        <video controls id="videoPreviewEl" preload="metadata" playsinline
               oncanplay="document.getElementById('previewSpinner').classList.add('oculto')"
               onloadstart="document.getElementById('previewSpinner').classList.remove('oculto')"
               style="position:relative;z-index:2;"></video>
        <div class="modal-video-preview-info">
            <span class="dur-text" id="previewDurText"></span>
        </div>
    </div>
</div>

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
            <a href="reportes.php"><i class="bi bi-bar-chart"></i> Reportes</a>
            <a href="auditoria_admin.php"><i class="bi bi-shield-check"></i> Auditoría</a>
            <a href="ayudas_admin.php" class="active"><i class="bi bi-question-circle"></i> Ayuda</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Gestión de Ayuda</h1>
                <p class="subtitle">Administra los tutoriales y guías</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
            </div>
        </header>

        <div class="content-area">

            <?php if ($mensaje): ?>
                <div class="alert alert-<?= $tipo_mensaje ?>" id="alertaMensaje">
                    <i class="bi bi-<?= $tipo_mensaje === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?>"></i>
                    <span><?= htmlspecialchars($mensaje) ?></span>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card primary"><i class="bi bi-collection-play card-icon"></i><h3>Total</h3><div class="number"><?= $total ?></div><p>Registrados</p></div>
                <div class="stat-card success"><i class="bi bi-eye card-icon"></i><h3>Visibles</h3><div class="number"><?= $activos ?></div><p>Activos</p></div>
                <div class="stat-card warning"><i class="bi bi-eye-slash card-icon"></i><h3>Ocultos</h3><div class="number"><?= $inactivos ?></div><p>Desactivados</p></div>
            </div>

            <div class="filtros-bar">
                <div class="filtros-bar-left">
                    <div class="buscador-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" id="buscadorTutorial" oninput="filtrarTutoriales()" class="form-control" placeholder="Buscar tutorial...">
                    </div>
                    <div class="chips-seccion">
                        <span class="chip-sec active" data-sec="todas" onclick="filtrarChip(this)">Todas</span>
                        <span class="chip-sec" data-sec="frecuentes" onclick="filtrarChip(this)"><i class="bi bi-question-circle"></i> Frecuentes</span>
                        <span class="chip-sec" data-sec="productor"  onclick="filtrarChip(this)"><i class="bi bi-person-badge"></i> Productor</span>
                        <span class="chip-sec" data-sec="comprador"  onclick="filtrarChip(this)"><i class="bi bi-bag"></i> Comprador</span>
                    </div>
                </div>
                <button class="btn btn-primary" onclick="abrirModalCrear()">
                    <i class="bi bi-plus-circle"></i> Nuevo Tutorial
                </button>
            </div>

            <?php
            $secciones_keys = ['frecuentes', 'productor', 'comprador'];
            foreach ($secciones_keys as $sec_key):
                $tuts_sec = array_filter($tutoriales, fn($t) => $t['seccion'] === $sec_key);
                $m = $meta[$sec_key];
            ?>
            <div class="seccion-bloque" data-seccion="<?= $sec_key ?>">
                <div class="seccion-bloque-header">
                    <h3><i class="bi <?= $m['icono'] ?>"></i> <?= $m['titulo'] ?></h3>
                    <span class="badge-count"><?= count($tuts_sec) ?> tutorial<?= count($tuts_sec) !== 1 ? 'es' : '' ?></span>
                </div>

                <?php if (empty($tuts_sec)): ?>
                    <div class="empty-seccion"><i class="bi bi-collection-play"></i><p>No hay tutoriales aún.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="tutoriales-table">
                        <thead>
                            <tr><th>Video</th><th>Título</th><th>Descripción</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tuts_sec as $t):
                            $existe    = videoExiste($ruta_fisica, $t['video']);
                            $streamUrl = $existe ? htmlspecialchars($stream_base . urlencode($t['video'])) : '';
                        ?>
                        <tr class="<?= $t['activo'] ? '' : 'inactivo' ?>"
                            data-titulo="<?= strtolower(htmlspecialchars($t['titulo'])) ?>">

                            <td data-label="Video">
                                <?php if ($existe): ?>
                                    <div class="video-thumb"
                                         onclick="verVideoPreview('<?= $streamUrl ?>', '<?= htmlspecialchars(addslashes($t['titulo'])) ?>')"
                                         title="Clic para reproducir">
                                        <video class="thumb-vid" muted preload="metadata" playsinline
                                               id="thumb-<?= $t['id_tutorial'] ?>">
                                            <source src="<?= $streamUrl ?>#t=0.5" type="video/mp4">
                                        </video>
                                        <div class="play-over"><i class="bi bi-play-circle-fill"></i></div>
                                        <span class="dur-badge" id="dur-<?= $t['id_tutorial'] ?>">--:--</span>
                                    </div>
                                <?php else: ?>
                                    <div class="sin-video-thumb" title="Sin video">
                                        <i class="bi bi-camera-video-off" style="color:#ccc;font-size:20px;"></i>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td data-label="Título">
                                <strong style="color:#2c3e50;"><?= htmlspecialchars($t['titulo']) ?></strong>
                                <?php if (!empty($t['video'])): ?>
                                    <br><small style="color:#adb5bd;font-size:11px;"><?= htmlspecialchars($t['video']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Descripción"><span style="color:#6c757d;font-size:13px;"><?= htmlspecialchars(mb_substr($t['descripcion'] ?? '', 0, 60)) ?><?= strlen($t['descripcion'] ?? '') > 60 ? '...' : '' ?></span></td>

                            <td data-label="Estado">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="accion"        value="toggle_activo">
                                    <input type="hidden" name="id_tutorial"   value="<?= $t['id_tutorial'] ?>">
                                    <input type="hidden" name="activo_actual" value="<?= $t['activo'] ?>">
                                    <button type="submit" class="estado-toggle <?= $t['activo'] ? 'activo' : 'inactivo' ?>" title="Cambiar estado">
                                        <i class="bi bi-<?= $t['activo'] ? 'eye' : 'eye-slash' ?>"></i>
                                        <?= $t['activo'] ? 'Visible' : 'Oculto' ?>
                                    </button>
                                </form>
                            </td>

                            <td data-label="Fecha"><small style="color:#adb5bd;"><?= date('d/m/Y', strtotime($t['fecha_creacion'])) ?></small></td>

                            <td data-label="Acciones">
                                <div class="acciones-td">
                                    <button class="btn-icon edit"
                                            onclick='abrirModalEditar(<?= json_encode($t) ?>, "<?= $streamUrl ?>", <?= $existe ? 'true' : 'false' ?>)'
                                            title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn-icon delete"
                                            onclick="confirmarEliminar(<?= $t['id_tutorial'] ?>, '<?= htmlspecialchars(addslashes($t['titulo'])) ?>')"
                                            title="Eliminar">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

        </div>
    </main>
</div>

<div id="modalCrear" class="modal">
    <div class="modal-content" style="max-width:560px;position:relative;">
        <div class="modal-upload-lock" id="lockCrear">
            <span class="spinner-btn" style="width:22px;height:22px;border-width:3px;margin:0;"></span>
            <span id="lockCrearTxt">Subiendo video...</span>
        </div>
        <div class="modal-header">
            <h2><i class="bi bi-plus-circle"></i> Nuevo Tutorial</h2>
            <button class="close-modal" id="btnXCrear" onclick="cerrarModalCrear()">&times;</button>
        </div>
        <div id="formCrearWrap">
            <div class="form-group">
                <label>Título <span style="color:red">*</span></label>
                <input type="text" id="crear_titulo" maxlength="150" placeholder="Ej: ¿Cómo registrarse?">
                <small style="color:#6c757d;font-size:12px;margin-top:4px;display:block;">
                    <i class="bi bi-info-circle"></i> El nombre del archivo de video se generará automáticamente a partir del título.
                </small>
            </div>
            <div class="form-group">
                <label>Descripción</label>
                <textarea id="crear_descripcion" rows="3" placeholder="Breve descripción..."></textarea>
            </div>
            <div class="form-group">
                <label>Sección <span style="color:red">*</span></label>
                <select id="crear_seccion">
                    <option value="frecuentes">Preguntas Frecuentes</option>
                    <option value="productor">Productor</option>
                    <option value="comprador">Comprador</option>
                </select>
            </div>
            <div class="form-group">
                <label>Video <span style="color:red">*</span></label>
                <label class="dropzone-video" for="crear_video" id="dropzoneLabel">
                    <input type="file" id="crear_video" accept="video/mp4,video/webm,video/ogg" onchange="onVideoSeleccionado(this)">
                    <i class="bi bi-cloud-upload" id="dzIcon"></i>
                    <strong id="dzTexto">Haz clic o arrastra el video aquí</strong>
                    <p id="dzSub">MP4, WEBM u OGG — Máximo 100 MB</p>
                </label>
                <div class="peso-indicador" id="pesoIndicador"><i class="bi bi-check-circle-fill"></i><span id="pesoIndicadorTxt"></span></div>
            </div>
            <div id="uploadProgressWrap">
                <div class="progress-header"><span><i class="bi bi-upload"></i> Subiendo...</span><span id="uploadPct">0%</span></div>
                <div class="progress-track"><div class="progress-fill" id="uploadBar"></div></div>
                <div class="progress-sub" id="uploadSub">Iniciando...</div>
            </div>
            <div class="upload-success-msg" id="uploadSuccessMsg"><i class="bi bi-check-circle-fill"></i><span>¡Tutorial guardado!</span></div>
            <div class="upload-error-msg"   id="uploadErrorMsg"><i class="bi bi-exclamation-triangle-fill"></i><span id="uploadErrorTxt"></span></div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" id="btnCancelarCrear" onclick="cerrarModalCrear()"><i class="bi bi-x-circle"></i> Cancelar</button>
                <button type="button" class="btn btn-primary"   id="btnGuardarCrear"  onclick="submitCrearAjax()"><i class="bi bi-check-circle"></i> Guardar</button>
            </div>
        </div>
    </div>
</div>

<div id="modalEditar" class="modal">
    <div class="modal-content" style="max-width:580px;position:relative;">
        <div class="modal-upload-lock" id="lockEditar">
            <span class="spinner-btn" style="width:22px;height:22px;border-width:3px;margin:0;"></span>
            <span id="lockEditarTxt">Guardando...</span>
        </div>
        <div class="modal-header">
            <h2><i class="bi bi-pencil"></i> Editar Tutorial</h2>
            <button class="close-modal" id="btnXEditar" onclick="cerrarModalEditar()">&times;</button>
        </div>
        <div id="formEditarWrap">
            <input type="hidden" id="edit_id">
            <div class="form-group">
                <label>Título <span style="color:red">*</span></label>
                <input type="text" id="edit_titulo" maxlength="150">
                <small style="color:#6c757d;font-size:12px;margin-top:4px;display:block;">
                    <i class="bi bi-info-circle"></i> Si reemplazas el video, su nombre se generará desde el título actualizado.
                </small>
            </div>
            <div class="form-group"><label>Descripción</label><textarea id="edit_descripcion" rows="3"></textarea></div>
            <div class="form-group"><label>Sección</label><select id="edit_seccion"><option value="frecuentes">Preguntas Frecuentes</option><option value="productor">Productor</option><option value="comprador">Comprador</option></select></div>
            <div class="form-group">
                <div class="checkbox-group"><input type="checkbox" id="edit_activo" value="1"><label for="edit_activo">Visible para usuarios</label></div>
            </div>
            <div class="form-group">
                <label>Video actual</label>
                <div class="video-actual-wrap" id="videoActualWrap">
                    <video muted preload="metadata" id="videoActualPreview" playsinline
                           onloadedmetadata="actualizarDurEditar(this)">
                        <source src="" type="video/mp4">
                    </video>
                    <div class="video-actual-info">
                        <strong>Video cargado</strong>
                        <span class="dur-info" id="durEditarInfo"></span>
                    </div>
                </div>
                <div id="sinVideoActualWrap" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:10px 14px;font-size:13px;color:#856404;margin-bottom:10px;">
                    <i class="bi bi-exclamation-triangle"></i> Sin video asignado.
                </div>
                <label style="font-size:13px;color:#495057;margin-bottom:6px;display:block;">Reemplazar video</label>
                <label class="dropzone-video" for="edit_video" id="dropzoneLabelEdit" style="padding:20px;">
                    <input type="file" id="edit_video" accept="video/mp4,video/webm,video/ogg" onchange="onVideoEditSeleccionado(this)">
                    <i class="bi bi-arrow-repeat" id="dzIconEdit" style="font-size:32px;"></i>
                    <strong id="dzTextoEdit" style="font-size:14px;">Clic para subir nuevo video</strong>
                    <p id="dzSubEdit">MP4, WEBM u OGG — Máximo 100 MB</p>
                </label>
                <div class="peso-indicador" id="pesoIndicadorEdit"><i class="bi bi-check-circle-fill"></i><span id="pesoIndicadorEditTxt"></span></div>
            </div>
            <div id="uploadProgressWrapEdit">
                <div class="progress-header"><span><i class="bi bi-upload"></i> Subiendo...</span><span id="uploadPctEdit">0%</span></div>
                <div class="progress-track"><div class="progress-fill" id="uploadBarEdit"></div></div>
                <div class="progress-sub" id="uploadSubEdit">Iniciando...</div>
            </div>
            <div class="upload-success-msg" id="uploadSuccessMsgEdit"><i class="bi bi-check-circle-fill"></i><span>¡Actualizado!</span></div>
            <div class="upload-error-msg"   id="uploadErrorMsgEdit"><i class="bi bi-exclamation-triangle-fill"></i><span id="uploadErrorTxtEdit"></span></div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" id="btnCancelarEditar" onclick="cerrarModalEditar()"><i class="bi bi-x-circle"></i> Cancelar</button>
                <button type="button" class="btn btn-primary"   id="btnGuardarEditar"  onclick="submitEditarAjax()"><i class="bi bi-check-circle"></i> Actualizar</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formEliminar" style="display:none;">
    <input type="hidden" name="accion" value="eliminar_tutorial">
    <input type="hidden" name="id_tutorial" id="eliminar_id">
</form>

<script>
const MAX_B = 100 * 1024 * 1024;

function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }
document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar'), mt = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && !sb.contains(e.target) && !mt.contains(e.target) && sb.classList.contains('open')) sb.classList.remove('open');
});

setTimeout(() => {
    const a = document.getElementById('alertaMensaje');
    if (a) { a.style.transition='opacity .5s'; a.style.opacity='0'; setTimeout(()=>a.remove(),500); }
}, 5000);

function bloquearScroll()    { document.body.classList.add('modal-open'); }
function desbloquearScroll() { document.body.classList.remove('modal-open'); }

let _subiendo = false;
function setSubiendo(activo, m) {
    _subiendo = activo;
    const lock = document.getElementById(m==='crear' ? 'lockCrear' : 'lockEditar');
    const btnX = document.getElementById(m==='crear' ? 'btnXCrear' : 'btnXEditar');
    const btnC = document.getElementById(m==='crear' ? 'btnCancelarCrear' : 'btnCancelarEditar');
    lock.classList.toggle('active', activo);
    if (btnX) btnX.style.pointerEvents = activo ? 'none' : '';
    if (btnC) btnC.disabled = activo;
}

function iniciarMiniaturas() {
    document.querySelectorAll('.thumb-vid').forEach(vid => {
        function capturar() { vid.currentTime = 0.5; }
        if (vid.readyState >= 1) capturar();
        else vid.addEventListener('loadedmetadata', capturar, {once:true});
        vid.addEventListener('loadedmetadata', () => {
            const badge = document.getElementById('dur-' + vid.id.replace('thumb-',''));
            if (badge && vid.duration) badge.textContent = formatDur(vid.duration);
        }, {once:true});
    });
}
iniciarMiniaturas();

function formatDur(s) {
    if (!s || isNaN(s)) return '';
    return Math.floor(s/60) + ':' + Math.floor(s%60).toString().padStart(2,'0');
}
function actualizarDurEditar(vid) {
    const el = document.getElementById('durEditarInfo');
    if (el && vid.duration) el.textContent = '⏱ Duración: ' + formatDur(vid.duration);
}

let _chipActivo = 'todas';
function filtrarChip(chip) {
    _chipActivo = chip.dataset.sec;
    document.querySelectorAll('.chip-sec').forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    filtrarTutoriales();
}

function filtrarTutoriales() {
    const q = document.getElementById('buscadorTutorial').value.toLowerCase();
    document.querySelectorAll('.tutoriales-table tbody tr').forEach(row => {
        const matchTitulo = (row.dataset.titulo || '').includes(q);
        row.style.display = matchTitulo ? '' : 'none';
    });
    document.querySelectorAll('.seccion-bloque').forEach(bloque => {
        const secMatch = _chipActivo === 'todas' || bloque.dataset.seccion === _chipActivo;
        bloque.style.display = secMatch ? '' : 'none';
    });
}

function onVideoSeleccionado(input) {
    const pi=document.getElementById('pesoIndicador'), piTxt=document.getElementById('pesoIndicadorTxt');
    const icon=document.getElementById('dzIcon'), texto=document.getElementById('dzTexto'), sub=document.getElementById('dzSub');
    const dz=document.getElementById('dropzoneLabel');
    if (!input.files || !input.files[0]) return;
    const f=input.files[0], mb=(f.size/1048576).toFixed(1);
    if (f.size > MAX_B) {
        icon.className='bi bi-x-circle'; icon.style.color='#dc3545';
        texto.textContent=f.name; sub.textContent=mb+' MB — supera 100 MB'; sub.style.color='#dc3545';
        dz.classList.add('error-border'); pi.className='peso-indicador error';
        piTxt.textContent='⚠ El archivo pesa '+mb+' MB y supera el límite.'; input.value=''; return;
    }
    icon.className='bi bi-film'; icon.style.color='#28a745';
    texto.textContent=f.name; sub.textContent=mb+' MB — listo'; sub.style.color='#28a745';
    dz.classList.remove('error-border'); pi.className='peso-indicador ok';
    piTxt.textContent='✓ '+f.name+' ('+mb+' MB)';
}

function onVideoEditSeleccionado(input) {
    const pi=document.getElementById('pesoIndicadorEdit'), piTxt=document.getElementById('pesoIndicadorEditTxt');
    const icon=document.getElementById('dzIconEdit'), texto=document.getElementById('dzTextoEdit'), sub=document.getElementById('dzSubEdit');
    const dz=document.getElementById('dropzoneLabelEdit');
    if (!input.files || !input.files[0]) return;
    const f=input.files[0], mb=(f.size/1048576).toFixed(1);
    if (f.size > MAX_B) {
        icon.className='bi bi-x-circle'; icon.style.color='#dc3545';
        texto.textContent=f.name; sub.textContent=mb+' MB — supera 100 MB'; sub.style.color='#dc3545';
        dz.classList.add('error-border'); pi.className='peso-indicador error';
        piTxt.textContent='⚠ '+mb+' MB supera el límite.'; input.value=''; return;
    }
    icon.className='bi bi-film'; icon.style.color='#28a745';
    texto.textContent=f.name; sub.textContent=mb+' MB — listo'; sub.style.color='#28a745';
    dz.classList.remove('error-border'); pi.className='peso-indicador ok';
    piTxt.textContent='✓ Nuevo: '+f.name+' ('+mb+' MB)';
}

function abrirModalCrear()  { resetFormCrear(); document.getElementById('modalCrear').classList.add('show'); bloquearScroll(); }
function cerrarModalCrear() { if (_subiendo) return; document.getElementById('modalCrear').classList.remove('show'); desbloquearScroll(); }
document.getElementById('modalCrear').addEventListener('click', function(e){ if(e.target===this) cerrarModalCrear(); });

function resetFormCrear() {
    ['crear_titulo','crear_descripcion'].forEach(id => document.getElementById(id).value='');
    document.getElementById('crear_seccion').value='frecuentes';
    document.getElementById('crear_video').value='';
    document.getElementById('dzTexto').textContent='Haz clic o arrastra el video aquí';
    document.getElementById('dzSub').textContent='MP4, WEBM u OGG — Máximo 100 MB';
    document.getElementById('dzSub').style.color='#6c757d';
    document.getElementById('dzIcon').className='bi bi-cloud-upload'; document.getElementById('dzIcon').style.color='#28a745';
    document.getElementById('dropzoneLabel').classList.remove('error-border');
    document.getElementById('pesoIndicador').className='peso-indicador';
    document.getElementById('uploadProgressWrap').style.display='none';
    document.getElementById('uploadSuccessMsg').style.display='none';
    document.getElementById('uploadErrorMsg').style.display='none';
    document.getElementById('uploadBar').style.width='0%';
    document.getElementById('uploadPct').textContent='0%';
    document.getElementById('btnGuardarCrear').disabled=false;
    document.getElementById('btnGuardarCrear').innerHTML='<i class="bi bi-check-circle"></i> Guardar';
    document.getElementById('btnGuardarCrear').style.background='';
    setSubiendo(false,'crear');
}

function abrirModalEditar(t, streamUrl, videoExiste) {
    document.getElementById('edit_id').value=t.id_tutorial;
    document.getElementById('edit_titulo').value=t.titulo;
    document.getElementById('edit_descripcion').value=t.descripcion||'';
    document.getElementById('edit_seccion').value=t.seccion;
    document.getElementById('edit_activo').checked=t.activo==1;
    document.getElementById('edit_video').value='';
    document.getElementById('dzTextoEdit').textContent='Clic para subir nuevo video';
    document.getElementById('dzSubEdit').textContent='MP4, WEBM u OGG — Máximo 100 MB';
    document.getElementById('dzSubEdit').style.color='#6c757d';
    document.getElementById('dzIconEdit').className='bi bi-arrow-repeat'; document.getElementById('dzIconEdit').style.color='#28a745';
    document.getElementById('dropzoneLabelEdit').classList.remove('error-border');
    document.getElementById('pesoIndicadorEdit').className='peso-indicador';
    document.getElementById('uploadProgressWrapEdit').style.display='none';
    document.getElementById('uploadSuccessMsgEdit').style.display='none';
    document.getElementById('uploadErrorMsgEdit').style.display='none';
    document.getElementById('uploadBarEdit').style.width='0%';
    document.getElementById('uploadPctEdit').textContent='0%';
    document.getElementById('btnGuardarEditar').disabled=false;
    document.getElementById('btnGuardarEditar').innerHTML='<i class="bi bi-check-circle"></i> Actualizar';
    document.getElementById('btnGuardarEditar').style.background='';
    document.getElementById('durEditarInfo').textContent='';
    setSubiendo(false,'editar');
    if (videoExiste) {
        document.getElementById('videoActualWrap').style.display='flex';
        document.getElementById('sinVideoActualWrap').style.display='none';
        const vid=document.getElementById('videoActualPreview');
        vid.src=streamUrl; vid.load();
    } else {
        document.getElementById('videoActualWrap').style.display='none';
        document.getElementById('sinVideoActualWrap').style.display='block';
    }
    document.getElementById('modalEditar').classList.add('show'); bloquearScroll();
}
function cerrarModalEditar() {
    if (_subiendo) return;
    const vid=document.getElementById('videoActualPreview'); vid.pause(); vid.src='';
    document.getElementById('modalEditar').classList.remove('show'); desbloquearScroll();
}
document.getElementById('modalEditar').addEventListener('click', function(e){ if(e.target===this) cerrarModalEditar(); });

function submitCrearAjax() {
    const titulo=document.getElementById('crear_titulo').value.trim();
    const videoFile=document.getElementById('crear_video').files[0];
    if (!titulo)     { mostrarErrorModal('El título es obligatorio.','crear'); return; }
    if (!videoFile)  { mostrarErrorModal('El video es obligatorio.','crear'); return; }
    if (videoFile.size > MAX_B) { mostrarErrorModal('El video supera 100 MB.','crear'); return; }

    const fd=new FormData();
    fd.append('accion','crear_tutorial');
    fd.append('titulo',titulo);
    fd.append('descripcion',document.getElementById('crear_descripcion').value);
    fd.append('seccion',document.getElementById('crear_seccion').value);
    fd.append('video',videoFile);

    const btn=document.getElementById('btnGuardarCrear');
    btn.disabled=true; btn.innerHTML='<span class="spinner-btn"></span> Subiendo...';
    setSubiendo(true,'crear');
    document.getElementById('uploadProgressWrap').style.display='block';
    document.getElementById('uploadSuccessMsg').style.display='none';
    document.getElementById('uploadErrorMsg').style.display='none';
    xhrUpload(fd,'crear');
}

function submitEditarAjax() {
    const id=document.getElementById('edit_id').value;
    const titulo=document.getElementById('edit_titulo').value.trim();
    if (!titulo || !id) { mostrarErrorModal('El título es obligatorio.','editar'); return; }
    const videoFile=document.getElementById('edit_video').files[0];
    if (videoFile && videoFile.size > MAX_B) { mostrarErrorModal('El video supera 100 MB.','editar'); return; }

    const fd=new FormData();
    fd.append('accion','editar_tutorial');
    fd.append('id_tutorial',id);
    fd.append('titulo',titulo);
    fd.append('descripcion',document.getElementById('edit_descripcion').value);
    fd.append('seccion',document.getElementById('edit_seccion').value);
    fd.append('activo',document.getElementById('edit_activo').checked ? '1' : '');
    if (videoFile) fd.append('video_edit',videoFile);

    const btn=document.getElementById('btnGuardarEditar');
    btn.disabled=true;
    btn.innerHTML=videoFile ? '<span class="spinner-btn"></span> Subiendo...' : '<span class="spinner-btn"></span> Guardando...';
    setSubiendo(true,'editar');
    if (videoFile) {
        document.getElementById('uploadProgressWrapEdit').style.display='block';
        document.getElementById('uploadSuccessMsgEdit').style.display='none';
        document.getElementById('uploadErrorMsgEdit').style.display='none';
    }
    xhrUpload(fd,'editar');
}

function xhrUpload(fd, modal) {
    const sfx    = modal==='crear' ? '' : 'Edit';
    const btnId  = modal==='crear' ? 'btnGuardarCrear' : 'btnGuardarEditar';
    const lockTxtId = modal==='crear' ? 'lockCrearTxt' : 'lockEditarTxt';
    const labelOk   = modal==='crear' ? 'Guardar' : 'Actualizar';

    const xhr=new XMLHttpRequest();
    xhr.upload.addEventListener('progress', e => {
        if (!e.lengthComputable) return;
        const pct=Math.round((e.loaded/e.total)*100);
        const sent=(e.loaded/1048576).toFixed(1), tot=(e.total/1048576).toFixed(1);
        document.getElementById('uploadBar'+sfx).style.width=pct+'%';
        document.getElementById('uploadPct'+sfx).textContent=pct+'%';
        document.getElementById('uploadSub'+sfx).textContent=sent+' MB de '+tot+' MB';
        document.getElementById(lockTxtId).textContent='Subiendo... '+pct+'%';
    });
    xhr.upload.addEventListener('load', () => {
        document.getElementById('uploadBar'+sfx).style.width='100%';
        document.getElementById('uploadPct'+sfx).textContent='100%';
        document.getElementById('uploadSub'+sfx).textContent='Procesando...';
        document.getElementById(lockTxtId).textContent='Procesando...';
        document.getElementById(btnId).innerHTML='<span class="spinner-btn"></span> Procesando...';
    });
    xhr.addEventListener('load', () => {
        setSubiendo(false,modal); document.getElementById(btnId).disabled=false;
        let resp;
        try { resp=JSON.parse(xhr.responseText); } catch(ex) {
            document.getElementById('uploadProgressWrap'+sfx).style.display='none';
            mostrarErrorModal('Error del servidor.',modal);
            document.getElementById(btnId).innerHTML='<i class="bi bi-check-circle"></i> '+labelOk;
            return;
        }
        document.getElementById('uploadProgressWrap'+sfx).style.display='none';
        if (resp.ok) {
            document.getElementById('uploadSuccessMsg'+sfx).style.display='flex';
            document.getElementById(btnId).innerHTML='<i class="bi bi-check-circle-fill"></i> ¡Listo!';
            document.getElementById(btnId).style.background='#155724';
            setTimeout(()=>window.location.reload(), 1200);
        } else {
            mostrarErrorModal(resp.mensaje||'Error.',modal);
            document.getElementById(btnId).innerHTML='<i class="bi bi-check-circle"></i> '+labelOk;
        }
    });
    xhr.addEventListener('error',   () => { setSubiendo(false,modal); document.getElementById(btnId).disabled=false; document.getElementById('uploadProgressWrap'+sfx).style.display='none'; mostrarErrorModal('Error de red.',modal); document.getElementById(btnId).innerHTML='<i class="bi bi-check-circle"></i> '+labelOk; });
    xhr.addEventListener('timeout', () => { setSubiendo(false,modal); document.getElementById(btnId).disabled=false; document.getElementById('uploadProgressWrap'+sfx).style.display='none'; mostrarErrorModal('Tiempo agotado.',modal); document.getElementById(btnId).innerHTML='<i class="bi bi-check-circle"></i> '+labelOk; });
    xhr.timeout=600000;
    xhr.open('POST',window.location.href);
    xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
    xhr.send(fd);
}

function mostrarErrorModal(msg, modal) {
    const sfx=modal==='crear' ? '' : 'Edit';
    const em=document.getElementById('uploadErrorMsg'+sfx);
    document.getElementById('uploadErrorTxt'+sfx).textContent=msg;
    em.style.display='flex';
    setTimeout(()=>{ em.style.display='none'; },10000);
}

function verVideoPreview(url, titulo) {
    const vid=document.getElementById('videoPreviewEl');
    document.getElementById('previewDurText').textContent='';
    vid.src=url; vid.load();
    vid.addEventListener('loadedmetadata', ()=>{
        document.getElementById('previewDurText').textContent = vid.duration ? '⏱ Duración: '+formatDur(vid.duration) : '';
    }, {once:true});
    document.getElementById('modalVideoPreview').classList.add('show');
    bloquearScroll();
}
function cerrarPreviewVideo() {
    const vid=document.getElementById('videoPreviewEl'); vid.pause(); vid.src='';
    document.getElementById('modalVideoPreview').classList.remove('show');
    desbloquearScroll();
}
document.getElementById('modalVideoPreview').addEventListener('click', function(e){ if(e.target===this) cerrarPreviewVideo(); });

function confirmarEliminar(id, titulo) {
    if (!confirm('¿Eliminar "'+titulo+'"?\n\nSe borrará también el archivo de video.')) return;
    document.getElementById('eliminar_id').value=id;
    document.getElementById('formEliminar').submit();
}

document.addEventListener('keydown', e => {
    if (e.key!=='Escape') return;
    cerrarModalCrear(); cerrarModalEditar(); cerrarPreviewVideo();
});
</script>
</body>
</html>