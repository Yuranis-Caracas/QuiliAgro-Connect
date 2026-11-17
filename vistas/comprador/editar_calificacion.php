<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'comprador');
require_once __DIR__ . '/../../config/auth_check.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'comprador') {
    header("Location: ../auth/login.php");
    exit();
}

$usuario_id     = intval($_SESSION['user_id']);
$usuario_nombre = htmlspecialchars($_SESSION['user_nombre']);

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

function urlImagen($ruta, $base = '../../') {
    if (empty($ruta)) return '';
    if (str_starts_with($ruta, 'http://') || str_starts_with($ruta, 'https://')) return $ruta;
    $ruta = ltrim($ruta, '/');
    return $base . $ruta;
}

$mensaje      = '';
$tipo_mensaje = '';

$id_calificacion = isset($_GET['id_calificacion']) ? intval($_GET['id_calificacion']) : 0;

if ($id_calificacion <= 0) {
    header("Location: pedidos_comprador.php");
    exit();
}

$rv_resena = ejecutarConsulta($conn,
    "SELECT c.*, u.nombre AS nombre_productor,
            pp.foto_perfil, o.fecha AS fecha_orden
     FROM calificaciones_resenas c
     INNER JOIN usuarios u ON u.id_usuario = c.id_productor
     LEFT JOIN perfiles_productor pp ON pp.id_productor = c.id_productor
     INNER JOIN ordenes o ON o.id_orden = c.id_orden
     WHERE c.id_calificacion = ? AND c.id_comprador = ?
     LIMIT 1",
    "ii", [$id_calificacion, $usuario_id]);

if (!$rv_resena || $rv_resena->num_rows === 0) {
    $_SESSION['mensaje_temporal'] = "No tienes permiso para editar esta resena.";
    $_SESSION['tipo_mensaje_temporal'] = "danger";
    header("Location: pedidos_comprador.php");
    exit();
}

$resena_actual = $rv_resena->fetch_assoc();
$id_orden      = $resena_actual['id_orden'];
$id_productor  = $resena_actual['id_productor'];



$result_productos = ejecutarConsulta($conn,
    "SELECT ped.cantidad, ped.total,
            prod.nombre AS nombre_producto, prod.unidad_medida,
            COALESCE(
                (SELECT fp.ruta_foto FROM fotos_productos fp
                 WHERE fp.id_producto = prod.id_producto
                 ORDER BY fp.orden ASC LIMIT 1),
                prod.imagen
            ) AS imagen_producto
     FROM pedidos ped
     INNER JOIN productos prod ON prod.id_producto = ped.id_producto
     WHERE ped.id_orden = ? AND ped.id_productor = ? AND ped.id_comprador = ? AND ped.estado = 'entregado'",
    "iii", [$id_orden, $id_productor, $usuario_id]);

$productos = [];
if ($result_productos) {
    while ($row = $result_productos->fetch_assoc()) $productos[] = $row;
}

$imagen_principal = null;
foreach ($productos as $p) {
    if (!empty($p['imagen_producto'])) {
        $imagen_principal = $p['imagen_producto'];
        break;
    }
}

$imagenes_actuales = !empty($resena_actual['imagenes'])
    ? json_decode($resena_actual['imagenes'], true)
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_calificacion'])) {
    $calificacion = isset($_POST['calificacion']) ? intval($_POST['calificacion']) : 0;
    $resena_texto = trim($_POST['resena'] ?? '');

    if ($calificacion < 1 || $calificacion > 5) {
        $mensaje      = "Por favor selecciona una calificacion.";
        $tipo_mensaje = "danger";
    } else {
        $imagenes_mantener = isset($_POST['imagenes_mantener'])
            ? array_values($_POST['imagenes_mantener'])
            : [];

        $imagenes_nuevas = [];
        if (isset($_FILES['imagenes_nuevas']) && !empty($_FILES['imagenes_nuevas']['name'][0])) {
            $directorio = "../../uploads/calificaciones/";
            if (!file_exists($directorio)) mkdir($directorio, 0777, true);
            $maxSize = 5 * 1024 * 1024;
            $exts_ok = ['jpg', 'jpeg', 'png', 'gif'];
            $total   = count($_FILES['imagenes_nuevas']['name']);
            $espacio = 5 - count($imagenes_mantener);

            for ($i = 0; $i < min($total, $espacio); $i++) {
                if ($_FILES['imagenes_nuevas']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['imagenes_nuevas']['size'][$i] > $maxSize) continue;
                $ext = strtolower(pathinfo($_FILES['imagenes_nuevas']['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $exts_ok)) continue;
                $nombre = "calif_edit_ord{$id_orden}_prod{$id_productor}_" . time() . "_{$i}.{$ext}";
                if (move_uploaded_file($_FILES['imagenes_nuevas']['tmp_name'][$i], $directorio . $nombre)) {
                    $imagenes_nuevas[] = "uploads/calificaciones/" . $nombre;
                }
            }
        }

        foreach ($imagenes_actuales as $img_antigua) {
            if (!in_array($img_antigua, $imagenes_mantener)) {
                $ruta_fisica = "../../" . $img_antigua;
                if (file_exists($ruta_fisica)) @unlink($ruta_fisica);
            }
        }

        $todas_imagenes = array_merge($imagenes_mantener, $imagenes_nuevas);
        $imagenes_json  = !empty($todas_imagenes) ? json_encode(array_values($todas_imagenes)) : null;

            $ok = ejecutarConsulta($conn,
        "UPDATE calificaciones_resenas
        SET calificacion = ?, resena = ?, imagenes = ?, estado = 'pendiente',
            motivo_rechazo = NULL, fecha_calificacion = NOW()
        WHERE id_calificacion = ? AND id_comprador = ?",
        "issii",
        [$calificacion, $resena_texto, $imagenes_json, $id_calificacion, $usuario_id]);

        if ($ok) {
            $_SESSION['mensaje_temporal'] = "Resena actualizada. Sera revisada nuevamente antes de publicarse.";
            $_SESSION['tipo_mensaje_temporal'] = "success";
            header("Location: pedidos_comprador.php");
            exit();
        } else {
            $mensaje      = "Error al actualizar la resena. Intenta nuevamente.";
            $tipo_mensaje = "danger";
        }
    }
}

$cal_actual = intval($resena_actual['calificacion']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Resena - QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .calificacion-container { max-width: 860px; margin: 0 auto; }

        .calificacion-section {
            background: white;
            border-radius: var(--radius-lg);
            padding: 28px 30px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 22px;
        }

        .productor-header {
            display: flex; gap: 20px; align-items: center;
            background: linear-gradient(135deg, #f0f8f4, #e8f5e9);
            border-radius: var(--radius-lg);
            padding: 24px; margin-bottom: 22px;
            border-left: 5px solid var(--primary);
        }
        .productor-foto {
            width: 90px; height: 90px; border-radius: 50%;
            object-fit: cover; border: 4px solid white;
            box-shadow: var(--shadow-sm); flex-shrink: 0;
        }
        .productor-foto-placeholder {
            width: 90px; height: 90px; border-radius: 50%;
            background: var(--primary);
            display: flex; align-items: center; justify-content: center;
            font-size: 38px; color: white;
            border: 4px solid white; box-shadow: var(--shadow-sm); flex-shrink: 0;
        }
        .productor-info h2 { font-size: 22px; font-weight: 800; color: var(--text-dark); margin: 0 0 6px; }
        .productor-info p  { font-size: 14px; color: var(--text-muted); margin: 0; }

        .producto-imagen-wrap { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
        .producto-img-principal {
            width: 110px; height: 110px; object-fit: cover;
            border-radius: var(--radius); box-shadow: var(--shadow-sm);
            border: 3px solid #e9ecef; flex-shrink: 0;
        }
        .producto-img-placeholder {
            width: 110px; height: 110px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: var(--radius); border: 3px dashed #dee2e6;
            display: flex; align-items: center; justify-content: center;
            font-size: 40px; color: #adb5bd; flex-shrink: 0;
        }
        .productos-lista { flex: 1; }
        .productos-lista h4 { font-size: 15px; font-weight: 700; color: var(--text-dark); margin: 0 0 10px; }
        .producto-fila {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 0; border-bottom: 1px solid #f0f0f0;
            font-size: 14px; color: var(--text-dark);
        }
        .producto-fila:last-child { border-bottom: none; }
        .badge-cant {
            background: var(--primary); color: white;
            border-radius: 20px; padding: 2px 10px;
            font-size: 12px; font-weight: 600;
        }

        .rating-container {
            text-align: center; padding: 36px 20px;
            background: linear-gradient(135deg, #fff9e6, #fff3cd);
            border-radius: var(--radius-lg);
        }
        .rating-title    { font-size: 22px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
        .rating-subtitle { font-size: 14px; color: var(--text-muted); margin-bottom: 26px; }
        .stars-wrap { display: flex; justify-content: center; gap: 6px; }
        .stars-wrap .star {
            font-size: 54px; color: #e0e0e0; cursor: pointer;
            line-height: 1; user-select: none;
            transition: color .15s ease, transform .1s ease;
        }
        .stars-wrap .star.activa { color: #ffc107; }
        .stars-wrap .star:active { transform: scale(0.9); }
        .rating-description {
            font-size: 16px; font-weight: 700; color: var(--primary);
            min-height: 28px; margin-top: 20px;
        }

        .resena-container label { font-size: 16px; font-weight: 700; color: var(--text-dark); margin-bottom: 10px; display: block; }
        .resena-container textarea {
            width: 100%; min-height: 160px; padding: 16px;
            border: 2px solid #e0e0e0; border-radius: var(--radius);
            font-size: 15px; line-height: 1.6; resize: vertical;
            transition: var(--transition); box-sizing: border-box;
        }
        .resena-container textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(40,167,69,.1);
            outline: none;
        }
        .char-counter { text-align: right; font-size: 13px; color: var(--text-muted); margin-top: 6px; }

        .fotos-actuales-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px; margin-bottom: 20px;
        }
        .foto-actual-item {
            position: relative; aspect-ratio: 1;
            border-radius: var(--radius); overflow: hidden;
            border: 3px solid #e0e0e0; box-shadow: var(--shadow-sm);
        }
        .foto-actual-item img { width: 100%; height: 100%; object-fit: cover; }
        .foto-actual-item.eliminada img { opacity: 0.3; filter: grayscale(1); }
        .btn-eliminar-foto {
            position: absolute; top: 6px; right: 6px;
            background: var(--danger); color: white;
            border: none; width: 28px; height: 28px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 16px; box-shadow: 0 2px 6px rgba(0,0,0,.3); transition: var(--transition);
        }
        .btn-eliminar-foto:hover { background: #c82333; transform: scale(1.1); }
        .btn-restaurar {
            position: absolute; top: 6px; right: 6px;
            background: var(--success); color: white;
            border: none; width: 28px; height: 28px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 16px; box-shadow: 0 2px 6px rgba(0,0,0,.3); transition: var(--transition);
        }

        .upload-area {
            border: 3px dashed var(--primary);
            border-radius: var(--radius-lg); padding: 30px;
            text-align: center;
            background: linear-gradient(135deg, #f0f8f4, #e8f5e9);
            cursor: pointer; transition: var(--transition); display: block;
        }
        .upload-area:hover { background: linear-gradient(135deg, #e8f5e9, #d4edda); transform: translateY(-2px); }
        .upload-area input[type="file"] { display: none; }
        .upload-icon { font-size: 44px; color: var(--primary); margin-bottom: 10px; }
        .upload-text { font-size: 15px; font-weight: 600; color: var(--text-dark); margin-bottom: 4px; }
        .upload-hint { font-size: 13px; color: var(--text-muted); }

        .preview-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; margin-top: 14px; }
        .preview-item { position: relative; aspect-ratio: 1; border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-sm); border: 3px solid white; }
        .preview-item img { width: 100%; height: 100%; object-fit: cover; }
        .preview-remove {
            position: absolute; top: 6px; right: 6px;
            background: var(--danger); color: white;
            border: none; width: 28px; height: 28px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 16px; transition: var(--transition);
        }
        .preview-remove:hover { background: #c82333; transform: scale(1.1); }

        .aviso-edicion {
            background: linear-gradient(135deg, #fff3e0, #ffe0b2);
            border-left: 5px solid #ff9800;
            border-radius: var(--radius);
            padding: 16px 20px; margin-bottom: 22px;
            display: flex; align-items: flex-start; gap: 12px;
            font-size: 14px; color: #e65100;
        }
        .aviso-edicion i { font-size: 22px; flex-shrink: 0; margin-top: 2px; }

        .btn-cancelar {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 13px 24px;
            background: #fff;
            color: #6c757d;
            border: 2px solid #dee2e6;
            border-radius: var(--radius);
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background .2s, border-color .2s, color .2s, transform .15s;
            white-space: nowrap;
        }
        .btn-cancelar i { transition: transform .2s ease; }
        .btn-cancelar:hover {
            background: #f1f3f5;
            border-color: #adb5bd;
            color: #343a40;
            text-decoration: none;
        }
        .btn-cancelar:hover i { transform: translateX(-4px); }
        .btn-cancelar:active { background: #e9ecef; transform: scale(0.97); }

        @media (max-width: 600px) {
            .productor-header { flex-direction: column; text-align: center; }
            .stars-wrap .star { font-size: 42px; }
            .producto-imagen-wrap { flex-direction: column; }
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
            <a href="panel_comprador.php"><i class="bi bi-speedometer2"></i> Inicio</a>
            <a href="catalogo_comprador.php"><i class="bi bi-shop"></i> Catálogo</a>
            <a href="pedidos_comprador.php" class="active"><i class="bi bi-clipboard-check"></i> Mis Pedidos</a>
            <a href="productores_comprador.php"><i class="bi bi-people"></i> Productores</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Editar Resena</h1>
                <p class="subtitle">Modifica tu calificacion -- pasara a revision nuevamente</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout">
                    <i class="bi bi-box-arrow-right"></i> Cerrar Sesion
                </a>
            </div>
        </header>

        <div class="content-area">

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                    <i class="bi bi-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill"></i>
                    <?php echo $mensaje; ?>
                </div>
            <?php endif; ?>

            <div class="calificacion-container">

                <div class="productor-header">
                    <?php if (!empty($resena_actual['foto_perfil'])): ?>
                        <img src="<?php echo htmlspecialchars(urlImagen($resena_actual['foto_perfil'])); ?>"
                             alt="Productor"
                             class="productor-foto"
                             onerror="onImgError(this)">
                        <div class="productor-foto-placeholder" style="display:none;">
                            <i class="bi bi-person-fill"></i>
                        </div>
                    <?php else: ?>
                        <div class="productor-foto-placeholder">
                            <i class="bi bi-person-fill"></i>
                        </div>
                    <?php endif; ?>
                    <div class="productor-info">
                        <h2><?php echo htmlspecialchars($resena_actual['nombre_productor']); ?></h2>
                        <p><i class="bi bi-calendar3"></i> Orden del <?php echo date('d/m/Y', strtotime($resena_actual['fecha_orden'])); ?></p>
                        <p>
                            <i class="bi bi-info-circle"></i> Estado actual:
                            <?php if ($resena_actual['estado'] === 'activa'): ?>
                                <strong style="color:var(--success);">Publicada</strong>
                            <?php else: ?>
                                <strong style="color:var(--warning);">En revision</strong>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <?php if (!empty($productos)): ?>
                <div class="calificacion-section">
                    <div class="producto-imagen-wrap">

                        <?php if (!empty($imagen_principal)): ?>
                            <?php
                                $img_path = str_starts_with($imagen_principal, 'http')
                                    ? $imagen_principal
                                    : '../../' . ltrim($imagen_principal, '/');
                                ?>
                                <img src="<?php echo htmlspecialchars($img_path); ?>"
                                 alt="Foto del producto"
                                 class="producto-img-principal"
                                 onerror="onImgError(this)">
                            <div class="producto-img-placeholder" style="display:none;">
                                <i class="bi bi-image" style="font-size:40px;color:#ccc;"></i>
                            </div>
                        <?php else: ?>
                            <div class="producto-img-placeholder">
                                <i class="bi bi-image" style="font-size:40px;color:#ccc;"></i>
                            </div>
                        <?php endif; ?>

                        <div class="productos-lista">
                            <h4><i class="bi bi-check2-all"></i> Productos que recibiste:</h4>
                            <?php foreach ($productos as $prod): ?>
                                <div class="producto-fila">
                                    <span style="flex:1; font-weight:600;"><?php echo htmlspecialchars($prod['nombre_producto']); ?></span>
                                    <span class="badge-cant"><?php echo $prod['cantidad']; ?> <?php echo htmlspecialchars($prod['unidad_medida'] ?? 'und'); ?></span>
                                    <span style="color:var(--primary); font-weight:700;">$<?php echo number_format($prod['total'], 0); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="aviso-edicion">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div>
                        <strong>Al guardar, tu resena volvera a estado pendiente</strong> y debera ser aprobada nuevamente por el administrador antes de publicarse.
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="formEditar">
                    <input type="hidden" name="actualizar_calificacion" value="1">

                    <div class="calificacion-section">
                        <div class="rating-container">
                            <div class="rating-title">Como fue tu experiencia?</div>
                            <div class="rating-subtitle">Toca las estrellas para cambiar la calificacion</div>

                            <input type="hidden" name="calificacion" id="calificacion-hidden"
                                   value="<?php echo $cal_actual; ?>">

                            <div class="stars-wrap">
                                <span class="star<?php echo $cal_actual >= 1 ? ' activa' : ''; ?>" data-value="1">&#9733;</span>
                                <span class="star<?php echo $cal_actual >= 2 ? ' activa' : ''; ?>" data-value="2">&#9733;</span>
                                <span class="star<?php echo $cal_actual >= 3 ? ' activa' : ''; ?>" data-value="3">&#9733;</span>
                                <span class="star<?php echo $cal_actual >= 4 ? ' activa' : ''; ?>" data-value="4">&#9733;</span>
                                <span class="star<?php echo $cal_actual >= 5 ? ' activa' : ''; ?>" data-value="5">&#9733;</span>
                            </div>

                            <div class="rating-description" id="ratingDescription">
                                <?php
                                $desc_map = [
                                    1 => 'Malo - No cumplio las expectativas',
                                    2 => 'Regular - Tiene aspectos a mejorar',
                                    3 => 'Bueno - Cumplio las expectativas',
                                    4 => 'Muy bueno - Supero las expectativas',
                                    5 => 'Excelente - Increible experiencia!',
                                ];
                                echo $cal_actual > 0
                                    ? htmlspecialchars($desc_map[$cal_actual])
                                    : 'Selecciona una calificacion';
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="calificacion-section">
                        <div class="resena-container">
                            <label for="resena">
                                <i class="bi bi-chat-left-text"></i> Tu comentario
                                <span style="font-weight:400; color:var(--text-muted);">(opcional)</span>
                            </label>
                            <textarea name="resena" id="resena" maxlength="1000"
                                placeholder="Como estuvo la calidad del producto? Lo recomendarias?"><?php echo htmlspecialchars($resena_actual['resena'] ?? ''); ?></textarea>
                            <div class="char-counter">
                                <span id="charCount"><?php echo strlen($resena_actual['resena'] ?? ''); ?></span> / 1000
                            </div>
                        </div>
                    </div>

                    <div class="calificacion-section">
                        <h4 style="font-size:16px; font-weight:700; margin:0 0 16px;">
                            <i class="bi bi-images"></i> Fotos actuales
                        </h4>

                        <?php if (!empty($imagenes_actuales)): ?>
                            <div class="fotos-actuales-grid" id="fotosActualesGrid">
                                <?php foreach ($imagenes_actuales as $idx => $img): ?>
                                    <div class="foto-actual-item" id="foto-item-<?php echo $idx; ?>">
                                        <img src="<?php echo htmlspecialchars(urlImagen($img)); ?>"
                                             alt="Foto resena"
                                             onerror="this.src=''; this.style.background='#f1f3f5';">
                                        <input type="checkbox"
                                               name="imagenes_mantener[]"
                                               value="<?php echo htmlspecialchars($img); ?>"
                                               id="mantener-<?php echo $idx; ?>"
                                               checked style="display:none;">
                                        <button type="button" class="btn-eliminar-foto"
                                                onclick="toggleFoto(<?php echo $idx; ?>)"
                                                id="btn-foto-<?php echo $idx; ?>">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p style="font-size:13px; color:var(--text-muted); margin-top:8px;">
                                <i class="bi bi-info-circle"></i> Haz clic en la x para quitar una foto. Puedes restaurarla con el boton verde.
                            </p>
                        <?php else: ?>
                            <p style="color:var(--text-muted); font-size:14px;">
                                <i class="bi bi-image"></i> Esta resena no tiene fotos.
                            </p>
                        <?php endif; ?>

                        <h4 style="font-size:16px; font-weight:700; margin:20px 0 12px;">
                            <i class="bi bi-cloud-upload"></i> Agregar nuevas fotos
                            <span style="font-weight:400; color:var(--text-muted);">(opcional)</span>
                        </h4>
                        <label for="imagenes_nuevas" class="upload-area">
                            <input type="file" name="imagenes_nuevas[]" id="imagenes_nuevas"
                                   accept="image/jpeg,image/png,image/jpg,image/gif"
                                   multiple onchange="previewImages(this)">
                            <div class="upload-icon"><i class="bi bi-cloud-upload"></i></div>
                            <div class="upload-text">Haz clic o arrastra imagenes aqui</div>
                            <div class="upload-hint">Maximo 5 fotos en total · JPG, PNG o GIF · Max. 5 MB c/u</div>
                        </label>
                        <div id="previewContainer" class="preview-container"></div>
                    </div>

                    <div style="display:flex; gap:14px; align-items:center;">
                        <a href="pedidos_comprador.php" class="btn-cancelar">
                            <i class="bi bi-arrow-left-circle"></i> Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary" style="flex:1;">
                            <i class="bi bi-check-circle"></i> Guardar Cambios
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </main>
</div>

<script>
function onImgError(img) {
    img.style.display = 'none';
    var placeholder = img.nextElementSibling;
    if (placeholder) placeholder.style.display = 'flex';
}

var descriptions = {
    1: 'Malo - No cumplio las expectativas',
    2: 'Regular - Tiene aspectos a mejorar',
    3: 'Bueno - Cumplio las expectativas',
    4: 'Muy bueno - Supero las expectativas',
    5: 'Excelente - Increible experiencia!'
};

var stars       = document.querySelectorAll('.stars-wrap .star');
var hiddenInput = document.getElementById('calificacion-hidden');
var descEl      = document.getElementById('ratingDescription');
var valorActual = parseInt(hiddenInput.value) || 0;

function pintarEstrellas(valor) {
    stars.forEach(function(s) {
        s.classList.toggle('activa', parseInt(s.dataset.value) <= valor);
    });
}

stars.forEach(function(star) {
    star.addEventListener('click', function() {
        valorActual       = parseInt(star.dataset.value);
        hiddenInput.value = valorActual;
        pintarEstrellas(valorActual);
        descEl.textContent = descriptions[valorActual];
    });
});

function toggleFoto(idx) {
    var item     = document.getElementById('foto-item-' + idx);
    var checkbox = document.getElementById('mantener-' + idx);
    var btn      = document.getElementById('btn-foto-' + idx);

    if (checkbox.checked) {
        checkbox.checked = false;
        item.classList.add('eliminada');
        btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i>';
        btn.className = 'btn-restaurar';
        btn.title = 'Restaurar foto';
    } else {
        checkbox.checked = true;
        item.classList.remove('eliminada');
        btn.innerHTML = '<i class="bi bi-x"></i>';
        btn.className = 'btn-eliminar-foto';
        btn.title = 'Eliminar foto';
    }
}

function previewImages(input) {
    var container = document.getElementById('previewContainer');
    var files = Array.from(input.files);
    var maxSize = 5 * 1024 * 1024;
    if (files.length > 5) { alert('Solo puedes subir maximo 5 fotos nuevas.'); input.value = ''; return; }
    for (var f of files) {
        if (f.size > maxSize) { alert('"' + f.name + '" supera los 5 MB.'); input.value = ''; return; }
    }
    container.innerHTML = '';
    files.forEach(function(file, i) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var item = document.createElement('div');
            item.className = 'preview-item';
            item.innerHTML =
                '<img src="' + e.target.result + '" alt="Preview">' +
                '<button type="button" class="preview-remove" onclick="removeImage(' + i + ')">' +
                '<i class="bi bi-x"></i></button>';
            container.appendChild(item);
        };
        reader.readAsDataURL(file);
    });
}

function removeImage(index) {
    var input = document.getElementById('imagenes_nuevas');
    var dt    = new DataTransfer();
    Array.from(input.files).forEach(function(f, i) { if (i !== index) dt.items.add(f); });
    input.files = dt.files;
    previewImages(input);
}

var resenaEl   = document.getElementById('resena');
var contadorEl = document.getElementById('charCount');
resenaEl.addEventListener('input', function() {
    contadorEl.textContent = this.value.length;
    contadorEl.style.color = this.value.length > 900
        ? 'var(--danger)' : this.value.length > 700
        ? 'var(--warning)' : 'var(--text-muted)';
});

document.getElementById('formEditar').addEventListener('submit', function(e) {
    if (valorActual === 0) {
        e.preventDefault();
        alert('Por favor selecciona una calificacion.');
    }
});

function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }
document.addEventListener('click', function(e) {
    var sb = document.getElementById('sidebar'), mt = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && sb && !sb.contains(e.target) && mt && !mt.contains(e.target) && sb.classList.contains('open'))
        sb.classList.remove('open');
});
</script>
</body>
</html>