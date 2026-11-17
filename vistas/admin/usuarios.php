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
            if (!$stmt) throw new Exception("Error preparando consulta: " . $conn->error);
            $stmt->bind_param($tipos, ...$params);
            $result = $stmt->execute();
            if (stripos(trim($query), 'SELECT') === 0) return $stmt->get_result();
            return $result;
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

$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($accion === 'crear_admin') {
        $nombre   = trim($_POST['nombre']);
        $cedula   = trim($_POST['cedula']);
        $telefono = trim($_POST['telefono']);
        $email    = trim($_POST['email']);
        $password = $_POST['password'];
        $rol      = 'administrador';

        $verificar_cedula = ejecutarConsulta($conn, "SELECT id_usuario FROM usuarios WHERE cedula = ?", "s", [$cedula]);
        if ($verificar_cedula && $verificar_cedula->num_rows > 0) {
            $mensaje = "La cédula ya está registrada"; $tipo_mensaje = "danger";
        } else {
            $verificar_email = ejecutarConsulta($conn, "SELECT id_usuario FROM usuarios WHERE email = ?", "s", [$email]);
            if ($verificar_email && $verificar_email->num_rows > 0) {
                $mensaje = "El email ya está registrado"; $tipo_mensaje = "danger";
            } else {
                $errores_pass = [];
                if (strlen($password) < 8)                              $errores_pass[] = "al menos 8 caracteres";
                if (!preg_match('/[A-Z]/', $password))                  $errores_pass[] = "una mayúscula";
                if (!preg_match('/[a-z]/', $password))                  $errores_pass[] = "una minúscula";
                if (!preg_match('/[0-9]/', $password))                  $errores_pass[] = "un número";
                if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) $errores_pass[] = "un símbolo";

                if ($errores_pass) {
                    $mensaje = "La contraseña requiere: " . implode(', ', $errores_pass);
                    $tipo_mensaje = "danger";
                } else {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $query = "INSERT INTO usuarios (nombre, cedula, telefono, email, password, rol, estado) VALUES (?, ?, ?, ?, ?, ?, 'activo')";
                    if (ejecutarConsulta($conn, $query, "ssssss", [$nombre, $cedula, $telefono, $email, $password_hash, $rol])) {
                        $mensaje = "Administrador creado exitosamente"; $tipo_mensaje = "success";
                        ejecutarConsulta($conn, "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, descripcion, ip_address) VALUES (?, 'crear_admin', 'usuarios', ?, ?)", "iss", [$usuario_id, "Creó administrador: $nombre", $ip_address]);
                    } else {
                        $mensaje = "Error al crear administrador: " . $conn->error; $tipo_mensaje = "danger";
                    }
                }
            }
        }
    }

    
if ($accion === 'editar_estado') {
    $id_usuario   = intval($_POST['id_usuario']);
    $estado       = $_POST['estado'];
    $motivo_sel   = $_POST['motivo_inactivacion'] ?? '';
    $motivo_otro  = trim($_POST['motivo_otro'] ?? '');

    $motivo_final = null;
    $validacion_ok = true;

    if ($estado === 'inactivo') {
        $motivo_final = ($motivo_sel === 'Otro' && $motivo_otro !== '')
            ? $motivo_otro
            : $motivo_sel;
        if (!$motivo_final) {
            $mensaje = "Debe seleccionar un motivo para inactivar la cuenta.";
            $tipo_mensaje = "danger";
            $validacion_ok = false;
        }
    }

    if ($validacion_ok) {
        $query_info    = ejecutarConsulta($conn, "SELECT nombre FROM usuarios WHERE id_usuario = ?", "i", [$id_usuario]);
        $usuario_info  = $query_info ? $query_info->fetch_assoc() : [];
        $nombre_usuario = $usuario_info['nombre'] ?? 'Desconocido';

        if ($estado === 'inactivo') {
            $ok = ejecutarConsulta($conn, "UPDATE usuarios SET estado = ?, motivo_eliminacion = ? WHERE id_usuario = ?", "ssi", [$estado, $motivo_final, $id_usuario]);
        } else {
            $ok = ejecutarConsulta($conn, "UPDATE usuarios SET estado = ?, motivo_eliminacion = NULL WHERE id_usuario = ?", "si", [$estado, $id_usuario]);
        }

        if ($ok) {
            $mensaje = "Estado actualizado exitosamente."; $tipo_mensaje = "success";
            $desc_audit = "Cambió estado de '$nombre_usuario' a '$estado'" . ($motivo_final ? " — Motivo: $motivo_final" : "");
            ejecutarConsulta($conn, "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, id_registro_afectado, descripcion, ip_address) VALUES (?, 'cambiar_estado', 'usuarios', ?, ?, ?)", "iiss", [$usuario_id, $id_usuario, $desc_audit, $ip_address]);
        } else {
            $mensaje = "Error al actualizar el estado."; $tipo_mensaje = "danger";
        }
    }
}

    if ($accion === 'editar_perfil_admin') {
        $id_admin = intval($_POST['id_admin']);
        $nombre   = trim($_POST['nombre']);
        $telefono = trim($_POST['telefono']);
        $email    = trim($_POST['email']);

        if ($id_admin != $usuario_id) {
            $mensaje = "No tiene permisos para editar este perfil"; $tipo_mensaje = "danger";
        } else {
            $verificar = ejecutarConsulta($conn, "SELECT id_usuario FROM usuarios WHERE email = ? AND id_usuario != ?", "si", [$email, $id_admin]);
            if ($verificar && $verificar->num_rows > 0) {
                $mensaje = "El email ya está registrado por otro usuario"; $tipo_mensaje = "danger";
            } else {
                if (ejecutarConsulta($conn, "UPDATE usuarios SET nombre = ?, telefono = ?, email = ? WHERE id_usuario = ?", "sssi", [$nombre, $telefono, $email, $id_admin])) {
                    $mensaje = "Perfil actualizado exitosamente"; $tipo_mensaje = "success";
                    $_SESSION['user_nombre'] = $nombre; $usuario_nombre = $nombre;
                    ejecutarConsulta($conn, "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, descripcion, ip_address) VALUES (?, 'editar_perfil', 'usuarios', 'Editó su propio perfil', ?)", "is", [$usuario_id, $ip_address]);
                } else {
                    $mensaje = "Error al actualizar perfil"; $tipo_mensaje = "danger";
                }
            }
        }
    }

    if ($accion === 'cambiar_password_admin') {
        $id_admin       = intval($_POST['id_admin']);
        $password_actual = $_POST['password_actual'];
        $password_nueva  = $_POST['password_nueva'];

        if ($id_admin != $usuario_id) {
            $mensaje = "No tiene permisos para cambiar esta contraseña"; $tipo_mensaje = "danger";
        } else {
            $query_pass  = ejecutarConsulta($conn, "SELECT password FROM usuarios WHERE id_usuario = ?", "i", [$id_admin]);
            $usuario_pass = $query_pass->fetch_assoc();

            if ($usuario_pass && password_verify($password_actual, $usuario_pass['password'])) {
                $errores_pass = [];
                if (strlen($password_nueva) < 8)                               $errores_pass[] = "al menos 8 caracteres";
                if (!preg_match('/[A-Z]/', $password_nueva))                   $errores_pass[] = "una mayúscula";
                if (!preg_match('/[a-z]/', $password_nueva))                   $errores_pass[] = "una minúscula";
                if (!preg_match('/[0-9]/', $password_nueva))                   $errores_pass[] = "un número";
                if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password_nueva)) $errores_pass[] = "un símbolo";

                if ($errores_pass) {
                    $mensaje = "La contraseña requiere: " . implode(', ', $errores_pass); $tipo_mensaje = "danger";
                } else {
                    $password_hash = password_hash($password_nueva, PASSWORD_DEFAULT);
                    if (ejecutarConsulta($conn, "UPDATE usuarios SET password = ? WHERE id_usuario = ?", "si", [$password_hash, $id_admin])) {
                        $mensaje = "Contraseña cambiada exitosamente"; $tipo_mensaje = "success";
                        ejecutarConsulta($conn, "INSERT INTO auditoria_admin (id_administrador, accion, tabla_afectada, descripcion, ip_address) VALUES (?, 'cambiar_password', 'usuarios', 'Cambió su contraseña', ?)", "is", [$usuario_id, $ip_address]);
                    } else {
                        $mensaje = "Error al cambiar contraseña"; $tipo_mensaje = "danger";
                    }
                }
            } else {
                $mensaje = "La contraseña actual es incorrecta"; $tipo_mensaje = "danger";
            }
        }
    }
}

$filtro_rol    = $_GET['rol']    ?? 'todos';
$filtro_estado = $_GET['estado'] ?? 'todos';
$busqueda      = $_GET['busqueda'] ?? '';
$query_usuarios = "SELECT * FROM usuarios WHERE 1=1";
$params = []; $tipos = "";

if ($filtro_rol !== 'todos')    { $query_usuarios .= " AND rol = ?";    $params[] = $filtro_rol;    $tipos .= "s"; }
if ($filtro_estado !== 'todos') { $query_usuarios .= " AND estado = ?"; $params[] = $filtro_estado; $tipos .= "s"; }
if (!empty($busqueda)) {
    $query_usuarios .= " AND (nombre LIKE ? OR cedula LIKE ?)";
    $b = "%$busqueda%"; $params = array_merge($params, [$b,$b]); $tipos .= "ss";
}
$query_usuarios .= " ORDER BY fecha_registro DESC";
$result_usuarios = ejecutarConsulta($conn, $query_usuarios, $tipos, $params);

if (isset($_GET['solo_tabla'])) {
    if ($result_usuarios && $result_usuarios->num_rows > 0):
        while ($usuario = $result_usuarios->fetch_assoc()):
            $es_admin_actual = ($usuario['id_usuario'] == $usuario_id);
            $badge_rol = $usuario['rol'] === 'administrador' ? 'primary' : ($usuario['rol'] === 'productor' ? 'success' : 'warning');
            $badge_estado = $usuario['estado'] === 'activo' ? 'success' : 'danger';
            $toggle = $usuario['estado'] === 'activo' ? 'on' : 'off';
            $u_json = htmlspecialchars(json_encode($usuario), ENT_QUOTES);
            echo "<tr class='" . ($es_admin_actual ? 'current-admin-row' : '') . "'>";
            echo "<td data-label='ID'>{$usuario['id_usuario']}</td>";
            echo "<td data-label='Nombre'>" . htmlspecialchars($usuario['nombre']) . ($es_admin_actual ? ' <span class="badge badge-you">Tú</span>' : '') . "</td>";
            echo "<td data-label='Cédula'>" . htmlspecialchars($usuario['cedula']) . "</td>";
            echo "<td data-label='Teléfono'>" . htmlspecialchars($usuario['telefono']) . "</td>";
            echo "<td data-label='Email'>" . htmlspecialchars($usuario['email'] ?? 'N/A') . "</td>";
            echo "<td data-label='Rol'><span class='badge badge-{$badge_rol}'>" . ucfirst($usuario['rol']) . "</span></td>";
            echo "<td data-label='Estado'><span class='badge badge-{$badge_estado}'>" . ucfirst($usuario['estado']) . "</span></td>";
            echo "<td data-label='Motivo'>";
            if ($usuario['motivo_eliminacion']) {
                echo "<span style='font-size:12px;color:#e65100;font-style:italic;'><i class='bi bi-chat-left-text'></i> " . htmlspecialchars($usuario['motivo_eliminacion']) . "</span>";
            } else {
                echo "<span style='color:var(--text-muted);font-size:12px;'>—</span>";
            }
            echo "</td>";
            echo "<td data-label='Registro'>" . date('d/m/Y', strtotime($usuario['fecha_registro'])) . "</td>";
            echo "<td data-label='Acciones'>";
            if (!$es_admin_actual) {
                echo "<button class='btn btn-warning btn-sm' onclick='cambiarEstadoUsuario({$u_json})'><i class='bi bi-toggle-{$toggle}'></i> Estado</button>";
            } else {
                echo "<button class='btn btn-secondary btn-sm' disabled title='No puedes cambiar tu propio estado'><i class='bi bi-person'></i> Tú</button>";
            }
            echo "</td></tr>";
        endwhile;
    else:
        echo "<tr><td colspan='10' style='text-align:center;padding:40px;color:#666;'><i class='bi bi-info-circle' style='font-size:22px;display:block;margin-bottom:8px;'></i><strong>No se encontraron usuarios</strong></td></tr>";
    endif;
    exit();
}

$query_admin_actual = ejecutarConsulta($conn, "SELECT * FROM usuarios WHERE id_usuario = ?", "i", [$usuario_id]);
$admin_actual = ($query_admin_actual && $query_admin_actual !== false)
    ? $query_admin_actual->fetch_assoc()
    : null;

$query_admin_actual = ejecutarConsulta($conn, "SELECT * FROM usuarios WHERE id_usuario = ?", "i", [$usuario_id]);
$admin_actual = $query_admin_actual->fetch_assoc();

$motivos_inactivacion = [
    'Incumplimiento de términos y condiciones',
    'Conducta inapropiada hacia otros usuarios',
    'Uso indebido de la plataforma',
    'Otro',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Gestión de Usuarios — QuiliAgro Connect</title>
<link rel="icon" type="image/png" href="../../imagenes/logo.png">
<link rel="stylesheet" href="../../css/styles.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.filters-bar { display:flex; gap:15px; flex-wrap:wrap; align-items:center; margin-bottom:25px; }
.current-admin-row { background:rgba(40,167,69,.05) !important; }
.badge-you { margin-left:6px; }
.password-requirements { font-size:12px; color:var(--text-muted); margin-top:6px; line-height:1.5; }
.admin-info-content { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px; }
.info-label { font-size:13px; font-weight:600; color:var(--text-muted); }
.info-value { font-size:15px; font-weight:500; color:var(--text-dark); }

.buscador-wrap {
    position: relative;
    display: flex;
    align-items: center;
    flex: 1;
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

#motivo-container {
    background: #fff8e1;
    border: 1.5px solid #d88e17;
    border-radius: 10px;
    padding: 16px;
    margin-top: 10px;
    display: none;
    animation: fadeIn .2s ease;
}
#motivo-container.visible { display: block; }
@keyframes fadeIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.motivo-label {
    font-size: 13px;
    font-weight: 700;
    color: #e6c400;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
#motivo_otro_container { display:none; margin-top:10px; }
#motivo_otro_container.visible { display:block; }

@media (max-width: 768px) {
    .productos-table thead { display: none; }

    .productos-table,
    .productos-table tbody,
    .productos-table tr,
    .productos-table td {
        display: block !important;
        width: 100% !important;
    }

    .productos-table tr {
        margin-bottom: 16px;
        border: 1px solid #e0e0e0;
        border-radius: 12px;
        padding: 14px;
        background: #fff;
        box-shadow: 0 1px 4px rgba(0,0,0,.06);
        grid-template-columns: none !important;
    }

    .productos-table td {
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between;
        align-items: flex-start;
        padding: 6px 4px !important;
        border: none !important;
        border-bottom: 1px solid #f0f0f0 !important;
        font-size: 14px;
        gap: 10px;
        grid-column: unset !important;
    }

    .productos-table td:last-child {
        border-bottom: none !important;
    }

    .productos-table td::before,
    .productos-table td:first-child::before,
    .productos-table td:last-child::before {
        content: attr(data-label) !important;
        display: block !important;
        font-weight: 700;
        color: var(--text-muted);
        font-size: 12px;
        min-width: 110px;
        flex-shrink: 0;
    }

    .filters-bar input, .filters-bar select, .filters-bar .btn { width: 100%; }
}

</style>
</head>
<body>

<div class="layout">
<button class="menu-toggle" onclick="toggleMenu()"><i class="bi bi-list"></i></button>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="../../imagenes/logo.png" alt="QuiliAgro" class="logo">
    </div>
    <nav class="sidebar-menu">
        <a href="panel.php"><i class="bi bi-speedometer2"></i> Inicio</a>
        <a href="usuarios.php" class="active"><i class="bi bi-people"></i> Usuarios</a>
        <a href="catalogo_admin.php"><i class="bi bi-tags"></i> Catálogo</a>
        <a href="pedidos_admin.php"><i class="bi bi-box-seam"></i> Pedidos</a>
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
            <h1>Gestión de Usuarios</h1>
            <p class="subtitle">Administración de usuarios del sistema</p>
        </div>
        <div class="topbar-right">
            <a href="../../logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a>
        </div>
    </header>

    <div class="content-area">
        <?php if ($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?>">
            <i class="bi bi-<?php echo $tipo_mensaje === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill"></i>
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
        <?php endif; ?>

        <?php if ($admin_actual): ?>
        <div class="catalogo-container">
            <div class="catalogo-header">
                <h2><i class="bi bi-person-badge"></i> Tu Información de Administrador</h2>
            </div>
            <div class="admin-info-content">
                <div><div class="info-label">Nombre</div><div class="info-value"><?php echo htmlspecialchars($admin_actual['nombre']); ?></div></div>
                <div><div class="info-label">Cédula</div><div class="info-value"><?php echo htmlspecialchars($admin_actual['cedula']); ?></div></div>
                <div><div class="info-label">Teléfono</div><div class="info-value"><?php echo htmlspecialchars($admin_actual['telefono']); ?></div></div>
                <div><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($admin_actual['email']); ?></div></div>
                <div><div class="info-label">Estado</div><div class="info-value"><span class="badge badge-success">Activo</span></div></div>
                <div><div class="info-label">Fecha Registro</div><div class="info-value"><?php echo date('d/m/Y', strtotime($admin_actual['fecha_registro'])); ?></div></div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" onclick="abrirModalEditarPerfil()"><i class="bi bi-pencil"></i> Editar Mi Perfil</button>
                <button class="btn btn-warning" onclick="abrirModalCambiarPassword()"><i class="bi bi-key"></i> Cambiar Contraseña</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="catalogo-container">
            <form method="GET" id="formBusqueda" class="catalogo-actions">
                <div class="buscador-wrap">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" name="busqueda" id="inputBusqueda" class="form-control"
                           placeholder="Buscar por nombre o cédula..."
                           value="<?php echo htmlspecialchars($busqueda); ?>"
                           autocomplete="off">
                </div>
                <select name="estado" id="selectEstado" class="form-control" style="width:auto;flex-shrink:0;">
                    <option value="todos">Todos los estados</option>
                    <option value="activo"   <?php echo $filtro_estado==='activo'   ? 'selected' : ''; ?>>✅ Activos</option>
                    <option value="inactivo" <?php echo $filtro_estado==='inactivo' ? 'selected' : ''; ?>>🚫 Inactivos</option>
                </select>
                <select name="rol" id="selectRol" class="form-control" style="width:auto;flex-shrink:0;">
                    <option value="todos">Todos los roles</option>
                    <option value="productor"     <?php echo $filtro_rol==='productor'?'selected':''; ?>>Productores</option>
                    <option value="comprador"     <?php echo $filtro_rol==='comprador'?'selected':''; ?>>Compradores</option>
                    <option value="administrador" <?php echo $filtro_rol==='administrador'?'selected':''; ?>>Administradores</option>
                </select>
                <button type="button" class="btn btn-success" onclick="abrirModalCrearAdmin()" style="flex-shrink:0;"><i class="bi bi-person-plus"></i> Nuevo Administrador</button>
            </form>
        </div>

        <div class="productos-table-container">
        <div class="table-responsive">
        <table class="productos-table">
            <thead>
                <tr>
                    <th>ID</th><th>Nombre</th><th>Cédula</th><th>Teléfono</th><th>Email</th>
                    <th>Rol</th><th>Estado</th><th>Motivo inactivación</th><th>Fecha Registro</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody id="tablaUsuariosTbody">
            <?php if ($result_usuarios && $result_usuarios->num_rows > 0):
                while ($usuario = $result_usuarios->fetch_assoc()):
                    $es_admin_actual = ($usuario['id_usuario'] == $usuario_id);
                    $badge_rol = $usuario['rol'] === 'administrador' ? 'primary' : ($usuario['rol'] === 'productor' ? 'success' : 'warning');
            ?>
            <tr class="<?php echo $es_admin_actual ? 'current-admin-row' : ''; ?>">
                <td data-label="ID"><?php echo $usuario['id_usuario']; ?></td>
                <td data-label="Nombre">
                    <?php echo htmlspecialchars($usuario['nombre']); ?>
                    <?php if ($es_admin_actual): ?><span class="badge badge-you">Tú</span><?php endif; ?>
                </td>
                <td data-label="Cédula"><?php echo htmlspecialchars($usuario['cedula']); ?></td>
                <td data-label="Teléfono"><?php echo htmlspecialchars($usuario['telefono']); ?></td>
                <td data-label="Email"><?php echo htmlspecialchars($usuario['email'] ?? 'N/A'); ?></td>
                <td data-label="Rol"><span class="badge badge-<?php echo $badge_rol; ?>"><?php echo ucfirst($usuario['rol']); ?></span></td>
                <td data-label="Estado">
                    <span class="badge badge-<?php echo $usuario['estado'] === 'activo' ? 'success' : 'danger'; ?>">
                        <?php echo ucfirst($usuario['estado']); ?>
                    </span>
                </td>
                <td data-label="Motivo">
                    <?php if ($usuario['motivo_eliminacion']): ?>
                        <span style="font-size:12px; color:#e65100; font-style:italic;">
                            <i class="bi bi-chat-left-text"></i> <?php echo htmlspecialchars($usuario['motivo_eliminacion']); ?>
                        </span>
                    <?php else: ?>
                        <span style="color:var(--text-muted); font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
                <td data-label="Registro"><?php echo date('d/m/Y', strtotime($usuario['fecha_registro'])); ?></td>
                <td data-label="Acciones">
                    <?php if (!$es_admin_actual): ?>
                    <button class="btn btn-warning btn-sm" onclick='cambiarEstadoUsuario(<?php echo json_encode($usuario); ?>)'>
                        <i class="bi bi-toggle-<?php echo $usuario['estado'] === 'activo' ? 'on' : 'off'; ?>"></i> Estado
                    </button>
                    <?php else: ?>
                    <button class="btn btn-secondary btn-sm" disabled title="No puedes cambiar tu propio estado">
                        <i class="bi bi-person"></i> Tú
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="10" style="text-align:center;padding:40px;color:#666;">
                <i class="bi bi-info-circle" style="font-size:22px;display:block;margin-bottom:8px;"></i>
                <strong>No se encontraron usuarios</strong>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>
</main>
</div>

<div id="modalCrearAdmin" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Crear Nuevo Administrador</h2>
            <button type="button" class="close-modal" onclick="cerrarModalCrearAdmin()">&times;</button>
        </div>
        <form id="formCrearAdmin" method="POST" onsubmit="return validarCrearAdmin()">
            <input type="hidden" name="accion" value="crear_admin">
            <div class="form-group"><label>Nombre Completo *</label><input type="text" name="nombre" required maxlength="100" placeholder="Nombre completo"></div>
            <div class="form-group"><label>Cédula *</label><input type="text" name="cedula" required maxlength="20" placeholder="Cédula"></div>
            <div class="form-group"><label>Teléfono *</label><input type="text" name="telefono" required maxlength="15" placeholder="Teléfono"></div>
            <div class="form-group"><label>Email *</label><input type="email" name="email" required maxlength="100" placeholder="correo@ejemplo.com"></div>
            <div class="form-group">
                <label>Contraseña *</label>
                <input type="password" name="password" id="admin_password" required placeholder="Contraseña">
                <div class="password-requirements"><strong>Requisitos:</strong><br>• Mínimo 8 caracteres<br>• Mayúscula, minúscula, número y símbolo</div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalCrearAdmin()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear Administrador</button>
            </div>
        </form>
    </div>
</div>

<div id="modalEstadoUsuario" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Cambiar Estado de Usuario</h2>
            <button type="button" class="close-modal" onclick="cerrarModalEstado()">&times;</button>
        </div>
        <form id="formEstadoUsuario" method="POST" onsubmit="return validarEstado()">
            <input type="hidden" name="accion" value="editar_estado">
            <input type="hidden" name="id_usuario" id="estado_id_usuario">

            <div class="form-group"><label>Nombre</label><input type="text" id="estado_nombre" readonly></div>
            <div class="form-group"><label>Cédula</label><input type="text" id="estado_cedula" readonly></div>
            <div class="form-group"><label>Rol</label><input type="text" id="estado_rol" readonly></div>
            <div class="form-group"><label>Estado actual</label><input type="text" id="estado_actual" readonly></div>

            <div class="form-group">
                <label>Nuevo Estado *</label>
                <select name="estado" id="nuevo_estado" required onchange="toggleMotivo(this.value)">
                    <option value="activo">✅ Activo</option>
                    <option value="inactivo">🚫 Inactivo</option>
                </select>
            </div>

            <div id="motivo-container">
                <div class="motivo-label"><i class="bi bi-chat-left-text-fill"></i> Motivo de inactivación *</div>
                <select name="motivo_inactivacion" id="motivo_inactivacion" class="form-control" onchange="toggleOtro(this.value)">
                    <option value="">— Seleccione un motivo —</option>
                    <?php foreach ($motivos_inactivacion as $m): ?>
                    <option value="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars($m); ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="motivo_otro_container">
                    <input type="text" name="motivo_otro" id="motivo_otro" class="form-control" placeholder="Describa el motivo..." maxlength="200" style="margin-top:8px;">
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalEstado()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnGuardarEstado">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<div id="modalEditarPerfil" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Editar Mi Perfil</h2>
            <button type="button" class="close-modal" onclick="cerrarModalPerfil()">&times;</button>
        </div>
        <form id="formEditarPerfil" method="POST" onsubmit="return validarPerfil()">
            <input type="hidden" name="accion" value="editar_perfil_admin">
            <input type="hidden" name="id_admin" value="<?php echo $usuario_id; ?>">
            <div class="form-group"><label>Nombre Completo *</label><input type="text" name="nombre" id="perfil_nombre" value="<?php echo htmlspecialchars($admin_actual['nombre'] ?? ''); ?>" required maxlength="100"></div>
            <div class="form-group"><label>Cédula</label><input type="text" value="<?php echo htmlspecialchars($admin_actual['cedula'] ?? ''); ?>" readonly><small style="color:#666;font-size:12px;">La cédula no se puede modificar</small></div>
            <div class="form-group"><label>Teléfono *</label><input type="text" name="telefono" id="perfil_telefono" value="<?php echo htmlspecialchars($admin_actual['telefono'] ?? ''); ?>" required maxlength="15"></div>
            <div class="form-group"><label>Email *</label><input type="email" name="email" id="perfil_email" value="<?php echo htmlspecialchars($admin_actual['email'] ?? ''); ?>" required maxlength="100"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalPerfil()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<div id="modalCambiarPassword" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Cambiar Contraseña</h2>
            <button type="button" class="close-modal" onclick="cerrarModalPassword()">&times;</button>
        </div>
        <form id="formCambiarPassword" method="POST" onsubmit="return validarPasswordCambio()">
            <input type="hidden" name="accion" value="cambiar_password_admin">
            <input type="hidden" name="id_admin" value="<?php echo $usuario_id; ?>">
            <div class="form-group"><label>Contraseña Actual *</label><input type="password" name="password_actual" id="password_actual" required placeholder="Contraseña actual"></div>
            <div class="form-group">
                <label>Nueva Contraseña *</label>
                <input type="password" name="password_nueva" id="password_nueva" required placeholder="Nueva contraseña">
                <div class="password-requirements"><strong>Requisitos:</strong><br>• Mínimo 8 caracteres<br>• Mayúscula, minúscula, número y símbolo</div>
            </div>
            <div class="form-group"><label>Confirmar Nueva Contraseña *</label><input type="password" name="password_confirm" id="password_confirm" required placeholder="Confirmar contraseña"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalPassword()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Cambiar Contraseña</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalCrearAdmin() { document.getElementById('modalCrearAdmin').classList.add('show'); document.getElementById('formCrearAdmin').reset(); }
function cerrarModalCrearAdmin() { document.getElementById('modalCrearAdmin').classList.remove('show'); }
function validarCrearAdmin() {
    const p = document.getElementById('admin_password').value;
    const err = [];
    if (p.length < 8) err.push('Mínimo 8 caracteres');
    if (!/[A-Z]/.test(p)) err.push('Una mayúscula');
    if (!/[a-z]/.test(p)) err.push('Una minúscula');
    if (!/[0-9]/.test(p)) err.push('Un número');
    if (!/[!@#$%^&*()\-_=+{};:,<.>]/.test(p)) err.push('Un símbolo');
    if (err.length) { alert('Contraseña inválida:\n• ' + err.join('\n• ')); return false; }
    return true;
}

function cambiarEstadoUsuario(u) {
    document.getElementById('modalEstadoUsuario').classList.add('show');
    document.getElementById('estado_id_usuario').value = u.id_usuario;
    document.getElementById('estado_nombre').value     = u.nombre;
    document.getElementById('estado_cedula').value     = u.cedula;
    document.getElementById('estado_rol').value        = u.rol;
    document.getElementById('estado_actual').value     = u.estado;
    document.getElementById('nuevo_estado').value      = u.estado;
    document.getElementById('motivo_inactivacion').value = '';
    document.getElementById('motivo_otro').value          = '';
    toggleMotivo(u.estado);
    toggleOtro('');
}
function cerrarModalEstado() { document.getElementById('modalEstadoUsuario').classList.remove('show'); }

function toggleMotivo(estado) {
    const cont = document.getElementById('motivo-container');
    if (estado === 'inactivo') {
        cont.classList.add('visible');
    } else {
        cont.classList.remove('visible');
        document.getElementById('motivo_inactivacion').value = '';
        document.getElementById('motivo_otro').value = '';
        toggleOtro('');
    }
}

function toggleOtro(val) {
    const oc = document.getElementById('motivo_otro_container');
    if (val === 'Otro') { oc.classList.add('visible'); }
    else                { oc.classList.remove('visible'); document.getElementById('motivo_otro').value = ''; }
}

function validarEstado() {
    const estado = document.getElementById('nuevo_estado').value;
    if (estado === 'inactivo') {
        const motivo = document.getElementById('motivo_inactivacion').value;
        if (!motivo) { alert('Por favor seleccione un motivo para inactivar la cuenta.'); return false; }
        if (motivo === 'Otro') {
            const otro = document.getElementById('motivo_otro').value.trim();
            if (!otro) { alert('Por favor describa el motivo de inactivación.'); return false; }
        }
    }
    return true;
}

function abrirModalEditarPerfil() { document.getElementById('modalEditarPerfil').classList.add('show'); }
function cerrarModalPerfil() { document.getElementById('modalEditarPerfil').classList.remove('show'); }
function validarPerfil() {
    const tel = document.getElementById('perfil_telefono').value;
    const email = document.getElementById('perfil_email').value;
    if (!/^[0-9]+$/.test(tel.replace(/\s/g,''))) { alert('El teléfono debe contener solo números'); return false; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { alert('Email inválido'); return false; }
    return true;
}

function abrirModalCambiarPassword() { document.getElementById('modalCambiarPassword').classList.add('show'); document.getElementById('formCambiarPassword').reset(); }
function cerrarModalPassword() { document.getElementById('modalCambiarPassword').classList.remove('show'); }
function validarPasswordCambio() {
    const pn = document.getElementById('password_nueva').value;
    const pc = document.getElementById('password_confirm').value;
    const err = [];
    if (pn.length < 8) err.push('Mínimo 8 caracteres');
    if (!/[A-Z]/.test(pn)) err.push('Una mayúscula');
    if (!/[a-z]/.test(pn)) err.push('Una minúscula');
    if (!/[0-9]/.test(pn)) err.push('Un número');
    if (!/[!@#$%^&*()\-_=+{};:,<.>]/.test(pn)) err.push('Un símbolo');
    if (pn !== pc) err.push('Las contraseñas no coinciden');
    if (err.length) { alert('Error:\n• ' + err.join('\n• ')); return false; }
    return true;
}

['modalCrearAdmin','modalEstadoUsuario','modalEditarPerfil','modalCambiarPassword'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('show');
    });
});

function toggleMenu() { document.getElementById('sidebar').classList.toggle('open'); }
document.addEventListener('click', e => {
    const sb = document.getElementById('sidebar');
    const mt = document.querySelector('.menu-toggle');
    if (window.innerWidth <= 768 && sb && !sb.contains(e.target) && mt && !mt.contains(e.target) && sb.classList.contains('open'))
        sb.classList.remove('open');
});

(function () {
    let timer;
   const input         = document.getElementById('inputBusqueda');
const selectRol     = document.getElementById('selectRol');
const selectEstado  = document.getElementById('selectEstado');
const tbody         = document.getElementById('tablaUsuariosTbody');

function buscar() {
    const busqueda = input.value;
    const rol      = selectRol.value;
    const estado   = selectEstado.value;
    const url      = `usuarios.php?busqueda=${encodeURIComponent(busqueda)}&rol=${encodeURIComponent(rol)}&estado=${encodeURIComponent(estado)}&solo_tabla=1`;

        fetch(url)
            .then(r => r.text())
            .then(html => { tbody.innerHTML = html; })
            .catch(() => {});
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(buscar, 200);
    });

    selectRol.addEventListener('change', buscar);
    selectEstado.addEventListener('change', buscar);
})();
</script>
</body>
</html>