<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'comprador');
require_once __DIR__ . '/../../config/auth_check.php';

$token = $_GET['token'] ?? '';
$accion = $_GET['accion'] ?? '';

if (empty($token) || !in_array($accion, ['confirmar', 'rechazar'])) {
    die("Enlace inválido.");
}

$query = "SELECT t.*, p.id_pago, p.estado, u.email, u.nombre, o.id_orden
          FROM tokens_temp t
          INNER JOIN pagos p ON t.id_pago = p.id_pago
          INNER JOIN ordenes o ON p.id_orden = o.id_orden
          INNER JOIN usuarios u ON t.user_id = u.id_usuario
          WHERE t.token = ? AND t.tipo = 'confirmacion_pago' AND t.usado = 0 AND t.expira > NOW()";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("El enlace ha expirado o ya fue usado.");
}

$data = $result->fetch_assoc();

$update = "UPDATE tokens_temp SET usado = 1 WHERE id = ?";
$stmt_up = $conn->prepare($update);
$stmt_up->bind_param("i", $data['id']);
$stmt_up->execute();

$nuevo_estado = ($accion === 'confirmar') ? 'confirmado' : 'rechazado';
$update_pago = "UPDATE pagos SET estado = ?, fecha_confirmacion = NOW() WHERE id_pago = ?";
$stmt_pago = $conn->prepare($update_pago);
$stmt_pago->bind_param("si", $nuevo_estado, $data['id_pago']);
$stmt_pago->execute();

if ($accion === 'confirmar') {
    $update_orden = "UPDATE ordenes SET estado = 'pagado' WHERE id_orden = ?";
    $stmt_orden = $conn->prepare($update_orden);
    $stmt_orden->bind_param("i", $data['id_orden']);
    $stmt_orden->execute();
}

header("Location: resultado_pago.php?estado=$nuevo_estado&orden=" . $data['id_orden']);
exit();