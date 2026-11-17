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

function ejecutarConsulta($conn, $query, $tipos = "", $params = []) {
    try {
        if (!empty($tipos) && !empty($params)) {
            $stmt = $conn->prepare($query);
            if (!$stmt) throw new Exception("Error preparando consulta: " . $conn->error);
            $stmt->bind_param($tipos, ...$params);
            $stmt->execute();
            return $stmt->get_result();
        } else {
            $result = $conn->query($query);
            if (!$result) throw new Exception("Error en consulta: " . $conn->error);
            return $result;
        }
    } catch (Exception $e) {
        error_log("Error BD: " . $e->getMessage());
        return false;
    }
}

$fecha_desde    = $_GET['fecha_desde'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_hasta    = $_GET['fecha_hasta'] ?? date('Y-m-d');
$accion_filtro  = $_GET['accion']      ?? 'todas';
$tabla_filtro   = $_GET['tabla']       ?? 'todas';
$admin_filtro   = $_GET['admin']       ?? 'todos';

if ($fecha_desde > $fecha_hasta) {
    $fecha_desde = date('Y-m-d', strtotime('-30 days'));
    $fecha_hasta = date('Y-m-d');
}

$query  = "SELECT a.*, u.nombre as admin_nombre FROM auditoria_admin a INNER JOIN usuarios u ON a.id_administrador = u.id_usuario WHERE DATE(a.fecha_accion) BETWEEN ? AND ?";
$params = [$fecha_desde, $fecha_hasta];
$tipos  = "ss";

if ($accion_filtro !== 'todas') { $query .= " AND a.accion = ?";              $params[] = $accion_filtro;         $tipos .= "s"; }
if ($tabla_filtro  !== 'todas') { $query .= " AND a.tabla_afectada = ?";      $params[] = $tabla_filtro;          $tipos .= "s"; }
if ($admin_filtro  !== 'todos') { $query .= " AND a.id_administrador = ?";    $params[] = intval($admin_filtro);  $tipos .= "i"; }
$query .= " ORDER BY a.fecha_accion DESC LIMIT 500";

$result_auditoria = ejecutarConsulta($conn, $query, $tipos, $params);
$result_acciones  = ejecutarConsulta($conn, "SELECT DISTINCT accion FROM auditoria_admin ORDER BY accion");
$result_tablas    = ejecutarConsulta($conn, "SELECT DISTINCT tabla_afectada FROM auditoria_admin WHERE tabla_afectada IS NOT NULL ORDER BY tabla_afectada");
$result_admins    = ejecutarConsulta($conn, "SELECT DISTINCT u.id_usuario, u.nombre FROM usuarios u INNER JOIN auditoria_admin a ON u.id_usuario = a.id_administrador WHERE u.rol = 'administrador' ORDER BY u.nombre");
$result_stats     = ejecutarConsulta($conn, "SELECT COUNT(*) as total_acciones, COUNT(DISTINCT id_administrador) as total_admins, COUNT(DISTINCT DATE(fecha_accion)) as dias_activos FROM auditoria_admin WHERE DATE(fecha_accion) BETWEEN ? AND ?", "ss", [$fecha_desde, $fecha_hasta]);
$stats            = $result_stats->fetch_assoc();

function clasificar(string $accion): array {
    if (strpos($accion, 'crear')    !== false) return ['create', 'plus-circle-fill'];
    if (strpos($accion, 'editar')   !== false || strpos($accion, 'actualizar') !== false) return ['edit', 'pencil-square'];
    if (strpos($accion, 'eliminar') !== false) return ['delete', 'trash-fill'];
    if (strpos($accion, 'exportar') !== false) return ['export', 'file-earmark-arrow-down-fill'];
    if (strpos($accion, 'aprobar')  !== false || strpos($accion, 'confirmar') !== false) return ['approve', 'check-circle-fill'];
    if (strpos($accion, 'reporte')  !== false) return ['report', 'bar-chart-fill'];
    if (strpos($accion, 'panel')    !== false) return ['panel', 'speedometer2'];
    return ['info', 'info-circle-fill'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auditoría - QuiliAgro Connect</title>
    <link rel="icon" type="image/png" href="../../imagenes/logo.png">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        .audit-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .audit-stat {
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            text-align: center;
        }
        .audit-stat h4 {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .audit-stat .num {
            font-size: 32px;
            font-weight: 800;
            color: var(--primary);
        }

        .filters-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 22px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
        }
        .filters-title {
            font-size: .8rem;
            font-weight: 700;
            color: var(--primary-dark);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .filters-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }
        .filters-grid .form-group { margin-bottom: 0; }
        .filters-grid .form-group label {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--text-muted);
        }
        .btn-row { display: flex; gap: 10px; margin-top: 15px; }

        .results-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .results-count {
            font-size: .8rem;
            font-weight: 600;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .audit-list { display: flex; flex-direction: column; gap: 12px; }

        .audit-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid rgba(40,167,69,.1);
            animation: auditFadeUp .3s ease both;
        }
        .audit-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }

        @keyframes auditFadeUp {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .card-accent {
            height: 4px;
        }
        .card-accent.create  { background: linear-gradient(90deg, var(--success), #5cb85c); }
        .card-accent.edit    { background: linear-gradient(90deg, var(--warning), #ffd454); }
        .card-accent.delete  { background: linear-gradient(90deg, var(--danger),  #f17070); }
        .card-accent.info    { background: linear-gradient(90deg, var(--info),    #4fc3d5); }
        .card-accent.export  { background: linear-gradient(90deg, #6f42c1, #a67dd4); }
        .card-accent.approve { background: linear-gradient(90deg, #28a745, #5cb85c); }
        .card-accent.report  { background: linear-gradient(90deg, var(--info),    #4fc3d5); }
        .card-accent.panel   { background: linear-gradient(90deg, #6c757d, #adb5bd); }

        .card-body { padding: 14px 16px; }

        .card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .card-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: .75rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .badge-create  { background: #d4edda; color: #155724; }
        .badge-edit    { background: #fff3cd; color: #856404; }
        .badge-delete  { background: #f8d7da; color: #721c24; }
        .badge-info    { background: #d1ecf1; color: #0c5460; }
        .badge-export  { background: #e2d9f3; color: #4a1d96; }
        .badge-approve { background: #d4edda; color: #155724; }
        .badge-report  { background: #d1ecf1; color: #0c5460; }
        .badge-panel   { background: #e9ecef; color: #495057; }

        .card-datetime {
            font-size: .71rem;
            color: var(--text-muted);
            text-align: right;
            line-height: 1.5;
            font-family: 'Courier New', monospace;
        }
        .card-datetime strong {
            display: block;
            font-size: .78rem;
            color: var(--text-dark);
        }

        .card-desc {
            font-size: .88rem;
            color: var(--text-dark);
            font-weight: 500;
            margin-bottom: 10px;
            line-height: 1.45;
        }
        .card-desc.muted { color: var(--text-muted); font-style: italic; }

        .card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 9px;
            background: var(--bg-light);
            border: 1px solid rgba(40,167,69,.15);
            border-radius: 6px;
            font-size: .72rem;
            color: var(--text-dark);
            font-weight: 600;
        }
        .meta-chip i { color: var(--primary); font-size: .78rem; }
        .meta-chip.chip-ip {
            font-family: 'Courier New', monospace;
            font-size: .68rem;
            background: #e8f5e9;
            border-color: var(--primary-light);
            color: var(--primary-dark);
        }

        .table-wrapper {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .table-wrapper .table-scroll {
            overflow-x: auto;
        }
        .table-wrapper table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
        }
        .table-wrapper thead { background: var(--primary-dark); }
        .table-wrapper th {
            padding: 14px 16px;
            color: #fff;
            font-size: .78rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
            text-align: left;
        }
        .table-wrapper td {
            padding: 13px 16px;
            border-bottom: 1px solid #f0f8f4;
            font-size: .84rem;
            vertical-align: middle;
        }
        .table-wrapper tbody tr:last-child td { border-bottom: none; }
        .table-wrapper tbody tr:hover { background: var(--bg-light); }
        .td-fecha {
            white-space: nowrap;
            font-family: 'Courier New', monospace;
            font-size: .78rem;
            color: var(--text-muted);
        }
        .td-fecha strong { display: block; color: var(--text-dark); font-size: .82rem; }
        .td-ip {
            font-family: 'Courier New', monospace;
            font-size: .75rem;
            background: #e8f5e9;
            border: 1px solid rgba(40,167,69,.2);
            padding: 3px 8px;
            border-radius: 5px;
            color: var(--primary-dark);
            display: inline-block;
        }
        .td-ip-null {
            font-size: .75rem;
            color: var(--text-muted);
            font-style: italic;
        }
        .td-desc-null {
            color: var(--text-muted);
            font-style: italic;
            font-size: .82rem;
        }
        .td-id {
            display: inline-block;
            background: #e8f5e9;
            border: 1px solid rgba(40,167,69,.2);
            padding: 2px 7px;
            border-radius: 4px;
            font-size: .75rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-state i { font-size: 3rem; display: block; margin-bottom: 12px; opacity: .35; }
        .empty-state p { font-size: .9rem; }

        .mobile-only  { display: flex; flex-direction: column; }
        .desktop-only { display: none; }

        @media (min-width: 769px) {
            .mobile-only  { display: none !important; }
            .desktop-only { display: block; }
            .filters-grid { grid-template-columns: repeat(3, 1fr); }
            .audit-stats  { gap: 20px; }
            .audit-stat .num { font-size: 2.6rem; }
        }

        @media (min-width: 1024px) {
            .filters-grid { grid-template-columns: repeat(5, 1fr); }
        }

        @media (max-width: 768px) {
            .filters-card { padding: 16px; }
        }
        .meta-chip strong {
            font-weight: 700;
            color: var(--text-muted);
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            margin-right: 2px;
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
            <a href="panel.php"><i class="bi bi-speedometer2"></i> Inicio</a>
            <a href="usuarios.php"><i class="bi bi-people"></i> Usuarios</a>
            <a href="catalogo_admin.php"><i class="bi bi-tags"></i> Catálogo</a>
            <a href="pedidos_admin.php"><i class="bi bi-box-seam"></i> Pedidos</a>
            <a href="pagos_admin.php"><i class="bi bi-credit-card"></i> Pagos</a>
            <a href="calificaciones.php"><i class="bi bi-star"></i> Reseñas</a>
            <a href="reportes.php"><i class="bi bi-bar-chart"></i> Reportes</a>
            <a href="auditoria_admin.php" class="active"><i class="bi bi-shield-check"></i> Auditoría</a>
            <a href="ayudas_admin.php"><i class="bi bi-question-circle"></i> Ayuda</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <h1><i class="bi bi-shield-check"></i> Auditoría</h1>
                <p class="subtitle">Registro de acciones administrativas</p>
            </div>
            <div class="topbar-right">
                <a href="../../logout.php" class="btn-logout">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Cerrar Sesión</span>
                </a>
            </div>
        </header>

        <div class="content-area">

            <div class="audit-stats">
                <div class="audit-stat">
                    <h4>Total Acciones</h4>
                    <div class="num"><?= $stats['total_acciones'] ?></div>
                </div>
                <div class="audit-stat">
                    <h4>Administradores Activos</h4>
                    <div class="num"><?= $stats['total_admins'] ?></div>
                </div>
                <div class="audit-stat">
                    <h4>Días con Actividad</h4>
                    <div class="num"><?= $stats['dias_activos'] ?></div>
                </div>
            </div>

            <div class="filters-card">
                <div class="filters-title">
                    <i class="bi bi-funnel-fill"></i> Filtros
                </div>
                <form method="GET" id="formAuditoria">
                    <div class="filters-grid">
                        <div class="form-group">
                            <label>Desde</label>
                            <input type="date" name="fecha_desde" value="<?= $fecha_desde ?>">
                        </div>
                        <div class="form-group">
                            <label>Hasta</label>
                            <input type="date" name="fecha_hasta" value="<?= $fecha_hasta ?>">
                        </div>
                        <div class="form-group">
                            <label>Acción</label>
                            <select name="accion">
                                <option value="todas">Todas</option>
                                <?php while ($ac = $result_acciones->fetch_assoc()): ?>
                                    <option value="<?= htmlspecialchars($ac['accion']) ?>"
                                        <?= $accion_filtro === $ac['accion'] ? 'selected' : '' ?>>
                                        <?= ucfirst(str_replace('_', ' ', $ac['accion'])) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tabla</label>
                            <select name="tabla">
                                <option value="todas">Todas</option>
                                <?php while ($tb = $result_tablas->fetch_assoc()): ?>
                                    <option value="<?= htmlspecialchars($tb['tabla_afectada']) ?>"
                                        <?= $tabla_filtro === $tb['tabla_afectada'] ? 'selected' : '' ?>>
                                        <?= ucfirst($tb['tabla_afectada']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Administrador</label>
                            <select name="admin">
                                <option value="todos">Todos</option>
                                <?php while ($adm = $result_admins->fetch_assoc()): ?>
                                    <option value="<?= $adm['id_usuario'] ?>"
                                        <?= $admin_filtro == $adm['id_usuario'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($adm['nombre']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filtrar
                        </button>
                        <a href="auditoria_admin.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Limpiar
                        </a>
                    </div>
                </form>
            </div>

            <?php
            $filas = [];
            if ($result_auditoria && $result_auditoria->num_rows > 0) {
                while ($r = $result_auditoria->fetch_assoc()) $filas[] = $r;
            }
            $total = count($filas);
            ?>

            <div class="results-header">
                <span class="results-count">
                    <i class="bi bi-list-ul"></i>
                    <?= $total ?> registro<?= $total != 1 ? 's' : '' ?> encontrado<?= $total != 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if ($total === 0): ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No se encontraron registros con los filtros seleccionados.</p>
                </div>

            <?php else: ?>

                <div class="audit-list mobile-only">
                    <?php foreach ($filas as $i => $audit):
                        [$tipo, $icon] = clasificar($audit['accion']);
                        $label = ucfirst(str_replace('_', ' ', $audit['accion']));
                        $fecha = date('d/m/Y', strtotime($audit['fecha_accion']));
                        $hora  = date('H:i:s', strtotime($audit['fecha_accion']));
                    ?>
                    <div class="audit-card" style="animation-delay:<?= min($i * 25, 250) ?>ms">
                        <div class="card-accent <?= $tipo ?>"></div>
                        <div class="card-body">
                            <div class="card-top">
                                <span class="card-badge badge-<?= $tipo ?>">
                                    <i class="bi bi-<?= $icon ?>"></i>
                                    <?= $label ?>
                                </span>
                                <div class="card-datetime">
                                    <strong><?= $fecha ?></strong>
                                    <?= $hora ?>
                                </div>
                            </div>

                            <?php if (!empty($audit['descripcion'])): ?>
                                <div class="card-desc"><?= htmlspecialchars($audit['descripcion']) ?></div>
                            <?php else: ?>
                                <div class="card-desc muted">Sin descripción</div>
                            <?php endif; ?>

                            <div class="card-meta">
                                <span class="meta-chip">
                                    <i class="bi bi-person-fill"></i>
                                    <strong>Administrador:</strong> <?= htmlspecialchars($audit['admin_nombre']) ?>
                                </span>
                                <?php if ($audit['tabla_afectada']): ?>
                                <span class="meta-chip">
                                    <i class="bi bi-table"></i>
                                    <strong>Tabla:</strong> <?= ucfirst($audit['tabla_afectada']) ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($audit['id_registro_afectado']): ?>
                                <span class="meta-chip">
                                    <i class="bi bi-hash"></i>
                                    <strong>ID:</strong> <?= $audit['id_registro_afectado'] ?>
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($audit['ip_address'])): ?>
                                <span class="meta-chip chip-ip">
                                    <i class="bi bi-hdd-network"></i>
                                    <strong>IP:</strong> <?= htmlspecialchars($audit['ip_address']) ?>
                                </span>
                                <?php else: ?>
                                <span class="meta-chip" style="color:var(--text-muted); font-style:italic;">
                                    <i class="bi bi-hdd-network"></i>
                                    <strong>IP:</strong> No registrada
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="table-wrapper desktop-only">
                    <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha / Hora</th>
                                <th>Administrador</th>
                                <th>Acción</th>
                                <th>Tabla</th>
                                <th>ID</th>
                                <th>Descripción</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($filas as $audit):
                            [$tipo, $icon] = clasificar($audit['accion']);
                            $label = ucfirst(str_replace('_', ' ', $audit['accion']));
                        ?>
                            <tr>
                                <td class="td-fecha">
                                    <strong><?= date('d/m/Y', strtotime($audit['fecha_accion'])) ?></strong>
                                    <?= date('H:i:s', strtotime($audit['fecha_accion'])) ?>
                                </td>
                                <td><?= htmlspecialchars($audit['admin_nombre']) ?></td>
                                <td>
                                    <span class="card-badge badge-<?= $tipo ?>">
                                        <i class="bi bi-<?= $icon ?>"></i> <?= $label ?>
                                    </span>
                                </td>
                                <td><?= $audit['tabla_afectada'] ? ucfirst($audit['tabla_afectada']) : '<span class="td-desc-null">—</span>' ?></td>
                                <td><?= $audit['id_registro_afectado'] ? ('<span class="td-id">#' . $audit['id_registro_afectado'] . '</span>') : '<span class="td-desc-null">—</span>' ?></td>
                                <td style="max-width:220px">
                                    <?php if (!empty($audit['descripcion'])): ?>
                                        <?= htmlspecialchars($audit['descripcion']) ?>
                                    <?php else: ?>
                                        <span class="td-desc-null">Sin descripción</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($audit['ip_address'])): ?>
                                        <span class="td-ip"><?= htmlspecialchars($audit['ip_address']) ?></span>
                                    <?php else: ?>
                                        <span class="td-ip-null">No registrada</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </main>
</div>

<script>
function toggleMenu() {
    document.getElementById('sidebar').classList.toggle('open');
}

document.addEventListener('click', (e) => {
    const sidebar    = document.getElementById('sidebar');
    const menuToggle = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 &&
        menuToggle &&
        !sidebar.contains(e.target) &&
        !menuToggle.contains(e.target) &&
        sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
    }
});

const inputDesde = document.querySelector('input[name="fecha_desde"]');
const inputHasta = document.querySelector('input[name="fecha_hasta"]');

function siguienteDia(fecha) {
    const d = new Date(fecha + 'T00:00:00');
    d.setDate(d.getDate() + 1);
    return d.toISOString().split('T')[0];
}

inputDesde.addEventListener('change', function () {
    const min = siguienteDia(this.value);
    inputHasta.min = min;
    if (inputHasta.value && inputHasta.value <= this.value) inputHasta.value = min;
});

document.addEventListener('DOMContentLoaded', () => {
    if (inputDesde && inputHasta) inputHasta.min = siguienteDia(inputDesde.value);
});
</script>
</body>
</html>