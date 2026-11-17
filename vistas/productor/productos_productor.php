<?php

session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'productor');
require_once __DIR__ . '/../../config/auth_check.php';
require_once 'verificar_perfil.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'productor') {
    header("Location: ../auth/login.php");
    exit();
}

$usuario_id = intval($_SESSION['user_id']);
$usuario_nombre = htmlspecialchars($_SESSION['user_nombre']);

if (isset($_GET['accion']) && $_GET['accion'] === 'verificar_nombre') {
    header('Content-Type: application/json');
    $nombre = trim($_GET['nombre'] ?? '');
    $id_producto_editar = intval($_GET['id_producto'] ?? 0);
    if (empty($nombre)) { echo json_encode(['existe' => false]); exit; }
    if ($id_producto_editar > 0) {
        $stmt = $conn->prepare("SELECT id_producto FROM productos WHERE LOWER(nombre) = LOWER(?) AND id_productor = ? AND id_producto != ?");
        $stmt->bind_param("sii", $nombre, $usuario_id, $id_producto_editar);
    } else {
        $stmt = $conn->prepare("SELECT id_producto FROM productos WHERE LOWER(nombre) = LOWER(?) AND id_productor = ?");
        $stmt->bind_param("si", $nombre, $usuario_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    echo json_encode(['existe' => $result->num_rows > 0]);
    $stmt->close();
    exit;
}

if (isset($_GET['accion']) && $_GET['accion'] === 'eliminar_foto_ajax') {
    header('Content-Type: application/json');
    $id_foto    = intval($_POST['id_foto']    ?? 0);
    $id_producto = intval($_POST['id_producto'] ?? 0);
    $stmt_check = $conn->prepare(
        "SELECT f.ruta_foto FROM fotos_productos f
         INNER JOIN productos p ON f.id_producto = p.id_producto
         WHERE f.id_foto = ? AND p.id_productor = ?"
    );
    $stmt_check->bind_param("ii", $id_foto, $usuario_id);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    if ($result->num_rows > 0) {
        $foto = $result->fetch_assoc();
        $ruta_fisica = __DIR__ . '/../../' . $foto['ruta_foto'];
        if (file_exists($ruta_fisica)) unlink($ruta_fisica);
        $stmt_del = $conn->prepare("DELETE FROM fotos_productos WHERE id_foto = ?");
        $stmt_del->bind_param("i", $id_foto);
        $stmt_del->execute();
        $stmt_del->close();
        $stmt_f = $conn->prepare("SELECT id_foto, ruta_foto, orden FROM fotos_productos WHERE id_producto = ? ORDER BY orden ASC");
        $stmt_f->bind_param("i", $id_producto);
        $stmt_f->execute();
        $fotos = $stmt_f->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_f->close();
        echo json_encode(['ok' => true, 'fotos' => $fotos]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    }
    $stmt_check->close();
    exit;
}

$perfil_completo   = perfilCompleto($conn, $usuario_id);
$campos_faltantes  = camposFaltantes($conn, $usuario_id);
$porcentaje        = porcentajeCompletitud($conn, $usuario_id);
$mensaje           = '';
$tipo_mensaje      = '';

// ── CREAR producto ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear' && $perfil_completo) {
    $nombre                    = ucfirst(strtolower(trim($_POST['nombre'])));
    $id_categoria              = intval($_POST['id_categoria']);
    $cantidad                  = intval($_POST['cantidad']);
    $precio                    = floatval($_POST['precio']);
    $precio_mayorista          = !empty($_POST['precio_mayorista']) ? floatval($_POST['precio_mayorista']) : null;
    $cantidad_minima_mayorista = intval($_POST['cantidad_minima_mayorista'] ?? 10);
    $unidad_medida             = $_POST['unidad_medida'] ?? 'unidad';
    $stock_minimo              = intval($_POST['stock_minimo'] ?? 5);
    $descripcion               = trim($_POST['descripcion']);
    $errores = [];
    if (empty($nombre))      $errores[] = "El nombre es obligatorio";
    if ($id_categoria <= 0)  $errores[] = "Debes seleccionar una categoría";
    if ($cantidad < 0)       $errores[] = "La cantidad no puede ser negativa";
    if ($precio < 3000) $errores[] = "El precio mínimo es de $3.000"; 
    if ($precio_mayorista !== null && $precio_mayorista >= $precio) $errores[] = "El precio mayorista debe ser menor al precio de detal";

    if (empty($errores)) {
        $stmt_check_cat = $conn->prepare("SELECT activa FROM categorias_productos WHERE id_categoria = ?");
        $stmt_check_cat->bind_param("i", $id_categoria);
        $stmt_check_cat->execute();
        $result_check_cat = $stmt_check_cat->get_result();
        if ($result_check_cat->num_rows > 0) {
            $categoria = $result_check_cat->fetch_assoc();
            if ($categoria['activa'] == 0) $errores[] = "No se puede crear un producto en una categoría inactiva";
        } else { $errores[] = "La categoría seleccionada no existe"; }
        $stmt_check_cat->close();
    }
    if (empty($errores)) {
        $stmt_check = $conn->prepare("SELECT id_producto FROM productos WHERE LOWER(nombre) = LOWER(?) AND id_productor = ?");
        $stmt_check->bind_param("si", $nombre, $usuario_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        if ($result_check->num_rows > 0) $errores[] = "Ya tienes un producto con este nombre";
        $stmt_check->close();
    }
    if (empty($errores)) {
        $fotos_procesadas = [];
        if (isset($_FILES['imagenes']) && !empty($_FILES['imagenes']['name'][0])) {
            $dir_uploads = __DIR__ . '/../../uploads/productos/';
            if (!is_dir($dir_uploads)) mkdir($dir_uploads, 0755, true);
            $permitidos = ['jpg', 'jpeg', 'png', 'webp'];
            $total_imagenes = count($_FILES['imagenes']['name']);
            for ($i = 0; $i < $total_imagenes && $i < 5; $i++) {
                if ($_FILES['imagenes']['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['imagenes']['name'][$i], PATHINFO_EXTENSION));
                    if (in_array($ext, $permitidos)) {
                        $nombre_archivo = 'producto_' . $usuario_id . '_' . time() . '_' . $i . '.' . $ext;
                        $ruta_destino   = $dir_uploads . $nombre_archivo;
                        if (move_uploaded_file($_FILES['imagenes']['tmp_name'][$i], $ruta_destino)) {
                            $fotos_procesadas[] = ['ruta' => 'uploads/productos/' . $nombre_archivo, 'orden' => $i];
                        }
                    }
                }
            }
        }
        $stmt = $conn->prepare("INSERT INTO productos (id_productor, nombre, id_categoria, cantidad, precio, precio_mayorista, cantidad_minima_mayorista, unidad_medida, stock_minimo, descripcion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isiiddisss", $usuario_id, $nombre, $id_categoria, $cantidad, $precio, $precio_mayorista, $cantidad_minima_mayorista, $unidad_medida, $stock_minimo, $descripcion);
        if ($stmt->execute()) {
            $id_producto_nuevo = $conn->insert_id;
            if (!empty($fotos_procesadas)) {
                $stmt_foto = $conn->prepare("INSERT INTO fotos_productos (id_producto, ruta_foto, orden, descripcion) VALUES (?, ?, ?, ?)");
                foreach ($fotos_procesadas as $foto) {
                    $desc = $foto['orden'] == 0 ? 'Foto principal' : 'Foto adicional ' . ($foto['orden'] + 1);
                    $stmt_foto->bind_param("isis", $id_producto_nuevo, $foto['ruta'], $foto['orden'], $desc);
                    $stmt_foto->execute();
                }
                $stmt_foto->close();
            }
            $mensaje = "✅ Producto creado exitosamente con " . count($fotos_procesadas) . " foto(s)";
            $tipo_mensaje = "success";
        } else { $mensaje = "❌ Error al crear el producto"; $tipo_mensaje = "danger"; }
        $stmt->close();
    } else { $mensaje = "⚠️ " . implode(", ", $errores); $tipo_mensaje = "warning"; }
}

// ── EDITAR producto ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar' && $perfil_completo) {
    $id_producto               = intval($_POST['id_producto']);
    $nombre                    = ucfirst(strtolower(trim($_POST['nombre'])));
    $id_categoria              = intval($_POST['id_categoria']);
    $cantidad                  = intval($_POST['cantidad']);
    $precio                    = floatval($_POST['precio']);
    $precio_mayorista          = !empty($_POST['precio_mayorista']) ? floatval($_POST['precio_mayorista']) : null;
    $cantidad_minima_mayorista = intval($_POST['cantidad_minima_mayorista'] ?? 10);
    $unidad_medida             = $_POST['unidad_medida'] ?? 'unidad';
    $stock_minimo              = intval($_POST['stock_minimo'] ?? 5);
    $descripcion               = trim($_POST['descripcion']);
    $errores = [];
    if ($precio < 3000) $errores[] = "El precio mínimo es de $3.000";
    if ($precio_mayorista !== null && $precio_mayorista >= $precio)
    $errores[] = "El precio mayorista debe ser menor al precio de detal";

    if (empty($errores)) {
        $stmt = $conn->prepare(
            "UPDATE productos SET nombre=?, id_categoria=?, cantidad=?, precio=?,
             precio_mayorista=?, cantidad_minima_mayorista=?, unidad_medida=?,
             stock_minimo=?, descripcion=? WHERE id_producto=? AND id_productor=?"
        );
        $stmt->bind_param("siiddisisii",
            $nombre, $id_categoria, $cantidad, $precio, $precio_mayorista,
            $cantidad_minima_mayorista, $unidad_medida, $stock_minimo,
            $descripcion, $id_producto, $usuario_id
        );
        if ($stmt->execute()) {
            if (isset($_FILES['imagenes_nuevas']) && !empty($_FILES['imagenes_nuevas']['name'][0])) {
                $stmt_cnt = $conn->prepare("SELECT COUNT(*) as c FROM fotos_productos WHERE id_producto = ?");
                $stmt_cnt->bind_param("i", $id_producto);
                $stmt_cnt->execute();
                $fotos_actuales = $stmt_cnt->get_result()->fetch_assoc()['c'];
                $stmt_cnt->close();

                $stmt_max = $conn->prepare("SELECT COALESCE(MAX(orden), -1) as max_orden FROM fotos_productos WHERE id_producto = ?");
                $stmt_max->bind_param("i", $id_producto);
                $stmt_max->execute();
                $orden_actual = intval($stmt_max->get_result()->fetch_assoc()['max_orden']) + 1;
                $stmt_max->close();

                $dir_uploads = __DIR__ . '/../../uploads/productos/';
                if (!is_dir($dir_uploads)) mkdir($dir_uploads, 0755, true);

                $permitidos  = ['jpg', 'jpeg', 'png', 'webp'];
                $stmt_foto   = $conn->prepare("INSERT INTO fotos_productos (id_producto, ruta_foto, orden, descripcion) VALUES (?, ?, ?, ?)");

                foreach ($_FILES['imagenes_nuevas']['name'] as $i => $name) {
                    if ($fotos_actuales >= 5) break;
                    if ($_FILES['imagenes_nuevas']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, $permitidos)) continue;
                    $nombre_archivo = 'producto_' . $usuario_id . '_' . time() . '_' . $orden_actual . '.' . $ext;
                    $ruta_destino   = $dir_uploads . $nombre_archivo;
                    if (move_uploaded_file($_FILES['imagenes_nuevas']['tmp_name'][$i], $ruta_destino)) {
                        $ruta = 'uploads/productos/' . $nombre_archivo;
                        $desc = $orden_actual == 0 ? 'Foto principal' : 'Foto adicional ' . ($orden_actual + 1);
                        $stmt_foto->bind_param("isis", $id_producto, $ruta, $orden_actual, $desc);
                        $stmt_foto->execute();
                        $orden_actual++;
                        $fotos_actuales++;
                    }
                }
                $stmt_foto->close();
            }
            $mensaje = "✅ Producto actualizado exitosamente"; $tipo_mensaje = "success";
        } else { $mensaje = "❌ Error al actualizar el producto"; $tipo_mensaje = "danger"; }
        $stmt->close();
    } else { $mensaje = "⚠️ " . implode(", ", $errores); $tipo_mensaje = "warning"; }
}

// ── ELIMINAR producto ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar' && $perfil_completo) {
    $id_producto = intval($_POST['id_producto']);
    $stmt = $conn->prepare("DELETE FROM productos WHERE id_producto = ? AND id_productor = ?");
    $stmt->bind_param("ii", $id_producto, $usuario_id);
    if ($stmt->execute()) { $mensaje = "✅ Producto eliminado exitosamente"; $tipo_mensaje = "success"; }
    else                  { $mensaje = "❌ Error al eliminar el producto";   $tipo_mensaje = "danger"; }
    $stmt->close();
}

$filtro_categoria = isset($_GET['categoria']) ? intval($_GET['categoria']) : 0;

$result_categorias_select = $conn->query(
    "SELECT id_categoria, nombre_categoria, descripcion, icono, activa
     FROM categorias_productos WHERE activa = 1 ORDER BY nombre_categoria ASC"
);

$stmt_todas_cat = $conn->prepare(
    "SELECT DISTINCT c.id_categoria, c.nombre_categoria, c.icono, c.activa,
            COUNT(p.id_producto) as total_productos
     FROM categorias_productos c
     LEFT JOIN productos p ON c.id_categoria = p.id_categoria AND p.id_productor = ?
     GROUP BY c.id_categoria
     HAVING total_productos > 0 OR c.activa = 1
     ORDER BY c.nombre_categoria ASC"
);
$stmt_todas_cat->bind_param("i", $usuario_id);
$stmt_todas_cat->execute();
$result_todas_categorias = $stmt_todas_cat->get_result();

$query_productos = "SELECT p.*, c.nombre_categoria, c.icono, c.activa as categoria_activa
                    FROM productos p
                    INNER JOIN categorias_productos c ON p.id_categoria = c.id_categoria
                    WHERE p.id_productor = ?";
if ($filtro_categoria > 0) $query_productos .= " AND p.id_categoria = " . $filtro_categoria;
$query_productos .= " ORDER BY p.fecha_creacion DESC";
$stmt_productos = $conn->prepare($query_productos);
$stmt_productos->bind_param("i", $usuario_id);
$stmt_productos->execute();
$result_productos = $stmt_productos->get_result();

$stmt_total = $conn->prepare(
    "SELECT COUNT(*) as total,
            SUM(CASE WHEN estado='disponible' THEN 1 ELSE 0 END) as disponibles,
            SUM(CASE WHEN estado='stock_bajo'  THEN 1 ELSE 0 END) as stock_bajo,
            SUM(CASE WHEN estado='agotado'     THEN 1 ELSE 0 END) as agotados
     FROM productos WHERE id_productor = ?"
);
$stmt_total->bind_param("i", $usuario_id);
$stmt_total->execute();
$stats               = $stmt_total->get_result()->fetch_assoc();
$total_productos     = $stats['total'];
$productos_disponibles = $stats['disponibles'];
$productos_agotados    = $stats['agotados'];
$productos_stock_bajo  = $stats['stock_bajo'];

$productos_array = [];
while ($row = $result_productos->fetch_assoc()) {
    $stmt_fotos = $conn->prepare(
        "SELECT id_foto, ruta_foto, orden, descripcion
         FROM fotos_productos WHERE id_producto = ? ORDER BY orden ASC"
    );
    $stmt_fotos->bind_param("i", $row['id_producto']);
    $stmt_fotos->execute();
    $row['fotos'] = $stmt_fotos->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_fotos->close();
    $productos_array[] = $row;
}
$productos_json = json_encode($productos_array);

$stmt_cat_inactivas = $conn->prepare(
    "SELECT COUNT(*) as total FROM productos p
     INNER JOIN categorias_productos c ON p.id_categoria = c.id_categoria
     WHERE p.id_productor = ? AND c.activa = 0"
);
$stmt_cat_inactivas->bind_param("i", $usuario_id);
$stmt_cat_inactivas->execute();
$cat_inactivas = $stmt_cat_inactivas->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Productos - QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
    .fotos-galeria { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 10px; margin-top: 10px; }
    .foto-item { position: relative; aspect-ratio: 1; border-radius: var(--radius); overflow: hidden; border: 2px solid var(--bg-light); }
    .foto-item img { width: 100%; height: 100%; object-fit: cover; }
    .foto-item.principal { border-color: var(--primary); box-shadow: 0 0 10px rgba(40, 167, 69, 0.3); }
    .foto-badge { position: absolute; top: 5px; left: 5px; background: var(--primary); color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; }
    .dropzone-multiple { border: 2px dashed var(--primary); border-radius: var(--radius); padding: 30px; text-align: center; background: var(--bg-light); cursor: pointer; transition: var(--transition); }
    .dropzone-multiple:hover { background: rgba(40, 167, 69, 0.1); border-color: var(--primary-dark); }
    .dropzone-multiple i { font-size: 48px; color: var(--primary); display: block; margin-bottom: 10px; }
    .preview-fotos { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; margin-top: 15px; }
    .preview-item { position: relative; aspect-ratio: 1; border-radius: var(--radius); overflow: hidden; border: 2px solid var(--primary); }
    .preview-item img { width: 100%; height: 100%; object-fit: cover; }
    .preview-item .remove-preview { position: absolute; top: 5px; right: 5px; background: var(--danger); color: white; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .categoria-icon-display { display: inline-flex; align-items: center; gap: 10px; padding: 8px 15px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 25px; font-size: 14px; font-weight: 500; border: 1px solid #dee2e6; transition: all 0.3s ease; }
    .categoria-icon-display:hover { background: linear-gradient(135deg, #e7f3ff 0%, #d4e9ff 100%); border-color: var(--primary); transform: translateX(3px); }
    .categoria-icon-display i { font-size: 20px; color: var(--primary); min-width: 20px; text-align: center; }
    .modal-galeria { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 10000; justify-content: center; align-items: center; }
    .modal-galeria.show { display: flex; }
    .galeria-content { max-width: 90%; max-height: 90%; position: relative; }
    .galeria-content img { max-width: 100%; max-height: 90vh; object-fit: contain; }
    .galeria-close { position: absolute; top: -40px; right: 0; background: white; color: black; border: none; padding: 10px 15px; border-radius: var(--radius); cursor: pointer; font-size: 20px; }
    .foto-eliminar-btn { position: absolute; bottom: 5px; right: 5px; background: var(--danger); color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; transition: opacity 0.3s; }
    @media (hover: hover) { .foto-eliminar-btn { opacity: 0; } .foto-item:hover .foto-eliminar-btn { opacity: 1; } }
    .foto-eliminar-btn.cargando { opacity: 0.5; pointer-events: none; }
    .filtro-seccion { background: white; padding: 20px; border-radius: var(--radius-lg); box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #f0f0f0; transition: var(--transition); }
    .filtro-seccion:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: var(--primary-light); }
    .filtro-seccion h4 { padding-bottom: 10px; border-bottom: 2px solid var(--primary-light); color: var(--primary); font-size: 16px; display: flex; align-items: center; gap: 8px; margin: 0 0 15px 0; }
    .checkbox-inline { padding: 10px; border-radius: var(--radius); transition: var(--transition); cursor: pointer; display: flex; align-items: center; gap: 10px; }
    .checkbox-inline:hover { background: var(--bg-light); }
    .checkbox-inline input[type="checkbox"] { cursor: pointer; width: 18px; height: 18px; accent-color: var(--success); }
    #contadorFiltros { animation: slideDown 0.3s ease; background: linear-gradient(135deg, var(--bg-light) 0%, #f8f9fa 100%); border: 1px solid #e9ecef; border-radius: var(--radius); margin-top: 15px; padding: 15px; }
    .producto-filtrado { animation: fadeIn 0.3s ease; }
    .producto-oculto { display: none !important; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    .productos-table tbody tr.row-warning { background: inherit !important; }
    .productos-table tbody tr.row-warning:hover { background: rgba(40, 167, 69, 0.08) !important; }
    .btn-disabled-tooltip { position: relative; cursor: not-allowed !important; }
    .btn-disabled-tooltip:hover::after { content: attr(data-tooltip); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.9); color: white; padding: 8px 12px; border-radius: 6px; white-space: nowrap; font-size: 12px; z-index: 1000; margin-bottom: 5px; }
    .btn-disabled-tooltip:hover::before { content: ''; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-top-color: rgba(0,0,0,0.9); margin-bottom: -6px; }
    #agregarMasFotos label.dropzone-multiple { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 120px; width: 100%; gap: 8px; }
    #agregarMasFotos label.dropzone-multiple i { font-size: 36px; display: block; margin: 0; }
    .desktop-cell { display: table-cell; }
    .card-header-cell, .card-grid-cell, .card-footer-cell { display: none; }
    body.modal-open { overflow: hidden !important; }


    .toast-archivo-invalido {
        position: fixed;
        top: 24px;
        right: 24px;
        background: #fff;
        border-radius: 14px;
        padding: 0;
        box-shadow: 0 10px 40px rgba(0,0,0,0.18), 0 2px 8px rgba(220,53,69,0.15);
        z-index: 99999;
        max-width: 400px;
        width: calc(100vw - 48px);
        overflow: hidden;
        animation: toastSlideIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        border: 1px solid #f5c6cb;
    }

    @keyframes toastSlideIn {
        from { opacity: 0; transform: translateX(60px) scale(0.95); }
        to   { opacity: 1; transform: translateX(0) scale(1); }
    }

    @keyframes toastSlideOut {
        from { opacity: 1; transform: translateX(0) scale(1); }
        to   { opacity: 0; transform: translateX(60px) scale(0.95); }
    }

    .toast-archivo-invalido .toast-barra {
        height: 5px;
        background: linear-gradient(90deg, #dc3545, #ff6b6b);
        width: 100%;
        animation: toastBarra 5s linear forwards;
    }

    @keyframes toastBarra {
        from { width: 100%; }
        to   { width: 0%; }
    }

    .toast-archivo-invalido .toast-inner {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 18px 18px 18px 18px;
    }

    .toast-archivo-invalido .toast-icono-wrap {
        width: 44px;
        height: 44px;
        background: linear-gradient(135deg, #fff5f5, #ffe0e3);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid #f5c6cb;
    }

    .toast-archivo-invalido .toast-icono-wrap i {
        font-size: 22px;
        color: #dc3545;
    }

    .toast-archivo-invalido .toast-contenido {
        flex: 1;
        min-width: 0;
    }

    .toast-archivo-invalido .toast-titulo {
        font-weight: 700;
        color: #b91c2e;
        font-size: 15px;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .toast-archivo-invalido .toast-archivos {
        background: #fff5f5;
        border: 1px solid #f5c6cb;
        border-radius: 8px;
        padding: 8px 12px;
        margin-bottom: 8px;
        font-size: 13px;
        color: #721c24;
        max-height: 80px;
        overflow-y: auto;
    }

    .toast-archivo-invalido .toast-archivos span {
        display: block;
        font-weight: 600;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .toast-archivo-invalido .toast-formatos {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: #6c757d;
    }

    .toast-archivo-invalido .toast-formatos .formato-badge {
        background: #e8f5e9;
        color: #2e7d32;
        border: 1px solid #c8e6c9;
        border-radius: 20px;
        padding: 2px 8px;
        font-weight: 600;
        font-size: 11px;
    }

    .toast-archivo-invalido .toast-cerrar {
        background: none;
        border: none;
        color: #adb5bd;
        cursor: pointer;
        font-size: 20px;
        line-height: 1;
        padding: 0;
        flex-shrink: 0;
        transition: color 0.2s;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
    }

    .toast-archivo-invalido .toast-cerrar:hover {
        color: #dc3545;
        background: #fff5f5;
    }

    @media (max-width: 480px) {
        .toast-archivo-invalido {
            top: auto;
            bottom: 16px;
            right: 12px;
            left: 12px;
            width: auto;
            max-width: none;
        }
    }

    @media (max-width: 768px) {
        .modal-content { max-height: 100dvh !important; height: 100dvh !important; max-width: 100% !important; width: 100% !important; margin: 0 !important; border-radius: 0 !important; display: flex; flex-direction: column; background: #fff; overflow-y: hidden !important; }
        .modal-content .modal-header { flex-shrink: 0; }
        .modal-content #formProducto { flex: 1; overflow-y: auto; display: flex; flex-direction: column; padding-bottom: 20px; }
        .modal-content .form-actions { margin-top: 0; position: relative; bottom: auto; flex-shrink: 0; background: white; padding: 14px 20px; border-top: 1px solid #e9ecef; display: flex; gap: 10px; z-index: 10; box-shadow: 0 -2px 5px rgba(0,0,0,0.05); }
        .modal-content .form-actions .btn { flex: 1; }
        .desktop-cell { display: none !important; }
        .productos-table thead { display: none; }
        .productos-table, .productos-table tbody { display: block; width: 100%; }
        .productos-table tbody tr { display: block; width: 100%; box-sizing: border-box; border-radius: var(--radius-lg, 12px); border: 1px solid rgba(0,0,0,0.1); margin-bottom: 16px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.07); }
        .productos-table tbody tr td { display: none; }
        .productos-table tbody tr td.card-header-cell { display: flex; align-items: center; gap: 14px; padding: 14px 16px 12px; border-bottom: 1px solid rgba(0,0,0,0.07); }
        .productos-table tbody tr td.card-header-cell .producto-img { width: 56px; height: 56px; min-width: 56px; border-radius: 10px; object-fit: cover; flex-shrink: 0; cursor: pointer; }
        .productos-table tbody tr td.card-header-cell .producto-img-placeholder { width: 56px; height: 56px; min-width: 56px; border-radius: 10px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .productos-table tbody tr td.card-header-cell .producto-text h4 { margin: 0 0 4px; font-size: 15px; font-weight: 600; line-height: 1.3; }
        .productos-table tbody tr td.card-header-cell .producto-text p { margin: 0; font-size: 12px; color: var(--text-muted); line-height: 1.4; }
        .productos-table tbody tr td.card-grid-cell { display: block; padding: 0; }
        .card-data-grid { display: grid; grid-template-columns: 1fr 1fr; border-bottom: 1px solid rgba(0,0,0,0.06); }
        .card-data-item { padding: 10px 16px; border-right: 1px solid rgba(0,0,0,0.06); }
        .card-data-item:nth-child(even) { border-right: none; }
        .card-data-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-dark, #333); margin-bottom: 4px; }
        .card-data-value { font-size: 13px; font-weight: 500; line-height: 1.4; }
        .card-stock-solo { display: grid; grid-template-columns: 1fr; border-bottom: 1px solid rgba(0,0,0,0.06); }
        .card-stock-solo .card-data-item { border-right: none; }
        .card-inactiva-badge { display: flex; align-items: center; gap: 3px; font-size: 11px; color: var(--warning); margin-top: 3px; }
        .precio-detal-valor { color: var(--primary); font-size: 15px; font-weight: 700; }
        .precio-mayorista-valor { color: var(--success); font-size: 13px; font-weight: 600; }
        .productos-table tbody tr.row-warning { background: inherit !important; }
        .productos-table tbody tr.row-warning:hover { background: rgba(40,167,69,0.08) !important; }
        .productos-table tbody tr td.card-footer-cell { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; flex-wrap: wrap; gap: 8px; }
        .card-footer-actions { display: flex; gap: 8px; }
        .card-footer-actions .btn { padding: 7px 14px; font-size: 13px; }
        .catalogo-header { flex-direction: column; align-items: flex-start; gap: 12px; }
        .table-responsive { overflow-x: visible; }
    }
    @media (max-width: 992px) { .filtros-grid { grid-template-columns: 1fr !important; } .filtro-seccion { padding: 15px; } }

    .dropzone-multiple.dragover {
    background: rgba(40, 167, 69, 0.15);
    border-color: var(--primary-dark);
    transform: scale(1.01);
    transition: all 0.2s ease;
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
            <a href="productos_productor.php" class="active"><i class="bi bi-box-seam"></i> Mis Productos</a>
            <a href="pedidos_productor.php"><i class="bi bi-clipboard-check"></i> Pedidos</a>
            <a href="calificaciones_productor.php"><i class="bi bi-star"></i> Reseñas</a>
            <a href="perfil.php"><i class="bi bi-person-circle"></i> Mi Perfil</a>
            <a href="estadisticas.php"><i class="bi bi-bar-chart"></i> Estadísticas</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Mis Productos</h1>
                <p class="subtitle">Gestiona tu catálogo de productos agropecuarios</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout">
                    <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                </a>
            </div>
        </header>

        <div class="content-area">

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                    <i class="bi bi-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : ($tipo_mensaje === 'warning' ? 'exclamation-triangle' : 'x-circle'); ?>-fill"></i>
                    <?php echo $mensaje; ?>
                </div>
            <?php endif; ?>

            <?php if (!$perfil_completo): ?>
                <div class="perfil-incompleto-alerta">
                    <div class="alerta-icono"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    <div class="alerta-contenido">
                        <h3><i class="bi bi-lock-fill"></i> ¡Completa tu perfil para publicar productos!</h3>
                        <p>Debes completar la información de tu finca antes de poder agregar productos a tu catálogo.</p>
                        <div class="progreso-perfil">
                            <div class="progreso-barra">
                                <div class="progreso-fill" style="width: <?php echo $porcentaje; ?>%"><?php echo $porcentaje; ?>%</div>
                            </div>
                            <p class="progreso-texto">Completitud del perfil</p>
                        </div>
                        <div class="campos-faltantes">
                            <strong>Campos que te faltan por completar:</strong>
                            <ul>
                                <?php foreach ($campos_faltantes as $campo): ?>
                                    <li><i class="bi bi-x-circle"></i> <?php echo $campo; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <a href="perfil.php" class="btn-completar-perfil">
                            <i class="bi bi-person-fill-check"></i> Completar Mi Perfil Ahora
                        </a>
                    </div>
                </div>

            <?php else: ?>

                <div class="stats-grid">
                    <div class="stat-card primary"><i class="bi bi-box-seam card-icon"></i><h3>Total Productos</h3><div class="number"><?php echo $total_productos; ?></div></div>
                    <div class="stat-card success"><i class="bi bi-check-circle card-icon"></i><h3>Disponibles</h3><div class="number"><?php echo $productos_disponibles; ?></div></div>
                    <div class="stat-card warning"><i class="bi bi-exclamation-triangle card-icon"></i><h3>Stock Bajo</h3><div class="number"><?php echo $productos_stock_bajo; ?></div></div>
                    <div class="stat-card danger"><i class="bi bi-x-circle card-icon"></i><h3>Agotados</h3><div class="number"><?php echo $productos_agotados; ?></div></div>
                </div>

                <?php if ($cat_inactivas['total'] > 0): ?>
                <div class="alert alert-warning" style="margin-top:25px;margin-bottom:25px;">
                    <div style="display:flex;align-items:center;gap:15px;">
                        <i class="bi bi-exclamation-triangle-fill" style="font-size:24px;color:var(--warning);"></i>
                        <div>
                            <strong style="color:var(--warning-dark);">⚠️ Categorías Inactivas Detectadas</strong>
                            <p style="margin:8px 0 0 0;font-size:14px;color:var(--text-dark);">
                                Tienes <strong><?php echo $cat_inactivas['total']; ?></strong>
                                <?php echo $cat_inactivas['total'] == 1 ? 'producto' : 'productos'; ?>
                                en categorías que fueron desactivadas por el administrador.
                            </p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="filtros-productos">
                    <h3 style="margin-bottom:25px;color:var(--text-dark);display:flex;align-items:center;gap:10px;">
                        <i class="bi bi-funnel-fill" style="color:var(--primary);"></i> Filtrar Productos
                    </h3>
                    <div class="filtros-grid" style="display:grid;grid-template-columns:1fr;gap:25px;">
                        <div style="position:relative;width:100%;">
                            <i class="bi bi-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;z-index:1;"></i>
                            <input type="text" id="busquedaProductos" class="form-control" placeholder="Buscar por nombre, descripción o categoría..." style="padding-left:42px;width:100%;">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:30px;margin-top:25px;">
                        <div class="filtro-seccion">
                            <h4><i class="bi bi-tag"></i> Categoría</h4>
                            <select id="filtroCategoria" class="form-control">
                                <option value="0">Todas las categorías</option>
                                <?php
                                $result_todas_categorias->data_seek(0);
                                while ($cat = $result_todas_categorias->fetch_assoc()):
                                    if ($cat['total_productos'] > 0): ?>
                                    <option value="<?php echo $cat['id_categoria']; ?>" <?php echo ($filtro_categoria == $cat['id_categoria']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['nombre_categoria']); ?> (<?php echo $cat['total_productos']; ?>)
                                    </option>
                                <?php endif; endwhile; ?>
                            </select>
                        </div>
                        <div class="filtro-seccion">
                            <h4><i class="bi bi-clipboard-check"></i> Estado del Stock</h4>
                            <div style="display:flex;flex-direction:column;gap:12px;">
                                <label class="checkbox-inline"><input type="checkbox" name="filtroEstado[]" value="disponible" class="filtro-checkbox" data-tipo="estado"><span><span class="badge badge-success" style="padding:4px 10px;">Disponible</span> <small style="color:var(--text-muted);">(Stock normal)</small></span></label>
                                <label class="checkbox-inline"><input type="checkbox" name="filtroEstado[]" value="stock_bajo" class="filtro-checkbox" data-tipo="estado"><span><span class="badge badge-warning" style="padding:4px 10px;">Stock Bajo</span> <small style="color:var(--text-muted);">(&lt; stock mínimo)</small></span></label>
                                <label class="checkbox-inline"><input type="checkbox" name="filtroEstado[]" value="agotado" class="filtro-checkbox" data-tipo="estado"><span><span class="badge badge-danger" style="padding:4px 10px;">Agotado</span> <small style="color:var(--text-muted);">(Sin stock)</small></span></label>
                            </div>
                        </div>
                        <div class="filtro-seccion">
                            <h4><i class="bi bi-currency-dollar"></i> Rango de Precio</h4>
                            <div style="display:flex;flex-direction:column;gap:12px;">
                                <label class="checkbox-inline"><input type="checkbox" name="filtroPrecio[]" value="economico" class="filtro-checkbox" data-tipo="precio"><span><span style="font-weight:500;">Económico</span> <small style="color:var(--text-muted);">(Hasta $5,000)</small></span></label>
                                <label class="checkbox-inline"><input type="checkbox" name="filtroPrecio[]" value="medio" class="filtro-checkbox" data-tipo="precio"><span><span style="font-weight:500;">Medio</span> <small style="color:var(--text-muted);">($5,000 - $20,000)</small></span></label>
                                <label class="checkbox-inline"><input type="checkbox" name="filtroPrecio[]" value="alto" class="filtro-checkbox" data-tipo="precio"><span><span style="font-weight:500;">Alto</span> <small style="color:var(--text-muted);">(Más de $20,000)</small></span></label>
                            </div>
                        </div>
                    </div>
                    <div id="contadorFiltros" style="display:none;">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <i class="bi bi-filter-circle" style="color:var(--primary);font-size:18px;"></i>
                                <div>
                                    <span style="color:var(--text-dark);font-weight:500;">Mostrando <strong id="contadorProductos" style="color:var(--primary);">0</strong> de <span id="totalProductosJs"><?php echo $total_productos; ?></span> productos</span>
                                    <small id="detallesFiltro" style="display:none;color:var(--text-muted);font-size:12px;margin-top:2px;"></small>
                                </div>
                            </div>
                            <button class="btn btn-secondary btn-sm" onclick="limpiarFiltros()"><i class="bi bi-x-circle"></i> Limpiar</button>
                        </div>
                    </div>
                </div>

                <div class="catalogo-container">
                    <div class="catalogo-header">
                        <h2><i class="bi bi-box-seam"></i> Mi Catálogo de Productos</h2>
                        <div class="catalogo-actions">
                            <button class="btn btn-primary" onclick="abrirModalProducto()">
                                <i class="bi bi-plus-circle"></i> Nuevo Producto
                            </button>
                        </div>
                    </div>

                    <?php if (count($productos_array) > 0): ?>
                    <div class="productos-table-container">
                        <div class="table-responsive">
                            <table class="productos-table">
                                <thead>
                                    <tr>
                                        <th>Producto</th><th>Categoría</th><th>Fotos</th><th>Stock</th>
                                        <th>Precio Detal</th><th>Precio Mayorista</th><th>Estado</th><th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($productos_array as $producto):
                                    $badge_class = 'badge-secondary'; $icon_estado = 'bi-question-circle'; $texto_estado = 'Desconocido';
                                    switch($producto['estado']) {
                                        case 'disponible': $badge_class='badge-success'; $icon_estado='bi-check-circle';         $texto_estado='Disponible'; break;
                                        case 'stock_bajo': $badge_class='badge-warning'; $icon_estado='bi-exclamation-triangle'; $texto_estado='Stock Bajo'; break;
                                        case 'agotado':    $badge_class='badge-danger';  $icon_estado='bi-x-circle';             $texto_estado='Agotado';    break;
                                    }
                                ?>
                                <tr class="<?php echo $producto['estado']==='stock_bajo' ? 'row-warning' : ''; ?>" data-producto-id="<?php echo $producto['id_producto']; ?>">

                                    <td class="desktop-cell">
                                        <div class="producto-info">
                                            <?php if (!empty($producto['fotos'])): ?>
                                                <img src="../../<?php echo htmlspecialchars($producto['fotos'][0]['ruta_foto']); ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>" class="producto-img" onclick="verGaleria(<?php echo htmlspecialchars(json_encode($producto['fotos'])); ?>)">
                                            <?php else: ?>
                                                <div class="producto-img" style="background:#f0f0f0;display:flex;align-items:center;justify-content:center;"><i class="bi bi-image" style="font-size:24px;color:#999;"></i></div>
                                            <?php endif; ?>
                                            <div class="producto-text">
                                                <h4><?php echo htmlspecialchars($producto['nombre']); ?></h4>
                                                <p><?php echo htmlspecialchars(substr($producto['descripcion'], 0, 50)) . (strlen($producto['descripcion']) > 50 ? '...' : ''); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="desktop-cell">
                                        <div class="categoria-icon-display">
                                            <i class="<?php echo htmlspecialchars($producto['icono'] ?: 'bi bi-tag'); ?>"></i>
                                            <span><?php echo htmlspecialchars($producto['nombre_categoria']); ?></span>
                                        </div>
                                        <?php if (!$producto['categoria_activa']): ?><br><small style="color:var(--warning);"><i class="bi bi-exclamation-triangle-fill"></i> Categoría inactiva</small><?php endif; ?>
                                    </td>
                                    <td class="desktop-cell">
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <i class="bi bi-images" style="color:var(--primary);"></i>
                                            <strong><?php echo count($producto['fotos']); ?></strong>
                                            <?php if (count($producto['fotos']) < 5): ?><small style="color:var(--text-muted);">(máx. 5)</small><?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="desktop-cell"><strong><?php echo $producto['cantidad']; ?></strong> <?php echo $producto['unidad_medida']; ?>(s)</td>
                                    <td class="desktop-cell"><strong style="color:var(--primary);font-size:16px;">$<?php echo number_format($producto['precio'],0,',','.'); ?></strong></td>
                                    <td class="desktop-cell">
                                        <?php if ($producto['precio_mayorista']): ?>
                                            <strong style="color:var(--success);">$<?php echo number_format($producto['precio_mayorista'],0,',','.'); ?></strong>
                                            <br><small>Desde <?php echo $producto['cantidad_minima_mayorista']; ?> <?php echo $producto['unidad_medida']; ?>s</small>
                                        <?php else: ?><span style="color:var(--text-muted);">No aplica</span><?php endif; ?>
                                    </td>
                                    <td class="desktop-cell"><span class="badge <?php echo $badge_class; ?>"><i class="<?php echo $icon_estado; ?>"></i> <?php echo $texto_estado; ?></span></td>
                                    <td class="desktop-cell">
                                        <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                            <?php if ($producto['categoria_activa']): ?>
                                                <button class="btn btn-sm btn-warning" onclick='editarProducto(<?php echo htmlspecialchars(json_encode($producto), ENT_QUOTES); ?>)'><i class="bi bi-pencil"></i></button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-warning btn-disabled-tooltip" disabled data-tooltip="No se puede editar" style="opacity:0.5;cursor:not-allowed;"><i class="bi bi-pencil"></i></button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-danger" onclick="confirmarEliminar(<?php echo $producto['id_producto']; ?>, '<?php echo htmlspecialchars($producto['nombre']); ?>')"><i class="bi bi-trash"></i></button>
                                        </div>
                                    </td>

                                    <td class="card-header-cell">
                                        <?php if (!empty($producto['fotos'])): ?>
                                            <img src="../../<?php echo htmlspecialchars($producto['fotos'][0]['ruta_foto']); ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>" class="producto-img" onclick="verGaleria(<?php echo htmlspecialchars(json_encode($producto['fotos'])); ?>)">
                                        <?php else: ?>
                                            <div class="producto-img-placeholder"><i class="bi bi-image" style="font-size:24px;color:#999;"></i></div>
                                        <?php endif; ?>
                                        <div class="producto-text">
                                            <h4><?php echo htmlspecialchars($producto['nombre']); ?></h4>
                                            <p><?php echo htmlspecialchars(substr($producto['descripcion'],0,60)) . (strlen($producto['descripcion'])>60?'...':''); ?></p>
                                        </div>
                                    </td>
                                    <td class="card-grid-cell">
                                        <div class="card-data-grid">
                                            <div class="card-data-item">
                                                <div class="card-data-label"><i class="bi bi-tag"></i> Categoría</div>
                                                <div class="card-data-value">
                                                    <i class="<?php echo htmlspecialchars($producto['icono']?:'bi bi-tag'); ?>" style="color:var(--primary);margin-right:4px;"></i><?php echo htmlspecialchars($producto['nombre_categoria']); ?>
                                                    <?php if (!$producto['categoria_activa']): ?><div class="card-inactiva-badge"><i class="bi bi-exclamation-triangle-fill"></i> Inactiva</div><?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="card-data-item">
                                                <div class="card-data-label"><i class="bi bi-images"></i> Fotos</div>
                                                <div class="card-data-value"><strong><?php echo count($producto['fotos']); ?></strong> foto<?php echo count($producto['fotos'])!==1?'s':''; ?> <small style="color:var(--text-muted);">(máx. 5)</small></div>
                                            </div>
                                        </div>
                                        <div class="card-stock-solo">
                                            <div class="card-data-item">
                                                <div class="card-data-label"><i class="bi bi-box"></i> Stock</div>
                                                <div class="card-data-value"><strong><?php echo $producto['cantidad']; ?></strong> <?php echo $producto['unidad_medida']; ?>s</div>
                                            </div>
                                        </div>
                                        <div class="card-data-grid">
                                            <div class="card-data-item">
                                                <div class="card-data-label"><i class="bi bi-cart"></i> Precio detal</div>
                                                <div class="card-data-value precio-detal-valor">$<?php echo number_format($producto['precio'],0,',','.'); ?></div>
                                            </div>
                                            <div class="card-data-item">
                                                <div class="card-data-label"><i class="bi bi-truck"></i> Precio mayorista</div>
                                                <div class="card-data-value">
                                                    <?php if ($producto['precio_mayorista']): ?><span class="precio-mayorista-valor">$<?php echo number_format($producto['precio_mayorista'],0,',','.'); ?></span> <small>desde <?php echo $producto['cantidad_minima_mayorista']; ?> <?php echo $producto['unidad_medida']; ?>s</small><?php else: ?><span style="color:var(--text-muted);">No aplica</span><?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="card-footer-cell">
                                        <span class="badge <?php echo $badge_class; ?>"><i class="<?php echo $icon_estado; ?>"></i> <?php echo $texto_estado; ?></span>
                                        <div class="card-footer-actions">
                                            <?php if ($producto['categoria_activa']): ?>
                                                <button class="btn btn-sm btn-warning" onclick='editarProducto(<?php echo htmlspecialchars(json_encode($producto), ENT_QUOTES); ?>)'><i class="bi bi-pencil"></i> Editar</button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-warning btn-disabled-tooltip" disabled data-tooltip="No se puede editar" style="opacity:0.5;cursor:not-allowed;"><i class="bi bi-pencil"></i> Editar</button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-danger" onclick="confirmarEliminar(<?php echo $producto['id_producto']; ?>, '<?php echo htmlspecialchars($producto['nombre']); ?>')"><i class="bi bi-trash"></i> Eliminar</button>
                                        </div>
                                    </td>

                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="text-align:center;padding:60px 20px;background:#f8f9fa;border-radius:var(--radius-lg);margin-top:20px;">
                        <i class="bi bi-inbox" style="font-size:64px;color:var(--text-muted);display:block;margin-bottom:20px;"></i>
                        <h3 style="color:var(--text-muted);margin-bottom:10px;">No tienes productos<?php echo $filtro_categoria > 0 ? ' en esta categoría' : ' registrados'; ?></h3>
                        <p style="color:var(--text-muted);margin-bottom:25px;">Comienza a crear tu catálogo de productos</p>
                        <button class="btn btn-primary" onclick="abrirModalProducto()"><i class="bi bi-plus-circle"></i> Crear Primer Producto</button>
                    </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>
    </main>
</div>

<div class="modal" id="modalProducto">
    <div class="modal-content" style="max-width:900px;max-height:90vh;">
        <div class="modal-header">
            <h2 id="modalTitulo"><i class="bi bi-plus-circle"></i> Nuevo Producto</h2>
            <button class="close-modal" onclick="cerrarModal()">&times;</button>
        </div>
        <form id="formProducto" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" id="accionProducto" value="crear">
            <input type="hidden" name="id_producto" id="idProducto">

            <div class="form-section">
                <h3 style="margin-bottom:20px;color:var(--primary);border-bottom:2px solid var(--primary);padding-bottom:10px;"><i class="bi bi-info-circle"></i> Información Básica</h3>
                <div class="form-group">
                    <label><i class="bi bi-card-text"></i> Nombre del Producto <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="nombre" id="nombreProducto" required placeholder="Ej: Tomate cherry orgánico" maxlength="100" oninput="validarNombreProducto(this.value)">
                    <div id="mensajeValidacion" style="margin-top:8px;display:none;"></div>
                </div>
                <div class="form-group">
                    <label><i class="bi bi-tag"></i> Categoría <span style="color:var(--danger);">*</span></label>
                    <select name="id_categoria" id="categoriaProducto" required onchange="mostrarInfoCategoria(this)">
                        <option value="">-- Seleccionar categoría --</option>
                        <?php if ($result_categorias_select->num_rows > 0) { $result_categorias_select->data_seek(0); while ($cat = $result_categorias_select->fetch_assoc()): ?>
                        <option value="<?php echo $cat['id_categoria']; ?>" data-icono="<?php echo htmlspecialchars($cat['icono'] ?? 'bi bi-tag'); ?>" data-descripcion="<?php echo htmlspecialchars($cat['descripcion'] ?? 'Sin descripción'); ?>">
                            <?php echo htmlspecialchars($cat['nombre_categoria']); ?>
                        </option>
                        <?php endwhile; } ?>
                    </select>
                    <div id="infoCategoriaSeleccionada" style="display:none;margin-top:15px;padding:15px;background:#f8f9fa;border-radius:var(--radius);border-left:4px solid var(--primary);">
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                            <i id="iconoCategoriaSeleccionada" style="font-size:28px;color:var(--primary);"></i>
                            <strong>Información de la categoría:</strong>
                        </div>
                        <p id="descripcionCategoriaSeleccionada" style="color:var(--text-muted);margin:0;line-height:1.6;font-size:14px;"></p>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="bi bi-text-paragraph"></i> Descripción</label>
                    <textarea name="descripcion" id="descripcionProducto" rows="4" placeholder="Describe las características de tu producto..."></textarea>
                </div>
            </div>

            <div class="form-section" style="margin-top:30px;">
                <h3 style="margin-bottom:20px;color:var(--primary);border-bottom:2px solid var(--primary);padding-bottom:10px;"><i class="bi bi-currency-dollar"></i> Inventario y Precios</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <div class="form-group">
                        <label><i class="bi bi-rulers"></i> Unidad de Medida *</label>
                        <select name="unidad_medida" id="unidadMedida" required>
                            <option value="unidad">Unidad</option><option value="libra">Libra</option>
                            <option value="kilogramo">Kilogramo</option><option value="canastilla">Canastilla</option>
                            <option value="bulto">Bulto</option><option value="arroba">Arroba</option>
                            <option value="litro">Litro</option><option value="docena">Docena</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="bi bi-box"></i> Cantidad en Stock *</label>
                        <input type="number" name="cantidad" id="cantidadProducto" required min="0" placeholder="0">
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="bi bi-exclamation-triangle"></i> Stock Mínimo de Alerta *</label>
                    <input type="number" name="stock_minimo" id="stockMinimo" required min="1" value="5" placeholder="5">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <div class="form-group">
                        <label><i class="bi bi-cart"></i> Precio de Detal *</label>
                        <input type="number" name="precio" id="precioProducto" required min="3000" step="1" placeholder="0.00" oninput="validarPrecios()">
                    </div>
                    <div class="form-group">
                        <label><i class="bi bi-truck"></i> Precio Mayorista (Opcional)</label>
                        <input type="number" name="precio_mayorista" id="precioMayorista" min="0" step="0.01" placeholder="0.00" oninput="validarPrecios()">
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="bi bi-box-seam"></i> Cantidad Mínima para Precio Mayorista</label>
                    <input type="number" name="cantidad_minima_mayorista" id="cantidadMinimaMayorista" min="2" value="10" placeholder="10">
                </div>
                <div id="alertaPrecio" style="display:none;margin-top:15px;"></div>
            </div>

            <div class="form-section" style="margin-top:30px;">
                <h3 style="margin-bottom:20px;color:var(--primary);border-bottom:2px solid var(--primary);padding-bottom:10px;">
                    <i class="bi bi-images"></i> Fotos del Producto
                    <small style="font-weight:normal;color:var(--text-muted);font-size:14px;">(Máximo 5 fotos)</small>
                </h3>

                <div id="zonaFotosCrear">
                    <div class="form-group">
                        <label class="dropzone-multiple" for="imagenesProducto">
                            <i class="bi bi-cloud-upload"></i>
                            <strong>Haz clic o arrastra fotos aquí</strong>
                            <p style="margin:10px 0 0;font-size:14px;color:var(--text-muted);">PNG, JPG, WEBP · Máximo 5 fotos · Primera foto será la principal</p>
                        </label>
                        <input type="file" name="imagenes[]" id="imagenesProducto" accept="image/jpeg,image/jpg,image/png,image/webp" multiple hidden onchange="previsualizarImagenes(this)">
                        <div id="previewImagenes" class="preview-fotos"></div>
                    </div>
                </div>

                <div id="fotosExistentes" style="display:none;margin-top:20px;">
                    <h4 style="margin-bottom:15px;color:var(--text-dark);"><i class="bi bi-images"></i> Fotos Actuales <span id="contadorFotosExistentes" style="font-size:13px;color:var(--text-muted);font-weight:normal;"></span></h4>
                    <div id="galeriaFotosExistentes" class="fotos-galeria"></div>
                </div>

                <div id="agregarMasFotos" style="display:none;margin-top:20px;">
                    <label class="dropzone-multiple" for="imagenesNuevas" style="padding:20px;">
                        <i class="bi bi-plus-circle" style="font-size:32px;"></i>
                        <strong>Agregar más fotos</strong>
                        <small style="color:var(--text-muted);" id="textoFotosRestantes"></small>
                    </label>
                    <input type="file" name="imagenes_nuevas[]" id="imagenesNuevas" accept="image/jpeg,image/jpg,image/png,image/webp" multiple hidden onchange="previsualizarImagenesNuevas(this)">
                    <div id="previewImagenesNuevas" class="preview-fotos"></div>
                </div>
            </div>

            <div class="form-actions" style="margin-top:30px;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal()"><i class="bi bi-x-circle"></i> Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnGuardar"><i class="bi bi-save"></i> Guardar Producto</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="modalEliminar">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header">
            <h2><i class="bi bi-exclamation-triangle"></i> Confirmar Eliminación</h2>
            <button class="close-modal" onclick="cerrarModalEliminar()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="id_producto" id="idProductoEliminar">
            <p style="font-size:16px;margin:20px 0;">¿Estás seguro de que deseas eliminar el producto <strong id="nombreProductoEliminar"></strong>?</p>
            <p style="color:var(--danger);margin:15px 0;"><i class="bi bi-exclamation-circle"></i> Esta acción eliminará el producto y todas sus fotos.</p>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalEliminar()"><i class="bi bi-x-circle"></i> Cancelar</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Eliminar Producto</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-galeria" id="modalGaleria">
    <div class="galeria-content">
        <button class="galeria-close" onclick="cerrarGaleria()"><i class="bi bi-x-lg"></i> Cerrar</button>
        <img id="galeriaImagen" src="" alt="Producto">
    </div>
</div>

<script>
function bloquearScroll() { document.body.classList.add('modal-open'); }
function desbloquearScroll() { document.body.classList.remove('modal-open'); }

function mostrarToastArchivoInvalido(archivos) {
    const existente = document.getElementById('toastArchivoInvalido');
    if (existente) {
        existente.style.animation = 'toastSlideOut 0.3s ease forwards';
        setTimeout(() => existente.remove(), 300);
    }

    const listaArchivos = archivos.map(a => `<span><i class="bi bi-file-earmark-x" style="color:#dc3545;margin-right:4px;"></i>${a}</span>`).join('');

    const toast = document.createElement('div');
    toast.className = 'toast-archivo-invalido';
    toast.id = 'toastArchivoInvalido';
    toast.innerHTML = `
        <div class="toast-barra"></div>
        <div class="toast-inner">
            <div class="toast-icono-wrap">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <div class="toast-contenido">
                <div class="toast-titulo">
                    Archivo${archivos.length > 1 ? 's' : ''} no permitido${archivos.length > 1 ? 's' : ''}
                </div>
                <div class="toast-archivos">${listaArchivos}</div>
                <div class="toast-formatos">
                    <span>Solo se permiten:</span>
                    <span class="formato-badge">JPG</span>
                    <span class="formato-badge">PNG</span>
                    <span class="formato-badge">WEBP</span>
                </div>
            </div>
            <button class="toast-cerrar" onclick="cerrarToast()">&times;</button>
        </div>
    `;

    document.body.appendChild(toast);

    setTimeout(() => cerrarToast(), 5000);
}

function cerrarToast() {
    const toast = document.getElementById('toastArchivoInvalido');
    if (toast) {
        toast.style.animation = 'toastSlideOut 0.3s ease forwards';
        setTimeout(() => { if (toast.parentElement) toast.remove(); }, 300);
    }
}

let archivosSeleccionados    = [];
let fotosExistentesProducto  = [];
let productoEnEdicion        = null;
let productosOriginales      = <?php echo $productos_json; ?>;
let timeoutBusqueda;
let filtrosActivos = { categoria: <?php echo $filtro_categoria; ?>, estados: [], precio: [], busqueda: '' };
let nombreValido = true;

document.addEventListener('DOMContentLoaded', function () {
    inicializarFiltros();
    const urlParams = new URLSearchParams(window.location.search);
    const estadoUrl = urlParams.get('estado');
    if (estadoUrl) {
        const cb = document.querySelector(`.filtro-checkbox[data-tipo="estado"][value="${estadoUrl}"]`);
        if (cb) { cb.checked = true; filtrosActivos.estados = [estadoUrl]; }
        setTimeout(() => {
            const tabla = document.querySelector('.catalogo-container');
            if (tabla) tabla.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 300);
    }
    aplicarFiltros();
});

function inicializarFiltros() {
    const b = document.getElementById('busquedaProductos');
    if (b) {
        b.addEventListener('input', e => {
            clearTimeout(timeoutBusqueda);
            timeoutBusqueda = setTimeout(() => { filtrosActivos.busqueda = e.target.value.toLowerCase().trim(); aplicarFiltros(); }, 300);
        });
        b.addEventListener('keydown', e => { if (e.key==='Escape') { e.target.value=''; filtrosActivos.busqueda=''; aplicarFiltros(); } });
    }
    const fc = document.getElementById('filtroCategoria');
    if (fc) {
        filtrosActivos.categoria = parseInt(fc.value) || 0;
        fc.addEventListener('change', function () { filtrosActivos.categoria = parseInt(this.value) || 0; aplicarFiltros(); });
    }
    document.querySelectorAll('.filtro-checkbox[data-tipo="estado"]').forEach(cb => cb.addEventListener('change', actualizarFiltrosEstado));
    document.querySelectorAll('.filtro-checkbox[data-tipo="precio"]').forEach(cb => cb.addEventListener('change', actualizarFiltrosPrecio));
}
function actualizarFiltrosEstado() {
    filtrosActivos.estados = [...document.querySelectorAll('.filtro-checkbox[data-tipo="estado"]:checked')].map(c => c.value);
    aplicarFiltros();
}
function actualizarFiltrosPrecio() {
    filtrosActivos.precio = [...document.querySelectorAll('.filtro-checkbox[data-tipo="precio"]:checked')].map(c => c.value);
    aplicarFiltros();
}
function limpiarFiltros() {
    const b = document.getElementById('busquedaProductos'); if (b) b.value = '';
    const f = document.getElementById('filtroCategoria');   if (f) f.value = '0';
    document.querySelectorAll('.filtro-checkbox').forEach(cb => cb.checked = false);
    filtrosActivos = { categoria: 0, estados: [], precio: [], busqueda: '' };
    aplicarFiltros();
}
function aplicarFiltros() {
    let visible = 0;
    document.querySelectorAll('.productos-table tbody tr').forEach(fila => {
        const pid     = parseInt(fila.dataset.productoId) || 0;
        const producto = productosOriginales.find(p => p.id_producto === pid);
        if (!producto) { fila.classList.add('producto-oculto'); return; }
        let mostrar = true;
        if (filtrosActivos.categoria > 0 && producto.id_categoria !== filtrosActivos.categoria) mostrar = false;
        if (mostrar && filtrosActivos.estados.length > 0 && !filtrosActivos.estados.includes(producto.estado)) mostrar = false;
        if (mostrar && filtrosActivos.precio.length > 0) {
            const p = producto.precio;
            const rango = p < 5000 ? 'economico' : p <= 20000 ? 'medio' : 'alto';
            if (!filtrosActivos.precio.includes(rango)) mostrar = false;
        }
        if (mostrar && filtrosActivos.busqueda) {
            const t = filtrosActivos.busqueda;
            if (!producto.nombre.toLowerCase().includes(t) &&
                !(producto.descripcion||'').toLowerCase().includes(t) &&
                !(producto.nombre_categoria||'').toLowerCase().includes(t)) mostrar = false;
        }
        if (mostrar) { fila.classList.remove('producto-oculto'); fila.classList.add('producto-filtrado'); visible++; }
        else         { fila.classList.remove('producto-filtrado'); fila.classList.add('producto-oculto'); }
    });
    actualizarContadorFiltros(visible);
    mostrarMensajeSinResultados(visible);
}
function actualizarContadorFiltros(cantidad) {
    const cont = document.getElementById('contadorFiltros');
    const cp   = document.getElementById('contadorProductos');
    const tp   = document.getElementById('totalProductosJs');
    if (!cont || !cp || !tp) return;
    const activo = filtrosActivos.categoria > 0 || filtrosActivos.estados.length > 0 || filtrosActivos.precio.length > 0 || filtrosActivos.busqueda !== '';
    cont.style.display = activo ? 'block' : 'none';
    cp.textContent = cantidad;
    tp.textContent = productosOriginales.length;
    const det = document.getElementById('detallesFiltro');
    if (det) {
        let t = [];
        const sc = document.getElementById('filtroCategoria');
        if (sc && sc.value !== '0') t.push('Categoría: ' + sc.options[sc.selectedIndex].text.split(' (')[0]);
        if (filtrosActivos.estados.length > 0) t.push('Estados: ' + filtrosActivos.estados.map(e => ({disponible:'Disponible',stock_bajo:'Stock Bajo',agotado:'Agotado'})[e]).join(', '));
        if (filtrosActivos.precio.length > 0)  t.push('Precios: ' + filtrosActivos.precio.map(p => ({economico:'Económico',medio:'Medio',alto:'Alto'})[p]).join(', '));
        if (filtrosActivos.busqueda) t.push(`Búsqueda: "${filtrosActivos.busqueda}"`);
        det.textContent   = t.join(' • ');
        det.style.display = t.length > 0 ? 'block' : 'none';
    }
}
function mostrarMensajeSinResultados(cantidad) {
    let m = document.querySelector('.mensaje-sin-resultados');
    if (cantidad === 0) {
        if (!m) {
            m = document.createElement('div');
            m.className = 'mensaje-sin-resultados';
            m.style.cssText = 'text-align:center;padding:60px 20px;background:#f8f9fa;border-radius:var(--radius-lg);margin-top:20px;';
            m.innerHTML = `<i class="bi bi-inbox" style="font-size:64px;color:var(--text-muted);display:block;margin-bottom:20px;"></i><h3 style="color:var(--text-muted);margin-bottom:10px;">No tienes productos que coincidan</h3><p style="color:var(--text-muted);">Intenta ajustar los filtros</p>`;
            const tc = document.querySelector('.productos-table-container');
            if (tc) tc.parentNode.insertBefore(m, tc.nextSibling);
        }
    } else if (m) { m.remove(); }
}

function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }

let timeoutValidacion;
function validarNombreProducto(nombre) {
    const msg      = document.getElementById('mensajeValidacion');
    const btnGuard = document.getElementById('btnGuardar');
    clearTimeout(timeoutValidacion);
    if (!nombre.trim()) { msg.style.display = 'none'; nombreValido = true; btnGuard.disabled = false; return; }
    msg.style.display = 'block'; msg.style.color = '#6c757d';
    msg.innerHTML = '<i class="bi bi-hourglass-split"></i> Verificando...';
    btnGuard.disabled = true;
    timeoutValidacion = setTimeout(() => {
        const idP = document.getElementById('idProducto').value;
        fetch(`?accion=verificar_nombre&nombre=${encodeURIComponent(nombre)}&id_producto=${idP}`)
            .then(r => r.json()).then(data => {
                if (data.existe) {
                    msg.style.color = 'var(--danger)';
                    msg.innerHTML = '<i class="bi bi-x-circle-fill"></i> Ya tienes un producto con este nombre';
                    nombreValido = false; btnGuard.disabled = true;
                } else {
                    msg.style.color = 'var(--success)';
                    msg.innerHTML = '<i class="bi bi-check-circle-fill"></i> Nombre disponible';
                    nombreValido = true; btnGuard.disabled = false;
                }
            }).catch(() => { msg.style.display = 'none'; nombreValido = true; btnGuard.disabled = false; });
    }, 500);
}
function validarPrecios() {
    const pf = parseFloat(document.getElementById('precioProducto').value) || 0;
    const pm = parseFloat(document.getElementById('precioMayorista').value) || 0;
    const alerta = document.getElementById('alertaPrecio');
    
    if (pf > 0 && pf < 3000) {
    alerta.style.cssText = 'display:block;padding:15px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:var(--radius);';
    alerta.innerHTML = '<strong style="color:#856404;"><i class="bi bi-exclamation-triangle"></i> Advertencia:</strong><br>El precio mínimo permitido es <strong>$3.000 COP</strong>';
    document.getElementById('btnGuardar').disabled = true;
    return;
    }

    if (pm > 0 && pm >= pf) {
        alerta.style.cssText = 'display:block;padding:15px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:var(--radius);';
        alerta.innerHTML = '<strong style="color:#856404;"><i class="bi bi-exclamation-triangle"></i> Advertencia:</strong><br>El precio mayorista debe ser menor que el precio de detal';
        document.getElementById('btnGuardar').disabled = true;
    } else {
        alerta.style.display = 'none';
        if (nombreValido) document.getElementById('btnGuardar').disabled = false;
    }
}

function filtrarArchivosValidos(files) {
    const permitidos = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    const validos    = [];
    const rechazados = [];

    Array.from(files).forEach(file => {
        if (permitidos.includes(file.type)) {
            validos.push(file);
        } else {
            rechazados.push(file.name);
        }
    });

    if (rechazados.length > 0) {
        mostrarToastArchivoInvalido(rechazados);
    }

    return validos;
}


document.addEventListener('DOMContentLoaded', function () {
    configurarDropzone('imagenesProducto', 'zonaFotosCrear', false);
    configurarDropzone('imagenesNuevas',   'agregarMasFotos', true);
});

function configurarDropzone(inputId, zonaId, esEdicion) {
    const zona  = document.getElementById(zonaId);
    const input = document.getElementById(inputId);
    if (!zona || !input) return;

    ['dragenter', 'dragover'].forEach(evento => {
        zona.addEventListener(evento, e => {
            e.preventDefault();
            zona.querySelector('label')?.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach(evento => {
        zona.addEventListener(evento, e => {
            e.preventDefault();
            zona.querySelector('label')?.classList.remove('dragover');
        });
    });

    zona.addEventListener('drop', e => {
        const archivos = e.dataTransfer.files;
        if (!archivos.length) return;
        const dt = new DataTransfer();
        Array.from(archivos).forEach(f => dt.items.add(f));
        input.files = dt.files;
        esEdicion ? previsualizarImagenesNuevas(input) : previsualizarImagenes(input);
    });
}
function previsualizarImagenes(input) {
    const preview = document.getElementById('previewImagenes');
    preview.innerHTML = '';
    archivosSeleccionados = [];
    if (!input.files || !input.files.length) return;

    const validos = filtrarArchivosValidos(input.files);
    const maxFiles = Math.min(validos.length, 5);

    for (let i = 0; i < maxFiles; i++) {
        archivosSeleccionados.push(validos[i]);
        const reader = new FileReader();
        const idx = i;
        reader.onload = e => {
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.innerHTML = idx === 0
                ? `<img src="${e.target.result}" alt="Preview"><div class="foto-badge">Principal</div><button type="button" class="remove-preview" onclick="eliminarPreview(${idx})"><i class="bi bi-x"></i></button>`
                : `<img src="${e.target.result}" alt="Preview"><button type="button" class="remove-preview" onclick="eliminarPreview(${idx})"><i class="bi bi-x"></i></button>`;
            preview.appendChild(div);
        };
        reader.readAsDataURL(validos[i]);
    }

    const dt = new DataTransfer();
    archivosSeleccionados.forEach(f => dt.items.add(f));
    input.files = dt.files;
}

function previsualizarImagenesNuevas(input) {
    const preview = document.getElementById('previewImagenesNuevas');
    preview.innerHTML = '';
    if (!input.files || !input.files.length) return;

    const validos  = filtrarArchivosValidos(input.files);
    const maxFiles = Math.min(validos.length, 5);

    for (let i = 0; i < maxFiles; i++) {
        const reader = new FileReader();
        reader.onload = e => {
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.innerHTML = `<img src="${e.target.result}" alt="Nueva foto">`;
            preview.appendChild(div);
        };
        reader.readAsDataURL(validos[i]);
    }

    const dt = new DataTransfer();
    validos.forEach(f => dt.items.add(f));
    input.files = dt.files;
}

function eliminarPreview(index) {
    archivosSeleccionados.splice(index, 1);
    const dt = new DataTransfer();
    archivosSeleccionados.forEach(f => dt.items.add(f));
    document.getElementById('imagenesProducto').files = dt.files;
    previsualizarImagenes(document.getElementById('imagenesProducto'));
}

function mostrarInfoCategoria(select) {
    const opt = select.options[select.selectedIndex];
    const infoDiv = document.getElementById('infoCategoriaSeleccionada');
    if (opt.value) {
        document.getElementById('iconoCategoriaSeleccionada').className = opt.getAttribute('data-icono') || 'bi bi-tag';
        document.getElementById('descripcionCategoriaSeleccionada').textContent = opt.getAttribute('data-descripcion') || 'Sin descripción';
        infoDiv.style.display = 'block';
    } else { infoDiv.style.display = 'none'; }
}

function abrirModalProducto() {
    productoEnEdicion = null;
    document.getElementById('modalTitulo').innerHTML = '<i class="bi bi-plus-circle"></i> Nuevo Producto';
    document.getElementById('accionProducto').value = 'crear';
    document.getElementById('formProducto').reset();
    document.getElementById('idProducto').value = '';
    document.getElementById('previewImagenes').innerHTML = '';
    document.getElementById('mensajeValidacion').style.display = 'none';
    document.getElementById('alertaPrecio').style.display = 'none';
    document.getElementById('infoCategoriaSeleccionada').style.display = 'none';
    document.getElementById('fotosExistentes').style.display = 'none';
    document.getElementById('agregarMasFotos').style.display = 'none';
    document.getElementById('zonaFotasCrear') && (document.getElementById('zonaFotosCrear').style.display = 'block');
    nombreValido = true; archivosSeleccionados = [];
    document.getElementById('btnGuardar').disabled = false;
    document.getElementById('modalProducto').classList.add('show');
    bloquearScroll();
}

function editarProducto(producto) {
    if (!producto.categoria_activa) { alert('No es posible editar este producto porque la categoría está inactiva.'); return; }
    productoEnEdicion       = producto;
    fotosExistentesProducto = producto.fotos || [];

    document.getElementById('modalTitulo').innerHTML = '<i class="bi bi-pencil"></i> Editar Producto';
    document.getElementById('accionProducto').value  = 'editar';
    document.getElementById('idProducto').value      = producto.id_producto;
    document.getElementById('nombreProducto').value  = producto.nombre;
    document.getElementById('categoriaProducto').value   = producto.id_categoria;
    document.getElementById('cantidadProducto').value    = producto.cantidad;
    document.getElementById('precioProducto').value      = producto.precio;
    document.getElementById('precioMayorista').value     = producto.precio_mayorista || '';
    document.getElementById('cantidadMinimaMayorista').value = producto.cantidad_minima_mayorista || 10;
    document.getElementById('unidadMedida').value    = producto.unidad_medida || 'unidad';
    document.getElementById('stockMinimo').value     = producto.stock_minimo || 5;
    document.getElementById('descripcionProducto').value = producto.descripcion || '';

    document.getElementById('mensajeValidacion').style.display  = 'none';
    document.getElementById('alertaPrecio').style.display       = 'none';
    document.getElementById('previewImagenes').innerHTML        = '';
    document.getElementById('previewImagenesNuevas').innerHTML  = '';
    nombreValido = true;
    mostrarInfoCategoria(document.getElementById('categoriaProducto'));

    const zonaCrear = document.getElementById('zonaFotosCrear');
    if (zonaCrear) zonaCrear.style.display = 'none';

    renderizarFotosExistentes(fotosExistentesProducto, producto.id_producto);

    document.getElementById('btnGuardar').disabled = false;
    document.getElementById('modalProducto').classList.add('show');
    bloquearScroll();
}

function renderizarFotosExistentes(fotos, idProducto) {
    const contenedor = document.getElementById('fotosExistentes');
    const galeria    = document.getElementById('galeriaFotosExistentes');
    const masDiv     = document.getElementById('agregarMasFotos');
    const textoRest  = document.getElementById('textoFotosRestantes');
    const contador   = document.getElementById('contadorFotosExistentes');

    if (fotos.length > 0) {
        contenedor.style.display = 'block';
        if (contador) contador.textContent = `(${fotos.length}/5)`;
        galeria.innerHTML = '';
        fotos.forEach((foto, index) => {
            const div = document.createElement('div');
            div.className = 'foto-item' + (foto.orden === 0 ? ' principal' : '');
            div.dataset.idFoto = foto.id_foto;
            div.innerHTML = `
                <img src="../../${foto.ruta_foto}" alt="Foto ${index + 1}" style="cursor:pointer;" onclick="verGaleriaUna('../../${foto.ruta_foto}')">
                ${foto.orden === 0 ? '<div class="foto-badge">Principal</div>' : ''}
                <button type="button" class="foto-eliminar-btn" onclick="eliminarFotoAjax(${foto.id_foto}, ${idProducto}, this)">
                    <i class="bi bi-trash"></i>
                </button>`;
            galeria.appendChild(div);
        });
    } else {
        contenedor.style.display = 'none';
    }

    if (fotos.length < 5) {
        masDiv.style.display = 'block';
        if (textoRest) textoRest.textContent = `Puedes agregar ${5 - fotos.length} foto(s) más`;
        document.getElementById('previewImagenesNuevas').innerHTML = '';
        document.getElementById('imagenesNuevas').value = '';
    } else {
        masDiv.style.display = 'none';
    }
}

function eliminarFotoAjax(idFoto, idProducto, btnEl) {
    if (!confirm('¿Eliminar esta foto?')) return;

    btnEl.classList.add('cargando');
    btnEl.innerHTML = '<i class="bi bi-hourglass-split"></i>';

    const fd = new FormData();
    fd.append('id_foto',     idFoto);
    fd.append('id_producto', idProducto);

    fetch('?accion=eliminar_foto_ajax', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                fotosExistentesProducto = data.fotos;
                if (productoEnEdicion) productoEnEdicion.fotos = data.fotos;
                renderizarFotosExistentes(data.fotos, idProducto);
                actualizarFilaTabla(idProducto, data.fotos);
            } else {
                alert('No se pudo eliminar la foto: ' + (data.error || 'Error desconocido'));
                btnEl.classList.remove('cargando');
                btnEl.innerHTML = '<i class="bi bi-trash"></i>';
            }
        })
        .catch(() => {
            alert('Error de red al eliminar la foto. Intenta de nuevo.');
            btnEl.classList.remove('cargando');
            btnEl.innerHTML = '<i class="bi bi-trash"></i>';
        });
}

function actualizarFilaTabla(idProducto, fotos) {
    const fila = document.querySelector(`tr[data-producto-id="${idProducto}"]`);
    if (!fila) return;

    const idx = productosOriginales.findIndex(p => p.id_producto === idProducto);
    if (idx !== -1) productosOriginales[idx].fotos = fotos;

    const imgDesktop = fila.querySelector('.desktop-cell .producto-img');
    if (imgDesktop) {
        if (fotos.length > 0) {
            imgDesktop.src = '../../' + fotos[0].ruta_foto;
            imgDesktop.setAttribute('onclick', `verGaleria(${JSON.stringify(fotos)})`);
        } else {
            imgDesktop.style.background = '#f0f0f0';
            imgDesktop.src = '';
        }
    }

    const imgMovil = fila.querySelector('.card-header-cell .producto-img');
    if (imgMovil && fotos.length > 0) {
        imgMovil.src = '../../' + fotos[0].ruta_foto;
        imgMovil.setAttribute('onclick', `verGaleria(${JSON.stringify(fotos)})`);
    }

    const celdaFotos = fila.querySelectorAll('.desktop-cell');
    if (celdaFotos[2]) {
        const strong = celdaFotos[2].querySelector('strong');
        if (strong) strong.textContent = fotos.length;
    }
}

function verGaleriaUna(src) {
    document.getElementById('galeriaImagen').src = src;
    document.getElementById('modalGaleria').classList.add('show');
    bloquearScroll();
}

function cerrarModal() {
    document.getElementById('modalProducto').classList.remove('show');
    const zonaCrear = document.getElementById('zonaFotosCrear');
    if (zonaCrear) zonaCrear.style.display = 'block';
    desbloquearScroll();
}
function confirmarEliminar(id, nombre) {
    document.getElementById('idProductoEliminar').value = id;
    document.getElementById('nombreProductoEliminar').textContent = nombre;
    document.getElementById('modalEliminar').classList.add('show');
    bloquearScroll();
}
function cerrarModalEliminar() { document.getElementById('modalEliminar').classList.remove('show'); desbloquearScroll(); }
function verGaleria(fotos) {
    if (fotos && fotos.length > 0) {
        document.getElementById('galeriaImagen').src = '../../' + fotos[0].ruta_foto;
        document.getElementById('modalGaleria').classList.add('show');
        bloquearScroll();
    }
}
function cerrarGaleria() { document.getElementById('modalGaleria').classList.remove('show'); desbloquearScroll(); }

window.onclick = function (event) {
    if (event.target === document.getElementById('modalProducto'))  cerrarModal();
    if (event.target === document.getElementById('modalEliminar'))  cerrarModalEliminar();
    if (event.target === document.getElementById('modalGaleria'))   cerrarGaleria();
};

document.addEventListener('click', e => {
    const sidebar    = document.getElementById('sidebar');
    const menuToggle = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !menuToggle.contains(e.target) && sidebar.classList.contains('open'))
        sidebar.classList.remove('open');
});

setTimeout(() => {
    const alertEl = document.querySelector('.alert');
    if (alertEl && (alertEl.classList.contains('alert-success') || alertEl.classList.contains('alert-danger') || alertEl.classList.contains('alert-warning'))) {
        alertEl.style.transition = 'opacity 0.5s'; alertEl.style.opacity = '0';
        setTimeout(() => alertEl.remove(), 500);
    }
}, 5000);

document.getElementById('formProducto').addEventListener('submit', function (e) {
    if (!nombreValido) { e.preventDefault(); alert('Por favor corrige el nombre del producto'); return false; }
    const pf = parseFloat(document.getElementById('precioProducto').value) || 0;
    const pm = parseFloat(document.getElementById('precioMayorista').value) || 0;
    if (pf < 3000) { e.preventDefault(); alert('El precio mínimo es $3.000'); return false; } 
    if (pm > 0 && pm >= pf) { e.preventDefault(); alert('El precio mayorista debe ser menor que el precio de detal'); return false; }
});
</script>

</body>
</html>
<?php $conn->close(); ?>