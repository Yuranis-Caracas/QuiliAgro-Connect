<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mail.php';

if (isset($_GET['cancelar'])) {
    unset($_SESSION['recuperacion']);
    header('Location: login.php');
    exit();
}

$error = '';
$paso  = 'solicitud';

if (!empty($_SESSION['recuperacion'])) {
    $rec = &$_SESSION['recuperacion'];

    if (time() > $rec['expira']) {
        unset($_SESSION['recuperacion']);
        $paso  = 'solicitud';
        $error = 'El código expiró. Solicita uno nuevo.';
    } elseif ($rec['intentos'] >= 5) {
        unset($_SESSION['recuperacion']);
        $paso  = 'solicitud';
        $error = 'Demasiados intentos fallidos. Solicita un nuevo código.';
    } elseif ($rec['verificado']) {
        $paso = 'password';
    } else {
        $paso = 'codigo';
    }
}

// ═══════════════════════════════════════════════════════════════════
// PASO 0 — Solicitar código
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar_codigo'])) {
    $cedula = trim($_POST['cedula'] ?? '');
    $email  = trim($_POST['email']  ?? '');

    if (empty($cedula) || empty($email)) {
        $error = 'Por favor completa todos los campos.';
    } elseif (!preg_match('/^[0-9]+$/', $cedula)) {
        $error = 'La cédula solo debe contener números.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no tiene un formato válido.';
    } else {
        try {
            $database = new Database();
            $conn = $database->getConnection();

            $stmt = $conn->prepare(
                "SELECT id_usuario, nombre, email FROM usuarios
                 WHERE cedula = :cedula AND email = :email
                   AND eliminado = 0 AND estado = 'activo'"
            );
            $stmt->bindParam(':cedula', $cedula);
            $stmt->bindParam(':email',  $email);
            $stmt->execute();

            if ($stmt->rowCount() === 1) {
                $user   = $stmt->fetch(PDO::FETCH_ASSOC);
                $codigo = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

                $_SESSION['recuperacion'] = [
                    'id_usuario' => $user['id_usuario'],
                    'nombre'     => $user['nombre'],
                    'email'      => $user['email'],
                    'codigo'     => $codigo,
                    'expira'     => time() + 600,
                    'intentos'   => 0,
                    'verificado' => false,
                ];
                $rec = &$_SESSION['recuperacion'];

                $nombreEsc  = htmlspecialchars($user['nombre']);
                $cuerpoHTML = "<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4f6f9;font-family:Segoe UI,Arial,sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f6f9;padding:30px 0;'>
    <tr><td align='center'>
      <table width='480' cellpadding='0' cellspacing='0'
             style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);'>
        <tr>
          <td style='background:linear-gradient(135deg,#28a745,#20c997);padding:32px 40px;text-align:center;'>
            <h1 style='color:#fff;margin:0;font-size:22px;font-weight:700;'>🌿 QuiliAgro Connect</h1>
            <p style='color:rgba(255,255,255,.85);margin:8px 0 0;font-size:14px;'>Recuperación de contraseña</p>
          </td>
        </tr>
        <tr>
          <td style='padding:36px 40px;'>
            <p style='color:#333;font-size:16px;margin:0 0 12px;'>Hola, <strong>{$nombreEsc}</strong></p>
            <p style='color:#555;font-size:14px;line-height:1.6;margin:0 0 28px;'>
              Recibimos una solicitud para restablecer tu contraseña. Usa el siguiente código:
            </p>
            <div style='background:#f0faf4;border:2px dashed #28a745;border-radius:10px;padding:24px;text-align:center;margin-bottom:28px;'>
              <p style='color:#888;font-size:12px;margin:0 0 8px;text-transform:uppercase;letter-spacing:1px;'>Código de verificación</p>
              <span style='font-size:42px;font-weight:800;letter-spacing:10px;color:#28a745;'>{$codigo}</span>
              <p style='color:#999;font-size:12px;margin:12px 0 0;'>⏱ Válido por <strong>10 minutos</strong></p>
            </div>
            <p style='color:#888;font-size:13px;line-height:1.6;margin:0;'>
              Si no solicitaste este cambio, puedes ignorar este correo.
            </p>
          </td>
        </tr>
        <tr>
          <td style='background:#f8f9fa;padding:20px 40px;text-align:center;border-top:1px solid #eee;'>
            <p style='color:#aaa;font-size:12px;margin:0;'>© " . date('Y') . " QuiliAgro Connect — No respondas este correo.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>";

                $resultado = enviarCorreo(
                    $user['email'],
                    $user['nombre'],
                    'Código de verificación - QuiliAgro Connect',
                    $cuerpoHTML
                );

                if ($resultado['success']) {
                    $paso = 'codigo';
                } else {
                    unset($_SESSION['recuperacion']);
                    $error = 'No se pudo enviar el correo. Intenta de nuevo más tarde.';
                    error_log('Mail recuperación: ' . $resultado['message']);
                }
            } else {
                $error = 'No encontramos una cuenta activa con esa cédula y correo. Verifica los datos ingresados.';
            }
        } catch (PDOException $e) {
            $error = 'Error en el sistema. Intenta más tarde.';
            error_log('Recuperación DB: ' . $e->getMessage());
        }
    }
}

// ═══════════════════════════════════════════════════════════════════
// PASO 1 — Verificar código 
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verificar_codigo']) && !empty($_SESSION['recuperacion'])) {
    $rec = &$_SESSION['recuperacion'];
    $codigoIngresado = trim(implode('', $_POST['digit'] ?? []));

    if (strlen($codigoIngresado) !== 6 || !ctype_digit($codigoIngresado)) {
        $error = 'Ingresa el código completo de 6 dígitos.';
        $paso  = 'codigo';
    } elseif ($codigoIngresado !== (string)$rec['codigo']) {
        $rec['intentos']++;
        $restantes = 5 - $rec['intentos'];
        if ($restantes > 0) {
            $error = "Código incorrecto. Te quedan {$restantes} intento" . ($restantes === 1 ? '' : 's') . '.';
        } else {
            unset($_SESSION['recuperacion']);
            $error = 'Demasiados intentos fallidos. Solicita un nuevo código.';
            $paso  = 'solicitud';
        }
        if ($paso !== 'solicitud') $paso = 'codigo';
    } else {
        $rec['verificado'] = true;
        $paso = 'password';
    }
}

// ═══════════════════════════════════════════════════════════════════
// PASO 2 — Guardar nueva contraseña
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_password']) && !empty($_SESSION['recuperacion']['verificado'])) {
    $rec          = &$_SESSION['recuperacion'];
    $nueva        = $_POST['nueva_password']     ?? '';
    $confirmacion = $_POST['confirmar_password'] ?? '';

    if (strlen($nueva) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif (!preg_match('/[A-Z]/', $nueva)) {
        $error = 'Debe incluir al menos una letra mayúscula.';
    } elseif (!preg_match('/[a-z]/', $nueva)) {
        $error = 'Debe incluir al menos una letra minúscula.';
    } elseif (!preg_match('/[0-9]/', $nueva)) {
        $error = 'Debe incluir al menos un número.';
    } elseif (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $nueva)) {
        $error = 'Debe incluir al menos un símbolo (!@#$%^&*()-_=+{};:,<.>).';
    } elseif ($nueva !== $confirmacion) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        try {
            $database = new Database();
            $conn     = $database->getConnection();
            $hash     = password_hash($nueva, PASSWORD_BCRYPT);

            $stmt = $conn->prepare(
                "UPDATE usuarios SET password = :pwd WHERE id_usuario = :id AND eliminado = 0"
            );
            $stmt->bindParam(':pwd', $hash);
            $stmt->bindParam(':id',  $rec['id_usuario'], PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 1) {
                unset($_SESSION['recuperacion']);
                $paso = 'exito';
            } else {
                $error = 'No se pudo actualizar la contraseña. Intenta de nuevo.';
            }
        } catch (PDOException $e) {
            $error = 'Error en el sistema. Intenta más tarde.';
            error_log('nueva_password DB: ' . $e->getMessage());
        }
    }

    if ($error) $paso = 'password';
}


$segundosRestantes = 0;
$emailOculto       = '';

if (!empty($_SESSION['recuperacion'])) {
    $rec = $_SESSION['recuperacion'];
    $segundosRestantes = max(0, $rec['expira'] - time());

    if (!empty($rec['email'])) {
        [$u, $d]    = explode('@', $rec['email']);
        $emailOculto = substr($u, 0, 2)
                     . str_repeat('*', max(strlen($u) - 3, 2))
                     . substr($u, -1) . '@' . $d;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php
        echo match($paso) {
            'solicitud' => 'Recuperar Contraseña',
            'codigo'    => 'Verificar Código',
            'password'  => 'Nueva Contraseña',
            default     => '¡Contraseña Actualizada!'
        };
        ?> - QuiliAgro Connect
    </title>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/quiliagro_connect/config/pwa_head.php'; ?>
    <link rel="icon" type="image/png" sizes="192x192" href="/quiliagro_connect/imagenes/icon-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/quiliagro_connect/imagenes/icon-512x512.png">
    <link rel="apple-touch-icon" href="/quiliagro_connect/imagenes/apple-touch-icon.png">
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
            background:linear-gradient(135deg,#e4e5e9 0%,#edebf0 100%);
            min-height:100vh; display:flex; align-items:center;
            justify-content:center; padding:20px;
        }
        .card { background:#fff; border-radius:16px; box-shadow:0 15px 40px rgba(0,0,0,.15); width:100%; max-width:430px; overflow:hidden; }

        .card-header { background:linear-gradient(135deg,#28a745,#20c997); padding:28px; text-align:center; }
        .header-icon {
            width:70px; height:70px; background:rgba(255,255,255,.25); border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            margin:0 auto 12px; font-size:28px; color:#fff;
        }
        .logo-img {
            width:70px; height:70px; object-fit:contain; border-radius:50%;
            background:#fff; padding:8px; box-shadow:0 4px 14px rgba(0,0,0,.2);
            border:3px solid rgba(255,255,255,.4); margin-bottom:12px;
        }
        .card-header h1 { color:#fff; font-size:21px; font-weight:700; }
        .card-header p  { color:rgba(255,255,255,.85); font-size:13px; margin-top:5px; }

        .steps { display:flex; align-items:center; padding:16px 28px 0; background:#fff; }
        .step-item { display:flex; flex-direction:column; align-items:center; gap:4px; flex:1; }
        .step-num {
            width:30px; height:30px; border-radius:50%; border:2px solid #ddd;
            display:flex; align-items:center; justify-content:center;
            font-size:12px; font-weight:700; color:#bbb; background:#fff; transition:all .3s;
        }
        .step-num.active { border-color:#28a745; color:#28a745; }
        .step-num.done   { border-color:#28a745; background:#28a745; color:#fff; }
        .step-label { font-size:11px; color:#bbb; white-space:nowrap; }
        .step-label.active, .step-label.done { color:#28a745; font-weight:600; }
        .step-line { flex:1; height:2px; background:#eee; margin:0 4px 16px; transition:background .3s; }
        .step-line.done { background:#28a745; }

        .card-body { padding:26px 28px; }

        .alert {
            display:flex; align-items:flex-start; gap:10px; padding:12px 16px;
            border-radius:8px; margin-bottom:20px; font-size:14px;
        }
        .alert i { margin-top:2px; flex-shrink:0; }
        .alert-error { background:#fff0f0; color:#c0392b; border-left:4px solid #c0392b; }

        .info-box {
            background:#f0faf4; border-left:4px solid #28a745; border-radius:8px;
            padding:12px 16px; margin-bottom:22px; font-size:13px;
            color:#2d6a3f; display:flex; gap:10px; align-items:flex-start;
        }
        .info-box i { margin-top:2px; flex-shrink:0; }

        .form-group { margin-bottom:16px; }
        .form-group label { display:block; font-size:13px; font-weight:600; color:#444; margin-bottom:7px; }
        .form-group label .req { color:#e74c3c; }

        .input-wrap { position:relative; }
        .input-wrap .ico {
            position:absolute; left:14px; top:50%; transform:translateY(-50%);
            color:#bbb; font-size:14px; pointer-events:none;
        }
        .input-wrap input {
            width:100%; padding:12px 44px 12px 42px;
            border:2px solid #ddd; border-radius:9px;
            font-size:15px; color:#333;
            transition:border-color .25s, box-shadow .25s;
        }
        .input-wrap input:focus { outline:none; border-color:#28a745; box-shadow:0 0 0 3px rgba(40,167,69,.12); }

        .input-wrap input.is-valid   { border-color:#28a745 !important; }
        .input-wrap input.is-invalid { border-color:#e74c3c !important; }

        .val-icon {
            position:absolute; right:14px; top:50%; transform:translateY(-50%);
            font-size:14px; display:none; pointer-events:none;
        }
        .val-icon.show-ok  { display:block; color:#28a745; }
        .val-icon.show-err { display:block; color:#e74c3c; }

        .toggle-pwd {
            position:absolute; right:14px; top:50%; transform:translateY(-50%);
            background:none; border:none; cursor:pointer; color:#bbb; font-size:15px;
        }
        .toggle-pwd:hover { color:#28a745; }

        .field-error { font-size:11.5px; color:#e74c3c; margin-top:4px; display:none; }
        .field-error.visible { display:block; }

        .pw-reqs { margin-top:7px; }
        .req-item {
            display:flex; align-items:center; gap:6px;
            font-size:11.5px; margin-bottom:2px; color:#bbb;
        }
        .req-item i { font-size:10px; width:12px; }
        .req-item.ok  { color:#28a745; }
        .req-item.bad { color:#e74c3c; }

        .match-msg { font-size:12px; margin-top:5px; min-height:16px; }

        .otp-info { text-align:center; margin-bottom:20px; }
        .otp-info p { color:#666; font-size:14px; line-height:1.5; }
        .otp-info strong { color:#28a745; }
        .otp-inputs { display:flex; gap:10px; justify-content:center; margin-bottom:8px; }
        .otp-inputs input {
            width:48px; height:58px; text-align:center; font-size:24px; font-weight:700;
            border:2px solid #ddd; border-radius:10px; color:#28a745;
            transition:border-color .2s, box-shadow .2s, background .2s;
            caret-color:#28a745;
        }
        .otp-inputs input:focus { outline:none; border-color:#28a745; background:#f0faf4; box-shadow:0 0 0 3px rgba(40,167,69,.15); }
        .otp-inputs input.filled { border-color:#28a745; background:#f0faf4; }

        .timer-wrap { text-align:center; margin-bottom:20px; }
        .timer { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#888; }
        .timer #countdown { font-weight:700; color:#28a745; min-width:36px; display:inline-block; }
        .timer.warning #countdown { color:#e67e22; }
        .timer.danger  #countdown { color:#e74c3c; }
        .attempts { text-align:center; font-size:12px; color:#ccc; margin-top:8px; }

        .btn {
            width:100%; padding:14px; border:none; border-radius:9px;
            font-size:15px; font-weight:700; cursor:pointer;
            transition:transform .2s, box-shadow .2s;
            display:block; text-align:center; text-decoration:none; margin-top:4px;
        }
        .btn-primary { background:linear-gradient(135deg,#28a745,#20c997); color:#fff; }
        .btn-primary:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 6px 18px rgba(40,167,69,.35); }
        .btn-primary:disabled { opacity:.55; cursor:not-allowed; transform:none; box-shadow:none; }
        .btn-outline { background:transparent; border:2px solid #28a745; color:#28a745; margin-top:10px; }
        .btn-outline:hover { background:#f0faf4; }

        .success-wrap { text-align:center; }
        .success-icon {
            width:80px; height:80px;
            background:linear-gradient(135deg,#28a745,#20c997); border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            margin:0 auto 20px; font-size:36px; color:#fff;
            animation:pop .5s cubic-bezier(.36,.07,.19,.97);
        }
        @keyframes pop { 0%{transform:scale(0);opacity:0} 70%{transform:scale(1.15)} 100%{transform:scale(1);opacity:1} }
        .success-wrap h2 { color:#2d6a3f; font-size:20px; margin-bottom:10px; }
        .success-wrap p  { color:#777; font-size:14px; line-height:1.6; margin-bottom:22px; }
        .redir-msg { font-size:12px; color:#bbb; margin-top:14px; }
        .redir-msg span { color:#28a745; font-weight:700; }

        .back-link { text-align:center; margin-top:20px; padding-top:16px; border-top:1px solid #eee; }
        .back-link a { color:#666; text-decoration:none; font-size:13px; }
        .back-link a:hover { color:#28a745; }

        @media(max-width:460px) {
            .otp-inputs input { width:40px; height:50px; font-size:20px; }
            .card-body { padding:22px 18px; }
        }
    </style>
</head>
<body>
<div class="card">

    <div class="card-header">
        <?php if ($paso === 'solicitud' || $paso === 'exito'): ?>
            <img src="../../imagenes/logo.png" alt="Logo QuiliAgro Connect" class="logo-img">
            <h1><?= $paso === 'solicitud' ? 'Recuperar Contraseña' : '¡Contraseña actualizada!' ?></h1>
            <p><?= $paso === 'solicitud' ? 'Te enviaremos un código a tu correo' : 'Ya puedes iniciar sesión' ?></p>
        <?php else: ?>
            <div class="header-icon">
                <i class="fas <?= $paso === 'codigo' ? 'fa-shield-halved' : 'fa-lock' ?>"></i>
            </div>
            <h1><?= $paso === 'codigo' ? 'Verificar Código' : 'Nueva Contraseña' ?></h1>
            <p><?= $paso === 'codigo' ? 'Revisa tu bandeja de entrada' : 'Elige una contraseña segura' ?></p>
        <?php endif; ?>
    </div>

    <?php if (in_array($paso, ['codigo', 'password'])): ?>
    <div class="steps">
        <div class="step-item">
            <div class="step-num <?= $paso === 'codigo' ? 'active' : 'done' ?>">
                <?= $paso === 'codigo' ? '1' : '<i class="fas fa-check" style="font-size:10px"></i>' ?>
            </div>
            <span class="step-label <?= $paso === 'codigo' ? 'active' : 'done' ?>">Verificar</span>
        </div>
        <div class="step-line <?= $paso === 'password' ? 'done' : '' ?>"></div>
        <div class="step-item">
            <div class="step-num <?= $paso === 'password' ? 'active' : '' ?>">2</div>
            <span class="step-label <?= $paso === 'password' ? 'active' : '' ?>">Nueva clave</span>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-body">

    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- ══ PASO 0: SOLICITAR CÓDIGO ══ -->
    <?php if ($paso === 'solicitud'): ?>

        <div class="info-box">
            <i class="fas fa-envelope-open-text"></i>
            <span>Ingresa tu cédula y el correo registrado en tu cuenta. Te enviaremos un código de 6 dígitos válido por 10 minutos.</span>
        </div>

        <form method="POST" action="" id="formSolicitud" novalidate>
            <input type="hidden" name="solicitar_codigo" value="1">

            <div class="form-group">
                <label for="cedula">Número de Cédula <span class="req">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-id-card ico"></i>
                    <input type="text" id="cedula" name="cedula"
                           inputmode="numeric" maxlength="10"
                           placeholder="Ej: 1030320442" autocomplete="off"
                           value="<?= isset($_POST['cedula']) ? htmlspecialchars($_POST['cedula']) : '' ?>">
                    <i class="fas fa-check-circle val-icon" id="ico-cedula"></i>
                </div>
                <div class="field-error" id="err-cedula"></div>
            </div>

            <div class="form-group">
                <label for="email">Correo Electrónico <span class="req">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-envelope ico"></i>
                    <input type="text" id="email" name="email"
                           maxlength="100" autocomplete="email"
                           placeholder="tu@correo.com"
                           value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                    <i class="fas fa-check-circle val-icon" id="ico-email"></i>
                </div>
                <div class="field-error" id="err-email"></div>
            </div>

            <button type="submit" class="btn btn-primary" id="btnEnviar">
                <i class="fas fa-paper-plane"></i> Enviar Código
            </button>
        </form>

        <div class="back-link">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Volver al inicio de sesión</a>
        </div>

    <!-- ══ PASO 1: VERIFICAR CÓDIGO ══ -->
    <?php elseif ($paso === 'codigo'): ?>

        <div class="otp-info">
            <p>Enviamos un código de 6 dígitos a<br>
               <strong><?= htmlspecialchars($emailOculto) ?></strong>
            </p>
        </div>

        <form method="POST" action="" id="formCodigo">
            <input type="hidden" name="verificar_codigo" value="1">
            <div class="otp-inputs">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <input type="text" name="digit[]" id="d<?= $i ?>"
                       maxlength="1" inputmode="numeric" pattern="[0-9]"
                       autocomplete="off" required>
                <?php endfor; ?>
            </div>
            <div class="timer-wrap">
                <div class="timer" id="timerEl">
                    <i class="fas fa-clock"></i>
                    Expira en <span id="countdown"></span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" id="btnVerif" disabled>
                <i class="fas fa-check-circle"></i> Verificar Código
            </button>
            <p class="attempts">
                Intentos restantes: <?= 5 - ($_SESSION['recuperacion']['intentos'] ?? 0) ?>
            </p>
        </form>

        <div class="back-link">
            <a href="recuperar_password.php?cancelar=1"><i class="fas fa-times"></i> Cancelar</a>
        </div>

    <!-- ══ PASO 2: NUEVA CONTRASEÑA ══ -->
    <?php elseif ($paso === 'password'): ?>

        <form method="POST" action="" id="formPwd" novalidate>
            <input type="hidden" name="actualizar_password" value="1">

            <div class="form-group">
                <label for="nueva_password">Nueva contraseña <span class="req">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-lock ico"></i>
                    <input type="password" id="nueva_password" name="nueva_password"
                           placeholder="Mínimo 8 caracteres"  maxlength="72" autocomplete="new-password">
                    <button type="button" class="toggle-pwd" data-t="nueva_password" tabindex="-1">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <!-- Requisitos en tiempo real — igual que en registro -->
                <div class="pw-reqs">
                    <div class="req-item" id="r-len"><i class="fas fa-times"></i>Mínimo 8 caracteres</div>
                    <div class="req-item" id="r-upper"><i class="fas fa-times"></i>Al menos una mayúscula (A-Z)</div>
                    <div class="req-item" id="r-lower"><i class="fas fa-times"></i>Al menos una minúscula (a-z)</div>
                    <div class="req-item" id="r-num"><i class="fas fa-times"></i>Al menos un número (0-9)</div>
                    <div class="req-item" id="r-sym"><i class="fas fa-times"></i>Al menos un símbolo (!@#$%…)</div>
                </div>
            </div>

            <div class="form-group">
                <label for="confirmar_password">Confirmar contraseña <span class="req">*</span></label>
                <div class="input-wrap">
                    <i class="fas fa-lock-open ico"></i>
                    <input type="password" id="confirmar_password" name="confirmar_password"
                           placeholder="Repite tu contraseña" maxlength="72" autocomplete="new-password">
                    <button type="button" class="toggle-pwd" data-t="confirmar_password" tabindex="-1">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="match-msg" id="matchMsg"></div>
            </div>

            <button type="submit" class="btn btn-primary" id="btnGuardar" disabled>
                <i class="fas fa-save"></i> Guardar Contraseña
            </button>
        </form>

        <div class="back-link">
            <a href="login.php"><i class="fas fa-times"></i> Cancelar y volver al login</a>
        </div>

    <!-- ══ PASO 3: ÉXITO ══ -->
    <?php else: ?>

        <div class="success-wrap">
            <div class="success-icon"><i class="fas fa-check"></i></div>
            <p>Tu contraseña ha sido cambiada correctamente.<br>Ya puedes iniciar sesión con tu nueva clave.</p>
            <a href="login.php" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i> Ir al inicio de sesión
            </a>
            <p class="redir-msg">Redirigiendo en <span id="rdSec">5</span> segundos…</p>
        </div>

    <?php endif; ?>

    </div>
    <footer style="text-align: center; padding: 14px 20px; font-size: 12px; color: #888; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; border-top: 1px solid #eee;">
        &copy; <?php echo date('Y'); ?> QuiliAgro Connect &mdash; Todos los derechos reservados &mdash; NIT: 900.123.456-7
    </footer>
</div>

<script>
const $el = id => document.getElementById(id);

function setValid(inputId, icoId, errId) {
    $el(inputId).classList.remove('is-invalid'); $el(inputId).classList.add('is-valid');
    if (icoId) {
        const ico = $el(icoId);
        ico.className = ico.className.replace(/fa-times-circle|fa-check-circle/g,'') + ' fa-check-circle';
        ico.classList.remove('show-err'); ico.classList.add('show-ok');
    }
    if (errId) { $el(errId).textContent=''; $el(errId).classList.remove('visible'); }
}

function setInvalid(inputId, icoId, errId, msg) {
    $el(inputId).classList.add('is-invalid'); $el(inputId).classList.remove('is-valid');
    if (icoId) {
        const ico = $el(icoId);
        ico.className = ico.className.replace(/fa-times-circle|fa-check-circle/g,'') + ' fa-times-circle';
        ico.classList.remove('show-ok'); ico.classList.add('show-err');
    }
    if (errId && msg) { $el(errId).textContent=msg; $el(errId).classList.add('visible'); }
}

function clearState(inputId, icoId, errId) {
    $el(inputId).classList.remove('is-invalid','is-valid');
    if (icoId) { const ico=$el(icoId); ico.classList.remove('show-ok','show-err'); }
    if (errId) { $el(errId).textContent=''; $el(errId).classList.remove('visible'); }
}

// ══════════════════════ PASO 0: SOLICITAR CÓDIGO ══════════════════
(function () {
    const cedulaInp = $el('cedula');
    const emailInp  = $el('email');
    const btnEnviar = $el('btnEnviar');
    if (!cedulaInp) return;

    function validateCedula(live) {
        cedulaInp.value = cedulaInp.value.replace(/\D/g,'');
        const v = cedulaInp.value;
        if (!live && v === '') return setInvalid('cedula','ico-cedula','err-cedula','Ingresa tu número de cédula.');
        if (v === '')          return clearState('cedula','ico-cedula','err-cedula');
        if (v.length < 7)      return setInvalid('cedula','ico-cedula','err-cedula','La cédula debe tener mínimo 7 dígitos.');
        if (v[0] === '0')      return setInvalid('cedula','ico-cedula','err-cedula','La cédula no puede empezar en cero.');
        if (/^(.)\1+$/.test(v)) return setInvalid('cedula','ico-cedula','err-cedula','La cédula ingresada no es válida.');
        setValid('cedula','ico-cedula','err-cedula');
    }
    cedulaInp.addEventListener('input', () => validateCedula(true));
    cedulaInp.addEventListener('blur',  () => validateCedula(false));

    const EMAIL_RE = /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9\-]+(\.[a-zA-Z0-9\-]+)*\.[a-zA-Z]{2,6}$/;

    function validateEmail(live) {
        emailInp.value = emailInp.value.replace(/\s/g,'');
        const v = emailInp.value;
        if (!live && v === '') return setInvalid('email','ico-email','err-email','Ingresa tu correo electrónico.');
        if (v === '')          return clearState('email','ico-email','err-email');
        if (!EMAIL_RE.test(v)) return setInvalid('email','ico-email','err-email','Formato inválido. Ej: nombre@dominio.com');
        if (v.length > 100)    return setInvalid('email','ico-email','err-email','El correo no puede superar 100 caracteres.');
        setValid('email','ico-email','err-email');
    }
    emailInp.addEventListener('input', () => validateEmail(true));
    emailInp.addEventListener('blur',  () => validateEmail(false));
    emailInp.addEventListener('keydown', e => { if (e.key === ' ') e.preventDefault(); });
    emailInp.addEventListener('paste', e => {
        e.preventDefault();
        emailInp.value = (e.clipboardData || window.clipboardData).getData('text').replace(/\s/g,'');
        validateEmail(true);
    });

    document.getElementById('formSolicitud')?.addEventListener('submit', function(e) {
        validateCedula(false);
        validateEmail(false);
        const first = document.querySelector('#formSolicitud .is-invalid');
        if (first) { e.preventDefault(); first.focus(); return; }
        btnEnviar.disabled = true;
        btnEnviar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
    });

    cedulaInp.focus();
})();

// ══════════════════════ PASO 1: VERIFICAR CÓDIGO ══════════════════════════════
(function () {
    const inputs   = document.querySelectorAll('.otp-inputs input');
    const btnVerif = $el('btnVerif');
    if (!inputs.length) return;

    function checkFull() {
        btnVerif.disabled = ![...inputs].every(i => i.value);
    }

    inputs.forEach((inp, idx) => {
        inp.addEventListener('input', () => {
            inp.value = inp.value.replace(/[^0-9]/g,'').slice(-1);
            inp.classList.toggle('filled', inp.value !== '');
            if (inp.value && idx < inputs.length - 1) inputs[idx + 1].focus();
            checkFull();
        });
        inp.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !inp.value && idx > 0) {
                inputs[idx - 1].focus();
                inputs[idx - 1].value = '';
                inputs[idx - 1].classList.remove('filled');
                checkFull();
            }
            if (e.key === 'ArrowLeft'  && idx > 0)               inputs[idx - 1].focus();
            if (e.key === 'ArrowRight' && idx < inputs.length - 1) inputs[idx + 1].focus();
        });
        inp.addEventListener('paste', e => {
            e.preventDefault();
            const txt = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
            txt.split('').forEach((c, i) => { if (inputs[i]) { inputs[i].value = c; inputs[i].classList.add('filled'); } });
            inputs[Math.min(txt.length, inputs.length) - 1]?.focus();
            checkFull();
        });
    });

    document.getElementById('formCodigo')?.addEventListener('submit', () => {
        btnVerif.disabled = true;
        btnVerif.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...';
    });

    let secs = <?= $segundosRestantes ?>;
    const cEl = $el('countdown');
    const tEl = $el('timerEl');
    const tick = () => {
        if (secs <= 0) {
            cEl.textContent = 'expirado';
            tEl.classList.add('danger');
            btnVerif.disabled = true;
            btnVerif.innerHTML = '<i class="fas fa-times-circle"></i> Código expirado';
            clearInterval(iv);
            setTimeout(() => { window.location.href = 'recuperar_password.php'; }, 2000);
            return;
        }
        const m = String(Math.floor(secs / 60)).padStart(2,'0');
        const s = String(secs % 60).padStart(2,'0');
        cEl.textContent = m + ':' + s;
        if (secs <= 60) tEl.classList.add('warning');
        if (secs <= 30) { tEl.classList.remove('warning'); tEl.classList.add('danger'); }
        secs--;
    };
    tick();
    const iv = setInterval(tick, 1000);
    inputs[0].focus();
})();

// ══════════════════════ PASO 2: NUEVA CONTRASEÑA ══════════════════
(function () {
    const inpN = $el('nueva_password');
    const inpC = $el('confirmar_password');
    const btn  = $el('btnGuardar');
    if (!inpN) return;

    const mMsg = $el('matchMsg');

    const PW_RULES = [
        { id:'r-len',   test: v => v.length >= 8 },
        { id:'r-upper', test: v => /[A-Z]/.test(v) },
        { id:'r-lower', test: v => /[a-z]/.test(v) },
        { id:'r-num',   test: v => /[0-9]/.test(v) },
        { id:'r-sym',   test: v => /[!@#$%^&*()\-_=+{};:,<.>]/.test(v) },
    ];

    function validate() {
        const p = inpN.value, c = inpC.value;

        let allOk = true;
        PW_RULES.forEach(r => {
            const ok = r.test(p);
            if (!ok) allOk = false;
            const el = $el(r.id);
            if (!el) return;
            el.classList.toggle('ok',  ok);
            el.classList.toggle('bad', !ok && p.length > 0);
            el.querySelector('i').className = ok ? 'fas fa-check' : 'fas fa-times';
        });

        if (p.length > 0) {
            inpN.classList.toggle('is-valid',   allOk);
            inpN.classList.toggle('is-invalid', !allOk);
        } else {
            inpN.classList.remove('is-valid','is-invalid');
        }

        const match = p === c;
        if (c.length > 0) {
            mMsg.textContent = match ? '✓ Las contraseñas coinciden' : '✗ No coinciden';
            mMsg.style.color = match ? '#28a745' : '#e74c3c';
            inpC.classList.toggle('is-valid',   match);
            inpC.classList.toggle('is-invalid', !match);
        } else {
            mMsg.textContent = '';
            inpC.classList.remove('is-valid','is-invalid');
        }

        btn.disabled = !(allOk && match && c.length > 0);
    }

    inpN.addEventListener('input', validate);
    inpC.addEventListener('input', validate);

    document.querySelectorAll('.toggle-pwd').forEach(b => {
        b.addEventListener('click', () => {
            const t = $el(b.dataset.t);
            const visible = t.type === 'text';
            t.type = visible ? 'password' : 'text';
            b.querySelector('i').className = 'fas fa-' + (visible ? 'eye' : 'eye-slash');
        });
    });

    document.getElementById('formPwd')?.addEventListener('submit', function(e) {
        validate();
        if (btn.disabled) { e.preventDefault(); return; }
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
    });

    inpN.focus();
})();

// ══════════════════════ PASO 3: REDIRECCIÓN ÉXITO ════════════════
(function () {
    const el = $el('rdSec');
    if (!el) return;
    let s = 5;
    setInterval(() => { s--; el.textContent = s; if (s <= 0) window.location.href = 'login.php'; }, 1000);
})();
</script>
</body>
</html>