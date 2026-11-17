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
$usuario_id     = intval($_SESSION['user_id']);

function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param($tipos, ...$params);
            $ok = $stmt->execute();
            return stripos(trim($query), 'select') === 0 ? $stmt->get_result() : $ok;
        }
        $result = $conn->query($query);
        if (!$result) throw new Exception($conn->error);
        return $result;
    } catch (Exception $e) {
        error_log('Error BD: ' . $e->getMessage());
        return false;
    }
}

$mensaje = ''; $tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'marcar_entregado') {
    $id_pedido = intval($_POST['id_pedido']);

    $rv = ejecutarConsulta($conn,
        "SELECT id_pedido FROM pedidos WHERE id_pedido=? AND id_productor=? AND estado='confirmado'",
        "ii", [$id_pedido, $usuario_id]);

    if (!$rv || $rv->num_rows === 0) {
        $mensaje = "No se puede actualizar este pedido."; $tipo_mensaje = "danger";
    } else {
        $r = ejecutarConsulta($conn,
            "UPDATE pedidos SET estado='entregado' WHERE id_pedido=?", "i", [$id_pedido]);
        $mensaje      = $r ? "✅ Pedido marcado como entregado." : "Error al actualizar.";
        $tipo_mensaje = $r ? "success" : "danger";
    }
}

$result_stats = ejecutarConsulta($conn, "
    SELECT
        COUNT(CASE WHEN estado='confirmado' THEN 1 END) as confirmados,
        COUNT(CASE WHEN estado='entregado'  THEN 1 END) as entregados,
        COALESCE(SUM(CASE WHEN estado IN ('confirmado','entregado') THEN total ELSE 0 END),0) as ventas_totales
    FROM pedidos
    WHERE id_productor = ?
", "i", [$usuario_id]);

$stats = $result_stats ? $result_stats->fetch_assoc() : [
    'confirmados'=>0, 'entregados'=>0, 'ventas_totales'=>0
];

$query_pedidos = "
    SELECT
        ped.*,
        ROW_NUMBER() OVER (
            PARTITION BY ord.id_comprador 
            ORDER BY ord.fecha
        ) AS numero_orden,
        prod.nombre   AS nombre_producto,
        prod.imagen   AS imagen_producto,
        prod.precio   AS precio_unitario,
        comp.nombre   AS nombre_comprador,
        comp.email    AS email_comprador,
        comp.telefono AS telefono_comprador,
        pag.referencia      AS referencia_pago,
        pag.fecha_confirmacion,
        met.nombre_metodo,
        met.icono     AS icono_metodo
    FROM pedidos ped
    INNER JOIN productos prod ON ped.id_producto  = prod.id_producto
    INNER JOIN usuarios comp  ON ped.id_comprador = comp.id_usuario
    LEFT JOIN ordenes ord     ON ped.id_orden      = ord.id_orden
    LEFT JOIN pagos pag       ON ord.id_orden      = pag.id_orden
    LEFT JOIN metodos_pago met ON pag.id_metodo_pago = met.id_metodo
    WHERE ped.id_productor = ?
      AND ped.estado IN ('confirmado', 'entregado')
    ORDER BY ped.fecha_pedido DESC
";
$result_pedidos = ejecutarConsulta($conn, $query_pedidos, "i", [$usuario_id]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Mis Pedidos — QuiliAgro Connect</title>
<link rel="icon" type="image/png" href="../../imagenes/logo.png">
<link rel="stylesheet" href="../../css/styles.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.pedido-card {
    background: white; border-radius: var(--radius-lg); padding: 25px;
    margin-bottom: 25px; box-shadow: var(--shadow-sm);
    border-left: 5px solid var(--primary); transition: var(--transition);
}
.pedido-card:hover   { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.pedido-card.confirmado { border-left-color: var(--success); }
.pedido-card.entregado  { border-left-color: #17a2b8; }

.pedido-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 20px; flex-wrap: wrap; gap: 15px;
    padding-bottom: 20px; border-bottom: 2px solid #f0f0f0;
}
.pedido-producto { display: flex; gap: 15px; align-items: center; flex: 1; }
.pedido-producto-img {
    width: 90px; height: 90px; object-fit: cover;
    border-radius: var(--radius); border: 3px solid var(--bg-light); box-shadow: var(--shadow-sm);
}
.pedido-info h3 { font-size: 19px; color: var(--text-dark); margin-bottom: 8px; font-weight: 700; }
.pedido-info p  { color: var(--text-muted); margin: 4px 0; font-size: 14px; }
.pedido-status  { text-align: right; }
.pedido-total   { font-size: 32px; font-weight: 800; color: var(--primary); margin: 10px 0; }

.pedido-meta {
    display: grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr));
    gap: 20px; margin: 20px 0; padding: 20px;
    background: #f8f9fa; border-radius: var(--radius-sm);
}
.meta-item  { display: flex; flex-direction: column; gap: 5px; }
.meta-label { font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.meta-value { font-size: 15px; color: var(--text-dark); font-weight: 600; }

.pedido-actions {
    display: flex; gap: 10px; flex-wrap: wrap;
    margin-top: 20px; padding-top: 20px; border-top: 2px solid #e0e0e0;
    align-items: center;
}

@media (max-width:768px) {
    .pedido-producto { flex-direction: column; align-items: flex-start; }
    .pedido-status   { text-align: left; width: 100%; }
    .pedido-meta     { grid-template-columns: 1fr; }
    .pedido-actions  { flex-direction: column; }
    .pedido-actions .btn { width: 100%; justify-content: center; }
}
.buscador-wrap {
    position: relative;
    display: flex;
    align-items: center;
}
.buscador-wrap .form-control {
    padding-left: 42px;
}
.buscador-wrap .search-icon {
    position: absolute;
    left: 14px;
    color: var(--text-muted);
    font-size: 16px;
    pointer-events: none;
}
</style>
</head>
<body>

<button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
  <i class="bi bi-list"></i>
</button>

<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header"><img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo"></div>
    <nav class="sidebar-menu">
      <a href="panel_productor.php"><i class="bi bi-speedometer2"></i> Inicio</a>
      <a href="productos_productor.php"><i class="bi bi-box-seam"></i> Mis Productos</a>
      <a href="pedidos_productor.php" class="active"><i class="bi bi-clipboard-check"></i> Pedidos</a>
      <a href="calificaciones_productor.php"><i class="bi bi-star"></i> Reseñas</a>
      <a href="perfil.php"><i class="bi bi-person-circle"></i> Mi Perfil</a>
      <a href="estadisticas.php"><i class="bi bi-bar-chart"></i> Estadísticas</a>
    </nav>
  </aside>

  <main class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <h1>Mis Pedidos</h1>
        <p class="subtitle">Pedidos con pago confirmado listos para entregar</p>
      </div>
      <div class="topbar-right">
        <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
      </div>
    </header>

    <div class="content-area">

      <?php if ($mensaje): ?>
      <div class="alert alert-<?php echo $tipo_mensaje; ?>" id="alertaMensaje">
        <i class="bi bi-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill"></i>
        <?php echo $mensaje; ?>
      </div>
      <?php endif; ?>

      <div class="stats-grid">
        <div class="stat-card success">
          <i class="bi bi-check-circle card-icon"></i>
          <h3>Confirmados</h3>
          <div class="number"><?php echo $stats['confirmados']; ?></div>
          <p>Listos para entregar</p>
        </div>
        <div class="stat-card info">
          <i class="bi bi-truck card-icon"></i>
          <h3>Entregados</h3>
          <div class="number"><?php echo $stats['entregados']; ?></div>
          <p>Completados</p>
        </div>
        <div class="stat-card primary">
          <i class="bi bi-cash-stack card-icon"></i>
          <h3>Ventas Totales</h3>
          <div class="number">$<?php echo number_format($stats['ventas_totales'], 0); ?></div>
          <p>Ingresos confirmados</p>
        </div>
      </div>

      <div class="catalogo-actions" style="margin-top:30px;">
    <div class="buscador-wrap" style="flex:1;">
        <i class="bi bi-search search-icon"></i>
        <input type="text" id="f_busqueda" class="form-control"
               placeholder="Buscar por comprador o producto..."
               oninput="filtrarPedidos()">
    </div>

    <select id="f_estado" class="form-control" style="max-width:200px;"
            onchange="filtrarPedidos()">
        <option value="todos">Todos</option>
        <option value="confirmado">✅ Confirmados</option>
        <option value="entregado">🚚 Entregados</option>
    </select>
</div>

        <?php $num_pedidos = $result_pedidos ? $result_pedidos->num_rows : 0; ?>
        <div style="font-size:14px;color:var(--text-muted);margin:20px 0 10px;font-weight:600;">
          <i class="bi bi-list-ul"></i>
          <span id="numPedidos"><?php echo number_format($num_pedidos); ?></span>
          pedido<?php echo $num_pedidos !== 1 ? 's' : ''; ?> encontrado<?php echo $num_pedidos !== 1 ? 's' : ''; ?>
        </div>

        <div style="margin-top:10px;">
          <?php if ($result_pedidos && $result_pedidos->num_rows > 0): ?>
            <?php while ($pedido = $result_pedidos->fetch_assoc()):
              $badge_class = $pedido['estado'] === 'entregado' ? 'info' : 'success';
              $icono       = $pedido['estado'] === 'entregado' ? '🚚' : '✅';
            ?>
            <div class="pedido-card <?php echo $pedido['estado']; ?>"
                 data-estado="<?php echo htmlspecialchars($pedido['estado']); ?>"
                 data-comprador="<?php echo strtolower(htmlspecialchars($pedido['nombre_comprador'])); ?>"
                 data-producto="<?php echo strtolower(htmlspecialchars($pedido['nombre_producto'])); ?>">

              <div class="pedido-header">
                <div class="pedido-producto">
                  <?php if ($pedido['imagen_producto']): ?>
                  <img src="../../<?php echo htmlspecialchars($pedido['imagen_producto']); ?>"
                       alt="<?php echo htmlspecialchars($pedido['nombre_producto']); ?>"
                       class="pedido-producto-img">
                  <?php else: ?>
                  <div class="pedido-producto-img" style="display:flex;align-items:center;justify-content:center;background:var(--bg-light);">
                    <i class="bi bi-image" style="font-size:36px;color:var(--text-muted);"></i>
                  </div>
                  <?php endif; ?>
                  <div class="pedido-info">
                    <h3><?php echo htmlspecialchars($pedido['nombre_producto']); ?></h3>
                    <p><i class="bi bi-person"></i> <?php echo htmlspecialchars($pedido['nombre_comprador']); ?></p>
                    <p><i class="bi bi-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($pedido['fecha_pedido'])); ?></p>
                    <p><i class="bi bi-hash"></i> Pedido #<?php echo $pedido['numero_orden']; ?></p>
                  </div>
                </div>
                <div class="pedido-status">
                  <span class="badge badge-<?php echo $badge_class; ?>" style="font-size:15px;padding:10px 18px;">
                    <?php echo $icono . ' ' . ucfirst($pedido['estado']); ?>
                  </span>
                  <div class="pedido-total">$<?php echo number_format($pedido['total'], 2); ?></div>
                </div>
              </div>

              <div class="pedido-meta">
                <div class="meta-item">
                  <div class="meta-label">Cantidad</div>
                  <div class="meta-value"><?php echo $pedido['cantidad']; ?> unidades</div>
                </div>
                <div class="meta-item">
                  <div class="meta-label">Precio Unitario</div>
                  <div class="meta-value">$<?php echo number_format($pedido['precio_unitario'], 2); ?></div>
                </div>
                <div class="meta-item">
                  <div class="meta-label">Método de pago</div>
                  <div class="meta-value">
                    <?php if ($pedido['icono_metodo']): ?>
                    <i class="<?php echo htmlspecialchars($pedido['icono_metodo']); ?>"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($pedido['nombre_metodo'] ?? '—'); ?>
                  </div>
                </div>
                <div class="meta-item">
                  <div class="meta-label">Contacto</div>
                  <div class="meta-value" style="font-size:13px;">
                    <div><?php echo htmlspecialchars($pedido['email_comprador']); ?></div>
                    <div><?php echo htmlspecialchars($pedido['telefono_comprador']); ?></div>
                  </div>
                </div>
                <?php if ($pedido['fecha_confirmacion']): ?>
                <div class="meta-item">
                  <div class="meta-label">Pago confirmado</div>
                  <div class="meta-value" style="color:var(--success);">
                    <?php echo date('d/m/Y H:i', strtotime($pedido['fecha_confirmacion'])); ?>
                  </div>
                </div>
                <?php endif; ?>
                <?php if ($pedido['referencia_pago']): ?>
                <div class="meta-item">
                  <div class="meta-label">Referencia</div>
                  <div class="meta-value" style="font-family:monospace;font-size:13px;">
                    <?php echo htmlspecialchars($pedido['referencia_pago']); ?>
                  </div>
                </div>
                <?php endif; ?>
              </div>

              <div class="pedido-actions">
                <?php if ($pedido['estado'] === 'confirmado'): ?>
                <form method="POST" onsubmit="return confirm('¿Confirmar que este pedido fue entregado al comprador?');">
                  <input type="hidden" name="accion"    value="marcar_entregado">
                  <input type="hidden" name="id_pedido" value="<?php echo $pedido['id_pedido']; ?>">
                  <button type="submit" class="btn btn-primary">
                    <i class="bi bi-truck"></i> Marcar como Entregado
                  </button>
                </form>
                <?php endif; ?>
                <div style="flex:1;text-align:right;color:var(--text-muted);font-size:13px;display:flex;align-items:center;justify-content:flex-end;gap:5px;">
                  <i class="bi bi-info-circle"></i>
                  <em><?php echo $pedido['estado']==='entregado' ? 'Pedido completado' : 'Listo para entregar'; ?></em>
                </div>
              </div>

            </div>
            <?php endwhile; ?>

            <div id="sinResultadosPedidos" style="display:none; text-align:center; padding:60px;">
              <i class="bi bi-inbox" style="font-size:48px;color:var(--text-muted);display:block;margin-bottom:15px;"></i>
              <h3>No hay pedidos que mostrar</h3>
              <p style="color:var(--text-muted);">Intenta ajustar los filtros de búsqueda.</p>
            </div>

          <?php else: ?>
          <div class="catalogo-container" style="text-align:center;padding:60px;">
            <i class="bi bi-inbox" style="font-size:48px;color:var(--text-muted);display:block;margin-bottom:15px;"></i>
            <h3>No hay pedidos que mostrar</h3>
            <p style="color:var(--text-muted);">
              Cuando se confirmen pagos de tus productos, los pedidos aparecerán aquí.
            </p>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </main>
</div>

<div id="modalImagen" class="modal" onclick="this.classList.remove('show')">
  <div class="modal-content" style="max-width:90%;max-height:90vh;padding:0;background:transparent;box-shadow:none;">
    <img id="imagenGrande" src="" style="max-width:100%;max-height:90vh;border-radius:var(--radius-lg);"
         onclick="event.stopPropagation()">
  </div>
</div>

<script>
function filtrarPedidos() {
    const buscar = (document.getElementById('f_busqueda')?.value || '').toLowerCase().trim();
    const estado = document.getElementById('f_estado')?.value || 'todos';

    const tarjetas = document.querySelectorAll('.pedido-card');
    let visibles = 0;

    tarjetas.forEach(card => {
        const cardEstado    = card.dataset.estado    || '';
        const cardComprador = card.dataset.comprador || '';
        const cardProducto  = card.dataset.producto  || '';

        const okEstado = (estado === 'todos') || (cardEstado === estado);

        const okBuscar = !buscar ||
            cardComprador.includes(buscar) ||
            cardProducto.includes(buscar);

        const visible = okEstado && okBuscar;
        card.style.display = visible ? '' : 'none';
        if (visible) visibles++;
    });

    const numEl = document.getElementById('numPedidos');
    if (numEl) numEl.textContent = visibles.toLocaleString('es-CO');

    // Mostrar/ocultar bloque vacío
    const vacio = document.getElementById('sinResultadosPedidos');
    if (vacio) vacio.style.display = visibles === 0 ? '' : 'none';
}

setTimeout(() => {
    const a = document.getElementById('alertaMensaje');
    if (a) { a.style.transition = 'opacity .5s'; a.style.opacity = '0'; setTimeout(() => a.remove(), 500); }
}, 5000);

document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar');
    const mt = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && sb && !sb.contains(e.target) && mt && !mt.contains(e.target) && sb.classList.contains('open'))
        sb.classList.remove('open');
});
</script>
</body>
</html>