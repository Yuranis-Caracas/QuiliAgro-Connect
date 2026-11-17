<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['user_rol'])) {
    _forzar_logout('Tu sesión ha expirado. Inicia sesión nuevamente.');
}

try {
    $__user_id = (int) $_SESSION['user_id'];

    if (!isset($conn) && !isset($__pdo_conn)) {
        $__base = __DIR__;                          
        if (file_exists($__base . '/database.php')) {
            require_once $__base . '/database.php';
            $__db       = new Database();
            $__pdo_conn = $__db->getConnection();  
        } elseif (file_exists($__base . '/conexion.php')) {
            require_once $__base . '/conexion.php'; 
        }
    }

    if (isset($__pdo_conn)) {
        $__stmt = $__pdo_conn->prepare(
            "SELECT estado FROM usuarios WHERE id_usuario = ? AND eliminado = 0 LIMIT 1"
        );
        $__stmt->execute([$__user_id]);
        $__fila = $__stmt->fetch(PDO::FETCH_ASSOC);

    } elseif (isset($conn)) {
        $__stmt = $conn->prepare("SELECT estado FROM usuarios WHERE id_usuario = ? LIMIT 1");
        $__stmt->bind_param('i', $__user_id);
        $__stmt->execute();
        $__fila = $__stmt->get_result()->fetch_assoc();

    } else {
        $__fila = ['estado' => 'activo'];
    }

    if (!$__fila) {
        _forzar_logout('Tu cuenta no fue encontrada. Contacta al administrador.');
    }

    $__estado_bd = $__fila['estado'];

    $_SESSION['user_estado'] = $__estado_bd;

    if ($__estado_bd === 'inactivo') {
        _forzar_logout(
            'Tu cuenta ha sido desactivada. Si crees que es un error, comunícate con el administrador.'
        );
    }

    if ($__estado_bd === 'suspendido') {
        _forzar_logout(
            'Tu cuenta ha sido suspendida temporalmente. Comunícate con el administrador.'
        );
    }

} catch (Throwable $__e) {
    error_log('[auth_check] Error verificando estado: ' . $__e->getMessage());
}

$__rol_actual = $_SESSION['user_rol'];

if (defined('ROL_REQUERIDO') && $__rol_actual !== ROL_REQUERIDO) {
    _redirigir_a_su_panel($__rol_actual);
}

if (defined('ROLES_REQUERIDOS') && !in_array($__rol_actual, ROLES_REQUERIDOS, true)) {
    _redirigir_a_su_panel($__rol_actual);
}


function _forzar_logout(string $mensaje): void {
    session_unset();
    session_destroy();
    session_start();                        
    $_SESSION['error_login'] = $mensaje;    
    header('Location: /quiliagro_connect/vistas/auth/login.php');
    exit();
}


function _redirigir_a_su_panel(string $rol): void {
    $destinos = [
        'administrador' => '/quiliagro_connect/vistas/admin/panel.php',
        'productor'     => '/quiliagro_connect/vistas/productor/panel_productor.php',
        'comprador'     => '/quiliagro_connect/vistas/comprador/panel_comprador.php',
    ];
    header('Location: ' . ($destinos[$rol] ?? '/quiliagro_connect/vistas/auth/login.php'));
    exit();
}