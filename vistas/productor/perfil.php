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
            if (!$stmt) {
                throw new Exception($conn->error);
            }
            $stmt->bind_param($tipos, ...$params);
            $ok = $stmt->execute();
            
            if (stripos(trim($query), 'select') === 0) {
                return $stmt->get_result();
            }
            return $ok;
        } else {
            $result = $conn->query($query);
            if (!$result) {
                throw new Exception($conn->error);
            }
            return $result;
        }
    } catch (Exception $e) {
        error_log('Error BD: ' . $e->getMessage());
        return false;
    }
}

$mensaje = '';
$tipo_mensaje = '';
$modo_edicion = false;

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'actualizado') {
        $mensaje = "✅ Perfil actualizado exitosamente";
        $tipo_mensaje = "success";
    } elseif ($_GET['msg'] === 'creado') {
        $mensaje = "✅ Perfil creado exitosamente";
        $tipo_mensaje = "success";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'actualizar_foto_perfil') {
        if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (in_array($_FILES['foto_perfil']['type'], $allowed_types) && 
                $_FILES['foto_perfil']['size'] <= $max_size) {
                
                $upload_dir = '../../uploads/perfiles/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $extension = pathinfo($_FILES['foto_perfil']['name'], PATHINFO_EXTENSION);
                $nombre_archivo = 'perfil_' . $usuario_id . '_' . time() . '.' . $extension;
                $ruta_destino = $upload_dir . $nombre_archivo;
                
                if (move_uploaded_file($_FILES['foto_perfil']['tmp_name'], $ruta_destino)) {
                    $foto_perfil = 'uploads/perfiles/' . $nombre_archivo;
                    
                    $query_check = "SELECT id_perfil, foto_perfil FROM perfiles_productor WHERE id_productor = ?";
                    $result_check = ejecutarConsulta($conn, $query_check, "i", [$usuario_id]);
                    $perfil_existe = $result_check && $result_check->num_rows > 0;
                    
                    if ($perfil_existe) {
                        $perfil_actual = $result_check->fetch_assoc();
                        $query = "UPDATE perfiles_productor SET foto_perfil = ? WHERE id_productor = ?";
                        $result = ejecutarConsulta($conn, $query, "si", [$foto_perfil, $usuario_id]);
                        
                        if ($perfil_actual['foto_perfil'] && file_exists('../../' . $perfil_actual['foto_perfil'])) {
                            unlink('../../' . $perfil_actual['foto_perfil']);
                        }
                    } else {
                        $query = "INSERT INTO perfiles_productor (id_productor, nombre_finca, foto_perfil) VALUES (?, ?, ?)";
                        $result = ejecutarConsulta($conn, $query, "iss", [$usuario_id, $usuario_nombre, $foto_perfil]);
                    }
                    
                    if ($result) {
                        $mensaje = "✅ Foto de perfil actualizada exitosamente";
                        $tipo_mensaje = "success";
                    } else {
                        $mensaje = "Error al actualizar la foto de perfil";
                        $tipo_mensaje = "danger";
                    }
                } else {
                    $mensaje = "Error al subir la imagen";
                    $tipo_mensaje = "danger";
                }
            } else {
                $mensaje = "Formato de imagen no válido o tamaño muy grande (máx. 5MB)";
                $tipo_mensaje = "danger";
            }
        } else {
            $mensaje = "No se seleccionó ninguna imagen";
            $tipo_mensaje = "warning";
        }
    }
    
    if ($accion === 'actualizar_perfil') {
        $nombre_finca = trim($_POST['nombre_finca']);
        $descripcion = trim($_POST['descripcion']);
        $ubicacion = trim($_POST['ubicacion']);
        $tipo_produccion = trim($_POST['tipo_produccion']);
        $experiencia = trim($_POST['experiencia']);
        
        $query_check = "SELECT id_perfil, foto_perfil FROM perfiles_productor WHERE id_productor = ?";
        $result_check = ejecutarConsulta($conn, $query_check, "i", [$usuario_id]);
        $perfil_existe = $result_check && $result_check->num_rows > 0;
        
        $foto_perfil = null;
        if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (in_array($_FILES['foto_perfil']['type'], $allowed_types) && 
                $_FILES['foto_perfil']['size'] <= $max_size) {
                
                $upload_dir = '../../uploads/perfiles/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $extension = pathinfo($_FILES['foto_perfil']['name'], PATHINFO_EXTENSION);
                $nombre_archivo = 'perfil_' . $usuario_id . '_' . time() . '.' . $extension;
                $ruta_destino = $upload_dir . $nombre_archivo;
                
                if (move_uploaded_file($_FILES['foto_perfil']['tmp_name'], $ruta_destino)) {
                    $foto_perfil = 'uploads/perfiles/' . $nombre_archivo;
                    
                    if ($perfil_existe) {
                        $perfil_actual = $result_check->fetch_assoc();
                        if ($perfil_actual['foto_perfil'] && file_exists('../../' . $perfil_actual['foto_perfil'])) {
                            unlink('../../' . $perfil_actual['foto_perfil']);
                        }
                    }
                }
            } else {
                $mensaje = "Formato de imagen no válido o tamaño muy grande (máx. 5MB)";
                $tipo_mensaje = "danger";
            }
        }
        
       if (empty($mensaje)) {
            if ($perfil_existe) {
                if ($foto_perfil) {
                    $query = "UPDATE perfiles_productor SET 
                             nombre_finca = ?, descripcion = ?, ubicacion = ?, 
                             tipo_produccion = ?, experiencia = ?, foto_perfil = ?
                             WHERE id_productor = ?";
                    $result = ejecutarConsulta($conn, $query, "ssssssi", [
                        $nombre_finca, $descripcion, $ubicacion, 
                        $tipo_produccion, $experiencia, $foto_perfil, $usuario_id
                    ]);
                } else {
                    $query = "UPDATE perfiles_productor SET 
                             nombre_finca = ?, descripcion = ?, ubicacion = ?, 
                             tipo_produccion = ?, experiencia = ?
                             WHERE id_productor = ?";
                    $result = ejecutarConsulta($conn, $query, "sssssi", [
                        $nombre_finca, $descripcion, $ubicacion, 
                        $tipo_produccion, $experiencia, $usuario_id
                    ]);
                }
                if ($result) {
                    header("Location: perfil.php?msg=actualizado");
                    exit();
                } else {
                    $mensaje = "Error al actualizar el perfil";
                    $tipo_mensaje = "danger";
                    $modo_edicion = true;
                }
            } else {
                $query = "INSERT INTO perfiles_productor 
                         (id_productor, nombre_finca, descripcion, ubicacion, tipo_produccion, experiencia, foto_perfil) 
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
                $result = ejecutarConsulta($conn, $query, "issssss", [
                    $usuario_id, $nombre_finca, $descripcion, $ubicacion, 
                    $tipo_produccion, $experiencia, $foto_perfil
                ]);
                if ($result) {
                    header("Location: perfil.php?msg=creado");
                    exit();
                } else {
                    $mensaje = "Error al crear el perfil";
                    $tipo_mensaje = "danger";
                    $modo_edicion = true;
                }
            }
        } else {
            $modo_edicion = true;
        }
    }
    
    if ($accion === 'subir_fotos_grupo') {
        if (isset($_FILES['fotos_grupo']) && !empty($_FILES['fotos_grupo']['name'][0])) {
            $total = count($_FILES['fotos_grupo']['name']);
            $subidas_exitosas = 0;
            $descripcion_grupo = trim($_POST['descripcion_grupo'] ?? '');
            
            $id_grupo = 'grupo_' . $usuario_id . '_' . time();
            
            for ($i = 0; $i < $total; $i++) {
                if ($_FILES['fotos_grupo']['error'][$i] === UPLOAD_ERR_OK) {
                    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
                    $max_size = 5 * 1024 * 1024; // 5MB
                    
                    if (in_array($_FILES['fotos_grupo']['type'][$i], $allowed_types) && 
                        $_FILES['fotos_grupo']['size'][$i] <= $max_size) {
                        
                        $upload_dir = '../../uploads/fincas/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        $extension = pathinfo($_FILES['fotos_grupo']['name'][$i], PATHINFO_EXTENSION);
                        $nombre_archivo = 'finca_' . $usuario_id . '_' . time() . '_' . $i . '.' . $extension;
                        $ruta_destino = $upload_dir . $nombre_archivo;
                        
                        if (move_uploaded_file($_FILES['fotos_grupo']['tmp_name'][$i], $ruta_destino)) {
                            $ruta_foto = 'uploads/fincas/' . $nombre_archivo;
                            
                            $query = "INSERT INTO fotos_finca (id_productor, ruta_foto, descripcion, grupo_id) VALUES (?, ?, ?, ?)";
                            $result = ejecutarConsulta($conn, $query, "isss", [
                                $usuario_id, 
                                $ruta_foto, 
                                $descripcion_grupo,
                                $id_grupo
                            ]);
                            
                            if ($result) {
                                $subidas_exitosas++;
                            } else {
                                if (file_exists($ruta_destino)) {
                                    unlink($ruta_destino);
                                }
                            }
                        }
                    }
                }
            }
            
            if ($subidas_exitosas > 0) {
                $mensaje = "✅ " . $subidas_exitosas . " foto(s) agregadas al grupo exitosamente";
                $tipo_mensaje = "success";
            } else {
                $mensaje = "Error al subir las fotos. Verifica que las imágenes sean válidas (JPG, PNG) y no excedan 5MB cada una.";
                $tipo_mensaje = "danger";
            }
        } else {
            $mensaje = "No se seleccionaron fotos para subir";
            $tipo_mensaje = "warning";
        }
    }
    
    if ($accion === 'eliminar_foto') {
        $id_foto = intval($_POST['id_foto']);
        
        $query_check = "SELECT ruta_foto FROM fotos_finca WHERE id_foto = ? AND id_productor = ?";
        $result_check = ejecutarConsulta($conn, $query_check, "ii", [$id_foto, $usuario_id]);
        
        if ($result_check && $result_check->num_rows > 0) {
            $foto = $result_check->fetch_assoc();
            
            if (file_exists('../../' . $foto['ruta_foto'])) {
                unlink('../../' . $foto['ruta_foto']);
            }
            
            $query = "DELETE FROM fotos_finca WHERE id_foto = ?";
            $result = ejecutarConsulta($conn, $query, "i", [$id_foto]);
            
            if ($result) {
                $mensaje = "✅ Foto eliminada exitosamente";
                $tipo_mensaje = "success";
            } else {
                $mensaje = "Error al eliminar la foto";
                $tipo_mensaje = "danger";
            }
        } else {
            $mensaje = "Foto no encontrada";
            $tipo_mensaje = "danger";
        }
    }
    
    if ($accion === 'actualizar_descripcion_grupo') {
        $grupo_id = $_POST['grupo_id'];
        $nueva_descripcion = trim($_POST['nueva_descripcion']);
        
        $query = "UPDATE fotos_finca SET descripcion = ? WHERE grupo_id = ? AND id_productor = ?";
        $result = ejecutarConsulta($conn, $query, "ssi", [$nueva_descripcion, $grupo_id, $usuario_id]);
        
        if ($result) {
            $mensaje = "✅ Descripción del la foto actualizada exitosamente";
            $tipo_mensaje = "success";
        } else {
            $mensaje = "Error al actualizar la descripción de la foto";
            $tipo_mensaje = "danger";
        }
    }
    
    if ($accion === 'eliminar_grupo') {
        $grupo_id = $_POST['grupo_id'];
        
        $query_fotos = "SELECT ruta_foto FROM fotos_finca WHERE grupo_id = ? AND id_productor = ?";
        $result_fotos = ejecutarConsulta($conn, $query_fotos, "si", [$grupo_id, $usuario_id]);
        
        if ($result_fotos && $result_fotos->num_rows > 0) {
            while ($foto = $result_fotos->fetch_assoc()) {
                if (file_exists('../../' . $foto['ruta_foto'])) {
                    unlink('../../' . $foto['ruta_foto']);
                }
            }
            
            $query = "DELETE FROM fotos_finca WHERE grupo_id = ? AND id_productor = ?";
            $result = ejecutarConsulta($conn, $query, "si", [$grupo_id, $usuario_id]);
            
            if ($result) {
                $mensaje = "✅ Foto eliminada exitosamente";
                $tipo_mensaje = "success";
            } else {
                $mensaje = "Error al eliminar la foto";
                $tipo_mensaje = "danger";
            }
        } else {
            $mensaje = "Grupo no encontrado";
            $tipo_mensaje = "danger";
        }
    }
}

$query_perfil = "SELECT * FROM perfiles_productor WHERE id_productor = ?";
$result_perfil = ejecutarConsulta($conn, $query_perfil, "i", [$usuario_id]);
$perfil = $result_perfil && $result_perfil->num_rows > 0 ? $result_perfil->fetch_assoc() : null;

$perfil_completo = false;
if ($perfil) {
    $campos_requeridos = ['nombre_finca', 'descripcion', 'ubicacion', 'tipo_produccion', 'experiencia'];
    $todos_completos = true;
    foreach ($campos_requeridos as $campo) {
        if (!isset($perfil[$campo]) || trim($perfil[$campo]) === '') {
            $todos_completos = false;
            break;
        }
    }
    $perfil_completo = $todos_completos;
}

if (!$perfil || !$perfil_completo) {
    $modo_edicion = true;
} elseif (isset($_GET['editar']) && $_GET['editar'] == '1') {
    $modo_edicion = true;
}


$query_grupos = "SELECT grupo_id, descripcion, COUNT(*) as cantidad_fotos, 
                MIN(fecha_subida) as fecha_grupo
                FROM fotos_finca 
                WHERE id_productor = ? 
                AND grupo_id IS NOT NULL
                GROUP BY grupo_id 
                ORDER BY fecha_grupo DESC";
$result_grupos = ejecutarConsulta($conn, $query_grupos, "i", [$usuario_id]);

$grupos_con_fotos = [];
if ($result_grupos && $result_grupos->num_rows > 0) {
    while ($grupo = $result_grupos->fetch_assoc()) {
        $query_fotos_grupo = "SELECT * FROM fotos_finca 
                             WHERE grupo_id = ? AND id_productor = ?
                             ORDER BY fecha_subida ASC";
        $result_fotos_grupo = ejecutarConsulta($conn, $query_fotos_grupo, "si", [
            $grupo['grupo_id'], 
            $usuario_id
        ]);
        
        $fotos_grupo = [];
        while ($foto = $result_fotos_grupo->fetch_assoc()) {
            $fotos_grupo[] = $foto;
        }
        
        $grupo['fotos'] = $fotos_grupo;
        $grupos_con_fotos[] = $grupo;
    }
}

$query_fotos_sin_grupo = "SELECT * FROM fotos_finca 
                         WHERE id_productor = ? 
                         AND (grupo_id IS NULL OR grupo_id = '')
                         ORDER BY fecha_subida DESC";
$result_fotos_sin_grupo = ejecutarConsulta($conn, $query_fotos_sin_grupo, "i", [$usuario_id]);

if ($result_fotos_sin_grupo && $result_fotos_sin_grupo->num_rows > 0) {
    $fotos_sin_grupo = [];
    while ($foto = $result_fotos_sin_grupo->fetch_assoc()) {
        $fotos_sin_grupo[] = $foto;
    }
    
    if (!empty($fotos_sin_grupo)) {
        $grupo_sin_grupo = [
            'grupo_id' => 'sin_grupo_' . $usuario_id,
            'descripcion' => 'Fotos individuales',
            'cantidad_fotos' => count($fotos_sin_grupo),
            'fecha_grupo' => $fotos_sin_grupo[0]['fecha_subida'],
            'fotos' => $fotos_sin_grupo
        ];
        
        array_unshift($grupos_con_fotos, $grupo_sin_grupo);
    }
}

$query_usuario = "SELECT * FROM usuarios WHERE id_usuario = ?";
$result_usuario = ejecutarConsulta($conn, $query_usuario, "i", [$usuario_id]);
$usuario = $result_usuario->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - QuiliAgro Connect</title>
    
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    
    <style>
        .debug-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 12px;
            display: none; 
        }
    </style>
</head>
<body>

<button class="menu-toggle" onclick="toggleMenu()">
    <i class="bi bi-list"></i>
</button>

<form id="formFotoPerfil" method="POST" enctype="multipart/form-data" style="display: none;">
    <input type="file" 
           id="foto_perfil_input_header" 
           name="foto_perfil" 
           accept="image/jpeg,image/png,image/jpg">
    <input type="hidden" name="accion" value="actualizar_foto_perfil">
</form>


<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo">
        </div>

        <nav class="sidebar-menu">
            <a href="panel_productor.php">
                <i class="bi bi-speedometer2"></i> Inicio
            </a>
            <a href="productos_productor.php">
                <i class="bi bi-box-seam"></i> Mis Productos
            </a>
            <a href="pedidos_productor.php">
                <i class="bi bi-clipboard-check"></i> Pedidos
            </a>
            <a href="calificaciones_productor.php">
                <i class="bi bi-star"></i> Reseñas
            </a>
            <a href="perfil.php" class="active">
                <i class="bi bi-person-circle"></i> Mi Perfil
            </a>
            <a href="estadisticas.php">
                <i class="bi bi-bar-chart"></i> Estadísticas
            </a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Mi Perfil de Productor</h1>
                <p class="subtitle">Gestiona la información de tu finca y perfil público</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout">
                    <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                </a>
            </div>
        </header>

        
        <div class="content-area">
            <div class="perfil-container">
                
                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                        <i class="bi bi-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill"></i>
                        <?php echo $mensaje; ?>
                    </div>
                <?php endif; ?>

                <?php if ($perfil_completo && !$modo_edicion): ?>
                    <div class="info-completa">
                        <i class="bi bi-check-circle-fill"></i>
                        <div class="info-completa-text">
                            <h3>¡Perfil completado!</h3>
                            <p>Tu perfil está visible para los compradores. Mantén tu información actualizada.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="perfil-header">
                    <div class="perfil-header-content">
                        <div class="foto-perfil-container">
                            <div class="foto-perfil-editable">
                                <?php if ($perfil && $perfil['foto_perfil']): ?>
                                    <img src="../../<?php echo htmlspecialchars($perfil['foto_perfil']); ?>" 
                                        alt="Foto de perfil" 
                                        class="foto-perfil"
                                        id="headerProfileImage">
                                    <div class="foto-perfil-overlay" onclick="abrirSelectorFotoPerfil()">
                                        <i class="bi bi-camera-fill"></i>
                                        <span>Cambiar foto</span>
                                    </div>
                                <?php else: ?>
                                <div class="foto-perfil-placeholder" id="headerProfilePlaceholder"
                                    onclick="abrirSelectorFotoPerfil()" 
                                    style="cursor:pointer; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px;">
                                    <i class="bi bi-person-circle" style="font-size:60px;"></i>
                                    <span style="
                                        font-size: 11px;
                                        font-weight: 600;
                                        color: var(--primary);
                                        background: rgba(255,255,255,0.9);
                                        border: 1px solid var(--primary);
                                        border-radius: 20px;
                                        padding: 2px 10px;
                                        white-space: nowrap;">
                                        <i class="bi bi-camera-fill"></i> Agregar foto
                                    </span>
                                </div>
                            <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="perfil-info">
                            <h1><?php echo $perfil ? htmlspecialchars($perfil['nombre_finca']) : htmlspecialchars($usuario['nombre']); ?></h1>
                            <p><i class="bi bi-person"></i> <?php echo htmlspecialchars($usuario['nombre']); ?></p>
                            <p><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($usuario['email']); ?></p>
                            <p><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($usuario['telefono']); ?></p>
                            <?php if ($perfil && $perfil['ubicacion']): ?>
                                <p><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($perfil['ubicacion']); ?></p>
                            <?php endif; ?>
                            <?php if ($perfil_completo): ?>
                            <span class="perfil-badge">
                                <i class="bi bi-patch-check-fill"></i> Productor Verificado
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="section-card">
                    <h2>
                        <i class="bi bi-info-circle"></i>
                        Información de tu Finca
                        <?php if (!$modo_edicion && $perfil && $perfil_completo): ?>
                            <button onclick="activarModoEdicion()" class="btn btn-primary" style="margin-left: auto; font-size: 14px; padding: 8px 16px;">
                                <i class="bi bi-pencil"></i> Editar Información
                            </button>
                        <?php endif; ?>
                    </h2>
                    
                    <?php if ($modo_edicion): ?>
                        <form method="POST" enctype="multipart/form-data" id="formPerfil">
                            <input type="hidden" name="accion" value="actualizar_perfil">
                        
                            
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="bi bi-building"></i>
                                    Información Básica
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>
                                        <i class="bi bi-house-door"></i>
                                        Nombre de la Finca
                                        <span class="required-mark">*</span>
                                    </label>
                                    <input type="text" 
                                           name="nombre_finca" 
                                           required
                                           maxlength="100"
                                           placeholder="Ej: Finca La Esperanza"
                                           value="<?php echo $perfil ? htmlspecialchars($perfil['nombre_finca']) : ''; ?>"
                                           oninput="updateCharCounter(this, 100, 'counter-nombre')">
                                    <div class="char-counter" id="counter-nombre">
                                        <?php echo $perfil ? strlen($perfil['nombre_finca']) : 0; ?> / 100 caracteres
                                    </div>
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>
                                        <i class="bi bi-geo-alt"></i>
                                        Ubicación
                                        <span class="required-mark">*</span>
                                    </label>
                                    <input type="text" 
                                           name="ubicacion" 
                                           required
                                           maxlength="150"
                                           placeholder="Ej: Vereda El Rosal, Quinchía, Santander"
                                           value="<?php echo $perfil ? htmlspecialchars($perfil['ubicacion']) : ''; ?>"
                                           oninput="updateCharCounter(this, 150, 'counter-ubicacion')">
                                    <div class="char-counter" id="counter-ubicacion">
                                        <?php echo $perfil ? strlen($perfil['ubicacion']) : 0; ?> / 150 caracteres
                                    </div>
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>
                                        <i class="bi bi-basket"></i>
                                        Tipo de Producción
                                        <span class="required-mark">*</span>
                                    </label>
                                    <input type="text" 
                                           name="tipo_produccion" 
                                           required
                                           maxlength="100"
                                           placeholder="Ej: Orgánica, Tradicional, Agroecológica"
                                           value="<?php echo $perfil ? htmlspecialchars($perfil['tipo_produccion']) : ''; ?>"
                                           oninput="updateCharCounter(this, 100, 'counter-tipo')">
                                    <div class="char-counter" id="counter-tipo">
                                        <?php echo $perfil ? strlen($perfil['tipo_produccion']) : 0; ?> / 100 caracteres
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <div class="form-section-title">
                                    <i class="bi bi-file-text"></i>
                                    Descripción y Experiencia
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>
                                        <i class="bi bi-card-text"></i>
                                        Descripción de tu Finca
                                        <span class="required-mark">*</span>
                                    </label>
                                    <textarea name="descripcion" 
                                              required
                                              maxlength="500"
                                              placeholder="Cuéntanos sobre tu finca, qué cultivas, qué animales crías, tu historia, qué te hace especial..."
                                              style="min-height: 140px;"
                                              oninput="updateCharCounter(this, 500, 'counter-descripcion')"><?php echo $perfil ? htmlspecialchars($perfil['descripcion']) : ''; ?></textarea>
                                    <div class="char-counter" id="counter-descripcion">
                                        <?php echo $perfil ? strlen($perfil['descripcion']) : 0; ?> / 500 caracteres
                                    </div>
                                </div>
                                
                                <div class="form-group-enhanced">
                                    <label>
                                        <i class="bi bi-award"></i>
                                        Experiencia
                                        <span class="required-mark">*</span>
                                    </label>
                                    <textarea name="experiencia" 
                                              required
                                              maxlength="400"
                                              placeholder="Cuéntanos sobre tu experiencia como productor, años de trabajo, logros, certificaciones..."
                                              style="min-height: 120px;"
                                              oninput="updateCharCounter(this, 400, 'counter-experiencia')"><?php echo $perfil ? htmlspecialchars($perfil['experiencia']) : ''; ?></textarea>
                                    <div class="char-counter" id="counter-experiencia">
                                        <?php echo $perfil ? strlen($perfil['experiencia']) : 0; ?> / 400 caracteres
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" style="font-size: 16px; padding: 14px 32px;">
                                    <i class="bi bi-check-circle"></i>
                                    Guardar Cambios
                                </button>
                                <?php if ($perfil_completo): ?>
                                <button type="button" class="btn btn-secondary" onclick="cancelarEdicion()" style="font-size: 16px; padding: 14px 32px;">
                                    <i class="bi bi-x-circle"></i>
                                    Cancelar
                                </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php else: ?>
                        <?php if ($perfil): ?>
                            <div class="perfil-info-view">
                                <div class="info-row">
                                    <i class="bi bi-house-door"></i>
                                    <div class="info-label">Nombre de la Finca:</div>
                                    <div class="info-value"><?php echo htmlspecialchars($perfil['nombre_finca']); ?></div>
                                </div>
                                
                                <div class="info-row">
                                    <i class="bi bi-geo-alt"></i>
                                    <div class="info-label">Ubicación:</div>
                                    <div class="info-value"><?php echo htmlspecialchars($perfil['ubicacion']); ?></div>
                                </div>
                                
                                <div class="info-row">
                                    <i class="bi bi-basket"></i>
                                    <div class="info-label">Tipo de Producción:</div>
                                    <div class="info-value"><?php echo htmlspecialchars($perfil['tipo_produccion']); ?></div>
                                </div>
                                
                                <div class="info-row">
                                    <i class="bi bi-card-text"></i>
                                    <div class="info-label">Descripción:</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($perfil['descripcion'])); ?></div>
                                </div>
                                
                                <div class="info-row">
                                    <i class="bi bi-award"></i>
                                    <div class="info-label">Experiencia:</div>
                                    <div class="info-value"><?php echo nl2br(htmlspecialchars($perfil['experiencia'])); ?></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <i class="bi bi-info-circle" style="font-size: 48px; display: block; margin-bottom: 15px;"></i>
                                <p>No tienes un perfil creado. Por favor, completa la información para que los compradores puedan conocerte.</p>
                                <button onclick="activarModoEdicion()" class="btn btn-primary" style="margin-top: 20px;">
                                    <i class="bi bi-plus-circle"></i> Crear Perfil
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="section-card">
                    <h2>
                        <i class="bi bi-images"></i>
                        Fotos de tu Finca
                    </h2>
                    
                    <p style="color: var(--text-muted); margin-bottom: 20px;">
                        <i class="bi bi-info-circle"></i>
                            Comparte fotos de tus cultivos, instalaciones y productos para generar confianza con los compradores.
                    </p>
                    
                    <div class="debug-info">
                        <strong>Debug Info:</strong><br>
                        Total grupos: <?php echo count($grupos_con_fotos); ?><br>
                        Grupos: <?php echo json_encode(array_map(function($g) {
                            return [
                                'grupo_id' => $g['grupo_id'],
                                'cantidad' => $g['cantidad_fotos']
                            ];
                        }, $grupos_con_fotos)); ?>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" id="form-fotos-grupo" style="margin-bottom: 30px;">
                        <input type="hidden" name="accion" value="subir_fotos_grupo">
                        
                        <div class="upload-area-simple" onclick="document.getElementById('fotos_grupo').click();">
                            <div id="grupoPlaceholder">
                                <i class="bi bi-cloud-upload upload-icon"></i>
                                <div class="upload-text">
                                    <h3>Subir fotos</h3>
                                    <p>Clic para seleccionar múltiples fotos (Ctrl+clic o Shift+clic)</p>
                                    <p>Formatos: JPG, PNG - Máx: 5MB por imagen</p>
                                </div>
                            </div>
                            
                            <input type="file" 
                                   id="fotos_grupo" 
                                   name="fotos_grupo[]" 
                                   accept="image/jpeg,image/png,image/jpg" 
                                   style="display: none;"
                                   onchange="handleGrupoFotosChange(this)"
                                   multiple>
                        </div>
                        
                        <div id="descripcion-grupo-container" style="display: none;">
                            <div class="form-group">
                                <label>Descripción para todas las fotos del grupo</label>
                                <input type="text" 
                                       name="descripcion_grupo" 
                                       class="form-control" 
                                       style="max-width: 100%;"
                                       placeholder="Ej: Cultivo de tomates orgánicos - Temporada 2024"
                                       required>
                                <small class="text-muted">Esta descripción se aplicará a todas las fotos que subas ahora.</small>
                            </div>
                            
                            <div id="preview-simple-container" class="preview-simple-container">
                                <div style="font-weight: 600; color: var(--text-dark); margin-bottom: 10px;">
                                    <i class="bi bi-images"></i>
                                    Fotos seleccionadas: <span id="selected-count-simple">0</span>
                                </div>
                                <div id="preview-simple-grid" class="preview-simple-grid"></div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-upload"></i> Subir Fotos
                            </button>
                        </div>
                    </form>
                    
                    <?php if (!empty($grupos_con_fotos)): ?>
                        <div class="carrusel-general">
                            <div class="carrusel-header">
                                <h3 style="margin: 0; color: var(--text-dark);">
                                    <i class="bi bi-collection"></i>
                                    Tus Fotos (<?php echo count($grupos_con_fotos); ?>)
                                </h3>
                            </div>
                            
                            <div class="carrusel-container" id="carruselGrupos">
                                <?php foreach ($grupos_con_fotos as $index => $grupo): ?>
                                    <div class="grupo-fotos" data-grupo-id="<?php echo htmlspecialchars($grupo['grupo_id']); ?>">
                                        <div class="grupo-header">
                                            <div class="grupo-titulo">
                                                <span>Fotos</span>
                                                <span style="background: var(--primary); color: white; padding: 2px 8px; border-radius: 10px; font-size: 12px;">
                                                    <?php echo $grupo['cantidad_fotos']; ?> foto(s)
                                                </span>
                                            </div>
                                            <div class="grupo-descripcion" id="descripcion-<?php echo htmlspecialchars($grupo['grupo_id']); ?>">
                                                <?php echo $grupo['descripcion'] ? htmlspecialchars($grupo['descripcion']) : '<em style="color: var(--text-muted);">Sin descripción</em>'; ?>
                                            </div>
                                            <div class="grupo-info">
                                                <span>
                                                    <i class="bi bi-calendar"></i>
                                                    <?php echo date('d/m/Y', strtotime($grupo['fecha_grupo'])); ?>
                                                </span>
                                                <span>
                                                    <i class="bi bi-images"></i>
                                                    Grupo <?php echo $index + 1; ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="mini-carrusel" id="miniCarrusel-<?php echo htmlspecialchars($grupo['grupo_id']); ?>">
                                            <div class="mini-carrusel-inner">
                                                <?php foreach ($grupo['fotos'] as $fotoIndex => $foto): ?>
                                                    <div class="mini-carrusel-item">
                                                        <img src="../../<?php echo htmlspecialchars($foto['ruta_foto']); ?>" 
                                                             alt="Foto de finca"
                                                             class="mini-carrusel-img"
                                                             onclick="abrirModalFotoIndividual('../../<?php echo htmlspecialchars($foto['ruta_foto']); ?>')"
                                                             data-foto-id="<?php echo $foto['id_foto']; ?>">
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <button class="mini-carrusel-nav mini-carrusel-prev" onclick="navMiniCarrusel('<?php echo htmlspecialchars($grupo['grupo_id']); ?>', -1)">‹</button>
                                            <button class="mini-carrusel-nav mini-carrusel-next" onclick="navMiniCarrusel('<?php echo htmlspecialchars($grupo['grupo_id']); ?>', 1)">›</button>
                                            <div class="mini-carrusel-counter">
                                                <span id="counter-<?php echo htmlspecialchars($grupo['grupo_id']); ?>">1/<?php echo count($grupo['fotos']); ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="grupo-acciones">
                                            <button class="btn-grupo btn-ver-grupo" onclick="verGrupoCompleto('<?php echo htmlspecialchars($grupo['grupo_id']); ?>')">
                                                <i class="bi bi-eye"></i> Ver Fotos
                                            </button>
                                            <button class="btn-grupo btn-editar-grupo" onclick="editarDescripcionGrupo('<?php echo htmlspecialchars($grupo['grupo_id']); ?>', '<?php echo htmlspecialchars($grupo['descripcion'] ?? ''); ?>')">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn-grupo btn-eliminar-grupo" onclick="eliminarGrupo('<?php echo htmlspecialchars($grupo['grupo_id']); ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <script>
                            var gruposData = <?php echo json_encode($grupos_con_fotos); ?>;
                            var miniCarruselIndices = {};
                            
                            <?php foreach ($grupos_con_fotos as $grupo): ?>
                                miniCarruselIndices['<?php echo htmlspecialchars($grupo['grupo_id']); ?>'] = 0;
                            <?php endforeach; ?>
                            
                            console.log('Grupos cargados:', gruposData);
                        </script>
                        
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px 40px; background: var(--bg-light); border-radius: var(--radius-lg); margin-top: 20px;">
                            <i class="bi bi-images" style="font-size: 64px; display: block; margin-bottom: 15px; opacity: 0.5; color: var(--text-muted);"></i>
                            <p style="color: var(--text-muted); font-size: 16px; margin-bottom: 5px;">Aún no has agregado fotos de tu finca</p>
                            <p style="color: var(--text-muted); font-size: 14px;">Sube tu primer foto para mostrar tus cultivos</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal-grupo" id="modalGrupo">
    <div class="modal-grupo-content">
        <div class="modal-grupo-header">
            <h3 id="modalGrupoTitulo">Fotos</h3>
            <button class="modal-grupo-close" onclick="cerrarModalGrupo()">&times;</button>
        </div>
        <div class="modal-grupo-body" id="modalGrupoBody">
        </div>
        <div class="modal-grupo-footer" id="modalGrupoFooter">
        </div>
    </div>
</div>

<div class="modal-foto-individual" id="modalFotoIndividual">
    <div class="modal-foto-content">
        <button class="modal-foto-close" onclick="cerrarModalFotoIndividual()">&times;</button>
        <img class="modal-foto-img" id="modalFotoImg" src="" alt="">
    </div>
</div>

<script>
var archivosGrupoSeleccionados = [];
var grupoActual = null;

function activarModoEdicion() {
    window.location.href = 'perfil.php?editar=1';
}

function cancelarEdicion() {
    window.location.href = 'perfil.php';
}

function abrirSelectorFotoPerfil() {
    const input = document.getElementById('foto_perfil_input_header');
    if (input) {
        input.click();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const inputHeader = document.getElementById('foto_perfil_input_header');
    if (inputHeader) {
        inputHeader.addEventListener('change', function() {
            handleProfileFotoHeader(this);
        });
    }
});

function handleProfileFotoHeader(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        const maxSize = 5 * 1024 * 1024;
        
        if (!allowedTypes.includes(file.type)) {
            mostrarNotificacion('Formato no válido. Solo JPG y PNG.', 'danger');
            input.value = '';
            return;
        }
        
        if (file.size > maxSize) {
            mostrarNotificacion('La imagen es muy grande. Máx 5MB.', 'danger');
            input.value = '';
            return;
        }
        
        mostrarModalFotoPerfil(file);
    }
}

function mostrarModalFotoPerfil(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const modalHTML = `
            <div class="modal" id="modalConfirmarFoto" style="display: flex;">
                <div class="modal-content" style="max-width: 400px;">
                    <div class="modal-header">
                        <h2><i class="bi bi-camera"></i> Confirmar Foto</h2>
                        <button class="close-modal" onclick="cerrarModalFoto()">&times;</button>
                    </div>
                    <div style="text-align: center; padding: 20px;">
                        <img src="${e.target.result}" 
                             alt="Vista previa" 
                             style="width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary); margin-bottom: 20px;">
                        <p style="color: var(--text-muted); margin-bottom: 25px;">
                            ¿Deseas usar esta imagen como tu nueva foto de perfil?
                        </p>
                        <div style="display: flex; gap: 10px; justify-content: center;">
                            <button type="button" class="btn btn-primary" onclick="guardarFotoPerfil()">
                                <i class="bi bi-check-circle"></i> Guardar Foto
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="cerrarModalFoto()">
                                <i class="bi bi-x-circle"></i> Cancelar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        const modalContainer = document.createElement('div');
        modalContainer.innerHTML = modalHTML;
        document.body.appendChild(modalContainer);
        
        window.fotoPerfilTemp = file;
        window.fotoPerfilTempUrl = e.target.result;
    };
    reader.readAsDataURL(file);
}

function guardarFotoPerfil() {
    if (!window.fotoPerfilTemp) return;
    
    const formData = new FormData();
    formData.append('accion', 'actualizar_foto_perfil');
    formData.append('foto_perfil', window.fotoPerfilTemp);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        window.location.reload();
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarNotificacion('Error al guardar la foto', 'danger');
    });
    
    cerrarModalFoto();
}

function cerrarModalFoto() {
    const modal = document.getElementById('modalConfirmarFoto');
    if (modal) {
        modal.remove();
    }
    window.fotoPerfilTemp = null;
    window.fotoPerfilTempUrl = null;
    
    const input = document.getElementById('foto_perfil_input_header');
    if (input) {
        input.value = '';
    }
}


function navMiniCarrusel(grupoId, direction) {
    if (!miniCarruselIndices[grupoId]) {
        miniCarruselIndices[grupoId] = 0;
    }
    
    const grupo = gruposData.find(g => g.grupo_id == grupoId);
    if (!grupo) return;
    
    const totalFotos = grupo.fotos.length;
    miniCarruselIndices[grupoId] += direction;
    
    if (miniCarruselIndices[grupoId] >= totalFotos) {
        miniCarruselIndices[grupoId] = 0;
    }
    if (miniCarruselIndices[grupoId] < 0) {
        miniCarruselIndices[grupoId] = totalFotos - 1;
    }
    
    const carruselInner = document.querySelector(`#miniCarrusel-${grupoId} .mini-carrusel-inner`);
    const counter = document.getElementById(`counter-${grupoId}`);
    
    if (carruselInner) {
        carruselInner.style.transform = `translateX(-${miniCarruselIndices[grupoId] * 100}%)`;
    }
    
    if (counter) {
        counter.textContent = `${miniCarruselIndices[grupoId] + 1}/${totalFotos}`;
    }
}

function handleGrupoFotosChange(input) {
    const placeholder = document.getElementById('grupoPlaceholder');
    const descripcionContainer = document.getElementById('descripcion-grupo-container');
    const previewContainer = document.getElementById('preview-simple-container');
    const previewGrid = document.getElementById('preview-simple-grid');
    const selectedCount = document.getElementById('selected-count-simple');
    
    previewGrid.innerHTML = '';
    archivosGrupoSeleccionados = [];
    
    if (input.files && input.files.length > 0) {
        const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
        const maxSize = 5 * 1024 * 1024;
        
        for (let i = 0; i < input.files.length; i++) {
            const file = input.files[i];
            
            if (!allowedTypes.includes(file.type)) {
                alert(`El archivo "${file.name}" no es una imagen válida. Solo se permiten JPG y PNG.`);
                input.value = '';
                return;
            }
            
            if (file.size > maxSize) {
                alert(`El archivo "${file.name}" es muy grande. El tamaño máximo es 5MB.`);
                input.value = '';
                return;
            }
            
            archivosGrupoSeleccionados.push(file);
            
            const reader = new FileReader();
            reader.onload = (function(file, index) {
                return function(e) {
                    const previewItem = document.createElement('div');
                    previewItem.className = 'preview-simple-item';
                    
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.alt = file.name;
                    
                    const fileName = document.createElement('div');
                    fileName.className = 'preview-simple-name';
                    fileName.textContent = file.name.length > 15 ? file.name.substring(0, 12) + '...' : file.name;
                    
                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'preview-simple-remove';
                    removeBtn.innerHTML = '<i class="bi bi-x"></i>';
                    removeBtn.onclick = function(e) {
                        e.stopPropagation();
                        eliminarArchivoGrupo(index);
                    };
                    
                    previewItem.appendChild(img);
                    previewItem.appendChild(fileName);
                    previewItem.appendChild(removeBtn);
                    previewGrid.appendChild(previewItem);
                    
                    selectedCount.textContent = archivosGrupoSeleccionados.length;
                };
            })(file, i);
            
            reader.readAsDataURL(file);
        }
        
        placeholder.style.display = 'none';
        descripcionContainer.style.display = 'block';
        previewContainer.style.display = 'block';
        selectedCount.textContent = archivosGrupoSeleccionados.length;
    } else {
        placeholder.style.display = 'block';
        descripcionContainer.style.display = 'none';
        previewContainer.style.display = 'none';
    }
}

function eliminarArchivoGrupo(index) {
    archivosGrupoSeleccionados.splice(index, 1);
    
    const dataTransfer = new DataTransfer();
    archivosGrupoSeleccionados.forEach(file => dataTransfer.items.add(file));
    document.getElementById('fotos_grupo').files = dataTransfer.files;
    
    const previewGrid = document.getElementById('preview-simple-grid');
    const selectedCount = document.getElementById('selected-count-simple');
    
    previewGrid.innerHTML = '';
    
    archivosGrupoSeleccionados.forEach((file, newIndex) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewItem = document.createElement('div');
            previewItem.className = 'preview-simple-item';
            
            const img = document.createElement('img');
            img.src = e.target.result;
            img.alt = file.name;
            
            const fileName = document.createElement('div');
            fileName.className = 'preview-simple-name';
            fileName.textContent = file.name.length > 15 ? file.name.substring(0, 12) + '...' : file.name;
            
            const removeBtn = document.createElement('button');
            removeBtn.className = 'preview-simple-remove';
            removeBtn.innerHTML = '<i class="bi bi-x"></i>';
            removeBtn.onclick = function(e) {
                e.stopPropagation();
                eliminarArchivoGrupo(newIndex);
            };
            
            previewItem.appendChild(img);
            previewItem.appendChild(fileName);
            previewItem.appendChild(removeBtn);
            previewGrid.appendChild(previewItem);
        };
        reader.readAsDataURL(file);
    });
    
    selectedCount.textContent = archivosGrupoSeleccionados.length;
    
    if (archivosGrupoSeleccionados.length === 0) {
        document.getElementById('grupoPlaceholder').style.display = 'block';
        document.getElementById('descripcion-grupo-container').style.display = 'none';
        document.getElementById('preview-simple-container').style.display = 'none';
        document.getElementById('fotos_grupo').value = '';
    }
}

function verGrupoCompleto(grupoId) {
    grupoActual = grupoId;
    const grupo = gruposData.find(g => g.grupo_id == grupoId);
    if (!grupo) {
        mostrarNotificacion('Grupo no encontrado', 'danger');
        return;
    }
    
    document.getElementById('modalGrupoTitulo').textContent = 
        `Grupo de Fotos - ${grupo.cantidad_fotos} foto(s)`;
    
    const modalBody = document.getElementById('modalGrupoBody');
    modalBody.innerHTML = `
        <div style="margin-bottom: 20px;">
            <p style="color: var(--text-muted); font-size: 16px; margin-bottom: 15px;">
                <i class="bi bi-card-text"></i>
                Descripción: <strong>${grupo.descripcion || 'Sin descripción'}</strong>
            </p>
            <p style="color: var(--text-muted); font-size: 14px;">
                <i class="bi bi-calendar"></i>
                Creado el: ${new Date(grupo.fecha_grupo).toLocaleDateString('es-ES', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                })}
            </p>
        </div>
        <div class="grupo-completo-grid" id="gridFotosGrupo"></div>
    `;
    
    const grid = document.getElementById('gridFotosGrupo');
    grupo.fotos.forEach(foto => {
        const fotoItem = document.createElement('div');
        fotoItem.className = 'foto-grupo-item';
        fotoItem.innerHTML = `
            <img src="../../${foto.ruta_foto}" 
                 alt="Foto de finca" 
                 class="foto-grupo-img"
                 onclick="abrirModalFotoIndividual('../../${foto.ruta_foto}')">
            <form method="POST" onsubmit="return confirm('¿Eliminar esta foto?');">
                <input type="hidden" name="accion" value="eliminar_foto">
                <input type="hidden" name="id_foto" value="${foto.id_foto}">
                <button type="submit" class="foto-grupo-eliminar" title="Eliminar foto">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
        `;
        grid.appendChild(fotoItem);
    });
    
    const modalFooter = document.getElementById('modalGrupoFooter');
    modalFooter.innerHTML = `
        <form method="POST" class="form-editar-grupo" onsubmit="return confirm('¿Actualizar la descripción de todas las fotos de este grupo?');">
            <input type="hidden" name="accion" value="actualizar_descripcion_grupo">
            <input type="hidden" name="grupo_id" value="${grupoId}">
            <input type="text" 
                   name="nueva_descripcion" 
                   placeholder="Nueva descripción para todas las fotos del grupo"
                   value="${grupo.descripcion || ''}"
                   required
                   style="flex: 1;">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-circle"></i> Actualizar
            </button>
        </form>
        <button class="btn btn-danger" onclick="eliminarGrupo('${grupoId}')">
            <i class="bi bi-trash"></i> Eliminar Fotos
        </button>
    `;
    
    document.getElementById('modalGrupo').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function cerrarModalGrupo() {
    document.getElementById('modalGrupo').classList.remove('show');
    document.body.style.overflow = 'auto';
    grupoActual = null;
}

function editarDescripcionGrupo(grupoId, descripcionActual) {
    const nuevaDescripcion = prompt('Nueva descripción para el grupo:', descripcionActual);
    if (nuevaDescripcion !== null && nuevaDescripcion.trim() !== '') {
        // Enviar formulario para actualizar
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        form.innerHTML = `
            <input type="hidden" name="accion" value="actualizar_descripcion_grupo">
            <input type="hidden" name="grupo_id" value="${grupoId}">
            <input type="hidden" name="nueva_descripcion" value="${nuevaDescripcion.trim()}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function eliminarGrupo(grupoId) {
    if (confirm('¿Estás seguro de eliminar las fotos? Esta acción no se puede deshacer.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        form.innerHTML = `
            <input type="hidden" name="accion" value="eliminar_grupo">
            <input type="hidden" name="grupo_id" value="${grupoId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function abrirModalFotoIndividual(src) {
    document.getElementById('modalFotoImg').src = src;
    document.getElementById('modalFotoIndividual').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function cerrarModalFotoIndividual() {
    document.getElementById('modalFotoIndividual').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function updateCharCounter(input, maxLength, counterId) {
    const counter = document.getElementById(counterId);
    const currentLength = input.value.length;
    const percentage = (currentLength / maxLength) * 100;
    
    counter.textContent = `${currentLength} / ${maxLength} caracteres`;
    
    counter.classList.remove('warning', 'danger');
    if (percentage >= 90) {
        counter.classList.add('danger');
    } else if (percentage >= 75) {
        counter.classList.add('warning');
    }
}

function mostrarNotificacion(mensaje, tipo = 'success') {
    const bgColor = tipo === 'success' ? '#28a745' : '#dc3545';
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed; 
        top: 20px; 
        right: 20px; 
        background: ${bgColor}; 
        color: white; 
        padding: 15px 20px; 
        border-radius: 8px; 
        z-index: 10000; 
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideIn 0.3s ease;
    `;
    notification.innerHTML = tipo === 'success' 
        ? `<i class="bi bi-check-circle-fill"></i> ${mensaje}`
        : `<i class="bi bi-exclamation-triangle-fill"></i> ${mensaje}`;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.transition = 'opacity 0.3s, transform 0.3s';
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(400px)';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

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

document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('input[maxlength], textarea[maxlength]');
    inputs.forEach(input => {
        const oninputAttr = input.getAttribute('oninput');
        if (oninputAttr) {
            const match = oninputAttr.match(/counter-[\w-]+/);
            if (match) {
                const maxLength = input.getAttribute('maxlength');
                const counterId = match[0];
                updateCharCounter(input, maxLength, counterId);
            }
        }
    });
    
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .grupo-fotos {
            animation: fadeIn 0.3s ease;
        }
        .foto-grupo-item {
            animation: fadeIn 0.3s ease;
        }
    `;
    document.head.appendChild(style);
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('modalGrupo').classList.contains('show')) {
            cerrarModalGrupo();
        }
        if (document.getElementById('modalFotoIndividual').classList.contains('show')) {
            cerrarModalFotoIndividual();
        }
    }
    
    if (document.getElementById('modalGrupo').classList.contains('show') && grupoActual) {
        if (e.key === 'ArrowLeft') {
            navMiniCarrusel(grupoActual, -1);
        } else if (e.key === 'ArrowRight') {
            navMiniCarrusel(grupoActual, 1);
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const uploadArea = document.querySelector('.upload-area-simple');
    const fileInput = document.getElementById('fotos_grupo');
    
    if (uploadArea && fileInput) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });
        
        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, unhighlight, false);
        });
        
        uploadArea.addEventListener('drop', handleDrop, false);
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        function highlight() {
            uploadArea.style.background = 'rgba(40, 167, 69, 0.15)';
            uploadArea.style.borderColor = 'var(--primary-dark)';
        }
        
        function unhighlight() {
            uploadArea.style.background = '';
            uploadArea.style.borderColor = '';
        }
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if (files.length > 0) {
                fileInput.files = files;
                handleGrupoFotosChange(fileInput);
            }
            unhighlight();
        }
    }
});

console.log('Grupos de fotos cargados:', gruposData);
</script>

</body>
</html>