<?php
session_start();
date_default_timezone_set('America/Bogota');
require_once __DIR__ . '/../../config/conexion.php';
define('ROL_REQUERIDO', 'comprador');
require_once __DIR__ . '/../../config/auth_check.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'comprador') {
    header("Location: ../auth/login.php"); exit();
}
if (empty($_SESSION['pago_aprobado'])) {
    header("Location: catalogo_comprador.php"); exit();
}

$pa = $_SESSION['pago_aprobado'];
unset($_SESSION['pago_aprobado']);

require_once '../../config/conexion.php';
$st_num = $conn->prepare("
    SELECT numero_orden FROM (
        SELECT id_orden,
               ROW_NUMBER() OVER (PARTITION BY id_comprador ORDER BY fecha) AS numero_orden
        FROM ordenes
        WHERE id_comprador = ?
    ) t
    WHERE id_orden = ?
");
$st_num->bind_param('ii', $_SESSION['user_id'], $pa['id_orden']);
$st_num->execute();
$row_num = $st_num->get_result()->fetch_assoc();
$numero_orden_usuario = $row_num ? $row_num['numero_orden'] : $pa['id_orden'];


$tipo_label = [
    'tarjeta'       => 'Tarjeta de crédito / débito',
    'billetera'     => 'Billetera digital',
    'pse'           => 'PSE — Débito bancario',
    'transferencia' => 'Transferencia bancaria',
    'efectivo'      => 'Pago en efectivo',
];
$metodo_txt = $tipo_label[$pa['tipo'] ?? ''] ?? ucfirst($pa['tipo'] ?? 'Electrónico');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Pago Aprobado — QuiliAgro Connect</title>
<link rel="icon" type="image/png" href="../../imagenes/logo.png">
<link rel="stylesheet" href="../../css/styles.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f0f5f0;--white:#ffffff;--border:#d6e8d6;
  --green:#2e7d32;--green-l:#43a047;--green-xl:#e8f5e9;--green-xxl:#f1f9f1;
  --text:#1b2e1b;--muted:#6b8c6b;
  --shadow:0 2px 16px rgba(46,125,50,.10);--shadow-md:0 8px 36px rgba(46,125,50,.16);
  --radius:20px;--radius-sm:12px;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;}

.ap-wrap{max-width:500px;width:100%;}

.ap-brand{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:28px;}
.ap-brand img{height:32px;}
.ap-brand span{font-size:15px;font-weight:800;color:var(--green);}

.ap-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:44px 40px 36px;box-shadow:var(--shadow-md);text-align:center;}

.ap-icon{width:80px;height:80px;border-radius:50%;background:var(--green-xl);border:3px solid rgba(46,125,50,.2);display:flex;align-items:center;justify-content:center;margin:0 auto 24px;animation:scaleIn .4s cubic-bezier(.34,1.56,.64,1) both;}
@keyframes scaleIn{from{opacity:0;transform:scale(.5);}to{opacity:1;transform:scale(1);}}
.ap-icon i{font-size:36px;color:var(--green);}

.ap-titulo{font-size:26px;font-weight:800;color:var(--text);margin-bottom:6px;}
.ap-sub{font-size:14px;color:var(--muted);line-height:1.5;margin-bottom:32px;}

.ap-badge{display:inline-flex;align-items:center;gap:6px;background:var(--green-xl);border:1.5px solid rgba(46,125,50,.25);color:var(--green);font-size:12px;font-weight:700;padding:6px 14px;border-radius:20px;margin-bottom:28px;}

.ap-datos{background:var(--green-xxl);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:28px;text-align:left;}
.ap-datos-row{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid var(--border);font-size:14px;}
.ap-datos-row:last-child{border-bottom:none;}
.adr-l{color:var(--muted);font-weight:500;}
.adr-v{font-weight:700;color:var(--text);text-align:right;}
.adr-v.mono{font-family:'JetBrains Mono',monospace;font-size:12px;letter-spacing:.5px;}
.adr-v.total{font-size:20px;color:var(--green);}

.ap-ref{background:var(--green-xl);border:1.5px solid rgba(46,125,50,.2);border-radius:var(--radius-sm);padding:14px 18px;margin-bottom:28px;text-align:center;}
.ap-ref-lbl{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px;}
.ap-ref-code{font-family:'JetBrains Mono',monospace;font-size:15px;font-weight:700;color:var(--green);letter-spacing:1px;}

.ap-info{background:#fff;border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px;margin-bottom:28px;display:flex;align-items:flex-start;gap:10px;text-align:left;font-size:13px;color:var(--muted);line-height:1.6;}
.ap-info i{color:var(--green);font-size:16px;flex-shrink:0;margin-top:1px;}

.ap-btns{display:flex;gap:10px;}
.btn-primary{flex:1;padding:13px;background:linear-gradient(135deg,var(--green),var(--green-l));color:white;border:none;border-radius:var(--radius-sm);font-size:14px;font-weight:800;font-family:'Plus Jakarta Sans',sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:all .18s;}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(46,125,50,.35);}
.btn-secondary{flex:1;padding:13px;background:white;color:var(--muted);border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:14px;font-weight:700;font-family:'Plus Jakarta Sans',sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:all .18s;}
.btn-secondary:hover{border-color:var(--green);color:var(--green);}

.ap-footer{text-align:center;margin-top:20px;font-size:11px;color:var(--muted);}
.ap-footer a{color:var(--green);font-weight:600;text-decoration:none;}
</style>
</head>
<body>
<div class="ap-wrap">

  <div class="ap-brand">
    <img src="../../imagenes/logo.png" alt="QuiliAgro">
    <span>QuiliAgro Connect</span>
  </div>

  <div class="ap-card">

    <div class="ap-icon">
      <i class="bi bi-check2-circle"></i>
    </div>

    <h1 class="ap-titulo">Pago Verificado</h1>
    <p class="ap-sub">Tu pago ha sido confirmado exitosamente.<br>Tu pedido está siendo procesado.</p>

    <div class="ap-badge">
      <i class="bi bi-shield-check-fill"></i> Transacción aprobada
    </div>

    <div class="ap-datos">
      <div class="ap-datos-row">
        <span class="adr-l">Número de pedido</span>
        <span class="adr-v">#<?php echo $numero_orden_usuario; ?></span>
      </div>
      <div class="ap-datos-row">
        <span class="adr-l">Método de pago</span>
        <span class="adr-v"><?php echo htmlspecialchars($metodo_txt); ?></span>
      </div>
      <div class="ap-datos-row">
        <span class="adr-l">Estado</span>
        <span class="adr-v" style="color:var(--green);">Confirmado</span>
      </div>
      <div class="ap-datos-row">
        <span class="adr-l">Fecha y hora</span>
        <span class="adr-v"><?php echo date('d/m/Y \a \l\a\s H:i'); ?></span>
      </div>
      <div class="ap-datos-row">
        <span class="adr-l">Total pagado</span>
        <span class="adr-v total">$<?php echo number_format($pa['total'], 0, '.', ','); ?></span>
      </div>
    </div>

    <div class="ap-ref">
      <div class="ap-ref-lbl">Número de referencia</div>
      <div class="ap-ref-code"><?php echo htmlspecialchars($pa['referencia']); ?></div>
    </div>

    <div class="ap-info">
      <i class="bi bi-envelope-check"></i>
      <span>Hemos enviado la confirmación de tu compra a tu correo electrónico. Guarda tu número de referencia para cualquier consulta.</span>
    </div>

    <div class="ap-btns">
      <a href="pedidos_comprador.php" class="btn-primary">
        <i class="bi bi-clipboard-check"></i> Ver mis pedidos
      </a>
      <a href="catalogo_comprador.php" class="btn-secondary">
        <i class="bi bi-shop"></i> Seguir comprando
      </a>
    </div>

  </div>

</div>
</body>
</html>