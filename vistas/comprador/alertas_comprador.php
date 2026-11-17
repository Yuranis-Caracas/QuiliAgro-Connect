<?php
session_start();
date_default_timezone_set('America/Bogota');
require_once __DIR__ . '/../../config/conexion.php';
define('ROL_REQUERIDO', 'comprador');
require_once __DIR__ . '/../../config/auth_check.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'comprador') {
    header('Location: ../../auth/login.php');
    exit();
}

$idComprador = (int)$_SESSION['user_id'];

if (isset($_GET['action']) && $_GET['action'] === 'contar') {
    header('Content-Type: application/json');
    $st = $conn->prepare("SELECT COUNT(*) FROM alertas WHERE id_comprador=? AND leida=0");
    $st->bind_param("i", $idComprador);
    $st->execute();
    echo json_encode(['total' => (int)$st->get_result()->fetch_row()[0]]);
    exit();
}

$conn->prepare("UPDATE alertas SET leida=1 WHERE id_comprador=? AND leida=0")
     ->bind_param("i", $idComprador) || null;
$stMark = $conn->prepare("UPDATE alertas SET leida=1 WHERE id_comprador=? AND leida=0");
$stMark->bind_param("i", $idComprador);
$stMark->execute();

$st = $conn->prepare(
    "SELECT a.*, p.nombre AS nombre_producto, u.nombre AS nombre_productor
     FROM alertas a
     LEFT JOIN productos p ON p.id_producto = a.id_producto
     LEFT JOIN usuarios  u ON u.id_usuario  = a.id_productor
     WHERE a.id_comprador = ?
     ORDER BY a.fecha_creacion DESC
     LIMIT 20"
);
$st->bind_param("i", $idComprador);
$st->execute();
$alertas = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$config = [
    'nuevo_producto'      => ['icon' => 'bi-box-seam',     'color' => '#28a745'],
    'producto_disponible' => ['icon' => 'bi-check-circle', 'color' => '#1976d2'],
    'nuevo_productor'     => ['icon' => 'bi-person-badge', 'color' => '#e53935'],
    'promocion'           => ['icon' => 'bi-tag',          'color' => '#f57f17'],
];

$grupos = ['Hoy' => [], 'Ayer' => [], 'Esta semana' => [], 'Anteriores' => []];
$ahora  = time();
foreach ($alertas as $a) {
    $ts   = strtotime($a['fecha_creacion']);
    $dias = ($ahora - $ts) / 86400;
    if ($dias < 1)      $grupos['Hoy'][]          = $a;
    elseif ($dias < 2)  $grupos['Ayer'][]          = $a;
    elseif ($dias < 7)  $grupos['Esta semana'][]   = $a;
    else                $grupos['Anteriores'][]     = $a;
}
$grupos = array_filter($grupos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificaciones — QuiliAgro Connect</title>
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #f5f5f5;
            font-family: 'Segoe UI', Tahoma, sans-serif;
            min-height: 100vh;
        }

        .header {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: #fff;
            padding: 14px 18px;
            display: flex; align-items: center; gap: 12px;
            position: sticky; top: 0; z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,.12);
        }
        .header a {
            color: #fff; text-decoration: none;
            font-size: 20px; opacity: .85; line-height: 1;
        }
        .header a:hover { opacity: 1; }
        .header h1 { font-size: 18px; font-weight: 700; flex: 1; }

        .wrap { max-width: 620px; margin: 0 auto; padding: 16px 14px 40px; }

        .grupo-label {
            font-size: 13px; font-weight: 700;
            color: #888; text-transform: uppercase;
            letter-spacing: .5px;
            padding: 20px 4px 8px;
        }
        .grupo-label:first-child { padding-top: 4px; }

        .notif-card {
            background: #fff;
            border-radius: 0;
            display: flex; align-items: flex-start; gap: 14px;
            padding: 16px 18px;
            border-bottom: 1px solid #f2f2f2;
        }
        .notif-card:first-of-type { border-radius: 12px 12px 0 0; }
        .notif-card:last-of-type  { border-bottom: none; border-radius: 0 0 12px 12px; }
        .notif-card:only-of-type  { border-radius: 12px; }

        .notif-ico {
            width: 46px; height: 46px; border-radius: 50%;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 19px;
        }

        .notif-text { flex: 1; min-width: 0; }
        .notif-title {
            font-size: 14px; font-weight: 600;
            color: #1a1a1a; line-height: 1.4;
            margin-bottom: 3px;
        }
        .notif-desc {
            font-size: 13px; color: #777;
            line-height: 1.45;
        }
        .notif-time {
            font-size: 12px; color: #bbb;
            margin-top: 5px;
        }

        .empty {
            text-align: center;
            padding: 80px 20px;
        }
        .empty-icon {
            width: 90px; height: 90px; border-radius: 50%;
            background: #f0f0f0;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            font-size: 38px; color: #ccc;
        }
        .empty h3 { font-size: 18px; color: #555; margin-bottom: 8px; }
        .empty p  { font-size: 14px; color: #aaa; }

        @media(max-width: 480px) {
            .wrap { padding: 12px 8px 32px; }
            .notif-card { padding: 14px 14px; }
        }
    </style>
</head>
<body>

<div class="header">
    <a href="panel_comprador.php"><i class="bi bi-arrow-left"></i></a>
    <h1>Notificaciones</h1>
</div>

<div class="wrap">

<?php if (empty($alertas)): ?>
    <div class="empty">
        <div class="empty-icon"><i class="bi bi-bell-slash"></i></div>
        <h3>Sin notificaciones</h3>
        <p>Aquí aparecerán alertas personalizadas<br>según tus búsquedas y compras.</p>
    </div>

<?php else:
    foreach ($grupos as $etiqueta => $items):
?>
    <div class="grupo-label"><?= $etiqueta ?></div>

    <?php foreach ($items as $a):
        $ta  = $a['tipo_alerta'];
        $cfg = $config[$ta] ?? ['icon' => 'bi-bell', 'color' => '#28a745'];

        $msg = $a['mensaje'];
        $titulo = $msg;
        $desc   = '';

        if (preg_match('/^([^"]+)"([^"]+)"(.*)$/u', $msg, $m)) {
            $titulo = trim($m[1]) . ' ' . '"' . $m[2] . '"';
            $desc   = trim($m[3]);
            if (str_starts_with($desc, 'en ') || str_starts_with($desc, '— ')) {
                $desc = ltrim($desc, '— ');
            }
        }

        $diff = time() - strtotime($a['fecha_creacion']);
        if ($diff < 60)        $cuando = 'Ahora';
        elseif ($diff < 3600)  $cuando = 'Hace ' . round($diff/60) . ' min';
        elseif ($diff < 86400) $cuando = 'Hace ' . round($diff/3600) . 'h';
        else                   $cuando = date('d/m/Y', strtotime($a['fecha_creacion']));
    ?>
    <div class="notif-card">
        <div class="notif-ico"
             style="background:<?= $cfg['color'] ?>18; color:<?= $cfg['color'] ?>">
            <i class="bi <?= $cfg['icon'] ?>"></i>
        </div>
        <div class="notif-text">
            <div class="notif-title"><?= htmlspecialchars($titulo) ?></div>
            <?php if ($desc): ?>
            <div class="notif-desc"><?= htmlspecialchars($desc) ?></div>
            <?php endif; ?>
            <div class="notif-time"><?= $cuando ?></div>
        </div>
    </div>
    <?php endforeach; ?>

<?php endforeach; endif; ?>
</div>

</body>
</html>