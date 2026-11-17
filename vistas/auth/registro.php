<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

if (isset($_SESSION['user_id'], $_SESSION['rol'])) {
    $destinos = [
        'admin'     => '../../panel_admin/index.php',
        'productor' => '../../panel_productor/index.php',
        'comprador' => '../../panel_comprador/index.php',
    ];
    header("Location: " . ($destinos[$_SESSION['rol']] ?? 'login.php'));
    exit();
}

$error = '';
$errorLink = '';

function cedulaValida(string $cedula): bool {
    if (!preg_match('/^\d{7,10}$/', $cedula)) return false;
    if ($cedula[0] === '0') return false;
    if (preg_match('/^(\d)\1+$/', $cedula)) return false;
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre           = trim($_POST['nombre'] ?? '');
    $cedula           = trim($_POST['cedula'] ?? '');
    $telefono         = trim($_POST['telefono'] ?? '');
    $email            = strtolower(trim($_POST['email'] ?? ''));
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $rol              = $_POST['rol'] ?? '';

    $palabras = array_filter(explode(' ', $nombre), fn($p) => strlen(trim($p)) >= 2);

    if (empty($nombre) || empty($cedula) || empty($telefono) ||
        empty($email) || empty($password) || empty($confirm_password) || empty($rol)) {
        $error = 'Por favor, completa todos los campos obligatorios.';

    } elseif (strlen($nombre) > 100) {
        $error = 'El nombre no puede superar 100 caracteres.';

    } elseif (!preg_match('/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s]+$/', $nombre)) {
        $error = 'El nombre solo puede contener letras y espacios.';

    } elseif (count($palabras) < 2) {
        $error = 'Ingresa tu nombre y apellido completos. Ej: Luz Paz';

    } elseif (!cedulaValida($cedula)) {
        $error = 'La cédula ingresada no es válida. Verifica el número.';

    } elseif (!preg_match('/^\d{10}$/', $telefono)) {
    $error = 'El teléfono debe tener 10 dígitos.';

    } elseif ($telefono[0] !== '3') {
        $error = 'El teléfono debe ser un número celular válido para Colombia.';
        
    } elseif (!preg_match('/^3(0[0-9]|1[0-9]|2[0-9]|3[0-9]|5[0-9])\d{7}$/', $telefono)) {
        $error = 'El número de celular no parece válido.';

    } elseif (preg_match('/^3\d{2}(.)\1{6}$/', $telefono)) {
        $error = 'El número de celular no parece válido.';

    } elseif (strpos($email, ' ') !== false ||
              !preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9\-]+(\.[a-zA-Z0-9\-]+)*\.[a-zA-Z]{2,6}$/', $email)) {
        $error = 'El correo electrónico no tiene un formato válido.';

    } elseif (strlen(explode('@', $email)[0]) < 6) {
        $error = 'La parte del correo antes del @ debe tener mínimo 6 caracteres. Ej: nombre@dominio.com';

    } elseif (ctype_digit(explode('@', $email)[0])) {
        $error = 'El correo no parece válido. Escribe una dirección real.';
        } elseif (!ctype_alpha(explode('@', $email)[0][0])) {
        $error = 'El correo debe comenzar con una letra.';

    } elseif (strlen($email) > 100) {
        $error = 'El correo electrónico no puede superar 100 caracteres.';

    } elseif ($password !== $confirm_password) {
        $error = 'Las contraseñas no coinciden.';

    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';

    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = 'La contraseña debe contener al menos una letra mayúscula.';

    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = 'La contraseña debe contener al menos una letra minúscula.';

    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = 'La contraseña debe contener al menos un número.';

    } elseif (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        $error = 'La contraseña debe contener al menos un símbolo (!@#$%^&*()-_=+{};:,<.>).';

    } elseif (!in_array($rol, ['productor', 'comprador'])) {
        $error = 'Selecciona un tipo de usuario válido.';

    } else {
        try {
            $database = new Database();
            $conn     = $database->getConnection();

            $chk = $conn->prepare(
                "SELECT COUNT(*) FROM usuarios WHERE cedula = ? OR email = ?"
            );
            $chk->execute([$cedula, $email]);

            if ((int)$chk->fetchColumn() > 0) {
                $error = 'Es posible que ya exista una cuenta con esa cédula o correo.';
                $errorLink = '<a href="login.php" style="color:#c33;font-weight:600;">Inicia sesión aquí</a>';
            } else {
                registrarUsuario($conn, $nombre, $cedula, $telefono, $email, $password, $rol);
            }

        } catch (PDOException $e) {
            $error = 'Error en el sistema. Intenta más tarde.';
            error_log("Registro error: " . $e->getMessage());
        }
    }
}

function registrarUsuario($conn, $nombre, $cedula, $telefono, $email, $password, $rol) {
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "INSERT INTO usuarios (nombre, cedula, telefono, email, password, rol) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$nombre, $cedula, $telefono, $email, $hashedPassword, $rol]);
    $userId = $conn->lastInsertId();

    if ($rol === 'productor') {
        $conn->prepare("INSERT INTO perfiles_productor (id_productor) VALUES (?)")->execute([$userId]);
    } 

    $_SESSION['success_message'] = 'Registro exitoso. Ahora puedes iniciar sesión.';
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/quiliagro_connect/config/pwa_head.php'; ?>
    <title>QuiliAgro Connect - Registro</title>
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

        .register-container {
            background: white; border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,.2);
            width: 100%; max-width: 520px; overflow: hidden;
        }

        .register-header {
            background: linear-gradient(135deg,#28a745 0%,#20c997 100%);
            color: white; padding: 25px 20px; text-align: center;
        }

        .logo {
            width:100px; height:100px; object-fit:contain; border-radius:50%;
            background:white; padding:10px; box-shadow:0 5px 15px rgba(0,0,0,.2);
            border:4px solid rgba(255,255,255,.3); margin-bottom:12px;
        }

        .register-header h1 { font-size:22px; margin-bottom:5px; font-weight:600; }
        .register-header p  { font-size:14px; opacity:.9; }

        .register-form { padding:25px; max-height:72vh; overflow-y:auto; }

        .alert {
            padding:12px 15px; border-radius:8px; margin-bottom:18px;
            font-size:14px; display:flex; align-items:flex-start; gap:10px;
        }
        .alert-error { background:#fee; color:#c33; border-left:4px solid #c33; }

        .form-group { margin-bottom:14px; }
        .form-group label { display:block; margin-bottom:5px; color:#333; font-weight:600; font-size:13px; }
        .form-group label .req { color:#e74c3c; }

        .iw { position:relative; }
        .iw > i.ico {
            position:absolute; left:12px; top:50%; transform:translateY(-50%);
            color:#888; font-size:14px; pointer-events:none;
        }

        .iw input, .iw select {
            width:100%; padding:10px 38px 10px 38px;
            border:2px solid #ddd; border-radius:8px; font-size:14px;
            transition:border-color .25s, box-shadow .25s;
            background:white; -webkit-appearance:none; appearance:none;
        }
        .iw input:focus, .iw select:focus {
            outline:none; border-color:#28a745;
            box-shadow:0 0 0 3px rgba(40,167,69,.12);
        }

        .iw input.is-invalid, .iw select.is-invalid { border-color:#dc3545 !important; }
        .iw input.is-valid,   .iw select.is-valid   { border-color:#28a745 !important; }

        .val-icon {
            position:absolute; right:12px; top:50%; transform:translateY(-50%);
            font-size:14px; display:none;
        }
        .val-icon.show-ok  { display:block; color:#28a745; }
        .val-icon.show-err { display:block; color:#dc3545; }

        .btn-eye {
            position:absolute; right:12px; top:50%; transform:translateY(-50%);
            background:none; border:none; color:#888; cursor:pointer; padding:0; font-size:14px;
        }

        .field-error { font-size:11.5px; color:#dc3545; margin-top:4px; display:none; }
        .field-error.visible { display:block; }

        .form-row { display:flex; gap:14px; }
        .form-row .form-group { flex:1; }

        .pw-reqs { margin-top:6px; }
        .req-item {
            display:flex; align-items:center; gap:6px;
            font-size:11.5px; margin-bottom:2px; color:#999;
        }
        .req-item i { font-size:10px; width:12px; }
        .req-item.ok  { color:#28a745; }
        .req-item.bad { color:#dc3545; }

        .btn-register {
            background:linear-gradient(135deg,#28a745 0%,#20c997 100%);
            color:white; border:none; padding:13px; width:100%;
            border-radius:8px; font-size:15px; font-weight:600; cursor:pointer;
            transition:transform .25s, box-shadow .25s; margin-top:8px;
        }
        .btn-register:hover  { transform:translateY(-2px); box-shadow:0 5px 15px rgba(40,167,69,.4); }
        .btn-register:active { transform:translateY(0); }
        .btn-register:disabled { opacity:.6; cursor:not-allowed; transform:none; }

        .login-link {
            text-align:center; margin-top:18px; padding-top:18px;
            border-top:1px solid #eee; font-size:13px; color:#666;
        }
        .login-link a { color:#28a745; font-weight:600; text-decoration:none; }
        .login-link a:hover { text-decoration:underline; }

        @media(max-width:580px){
            .form-row { flex-direction:column; gap:0; }
            .register-form { padding:18px; }
        }
    </style>
</head>
<body>
<div class="register-container">
    <div class="register-header">
        <img src="../../imagenes/logo.png" alt="Logo QuiliAgro Connect" class="logo">
        <h1>Crear Cuenta</h1>
        <p>Únete a nuestra comunidad agropecuaria</p>
    </div>

    <div class="register-form">
        
    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle" style="margin-top:2px;flex-shrink:0"></i>
            <span>
                <?php echo htmlspecialchars($error); ?>
                <?php if (!empty($errorLink)) echo ' ' . $errorLink; ?>
            </span>
        </div>
    <?php endif; ?>

        <form method="POST" action="" id="registerForm" novalidate>

            <div class="form-group">
                <label for="nombre">Nombre Completo <span class="req">*</span></label>
                <div class="iw">
                    <i class="fas fa-user ico"></i>
                    <input type="text" id="nombre" name="nombre"
                           placeholder="Nombre y apellido" maxlength="100"
                           value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
                    <i class="fas fa-check-circle val-icon" id="ico-nombre"></i>
                </div>
                <div class="field-error" id="err-nombre"></div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="cedula">Cédula <span class="req">*</span></label>
                    <div class="iw">
                        <i class="fas fa-id-card ico"></i>
                        <input type="text" id="cedula" name="cedula"
                               placeholder="7 a 10 dígitos" maxlength="10" inputmode="numeric"
                               value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>">
                        <i class="fas fa-check-circle val-icon" id="ico-cedula"></i>
                    </div>
                    <div class="field-error" id="err-cedula"></div>
                </div>

                <div class="form-group">
                    <label for="telefono">Teléfono <span class="req">*</span></label>
                    <div class="iw">
                        <i class="fas fa-phone ico"></i>
                        <input type="text" id="telefono" name="telefono"
                               placeholder="10 dígitos exactos" maxlength="10" inputmode="numeric"
                               value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
                        <i class="fas fa-check-circle val-icon" id="ico-telefono"></i>
                    </div>
                    <div class="field-error" id="err-telefono"></div>
                </div>
            </div>

            <div class="form-group">
                <label for="email">Correo Electrónico <span class="req">*</span></label>
                <div class="iw">
                    <i class="fas fa-envelope ico"></i>
                    <input type="text" id="email" name="email"
                           placeholder="tu@correo.com" maxlength="100" autocomplete="email"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    <i class="fas fa-check-circle val-icon" id="ico-email"></i>
                </div>
                <div class="field-error" id="err-email"></div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password">Contraseña <span class="req">*</span></label>
                    <div class="iw">
                        <i class="fas fa-lock ico"></i>
                        <input type="password" id="password" name="password"
                               placeholder="Mínimo 8 caracteres" maxlength="72" autocomplete="new-password">
                        <button type="button" class="btn-eye" id="togglePw" aria-label="Mostrar contraseña">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="pw-reqs">
                        <div class="req-item" id="r-len"><i class="fas fa-times"></i>Mínimo 8 caracteres</div>
                        <div class="req-item" id="r-upper"><i class="fas fa-times"></i>Al menos una mayúscula (A-Z)</div>
                        <div class="req-item" id="r-lower"><i class="fas fa-times"></i>Al menos una minúscula (a-z)</div>
                        <div class="req-item" id="r-num"><i class="fas fa-times"></i>Al menos un número (0-9)</div>
                        <div class="req-item" id="r-sym"><i class="fas fa-times"></i>Al menos un símbolo (!@#$%…)</div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirmar Contraseña <span class="req">*</span></label>
                    <div class="iw">
                        <i class="fas fa-lock ico"></i>
                        <input type="password" id="confirm_password" name="confirm_password"
                               placeholder="Repite la contraseña"  maxlength="72" autocomplete="new-password">
                        <button type="button" class="btn-eye" id="toggleCpw" aria-label="Mostrar contraseña">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="field-error" id="err-confirm" style="margin-top:6px;"></div>
                </div>
            </div>

            <div class="form-group">
                <label for="rol">Tipo de Usuario <span class="req">*</span></label>
                <div class="iw">
                    <i class="fas fa-user-tag ico"></i>
                    <select id="rol" name="rol">
                        <option value="">Selecciona tu rol</option>
                        <option value="productor" <?php echo (($_POST['rol'] ?? '') === 'productor') ? 'selected' : ''; ?>>Productor</option>
                        <option value="comprador" <?php echo (($_POST['rol'] ?? '') === 'comprador') ? 'selected' : ''; ?>>Comprador</option>
                    </select>
                    <i class="fas fa-check-circle val-icon" id="ico-rol"></i>
                </div>
                <div class="field-error" id="err-rol"></div>
            </div>

            <button type="submit" class="btn-register" id="submitBtn">
                <i class="fas fa-user-plus"></i> Registrarse
            </button>
        </form>

        <div class="login-link">
            ¿Ya tienes cuenta? <a href="login.php">Inicia sesión aquí</a>
        </div>
    </div>

    <footer style="text-align: center; padding: 14px 20px; font-size: 12px; color: #888; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; border-top: 1px solid #eee;">
        &copy; <?php echo date('Y'); ?> QuiliAgro Connect &mdash; Todos los derechos reservados &mdash; NIT: 900.123.456-7
    </footer>

</div>

<script>
const $ = id => document.getElementById(id);

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

function toggleEye(btn, input) {
    const hidden = input.type === 'password';
    input.type = hidden ? 'text' : 'password';
    btn.querySelector('i').className = hidden ? 'fas fa-eye-slash' : 'fas fa-eye';
}
$('togglePw') .addEventListener('click', () => toggleEye($('togglePw'),  $('password')));
$('toggleCpw').addEventListener('click', () => toggleEye($('toggleCpw'), $('confirm_password')));

function cedulaValida(v) {
    if (!/^\d{7,10}$/.test(v)) return false;   
    if (v[0] === '0') return false;            
    if (/^(.)\1+$/.test(v)) return false;       
    return true;
}

function validateNombre(live) {
    const v = $('nombre').value.trim();
    if (!live && v === '')  return setInvalid('nombre','ico-nombre','err-nombre','El nombre es obligatorio.');
    if (v === '')           return clearState('nombre','ico-nombre','err-nombre');
    if (!/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s]+$/.test(v))
                            return setInvalid('nombre','ico-nombre','err-nombre','Solo se permiten letras y espacios.');
    if (v.length > 100)     return setInvalid('nombre','ico-nombre','err-nombre','El nombre no puede superar 100 caracteres.');
    const palabras = v.split(/\s+/).filter(p => p.length >= 2);
    if (palabras.length < 2)
        return setInvalid('nombre','ico-nombre','err-nombre','Ingresa nombre y apellido. Ej: Luz Paz');
    setValid('nombre','ico-nombre','err-nombre');
}
$('nombre').addEventListener('input', () => validateNombre(true));
$('nombre').addEventListener('blur',  () => validateNombre(false));

function validateCedula(live) {
    $('cedula').value = $('cedula').value.replace(/\D/g,'');
    const v = $('cedula').value;
    if (!live && v === '') return setInvalid('cedula','ico-cedula','err-cedula','La cédula es obligatoria.');
    if (v === '')          return clearState('cedula','ico-cedula','err-cedula');
    if (v.length < 7)      return setInvalid('cedula','ico-cedula','err-cedula','La cédula debe tener mínimo 7 dígitos.');
    if (v[0] === '0')      return setInvalid('cedula','ico-cedula','err-cedula','La cédula no puede empezar en cero.');
    if (/^(.)\1+$/.test(v)) return setInvalid('cedula','ico-cedula','err-cedula','La cédula ingresada no es válida.');
    setValid('cedula','ico-cedula','err-cedula');
}
$('cedula').addEventListener('input', () => validateCedula(true));
$('cedula').addEventListener('blur',  () => validateCedula(false));

function validateTelefono(live) {
    $('telefono').value = $('telefono').value.replace(/\D/g,'');
    const v = $('telefono').value;
    if (!live && v === '') return setInvalid('telefono','ico-telefono','err-telefono','El teléfono es obligatorio.');
    if (v === '')          return clearState('telefono','ico-telefono','err-telefono');
    if (v.length < 10)     return setInvalid('telefono','ico-telefono','err-telefono','El teléfono debe tener exactamente 10 dígitos.');
    if (v[0] !== '3')      return setInvalid('telefono','ico-telefono','err-telefono','El teléfono debe ser un número móvil de Colombia (empieza por 3).');
    if (!/^3(0[0-9]|1[0-9]|2[0-9]|3[0-9]|5[0-9])\d{7}$/.test(v))
                           return setInvalid('telefono','ico-telefono','err-telefono','Número de celular no válido para Colombia.');
    if (/^3\d{2}(.)\1{6}$/.test(v))
                           return setInvalid('telefono','ico-telefono','err-telefono','El número de celular no parece válido.');
    setValid('telefono','ico-telefono','err-telefono');
}
$('telefono').addEventListener('input', () => validateTelefono(true));
$('telefono').addEventListener('blur',  () => validateTelefono(false));

const EMAIL_RE = /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9\-]+(\.[a-zA-Z0-9\-]+)*\.[a-zA-Z]{2,6}$/;

function validateEmail(live) {
    $('email').value = $('email').value.replace(/\s/g,'');
    const v = $('email').value;
    if (!live && v === '') return setInvalid('email','ico-email','err-email','El correo es obligatorio.');
    if (v === '')          return clearState('email','ico-email','err-email');
    if (!EMAIL_RE.test(v)) return setInvalid('email','ico-email','err-email','Correo inválido. Formato: nombre@dominio.com');
    if (v.split('@')[0].length < 6) return setInvalid('email','ico-email','err-email','La parte del correo antes del @ debe tener mínimo 6 caracteres. Ej: nombre@dominio.com');
    if (/^\d+$/.test(v.split('@')[0])) return setInvalid('email','ico-email','err-email','El correo no parece válido. Escribe una dirección real.');
    if (/^[^a-zA-Z]/.test(v.split('@')[0])) return setInvalid('email','ico-email','err-email','El correo debe comenzar con una letra.');
    if (v.length > 100)    return setInvalid('email','ico-email','err-email','El correo no puede superar 100 caracteres.');
    setValid('email','ico-email','err-email');
}
$('email').addEventListener('input', () => validateEmail(true));
$('email').addEventListener('blur',  () => validateEmail(false));
$('email').addEventListener('paste', e => {
    e.preventDefault();
    $('email').value = (e.clipboardData || window.clipboardData).getData('text').replace(/\s/g,'');
    validateEmail(true);
});
$('email').addEventListener('keydown', e => { if (e.key === ' ') e.preventDefault(); });

const PW_RULES = [
    { id:'r-len',   test: v => v.length >= 8 },
    { id:'r-upper', test: v => /[A-Z]/.test(v) },
    { id:'r-lower', test: v => /[a-z]/.test(v) },
    { id:'r-num',   test: v => /[0-9]/.test(v) },
    { id:'r-sym',   test: v => /[!@#$%^&*()\-_=+{};:,<.>]/.test(v) },
];

function validatePassword() {
    const v = $('password').value;
    let allOk = true;
    PW_RULES.forEach(r => {
        const ok = r.test(v);
        if (!ok) allOk = false;
        const el = $(r.id);
        el.classList.toggle('ok',  ok);
        el.classList.toggle('bad', !ok && v.length > 0);
        el.querySelector('i').className = ok ? 'fas fa-check' : 'fas fa-times';
    });
    if (v.length > 0) {
        $('password').classList.toggle('is-valid',   allOk);
        $('password').classList.toggle('is-invalid', !allOk);
    } else {
        $('password').classList.remove('is-valid','is-invalid');
    }
    validateConfirm();
    return allOk;
}

function validateConfirm() {
    const pw = $('password').value, cpw = $('confirm_password').value;
    if (cpw === '') { clearState('confirm_password',null,'err-confirm'); return false; }
    if (pw === cpw) {
        $('confirm_password').classList.add('is-valid'); $('confirm_password').classList.remove('is-invalid');
        $('err-confirm').textContent=''; $('err-confirm').classList.remove('visible');
        return true;
    }
    $('confirm_password').classList.add('is-invalid'); $('confirm_password').classList.remove('is-valid');
    $('err-confirm').textContent='Las contraseñas no coinciden.'; $('err-confirm').classList.add('visible');
    return false;
}

$('password')        .addEventListener('input', validatePassword);
$('confirm_password').addEventListener('input', validateConfirm);
$('confirm_password').addEventListener('blur',  validateConfirm);

function validateRol() {
    const v = $('rol').value;
    if (!v) return setInvalid('rol','ico-rol','err-rol','Selecciona un tipo de usuario.');
    setValid('rol','ico-rol','err-rol');
}
$('rol').addEventListener('change', validateRol);
$('rol').addEventListener('blur',   validateRol);

$('registerForm').addEventListener('submit', function(e) {
    validateNombre(false);
    validateCedula(false);
    validateTelefono(false);
    validateEmail(false);
    validateRol();
    const pwOk = validatePassword();
    const cpOk = validateConfirm();

    const first = document.querySelector('.is-invalid');
    if (first || !pwOk || !cpOk) {
        e.preventDefault();
        if (first) { first.scrollIntoView({ behavior:'smooth', block:'center' }); first.focus(); }
        return false;
    }

    const btn = $('submitBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registrando...';
    btn.disabled = true;
});

$('nombre').focus();
</script>
</body>
</html>
