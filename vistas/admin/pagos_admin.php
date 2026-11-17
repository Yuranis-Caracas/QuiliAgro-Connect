<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'administrador');
require_once __DIR__ . '/../../config/auth_check.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'administrador') {
    header("Location: ../auth/login.php"); exit();
}

$admin_id     = intval($_SESSION['user_id']);
$admin_nombre = htmlspecialchars($_SESSION['user_nombre'] ?? '');

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

$msg = ''; $msg_tipo = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($accion === 'confirmar_pago_efectivo') {
        $id_pago  = intval($_POST['id_pago']);
        $id_orden = intval($_POST['id_orden']);

        $conn->begin_transaction();
        try {
            qry($conn, "UPDATE pagos SET estado='confirmado', fecha_confirmacion=NOW() WHERE id_pago=? AND estado IN ('en_revision','registrado')", 'i', [$id_pago]);
            qry($conn, "UPDATE pedidos SET estado='confirmado' WHERE id_orden=? AND estado='pendiente'", 'i', [$id_orden]);
            qry($conn, "INSERT INTO auditoria_admin (id_administrador,accion,tabla_afectada,id_registro_afectado,descripcion,ip_address) VALUES (?,'confirmar_pago_efectivo','pagos',?,?,?)",
                'iiss', [$admin_id, $id_pago, "Confirmó pago efectivo #$id_pago — orden #$id_orden", $ip_address]);
            $conn->commit();
            $msg = "✅ Pago confirmado. Los pedidos de la orden #$id_orden quedaron confirmados.";
            $msg_tipo = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $msg = "Error al confirmar el pago."; $msg_tipo = "danger";
        }
    }

    if ($accion === 'crear_metodo') {
        $nombre = trim($_POST['nombre_metodo'] ?? '');
        $desc   = trim($_POST['descripcion']   ?? '');
        $icono  = trim($_POST['icono']         ?? 'bi-credit-card');
        $tipo   = $_POST['tipo'] ?? '';
        if (!$tipo) { $msg = "Selecciona el tipo de método."; $msg_tipo = "danger"; }
        else {
            $existe = qry($conn, "SELECT id_metodo FROM metodos_pago WHERE nombre_metodo=?", 's', [$nombre]);
            if ($existe && $existe->num_rows > 0) { $msg = "Ya existe un método con ese nombre."; $msg_tipo = "danger"; }
            else {
                qry($conn, "INSERT INTO metodos_pago (nombre_metodo,descripcion,icono,tipo,activo) VALUES (?,?,?,?,1)", 'ssss', [$nombre,$desc,$icono,$tipo]);
                qry($conn, "INSERT INTO auditoria_admin (id_administrador,accion,tabla_afectada,descripcion,ip_address) VALUES (?,'crear_metodo_pago','metodos_pago',?,?)", 'iss', [$admin_id,"Creó método: $nombre", $ip_address]);
                $msg = "Método de pago creado."; $msg_tipo = "success";
            }
        }
    }

    if ($accion === 'editar_metodo') {
        $id     = intval($_POST['id_metodo']);
        $nombre = trim($_POST['nombre_metodo'] ?? '');
        $desc   = trim($_POST['descripcion']   ?? '');
        $icono  = trim($_POST['icono']         ?? 'bi-credit-card');
        $tipo   = $_POST['tipo'] ?? '';
        $activo = intval($_POST['activo'] ?? 1);
        if (!$tipo) { $msg = "Selecciona el tipo de método."; $msg_tipo = "danger"; }
        else {
            $existe = qry($conn, "SELECT id_metodo FROM metodos_pago WHERE nombre_metodo=? AND id_metodo!=?", 'si', [$nombre,$id]);
            if ($existe && $existe->num_rows > 0) { $msg = "Otro método ya tiene ese nombre."; $msg_tipo = "danger"; }
            else {
                qry($conn, "UPDATE metodos_pago SET nombre_metodo=?,descripcion=?,icono=?,tipo=?,activo=? WHERE id_metodo=?", 'ssssii', [$nombre,$desc,$icono,$tipo,$activo,$id]);
                qry($conn, "INSERT INTO auditoria_admin (id_administrador,accion,tabla_afectada,id_registro_afectado,descripcion,ip_address) VALUES (?,'editar_metodo_pago','metodos_pago',?,?,?)", 'iiss', [$admin_id,$id,"Editó método: $nombre", $ip_address]);
                $msg = "Método actualizado."; $msg_tipo = "success";
            }
        }
    }

    if ($accion === 'eliminar_metodo') {
        $id = intval($_POST['id_metodo']);
        $c  = qry($conn, "SELECT COUNT(*) AS t FROM pagos WHERE id_metodo_pago=?", 'i', [$id])->fetch_assoc()['t'];
        if ($c > 0) { $msg = "No se puede eliminar: tiene $c pago(s) registrado(s)."; $msg_tipo = "danger"; }
        else {
            qry($conn, "DELETE FROM metodos_pago WHERE id_metodo=?", 'i', [$id]);
            qry($conn, "INSERT INTO auditoria_admin (id_administrador,accion,tabla_afectada,id_registro_afectado,descripcion,ip_address) VALUES (?,'eliminar_metodo_pago','metodos_pago',?,'Eliminó método de pago',?)", 'iis', [$admin_id,$id, $ip_address]);
            $msg = "Método eliminado."; $msg_tipo = "success";
        }
    }
}


$sr = qry($conn, "
    SELECT
        COUNT(*) AS total,
        COUNT(CASE WHEN pag.estado IN ('en_revision','registrado') AND o.estado != 'expirado' THEN 1 END) AS pendientes,
        COUNT(CASE WHEN pag.estado='confirmado' THEN 1 END)                                               AS confirmados,
        COUNT(CASE WHEN o.estado='expirado' THEN 1 END)                                                   AS expirados,
        COALESCE(SUM(CASE WHEN pag.estado='confirmado'                                          THEN pag.valor END),0) AS monto_confirmado,
        COALESCE(SUM(CASE WHEN pag.estado IN ('en_revision','registrado') AND o.estado != 'expirado' THEN pag.valor END),0) AS monto_pendiente,
        COUNT(CASE WHEN pag.estado IN ('en_revision','registrado') AND met.tipo='efectivo' AND o.estado != 'expirado' THEN 1 END) AS efectivo_pendiente
    FROM pagos pag
    INNER JOIN ordenes o ON o.id_orden = pag.id_orden
    LEFT JOIN metodos_pago met ON met.id_metodo = pag.id_metodo_pago
");
$stats = $sr ? $sr->fetch_assoc() : [];


$metodos_r = qry($conn, "SELECT * FROM metodos_pago ORDER BY nombre_metodo");
$metodos = [];
if ($metodos_r) while ($m = $metodos_r->fetch_assoc()) $metodos[] = $m;


$sql_pagos = "
    SELECT
        pag.*,
        o.estado AS estado_orden,
        met.nombre_metodo, met.tipo AS tipo_metodo, met.icono AS icono_metodo,
        comp.nombre AS nombre_comprador, comp.email AS email_comprador, comp.telefono AS tel_comprador,
        GROUP_CONCAT(DISTINCT prod.nombre ORDER BY ped.id_pedido SEPARATOR ', ') AS productos,
        COUNT(DISTINCT ped.id_pedido) AS num_pedidos,
        SUM(ped.total) AS total_calculado
    FROM pagos pag
    INNER JOIN ordenes o        ON o.id_orden    = pag.id_orden
    INNER JOIN metodos_pago met ON met.id_metodo = pag.id_metodo_pago
    INNER JOIN pedidos ped      ON ped.id_orden  = pag.id_orden
    INNER JOIN productos prod   ON prod.id_producto = ped.id_producto
    INNER JOIN usuarios comp    ON comp.id_usuario  = o.id_comprador
    GROUP BY pag.id_pago
    ORDER BY pag.fecha_pago DESC
";
$pagos_r = qry($conn, $sql_pagos);

$tipo_ui = [
    'billetera'     => ['bi-phone-fill',          '#c2185b', '#fce4ec'],
    'pse'           => ['bi-bank2',               '#1565c0', '#e3f2fd'],
    'efectivo'      => ['bi-cash-stack',           '#e65100', '#fff3e0'],
    'transferencia' => ['bi-arrow-left-right',     '#2e7d32', '#e8f5e9'],
    'tarjeta'       => ['bi-credit-card-2-front',  '#6a1b9a', '#f3e5f5'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Pagos — QuiliAgro Connect Admin</title>
<link rel="icon" type="image/png" href="../../imagenes/logo.png">
<link rel="stylesheet" href="../../css/styles.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.pago-card {
    border: 1px solid #e9ecef;
    border-radius: var(--radius-lg);
    margin-bottom: 1.5rem;
    overflow: hidden;
    transition: var(--transition);
    background: var(--bg-card);
}
.pago-card:hover { box-shadow: var(--shadow-md); }
.pago-card.registrado  { border-left: 4px solid var(--info); }
.pago-card.en_revision { border-left: 4px solid var(--warning); }
.pago-card.confirmado  { border-left: 4px solid var(--success); }

.pg-head {
    display: flex; align-items: center; gap: 1rem;
    padding: 1.2rem 1.5rem;
    background: var(--bg-light);
    border-bottom: 1px solid #e9ecef;
    flex-wrap: wrap;
}
.pg-metodo-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; flex-shrink: 0;
}
.pg-ref {
    font-family: monospace; font-size: .8rem; color: var(--text-muted);
    background: white; padding: .2rem .8rem; border-radius: 20px;
}
.pg-monto { margin-left: auto; font-size: 1.5rem; font-weight: 700; color: var(--primary); }
.pg-body {
    padding: 1.2rem 1.5rem;
    display: grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); gap: 1rem;
}
.pg-field { font-size: .9rem; }
.pg-field .pf-label {
    color: var(--text-muted); font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px; margin-bottom: .2rem;
}
.pg-field .pf-val { font-size: 1rem; color: var(--text-dark); font-weight: 600; }

.action-bar {
    background: #fff3cd; border-top: 1px solid var(--warning);
    padding: 1rem 1.5rem;
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
}
.action-bar p { margin: 0; color: #856404; flex: 1; }

.btn-confirm-green {
    background: var(--success); color: white; border: none;
    padding: .5rem 1rem; border-radius: var(--radius-sm);
    font-weight: 600; display: inline-flex; align-items: center; gap: .5rem;
    cursor: pointer; transition: var(--transition);
}
.btn-confirm-green:hover { background: var(--primary-dark); transform: translateY(-2px); }

.bsp {
    display: inline-flex; align-items: center; gap: .3rem;
    font-size: .75rem; font-weight: 700;
    padding: .2rem .8rem; border-radius: 20px;
}
.bsp.rev { background: #fff3cd; color: #856404; }
.bsp.ok  { background: #d4edda; color: #155724; }

.icon-grid {
    display: grid; grid-template-columns: repeat(auto-fill,minmax(90px,1fr));
    gap: .5rem; max-height: 250px; overflow-y: auto;
    border: 1px solid #e0e0e0; border-radius: var(--radius-sm); padding: .8rem;
}
.icon-opt {
    text-align: center; padding: .5rem .2rem;
    border: 2px solid transparent; border-radius: var(--radius-sm); cursor: pointer;
    transition: var(--transition);
}
.icon-opt:hover   { border-color: var(--primary); background: var(--bg-light); }
.icon-opt.selected { border-color: var(--primary); background: rgba(40,167,69,.1); }
.icon-opt i { font-size: 1.8rem; color: var(--primary); display: block; margin-bottom: .2rem; }
.icon-opt span { font-size: .7rem; color: var(--text-muted); }
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

#modalMetodo {
    display: none;
    align-items: center;
    justify-content: center;
}
#modalMetodo.show {
    display: flex !important;
}

#modalMetodo .modal-content {
    max-height: 85vh;
    overflow-y: auto;
    margin: auto;
    width: 100%;
    max-width: 560px;
}

body.modal-abierto {
    overflow: hidden;
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
      <a href="pagos_admin.php" class="active"><i class="bi bi-credit-card"></i> Pagos</a>
      <a href="calificaciones.php"><i class="bi bi-star"></i> Reseñas</a>
      <a href="reportes.php"><i class="bi bi-bar-chart"></i> Reportes</a>
      <a href="auditoria_admin.php"><i class="bi bi-shield-check"></i> Auditoría</a>
      <a href="ayudas_admin.php"><i class="bi bi-question-circle"></i> Ayuda</a>
    </nav>
  </aside>

  <main class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <h1>Gestión de Pagos</h1>
        <p class="subtitle">Métodos de pago configurados y transacciones de la plataforma</p>
      </div>
      <div class="topbar-right">
        <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
      </div>
    </header>

    <div class="content-area">

      <?php if ($msg): ?>
      <div class="alert alert-<?php echo $msg_tipo; ?>" id="alertaMensaje">
        <i class="bi bi-<?php echo $msg_tipo === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?>"></i>
        <?php echo htmlspecialchars($msg); ?>
      </div>
      <?php endif; ?>

      <div class="stats-grid">
        <div class="stat-card warning">
          <div class="card-icon"><i class="bi bi-hourglass-split"></i></div>
          <h3>Por verificar</h3>
          <div class="number"><?php echo $stats['pendientes'] ?? 0; ?></div>
          <p class="text-muted small"><?php echo $stats['efectivo_pendiente'] ?? 0; ?> en efectivo</p>
        </div>
        <div class="stat-card success">
          <div class="card-icon"><i class="bi bi-check-circle"></i></div>
          <h3>Confirmados</h3>
          <div class="number"><?php echo $stats['confirmados'] ?? 0; ?></div>
          <p class="text-muted small">Verificados</p>
        </div>
        <div class="stat-card warning">
          <div class="card-icon"><i class="bi bi-clock"></i></div>
          <h3>Monto pendiente</h3>
          <div class="number">$<?php echo number_format($stats['monto_pendiente'] ?? 0, 0, '.', ','); ?></div>
          <p class="text-muted small">Por confirmar</p>
        </div>
        <div class="stat-card success">
          <div class="card-icon"><i class="bi bi-currency-dollar"></i></div>
          <h3>Monto confirmado</h3>
          <div class="number">$<?php echo number_format($stats['monto_confirmado'] ?? 0, 0, '.', ','); ?></div>
          <p class="text-muted small">Ingresos reales</p>
        </div>
      </div>

      <?php if (($stats['efectivo_pendiente'] ?? 0) > 0): ?>
      <div class="alert alert-warning" style="display:flex;align-items:center;gap:1rem;">
        <i class="bi bi-cash-stack" style="font-size:1.5rem;"></i>
        <div style="flex:1;">
          <strong><?php echo $stats['efectivo_pendiente']; ?> pago<?php echo $stats['efectivo_pendiente'] > 1 ? 's' : ''; ?> en efectivo por confirmar</strong>
          <p class="mb-0">Estos pagos requieren tu verificación manual.</p>
        </div>
        <button class="btn btn-warning" onclick="filtroRapido('efectivo_pendiente')">
          <i class="bi bi-funnel-fill"></i> Ver solo efectivos
        </button>
      </div>
      <?php endif; ?>

      <div class="catalogo-container">
        <div class="catalogo-header">
          <h2><i class="bi bi-wallet2"></i> Métodos de Pago Configurados</h2>
          <button class="btn btn-primary" onclick="abrirModalMetodo()"><i class="bi bi-plus-lg"></i> Nuevo método</button>
        </div>

        <?php if (count($metodos) > 0): ?>
        <div class="categorias-grid">
          <?php foreach ($metodos as $met):
            $ui = $tipo_ui[$met['tipo']] ?? ['bi-credit-card','#388e3c','#e8f5e9'];
          ?>
          <div class="categoria-card <?php echo $met['activo'] ? '' : 'opacity-50'; ?>">
            <div class="categoria-header">
              <div class="categoria-icon" style="background:<?php echo $ui[2]; ?>;color:<?php echo $ui[1]; ?>;">
                <i class="bi <?php echo htmlspecialchars($met['icono']); ?>"></i>
              </div>
              <div class="categoria-info">
                <h3><?php echo htmlspecialchars($met['nombre_metodo']); ?></h3>
                <?php if ($met['descripcion']): ?>
                <p><?php echo htmlspecialchars($met['descripcion']); ?></p>
                <?php endif; ?>
                <span class="badge" style="background:<?php echo $ui[2]; ?>;color:<?php echo $ui[1]; ?>;"><?php echo strtoupper($met['tipo']); ?></span>
                <?php if (!$met['activo']): ?><span class="badge badge-secondary">Inactivo</span><?php endif; ?>
              </div>
            </div>
            <div class="categoria-actions">
              <button class="btn btn-warning btn-sm" onclick='editarMetodo(<?php echo htmlspecialchars(json_encode($met), ENT_QUOTES); ?>)'><i class="bi bi-pencil"></i> Editar</button>
              <button class="btn btn-danger btn-sm" onclick="eliminarMetodo(<?php echo $met['id_metodo']; ?>,'<?php echo htmlspecialchars($met['nombre_metodo']); ?>')"><i class="bi bi-trash"></i> Eliminar</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="bi bi-wallet2"></i><h3>Sin métodos configurados</h3></div>
        <?php endif; ?>
      </div>

      <div class="catalogo-container" style="margin-top:2rem;">
        <div class="catalogo-header">
          <h2><i class="bi bi-receipt"></i> Transacciones</h2>
        </div>

        
<div class="catalogo-actions" style="justify-content:flex-start; margin-bottom:1.5rem;">
    <div class="buscador-wrap" style="flex:1;">
        <i class="bi bi-search search-icon"></i>
        <input type="text" id="f_busqueda" class="form-control"
               placeholder="Buscar por referencia o comprador..."
               oninput="filtrarPagos()">
    </div>

    <select id="f_estado" class="form-control" style="max-width:220px;"
            onchange="filtrarPagos()">
        <option value="todos">Todos los estados</option>
        <option value="registrado">📝 Pendientes</option>
        <option value="confirmado">✅ Confirmados</option>
        <option value="expirado">⌛ Expirados</option>
    </select>

    <select id="f_metodo" class="form-control" style="max-width:200px;"
            onchange="filtrarPagos()">
        <option value="todos">Todos los métodos</option>
        <?php foreach ($metodos as $met): ?>
        <option value="<?php echo $met['id_metodo']; ?>">
            <?php echo htmlspecialchars($met['nombre_metodo']); ?>
        </option>
        <?php endforeach; ?>
    </select>
      <button class="btn btn-secondary" onclick="limpiarFiltrosPagos()">
        <i class="bi bi-x-circle"></i> Limpiar
      </button>
</div>

        <?php $num_pagos = $pagos_r ? $pagos_r->num_rows : 0; ?>
        <div style="font-size:14px;color:var(--text-muted);margin:0 0 16px;font-weight:600;">
          <i class="bi bi-list-ul"></i>
          <span id="numPagos"><?php echo number_format($num_pagos); ?></span>
          transacción<?php echo $num_pagos !== 1 ? 'es' : ''; ?> encontrada<?php echo $num_pagos !== 1 ? 's' : ''; ?>
        </div>

        <?php if ($pagos_r && $pagos_r->num_rows > 0): ?>
          <?php while ($pago = $pagos_r->fetch_assoc()):
            $ui      = $tipo_ui[$pago['tipo_metodo']] ?? ['bi-credit-card','#388e3c','#e8f5e9'];
            $es_ef   = ($pago['tipo_metodo'] === 'efectivo');
            $es_pend = in_array($pago['estado'], ['en_revision','registrado']);
            $vence_ts = $pago['fecha_expiracion'] ? strtotime($pago['fecha_expiracion']) : 0;
            $vencido  = $vence_ts && $vence_ts < time();
            $es_exp   = ($pago['estado_orden'] === 'expirado');

            $data_estado_js = $es_exp ? 'expirado' : $pago['estado'];
            $es_ef_pend = ($es_ef && $es_pend && !$vencido && !$es_exp);
          ?>
          <div class="pago-card <?php echo htmlspecialchars($pago['estado']); ?>"
               data-estado="<?php echo $data_estado_js; ?>"
               data-ef-pend="<?php echo $es_ef_pend ? '1' : '0'; ?>"
               data-metodo="<?php echo $pago['id_metodo_pago']; ?>"
               data-tipo="<?php echo htmlspecialchars($pago['tipo_metodo']); ?>"
               data-referencia="<?php echo strtolower(htmlspecialchars($pago['referencia'] ?? '')); ?>"
               data-comprador="<?php echo strtolower(htmlspecialchars($pago['nombre_comprador'])); ?>"
               data-email="<?php echo strtolower(htmlspecialchars($pago['email_comprador'])); ?>">

            <div class="pg-head">
              <div class="pg-metodo-icon" style="background:<?php echo $ui[2]; ?>;color:<?php echo $ui[1]; ?>;">
                <i class="bi <?php echo htmlspecialchars($pago['icono_metodo']); ?>"></i>
              </div>
              <div>
                <div style="font-weight:600;"><?php echo htmlspecialchars($pago['nombre_metodo']); ?></div>
                <div class="text-muted small"><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></div>
              </div>
              <div class="pg-ref"><?php echo htmlspecialchars($pago['referencia']); ?></div>
              <?php
                if ($es_exp)                              echo '<span class="bsp rev"><i class="bi bi-hourglass-split"></i> Expirado</span>';
                elseif ($es_pend)                         echo '<span class="bsp rev"><i class="bi bi-hourglass-split"></i> En revisión</span>';
                elseif ($pago['estado'] === 'confirmado') echo '<span class="bsp ok"><i class="bi bi-check-circle-fill"></i> Confirmado</span>';
              ?>
              <div class="pg-monto">$<?php echo number_format($pago['valor'], 0, '.', ','); ?></div>
            </div>

            <div class="pg-body">
              <div class="pg-field">
                <div class="pf-label"><i class="bi bi-person"></i> Comprador</div>
                <div class="pf-val"><?php echo htmlspecialchars($pago['nombre_comprador']); ?></div>
                <div class="small text-muted"><?php echo htmlspecialchars($pago['email_comprador']); ?></div>
              </div>
              <div class="pg-field">
                <div class="pf-label"><i class="bi bi-basket3"></i> Productos</div>
                <div class="pf-val"><?php echo htmlspecialchars($pago['productos']); ?></div>
                <div class="small text-muted"><?php echo $pago['num_pedidos']; ?> artículo<?php echo $pago['num_pedidos'] > 1 ? 's' : ''; ?></div>
              </div>
              <?php if ($vence_ts && $es_ef && $es_pend): ?>
              <div class="pg-field">
                <div class="pf-label"><i class="bi bi-clock"></i> Expira</div>
                <div class="pf-val" style="color:<?php echo $vencido ? 'var(--danger)' : 'var(--warning)'; ?>;">
                  <?php echo date('d/m/Y H:i', $vence_ts); ?>
                  <?php if ($vencido): ?> — <strong>VENCIDO</strong><?php endif; ?>
                </div>
              </div>
              <?php endif; ?>
              <?php if ($pago['fecha_confirmacion']): ?>
              <div class="pg-field">
                <div class="pf-label"><i class="bi bi-check"></i> Confirmado</div>
                <div class="pf-val" style="color:var(--success);"><?php echo date('d/m/Y H:i', strtotime($pago['fecha_confirmacion'])); ?></div>
              </div>
              <?php endif; ?>
            </div>

            <?php if ($es_ef && $es_pend && !$vencido && !$es_exp): ?>
            <div class="action-bar">
              <i class="bi bi-cash-stack" style="color:var(--warning);"></i>
              <p>El comprador pagó en <strong>Efecty / Sured</strong>. Confirma una vez recibido el dinero.</p>
              <form method="POST" onsubmit="return confirm('¿Confirmar que este pago en efectivo fue recibido?');" style="display:inline;">
                <input type="hidden" name="accion"   value="confirmar_pago_efectivo">
                <input type="hidden" name="id_pago"  value="<?php echo $pago['id_pago']; ?>">
                <input type="hidden" name="id_orden" value="<?php echo $pago['id_orden']; ?>">
                <button type="submit" class="btn-confirm-green">
                  <i class="bi bi-check-circle-fill"></i> Confirmar pago
                </button>
              </form>
            </div>

            <?php elseif ($es_ef && $vencido && $es_pend): ?>
            <div class="action-bar" style="background:#f8d7da;border-top-color:var(--danger);">
              <i class="bi bi-exclamation-triangle-fill" style="color:var(--danger);"></i>
              <p style="color:#721c24;">Este pago en efectivo venció sin ser confirmado.</p>
            </div>
            <?php endif; ?>

          </div>
          <?php endwhile; ?>

          <div id="sinResultadosPagos" style="display:none; text-align:center; padding:60px;">
            <i class="bi bi-inbox" style="font-size:48px;color:var(--text-muted);display:block;margin-bottom:15px;"></i>
            <h3>No hay transacciones que mostrar</h3>
            <p>Intenta ajustar los filtros de búsqueda.</p>
          </div>

        <?php else: ?>
          <div style="text-align:center;padding:60px;">
            <i class="bi bi-inbox" style="font-size:48px;color:var(--text-muted);display:block;margin-bottom:15px;"></i>
            <h3>No hay transacciones que mostrar</h3>
            <p>Intenta ajustar los filtros.</p>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </main>
</div>

<div class="modal" id="modalMetodo">
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="mm-titulo">Nuevo Método de Pago</h2>
      <button class="close-modal" onclick="cerrarModal()">&times;</button>
    </div>
    <div class="modal-body">
      <form method="POST" id="formMetodo">
        <input type="hidden" name="accion"    id="mm-accion" value="crear_metodo">
        <input type="hidden" name="id_metodo" id="mm-id">
        <div class="form-group">
          <label>Nombre del método *</label>
          <input type="text" name="nombre_metodo" id="mm-nombre" class="form-control" required maxlength="50" placeholder="Ej: Nequi, PSE, Efectivo">
        </div>
        <div class="form-group">
          <label>Tipo *</label>
          <select name="tipo" id="mm-tipo" class="form-control" required>
            <option value="">Seleccionar</option>
            <option value="billetera">Billetera digital (Nequi, Daviplata)</option>
            <option value="pse">PSE — Débito en línea</option>
            <option value="efectivo">Efectivo (Efecty, Sured)</option>
            <option value="transferencia">Transferencia bancaria</option>
            <option value="tarjeta">Tarjeta crédito / débito</option>
          </select>
        </div>
        <div class="form-group">
          <label>Descripción</label>
          <textarea name="descripcion" id="mm-desc" class="form-control" placeholder="Descripción breve (opcional)"></textarea>
        </div>
        <div class="form-group">
          <label>Ícono</label>
          <input type="hidden" name="icono" id="mm-icono" value="bi-credit-card">
          <div id="mm-icono-preview" style="font-size:2rem;color:var(--primary);text-align:center;margin:.5rem 0;"></div>
          <div class="icon-grid">
            <?php
            $iconos = [
                'bi-credit-card-2-front'=>'Tarjeta','bi-phone-fill'=>'Billetera',
                'bi-bank2'=>'PSE','bi-cash-stack'=>'Efectivo',
                'bi-arrow-left-right'=>'Transfer.','bi-wallet2'=>'Billetera',
                'bi-currency-dollar'=>'Dinero','bi-cash-coin'=>'Efectivo 2',
                'bi-bank'=>'Banco','bi-credit-card'=>'Tarjeta 2'
            ];
            foreach ($iconos as $ic => $lbl): ?>
            <div class="icon-opt" data-icon="<?php echo $ic; ?>" onclick="selIcon('<?php echo $ic; ?>')">
              <i class="bi <?php echo $ic; ?>"></i><span><?php echo $lbl; ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group" id="mm-grupo-activo" style="display:none;">
          <label>Estado</label>
          <select name="activo" id="mm-activo" class="form-control">
            <option value="1">Activo</option>
            <option value="0">Inactivo</option>
          </select>
        </div>
        <div class="form-actions">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function filtrarPagos() {
    const buscar = (document.getElementById('f_busqueda')?.value || '').toLowerCase().trim();
    const estado = document.getElementById('f_estado')?.value  || 'todos';
    const metodo = document.getElementById('f_metodo')?.value  || 'todos';

    const tarjetas = document.querySelectorAll('.pago-card');
    let visibles = 0;

    tarjetas.forEach(card => {
        const cardEstado   = card.dataset.estado    || '';
        const cardEfPend   = card.dataset.efPend    === '1';
        const cardMetodo   = card.dataset.metodo    || '';
        const cardRef      = card.dataset.referencia|| '';
        const cardComp     = card.dataset.comprador || '';
        const cardEmail    = card.dataset.email     || '';

        let okEstado = false;
        if (estado === 'todos') {
            okEstado = true;
        } else {
            okEstado = (cardEstado === estado);
        }

        const okMetodo = (metodo === 'todos') || (cardMetodo === metodo);

        const okBuscar = !buscar ||
            cardRef.includes(buscar)   ||
            cardComp.includes(buscar)  ||
            cardEmail.includes(buscar);

        const visible = okEstado && okMetodo && okBuscar;
        card.style.display = visible ? '' : 'none';
        if (visible) visibles++;
    });

    const numEl = document.getElementById('numPagos');
    if (numEl) numEl.textContent = visibles.toLocaleString('es-CO');

    const vacio = document.getElementById('sinResultadosPagos');
    if (vacio) vacio.style.display = visibles === 0 ? '' : 'none';
}

function filtroRapido(estado) {
    document.getElementById('f_estado').value = 'registrado';
    const opEfectivo = [...document.getElementById('f_metodo').options]
        .find(o => o.text.toLowerCase().includes('efectivo'));
    if (opEfectivo) document.getElementById('f_metodo').value = opEfectivo.value;
    filtrarPagos();
    document.getElementById('f_busqueda')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

setTimeout(() => {
    const a = document.getElementById('alertaMensaje');
    if (a) { a.style.transition = 'opacity .5s'; a.style.opacity = '0'; setTimeout(() => a.remove(), 500); }
}, 5000);


function selIcon(ic) {
    document.getElementById('mm-icono').value = ic;
    document.getElementById('mm-icono-preview').innerHTML = `<i class="bi ${ic}"></i>`;
    document.querySelectorAll('.icon-opt').forEach(o => o.classList.remove('selected'));
    document.querySelector(`.icon-opt[data-icon="${ic}"]`)?.classList.add('selected');
}
function abrirModalMetodo() {
    document.getElementById('mm-titulo').textContent = 'Nuevo Método de Pago';
    document.getElementById('mm-accion').value = 'crear_metodo';
    document.getElementById('mm-id').value = '';
    document.getElementById('formMetodo').reset();
    document.getElementById('mm-grupo-activo').style.display = 'none';
    selIcon('bi-credit-card');
    document.getElementById('modalMetodo').classList.add('show');
    document.body.classList.add('modal-abierto');
}

function editarMetodo(m) {
    document.getElementById('mm-titulo').textContent = 'Editar Método de Pago';
    document.getElementById('mm-accion').value = 'editar_metodo';
    document.getElementById('mm-id').value = m.id_metodo;
    document.getElementById('mm-nombre').value = m.nombre_metodo;
    document.getElementById('mm-tipo').value = m.tipo;
    document.getElementById('mm-desc').value = m.descripcion || '';
    document.getElementById('mm-activo').value = m.activo;
    document.getElementById('mm-grupo-activo').style.display = 'block';
    selIcon(m.icono || 'bi-credit-card');
    document.getElementById('modalMetodo').classList.add('show');
    document.body.classList.add('modal-abierto');
}

function cerrarModal() {
    document.getElementById('modalMetodo').classList.remove('show');
    document.body.classList.remove('modal-abierto');
}

function eliminarMetodo(id, nombre) {
    if (confirm(`¿Eliminar el método "${nombre}"?\nSolo se puede si no tiene transacciones.`)) {
        const f = document.createElement('form'); f.method = 'POST';
        f.innerHTML = `<input type="hidden" name="accion" value="eliminar_metodo"><input type="hidden" name="id_metodo" value="${id}">`;
        document.body.appendChild(f); f.submit();
    }
}

document.addEventListener('DOMContentLoaded', () => selIcon('bi-credit-card'));

document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar');
    const mt = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && sb && !sb.contains(e.target) && mt && !mt.contains(e.target) && sb.classList.contains('open'))
        sb.classList.remove('open');
});
function limpiarFiltrosPagos() {
    document.getElementById('f_busqueda').value = '';
    document.getElementById('f_estado').value   = 'todos';
    document.getElementById('f_metodo').value   = 'todos';
    filtrarPagos();
}
</script>
</body>
</html>