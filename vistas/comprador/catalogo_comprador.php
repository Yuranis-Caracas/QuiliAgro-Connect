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


if (isset($_GET['action']) && $_GET['action'] === 'guardar_busqueda') {
    header('Content-Type: application/json');
    $termino    = trim($_GET['termino'] ?? '');
    $id_cat     = isset($_GET['id_categoria']) && $_GET['id_categoria'] !== 'todas'
                  ? intval($_GET['id_categoria']) : null;
    if (!empty($termino) || $id_cat) {
        $termino_final = $termino ?: 'Filtro por categoría';
        $stmt = $conn->prepare(
            "INSERT INTO historial_busquedas (id_comprador, termino_busqueda, id_categoria)
             VALUES (?, ?, ?)"
        );
        $stmt->bind_param("isi", $usuario_id, $termino_final, $id_cat);
        $stmt->execute();
    }
    echo json_encode(['ok' => true]);
    exit();
}



if (isset($_GET['ac']) && $_GET['ac'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) { echo json_encode([]); exit(); }

    $like = '%' . $conn->real_escape_string($q) . '%';
    $qEsc = $conn->real_escape_string($q);
    $result_ac = $conn->query("
        SELECT DISTINCT p.nombre, c.nombre_categoria, c.icono
        FROM productos p
        INNER JOIN categorias_productos c ON p.id_categoria = c.id_categoria
        WHERE p.nombre LIKE '$like'
          AND p.estado != 'agotado'
          AND c.activa = 1
        ORDER BY
            CASE WHEN p.nombre LIKE '{$qEsc}%' THEN 0 ELSE 1 END,
            p.nombre ASC
        LIMIT 8
    ");
    $out = [];
    if ($result_ac) {
        while ($row = $result_ac->fetch_assoc()) {
            $out[] = [
                'nombre'    => $row['nombre'],
                'categoria' => $row['nombre_categoria'],
                'icono'     => $row['icono'] ?: 'bi bi-tag',
            ];
        }
    }
    echo json_encode($out);
    exit();
}

$usuario_nombre = htmlspecialchars($_SESSION['user_nombre']);

function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param($tipos, ...$params);
            $ok = $stmt->execute();
            return (stripos(trim($query), 'select') === 0) ? $stmt->get_result() : $ok;
        }
        $result = $conn->query($query);
        if (!$result) throw new Exception($conn->error);
        return $result;
    } catch (Exception $e) {
        error_log('Error BD: ' . $e->getMessage());
        return false;
    }
}

$mensaje = '';
$tipo_mensaje = '';

if (isset($_SESSION['mensaje_temporal'])) {
    $mensaje = $_SESSION['mensaje_temporal'];
    $tipo_mensaje = $_SESSION['tipo_mensaje_temporal'];
    unset($_SESSION['mensaje_temporal'], $_SESSION['tipo_mensaje_temporal']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'agregar_carrito') {
    $id_producto = intval($_POST['id_producto']);
    $cantidad    = intval($_POST['cantidad']);

    $r = ejecutarConsulta($conn,
        "SELECT p.*, c.activa as categoria_activa, u.nombre as nombre_productor
         FROM productos p
         INNER JOIN categorias_productos c ON p.id_categoria = c.id_categoria
         INNER JOIN usuarios u ON p.id_productor = u.id_usuario
         WHERE p.id_producto = ? AND c.activa = 1", "i", [$id_producto]);

    if (!$r || $r->num_rows === 0) {
        $mensaje = "❌ Este producto no está disponible o su categoría está inactiva";
        $tipo_mensaje = "danger";
    } else {
        $producto = $r->fetch_assoc();

        if ($producto['estado'] === 'agotado') {
            $mensaje = "❌ Este producto está agotado";
            $tipo_mensaje = "warning";
        } elseif ($cantidad > $producto['cantidad']) {
            $mensaje = "❌ Solo hay {$producto['cantidad']} unidades disponibles";
            $tipo_mensaje = "warning";
        } elseif ($cantidad <= 0) {
            $mensaje = "❌ La cantidad debe ser mayor a cero";
            $tipo_mensaje = "warning";
        } else {
            $precio_unitario = ($producto['precio_mayorista'] && $cantidad >= $producto['cantidad_minima_mayorista'])
                ? floatval($producto['precio_mayorista'])
                : floatval($producto['precio']);

            if (!isset($_SESSION['carrito'])) $_SESSION['carrito'] = [];

            $encontrado = false;
            foreach ($_SESSION['carrito'] as &$item) {
                if ($item['id_producto'] == $id_producto) {
                    $nueva_cantidad = $item['cantidad'] + $cantidad;
                    if ($nueva_cantidad > $producto['cantidad']) {
                        $mensaje = "❌ No puedes agregar más de {$producto['cantidad']} unidades";
                        $tipo_mensaje = "warning";
                    } else {
                        $item['cantidad'] = $nueva_cantidad;
                        $item['precio']   = ($producto['precio_mayorista'] && $nueva_cantidad >= $producto['cantidad_minima_mayorista'])
                            ? floatval($producto['precio_mayorista'])
                            : floatval($producto['precio']);
                        $mensaje = "✅ Producto actualizado en el carrito";
                        $tipo_mensaje = "success";
                    }
                    $encontrado = true;
                    break;
                }
            }
            unset($item);

            if (!$encontrado) {
                $_SESSION['carrito'][] = [
                    'id_producto'              => $id_producto,
                    'id_productor'             => $producto['id_productor'],
                    'nombre_productor'         => $producto['nombre_productor'],
                    'nombre'                   => $producto['nombre'],
                    'precio'                   => $precio_unitario,
                    'precio_detal'             => floatval($producto['precio']),
                    'precio_mayorista'         => $producto['precio_mayorista'] ? floatval($producto['precio_mayorista']) : null,
                    'cantidad_minima_mayorista'=> intval($producto['cantidad_minima_mayorista']),
                    'cantidad'                 => $cantidad,
                    'imagen'                   => $producto['imagen'],
                    'unidad_medida'            => $producto['unidad_medida'],
                ];
                $mensaje = "✅ Producto agregado al carrito";
                $tipo_mensaje = "success";
            }
        }
    }
}

$busqueda = $_GET['busqueda'] ?? '';
if (!empty($busqueda) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $cat_busqueda = isset($_GET['categoria']) && $_GET['categoria'] !== 'todas'
        ? intval($_GET['categoria']) : null;
    $stmt_hist = $conn->prepare("INSERT INTO historial_busquedas (id_comprador, termino_busqueda, id_categoria) VALUES (?, ?, ?)");
    if ($stmt_hist) {
        $stmt_hist->bind_param("isi", $usuario_id, $busqueda, $cat_busqueda);
        $stmt_hist->execute();
        $stmt_hist->close();
    }
}

$filtro_categoria   = $_GET['categoria'] ?? 'todas';
$filtro_productor   = $_GET['productor'] ?? 'todos';
$orden              = $_GET['orden'] ?? 'recientes';
$mostrar_agotados   = isset($_GET['mostrar_agotados']);
$producto_destacado = isset($_GET['producto']) ? intval($_GET['producto']) : null;

$result_categorias  = ejecutarConsulta($conn, "SELECT * FROM categorias_productos WHERE activa = 1 ORDER BY nombre_categoria");
$result_productores = ejecutarConsulta($conn,
    "SELECT DISTINCT u.id_usuario, u.nombre
     FROM usuarios u
     INNER JOIN productos p ON u.id_usuario = p.id_productor
     INNER JOIN categorias_productos c ON p.id_categoria = c.id_categoria
     WHERE u.rol = 'productor' AND u.estado = 'activo' AND c.activa = 1
     ORDER BY u.nombre");

$query_productos = "
    SELECT p.*, c.nombre_categoria, c.icono as icono_categoria,
           u.nombre as nombre_productor, u.telefono as telefono_productor,
           perf.foto_perfil,
           (SELECT AVG(calificacion) FROM calificaciones_resenas WHERE id_productor = p.id_productor AND estado = 'activa') as promedio_calificacion,
           (SELECT COUNT(*) FROM calificaciones_resenas WHERE id_productor = p.id_productor AND estado = 'activa') as total_resenas,
           GROUP_CONCAT(DISTINCT fp.ruta_foto ORDER BY fp.orden SEPARATOR '|') as fotos_producto
    FROM productos p
    INNER JOIN categorias_productos c ON p.id_categoria = c.id_categoria
    INNER JOIN usuarios u ON p.id_productor = u.id_usuario
    LEFT JOIN perfiles_productor perf ON u.id_usuario = perf.id_productor
    LEFT JOIN fotos_productos fp ON p.id_producto = fp.id_producto
    WHERE c.activa = 1 AND u.estado = 'activo'
    GROUP BY p.id_producto
    ORDER BY p.fecha_creacion DESC
";
$result_productos     = ejecutarConsulta($conn, $query_productos);
$productos_en_carrito = isset($_SESSION['carrito']) ? count($_SESSION['carrito']) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo - QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .producto-card {
            background: white; border-radius: var(--radius-lg); overflow: hidden;
            box-shadow: var(--shadow-sm); transition: var(--transition);
            height: 100%; display: flex; flex-direction: column; position: relative;
        }
        .producto-card:hover { box-shadow: var(--shadow-md); transform: translateY(-5px); }
        .producto-card.destacado { border: 3px solid var(--primary); box-shadow: 0 0 20px rgba(40,167,69,0.3); }
        .producto-card.agotado { opacity: 0.6; }
        .producto-card.agotado::after {
            content: 'AGOTADO'; position: absolute; top: 50%; left: 50%;
            transform: translate(-50%,-50%) rotate(-15deg);
            background: rgba(220,53,69,0.9); color: white;
            padding: 15px 40px; font-size: 24px; font-weight: 900;
            border-radius: var(--radius); z-index: 10; box-shadow: var(--shadow-lg);
        }

        .producto-imagenes { position: relative; width: 100%; height: 250px; background: var(--bg-light); overflow: hidden; }
        .carrusel-container { position: relative; width: 100%; height: 100%; }
        .carrusel-imagenes { display: flex; width: 100%; height: 100%; transition: transform 0.4s ease; }
        .carrusel-imagen { min-width: 100%; height: 100%; object-fit: cover; }
        .carrusel-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg,#f0f0f0,#e0e0e0); color: #999; }
        .carrusel-placeholder i { font-size: 64px; }
        .carrusel-nav {
            position: absolute; top: 50%; transform: translateY(-50%);
            background: rgba(0,0,0,0.5); color: white; border: none;
            width: 36px; height: 36px; border-radius: 50%; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: var(--transition); z-index: 5;
        }
        .carrusel-nav:hover { background: rgba(0,0,0,0.8); transform: translateY(-50%) scale(1.1); }
        .carrusel-nav.prev { left: 10px; }
        .carrusel-nav.next { right: 10px; }
        .carrusel-indicadores { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); display: flex; gap: 6px; z-index: 5; }
        .carrusel-indicador { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.5); cursor: pointer; transition: var(--transition); }
        .carrusel-indicador.active { background: white; width: 24px; border-radius: 4px; }
        
        .producto-body { padding: 20px; flex: 1; display: flex; flex-direction: column; }
        .producto-categoria { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--primary); background: var(--bg-light); padding: 5px 12px; border-radius: var(--radius-full); margin-bottom: 12px; width: fit-content; }
        .producto-titulo { font-size: 18px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
        .producto-productor { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #e0e0e0; }
        .productor-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); }
        .productor-nombre { font-size: 13px; color: var(--text-dark); font-weight: 600; }
        .productor-calificacion { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 5px; }
        
        .precios-bloque { background: #f8f9fa; border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; border: 1px solid #e9ecef; }
        .precio-detal-fila { display: flex; align-items: baseline; gap: 6px; margin-bottom: 4px; }
        .precio-detal-label { font-size: 11px; color: #6c757d; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; min-width: 55px; }
        .precio-detal-valor { font-size: 22px; font-weight: 800; color: var(--primary); line-height: 1; }
        .precio-unidad { font-size: 12px; font-weight: 400; color: #6c757d; }
        .precio-mayorista-fila { display: flex; align-items: center; gap: 6px; padding: 6px 8px; background: #e8f5e9; border-radius: 6px; margin-top: 6px; border-left: 3px solid #2e7d32; }
        .precio-mayorista-label { font-size: 11px; color: #2e7d32; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; min-width: 55px; }
        .precio-mayorista-valor { font-size: 16px; font-weight: 700; color: #2e7d32; }
        .precio-mayorista-condicion { font-size: 11px; color: #388e3c; margin-left: auto; }
        .precio-calculado-bloque { background: linear-gradient(135deg, #fff8e1, #fff3cd); border: 1px solid #ffc107; border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; display: none; }
        .precio-calculado-bloque.visible { display: flex; align-items: center; justify-content: space-between; }
        .precio-calculado-label { font-size: 12px; color: #856404; font-weight: 600; }
        .precio-calculado-valor { font-size: 18px; font-weight: 800; color: #664d03; }
        
        .producto-stock { font-size: 13px; margin-bottom: 12px; }
        .stock-disponible { color: var(--success); }
        .stock-bajo { color: var(--warning); font-weight: 600; }
        .producto-actions { margin-top: auto; display: flex; flex-direction: column; gap: 8px; }
        .cantidad-selector { display: flex; align-items: center; gap: 8px; }
        .cantidad-selector input { width: 65px; text-align: center; padding: 8px; border: 2px solid #dee2e6; border-radius: var(--radius); font-weight: 700; font-size: 15px; }
        .btn-cantidad { width: 34px; height: 34px; border: none; background: var(--primary); color: white; border-radius: var(--radius); cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: var(--transition); }
        .btn-cantidad:hover { background: var(--primary-dark); }
        .precio-hint { font-size: 11px; color: #6c757d; display: flex; align-items: center; gap: 4px; }
        .precio-hint.mayorista-activo { color: #2e7d32; font-weight: 600; }
        
        .carrito-flotante { position: fixed; bottom: 30px; right: 30px; z-index: 999; }
        .btn-carrito { position: relative; width: 60px; height: 60px; border-radius: 50%; background: var(--primary); color: white; border: none; box-shadow: var(--shadow-lg); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 24px; transition: var(--transition); text-decoration: none; }
        .btn-carrito:hover { background: var(--primary-dark); transform: scale(1.1); }
        .carrito-badge { position: absolute; top: -5px; right: -5px; background: var(--danger); color: white; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
        
        .productos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 25px; margin-top: 30px; }
        
        .autocomplete-list {
            position: fixed; background: #fff; border: 1px solid #d0e8d0; border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 9999; max-height: 320px; overflow-y: auto; display: none;
        }
        .autocomplete-list.open { display: block; }
        .ac-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; cursor: pointer; transition: background .15s; border-bottom: 1px solid #f5f5f5; }
        .ac-item:last-child { border-bottom: none; }
        .ac-item:hover, .ac-item.focused { background: #f0faf4; }
        .ac-icon { width: 32px; height: 32px; border-radius: 8px; background: #e8f5e9; color: #28a745; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
        .ac-texto { flex: 1; min-width: 0; }
        .ac-nombre { font-size: 14px; font-weight: 600; color: #222; }
        .ac-nombre mark { background: #c8f0c8; color: #1a5c1a; border-radius: 2px; padding: 0 2px; }
        .ac-cat { font-size: 11.5px; color: #888; margin-top: 1px; }
        .ac-empty { padding: 16px 14px; font-size: 13px; color: #aaa; text-align: center; }
        
        .filtros-linea { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .filtros-linea .filtro-busqueda { flex: 2; min-width: 200px; margin-bottom: 0; }
        .filtros-linea select.form-control { flex: 1; min-width: 230px; max-width: none !important; height: 48px; }

        @media (max-width: 768px) {
            .productos-grid { grid-template-columns: 1fr; }
            .carrito-flotante { bottom: 20px; right: 20px; }
            .btn-carrito { width: 55px; height: 55px; }
            .filtros-linea { flex-direction: column; }
            .filtros-linea .filtro-busqueda,
            .filtros-linea select.form-control { flex: none; width: 100%; }
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
            <a href="catalogo_comprador.php" class="active"><i class="bi bi-shop"></i> Catálogo</a>
            <a href="pedidos_comprador.php"><i class="bi bi-clipboard-check"></i> Mis Pedidos</a>
            <a href="productores_comprador.php"><i class="bi bi-people"></i> Productores</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Catálogo de Productos</h1>
                <p class="subtitle">Descubre productos frescos y locales</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
            </div>
        </header>

        <div class="content-area">
            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                    <i class="bi bi-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill"></i>
                    <?php echo $mensaje; ?>
                </div>
            <?php endif; ?>

            <div class="catalogo-container">
                <div class="filtros-linea">

                    <div class="filtro-busqueda">
                        <div class="filtro-busqueda-wrap">
                            <i class="bi bi-search"></i>
                            <input type="text" id="inputBusqueda" class="form-control"
                                   placeholder="Buscar productos..."
                                   autocomplete="off">
                        </div>
                    </div>

                    <select id="f_categoria" class="form-control" onchange="filtrarCards()">
                        <option value="todas">📁 Todas las Categorías</option>
                        <?php if ($result_categorias): while ($cat = $result_categorias->fetch_assoc()): ?>
                            <option value="<?php echo $cat['id_categoria']; ?>"
                                    data-icono="<?php echo htmlspecialchars($cat['icono'] ?? ''); ?>">
                                <?php echo htmlspecialchars($cat['nombre_categoria']); ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>

                    <select id="f_productor" class="form-control" onchange="filtrarCards()">
                        <option value="todos">👨‍🌾 Todos los Productores</option>
                        <?php if ($result_productores): while ($prod = $result_productores->fetch_assoc()): ?>
                            <option value="<?php echo $prod['id_usuario']; ?>">
                                <?php echo htmlspecialchars($prod['nombre']); ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>

                    <select id="f_orden" class="form-control" onchange="filtrarCards()">
                        <option value="recientes">🆕 Más Recientes</option>
                        <option value="precio_asc">💰 Menor Precio</option>
                        <option value="precio_desc">💎 Mayor Precio</option>
                        <option value="nombre">🔤 Alfabético (A-Z)</option>
                        <option value="popularidad">⭐ Más Populares</option>
                    </select>

                    <label style="display:flex;align-items:center;gap:8px;font-size:14px;white-space:nowrap;cursor:pointer;flex-shrink:0;">
                        <input type="checkbox" id="f_agotados" onchange="filtrarCards()"
                               style="width:18px;height:18px;accent-color:var(--primary);">
                        Mostrar agotados
                    </label>

                    <button class="btn btn-secondary" onclick="limpiarFiltros()" style="flex-shrink:0;">
                        <i class="bi bi-x-circle"></i> Limpiar
                    </button>
                </div>

                <div id="contadorProductos" style="margin-top:12px;font-size:14px;color:var(--text-muted);font-weight:600;display:none;">
                    <i class="bi bi-filter-circle" style="color:var(--primary);"></i>
                    Mostrando <strong id="numVisibles" style="color:var(--primary);">0</strong>
                    de <span id="numTotal">0</span> productos
                </div>
            </div>

            <div class="productos-grid">
                <?php if ($result_productos && $result_productos->num_rows > 0): ?>
                    <?php while ($producto = $result_productos->fetch_assoc()):
                        $imagenes = [];
                        if (!empty($producto['imagen'])) $imagenes[] = $producto['imagen'];
                        if (!empty($producto['fotos_producto'])) {
                            foreach (explode('|', $producto['fotos_producto']) as $foto) {
                                if ($foto && !in_array($foto, $imagenes)) $imagenes[] = $foto;
                            }
                        }
                        $total_imagenes  = count($imagenes);
                        $es_destacado    = ($producto_destacado && $producto['id_producto'] == $producto_destacado);
                        $tiene_mayorista = !empty($producto['precio_mayorista']) && $producto['precio_mayorista'] > 0;
                        $min_mayorista   = intval($producto['cantidad_minima_mayorista'] ?? 10);
                        $precio_detal    = floatval($producto['precio']);
                        $precio_mayorista= $tiene_mayorista ? floatval($producto['precio_mayorista']) : 0;
                        $id_p            = $producto['id_producto'];
                    ?>
                    <div class="producto-card <?php echo $producto['estado'] === 'agotado' ? 'agotado' : ''; ?> <?php echo $es_destacado ? 'destacado' : ''; ?>"
                         id="producto-<?php echo $id_p; ?>"
                         data-nombre="<?php echo strtolower(htmlspecialchars($producto['nombre'])); ?>"
                         data-categoria="<?php echo $producto['id_categoria']; ?>"
                         data-productor="<?php echo $producto['id_productor']; ?>"
                         data-precio="<?php echo $precio_detal; ?>"
                         data-estado="<?php echo $producto['estado']; ?>"
                         data-resenas="<?php echo intval($producto['total_resenas']); ?>"
                         data-calificacion="<?php echo floatval($producto['promedio_calificacion']); ?>">

                        <div class="producto-imagenes">
                            <?php if ($total_imagenes > 0): ?>
                                <div class="carrusel-container">
                                    <div class="carrusel-imagenes" data-current="0">
                                        <?php foreach ($imagenes as $imagen): ?>
                                            <img src="../../<?php echo htmlspecialchars($imagen); ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>" class="carrusel-imagen">
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($total_imagenes > 1): ?>
                                        <button class="carrusel-nav prev" onclick="cambiarImagen(this,-1)"><i class="bi bi-chevron-left"></i></button>
                                        <button class="carrusel-nav next" onclick="cambiarImagen(this,1)"><i class="bi bi-chevron-right"></i></button>
                                        <div class="carrusel-indicadores">
                                            <?php for ($i = 0; $i < $total_imagenes; $i++): ?>
                                                <div class="carrusel-indicador <?php echo $i === 0 ? 'active' : ''; ?>" onclick="irAImagen(this,<?php echo $i; ?>)"></div>
                                            <?php endfor; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="carrusel-placeholder"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                        </div>

                        <div class="producto-body">
                            <div class="producto-categoria">
                                <?php if ($producto['icono_categoria']): ?>
                                    <i class="<?php echo htmlspecialchars($producto['icono_categoria']); ?>"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($producto['nombre_categoria']); ?>
                            </div>
                            <h3 class="producto-titulo"><?php echo htmlspecialchars($producto['nombre']); ?></h3>
                            <div class="producto-productor">
                                <?php if ($producto['foto_perfil']): ?>
                                    <img src="../../<?php echo htmlspecialchars($producto['foto_perfil']); ?>" alt="" class="productor-avatar">
                                <?php else: ?>
                                    <div class="productor-avatar" style="background:var(--primary);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;">
                                        <?php echo strtoupper(substr($producto['nombre_productor'],0,1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div class="productor-nombre"><?php echo htmlspecialchars($producto['nombre_productor']); ?></div>
                                    <?php if ($producto['promedio_calificacion']): ?>
                                        <div class="productor-calificacion">
                                            <i class="bi bi-star-fill" style="color:#ffc107;"></i>
                                            <?php echo number_format($producto['promedio_calificacion'],1); ?>
                                            (<?php echo $producto['total_resenas']; ?> reseñas)
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($producto['descripcion']): ?>
                                <p style="font-size:14px;color:var(--text-muted);margin-bottom:12px;line-height:1.5;">
                                    <?php echo htmlspecialchars(substr($producto['descripcion'],0,100)) . (strlen($producto['descripcion']) > 100 ? '...' : ''); ?>
                                </p>
                            <?php endif; ?>

                            <div class="precios-bloque">
                                <div class="precio-detal-fila">
                                    <span class="precio-detal-label">Detal</span>
                                    <span class="precio-detal-valor" id="precioDetalValor_<?php echo $id_p; ?>">
                                        $<?php echo number_format($precio_detal, 0, ',', '.'); ?>
                                    </span>
                                    <span class="precio-unidad">/ <?php echo htmlspecialchars($producto['unidad_medida']); ?></span>
                                </div>
                                <?php if ($tiene_mayorista): ?>
                                <div class="precio-mayorista-fila">
                                    <span class="precio-mayorista-label">Mayor</span>
                                    <span class="precio-mayorista-valor">$<?php echo number_format($precio_mayorista, 0, ',', '.'); ?></span>
                                    <span class="precio-mayorista-condicion">desde <?php echo $min_mayorista; ?> <?php echo htmlspecialchars($producto['unidad_medida']);  ?>s</span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="precio-calculado-bloque" id="precioCalculado_<?php echo $id_p; ?>">
                                <div>
                                    <div class="precio-calculado-label">Total estimado <span id="badgeActivo_<?php echo $id_p; ?>"></span></div>
                                    <div style="font-size:11px;color:#856404;" id="cantidadInfo_<?php echo $id_p; ?>"></div>
                                </div>
                                <div class="precio-calculado-valor" id="precioCalculadoValor_<?php echo $id_p; ?>">$0</div>
                            </div>

                            <div class="producto-stock">
                                <?php if ($producto['estado'] === 'agotado'): ?>
                                    <span style="color:var(--danger);font-weight:600;"><i class="bi bi-x-circle-fill"></i> Producto agotado</span>
                                <?php elseif ($producto['estado'] === 'stock_bajo'): ?>
                                    <span class="stock-bajo"><i class="bi bi-exclamation-triangle-fill"></i> ¡Últimas <?php echo $producto['cantidad']; ?> unidades!</span>
                                <?php else: ?>
                                    <span class="stock-disponible"><i class="bi bi-check-circle-fill"></i> <?php echo $producto['cantidad']; ?> <?php echo htmlspecialchars($producto['unidad_medida']); ?>s disponibles</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($producto['estado'] !== 'agotado'): ?>
                            <form method="POST" class="producto-actions">
                                <input type="hidden" name="accion" value="agregar_carrito">
                                <input type="hidden" name="id_producto" value="<?php echo $id_p; ?>">
                                <input type="hidden" name="precio_aplicado" id="precioAplicado_<?php echo $id_p; ?>" value="<?php echo $precio_detal; ?>">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                                    <span style="font-size:12px;font-weight:600;color:var(--text-muted);">Cantidad:</span>
                                    <span class="precio-hint" id="hintPrecio_<?php echo $id_p; ?>">
                                        <i class="bi bi-tag"></i> Precio detal activo
                                    </span>
                                </div>
                                <div class="cantidad-selector">
                                    <button type="button" class="btn-cantidad" onclick="restarCantidad(this)"><i class="bi bi-dash"></i></button>
                                    <input type="number" name="cantidad"
                                           id="cantidad_<?php echo $id_p; ?>"
                                           value="1" min="1" max="<?php echo $producto['cantidad']; ?>"
                                           data-producto-id="<?php echo $id_p; ?>"
                                           data-precio-detal="<?php echo $precio_detal; ?>"
                                           data-precio-mayorista="<?php echo $precio_mayorista; ?>"
                                           data-min-mayorista="<?php echo $tiene_mayorista ? $min_mayorista : 99999; ?>"
                                           data-tiene-mayorista="<?php echo $tiene_mayorista ? '1' : '0'; ?>"
                                           data-unidad="<?php echo htmlspecialchars($producto['unidad_medida']); ?>"
                                           onchange="actualizarPrecio(this)" required>
                                    <button type="button" class="btn-cantidad" onclick="sumarCantidad(this)"><i class="bi bi-plus"></i></button>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width:100%;">
                                    <i class="bi bi-cart-plus"></i> Agregar al Carrito
                                </button>
                            </form>
                            <a href="https://wa.me/57<?php echo preg_replace('/[^0-9]/','', $producto['telefono_productor']); ?>?text=<?php echo urlencode('Hola, me interesa el producto: ' . $producto['nombre']); ?>"
                               target="_blank" class="btn"
                               style="width:100%;background:#25D366;color:white;border:none;display:flex;align-items:center;justify-content:center;gap:6px;margin-top:4px;">
                                <i class="bi bi-whatsapp"></i> Contactar por WhatsApp
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="grid-column:1/-1;text-align:center;padding:80px 20px;">
                        <i class="bi bi-inbox" style="font-size:100px;color:#e0e0e0;margin-bottom:20px;display:block;"></i>
                        <h3 style="font-size:28px;margin-bottom:15px;">No hay productos disponibles</h3>
                        <p style="font-size:16px;color:var(--text-muted);">Aún no hay productos en el catálogo.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<div class="autocomplete-list" id="acList"></div>

<?php if ($productos_en_carrito > 0): ?>
<div class="carrito-flotante">
    <a href="checkout_carrito.php" class="btn-carrito" title="Ver carrito">
        <i class="bi bi-cart3"></i>
        <span class="carrito-badge"><?php echo $productos_en_carrito; ?></span>
    </a>
</div>
<?php endif; ?>

<script>
function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }

function filtrarCards() {
    const q       = (document.getElementById('inputBusqueda')?.value || '').toLowerCase().trim();
    const catVal  = document.getElementById('f_categoria')?.value  || 'todas';
    const prodVal = document.getElementById('f_productor')?.value  || 'todos';
    const orden   = document.getElementById('f_orden')?.value      || 'recientes';
    const verAgot = document.getElementById('f_agotados')?.checked || false;

    const cards = [...document.querySelectorAll('.producto-card')];
    let visibles = 0;

    if (catVal !== 'todas' || q) {
    const termino = q || '';
    const idCat   = catVal !== 'todas' ? catVal : '';
    fetch(`catalogo_comprador.php?action=guardar_busqueda&termino=${encodeURIComponent(termino)}&id_categoria=${idCat}`)
        .catch(() => {});
    }

    cards.forEach(card => {
        const nombre    = card.dataset.nombre    || '';
        const categoria = card.dataset.categoria || '';
        const productor = card.dataset.productor || '';
        const estado    = card.dataset.estado    || '';
        const prodTxt   = card.querySelector('.productor-nombre')?.textContent.toLowerCase() || '';
        const catTxt    = card.querySelector('.producto-categoria')?.textContent.toLowerCase() || '';

        const okBusq = !q || nombre.includes(q);
        const okCat  = catVal  === 'todas' || categoria === catVal;
        const okProd = prodVal === 'todos' || productor === prodVal;
        const okAgot = verAgot || estado !== 'agotado';

        const mostrar = okBusq && okCat && okProd && okAgot;
        card.style.display = mostrar ? '' : 'none';
        if (mostrar) visibles++;
    });

    const grid = document.querySelector('.productos-grid');
    if (grid && orden !== 'recientes') {
        const visArr = cards.filter(c => c.style.display !== 'none');
        visArr.sort((a, b) => {
            const pA = parseFloat(a.dataset.precio), pB = parseFloat(b.dataset.precio);
            const nA = a.dataset.nombre,              nB = b.dataset.nombre;
            const rA = parseFloat(a.dataset.calificacion||0) * parseInt(a.dataset.resenas||0);
            const rB = parseFloat(b.dataset.calificacion||0) * parseInt(b.dataset.resenas||0);
            if (orden === 'precio_asc')  return pA - pB;
            if (orden === 'precio_desc') return pB - pA;
            if (orden === 'nombre')      return nA.localeCompare(nB);
            if (orden === 'popularidad') return rB - rA;
            return 0;
        });
        visArr.forEach(c => grid.appendChild(c));
    }

    const total     = cards.length;
    const hasFiltro = q || catVal !== 'todas' || prodVal !== 'todos' || verAgot;
    const contador  = document.getElementById('contadorProductos');
    document.getElementById('numVisibles').textContent = visibles;
    document.getElementById('numTotal').textContent    = total;
    contador.style.display = (hasFiltro && visibles < total) ? 'block' : 'none';

    let aviso = document.getElementById('sinResultadosLive');
    if (!aviso) {
        aviso = document.createElement('div');
        aviso.id = 'sinResultadosLive';
        aviso.style.cssText = 'grid-column:1/-1;text-align:center;padding:80px 20px;display:none;';
        aviso.innerHTML = `
            <i class="bi bi-inbox" style="font-size:100px;color:#e0e0e0;display:block;margin-bottom:20px;"></i>
            <h3 style="font-size:28px;margin-bottom:15px;">No hay productos que coincidan</h3>
            <p style="font-size:16px;color:var(--text-muted);">Intenta ajustar los filtros.</p>
            <button class="btn btn-primary" style="margin-top:15px;" onclick="limpiarFiltros()">
                <i class="bi bi-x-circle"></i> Limpiar filtros
            </button>`;
        document.querySelector('.productos-grid').appendChild(aviso);
    }
    aviso.style.display = (visibles === 0) ? 'block' : 'none';
}

function limpiarFiltros() {
    document.getElementById('inputBusqueda').value = '';
    document.getElementById('f_categoria').value  = 'todas';
    document.getElementById('f_productor').value  = 'todos';
    document.getElementById('f_orden').value      = 'recientes';
    document.getElementById('f_agotados').checked = false;
    cerrarAC();
    filtrarCards();
}

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const productorParam = params.get('productor');
    if (productorParam) {
        const sel = document.getElementById('f_productor');
        if (sel) sel.value = productorParam;
    }

    filtrarCards();

    if (productorParam) {
        document.querySelector('.productos-grid')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});

(function () {
    const input = document.getElementById('inputBusqueda');
    const list  = document.getElementById('acList');
    let timer = null, focusIdx = -1, items = [];

    function highlight(text, query) {
        if (!query) return text;
        const re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return text.replace(re, '<mark>$1</mark>');
    }
    function posicionarLista() {
        const r = input.getBoundingClientRect();
        list.style.top   = (r.bottom + window.scrollY + 4) + 'px';
        list.style.left  = (r.left + window.scrollX) + 'px';
        list.style.width = r.width + 'px';
    }
    function render(sugerencias, query) {
        list.innerHTML = ''; focusIdx = -1; posicionarLista();
        if (!sugerencias.length) {
            list.innerHTML = `<div class="ac-empty">Sin resultados para "<strong>${query}</strong>"</div>`;
            list.classList.add('open'); return;
        }
        sugerencias.forEach(s => {
            const el = document.createElement('div');
            el.className = 'ac-item';
            el.dataset.nombre = s.nombre;
            el.innerHTML = `
                <div class="ac-icon"><i class="${s.icono}"></i></div>
                <div class="ac-texto">
                    <div class="ac-nombre">${highlight(s.nombre, query)}</div>
                    <div class="ac-cat">${s.categoria}</div>
                </div>`;
            el.addEventListener('mousedown', e => {
                e.preventDefault();
                input.value = s.nombre;
                cerrarAC();
                filtrarCards();
            });
            list.appendChild(el);
        });
        items = list.querySelectorAll('.ac-item');
        list.classList.add('open');
    }
    window.cerrarAC = function() { list.classList.remove('open'); list.innerHTML = ''; focusIdx = -1; items = []; };

   input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim().toLowerCase();
        filtrarCards();
        if (!q) { cerrarAC(); return; }

        clearTimeout(window._timerHistorial);
        window._timerHistorial = setTimeout(() => {
            fetch(`catalogo_comprador.php?action=guardar_busqueda&termino=${encodeURIComponent(q)}&id_categoria=todas`)
                .catch(() => {});
        }, 800);

        timer = setTimeout(() => {
            fetch(`catalogo_comprador.php?ac=1&q=${encodeURIComponent(q)}`)
                .then(r => r.json()).then(data => render(data, q)).catch(() => cerrarAC());
        }, 250);
    });

    input.addEventListener('keydown', e => {
        if (!list.classList.contains('open')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); items[focusIdx]?.classList.remove('focused'); focusIdx = (focusIdx+1)%items.length; items[focusIdx]?.classList.add('focused'); items[focusIdx]?.scrollIntoView({block:'nearest'}); }
        if (e.key === 'ArrowUp')   { e.preventDefault(); items[focusIdx]?.classList.remove('focused'); focusIdx = (focusIdx-1+items.length)%items.length; items[focusIdx]?.classList.add('focused'); items[focusIdx]?.scrollIntoView({block:'nearest'}); }
        if (e.key === 'Enter' && focusIdx >= 0) { e.preventDefault(); input.value = items[focusIdx].dataset.nombre; cerrarAC(); filtrarCards(); }
        if (e.key === 'Escape') cerrarAC();
    });
    document.addEventListener('click', e => { if (!input.contains(e.target) && !list.contains(e.target)) cerrarAC(); });
    window.addEventListener('scroll', () => { if (list.classList.contains('open')) posicionarLista(); }, { passive: true });
    window.addEventListener('resize', () => { if (list.classList.contains('open')) posicionarLista(); });
})();

function sumarCantidad(btn) {
    const input = btn.parentElement.querySelector('input[type="number"]');
    if (parseInt(input.value) < parseInt(input.max)) { input.value = parseInt(input.value)+1; actualizarPrecio(input); }
}
function restarCantidad(btn) {
    const input = btn.parentElement.querySelector('input[type="number"]');
    if (parseInt(input.value) > 1) { input.value = parseInt(input.value)-1; actualizarPrecio(input); }
}

function actualizarPrecio(input) {
    const id = input.dataset.productoId;
    const cantidad = parseInt(input.value) || 1;
    const precioD = parseFloat(input.dataset.precioDetal);
    const precioM = parseFloat(input.dataset.precioMayorista);
    const minM    = parseInt(input.dataset.minMayorista);
    const tieneM  = input.dataset.tieneMayorista === '1';
    const unidad  = input.dataset.unidad;
    const esM     = tieneM && cantidad >= minM;
    const precio  = esM ? precioM : precioD;
    const total   = precio * cantidad;

    const campo = document.getElementById('precioAplicado_' + id);
    if (campo) campo.value = precio;

    const hint = document.getElementById('hintPrecio_' + id);
    if (hint) {
        if (esM) {
            hint.innerHTML = '<i class="bi bi-truck"></i> <strong style="color:#2e7d32;">Precio mayorista activo</strong>';
            hint.className = 'precio-hint mayorista-activo';
        } else if (tieneM) {
            hint.innerHTML = `<i class="bi bi-tag"></i> Detal · <span style="color:#2e7d32;">Agrega ${minM - cantidad} más para mayorista</span>`;
            hint.className = 'precio-hint';
        } else {
            hint.innerHTML = '<i class="bi bi-tag"></i> Precio detal activo';
            hint.className = 'precio-hint';
        }
    }

    const bloque = document.getElementById('precioCalculado_' + id);
    const val    = document.getElementById('precioCalculadoValor_' + id);
    const badge  = document.getElementById('badgeActivo_' + id);
    const info   = document.getElementById('cantidadInfo_' + id);
    if (bloque && val) {
        bloque.classList.toggle('visible', !(cantidad === 1 && !esM));
        val.textContent = '$' + Math.round(total).toLocaleString('es-CO');
        if (badge) badge.innerHTML = esM
            ? ' · <span style="background:#2e7d32;color:white;padding:1px 6px;border-radius:10px;font-size:10px;">MAYORISTA</span>'
            : ' · <span style="background:var(--primary);color:white;padding:1px 6px;border-radius:10px;font-size:10px;">DETAL</span>';
        if (info) info.textContent = `${cantidad} ${unidad}${cantidad > 1 ? 's' : ''} × $${Math.round(precio).toLocaleString('es-CO')}`;
    }
}

function cambiarImagen(btn, dir) {
    const cont = btn.closest('.carrusel-container');
    const c    = cont.querySelector('.carrusel-imagenes');
    const tot  = c.querySelectorAll('.carrusel-imagen').length;
    const inds = cont.querySelectorAll('.carrusel-indicador');
    let idx = (parseInt(c.dataset.current) + dir + tot) % tot;
    c.style.transform = `translateX(-${idx * 100}%)`;
    c.dataset.current = idx;
    inds.forEach((d, i) => d.classList.toggle('active', i === idx));
}
function irAImagen(ind, idx) {
    const cont = ind.closest('.carrusel-container');
    const c    = cont.querySelector('.carrusel-imagenes');
    c.style.transform = `translateX(-${idx * 100}%)`;
    c.dataset.current = idx;
    cont.querySelectorAll('.carrusel-indicador').forEach((d, i) => d.classList.toggle('active', i === idx));
}
setInterval(() => {
    document.querySelectorAll('.carrusel-nav.next').forEach(btn => {
        if (!btn.closest('.producto-card:hover')) cambiarImagen(btn, 1);
    });
}, 5000);

<?php if ($producto_destacado): ?>
document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('producto-<?php echo $producto_destacado; ?>');
    if (el) {
        setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 500);
        setTimeout(() => el.classList.remove('destacado'), 5000);
    }
});
<?php endif; ?>

document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar');
    const mt = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && !sb.contains(e.target) && !mt.contains(e.target) && sb.classList.contains('open'))
        sb.classList.remove('open');
});
</script>
</body>
</html>