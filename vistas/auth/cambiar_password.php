<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$error   = '';
$success = '';
$id_usuario = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password_actual  = $_POST['password_actual']  ?? '';
    $password_nueva   = $_POST['password_nueva']   ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (empty($password_actual) || empty($password_nueva) || empty($password_confirm)) {
        $error = 'Por favor completa todos los campos.';

    } elseif ($password_nueva !== $password_confirm) {
        $error = 'La nueva contraseña y su confirmación no coinciden.';

    } elseif (strlen($password_nueva) < 8) {
        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';

    } elseif (!preg_match('/[A-Z]/', $password_nueva)) {
        $error = 'La nueva contraseña debe contener al menos una letra mayúscula.';

    } elseif (!preg_match('/[a-z]/', $password_nueva)) {
        $error = 'La nueva contraseña debe contener al menos una letra minúscula.';

    } elseif (!preg_match('/[0-9]/', $password_nueva)) {
        $error = 'La nueva contraseña debe contener al menos un número.';

    } elseif (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password_nueva)) {
        $error = 'La nueva contraseña debe contener al menos un símbolo (!@#$%^&*()-_=+{};:,<.>).';

    } else {
        try {
            $database = new Database();
            $conn     = $database->getConnection();

            $stmt = $conn->prepare("SELECT password FROM usuarios WHERE id_usuario = :id");
            $stmt->bindParam(':id', $id_usuario);
            $stmt->execute();
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario || !password_verify($password_actual, $usuario['password'])) {
                $error = 'La contraseña actual es incorrecta.';
            } elseif (password_verify($password_nueva, $usuario['password'])) {
                $error = 'La nueva contraseña no puede ser igual a la contraseña actual.';
            } else {
                $hash = password_hash($password_nueva, PASSWORD_DEFAULT);
                $upd  = $conn->prepare("UPDATE usuarios SET password = :password WHERE id_usuario = :id");
                $upd->bindParam(':password', $hash);
                $upd->bindParam(':id', $id_usuario);
                $upd->execute();

                session_unset();
                session_destroy();
                header('Location: login.php?msg=password_cambiada');
                exit();
            }

        } catch (PDOException $e) {
            $error = 'Error en el sistema. Intenta más tarde.';
            error_log("Cambiar password error: " . $e->getMessage());
        }
    }
}

$back_url = '#';
switch ($_SESSION['user_rol'] ?? '') {
    case 'administrador': $back_url = '../admin/panel.php';       break;
    case 'productor':     $back_url = '../productor/panel_productor.php'; break;
    case 'comprador':     $back_url = '../comprador/panel_comprador.php'; break;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Contraseña - QuiliAgro Connect</title>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/quiliagro_connect/config/pwa_head.php'; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }

        body {
            background: linear-gradient(135deg, #e4e5e9 0%, #edebf0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 28px 20px;
            text-align: center;
        }
        .card-header .logo {
            width: 80px; height: 80px;
            object-fit: contain; border-radius: 50%;
            background: white; padding: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            border: 3px solid rgba(255,255,255,0.3);
            margin-bottom: 12px;
        }
        .card-header h1 { font-size: 22px; font-weight: 600; margin-bottom: 4px; }
        .card-header p  { font-size: 13px; opacity: .9; }

        .card-body { padding: 28px 26px; }

        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px; border-radius: 8px;
            margin-bottom: 20px; font-size: 14px;
        }
        .alert i { margin-top: 1px; flex-shrink: 0; }
        .alert-error   { background: #fee; color: #c33; border-left: 4px solid #c33; }
        .alert-success { background: #efe; color: #2a7a2a; border-left: 4px solid #28a745; }

        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; margin-bottom: 6px;
            color: #333; font-weight: 600; font-size: 14px;
        }
        .input-wrap { position: relative; }
        .input-wrap .icon-left {
            position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%); color: #888; pointer-events: none;
        }
        .input-wrap input {
            width: 100%; padding: 11px 44px 11px 42px;
            border: 2px solid #ddd; border-radius: 8px;
            font-size: 15px; transition: border-color .25s, box-shadow .25s;
        }
        .input-wrap input:focus {
            outline: none; border-color: #28a745;
            box-shadow: 0 0 0 3px rgba(40,167,69,.12);
        }
        .input-wrap input.is-invalid { border-color: #dc3545; }
        .toggle-btn {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; color: #888;
            cursor: pointer; padding: 4px;
        }
        .toggle-btn:hover { color: #555; }

        .requirements { margin-top: 8px; }
        .req {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: #888; margin-bottom: 3px; transition: color .2s;
        }
        .req.ok  { color: #28a745; }
        .req.bad { color: #dc3545; }
        .req i   { font-size: 10px; width: 12px; }

        .btn-submit {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white; border: none; border-radius: 8px;
            font-size: 16px; font-weight: 600;
            cursor: pointer; transition: transform .25s, box-shadow .25s;
            margin-top: 6px;
        }
        .btn-submit:hover  { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(40,167,69,.4); }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit:disabled { opacity: .65; cursor: not-allowed; transform: none; box-shadow: none; }

        .back-link {
            text-align: center; margin-top: 16px; padding-top: 16px;
            border-top: 1px solid #eee; font-size: 14px;
        }
        .back-link a { color: #28a745; text-decoration: none; font-weight: 600; }
        .back-link a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            .card { border-radius: 10px; }
            .card-header { padding: 22px 15px; }
            .card-body    { padding: 22px 18px; }
        }
    </style>
</head>
<body>

<div class="card">
    <div class="card-header">
        <img src="../../imagenes/logo.png" alt="Logo" class="logo">
        <h1>Cambiar Contraseña</h1>
        <p>Hola, <?php echo htmlspecialchars($_SESSION['user_nombre'] ?? 'usuario'); ?></p>
    </div>

    <div class="card-body">

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="changePassForm" novalidate>

            <div class="form-group">
                <label for="password_actual">Contraseña actual</label>
                <div class="input-wrap">
                    <i class="fas fa-lock icon-left"></i>
                    <input type="password" id="password_actual" name="password_actual"
                           placeholder="Ingresa tu contraseña actual" maxlength="72" required autocomplete="current-password">
                    <button type="button" class="toggle-btn" data-target="password_actual" aria-label="Mostrar contraseña">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="password_nueva">Nueva contraseña</label>
                <div class="input-wrap">
                    <i class="fas fa-key icon-left"></i>
                    <input type="password" id="password_nueva" name="password_nueva"
                           placeholder="Mínimo 8 caracteres" maxlength="72" required autocomplete="new-password">
                    <button type="button" class="toggle-btn" data-target="password_nueva" aria-label="Mostrar contraseña">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="requirements">
                    <div class="req" id="r-len"><i class="fas fa-times"></i> Mínimo 8 caracteres</div>
                    <div class="req" id="r-up"> <i class="fas fa-times"></i> Al menos una mayúscula</div>
                    <div class="req" id="r-low"><i class="fas fa-times"></i> Al menos una minúscula</div>
                    <div class="req" id="r-num"><i class="fas fa-times"></i> Al menos un número</div>
                    <div class="req" id="r-sym"><i class="fas fa-times"></i> Al menos un símbolo (!@#$%^&amp;*…)</div>
                </div>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirmar nueva contraseña</label>
                <div class="input-wrap">
                    <i class="fas fa-lock icon-left"></i>
                    <input type="password" id="password_confirm" name="password_confirm"
                           placeholder="Repite la nueva contraseña" maxlength="72" required autocomplete="new-password">
                    <button type="button" class="toggle-btn" data-target="password_confirm" aria-label="Mostrar contraseña">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="requirements" id="match-wrap" style="display:none;">
                    <div class="req" id="r-match"><i class="fas fa-times"></i> Las contraseñas no coinciden</div>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <i class="fas fa-save"></i> Guardar nueva contraseña
            </button>
        </form>

        <div class="back-link">
            <a href="<?php echo htmlspecialchars($back_url); ?>">
                <i class="fas fa-arrow-left"></i> Volver al panel
            </a>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.toggle-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.target);
            const icon  = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    });

    const rules = [
        { id: 'r-len', test: v => v.length >= 8 },
        { id: 'r-up',  test: v => /[A-Z]/.test(v) },
        { id: 'r-low', test: v => /[a-z]/.test(v) },
        { id: 'r-num', test: v => /[0-9]/.test(v) },
        { id: 'r-sym', test: v => /[!@#$%^&*()\-_=+{};:,<.>]/.test(v) },
    ];

    function setReq(id, valid) {
        const el = document.getElementById(id);
        el.classList.toggle('ok',  valid);
        el.classList.toggle('bad', !valid);
        el.querySelector('i').className = valid ? 'fas fa-check' : 'fas fa-times';
    }

    function allRulesOk(val) {
        return rules.every(r => r.test(val));
    }

    document.getElementById('password_nueva').addEventListener('input', function () {
        rules.forEach(r => setReq(r.id, r.test(this.value)));
        document.getElementById('password_nueva').classList.toggle('is-invalid', this.value && !allRulesOk(this.value));
        checkMatch();
    });

    document.getElementById('password_confirm').addEventListener('input', checkMatch);

    function checkMatch() {
        const a = document.getElementById('password_nueva').value;
        const b = document.getElementById('password_confirm').value;
        if (!b) { document.getElementById('match-wrap').style.display = 'none'; return; }
        document.getElementById('match-wrap').style.display = 'block';
        const ok = a === b;
        setReq('r-match', ok);
        document.getElementById('r-match').querySelector('span') // fallback: update text
        const rMatch = document.getElementById('r-match');
        rMatch.childNodes[1] && (rMatch.childNodes[1].textContent = ok ? ' Las contraseñas coinciden' : ' Las contraseñas no coinciden');
        document.getElementById('password_confirm').classList.toggle('is-invalid', !ok);
    }

    document.getElementById('changePassForm').addEventListener('submit', function (e) {
        const nueva   = document.getElementById('password_nueva').value;
        const confirm = document.getElementById('password_confirm').value;

        if (!allRulesOk(nueva)) {
            e.preventDefault();
            document.getElementById('password_nueva').focus();
            return;
        }
        if (nueva !== confirm) {
            e.preventDefault();
            document.getElementById('password_confirm').focus();
            return;
        }

        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
    });

    document.getElementById('password_actual').focus();
</script>
</body>
</html>