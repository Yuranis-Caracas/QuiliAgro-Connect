<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'administrador');
require_once __DIR__ . '/../../config/auth_check.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'administrador') {
    header("Location: ../auth/login.php");
    exit();
}

$usuario_nombre = htmlspecialchars($_SESSION['user_nombre']);
$usuario_id = intval($_SESSION['user_id']);

function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception($conn->error);
            $stmt->bind_param($tipos, ...$params);
            $stmt->execute();
            return stripos(trim($query), 'select') === 0 ? $stmt->get_result() : $stmt;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'aprobar_resena') {
        $id_calificacion = intval($_POST['id_calificacion']);

        $rv = ejecutarConsulta($conn,
            "SELECT estado FROM calificaciones_resenas WHERE id_calificacion = ?",
            'i', [$id_calificacion]);

        if ($rv && $row = $rv->fetch_assoc()) {
            if ($row['estado'] === 'activa') {
                $_SESSION['mensaje_temporal'] = "Esta reseña ya estaba publicada.";
                $_SESSION['tipo_mensaje_temporal'] = "info";
            } else {
                if (ejecutarConsulta($conn,
                    "UPDATE calificaciones_resenas SET estado = 'activa' WHERE id_calificacion = ?",
                    'i', [$id_calificacion])) {
                    $_SESSION['mensaje_temporal'] = "Reseña aprobada y publicada.";
                    $_SESSION['tipo_mensaje_temporal'] = "success";
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    ejecutarConsulta($conn,
                        "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, id_registro_afectado, descripcion, ip_address)
                        VALUES (?, 'aprobar_resena', 'calificaciones_resenas', ?, 'Aprobó reseña', ?)",
                        "iis", [$usuario_id, $id_calificacion, $ip_address]);
                } else {
                    $_SESSION['mensaje_temporal'] = "Error al aprobar.";
                    $_SESSION['tipo_mensaje_temporal'] = "danger";
                }
            }
        } else {
            $_SESSION['mensaje_temporal'] = "Reseña no encontrada.";
            $_SESSION['tipo_mensaje_temporal'] = "danger";
        }
    }

    if ($accion === 'rechazar_resena') {
        $id_calificacion = intval($_POST['id_calificacion']);
        $motivo = trim($_POST['motivo_rechazo'] ?? '');

        if (ejecutarConsulta($conn,
        "UPDATE calificaciones_resenas SET estado = 'eliminada', motivo_rechazo = ? WHERE id_calificacion = ?",
        'si', [$motivo, $id_calificacion])) {
            $_SESSION['mensaje_temporal'] = "Reseña rechazada.";
            $_SESSION['tipo_mensaje_temporal'] = "success";
            $desc = "Rechazó reseña" . ($motivo ? " - Motivo: $motivo" : "");
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            ejecutarConsulta($conn,
                "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, id_registro_afectado, descripcion, ip_address)
                VALUES (?, 'rechazar_resena', 'calificaciones_resenas', ?, ?, ?)",
                "iiss", [$usuario_id, $id_calificacion, $desc, $ip_address]);
        } else {
            $_SESSION['mensaje_temporal'] = "Error al rechazar.";
            $_SESSION['tipo_mensaje_temporal'] = "danger";
        }
    }

    header("Location: calificaciones.php");
    exit();
}

$mensaje      = $_SESSION['mensaje_temporal']      ?? '';
$tipo_mensaje = $_SESSION['tipo_mensaje_temporal'] ?? '';
unset($_SESSION['mensaje_temporal'], $_SESSION['tipo_mensaje_temporal']);

$stats = ejecutarConsulta($conn, "
    SELECT
        COUNT(*) as total,
        COUNT(CASE WHEN estado = 'activa'    THEN 1 END) as activas,
        COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes,
        COUNT(CASE WHEN estado = 'eliminada' THEN 1 END) as rechazadas,
        ROUND(AVG(CASE WHEN estado = 'activa' THEN calificacion END), 1) as promedio
    FROM calificaciones_resenas
")->fetch_assoc();

$sql = "
    SELECT c.*,
           prod.nombre as nombre_productor, prod.email as email_productor,
           comp.nombre as nombre_comprador, comp.email as email_comprador,
           o.id_orden, o.fecha as fecha_orden,
           GROUP_CONCAT(DISTINCT p.nombre SEPARATOR ', ') as nombre_producto
    FROM calificaciones_resenas c
    INNER JOIN usuarios prod ON c.id_productor = prod.id_usuario
    INNER JOIN usuarios comp ON c.id_comprador = comp.id_usuario
    INNER JOIN ordenes o ON c.id_orden = o.id_orden
    LEFT JOIN pedidos ped ON ped.id_orden = c.id_orden AND ped.id_productor = c.id_productor
    LEFT JOIN productos p ON ped.id_producto = p.id_producto
    GROUP BY c.id_calificacion
    ORDER BY
        CASE c.estado WHEN 'pendiente' THEN 0 WHEN 'activa' THEN 1 ELSE 2 END,
        c.fecha_calificacion DESC
";
$result_calificaciones = ejecutarConsulta($conn, $sql);

function generarEstrellas($calificacion) {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $calificacion
            ? '<i class="bi bi-star-fill" style="color:#ffc107;"></i>'
            : '<i class="bi bi-star" style="color:#ddd;"></i>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reseñas — QuiliAgro Connect Admin</title>
<link rel="icon" type="image/png" href="../../imagenes/logo.png">
<link rel="stylesheet" href="../../css/styles.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.review-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: var(--transition);
}
.review-card:hover { box-shadow: var(--shadow-md); }
.review-card.pendiente { border-left: 5px solid var(--warning); background: #fffbf0; }
.review-card.activa    { border-left: 5px solid var(--success); }
.review-card.eliminada { border-left: 5px solid var(--danger); opacity: 0.7; }

.rc-head {
    display: flex; align-items: center; gap: 15px;
    padding: 18px 25px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
}
.rc-rating { font-size: 20px; margin-right: 10px; }
.rc-meta {
    display: flex; flex-wrap: wrap; gap: 15px;
    font-size: 13px; color: var(--text-muted); align-items: center;
}
.rc-meta-item { display: flex; align-items: center; gap: 5px; }

.rc-body { padding: 20px 25px; background: var(--white); }
.rc-content { background: #f8f9fa; padding: 15px; border-radius: var(--radius-sm); line-height: 1.6; }
.rc-content.no-review { font-style: italic; color: #999; }

.rc-fotos { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
.rc-fotos a img {
    width: 90px; height: 90px; object-fit: cover;
    border-radius: 8px; border: 2px solid #e0e0e0; cursor: pointer;
    transition: transform .2s, border-color .2s;
}
.rc-fotos a img:hover { transform: scale(1.05); border-color: var(--primary); }

.rc-foot {
    display: flex; align-items: center; gap: 15px;
    padding: 15px 25px;
    background: var(--surface);
    border-top: 1px solid var(--border);
    flex-wrap: wrap;
}

.star-bar { display: flex; align-items: center; gap: 10px; font-size: 14px; margin-bottom: 8px; }
.star-bar-fill { flex: 1; height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden; }
.star-bar-progress { height: 100%; background: var(--warning); transition: width .3s; }

.badge-reeditada {
    background: #fff3cd; color: #856404;
    border: 1px solid #ffc107;
    border-radius: 20px; padding: 3px 12px;
    font-size: 12px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 4px;
}

@media (max-width: 768px) {
    .rc-head { flex-direction: column; align-items: flex-start; }
    .rc-meta { flex-direction: column; align-items: flex-start; }
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
      <a href="pedidos_admin.php"><i class="bi bi-box-seam"></i> Pedidos</a>
      <a href="pagos_admin.php"><i class="bi bi-credit-card"></i> Pagos</a>
      <a href="calificaciones.php" class="active"><i class="bi bi-star"></i> Reseñas</a>
      <a href="reportes.php"><i class="bi bi-bar-chart"></i> Reportes</a>
      <a href="auditoria_admin.php"><i class="bi bi-shield-check"></i> Auditoría</a>
      <a href="ayudas_admin.php"><i class="bi bi-question-circle"></i> Ayuda</a>
    </nav>
  </aside>

  <main class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <h1>Gestión de Reseñas</h1>
        <p class="subtitle">Modera y administra las opiniones de los usuarios</p>
      </div>
      <div class="topbar-right">
        <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
      </div>
    </header>

    <div class="content-area">

      <?php if ($mensaje): ?>
      <div class="alert alert-<?php echo $tipo_mensaje; ?>" id="alertaMensaje">
        <i class="bi bi-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : ($tipo_mensaje === 'info' ? 'info-circle' : 'exclamation-triangle'); ?>-fill"></i>
        <?php echo htmlspecialchars($mensaje); ?>
      </div>
      <?php endif; ?>

      <div class="stats-grid">
        <div class="stat-card">
            <i class="bi bi-star-fill card-icon"></i><h3>Total</h3>
            <div class="number"><?php echo $stats['total']; ?></div><p>Reseñas</p>
        </div>
        <div class="stat-card success">
            <i class="bi bi-check-circle card-icon"></i><h3>Activas</h3>
            <div class="number"><?php echo $stats['activas']; ?></div><p>Publicadas</p>
        </div>
        <div class="stat-card warning">
            <i class="bi bi-clock-history card-icon"></i><h3>Pendientes</h3>
            <div class="number"><?php echo $stats['pendientes']; ?></div><p>Por moderar</p>
        </div>
        <div class="stat-card danger">
            <i class="bi bi-x-circle card-icon"></i><h3>Rechazadas</h3>
            <div class="number"><?php echo $stats['rechazadas']; ?></div><p>Eliminadas</p>
        </div>
      </div>

      <div class="catalogo-container">
        <div class="catalogo-header"><h2><i class="bi bi-bar-chart"></i> Distribución de Calificaciones</h2></div>
        <div>
          <?php
          $total_activas = $stats['activas'] ?: 1;
          for ($i = 5; $i >= 1; $i--):
            $count = ejecutarConsulta($conn,
                "SELECT COUNT(*) as c FROM calificaciones_resenas WHERE calificacion=? AND estado='activa'",
                'i', [$i])->fetch_assoc()['c'];
            $percent = ($count / $total_activas) * 100;
          ?>
          <div class="star-bar">
            <div style="width:100px;"><?php echo generarEstrellas($i); ?></div>
            <div class="star-bar-fill">
                <div class="star-bar-progress" style="width:<?php echo $percent; ?>%"></div>
            </div>
            <span style="min-width:80px;"><?php echo $count; ?> (<?php echo number_format($percent,1); ?>%)</span>
          </div>
          <?php endfor; ?>
        </div>
      </div>

    <div class="catalogo-container">
        <div class="catalogo-actions" style="justify-content:flex-start;">
            <div class="buscador-wrap" style="flex:1;">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="f_busqueda" class="form-control"
                      placeholder="Buscar por productor o comprador..."
                      oninput="filtrarResenas()">
            </div>

            <select id="f_calificacion" class="form-control" style="max-width:170px;"
                onchange="filtrarResenas()">
            <option value="todas">Todas las estrellas</option>
            <?php for ($i = 5; $i >= 1; $i--): ?>
            <option value="<?php echo $i; ?>"><?php echo $i; ?> estrellas</option>
            <?php endfor; ?>
            </select>

            <select id="f_estado" class="form-control" style="max-width:170px;"
                onchange="filtrarResenas()">
            <option value="todas">Todos los estados</option>
            <option value="pendiente">⏳ Pendientes</option>
            <option value="activa">✅ Aprobadas</option>
            <option value="eliminada">❌ Rechazadas</option>
          </select>

          <button type="button" class="btn btn-secondary" onclick="limpiarFiltros()">
              <i class="bi bi-x-circle"></i> Limpiar
          </button>
      </div>
    </div>

      <?php $num_results = $result_calificaciones ? $result_calificaciones->num_rows : 0; ?>
      <div style="font-size:14px;color:var(--text-muted);margin:20px 0 10px;font-weight:600;">
        <i class="bi bi-list-ul"></i>
        <span id="numResenas"><?php echo number_format($num_results); ?></span>
        reseña<?php echo $num_results !== 1 ? 's' : ''; ?> encontrada<?php echo $num_results !== 1 ? 's' : ''; ?>
      </div>

      <?php if ($num_results > 0): ?>
        <?php while ($r = $result_calificaciones->fetch_assoc()): ?>
          <div class="review-card <?php echo $r['estado']; ?>"
               data-estado="<?php echo htmlspecialchars($r['estado']); ?>"
               data-calificacion="<?php echo intval($r['calificacion']); ?>"
               data-comprador="<?php echo strtolower(htmlspecialchars($r['nombre_comprador'])); ?>"
               data-productor="<?php echo strtolower(htmlspecialchars($r['nombre_productor'])); ?>">

            <div class="rc-head">
              <div class="rc-rating">
                <?php echo generarEstrellas($r['calificacion']); ?>
                <span style="font-size:16px; margin-left:8px;"><?php echo $r['calificacion']; ?>/5</span>
              </div>
              <div class="rc-meta">
                <span class="rc-meta-item">
                    <i class="bi bi-person"></i> <?php echo htmlspecialchars($r['nombre_comprador']); ?>
                </span>
                <span class="rc-meta-item">
                    <i class="bi bi-shop"></i> <?php echo htmlspecialchars($r['nombre_productor']); ?>
                </span>
                <span class="rc-meta-item">
                    <i class="bi bi-box"></i> <?php echo htmlspecialchars($r['nombre_producto'] ?: 'N/A'); ?>
                </span>
                <span class="rc-meta-item">
                    <i class="bi bi-calendar"></i>
                    <?php echo date('d/m/Y H:i', strtotime($r['fecha_calificacion'])); ?>
                </span>
                <?php
                $badge_class = $r['estado'] === 'activa' ? 'success' : ($r['estado'] === 'pendiente' ? 'warning' : 'danger');
                $icono       = $r['estado'] === 'activa' ? '✅' : ($r['estado'] === 'pendiente' ? '⏳' : '❌');
                ?>
                <span class="badge badge-<?php echo $badge_class; ?>">
                    <?php echo $icono; ?> <?php echo ucfirst($r['estado']); ?>
                </span>
              </div>
            </div>

            <div class="rc-body">
              <?php if (!empty($r['resena'])): ?>
                <div class="rc-content"><?php echo nl2br(htmlspecialchars($r['resena'])); ?></div>
              <?php else: ?>
                <div class="rc-content no-review">
                    <i class="bi bi-chat-left-dots"></i> Sin comentario.
                </div>
              <?php endif; ?>

              <?php
              $imgs = !empty($r['imagenes']) ? json_decode($r['imagenes'], true) : [];
              if (!empty($imgs)): ?>
                <div class="rc-fotos">
                  <?php foreach ($imgs as $img): ?>
                    <a href="../../<?php echo htmlspecialchars($img); ?>" target="_blank">
                      <img src="../../<?php echo htmlspecialchars($img); ?>" alt="Foto de reseña">
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="rc-foot">
              <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <?php if ($r['estado'] === 'pendiente'): ?>
                  <button class="btn btn-success btn-sm"
                          onclick="aprobar(<?php echo $r['id_calificacion']; ?>)">
                    <i class="bi bi-check-circle"></i> Aprobar
                  </button>
                  <button class="btn btn-danger btn-sm"
                          onclick="abrirRechazo(<?php echo $r['id_calificacion']; ?>)">
                    <i class="bi bi-x-circle"></i> Rechazar
                  </button>
                <?php elseif ($r['estado'] === 'activa'): ?>
                  <button class="btn btn-danger btn-sm"
                          onclick="abrirRechazo(<?php echo $r['id_calificacion']; ?>)">
                    <i class="bi bi-trash"></i> Eliminar
                  </button>
                <?php elseif ($r['estado'] === 'eliminada'): ?>
                  <button class="btn btn-success btn-sm"
                          onclick="aprobar(<?php echo $r['id_calificacion']; ?>)">
                    <i class="bi bi-arrow-counterclockwise"></i> Restaurar
                  </button>
                <?php endif; ?>
                <button class="btn btn-info btn-sm"
                        onclick='verDetalles(<?php echo json_encode($r); ?>)'>
                  <i class="bi bi-info-circle"></i> Detalles
                </button>
              </div>
            </div>
          </div>
        <?php endwhile; ?>

        <div id="sinResultadosResenas" style="display:none; text-align:center; padding:60px;">
          <i class="bi bi-inbox" style="font-size:48px;color:var(--text-muted);display:block;margin-bottom:15px;"></i>
          <h3>No hay reseñas que mostrar</h3>
          <p>Intenta ajustar los filtros de búsqueda.</p>
        </div>

      <?php else: ?>
        <div class="catalogo-container" style="text-align:center; padding:60px;">
          <i class="bi bi-inbox" style="font-size:48px; color:var(--text-muted); display:block; margin-bottom:15px;"></i>
          <h3>No hay reseñas que mostrar</h3>
          <p>Intenta ajustar los filtros de búsqueda.</p>
        </div>
      <?php endif; ?>

    </div>
  </main>
</div>

<div class="modal" id="modalRechazo">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Rechazar Reseña</h2>
      <button class="close-modal" onclick="cerrarRechazo()">&times;</button>
    </div>
    <form method="POST" action="calificaciones.php">
      <input type="hidden" name="accion"          value="rechazar_resena">
      <input type="hidden" name="id_calificacion" id="rechazo_id">
      <div style="padding:20px;">
        <p><i class="bi bi-info-circle"></i> Puedes agregar un motivo (opcional).</p>
        <textarea name="motivo_rechazo" rows="4"
                  style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px;"></textarea>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" onclick="cerrarRechazo()">Cancelar</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle"></i> Rechazar</button>
      </div>
    </form>
  </div>
</div>

<div class="modal" id="modalDetalles">
  <div class="modal-content" style="max-width:700px;">
    <div class="modal-header">
      <h2>Detalles de la Reseña</h2>
      <button class="close-modal" onclick="cerrarDetalles()">&times;</button>
    </div>
    <div class="modal-body" id="detalleBody" style="padding:20px;"></div>
    <div class="form-actions">
      <button class="btn" onclick="cerrarDetalles()">Cerrar</button>
    </div>
  </div>
</div>

<script>
function filtrarResenas() {
    const buscar       = (document.getElementById('f_busqueda')?.value    || '').toLowerCase().trim();
    const estado       = document.getElementById('f_estado')?.value       || 'todas';
    const calificacion = document.getElementById('f_calificacion')?.value || 'todas';

    const tarjetas = document.querySelectorAll('.review-card');
    let visibles = 0;

    tarjetas.forEach(card => {
        const cardEstado       = card.dataset.estado       || '';
        const cardCalificacion = card.dataset.calificacion || '';
        const cardComprador    = card.dataset.comprador    || '';
        const cardProductor    = card.dataset.productor    || '';

        const okEstado = (estado === 'todas') || (cardEstado === estado);

        const okCalificacion = (calificacion === 'todas') || (cardCalificacion === calificacion);

        const okBuscar = !buscar ||
            cardComprador.includes(buscar) ||
            cardProductor.includes(buscar);

        const visible = okEstado && okCalificacion && okBuscar;
        card.style.display = visible ? '' : 'none';
        if (visible) visibles++;
    });

    const numEl = document.getElementById('numResenas');
    if (numEl) numEl.textContent = visibles.toLocaleString('es-CO');

    const vacio = document.getElementById('sinResultadosResenas');
    if (vacio) vacio.style.display = visibles === 0 ? '' : 'none';
}

function limpiarFiltros() {
    document.getElementById('f_busqueda').value    = '';
    document.getElementById('f_estado').value      = 'todas';
    document.getElementById('f_calificacion').value= 'todas';
    filtrarResenas();
}


setTimeout(() => {
    const a = document.getElementById('alertaMensaje');
    if (a) { a.style.transition = 'opacity .5s'; a.style.opacity = '0'; setTimeout(() => a.remove(), 500); }
}, 5000);


function aprobar(id) {
    if (!confirm('¿Aprobar y publicar esta reseña?')) return;
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = 'calificaciones.php';
    f.innerHTML = `
        <input type="hidden" name="accion"          value="aprobar_resena">
        <input type="hidden" name="id_calificacion" value="${id}">
    `;
    document.body.appendChild(f);
    f.submit();
}

function abrirRechazo(id) {
    document.getElementById('rechazo_id').value = id;
    document.getElementById('modalRechazo').classList.add('show');
}
function cerrarRechazo()  { document.getElementById('modalRechazo').classList.remove('show'); }
function cerrarDetalles() { document.getElementById('modalDetalles').classList.remove('show'); }

function verDetalles(r) {
    const estrellas = (n) => {
        let s = '';
        for (let i = 1; i <= 5; i++)
            s += i <= n
                ? '<i class="bi bi-star-fill" style="color:#ffc107;"></i>'
                : '<i class="bi bi-star" style="color:#ddd;"></i>';
        return s;
    };
    let fotosHtml = '';
    try {
        const imgs = r.imagenes ? JSON.parse(r.imagenes) : [];
        if (imgs.length > 0) {
            fotosHtml = `<div><strong>Fotos:</strong>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                    ${imgs.map(i => `<a href="../../${i}" target="_blank">
                        <img src="../../${i}" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:2px solid #ddd;">
                    </a>`).join('')}
                </div></div>`;
        }
    } catch(e) {}

    const estadoBadge = r.estado === 'activa'
        ? '<span class="badge badge-success">✅ Aprobada</span>'
        : r.estado === 'pendiente'
            ? '<span class="badge badge-warning">⏳ Pendiente</span>'
            : '<span class="badge badge-danger">❌ Rechazada</span>';

    document.getElementById('detalleBody').innerHTML = `
        <div style="display:grid;gap:15px;">
            <div><strong>Calificación:</strong> ${estrellas(r.calificacion)} ${r.calificacion}/5</div>
            <div><strong>Comprador:</strong> ${r.nombre_comprador}<br><small>${r.email_comprador||''}</small></div>
            <div><strong>Productor:</strong> ${r.nombre_productor}<br><small>${r.email_productor||''}</small></div>
            <div><strong>Productos:</strong> ${r.nombre_producto||'N/A'}</div>
            <div><strong>Orden:</strong> #${r.id_orden} — ${new Date(r.fecha_orden).toLocaleDateString('es-ES')}</div>
            <div><strong>Fecha calificación:</strong> ${new Date(r.fecha_calificacion).toLocaleString('es-ES')}</div>
            <div><strong>Estado:</strong> ${estadoBadge}</div>
            <div><strong>Comentario:</strong>
                <div style="background:#f8f9fa;padding:15px;border-radius:8px;margin-top:6px;">
                    ${r.resena ? r.resena.replace(/\n/g,'<br>') : '<em>Sin comentario</em>'}
                </div>
            </div>
            ${fotosHtml}
        </div>
    `;
    document.getElementById('modalDetalles').classList.add('show');
}

document.getElementById('modalRechazo').addEventListener('click',
    function(e) { if (e.target === this) cerrarRechazo(); });
document.getElementById('modalDetalles').addEventListener('click',
    function(e) { if (e.target === this) cerrarDetalles(); });


function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }
document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar'), mt = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && sb && !sb.contains(e.target)
        && mt && !mt.contains(e.target) && sb.classList.contains('open'))
        sb.classList.remove('open');
});
</script>
</body>
</html>