<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'comprador');
require_once __DIR__ . '/../../config/auth_check.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'comprador') {
    header("Location: ../auth/login.php");
    exit();
}

$usuario_id = intval($_SESSION['user_id']);
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

$mensaje = '';
$tipo_mensaje = '';

$id_orden     = isset($_GET['id_orden'])     ? intval($_GET['id_orden'])     : 0;
$id_productor = isset($_GET['id_productor']) ? intval($_GET['id_productor']) : 0;

if ($id_orden <= 0 || $id_productor <= 0) {
    header("Location: pedidos_comprador.php");
    exit();
}

$rv_orden = ejecutarConsulta($conn,
    "SELECT ord.id_orden, ord.fecha
     FROM ordenes ord
     INNER JOIN pagos pag ON pag.id_orden = ord.id_orden AND pag.estado = 'confirmado'
     WHERE ord.id_orden = ? AND ord.id_comprador = ?
     LIMIT 1",
    "ii", [$id_orden, $usuario_id]);

if (!$rv_orden || $rv_orden->num_rows === 0) {
    $_SESSION['mensaje_temporal'] = "No puedes calificar este productor en esta orden.";
    $_SESSION['tipo_mensaje_temporal'] = "danger";
    header("Location: pedidos_comprador.php");
    exit();
}
$orden_row = $rv_orden->fetch_assoc();

$rv_ped = ejecutarConsulta($conn,
    "SELECT COUNT(*) AS total FROM pedidos
     WHERE id_orden = ? AND id_productor = ? AND id_comprador = ? AND estado = 'entregado'",
    "iii", [$id_orden, $id_productor, $usuario_id]);

if (!$rv_ped || ($rv_ped->fetch_assoc())['total'] == 0) {
    $_SESSION['mensaje_temporal'] = "Solo puedes calificar productores que ya entregaron.";
    $_SESSION['tipo_mensaje_temporal'] = "warning";
    header("Location: pedidos_comprador.php");
    exit();
}

$rv_prod = ejecutarConsulta($conn,
    "SELECT u.nombre AS nombre_productor, u.id_usuario AS id_productor,
            pp.foto_perfil, pp.descripcion AS descripcion_productor
     FROM usuarios u
     LEFT JOIN perfiles_productor pp ON pp.id_productor = u.id_usuario
     WHERE u.id_usuario = ? LIMIT 1",
    "i", [$id_productor]);

if (!$rv_prod || $rv_prod->num_rows === 0) {
    header("Location: pedidos_comprador.php");
    exit();
}

$datos = array_merge($rv_prod->fetch_assoc(), [
    'id_orden'  => $orden_row['id_orden'],
    'fecha'     => $orden_row['fecha'],
    'total_pedidos_entregados' => 0,
]);

$rv_cnt = ejecutarConsulta($conn,
    "SELECT COUNT(*) AS total FROM pedidos
     WHERE id_orden = ? AND id_productor = ? AND id_comprador = ? AND estado = 'entregado'",
    "iii", [$id_orden, $id_productor, $usuario_id]);
if ($rv_cnt) $datos['total_pedidos_entregados'] = $rv_cnt->fetch_assoc()['total'];

$result_ya = ejecutarConsulta($conn,
    "SELECT id_calificacion FROM calificaciones_resenas
     WHERE id_productor = ? AND id_comprador = ? AND id_orden = ?",
    "iii", [$id_productor, $usuario_id, $id_orden]);
if ($result_ya && $result_ya->num_rows > 0) {
    $_SESSION['mensaje_temporal'] = "Ya calificaste a este productor en esta orden.";
    $_SESSION['tipo_mensaje_temporal'] = "info";
    header("Location: pedidos_comprador.php");
    exit();
}

$result_productos = ejecutarConsulta($conn,
    "SELECT ped.id_pedido, ped.cantidad, ped.total,
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
$id_pedido_ref    = 0;
foreach ($productos as $p) {
    if ($id_pedido_ref === 0) $id_pedido_ref = intval($p['id_pedido']);
    if ($imagen_principal === null && !empty($p['imagen_producto'])) {
        $imagen_principal = $p['imagen_producto'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_calificacion'])) {
    $calificacion = isset($_POST['calificacion']) ? intval($_POST['calificacion']) : 0;
    $resena       = trim($_POST['resena'] ?? '');

    if ($calificacion < 1 || $calificacion > 5) {
        $mensaje      = "Por favor selecciona una calificacion.";
        $tipo_mensaje = "danger";
    } else {
        $imagenes_guardadas = [];
        if (isset($_FILES['imagenes']) && !empty($_FILES['imagenes']['name'][0])) {
            $directorio = "../../uploads/calificaciones/";
            if (!file_exists($directorio)) mkdir($directorio, 0777, true);
            $maxImg  = 5;
            $maxSize = 5 * 1024 * 1024;
            $exts_ok = ['jpg', 'jpeg', 'png', 'gif'];
            $total   = count($_FILES['imagenes']['name']);
            for ($i = 0; $i < min($total, $maxImg); $i++) {
                if ($_FILES['imagenes']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['imagenes']['size'][$i] > $maxSize) continue;
                $ext = strtolower(pathinfo($_FILES['imagenes']['name'][$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $exts_ok)) continue;
                $nombre = "calif_ord{$id_orden}_prod{$id_productor}_" . time() . "_{$i}.{$ext}";
                if (move_uploaded_file($_FILES['imagenes']['tmp_name'][$i], $directorio . $nombre)) {
                    $imagenes_guardadas[] = "uploads/calificaciones/" . $nombre;
                }
            }
        }

        $imagenes_json = !empty($imagenes_guardadas) ? json_encode($imagenes_guardadas) : null;

        $result_insert = ejecutarConsulta($conn,
            "INSERT INTO calificaciones_resenas
                (id_productor, id_comprador, id_pedido, id_orden, calificacion, resena, imagenes, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente')",
            "iiiiiss",
            [$id_productor, $usuario_id, $id_pedido_ref, $id_orden, $calificacion, $resena, $imagenes_json]);

        if ($result_insert) {
            $_SESSION['mensaje_temporal'] = "Gracias por tu calificacion! Sera revisada antes de publicarse.";
            $_SESSION['tipo_mensaje_temporal'] = "success";
            header("Location: pedidos_comprador.php");
            exit();
        } else {
            $mensaje      = "Error al enviar la calificacion. Intenta nuevamente.";
            $tipo_mensaje = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Califica al Productor - QuiliAgro Connect</title>
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

        .imagenes-container .label-titulo {
            font-size: 16px; font-weight: 700; color: var(--text-dark);
            margin-bottom: 10px; display: block;
        }
        .upload-area {
            border: 3px dashed var(--primary);
            border-radius: var(--radius-lg); padding: 36px;
            text-align: center;
            background: linear-gradient(135deg, #f0f8f4, #e8f5e9);
            cursor: pointer; transition: var(--transition); display: block;
        }
        .upload-area:hover { background: linear-gradient(135deg, #e8f5e9, #d4edda); transform: translateY(-2px); }
        .upload-area input[type="file"] { display: none; }
        .upload-icon { font-size: 52px; color: var(--primary); margin-bottom: 12px; }
        .upload-text { font-size: 15px; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }
        .upload-hint { font-size: 13px; color: var(--text-muted); }

        .preview-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 12px; margin-top: 16px; }
        .preview-item { position: relative; aspect-ratio: 1; border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-sm); border: 3px solid white; }
        .preview-item img { width: 100%; height: 100%; object-fit: cover; }
        .preview-remove {
            position: absolute; top: 6px; right: 6px;
            background: var(--danger); color: white;
            border: none; width: 28px; height: 28px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 16px; box-shadow: 0 2px 6px rgba(0,0,0,.3); transition: var(--transition);
        }
        .preview-remove:hover { background: #c82333; transform: scale(1.1); }

        .aviso-moderacion {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-left: 5px solid #2196f3;
            border-radius: var(--radius);
            padding: 16px 20px; margin-bottom: 22px;
            display: flex; align-items: flex-start; gap: 12px;
            font-size: 14px; color: #1565c0;
        }
        .aviso-moderacion i { font-size: 22px; flex-shrink: 0; margin-top: 2px; }

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
                <h1>Califica al Productor</h1>
                <p class="subtitle">Tu opinion ayuda a mejorar la comunidad agricola</p>
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
                    <?php if (!empty($datos['foto_perfil'])): ?>
                        <img src="<?php echo htmlspecialchars(urlImagen($datos['foto_perfil'])); ?>"
                             alt="<?php echo htmlspecialchars($datos['nombre_productor']); ?>"
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
                        <h2><?php echo htmlspecialchars($datos['nombre_productor']); ?></h2>
                        <p><i class="bi bi-bag-check"></i> <?php echo $datos['total_pedidos_entregados']; ?> producto(s) entregado(s) en esta orden</p>
                        <p><i class="bi bi-calendar3"></i> Orden del <?php echo date('d/m/Y', strtotime($datos['fecha'])); ?></p>
                    </div>
                </div>

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
                                    <span style="flex:1; font-weight:600;">
                                        <?php echo htmlspecialchars($prod['nombre_producto']); ?>
                                    </span>
                                    <span class="badge-cant">
                                        <?php echo $prod['cantidad']; ?> <?php echo htmlspecialchars($prod['unidad_medida'] ?? 'und'); ?>
                                    </span>
                                    <span style="color:var(--primary); font-weight:700;">
                                        $<?php echo number_format($prod['total'], 0); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="aviso-moderacion">
                    <i class="bi bi-shield-check"></i>
                    <div>
                        <strong>Tu calificacion sera revisada por nuestro equipo</strong> antes de publicarse.
                        Esto garantiza un espacio respetuoso y confiable para toda la comunidad.
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="formCalificacion">
                    <input type="hidden" name="enviar_calificacion" value="1">

                    <div class="calificacion-section">
                        <div class="rating-container">
                            <div class="rating-title">Como fue tu experiencia?</div>
                            <div class="rating-subtitle">Toca las estrellas para calificar al productor</div>
                            <input type="hidden" name="calificacion" id="calificacion-hidden" value="0">
                            <div class="stars-wrap">
                                <span class="star" data-value="1">&#9733;</span>
                                <span class="star" data-value="2">&#9733;</span>
                                <span class="star" data-value="3">&#9733;</span>
                                <span class="star" data-value="4">&#9733;</span>
                                <span class="star" data-value="5">&#9733;</span>
                            </div>
                            <div class="rating-description" id="ratingDescription">
                                Selecciona una calificacion
                            </div>
                        </div>
                    </div>

                    <div class="calificacion-section">
                        <div class="resena-container">
                            <label for="resena">
                                <i class="bi bi-chat-left-text"></i> Cuentanos tu experiencia
                                <span style="font-weight:400; color:var(--text-muted);">(opcional)</span>
                            </label>
                            <textarea name="resena" id="resena" maxlength="1000"
                                placeholder="Como estuvo la calidad del producto? La atencion del productor fue buena? Lo recomendarias?"></textarea>
                            <div class="char-counter">
                                <span id="charCount">0</span> / 1000
                            </div>
                        </div>
                    </div>

                    <div class="calificacion-section">
                        <div class="imagenes-container">
                            <span class="label-titulo">
                                <i class="bi bi-images"></i> Agrega fotos
                                <span style="font-weight:400; color:var(--text-muted);">(opcional)</span>
                            </span>
                            <label for="imagenes" class="upload-area">
                                <input type="file" name="imagenes[]" id="imagenes"
                                       accept="image/jpeg,image/png,image/jpg,image/gif"
                                       multiple onchange="previewImages(this)">
                                <div class="upload-icon"><i class="bi bi-cloud-upload"></i></div>
                                <div class="upload-text">Haz clic o arrastra imagenes aqui</div>
                                <div class="upload-hint">Maximo 5 fotos · JPG, PNG o GIF · Max. 5 MB cada una</div>
                            </label>
                            <div id="previewContainer" class="preview-container"></div>
                        </div>
                    </div>

                    <div style="display:flex; gap:14px; align-items:center;">
                        <a href="pedidos_comprador.php" class="btn-cancelar">
                            <i class="bi bi-arrow-left-circle"></i> Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary" style="flex:1;">
                            <i class="bi bi-send-check"></i> Enviar Calificacion
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
var valorActual = 0;

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

var resenaEl   = document.getElementById('resena');
var contadorEl = document.getElementById('charCount');
resenaEl.addEventListener('input', function() {
    contadorEl.textContent = this.value.length;
    contadorEl.style.color = this.value.length > 900
        ? 'var(--danger)' : this.value.length > 700
        ? 'var(--warning)' : 'var(--text-muted)';
});

var archivosSeleccionados = new DataTransfer();

function previewImages(input) {
    var container = document.getElementById('previewContainer');
    var maxSize = 5 * 1024 * 1024;

    Array.from(input.files).forEach(function(f) {
        if (archivosSeleccionados.items.length >= 5) {
            alert('Solo puedes subir maximo 5 fotos.'); return;
        }
        if (f.size > maxSize) {
            alert('"' + f.name + '" supera los 5 MB.'); return;
        }
        archivosSeleccionados.items.add(f);
    });

    input.files = archivosSeleccionados.files;
    renderPreview();
}

function renderPreview() {
    var container = document.getElementById('previewContainer');
    container.innerHTML = '';
    Array.from(archivosSeleccionados.files).forEach(function(file, i) {
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
    var input = document.getElementById('imagenes');
    var dt = new DataTransfer();
    Array.from(archivosSeleccionados.files).forEach(function(f, i) {
        if (i !== index) dt.items.add(f);
    });
    archivosSeleccionados = dt;
    input.files = dt.files;
    renderPreview();
}

document.getElementById('formCalificacion').addEventListener('submit', function(e) {
    if (valorActual === 0) {
        e.preventDefault();
        alert('Por favor selecciona una calificacion.');
    }
});

function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }
document.addEventListener('click', function(e) {
    var sb = document.getElementById('sidebar');
    var mt = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && sb && !sb.contains(e.target) && mt && !mt.contains(e.target) && sb.classList.contains('open'))
        sb.classList.remove('open');
});
</script>
</body>
</html>