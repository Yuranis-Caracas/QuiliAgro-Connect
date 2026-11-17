<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'administrador');
require_once __DIR__ . '/../../config/auth_check.php';
require_once '../../config/mail.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'administrador') {
    header("Location: ../auth/login.php"); exit();
}

$conn->query(
    "UPDATE ordenes o
     JOIN pagos p ON p.id_orden = o.id_orden
     SET o.estado = 'expirado',
         p.estado = 'registrado'
     WHERE o.estado = 'pendiente'
       AND p.estado IN ('en_revision','registrado')
       AND p.fecha_expiracion < NOW()"
);

$admin_id = intval($_SESSION['user_id']);

function qry($conn, $sql, $tipos = '', $params = []) {
    try {
        if ($tipos && $params) {
            $st = $conn->prepare($sql);
            if (!$st) throw new Exception($conn->error);
            $st->bind_param($tipos, ...$params);
            $st->execute();
            return stripos(trim($sql), 'select') === 0 ? $st->get_result() : $st;
        }
        $r = $conn->query($sql);
        if (!$r) throw new Exception($conn->error);
        return $r;
    } catch (Exception $e) { error_log('BD: '.$e->getMessage()); return false; }
}

$stats_r = qry($conn, "
    SELECT
        COUNT(DISTINCT o.id_orden) AS total_compras,
        COUNT(DISTINCT CASE WHEN pag.estado IN ('en_revision','registrado') AND o.estado != 'expirado' THEN o.id_orden END) AS pago_pendiente,
        COUNT(DISTINCT CASE WHEN pag.estado = 'confirmado' AND ped_min.estado != 'entregado' THEN o.id_orden END)            AS en_proceso,
        COUNT(DISTINCT CASE WHEN ped_min.estado = 'entregado' THEN o.id_orden END)                                          AS entregadas,
        COUNT(DISTINCT CASE WHEN o.estado = 'expirado' THEN o.id_orden END)                                                 AS expiradas,
        COALESCE(SUM(CASE WHEN pag.estado='confirmado' THEN o.total END),0) AS monto_confirmado
    FROM ordenes o
    LEFT JOIN pagos pag ON pag.id_orden = o.id_orden
    LEFT JOIN (
        SELECT id_orden, MIN(estado) AS estado FROM pedidos GROUP BY id_orden
    ) ped_min ON ped_min.id_orden = o.id_orden
");
$stats = $stats_r ? $stats_r->fetch_assoc() : [];

$sql = "
    SELECT
        o.id_orden,
        o.total,
        o.fecha                  AS fecha_compra,
        o.estado                 AS estado_orden,
        pag.id_pago,
        pag.estado               AS estado_pago,
        pag.referencia,
        pag.fecha_expiracion,
        met.tipo                 AS tipo_metodo,
        met.nombre_metodo,
        comp.nombre              AS nombre_comprador,
        comp.email               AS email_comprador,
        comp.telefono            AS telefono_comprador,
        GROUP_CONCAT(
            DISTINCT CONCAT(ped.id_pedido,'|',prod.nombre,'|',ped.cantidad,'|',ped.precio_unitario,'|',ped.estado,'|',COALESCE(ped.id_productor,''),'|',COALESCE(vend.nombre,''),'|',COALESCE(prod.unidad_medida,''))
            ORDER BY ped.id_pedido SEPARATOR ';;'
        ) AS items,
        MIN(ped.estado)          AS estado_min_pedido,
        COUNT(ped.id_pedido)     AS num_items
    FROM ordenes o
    LEFT JOIN pagos pag        ON pag.id_orden    = o.id_orden
    LEFT JOIN metodos_pago met ON met.id_metodo   = pag.id_metodo_pago
    LEFT JOIN usuarios comp    ON comp.id_usuario  = o.id_comprador
    LEFT JOIN pedidos ped      ON ped.id_orden     = o.id_orden
    LEFT JOIN productos prod   ON prod.id_producto = ped.id_producto
    LEFT JOIN usuarios vend    ON vend.id_usuario  = ped.id_productor
    GROUP BY o.id_orden
    ORDER BY o.fecha DESC
";

$result = qry($conn, $sql);

function formatoFecha12h($ts) {
    return date('d/m/Y \a \l\a\s g:i A', $ts);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Pedidos — QuiliAgro Connect Admin</title>
<link rel="icon" type="image/png" href="../../imagenes/logo.png">
<link rel="stylesheet" href="../../css/styles.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.compra-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: var(--transition);
}
.compra-card:hover          { box-shadow: var(--shadow-md); }
.compra-card.pago-pendiente { border-left: 5px solid var(--warning); }
.compra-card.pago-confirmado{ border-left: 5px solid var(--primary); }
.compra-card.entregado      { border-left: 5px solid var(--success); }
.compra-card.expirado       { border-left: 5px solid var(--secondary); opacity: .8; }

.cc-head {
    display: flex; align-items: center; gap: 15px;
    padding: 18px 25px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
}
.cc-num   { font-size: 16px; font-weight: 700; color: var(--text-dark); }
.cc-fecha { font-size: 13px; color: var(--text-muted); }
.cc-total { margin-left: auto; font-size: 24px; font-weight: 800; color: var(--primary); }
.cc-total small { font-size: 13px; font-weight: 400; color: var(--text-muted); margin-left: 5px; }

.cc-productos { padding: 0 25px; }
.prod-row {
    display: flex; align-items: center; gap: 15px;
    padding: 15px 0; border-bottom: 1px solid var(--border); font-size: 14px;
}
.prod-row:last-child { border-bottom: none; }
.prod-icon {
    width: 40px; height: 40px; border-radius: var(--radius-sm);
    background: var(--bg-light); display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; color: var(--primary); font-size: 20px;
}
.prod-nombre { font-weight: 700; color: var(--text-dark); }
.prod-det    { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
.prod-subtotal { margin-left: auto; font-weight: 700; color: var(--text-dark); white-space: nowrap; }
.prod-estado-badge { font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; white-space: nowrap; flex-shrink: 0; }

.cc-foot {
    display: flex; align-items: center; gap: 15px;
    padding: 15px 25px; background: var(--surface);
    border-top: 1px solid var(--border); flex-wrap: wrap;
}
.cc-actor { display: flex; flex-direction: column; gap: 3px; font-size: 13px; }
.cc-actor strong { font-size: 14px; color: var(--text-dark); font-weight: 700; }
.cc-actor span   { color: var(--text-muted); }
.cc-sep   { width: 1px; height: 40px; background: var(--border); flex-shrink: 0; }
.cc-ref   { font-family: monospace; font-size: 12px; color: var(--text-muted); background: var(--border); padding: 4px 10px; border-radius: 5px; }
.cc-actions { margin-left: auto; display: flex; gap: 10px; flex-wrap: wrap; }

.aviso-efectivo {
    margin: 15px 25px 5px; padding: 14px 20px;
    background: #fff3cd; border: 1.5px solid rgba(230,81,0,.2);
    border-radius: var(--radius-sm);
    display: flex; align-items: center; gap: 15px;
}
.aviso-efectivo i { font-size: 20px; color: var(--warning); flex-shrink: 0; }
.aviso-efectivo .ae-text { flex: 1; font-size: 14px; color: #856404; }
.aviso-efectivo .ae-text strong { display: block; font-size: 14px; margin-bottom: 2px; }
.aviso-efectivo a.btn-ir-pagos {
    padding: 8px 16px; background: var(--warning); color: white;
    border-radius: var(--radius-sm); font-size: 13px; font-weight: 700;
    text-decoration: none; display: flex; align-items: center; gap: 6px;
    white-space: nowrap; transition: var(--transition);
}
.aviso-efectivo a.btn-ir-pagos:hover { background: #bf360c; transform: translateY(-2px); }

@media (max-width: 768px) {
    .cc-head       { flex-direction: column; align-items: flex-start; }
    .cc-total      { margin-left: 0; }
    .prod-row      { flex-wrap: wrap; }
    .prod-subtotal { margin-left: 0; width: 100%; }
    .cc-foot       { flex-direction: column; align-items: flex-start; }
    .cc-actions    { margin-left: 0; }
    .cc-sep        { display: none; }
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
      <a href="panel.php"><i class="bi bi-speedometer2"></i> Inicio</a>
      <a href="usuarios.php"><i class="bi bi-people"></i> Usuarios</a>
      <a href="catalogo_admin.php"><i class="bi bi-tags"></i> Catálogo</a>
      <a href="pedidos_admin.php" class="active"><i class="bi bi-box-seam"></i> Pedidos</a>
      <a href="pagos_admin.php"><i class="bi bi-credit-card"></i> Pagos</a>
      <a href="calificaciones.php"><i class="bi bi-star"></i> Reseñas</a>
      <a href="reportes.php"><i class="bi bi-bar-chart"></i> Reportes</a>
      <a href="auditoria_admin.php"><i class="bi bi-shield-check"></i> Auditoría</a>
      <a href="ayudas_admin.php"><i class="bi bi-question-circle"></i> Ayuda</a>
    </nav>
  </aside>

  <main class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <h1>Compras y Pedidos</h1>
        <p class="subtitle">Gestión de todas las compras realizadas en la plataforma</p>
      </div>
      <div class="topbar-right">
        <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
      </div>
    </header>

    <div class="content-area">

      <div class="stats-grid">
        <div class="stat-card">
          <i class="bi bi-bag-check card-icon"></i>
          <h3>Total Compras</h3>
          <div class="number"><?php echo $stats['total_compras'] ?? 0; ?></div>
          <p>Registradas</p>
        </div>
        <div class="stat-card warning">
          <i class="bi bi-clock-history card-icon"></i>
          <h3>Pago Pendiente</h3>
          <div class="number"><?php echo $stats['pago_pendiente'] ?? 0; ?></div>
          <p>Por verificar</p>
        </div>
        <div class="stat-card info">
          <i class="bi bi-arrow-repeat card-icon"></i>
          <h3>En Proceso</h3>
          <div class="number"><?php echo $stats['en_proceso'] ?? 0; ?></div>
          <p>Pago confirmado</p>
        </div>
        <div class="stat-card success">
          <i class="bi bi-truck card-icon"></i>
          <h3>Entregadas</h3>
          <div class="number"><?php echo $stats['entregadas'] ?? 0; ?></div>
          <p>Completadas</p>
        </div>
        <div class="stat-card secondary">
          <i class="bi bi-hourglass-split card-icon"></i>
          <h3>Expiradas</h3>
          <div class="number"><?php echo $stats['expiradas'] ?? 0; ?></div>
          <p>No pagadas a tiempo</p>
        </div>
        <div class="stat-card primary">
          <i class="bi bi-currency-dollar card-icon"></i>
          <h3>Ingresos Confirmados</h3>
          <div class="number">$<?php echo number_format($stats['monto_confirmado'] ?? 0, 0, '.', ','); ?></div>
          <p>COP</p>
        </div>
      </div>

      
    <div class="catalogo-container" style="margin-top:30px;">
        <div class="catalogo-actions" style="justify-content:flex-start;">
            <div class="buscador-wrap" style="flex:1;">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="f_busqueda" class="form-control"
                      placeholder="Buscar por comprador, producto o referencia..."
                      oninput="filtrarCompras()">
            </div>

            <select id="f_estado" class="form-control" style="max-width:200px;"
                    onchange="filtrarCompras()">
                <option value="todos">Todos los estados</option>
                <option value="pendiente">⏳ Pago pendiente</option>
                <option value="confirmado">✅ En proceso</option>
                <option value="entregado">🚚 Entregados</option>
                <option value="expirado">⌛ Expirados</option>
            </select>

            <select id="f_fecha" class="form-control" style="max-width:180px;"
                    onchange="filtrarCompras()">
                <option value="todos">Cualquier fecha</option>
                <option value="hoy">Hoy</option>
                <option value="semana">Última semana</option>
                <option value="mes">Último mes</option>
            </select>
            <button class="btn btn-secondary" onclick="limpiarFiltrosPedidos()">
              <i class="bi bi-x-circle"></i> Limpiar
          </button>
        </div>
    </div>

      <?php $num_results = $result ? $result->num_rows : 0; ?>
      <div id="contadorCompras" style="font-size:14px;color:var(--text-muted);margin:20px 0 10px;font-weight:600;">
        <i class="bi bi-list-ul"></i>
        <span id="numCompras"><?php echo number_format($num_results); ?></span>
        compra<?php echo $num_results !== 1 ? 's' : ''; ?> encontrada<?php echo $num_results !== 1 ? 's' : ''; ?>
      </div>

      <?php if ($num_results > 0): ?>

        <?php while ($row = $result->fetch_assoc()):
          // Parsear items
          $items = [];
          foreach (explode(';;', $row['items'] ?? '') as $raw) {
            $parts = explode('|', $raw);
            if (count($parts) >= 6) {
              $items[] = [
                'id'        => $parts[0],
                'nombre'    => $parts[1],
                'cantidad'  => $parts[2],
                'precio'    => $parts[3],
                'estado'    => $parts[4],
                'id_prod'   => $parts[5],
                'productor' => $parts[6] ?? '',
                'unidad'    => $parts[7] ?? 'unidad',
              ];
            }
          }

          $es_efectivo = ($row['tipo_metodo'] === 'efectivo');
          $pago_pend   = in_array($row['estado_pago'], ['en_revision','registrado']);
          $vence_ts    = $row['fecha_expiracion'] ? strtotime($row['fecha_expiracion']) : 0;
          $es_exp      = ($row['estado_orden'] === 'expirado');

          $card_class = 'compra-card';
          if     ($es_exp)                          $card_class .= ' expirado';
          elseif ($pago_pend)                       $card_class .= ' pago-pendiente';
          elseif ($row['estado_pago']==='confirmado' && $row['estado_min_pedido']!=='entregado') $card_class .= ' pago-confirmado';
          elseif ($row['estado_min_pedido']==='entregado') $card_class .= ' entregado';

          if     ($es_exp)                              $badge_pago = '<span class="badge badge-secondary"><i class="bi bi-hourglass-split"></i> Expirada</span>';
          elseif ($pago_pend)                           $badge_pago = '<span class="badge badge-warning"><i class="bi bi-clock-history"></i> Pago pendiente</span>';
          elseif ($row['estado_pago']==='confirmado')   $badge_pago = '<span class="badge badge-success"><i class="bi bi-check-circle-fill"></i> Pago confirmado</span>';
          else                                          $badge_pago = '<span class="badge badge-secondary"><i class="bi bi-dash"></i> Sin pago</span>';

          $data_estado = 'todos';
          if     ($es_exp)                                                                              $data_estado = 'expirado';
          elseif ($pago_pend)                                                                           $data_estado = 'pendiente';
          elseif ($row['estado_pago']==='confirmado' && $row['estado_min_pedido']!=='entregado')        $data_estado = 'confirmado';
          elseif ($row['estado_min_pedido']==='entregado')                                              $data_estado = 'entregado';

          $data_productos = strtolower(implode(' ', array_column($items, 'nombre')));

          $json_row   = htmlspecialchars(json_encode($row), ENT_QUOTES);
          $json_items = htmlspecialchars(json_encode($items), ENT_QUOTES);
        ?>
        <div class="<?php echo $card_class; ?>"
             data-estado="<?php echo $data_estado; ?>"
             data-nombre="<?php echo strtolower(htmlspecialchars($row['nombre_comprador'])); ?>"
             data-email="<?php echo strtolower(htmlspecialchars($row['email_comprador'])); ?>"
             data-referencia="<?php echo strtolower(htmlspecialchars($row['referencia'] ?? '')); ?>"
             data-productos="<?php echo htmlspecialchars($data_productos); ?>"
             data-fecha="<?php echo date('Y-m-d', strtotime($row['fecha_compra'])); ?>">

          <div class="cc-head">
            <div>
              <div class="cc-num">
                <i class="bi bi-bag" style="color:var(--primary);"></i>
                Compra del <?php echo date('d/m/Y', strtotime($row['fecha_compra'])); ?>
                &nbsp;·&nbsp; <?php echo $row['num_items']; ?> producto<?php echo $row['num_items']>1?'s':''; ?>
              </div>
              <div class="cc-fecha">
                <i class="bi bi-person" style="color:var(--text-muted);"></i> <?php echo htmlspecialchars($row['nombre_comprador']); ?>
                &nbsp;·&nbsp;
                <i class="bi bi-clock" style="color:var(--text-muted);"></i> <?php echo date('g:i A', strtotime($row['fecha_compra'])); ?>
              </div>
            </div>
            <?php echo $badge_pago; ?>
            <div class="cc-total">$<?php echo number_format($row['total'],0,'.',','); ?><small>COP</small></div>
          </div>

          <?php if ($es_efectivo && $pago_pend && !$es_exp): ?>
          <div class="aviso-efectivo">
            <i class="bi bi-cash-stack"></i>
            <div class="ae-text">
              <strong>Pago en efectivo por verificar</strong>
              El comprador pagó en Efecty o Sured. Para confirmar este pago, ve al módulo de Pagos.
              <?php if ($vence_ts): ?>
                <span style="display:block;font-size:12px;color:#9a5000;margin-top:4px;">
                  <i class="bi bi-clock"></i> Vence el <?php echo formatoFecha12h($vence_ts); ?>
                </span>
              <?php endif; ?>
            </div>
            <a href="pagos_admin.php?estado=efectivo_pendiente" class="btn-ir-pagos">
              <i class="bi bi-credit-card"></i> Ir a Pagos
            </a>
          </div>
          <?php endif; ?>

          <div class="cc-productos">
            <?php foreach ($items as $item):
              $subtotal  = $item['cantidad'] * $item['precio'];
              $badge_ped = match($item['estado']) {
                'pendiente'  => 'badge-warning',
                'confirmado' => 'badge-info',
                'entregado'  => 'badge-success',
                default      => 'badge-secondary',
              };
              $label_ped = match($item['estado']) {
                'pendiente'  => '⏳ Pendiente',
                'confirmado' => '✅ Confirmado',
                'entregado'  => '🚚 Entregado',
                default      => ucfirst($item['estado']),
              };
            ?>
            <div class="prod-row">
              <div class="prod-icon"><i class="bi bi-basket3"></i></div>
              <div style="flex:1;min-width:0;">
                <div class="prod-nombre"><?php echo htmlspecialchars($item['nombre']); ?></div>
                <div class="prod-det">
                  <?php echo $item['cantidad']; ?> <?php echo htmlspecialchars($item['unidad']); ?>
                  &nbsp;×&nbsp; $<?php echo number_format($item['precio'],0,'.',','); ?>
                  &nbsp;·&nbsp; Productor: <?php echo htmlspecialchars($item['productor']); ?>
                </div>
              </div>
              <span class="badge <?php echo $badge_ped; ?> prod-estado-badge"><?php echo $label_ped; ?></span>
              <div class="prod-subtotal">$<?php echo number_format($subtotal,0,'.',','); ?></div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="cc-foot">
            <div class="cc-actor">
              <strong><i class="bi bi-person-circle" style="color:var(--text-muted);"></i> <?php echo htmlspecialchars($row['nombre_comprador']); ?></strong>
              <span><?php echo htmlspecialchars($row['email_comprador']); ?></span>
              <span><?php echo htmlspecialchars($row['telefono_comprador']); ?></span>
            </div>
            <?php if ($row['referencia']): ?>
            <div class="cc-sep"></div>
            <div>
              <div style="font-size:12px;color:var(--text-muted);margin-bottom:3px;">Referencia</div>
              <div class="cc-ref"><?php echo htmlspecialchars($row['referencia']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($row['nombre_metodo']): ?>
            <div class="cc-sep"></div>
            <div style="font-size:13px;color:var(--text-muted);">
              <div style="margin-bottom:2px;">Método de pago</div>
              <strong style="color:var(--text-dark);"><?php echo htmlspecialchars($row['nombre_metodo']); ?></strong>
            </div>
            <?php endif; ?>
            <div class="cc-actions">
              <button class="btn btn-info btn-sm" onclick='abrirDetalle(<?php echo $json_row; ?>,<?php echo $json_items; ?>)'>
                <i class="bi bi-info-circle"></i> Ver detalles
              </button>
            </div>
          </div>

        </div>
        <?php endwhile; ?>

        <div id="sinResultadosCompras" style="display:none; text-align:center; padding:60px;">
          <i class="bi bi-inbox" style="font-size:48px;color:var(--text-muted);display:block;margin-bottom:15px;"></i>
          <h3>No hay compras que mostrar</h3>
          <p>Intenta ajustar los filtros de búsqueda.</p>
        </div>

      <?php else: ?>
        <div class="catalogo-container" style="text-align:center;padding:60px;">
          <i class="bi bi-inbox" style="font-size:48px;color:var(--text-muted);display:block;margin-bottom:15px;"></i>
          <h3>No hay compras que mostrar</h3>
          <p>Intenta ajustar los filtros de búsqueda.</p>
        </div>
      <?php endif; ?>

    </div>
  </main>
</div>

<div class="modal" id="modalDetalle">
  <div class="modal-content" style="max-width:700px;">
    <div class="modal-header">
      <h2 id="md-titulo">Detalles de la compra</h2>
      <button class="close-modal" onclick="document.getElementById('modalDetalle').classList.remove('show')">&times;</button>
    </div>
    <div class="modal-body" id="md-cuerpo" style="padding:20px;"></div>
    <div class="form-actions">
      <button class="btn" onclick="document.getElementById('modalDetalle').classList.remove('show')">Cerrar</button>
    </div>
  </div>
</div>

<script>
function filtrarCompras() {
    const buscar = (document.getElementById('f_busqueda')?.value || '').toLowerCase().trim();
    const estado = document.getElementById('f_estado')?.value  || 'todos';
    const fecha  = document.getElementById('f_fecha')?.value   || 'todos';

    const hoy = new Date(); hoy.setHours(0, 0, 0, 0);
    let fechaLimite = null;
    if (fecha === 'hoy') {
        fechaLimite = hoy;
    } else if (fecha === 'semana') {
        fechaLimite = new Date(hoy); fechaLimite.setDate(hoy.getDate() - 7);
    } else if (fecha === 'mes') {
        fechaLimite = new Date(hoy); fechaLimite.setDate(hoy.getDate() - 30);
    }

    const tarjetas = document.querySelectorAll('.compra-card');
    let visibles = 0;

    tarjetas.forEach(card => {
        const cardEstado  = card.dataset.estado     || '';
        const cardNombre  = card.dataset.nombre     || '';
        const cardEmail   = card.dataset.email      || '';
        const cardRef     = card.dataset.referencia || '';
        const cardProds   = card.dataset.productos  || '';
        const cardFecha   = card.dataset.fecha      || '';   // 'YYYY-MM-DD'

        const okEstado = (estado === 'todos') || (cardEstado === estado);

        const okBuscar = !buscar ||
            cardNombre.includes(buscar) ||
            cardEmail.includes(buscar)  ||
            cardRef.includes(buscar)    ||
            cardProds.includes(buscar);

        let okFecha = true;
        if (fechaLimite && cardFecha) {
            const [y, m, d] = cardFecha.split('-').map(Number);
            const fechaCard = new Date(y, m - 1, d);
            okFecha = fechaCard >= fechaLimite;
        }

        const visible = okEstado && okBuscar && okFecha;
        card.style.display = visible ? '' : 'none';
        if (visible) visibles++;
    });

    const numEl = document.getElementById('numCompras');
    if (numEl) numEl.textContent = visibles.toLocaleString('es-CO');

    const vacio = document.getElementById('sinResultadosCompras');
    if (vacio) vacio.style.display = visibles === 0 ? '' : 'none';
}

function abrirDetalle(row, items) {
  const fmt  = n => '$ ' + Number(n).toLocaleString('es-CO', {minimumFractionDigits:0});
  const fmtD = d => { try { return new Date(d).toLocaleString('es-CO'); } catch(e){ return d; } };

  const estadoBadge = {
    pendiente:  '<span class="badge badge-warning">⏳ Pendiente</span>',
    confirmado: '<span class="badge badge-info">✅ Confirmado</span>',
    entregado:  '<span class="badge badge-success">🚚 Entregado</span>',
  };
  const pagoBadge = {
    en_revision: '<span class="badge badge-warning">Pendiente</span>',
    registrado:  '<span class="badge badge-warning">Pendiente</span>',
    confirmado:  '<span class="badge badge-success">Confirmado</span>',
  };

  const prods = items.map(i => `
    <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px dashed #eee;font-size:14px;">
      <div>
        <div style="font-weight:700;color:var(--text-dark);">${i.nombre}</div>
        <div style="color:var(--text-muted);margin-top:2px;">${i.cantidad} ${i.unidad} × ${fmt(i.precio)} · ${i.productor}</div>
      </div>
      <div style="text-align:right;">
        <div style="font-weight:700;">${fmt(i.cantidad*i.precio)}</div>
        <div style="margin-top:3px;">${estadoBadge[i.estado]||i.estado}</div>
      </div>
    </div>`).join('');

  document.getElementById('md-titulo').textContent = `Compra del ${fmtD(row.fecha_compra)}`;
  document.getElementById('md-cuerpo').innerHTML = `
    <div style="margin-bottom:20px;">
      <h4 style="font-size:14px;color:var(--primary);margin-bottom:10px;"><i class="bi bi-person"></i> Comprador</h4>
      <div style="display:flex;justify-content:space-between;padding:5px 0;"><span style="color:var(--text-muted);">Nombre</span><span style="font-weight:700;">${row.nombre_comprador}</span></div>
      <div style="display:flex;justify-content:space-between;padding:5px 0;"><span style="color:var(--text-muted);">Correo</span><span>${row.email_comprador}</span></div>
      <div style="display:flex;justify-content:space-between;padding:5px 0;"><span style="color:var(--text-muted);">Teléfono</span><span>${row.telefono_comprador}</span></div>
    </div>
    <div style="margin-bottom:20px;">
      <h4 style="font-size:14px;color:var(--primary);margin-bottom:10px;"><i class="bi bi-basket3"></i> Productos (${items.length})</h4>
      ${prods}
      <div style="display:flex;justify-content:flex-end;padding:10px 0;font-size:18px;font-weight:800;color:var(--primary);">Total: ${fmt(row.total)}</div>
    </div>
    <div>
      <h4 style="font-size:14px;color:var(--primary);margin-bottom:10px;"><i class="bi bi-credit-card"></i> Pago</h4>
      <div style="display:flex;justify-content:space-between;padding:5px 0;"><span style="color:var(--text-muted);">Método</span><span>${row.nombre_metodo||'—'}</span></div>
      <div style="display:flex;justify-content:space-between;padding:5px 0;"><span style="color:var(--text-muted);">Estado</span><span>${pagoBadge[row.estado_pago]||row.estado_pago||'—'}</span></div>
      <div style="display:flex;justify-content:space-between;padding:5px 0;"><span style="color:var(--text-muted);">Referencia</span><span style="font-family:monospace;">${row.referencia||'—'}</span></div>
      ${row.fecha_expiracion ? `<div style="display:flex;justify-content:space-between;padding:5px 0;"><span style="color:var(--text-muted);">Expira</span><span>${fmtD(row.fecha_expiracion)}</span></div>` : ''}
    </div>`;
  document.getElementById('modalDetalle').classList.add('show');
}

document.getElementById('modalDetalle').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('show');
});


document.addEventListener('click', e => {
  const sb = document.getElementById('sidebar');
  const mt = document.querySelector('.menu-toggle');
  if (window.innerWidth <= 768 && sb && !sb.contains(e.target) && mt && !mt.contains(e.target) && sb.classList.contains('open'))
    sb.classList.remove('open');
});
function limpiarFiltrosPedidos() {
    document.getElementById('f_busqueda').value = '';
    document.getElementById('f_estado').value   = 'todos';
    document.getElementById('f_fecha').value    = 'todos';
    filtrarCompras();
}
</script>
</body>
</html>