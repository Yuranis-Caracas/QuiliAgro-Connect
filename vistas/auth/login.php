<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

if (isset($_SESSION['user_id'], $_SESSION['user_rol'])) {
    $destinos = [
        'administrador' => '../admin/panel.php',
        'productor'     => '../productor/panel_productor.php',
        'comprador'     => '../comprador/panel_comprador.php',
    ];
    header("Location: " . ($destinos[$_SESSION['user_rol']] ?? 'login.php'));
    exit();
}

$error   = '';
$success = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

if (isset($_GET['msg']) && $_GET['msg'] === 'password_cambiada') {
    $success = 'Contraseña actualizada correctamente. Ingresa con tu nueva clave.';
}

if (!empty($_SESSION['error_login'])) {
    $error = $_SESSION['error_login'];
    unset($_SESSION['error_login']);
}

$cedula_value = '';

function cedulaValida(string $cedula): bool {
    if (!preg_match('/^\d{7,10}$/', $cedula)) return false; 
    if ($cedula[0] === '0') return false;                  
    if (preg_match('/^(\d)\1+$/', $cedula)) return false;   
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cedula   = trim($_POST['cedula']   ?? '');
    $password = $_POST['password'] ?? ''; 

    if (empty($cedula) || empty($password)) {
        $error        = 'Por favor, ingresa tu cédula y contraseña.';
        $cedula_value = htmlspecialchars($cedula);

    } elseif (!cedulaValida($cedula)) {
        $error = 'Cédula o contraseña incorrectos. Verifica tus datos.';

    } else {
        $cedula_value = htmlspecialchars($cedula);

        try {
            $database = new Database();
            $conn     = $database->getConnection();

            $stmt = $conn->prepare("SELECT * FROM usuarios WHERE cedula = ? AND eliminado = 0 LIMIT 1");
            $stmt->execute([$cedula]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password'])) {
                $error = 'Cédula o contraseña incorrectos. Verifica tus datos.';

            } elseif ($user['estado'] === 'suspendido') {
                $error = 'Tu cuenta ha sido suspendida. Comunícate con el administrador.';

            } elseif ($user['estado'] === 'inactivo') {
                $error = 'Tu cuenta está inactiva. Comunícate con el administrador.';

            } else {
                session_regenerate_id(true);

                $_SESSION['user_id']       = $user['id_usuario'];
                $_SESSION['user_nombre']   = $user['nombre'];
                $_SESSION['user_cedula']   = $user['cedula'];
                $_SESSION['user_telefono'] = $user['telefono'];
                $_SESSION['user_email']    = $user['email'];
                $_SESSION['user_rol']      = $user['rol'];
                $_SESSION['user_estado']   = $user['estado'];
                $_SESSION['logged_in']     = true;

                $destinos = [
                    'administrador' => '../admin/panel.php',
                    'productor'     => '../productor/panel_productor.php',
                    'comprador'     => '../comprador/panel_comprador.php',
                ];
                header("Location: " . ($destinos[$user['rol']] ?? 'login.php'));
                exit();
            }

        } catch (PDOException $e) {
            $error = 'Error en el sistema. Intenta más tarde.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/quiliagro_connect/config/pwa_head.php'; ?>
    <title>QuiliAgro Connect - Iniciar Sesión</title>
    <link rel="icon" type="image/png" sizes="192x192" href="/quiliagro_connect/imagenes/icon-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/quiliagro_connect/imagenes/icon-512x512.png">
    <link rel="apple-touch-icon" href="/quiliagro_connect/imagenes/icon-192x192.png">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }

        body {
            background: linear-gradient(135deg,#e4e5e9 0%,#edebf0 100%);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white; border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,.2);
            width: 100%; max-width: 400px; overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg,#28a745 0%,#20c997 100%);
            color: white; padding: 30px 20px; text-align: center;
        }

        .logo {
            width:100px; height:100px; object-fit:contain; border-radius:50%;
            background:white; padding:10px; box-shadow:0 5px 15px rgba(0,0,0,.2);
            border:4px solid rgba(255,255,255,.3); margin-bottom:15px;
        }

        .login-header h1 { font-size:24px; margin-bottom:5px; font-weight:600; }
        .login-header p  { font-size:14px; opacity:.9; }

        .login-form { padding:30px 25px; }

        .alert {
            padding:12px 15px; border-radius:8px; margin-bottom:20px;
            font-size:14px; display:flex; align-items:flex-start; gap:10px;
        }
        .alert-error   { background:#fee; color:#c33; border-left:4px solid #c33; }
        .alert-success { background:#efe; color:#0a0; border-left:4px solid #0a0; }

        .form-group { margin-bottom:20px; }
        .form-group label { display:block; margin-bottom:8px; color:#333; font-weight:600; font-size:14px; }
        .form-group label .req { color:#e74c3c; }

        .iw { position:relative; }
        .iw > i.ico {
            position:absolute; left:14px; top:50%; transform:translateY(-50%);
            color:#888; font-size:14px; pointer-events:none;
        }

        .iw input {
            width:100%; padding:12px 42px 12px 42px;
            border:2px solid #ddd; border-radius:8px; font-size:15px;
            transition:border-color .25s, box-shadow .25s;
        }
        .iw input:focus {
            outline:none; border-color:#28a745;
            box-shadow:0 0 0 3px rgba(40,167,69,.12);
        }

        .iw input.is-invalid { border-color:#dc3545 !important; }
        .iw input.is-valid   { border-color:#28a745 !important; }

        .val-icon {
            position:absolute; right:14px; top:50%; transform:translateY(-50%);
            font-size:14px; display:none;
        }
        .val-icon.show-ok  { display:block; color:#28a745; }
        .val-icon.show-err { display:block; color:#dc3545; }

        .btn-eye {
            position:absolute; right:14px; top:50%; transform:translateY(-50%);
            background:none; border:none; color:#888; cursor:pointer; padding:0; font-size:14px;
        }

        .field-error { font-size:11.5px; color:#dc3545; margin-top:4px; display:none; }
        .field-error.visible { display:block; }

        .btn-login {
            background: linear-gradient(135deg,#28a745 0%,#20c997 100%);
            color:white; border:none; padding:14px; width:100%;
            border-radius:8px; font-size:16px; font-weight:600; cursor:pointer;
            transition:transform .25s, box-shadow .25s; margin-top:8px;
        }
        .btn-login:hover  { transform:translateY(-2px); box-shadow:0 5px 15px rgba(40,167,69,.4); }
        .btn-login:active { transform:translateY(0); }
        .btn-login:disabled { opacity:.6; cursor:not-allowed; transform:none; }

        .links {
            margin-top:20px; text-align:center;
            display:flex; flex-direction:column; gap:8px;
        }
        .links a { color:#28a745; text-decoration:none; font-size:14px; }
        .links a:hover { text-decoration:underline; }

        .register-link {
            text-align:center; margin-top:15px; padding-top:15px;
            border-top:1px solid #eee; font-size:14px; color:#666;
        }
        .register-link a { color:#28a745; font-weight:600; text-decoration:none; }
        .register-link a:hover { text-decoration:underline; }

        @media(max-width:480px){
            .login-header { padding:25px 15px; }
            .login-form   { padding:25px 20px; }
            .logo { width:80px; height:80px; }
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="login-header">
        <img src="../../imagenes/logo.png" alt="Logo QuiliAgro Connect" class="logo">
        <h1>QuiliAgro Connect</h1>
        <p>Conectando productores y compradores</p>
    </div>

    <div class="login-form">

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle" style="margin-top:2px;flex-shrink:0"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle" style="margin-top:2px;flex-shrink:0"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm" novalidate>

            <div class="form-group">
                <label for="cedula">Número de Cédula <span class="req">*</span></label>
                <div class="iw">
                    <i class="fas fa-id-card ico"></i>
                    <input type="text" id="cedula" name="cedula"
                           placeholder="Tu número de cédula"
                           maxlength="10" inputmode="numeric"
                           value="<?php echo $cedula_value; ?>"
                           autocomplete="username">
                    <i class="fas fa-check-circle val-icon" id="ico-cedula"></i>
                </div>
                <div class="field-error" id="err-cedula"></div>
            </div>

            <div class="form-group">
                <label for="password">Contraseña <span class="req">*</span></label>
                <div class="iw">
                    <i class="fas fa-lock ico"></i>
                    <input type="password" id="password" name="password"
                           placeholder="Tu contraseña"
                           maxlength="72"
                           autocomplete="current-password">
                    <button type="button" class="btn-eye" id="togglePw" aria-label="Mostrar contraseña">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="field-error" id="err-password"></div>
            </div>

            <button type="submit" class="btn-login" id="submitBtn">
                <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
            </button>

            <div class="links">
                <a href="recuperar_password.php"><i class="fas fa-key" style="font-size:12px"></i> ¿Olvidaste tu contraseña?</a>
                <a href=../ayuda/ayuda.php><i class="fas fa-circle-question" style="font-size:12px"></i> ¿Necesitas ayuda?</a>
            </div>
        </form>

        <div class="register-link">
            ¿No tienes cuenta? <a href="registro.php">Regístrate aquí</a>
        </div>
    </div>
     <footer style="text-align: center; padding: 14px 20px; font-size: 12px; color: #888; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; border-top: 1px solid #eee;">
        &copy; <?php echo date('Y'); ?> QuiliAgro Connect &mdash; Todos los derechos reservados &mdash; NIT: 900.123.456-7
    </footer>
</div>

<script>
const $ = id => document.getElementById(id);

$('togglePw').addEventListener('click', function() {
    const inp = $('password');
    const hidden = inp.type === 'password';
    inp.type = hidden ? 'text' : 'password';
    this.querySelector('i').className = hidden ? 'fas fa-eye-slash' : 'fas fa-eye';
});

function setValid(inputId, icoId, errId) {
    $(inputId).classList.remove('is-invalid'); $(inputId).classList.add('is-valid');
    if (icoId) {
        const ico = $(icoId);
        ico.className = ico.className.replace(/fa-times-circle|fa-check-circle/g,'') + ' fa-check-circle';
        ico.classList.remove('show-err'); ico.classList.add('show-ok');
    }
    if (errId) { $(errId).textContent=''; $(errId).classList.remove('visible'); }
}

function setInvalid(inputId, icoId, errId, msg) {
    $(inputId).classList.add('is-invalid'); $(inputId).classList.remove('is-valid');
    if (icoId) {
        const ico = $(icoId);
        ico.className = ico.className.replace(/fa-times-circle|fa-check-circle/g,'') + ' fa-times-circle';
        ico.classList.remove('show-ok'); ico.classList.add('show-err');
    }
    if (errId && msg) { $(errId).textContent=msg; $(errId).classList.add('visible'); }
}

function clearState(inputId, icoId, errId) {
    $(inputId).classList.remove('is-invalid','is-valid');
    if (icoId) { const ico=$(icoId); ico.classList.remove('show-ok','show-err'); }
    if (errId) { $(errId).textContent=''; $(errId).classList.remove('visible'); }
}


function cedulaValida(v) {
    if (!/^\d{7,10}$/.test(v)) return false; 
    if (v[0] === '0') return false;           
    if (/^(.)\1+$/.test(v)) return false;     
    return true;
}

function validateCedula(live) {
    $('cedula').value = $('cedula').value.replace(/\D/g,''); 
    const v = $('cedula').value;

    if (!live && v === '') return setInvalid('cedula','ico-cedula','err-cedula','Ingresa tu número de cédula.');
    if (v === '')          return clearState('cedula','ico-cedula','err-cedula');
    if (v.length < 7)      return setInvalid('cedula','ico-cedula','err-cedula','La cédula debe tener mínimo 7 dígitos.');
    if (v[0] === '0')      return setInvalid('cedula','ico-cedula','err-cedula','La cédula no puede empezar en cero.');
    if (/^(.)\1+$/.test(v)) return setInvalid('cedula','ico-cedula','err-cedula','La cédula ingresada no es válida.');
    setValid('cedula','ico-cedula','err-cedula');
}
$('cedula').addEventListener('input', () => validateCedula(true));
$('cedula').addEventListener('blur',  () => validateCedula(false));


function validatePassword(live) {
    const v = $('password').value;
    if (!live && v === '') return setInvalid('password', null, 'err-password', 'Ingresa tu contraseña.');
    if (v === '')          return clearState('password', null, 'err-password');
    $('password').classList.remove('is-invalid');
    $('err-password').textContent=''; $('err-password').classList.remove('visible');
}
$('password').addEventListener('input', () => validatePassword(true));
$('password').addEventListener('blur',  () => validatePassword(false));

$('loginForm').addEventListener('submit', function(e) {
    validateCedula(false);
    validatePassword(false);

    const first = document.querySelector('.is-invalid');
    if (first) {
        e.preventDefault();
        first.scrollIntoView({ behavior:'smooth', block:'center' });
        first.focus();
        return false;
    }

    const btn = $('submitBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Iniciando sesión...';
    btn.disabled = true;
});


$('cedula').focus();
</script>
</body>
</html>