<?php
session_start();
date_default_timezone_set('America/Bogota');
require_once __DIR__ . '/../../config/conexion.php';
define('ROL_REQUERIDO', 'comprador');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/mail.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'comprador') {
    header("Location: ../auth/login.php"); exit();
}

if (empty($_SESSION['verificacion_pago'])) {
    $_SESSION['mensaje_temporal']      = "No hay ningún pago pendiente de verificación.";
    $_SESSION['tipo_mensaje_temporal'] = "warning";
    header("Location: catalogo_comprador.php"); exit();
}

$vp           = &$_SESSION['verificacion_pago'];
$usuario_id   = intval($_SESSION['user_id']);
$error        = '';
$reenvio_ok   = false;
$MAX_INTENTOS = 5;

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
    } catch (Exception $e) { error_log('BD: ' . $e->getMessage()); return false; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['reenviar'])) {
        $nuevo_codigo      = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $vp['codigo_hash'] = password_hash($nuevo_codigo, PASSWORD_BCRYPT);
        $vp['expira']      = time() + 600;
        $vp['intentos']    = 0;

        $html_codigo = _emailCodigo(
            $vp['nombre'],
            $nuevo_codigo,
            $vp['referencia'],
            $vp['total'],
            $vp['id_orden']
        );
        enviarCorreo($vp['email'], $vp['nombre'],
            "Nuevo código de verificación — QuiliAgro Connect",
            $html_codigo);

        $reenvio_ok = true;

    } elseif (isset($_POST['verificar'])) {
        $ingresado = trim(implode('', $_POST['digit'] ?? []));
        $ingresado = preg_replace('/\D/', '', $ingresado);

        if (time() > $vp['expira']) {
            $error = 'El código ha expirado. Solicita uno nuevo.';

        } elseif ($vp['intentos'] >= $MAX_INTENTOS) {
            $error = 'Demasiados intentos fallidos. Solicita un nuevo código.';

        } elseif (!password_verify($ingresado, $vp['codigo_hash'])) {
            $vp['intentos']++;
            $restantes = $MAX_INTENTOS - $vp['intentos'];
            $error = "Código incorrecto. Te quedan $restantes intento" . ($restantes === 1 ? '' : 's') . ".";

        } else {
            try {
                $conn->begin_transaction();

                $check = $conn->prepare("SELECT id_pago, estado FROM pagos WHERE id_pago = ?");
                if (!$check) throw new Exception("Error al verificar pago: " . $conn->error);
                $check->bind_param('i', $vp['id_pago']);
                $check->execute();
                $row = $check->get_result()->fetch_assoc();

                if (!$row) {
                    throw new Exception("No se encontró el pago #" . $vp['id_pago'] . ". Intenta realizar el pago de nuevo.");
                }
                if ($row['estado'] === 'confirmado') {
                    $conn->rollback();
                    $_SESSION['pago_aprobado'] = [
                        'referencia' => $vp['referencia'],
                        'id_orden'   => $vp['id_orden'],
                        'total'      => $vp['total'],
                        'tipo'       => $vp['tipo'],
                    ];
                    unset($_SESSION['verificacion_pago']);
                    header("Location: pago_aprobado.php"); exit();
                }

                $stmt = $conn->prepare("UPDATE pagos SET estado='confirmado', fecha_confirmacion=NOW() WHERE id_pago=?");
                if (!$stmt) throw new Exception("Error al preparar confirmación: " . $conn->error);
                $stmt->bind_param('i', $vp['id_pago']);
                if (!$stmt->execute()) throw new Exception("Error al ejecutar confirmación: " . $stmt->error);
                if ($stmt->affected_rows === 0) throw new Exception("El pago no pudo actualizarse. Contacta soporte.");

                $stmt2 = $conn->prepare("UPDATE ordenes SET estado='pagado' WHERE id_orden=?");
                if (!$stmt2) throw new Exception("Error al preparar orden: " . $conn->error);
                $stmt2->bind_param('i', $vp['id_orden']);
                if (!$stmt2->execute()) throw new Exception("Error al actualizar orden: " . $stmt2->error);


                $conn->commit();

                $_SESSION['pago_aprobado'] = [
                    'referencia' => $vp['referencia'],
                    'id_orden'   => $vp['id_orden'],
                    'total'      => $vp['total'],
                    'tipo'       => $vp['tipo'],
                ];

                $st_num2 = $conn->prepare("
                    SELECT numero_orden FROM (
                        SELECT id_orden,
                              ROW_NUMBER() OVER (PARTITION BY id_comprador ORDER BY fecha) AS numero_orden
                        FROM ordenes WHERE id_comprador = ?
                    ) t WHERE id_orden = ?
                ");
                $st_num2->bind_param('ii', $usuario_id, $vp['id_orden']);
                $st_num2->execute();
                $row_num2    = $st_num2->get_result()->fetch_assoc();
                $num_orden_u = $row_num2 ? $row_num2['numero_orden'] : $vp['id_orden'];

                enviarCorreo(
                    $vp['email'],
                    $vp['nombre'],
                    "¡Pago confirmado! — Pedido #$num_orden_u",
                    _emailAprobado($vp['nombre'], $vp['referencia'], $vp['total'], $num_orden_u)
                );

                unset($_SESSION['verificacion_pago']);
                header("Location: pago_aprobado.php"); exit();

            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

$minutos_restantes = max(0, intval(ceil(($vp['expira'] - time()) / 60)));
$email_enmascarado = '';
if (!empty($vp['email'])) {
    $parts            = explode('@', $vp['email']);
    $user_part        = $parts[0];
    $domain           = $parts[1] ?? '';
    $email_enmascarado = substr($user_part, 0, 3) . str_repeat('*', max(0, strlen($user_part) - 3)) . '@' . $domain;
}

function _emailCodigo($nombre, $codigo, $referencia, $total, $id_orden) {
    $n   = htmlspecialchars(trim($nombre));
    $ref = htmlspecialchars($referencia);
    $tv  = '$ ' . number_format($total, 0, '.', ',');
    $d   = str_split($codigo);
    $cajas = '';
    foreach ($d as $digito) {
        $cajas .= "<td style='width:44px;height:54px;border:2px solid #c8e6c9;border-radius:10px;background:#f1f8e9;text-align:center;vertical-align:middle;font-size:28px;font-weight:900;color:#1b5e20;font-family:monospace;'>$digito</td>";
    }
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f0f5f0;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f5f0;padding:24px 0;'>
<tr><td align='center'>
<table width='520' cellpadding='0' cellspacing='0' style='max-width:520px;width:100%;'>
  <tr><td style='background:#2e7d32;border-radius:10px 10px 0 0;padding:20px 28px;text-align:center;'>
    <div style='font-size:18px;font-weight:800;color:#fff;'>QuiliAgro Connect</div>
    <div style='font-size:13px;color:rgba(255,255,255,.75);margin-top:4px;'>Verificación de pago</div>
  </td></tr>
  <tr><td style='background:#fff;padding:36px 28px;text-align:center;'>
    <p style='font-size:19px;color:#222;margin:0 0 6px;'>Hola <strong style='color:#2e7d32;'>$n</strong> 👋</p>
    <p style='font-size:13px;color:#666;margin:0 0 28px;'>Ingresa este código para confirmar tu pago</p>
    <table cellpadding='0' cellspacing='6' style='margin:0 auto 24px;'><tr>$cajas</tr></table>
    <p style='font-size:12px;color:#e65100;font-weight:700;margin:0 0 24px;'>⏱ Válido por 10 minutos</p>
    <table cellpadding='0' cellspacing='0' style='width:100%;border:1px solid #e0e0e0;border-radius:10px;overflow:hidden;'>
      <tr style='background:#f5f5f5;'><td style='padding:10px 16px;font-size:12px;color:#777;border-bottom:1px solid #eee;'>Pedido</td><td style='padding:10px 16px;font-size:13px;font-weight:700;color:#222;text-align:right;border-bottom:1px solid #eee;'>#$id_orden</td></tr>
      <tr style='background:#f5f5f5;'><td style='padding:10px 16px;font-size:12px;color:#777;border-bottom:1px solid #eee;'>Referencia</td><td style='padding:10px 16px;font-size:12px;font-weight:700;color:#222;text-align:right;font-family:monospace;border-bottom:1px solid #eee;'>$ref</td></tr>
      <tr><td style='padding:10px 16px;font-size:12px;color:#777;'>Total a pagar</td><td style='padding:10px 16px;font-size:20px;font-weight:800;color:#2e7d32;text-align:right;'>$tv</td></tr>
    </table>
    <p style='font-size:11px;color:#aaa;margin-top:20px;'>Si no realizaste esta solicitud, ignora este mensaje.</p>
  </td></tr>
  <tr><td style='background:#f8fbf8;border-top:1px solid #d6e8d6;border-radius:0 0 10px 10px;padding:14px;text-align:center;font-size:11px;color:#888;'>
    Mensaje automático de <strong>QuiliAgro Connect</strong>.
  </td></tr>
</table></td></tr></table></body></html>";
}

function _emailAprobado($n, $r, $t, $o) {
    $tv = '$ ' . number_format($t, 0, '.', ',');
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='font-family:Arial,sans-serif;background:#f5f7fa;margin:0;padding:20px;'>
<div style='max-width:540px;margin:auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1);'>
  <div style='background:linear-gradient(135deg,#2e7d32,#43a047);padding:30px;text-align:center;'>
    <div style='font-size:48px;margin-bottom:8px;'>✅</div>
    <h1 style='color:#fff;margin:0;font-size:21px;'>¡Pago Confirmado!</h1>
  </div>
  <div style='padding:30px;'>
    <p style='font-size:16px;'>Hola <strong>" . htmlspecialchars($n) . "</strong>, tu pago fue verificado exitosamente.</p>
    <div style='background:#f1f8e9;border:2px solid #66bb6a;border-radius:10px;padding:18px;text-align:center;margin:18px 0;'>
      <p style='margin:0 0 4px;font-size:13px;color:#555;'>Pedido: <strong>#$o</strong> · Ref: <strong>" . htmlspecialchars($r) . "</strong></p>
      <p style='margin:0;font-size:28px;font-weight:800;color:#2e7d32;'>$tv</p>
    </div>
    <p style='font-size:13px;color:#666;'>Tu pedido está siendo procesado. Los productores recibirán la notificación de tu compra.</p>
  </div>
  <div style='background:#f8f9fa;padding:14px;text-align:center;font-size:11px;color:#aaa;'>
    QuiliAgro Connect &copy; " . date('Y') . "
  </div>
</div></body></html>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verificar Código — QuiliAgro Connect</title>
<link rel="icon" type="image/png" href="../../imagenes/logo.png">
<link rel="stylesheet" href="../../css/styles.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f0f5f0;--white:#ffffff;--surface:#f8fbf8;--border:#d6e8d6;
  --green:#2e7d32;--green-l:#43a047;--green-xl:#e8f5e9;
  --text:#1b2e1b;--muted:#6b8c6b;
  --red:#c62828;--red-l:#ffebee;--orange:#e65100;--orange-l:#fff3e0;
  --radius:16px;--radius-sm:10px;
  --shadow:0 2px 12px rgba(46,125,50,.08);--shadow-md:0 8px 32px rgba(46,125,50,.16);
}
*{box-sizing:border-box;}
body{background:var(--bg);font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);margin:0;min-height:100vh;display:flex;flex-direction:column;}
.hdr{background:var(--white);border-bottom:2px solid var(--border);padding:13px 28px;display:flex;align-items:center;gap:16px;box-shadow:var(--shadow);}
.hdr-logo{height:38px;}
.hdr-brand h1{margin:0;font-size:15px;font-weight:800;color:var(--green);}
.hdr-brand p{margin:1px 0 0;font-size:11px;color:var(--muted);}
.hdr-ssl{margin-left:auto;background:var(--green-xl);border:1.5px solid var(--border);color:var(--green);font-size:11px;font-weight:700;padding:6px 14px;border-radius:20px;display:flex;align-items:center;gap:6px;}

.page-wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:32px 16px 60px;}

.verify-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:40px 36px;max-width:460px;width:100%;box-shadow:var(--shadow-md);text-align:center;}

.steps{display:flex;align-items:center;gap:4px;justify-content:center;margin-bottom:32px;}
.stp{display:flex;align-items:center;gap:5px;}
.stp-n{width:24px;height:24px;border-radius:50%;background:#e8ece8;border:2px solid #cdd9cd;font-size:10px;font-weight:800;color:#9aaa9a;display:flex;align-items:center;justify-content:center;}
.stp.done .stp-n{background:var(--green);border-color:var(--green);color:white;}
.stp.active .stp-n{background:var(--green-l);border-color:var(--green-l);color:white;box-shadow:0 0 0 3px rgba(67,160,71,.25);}
.stp-l{font-size:11px;font-weight:600;color:#aabcaa;}
.stp.done .stp-l{color:var(--green);}
.stp.active .stp-l{color:var(--text);}
.stp-sep{width:20px;height:2px;background:#dce8dc;border-radius:1px;}
.stp.done+.stp-sep{background:var(--green);}

.verify-icon{width:72px;height:72px;background:var(--green-xl);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;border:3px solid var(--border);}
.verify-icon i{font-size:32px;color:var(--green);}

.verify-title{font-size:22px;font-weight:800;color:var(--text);margin:0 0 8px;}
.verify-sub{font-size:13px;color:var(--muted);line-height:1.6;margin:0 0 6px;}
.verify-email{font-size:13px;font-weight:700;color:var(--green);margin:0 0 28px;}

.otp-wrap{display:flex;gap:10px;justify-content:center;margin-bottom:8px;}
.otp-input{width:52px;height:62px;border:2.5px solid var(--border);border-radius:12px;font-size:26px;font-weight:900;font-family:'JetBrains Mono',monospace;text-align:center;background:var(--surface);color:var(--text);transition:all .18s;outline:none;caret-color:var(--green);}
.otp-input:focus{border-color:var(--green);background:var(--white);box-shadow:0 0 0 3px rgba(46,125,50,.15);}
.otp-input.filled{border-color:var(--green-l);background:var(--green-xl);color:var(--green);}
.otp-input.error{border-color:var(--red);background:var(--red-l);color:var(--red);}

.timer-wrap{display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;color:var(--muted);margin-bottom:24px;}
.timer-badge{background:var(--orange-l);border:1px solid rgba(230,81,0,.2);color:var(--orange);border-radius:20px;padding:3px 10px;font-size:12px;font-weight:700;}
.timer-badge.expired{background:var(--red-l);border-color:rgba(198,40,40,.2);color:var(--red);}

.alert{border-radius:10px;padding:11px 14px;font-size:13px;margin-bottom:16px;display:flex;align-items:flex-start;gap:9px;line-height:1.5;text-align:left;}
.alert i{flex-shrink:0;margin-top:1px;}
.alert.error{background:var(--red-l);border:1.5px solid rgba(198,40,40,.25);color:var(--red);}
.alert.success{background:var(--green-xl);border:1.5px solid rgba(46,125,50,.25);color:var(--green);}

.btn-verify{width:100%;padding:14px;border:none;border-radius:var(--radius-sm);font-size:15px;font-weight:800;cursor:pointer;background:linear-gradient(135deg,var(--green),var(--green-l));color:white;box-shadow:0 4px 18px rgba(46,125,50,.3);font-family:'Plus Jakarta Sans',sans-serif;transition:all .18s;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:12px;}
.btn-verify:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 26px rgba(46,125,50,.4);}
.btn-verify:disabled{opacity:.45;cursor:not-allowed;transform:none;}
.btn-resend{width:100%;padding:10px;border:2px solid var(--border);border-radius:var(--radius-sm);font-size:13px;font-weight:700;cursor:pointer;background:white;color:var(--muted);font-family:'Plus Jakarta Sans',sans-serif;transition:all .18s;display:flex;align-items:center;justify-content:center;gap:7px;margin-bottom:12px;}
.btn-resend:hover:not(:disabled){border-color:var(--green);color:var(--green);}
.btn-resend:disabled{opacity:.4;cursor:not-allowed;}

.orden-mini{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:20px;text-align:left;}
.om-row{display:flex;justify-content:space-between;font-size:13px;padding:3px 0;}
.om-label{color:var(--muted);}
.om-val{font-weight:700;color:var(--text);font-family:'JetBrains Mono',monospace;font-size:12px;}
.om-total{font-size:16px;font-weight:900;color:var(--green);font-family:'Plus Jakarta Sans',sans-serif;}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;}
.modal-overlay.on{display:flex;}
.modal-box{background:var(--white);border-radius:20px;padding:44px 36px;text-align:center;max-width:340px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:pop .3s ease;}
@keyframes pop{from{opacity:0;transform:scale(.9);}to{opacity:1;transform:scale(1);}}
.spinner{width:64px;height:64px;border:5px solid rgba(46,125,50,.15);border-top:5px solid var(--green);border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 18px;}
@keyframes spin{to{transform:rotate(360deg);}}
.modal-box h3{margin:0 0 8px;font-size:18px;color:var(--text);}
.modal-box p{color:var(--muted);font-size:13px;margin:0;}

@media(max-width:500px){.otp-input{width:42px;height:52px;font-size:22px;}.otp-wrap{gap:7px;}.verify-card{padding:28px 20px;}}
</style>
</head>
<body>

<header class="hdr">
  <img src="../../imagenes/logo.png" alt="QuiliAgro" class="hdr-logo">
  <div class="hdr-brand"><h1>QuiliAgro Connect</h1><p>Verificación de Pago</p></div>
  <div class="hdr-ssl"><i class="bi bi-shield-check-fill"></i> SSL Seguro</div>
</header>

<div class="modal-overlay" id="modalProc">
  <div class="modal-box">
    <div class="spinner"></div>
    <h3>Confirmando pago...</h3>
    <p>Un momento por favor</p>
  </div>
</div>

<div class="page-wrap">
  <div class="verify-card">

    <div class="steps">
      <div class="stp done"><div class="stp-n"><i class="bi bi-check-lg" style="font-size:9px;"></i></div><div class="stp-l">Datos</div></div>
      <div class="stp-sep"></div>
      <div class="stp active"><div class="stp-n">4</div><div class="stp-l">Código</div></div>
      <div class="stp-sep"></div>
      <div class="stp"><div class="stp-n">5</div><div class="stp-l">Listo</div></div>
    </div>

    <div class="verify-icon"><i class="bi bi-envelope-check-fill"></i></div>
    <div class="verify-title">Revisa tu correo</div>
    <div class="verify-sub">Enviamos un código de 6 dígitos a</div>
    <div class="verify-email"><?php echo htmlspecialchars($email_enmascarado); ?></div>

    <div class="orden-mini">
      <div class="om-row"><span class="om-label">Referencia</span><span class="om-val"><?php echo htmlspecialchars($vp['referencia']); ?></span></div>
      <div class="om-row" style="margin-top:6px;border-top:1px solid var(--border);padding-top:8px;">
        <span class="om-label" style="font-weight:700;">Total a pagar</span>
        <span class="om-total">$<?php echo number_format($vp['total'], 0, '.', ','); ?></span>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="alert error" id="alertErr">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?php echo htmlspecialchars($error); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($reenvio_ok): ?>
    <div class="alert success">
      <i class="bi bi-check-circle-fill"></i>
      <span>Nuevo código enviado a tu correo. Válido por 10 minutos.</span>
    </div>
    <?php endif; ?>

    <div class="timer-wrap">
      <i class="bi bi-clock"></i>
      Código válido por:
      <span class="timer-badge" id="timerBadge">
        <?php echo $minutos_restantes; ?> min
      </span>
    </div>

    <form method="POST" action="verificar_codigo.php" id="formVerify" novalidate>
      <input type="hidden" name="verificar" value="1">
      <div class="otp-wrap" id="otpWrap">
        <?php for ($i = 0; $i < 6; $i++): ?>
        <input type="text" name="digit[]" class="otp-input" id="d<?php echo $i; ?>"
               maxlength="1" inputmode="numeric" pattern="[0-9]"
               autocomplete="<?php echo $i === 0 ? 'one-time-code' : 'off'; ?>"
               required>
        <?php endfor; ?>
      </div>

      <button type="submit" class="btn-verify" id="btnVerify" disabled>
        <i class="bi bi-check-circle-fill"></i> Confirmar Código
      </button>
    </form>

    <form method="POST" action="verificar_codigo.php" id="formReenviar">
      <button type="submit" name="reenviar" class="btn-resend" id="btnReenviar" disabled>
        <i class="bi bi-arrow-clockwise"></i> <span id="resendText">Reenviar código</span>
      </button>
    </form>

    <a href="pasarela_pago.php" style="font-size:12px;color:var(--muted);text-decoration:none;display:block;margin-top:4px;">
      <i class="bi bi-arrow-left"></i> Cancelar y volver al pago
    </a>

  </div>
</div>

<script>
const EXPIRA_TS   = <?php echo $vp['expira']; ?>;
const inputs      = Array.from(document.querySelectorAll('.otp-input'));
const btnVerify   = document.getElementById('btnVerify');
const btnReenviar = document.getElementById('btnReenviar');
const timerBadge  = document.getElementById('timerBadge');
const formVerify  = document.getElementById('formVerify');

inputs.forEach((inp, idx) => {
  inp.addEventListener('input', (e) => {
    inp.value = inp.value.replace(/\D/g,'').slice(-1);
    inp.classList.toggle('filled', inp.value !== '');
    if (inp.value && idx < inputs.length - 1) inputs[idx + 1].focus();
    checkComplete();
  });

  inp.addEventListener('keydown', (e) => {
    if (e.key === 'Backspace' && !inp.value && idx > 0) {
      inputs[idx - 1].focus();
      inputs[idx - 1].value = '';
      inputs[idx - 1].classList.remove('filled');
      checkComplete();
    }
    if (e.key === 'ArrowLeft'  && idx > 0)                inputs[idx-1].focus();
    if (e.key === 'ArrowRight' && idx < inputs.length-1)  inputs[idx+1].focus();
  });

  inp.addEventListener('paste', (e) => {
    e.preventDefault();
    const pasted = (e.clipboardData?.getData('text') || '').replace(/\D/g,'').slice(0, 6);
    pasted.split('').forEach((ch, i) => {
      if (inputs[i]) { inputs[i].value = ch; inputs[i].classList.add('filled'); }
    });
    const nextEmpty = inputs.findIndex(el => !el.value);
    (inputs[nextEmpty] || inputs[5]).focus();
    checkComplete();
  });
});

function checkComplete() {
  btnVerify.disabled = !inputs.every(i => i.value !== '');
}

function startResendCooldown(seconds) {
  btnReenviar.disabled = true;
  let remaining = seconds;
  const resendText = document.getElementById('resendText');
  resendText.textContent = `Reenviar en ${remaining}s`;
  const t = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
      clearInterval(t);
      btnReenviar.disabled = false;
      resendText.textContent = 'Reenviar código';
    } else {
      resendText.textContent = `Reenviar en ${remaining}s`;
    }
  }, 1000);
}

function updateTimer() {
  const secsLeft = EXPIRA_TS - Math.floor(Date.now() / 1000);
  if (secsLeft <= 0) {
    timerBadge.textContent = 'Expirado';
    timerBadge.classList.add('expired');
    btnVerify.disabled = true;
    inputs.forEach(i => { i.disabled = true; i.classList.add('error'); });
    return;
  }
  const mins = Math.floor(secsLeft / 60);
  const secs = secsLeft % 60;
  timerBadge.textContent = `${mins}:${secs.toString().padStart(2,'0')}`;
  setTimeout(updateTimer, 1000);
}

formVerify.addEventListener('submit', (e) => {
  if (!inputs.every(i => i.value !== '')) { e.preventDefault(); return; }
  const alertEl = document.getElementById('alertErr');
  if (alertEl) alertEl.remove();
  btnVerify.disabled = true;
  document.getElementById('modalProc').classList.add('on');
});

updateTimer();
startResendCooldown(30);
inputs[0].focus();

<?php if ($reenvio_ok): ?>
startResendCooldown(60);
<?php endif; ?>
</script>
</body>
</html>