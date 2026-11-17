<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'comprador') {
    header("Location: ../auth/login.php"); exit();
}

if (isset($_GET['descargar'])) {
    if (!empty($_SESSION['recibo_efectivo_pdf'])) {
        $nombre = $_SESSION['recibo_efectivo_pdf_nombre'] ?? 'Recibo-QuiliAgro.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . strlen($_SESSION['recibo_efectivo_pdf']));
        header('Cache-Control: private, no-cache');
        echo $_SESSION['recibo_efectivo_pdf'];
        unset($_SESSION['recibo_efectivo_pdf'], $_SESSION['recibo_efectivo_pdf_nombre']);
        exit;
    }
    header("Location: recibo_efectivo.php"); exit();
}

if (empty($_SESSION['recibo_efectivo'])) {
    header("Location: catalogo_comprador.php"); exit();
}

$re        = $_SESSION['recibo_efectivo'];
$tiene_pdf = !empty($_SESSION['recibo_efectivo_pdf']);
$nombre_pdf = $_SESSION['recibo_efectivo_pdf_nombre'] ?? 'Recibo-QuiliAgro.pdf';

unset($_SESSION['recibo_efectivo']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Recibo de Pago — QuiliAgro Connect</title>
<link rel="icon" type="image/png" href="../../imagenes/logo.png">
<link rel="stylesheet" href="../../css/styles.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
body { background: #f4f6f8; font-family: Arial, sans-serif; margin: 0; padding: 40px 16px; }
.wrap { max-width: 480px; margin: 0 auto; }

.success-card {
    background: #fff;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,.1);
    text-align: center;
}
.sc-top {
    background: linear-gradient(135deg, #2e7d32, #43a047);
    padding: 40px 32px 32px;
    color: white;
}
.sc-icon {
    width: 72px; height: 72px;
    background: rgba(255,255,255,.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
    font-size: 36px;
}
.sc-top h1 { margin: 0 0 6px; font-size: 22px; font-weight: 800; }
.sc-top p  { margin: 0; font-size: 13px; opacity: .8; }

.sc-body { padding: 28px 32px; }

.email-banner {
    background: #e8f5e9;
    border: 1.5px solid #a5d6a7;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    text-align: left;
}
.email-banner i { font-size: 24px; color: #2e7d32; flex-shrink: 0; }
.email-banner div { font-size: 13px; color: #2e7d32; line-height: 1.5; }
.email-banner strong { display: block; font-size: 14px; margin-bottom: 2px; }

.descargando-banner {
    background: #fff8e1;
    border: 1.5px solid #ffe082;
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: #7a4f00;
}
.descargando-banner i { font-size: 18px; color: #f57c00; }

.btn-row { display: flex; gap: 10px; flex-wrap: wrap; }
.btn-pdf {
    flex: 1;
    padding: 13px;
    background: linear-gradient(135deg, #e65100, #ff8f00);
    color: white; border: none; border-radius: 10px;
    font-size: 14px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    text-decoration: none; min-width: 140px;
    transition: filter .2s;
}
.btn-pdf:hover { filter: brightness(1.1); }
.btn-mis {
    flex: 1;
    padding: 13px;
    background: #fff; border: 1.5px solid #e0e0e0;
    color: #333; border-radius: 10px;
    font-size: 14px; font-weight: 700;
    text-decoration: none;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    min-width: 140px;
    transition: background .2s;
}
.btn-mis:hover { background: #f5f5f5; }

@keyframes spin { to { transform: rotate(360deg); } }
.spin-icon { display: inline-block; animation: spin 1s linear infinite; }
</style>
</head>
<body>
<div class="wrap">
  <div class="success-card">
    <div class="sc-top">
      <div class="sc-icon">✅</div>
      <h1>¡Pedido registrado!</h1>
      <p>Recuerda pagar antes de que expire el plazo</p>
    </div>
    <div class="sc-body">

      <div class="email-banner">
        <i class="bi bi-envelope-check-fill"></i>
        <div>
          <strong>Recibo enviado a tu correo</strong>
          El PDF fue adjuntado al correo electrónico registrado en tu cuenta.
        </div>
      </div>

      <?php if ($tiene_pdf): ?>
      <div class="descargando-banner" id="bannerDescarga">
        <i class="bi bi-arrow-down-circle-fill spin-icon"></i>
        <span>Descargando tu recibo PDF...</span>
      </div>
      <?php endif; ?>

      <div class="btn-row">
        <?php if ($tiene_pdf): ?>
        <a href="recibo_efectivo.php?descargar=1" class="btn-pdf" id="btnDescarga">
          <i class="bi bi-file-earmark-pdf-fill"></i> Descargar PDF
        </a>
        <?php endif; ?>
        <a href="pedidos_comprador.php" class="btn-mis">
          <i class="bi bi-clipboard-check"></i> Mis Pedidos
        </a>
      </div>

    </div>
  </div>
</div>

<?php if ($tiene_pdf): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () {
        const a = document.createElement('a');
        a.href = 'recibo_efectivo.php?descargar=1';
        a.download = '<?php echo addslashes($nombre_pdf); ?>';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        setTimeout(function () {
            const banner = document.getElementById('bannerDescarga');
            if (banner) {
                banner.innerHTML = '<i class="bi bi-check-circle-fill" style="color:#2e7d32;font-size:18px;"></i>'
                                 + '<span>Recibo descargado correctamente</span>';
                banner.style.background = '#e8f5e9';
                banner.style.borderColor = '#a5d6a7';
                banner.style.color = '#2e7d32';
            }
        }, 2500);
    }, 800);
});
</script>
<?php endif; ?>

</body>
</html>