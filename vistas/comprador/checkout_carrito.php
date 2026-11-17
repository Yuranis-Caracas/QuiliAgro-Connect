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
function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param($tipos, ...$params);
            $stmt->execute();
            return (stripos(trim($query), 'select') === 0) ? $stmt->get_result() : $stmt;
        }
        $result = $conn->query($query);
        if (!$result) throw new Exception($conn->error);
        return $result;
    } catch (Exception $e) {
        error_log('Error BD: ' . $e->getMessage());
        return false;
    }
}

if (!empty($_GET['reintento'])) {
    $id_orden_reintento = intval($_GET['reintento']);

    $r_ord = ejecutarConsulta($conn,
        "SELECT id_orden FROM ordenes 
         WHERE id_orden = ? AND id_comprador = ? AND estado = 'expirado'",
        "ii", [$id_orden_reintento, $usuario_id]);

    if (!$r_ord || $r_ord->num_rows === 0) {
        $_SESSION['mensaje_temporal']      = "La orden no es válida para reintentar.";
        $_SESSION['tipo_mensaje_temporal'] = "warning";
        header("Location: pedidos_comprador.php"); exit();
    }

    $r_activa = ejecutarConsulta($conn,
        "SELECT id_orden FROM ordenes
         WHERE id_comprador = ? AND estado != 'expirado'
           AND fecha > (SELECT fecha FROM ordenes WHERE id_orden = ?)
         LIMIT 1",
        "ii", [$usuario_id, $id_orden_reintento]);

    if ($r_activa && $r_activa->num_rows > 0) {
        $_SESSION['mensaje_temporal']      = "Ya tienes un pedido activo con estos productos.";
        $_SESSION['tipo_mensaje_temporal'] = "warning";
        header("Location: pedidos_comprador.php"); exit();
    }

    $nueva_expiracion = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    ejecutarConsulta($conn,
        "UPDATE ordenes SET estado = 'pendiente', fecha_expiracion = ?
         WHERE id_orden = ?",
        "si", [$nueva_expiracion, $id_orden_reintento]);

   
    $_SESSION['orden_reintento'] = $id_orden_reintento;
    header("Location: pasarela_pago.php?reintento=$id_orden_reintento"); exit();
}

if (empty($_SESSION['carrito'])) {
    $_SESSION['mensaje_temporal']      = "Tu carrito está vacío";
    $_SESSION['tipo_mensaje_temporal'] = "warning";
    header("Location: catalogo_comprador.php");
    exit();
}

$items_sin_stock = [];
foreach ($_SESSION['carrito'] as &$item) {
    $r = ejecutarConsulta($conn,
        "SELECT cantidad, nombre, estado FROM productos WHERE id_producto = ?",
        "i", [$item['id_producto']]);
    if ($r) {
        $prod = $r->fetch_assoc();
        if (!$prod || $prod['estado'] === 'agotado' || $prod['cantidad'] <= 0) {
            $items_sin_stock[] = $item['nombre'] . ' (agotado)';
            $item['cantidad']  = 0;
        } elseif ($item['cantidad'] > $prod['cantidad']) {
            $items_sin_stock[] = $item['nombre'] . ' (solo ' . $prod['cantidad'] . ' disponibles, se ajustó tu cantidad)';
            $item['cantidad']  = $prod['cantidad'];
        }
    }
}
unset($item);

$_SESSION['carrito'] = array_values(array_filter(
    $_SESSION['carrito'],
    fn($item) => $item['cantidad'] > 0
));

if (!empty($items_sin_stock)) {
    $_SESSION['mensaje_temporal']      = "⚠️ Algunos productos fueron ajustados: " . implode(', ', $items_sin_stock);
    $_SESSION['tipo_mensaje_temporal'] = "warning";
}

if (empty($_SESSION['carrito'])) {
    $_SESSION['mensaje_temporal']      = "Los productos de tu orden ya no están disponibles.";
    $_SESSION['tipo_mensaje_temporal'] = "warning";
    header("Location: catalogo_comprador.php"); exit();
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vaciar_carrito'])) {
    $_SESSION['carrito'] = [];
    $_SESSION['mensaje_temporal'] = "Carrito vaciado correctamente";
    $_SESSION['tipo_mensaje_temporal'] = "success";
    header("Location: catalogo_comprador.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_producto'])) {
    $id_producto = intval($_POST['id_producto']);
    $_SESSION['carrito'] = array_values(array_filter(
        $_SESSION['carrito'],
        fn($item) => $item['id_producto'] != $id_producto
    ));
    $_SESSION['mensaje_temporal'] = "Producto eliminado del carrito";
    $_SESSION['tipo_mensaje_temporal'] = "success";
    if (empty($_SESSION['carrito'])) {
        header("Location: catalogo_comprador.php");
        exit();
    }
    header("Location: checkout_carrito.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_cantidad'])) {
    $id_producto    = intval($_POST['id_producto']);
    $nueva_cantidad = max(1, intval($_POST['cantidad']));

    $r = ejecutarConsulta($conn, "SELECT cantidad FROM productos WHERE id_producto = ?", "i", [$id_producto]);
    if ($r) {
        $prod = $r->fetch_assoc();
        if ($nueva_cantidad > $prod['cantidad']) {
            $_SESSION['mensaje_temporal'] = "Solo hay {$prod['cantidad']} unidades disponibles";
            $_SESSION['tipo_mensaje_temporal'] = "warning";
        } else {
            foreach ($_SESSION['carrito'] as &$item) {
                if ($item['id_producto'] == $id_producto) {
                    $item['cantidad'] = $nueva_cantidad;
                    break;
                }
            }
            unset($item);
            $_SESSION['mensaje_temporal'] = "Cantidad actualizada";
            $_SESSION['tipo_mensaje_temporal'] = "success";
        }
    }
    header("Location: checkout_carrito.php");
    exit();
}


$result_metodos = ejecutarConsulta($conn, "SELECT * FROM metodos_pago WHERE activo = 1 ORDER BY nombre_metodo");

$total_carrito = 0;
$items_carrito = count($_SESSION['carrito']);
foreach ($_SESSION['carrito'] as $item) {
    $total_carrito += $item['precio'] * $item['cantidad'];
}
define('PEDIDO_MINIMO', 80000);
$bajo_minimo   = $total_carrito < PEDIDO_MINIMO;
$faltante      = PEDIDO_MINIMO - $total_carrito;

$fotos_por_producto = [];
if (!empty($_SESSION['carrito'])) {
    $ids          = array_map(fn($i) => intval($i['id_producto']), $_SESSION['carrito']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tipos_ids    = str_repeat('i', count($ids));

    $r_fotos = ejecutarConsulta($conn,
        "SELECT fp.id_producto, fp.ruta_foto
         FROM fotos_productos fp
         INNER JOIN (
             SELECT id_producto, MIN(id_foto) AS min_id
             FROM fotos_productos
             WHERE id_producto IN ($placeholders) AND orden = 0
             GROUP BY id_producto
         ) principal ON fp.id_foto = principal.min_id",
        $tipos_ids, $ids
    );
    if ($r_fotos) {
        while ($f = $r_fotos->fetch_assoc()) {
            $fotos_por_producto[$f['id_producto']] = $f['ruta_foto'];
        }
    }

    $sin_foto = array_values(array_filter($ids, fn($id) => !isset($fotos_por_producto[$id])));
    if (!empty($sin_foto)) {
        $ph2 = implode(',', array_fill(0, count($sin_foto), '?'));
        $tp2 = str_repeat('i', count($sin_foto));
        $r2  = ejecutarConsulta($conn,
            "SELECT id_producto, MIN(ruta_foto) AS ruta_foto
             FROM fotos_productos
             WHERE id_producto IN ($ph2)
             GROUP BY id_producto",
            $tp2, $sin_foto
        );
        if ($r2) {
            while ($f = $r2->fetch_assoc()) {
                $fotos_por_producto[$f['id_producto']] = $f['ruta_foto'];
            }
        }
    }
}

$ids_productores     = array_unique(array_map(fn($i) => intval($i['id_productor']), $_SESSION['carrito']));
$nombres_productores = [];
if (!empty($ids_productores)) {
    $ph3 = implode(',', array_fill(0, count($ids_productores), '?'));
    $tp3 = str_repeat('i', count($ids_productores));
    $r3  = ejecutarConsulta($conn, "SELECT id_usuario, nombre FROM usuarios WHERE id_usuario IN ($ph3)", $tp3, $ids_productores);
    if ($r3) {
        while ($u = $r3->fetch_assoc()) {
            $nombres_productores[$u['id_usuario']] = $u['nombre'];
        }
    }
}

$stock_por_producto = [];
if (!empty($_SESSION['carrito'])) {
    $ids2 = array_map(fn($i) => intval($i['id_producto']), $_SESSION['carrito']);
    $ph4  = implode(',', array_fill(0, count($ids2), '?'));
    $tp4  = str_repeat('i', count($ids2));
    $r4   = ejecutarConsulta($conn, "SELECT id_producto, cantidad FROM productos WHERE id_producto IN ($ph4)", $tp4, $ids2);
    if ($r4) {
        while ($s = $r4->fetch_assoc()) {
            $stock_por_producto[$s['id_producto']] = intval($s['cantidad']);
        }
    }
}

$mensaje = $tipo_mensaje = '';
if (isset($_SESSION['mensaje_temporal'])) {
    $mensaje      = $_SESSION['mensaje_temporal'];
    $tipo_mensaje = $_SESSION['tipo_mensaje_temporal'];
    unset($_SESSION['mensaje_temporal'], $_SESSION['tipo_mensaje_temporal']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carrito de Compras - QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        .checkout-container { max-width: 1400px; margin: 0 auto; padding: 30px; }
        .checkout-grid      { display: grid; grid-template-columns: 1fr 400px; gap: 30px; margin-top: 30px; }
        .checkout-section   { background: white; border-radius: var(--radius-lg); padding: 30px; box-shadow: var(--shadow-sm); }
        .section-title      { color: var(--primary); margin-bottom: 25px; font-size: 22px; display: flex; align-items: center; gap: 10px; padding-bottom: 15px; border-bottom: 2px solid var(--bg-light); }

        .cart-item           { display: grid; grid-template-columns: 90px 1fr auto; gap: 20px; padding: 20px; background: #f8f9fa; border-radius: var(--radius); margin-bottom: 15px; align-items: center; }
        .cart-item-img       { width: 90px; height: 90px; object-fit: cover; border-radius: var(--radius-sm); border: 2px solid white; box-shadow: var(--shadow-sm); background: #e9ecef; }
        .cart-item-no-foto   { display: flex; align-items: center; justify-content: center; object-fit: unset; }
        .cart-item-info h4   { margin: 0 0 6px 0; font-size: 16px; color: var(--text-dark); }
        .cart-item-price     { font-size: 18px; font-weight: 700; color: var(--primary); margin: 6px 0; }
        .quantity-controls   { display: flex; align-items: center; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        .qty-btn             { width: 32px; height: 32px; border: none; background: white; border-radius: var(--radius-sm); cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center; transition: var(--transition); box-shadow: var(--shadow-sm); flex-shrink: 0; }
        .qty-btn:hover       { background: var(--primary); color: white; }
        .qty-input           { width: 55px; text-align: center; padding: 6px; border: 2px solid #dee2e6; border-radius: var(--radius-sm); font-weight: 600; }
        .btn-update          { padding: 6px 14px; background: var(--primary); color: white; border: none; border-radius: var(--radius-sm); cursor: pointer; font-size: 13px; transition: var(--transition); }
        .btn-update:hover    { background: var(--primary-dark); }
        .cart-item-actions   { display: flex; flex-direction: column; gap: 10px; align-items: flex-end; }
        .subtotal-item       { text-align: right; font-weight: 700; font-size: 20px; color: var(--primary); }
        .btn-delete          { padding: 7px 13px; background: transparent; color: #dc3545; border: 2px solid #dc3545; border-radius: var(--radius); cursor: pointer; font-size: 13px; transition: var(--transition); display: flex; align-items: center; gap: 5px; }
        .btn-delete:hover    { background: #dc3545; color: white; }

        .summary-row         { display: flex; justify-content: space-between; align-items: center; padding: 13px 0; border-bottom: 1px solid #e0e0e0; }
        .summary-row.total   { border-bottom: none; font-size: 22px; font-weight: 800; color: var(--primary); margin-top: 8px; }

        .payment-method-card          { border: 2px solid #dee2e6; border-radius: var(--radius); padding: 18px; margin-bottom: 12px; cursor: pointer; transition: var(--transition); display: flex; align-items: center; gap: 15px; }
        .payment-method-card:hover    { border-color: var(--primary); background: #f8f9fa; }
        .payment-method-card input[type="radio"] { width: 20px; height: 20px; cursor: pointer; accent-color: var(--primary); flex-shrink: 0; }
        .payment-method-card.selected { border-color: var(--primary); background: #e7f5e9; box-shadow: 0 0 0 3px rgba(46,125,50,0.1); }
        .payment-icon                 { font-size: 28px; color: var(--primary); }

        .btn-proceed       { width: 100%; padding: 16px; font-size: 17px; font-weight: 700; background: var(--primary); color: white; border: none; border-radius: var(--radius); cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 18px; }
        .btn-proceed:hover { background: var(--primary-dark); transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-proceed:disabled { opacity: .45; cursor: not-allowed; transform: none !important; box-shadow: none !important; }

        .btn-vaciar       { padding: 9px 18px; background: transparent; color: #dc3545; border: 2px solid #dc3545; border-radius: var(--radius); cursor: pointer; font-size: 14px; font-weight: 600; transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; }
        .btn-vaciar:hover { background: #dc3545; color: white; }

        #toast { position: fixed; top: 24px; right: 24px; z-index: 9999; min-width: 260px; max-width: 380px; padding: 14px 18px; border-radius: 10px; color: white; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 10px; box-shadow: 0 6px 24px rgba(0,0,0,0.18); transform: translateX(calc(100% + 30px)); transition: transform 0.35s cubic-bezier(.4,0,.2,1), opacity 0.35s ease; opacity: 0; pointer-events: none; line-height: 1.4; }
        #toast.visible      { transform: translateX(0); opacity: 1; pointer-events: auto; }
        #toast.success      { background: #2e7d32; }
        #toast.warning      { background: #e65100; }
        #toast.danger       { background: #c62828; }
        #toast .toast-icon  { font-size: 18px; flex-shrink: 0; }
        #toast .toast-close { margin-left: auto; background: none; border: none; color: rgba(255,255,255,0.8); cursor: pointer; font-size: 18px; padding: 0 0 0 8px; line-height: 1; flex-shrink: 0; }
        #toast .toast-close:hover { color: white; }

        .modal-overlay          { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 10000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; }
        .modal-overlay.visible  { opacity: 1; pointer-events: auto; }
        .modal-box              { background: white; border-radius: 16px; padding: 32px; max-width: 420px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.2); transform: scale(0.92); transition: transform 0.25s ease; }
        .modal-overlay.visible .modal-box { transform: scale(1); }
        .modal-icon             { font-size: 48px; text-align: center; margin-bottom: 16px; }
        .modal-title            { font-size: 20px; font-weight: 700; color: var(--text-dark); text-align: center; margin-bottom: 8px; }
        .modal-msg              { font-size: 15px; color: var(--text-muted); text-align: center; line-height: 1.5; margin-bottom: 24px; }
        .modal-btns             { display: flex; gap: 12px; }
        .modal-btn              { flex: 1; padding: 12px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: var(--transition); }
        .modal-btn.cancel       { background: #f1f3f5; color: #495057; }
        .modal-btn.cancel:hover { background: #dee2e6; }
        .modal-btn.confirm      { background: #dc3545; color: white; }
        .modal-btn.confirm:hover { background: #c82333; }

        .stock-warning         { background: #fff3cd; border: 1px solid #ffc107; color: #664d03; border-radius: 8px; padding: 10px 14px; font-size: 13px; font-weight: 500; display: none; align-items: center; gap: 8px; margin-top: 8px; }
        .stock-warning.visible { display: flex; }

        @media (max-width: 480px) {
            .checkout-grid { grid-template-columns: 1fr; }
            .cart-item {grid-template-columns: 70px 1fr; }
            .cart-item-actions { grid-column: 1 / -1; flex-direction: row; justify-content: space-between; align-items: center; }
        }

        @media (max-width: 968px) {
    .checkout-grid { grid-template-columns: 1fr; }

    .cart-item {
        grid-template-columns: 85px 1fr;
        grid-template-rows: auto auto;
        gap: 12px 15px;
    }

    .cart-item-img,
    .cart-item-no-foto {
        grid-column: 1;
        grid-row: 1;
        width: 85px;
        height: 85px;
    }

}

    </style>
</head>
<body>

<div id="toast">
    <i class="bi toast-icon" id="toastIcon"></i>
    <span id="toastMsg"></span>
    <button class="toast-close" onclick="ocultarToast()"><i class="bi bi-x"></i></button>
</div>

<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
        <div class="modal-icon"  id="modalIcon">⚠️</div>
        <div class="modal-title" id="modalTitle">¿Estás seguro?</div>
        <div class="modal-msg"   id="modalMsg"></div>
        <div class="modal-btns">
            <button class="modal-btn cancel"  onclick="cerrarModal()">Cancelar</button>
            <button class="modal-btn confirm" id="modalConfirmBtn" onclick="ejecutarAccionModal()">Confirmar</button>
        </div>
    </div>
</div>

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
            <a href="productores_comprador.php"><i class="bi bi-people"></i> Productores</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1>Carrito de Compras</h1>
                <p class="subtitle">Revisa tu pedido antes de continuar</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
            </div>
        </header>

        <div class="content-area">
            <div class="checkout-container">

                <?php if ($mensaje): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        mostrarToast(
                            <?php echo json_encode($mensaje); ?>,
                            '<?php echo $tipo_mensaje === 'success' ? 'success' : ($tipo_mensaje === 'danger' ? 'danger' : 'warning'); ?>'
                        );
                    });
                </script>
                <?php endif; ?>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
                    <a href="catalogo_comprador.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:8px;">
                        <i class="bi bi-arrow-left"></i> Seguir Comprando
                    </a>
                    <button type="button" class="btn-vaciar"
                        onclick="abrirModal('🗑️','Vaciar carrito','Se eliminarán todos los productos. Esta acción no se puede deshacer.','vaciar')">
                        <i class="bi bi-trash3"></i> Vaciar carrito
                    </button>
                </div>

                <form method="POST" action="pasarela_pago.php" id="formCheckout">
                <div class="checkout-grid">

                    <div class="checkout-section">
                        <h2 class="section-title">
                            <i class="bi bi-cart-check"></i>
                            Productos en tu Carrito (<?php echo $items_carrito; ?>)
                        </h2>

                        <?php foreach ($_SESSION['carrito'] as $item):
                            $id_prod          = $item['id_producto'];
                            $stock_disponible = $stock_por_producto[$id_prod] ?? 0;
                            $nombre_productor = $nombres_productores[$item['id_productor']] ?? 'Productor';
                            $stock_color      = ($stock_disponible <= 5 && $stock_disponible > 0) ? '#e65100' : '#6c757d';

                            $imagen_url = isset($fotos_por_producto[$id_prod])
                                ? '../../' . htmlspecialchars($fotos_por_producto[$id_prod])
                                : '../../imagenes/producto-default.png';
                        ?>
                        <div class="cart-item" id="item_<?php echo $id_prod; ?>">
                            <?php if (isset($fotos_por_producto[$id_prod])): ?>
                            <img src="<?php echo $imagen_url; ?>"
                                 alt="<?php echo htmlspecialchars($item['nombre']); ?>"
                                 class="cart-item-img"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="cart-item-img cart-item-no-foto" style="display:none;">
                                <i class="bi bi-image" style="font-size:32px;color:#adb5bd;"></i>
                            </div>
                            <?php else: ?>
                            <div class="cart-item-img cart-item-no-foto">
                                <i class="bi bi-image" style="font-size:32px;color:#adb5bd;"></i>
                            </div>
                            <?php endif; ?>

                            <div class="cart-item-info">
                                <h4><?php echo htmlspecialchars($item['nombre']); ?></h4>
                                <div style="font-size:13px;color:#6c757d;margin-bottom:4px;">
                                    <i class="bi bi-person-badge"></i>
                                    <?php echo htmlspecialchars($nombre_productor); ?>
                                    &nbsp;·&nbsp;
                                    <span style="color:<?php echo $stock_color; ?>;font-weight:500;">
                                        <?php echo $stock_disponible; ?> <?php echo htmlspecialchars($item['unidad_medida']); ?> disponibles
                                    </span>
                                </div>
                                <div class="cart-item-price">
                                    $<?php echo number_format($item['precio'], 0); ?>
                                    <span style="font-size:13px;font-weight:400;color:#6c757d;">
                                        / <?php echo ucfirst(htmlspecialchars($item['unidad_medida'])); ?>
                                    </span>
                                </div>

                                <div class="stock-warning" id="warn_<?php echo $id_prod; ?>">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <span id="warnMsg_<?php echo $id_prod; ?>"></span>
                                </div>

                                <div class="quantity-controls">
                                    <button type="button" class="qty-btn"
                                        onclick="cambiarCantidad(<?php echo $id_prod; ?>, -1, <?php echo $stock_disponible; ?>)">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <input type="number"
                                           id="qty_<?php echo $id_prod; ?>"
                                           value="<?php echo intval($item['cantidad']); ?>"
                                           min="1" max="<?php echo $stock_disponible; ?>"
                                           class="qty-input" readonly>
                                    <button type="button" class="qty-btn"
                                        onclick="cambiarCantidad(<?php echo $id_prod; ?>, 1, <?php echo $stock_disponible; ?>)">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                    <button type="button" class="btn-update"
                                        onclick="actualizarCantidad(<?php echo $id_prod; ?>, <?php echo $stock_disponible; ?>)">
                                        <i class="bi bi-check2"></i> Actualizar
                                    </button>
                                </div>
                            </div>

                            <div class="cart-item-actions">
                                <div class="subtotal-item" id="subtotal_<?php echo $id_prod; ?>">
                                    $<?php echo number_format($item['precio'] * $item['cantidad'], 0); ?>
                                </div>
                                <button type="button" class="btn-delete"
                                    onclick="abrirModal('🗑️','Eliminar producto','¿Deseas eliminar <strong><?php echo htmlspecialchars($item['nombre'], ENT_QUOTES); ?></strong> del carrito?','eliminar',<?php echo $id_prod; ?>)">
                                    <i class="bi bi-trash"></i> Eliminar
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div>
                        <div class="checkout-section">
                            <h2 class="section-title">
                                <i class="bi bi-receipt"></i>
                                Resumen del Pedido
                            </h2>
                            <div class="summary-row">
                                <span>Subtotal (<?php echo $items_carrito; ?> productos)</span>
                                <span id="totalCarrito">$<?php echo number_format($total_carrito, 0); ?></span>
                            </div>
                            <div class="summary-row">
                                <span>Envío</span>
                                <span style="color:var(--success);">Gratis</span>
                            </div>
                            <div class="summary-row total">
                                <span>TOTAL</span>
                                <span id="totalFinal">$<?php echo number_format($total_carrito, 0); ?></span>
                            </div>
                        </div>

                        <?php if ($bajo_minimo): ?>
                            <div style="background:#fffbf0;border:1.5px solid #ffe0a3;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
                                <div style="font-size:13px;color:#8a5c00;line-height:1.6;">
                                    Agrega <strong>$<?php echo number_format($faltante,0,'.',','); ?> más</strong> para completar el pedido mínimo de <strong>$<?php echo number_format(PEDIDO_MINIMO,0,'.',','); ?></strong>.
                                </div>
                                <a href="catalogo_comprador.php" style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;background:#f59e0b;color:white;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;">
                                    Ver más productos
                                </a>
                            </div>
                        <?php endif; ?>

                        <div class="checkout-section" style="margin-top:20px;">
                            <h2 class="section-title">
                                <i class="bi bi-credit-card"></i>
                                Método de Pago
                            </h2>

                            <div class="stock-warning" id="warnMetodo" style="margin-bottom:12px;">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <span>Selecciona un método de pago para continuar</span>
                            </div>

                            <?php if ($result_metodos && $result_metodos->num_rows > 0): ?>
                                <?php while ($metodo = $result_metodos->fetch_assoc()): ?>
                                    <label class="payment-method-card" onclick="marcarMetodo(this)">
                                        <input type="radio" name="id_metodo_pago" value="<?php echo $metodo['id_metodo']; ?>">
                                        <i class="<?php echo htmlspecialchars($metodo['icono']); ?> payment-icon"></i>
                                        <div style="flex:1;">
                                            <strong><?php echo htmlspecialchars($metodo['nombre_metodo']); ?></strong>
                                            <?php if ($metodo['descripcion']): ?>
                                                <div style="font-size:13px;color:#6c757d;margin-top:3px;">
                                                    <?php echo htmlspecialchars($metodo['descripcion']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </label>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="alert alert-warning">No hay métodos de pago disponibles</div>
                            <?php endif; ?>

                            <button type="submit" class="btn-proceed"<?php echo $bajo_minimo ? ' disabled title="Pedido mínimo: $' . number_format(PEDIDO_MINIMO,0,'.',',') . '"' : ''; ?>>
                                <i class="bi bi-shield-check"></i>
                                Proceder al Pago Seguro
                            </button>
                            <div style="margin-top:14px;text-align:center;">
                                <i class="bi bi-lock-fill" style="color:var(--primary);margin-right:5px;"></i>
                                <small style="color:#6c757d;">Pago 100% seguro y protegido</small>
                            </div>
                        </div>
                    </div>

                </div>
                </form>

            </div>
        </div>
    </main>
</div>

<script>
const precios = {
<?php foreach ($_SESSION['carrito'] as $item): ?>
    <?php echo $item['id_producto']; ?>: <?php echo floatval($item['precio']); ?>,
<?php endforeach; ?>
};

function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }

let toastTimer = null;
function mostrarToast(msg, tipo = 'success', duracion = 3500) {
    const el    = document.getElementById('toast');
    const icon  = document.getElementById('toastIcon');
    const texto = document.getElementById('toastMsg');
    const iconos = { success: 'bi-check-circle-fill', warning: 'bi-exclamation-triangle-fill', danger: 'bi-x-circle-fill' };
    el.className = '';
    el.classList.add(tipo);
    icon.className  = `bi ${iconos[tipo] || 'bi-info-circle-fill'} toast-icon`;
    texto.innerHTML = msg;
    void el.offsetWidth;
    el.classList.add('visible');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(ocultarToast, duracion);
}
function ocultarToast() { document.getElementById('toast').classList.remove('visible'); }

let accionModal = null, idProductoModal = null;
function abrirModal(icono, titulo, mensaje, accion, idProd = null) {
    document.getElementById('modalIcon').textContent  = icono;
    document.getElementById('modalTitle').textContent = titulo;
    document.getElementById('modalMsg').innerHTML     = mensaje;
    const btn = document.getElementById('modalConfirmBtn');
    btn.textContent = accion === 'vaciar' ? 'Sí, vaciar' : 'Sí, eliminar';
    accionModal     = accion;
    idProductoModal = idProd;
    document.getElementById('modalOverlay').classList.add('visible');
}
function cerrarModal() {
    document.getElementById('modalOverlay').classList.remove('visible');
    accionModal = idProductoModal = null;
}

function ejecutarAccionModal() {
    const accion = accionModal;
    const idProd = idProductoModal;
    cerrarModal();
    if (accion === 'vaciar') {
        document.getElementById('formVaciar').submit();
    } else if (accion === 'eliminar' && idProd !== null) {
        document.getElementById('elim_id').value = idProd;
        document.getElementById('formEliminar').submit();
    }
}

document.getElementById('modalOverlay').addEventListener('click', function (e) {
    if (e.target === this) cerrarModal();
});

function mostrarWarnStock(idProducto, msg) {
    const w = document.getElementById('warn_' + idProducto);
    if (!w) return;
    document.getElementById('warnMsg_' + idProducto).textContent = msg;
    w.classList.add('visible');
}
function ocultarWarnStock(idProducto) {
    const w = document.getElementById('warn_' + idProducto);
    if (w) w.classList.remove('visible');
}

function cambiarCantidad(idProducto, delta, stockMax) {
    const input  = document.getElementById('qty_' + idProducto);
    const actual = parseInt(input.value) || 1;
    const nuevo  = actual + delta;
    if (nuevo < 1) return;
    if (nuevo > stockMax) {
        mostrarWarnStock(idProducto, `Máximo disponible: ${stockMax} unidades`);
        return;
    }
    ocultarWarnStock(idProducto);
    input.value = nuevo;
    if (precios[idProducto] !== undefined) {
        const sub = document.getElementById('subtotal_' + idProducto);
        if (sub) sub.textContent = '$' + Math.round(precios[idProducto] * nuevo).toLocaleString('es-CO');
    }
}

function actualizarCantidad(idProducto, stockMax) {
    const cantidad = parseInt(document.getElementById('qty_' + idProducto).value) || 1;
    if (cantidad > stockMax) {
        mostrarWarnStock(idProducto, `Solo hay ${stockMax} unidades disponibles`);
        return;
    }
    ocultarWarnStock(idProducto);
    document.getElementById('act_id').value       = idProducto;
    document.getElementById('act_cantidad').value = cantidad;
    document.getElementById('formActualizar').submit();
}

function marcarMetodo(label) {
    document.querySelectorAll('.payment-method-card').forEach(c => c.classList.remove('selected'));
    label.classList.add('selected');
    label.querySelector('input[type="radio"]').checked = true;
    document.getElementById('warnMetodo').classList.remove('visible');
}

document.getElementById('formCheckout').addEventListener('submit', function (e) {
    if (<?php echo $bajo_minimo ? 'true' : 'false'; ?>) { e.preventDefault(); return; }
    const metodo = document.querySelector('input[name="id_metodo_pago"]:checked');
    if (!metodo) {
        e.preventDefault();
        const warn = document.getElementById('warnMetodo');
        warn.classList.add('visible');
        warn.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>

<form method="POST" action="checkout_carrito.php" id="formVaciar" style="display:none;">
    <input type="hidden" name="vaciar_carrito" value="1">
</form>
<form method="POST" action="checkout_carrito.php" id="formActualizar" style="display:none;">
    <input type="hidden" name="actualizar_cantidad" value="1">
    <input type="hidden" name="id_producto" id="act_id">
    <input type="hidden" name="cantidad"    id="act_cantidad">
</form>
<form method="POST" action="checkout_carrito.php" id="formEliminar" style="display:none;">
    <input type="hidden" name="eliminar_producto" value="1">
    <input type="hidden" name="id_producto"       id="elim_id">
</form>

</body>
</html>