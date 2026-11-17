<?php
session_start();
require_once __DIR__ . '/../../config/conexion.php'; 
define('ROL_REQUERIDO', 'comprador');
require_once __DIR__ . '/../../config/auth_check.php';
date_default_timezone_set('America/Bogota');
require_once '../../config/mail.php';

require_once __DIR__ . '/../../dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

$meses_es = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
define('EFECTY_CONVENIO', '110528');
define('EFECTY_NOMBRE',   'QuiliAgro Connect');

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'comprador') {
    header("Location: ../auth/login.php"); exit();
}

$usuario_id     = intval($_SESSION['user_id']);
$usuario_nombre = htmlspecialchars($_SESSION['user_nombre'] ?? '');

if (empty($_SESSION['carrito'])) {
    $_SESSION['mensaje_temporal']      = "Tu carrito está vacío";
    $_SESSION['tipo_mensaje_temporal'] = "warning";
    header("Location: catalogo_comprador.php"); exit();
}

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

function generarReciboPDFDompdf(array $d): string {
    $convenio  = htmlspecialchars($d['convenio']       ?? '');
    $empresa   = htmlspecialchars($d['empresa']        ?? '');
    $referencia= htmlspecialchars($d['referencia']     ?? '');
    $total     = number_format($d['total']             ?? 0, 0, '.', ',');
    $vence     = htmlspecialchars($d['fecha_vence']    ?? '');
    $id_orden  = htmlspecialchars($d['id_orden']       ?? '');
    $nombre    = htmlspecialchars($d['nombre_cliente'] ?? '');
    $emision   = htmlspecialchars($d['fecha_emision']  ?? '');
    $productos = $d['productos'] ?? [];

    $filas = '';
    $gran_total = 0;
    foreach ($productos as $p) {
        $sub = $p['precio'] * $p['cantidad'];
        $gran_total += $sub;
        $filas .= '<tr>
            <td>' . htmlspecialchars($p['nombre']) . '</td>
            <td style="text-align:center;">' . $p['cantidad'] . ' ' . htmlspecialchars($p['unidad_medida']) . '</td>
            <td style="text-align:right;">$ ' . number_format($p['precio'], 0, '.', ',') . '</td>
            <td style="text-align:right;font-weight:bold;">$ ' . number_format($sub, 0, '.', ',') . '</td>
        </tr>';
    }

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222;padding:30px;}
  .header{text-align:center;border-bottom:2px solid #333;padding-bottom:12px;margin-bottom:16px;}
  .header h1{font-size:18px;font-weight:bold;margin-bottom:3px;}
  .ref-box{border:1px solid #ccc;padding:12px 16px;margin-bottom:14px;}
  .ref-num{font-size:20px;font-weight:bold;font-family:"Courier New",monospace;letter-spacing:2px;}
  .total{font-size:22px;font-weight:bold;}
  .vence{font-size:11px;color:#b34700;font-weight:bold;}
  .instruccion{background:#fffbe6;border:1px solid #ffe082;padding:10px 12px;margin-bottom:14px;font-size:10.5px;line-height:1.6;}
  .datos-table{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:10px;}
  .datos-table th{background:#f0f0f0;padding:6px 8px;text-align:left;border:1px solid #ccc;}
  .datos-table td{padding:7px 8px;border:1px solid #ddd;vertical-align:top;line-height:1.6;}
  .prod-table{width:100%;border-collapse:collapse;margin-bottom:6px;font-size:10px;}
  .prod-table th{background:#e8e8e8;padding:6px 8px;border:1px solid #ccc;font-weight:bold;}
  .prod-table td{padding:5px 8px;border:1px solid #ddd;}
  .prod-table tr:nth-child(even) td{background:#f9f9f9;}
  .total-row td{font-weight:bold;border-top:2px solid #666;background:#f5f5f5;font-size:11px;}
  .footer{margin-top:16px;border-top:1px solid #ccc;padding-top:10px;text-align:center;font-size:9px;color:#888;line-height:1.6;}
  .row2col{display:table;width:100%;}
  .col-left{display:table-cell;width:50%;vertical-align:top;padding-right:8px;}
  .col-right{display:table-cell;width:50%;vertical-align:top;padding-left:8px;text-align:right;}
  .label{font-size:9px;color:#888;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;}
  .value{font-size:13px;font-weight:bold;}
</style></head><body>
<div class="header"><h1>' . $empresa . '</h1><p>Recibo de Pago en Efectivo &nbsp;|&nbsp; Orden #' . $id_orden . '</p></div>
<div class="ref-box">
  <div class="row2col">
    <div class="col-left">
      <div class="label">Numero de pago</div>
      <div class="ref-num">' . $referencia . '</div>
      <div style="margin-top:8px;font-size:10px;color:#555;">Convenio No. <strong>' . $convenio . '</strong> &nbsp;·&nbsp; A nombre de: <strong>' . $empresa . '</strong></div>
    </div>
    <div class="col-right">
      <div class="label">Total a pagar</div>
      <div class="total">$ ' . $total . '</div>
      <div class="vence" style="margin-top:6px;">Paga antes de:<br>' . $vence . '</div>
    </div>
  </div>
</div>
<div class="instruccion"><strong>Instrucciones:</strong> Presenta este recibo en cualquier punto <strong>EFECTY</strong> o <strong>Sured</strong> del pais. Indica el convenio <strong>' . $convenio . '</strong> de <strong>' . $empresa . '</strong> al cajero.</div>
<table class="datos-table">
  <tr><th width="50%">Datos de la compra</th><th width="50%">Datos del pagador</th></tr>
  <tr>
    <td><strong>Tienda:</strong> ' . $empresa . '<br><strong>Orden #:</strong> ' . $id_orden . '<br><strong>Fecha emision:</strong> ' . $emision . '<br><strong>Convenio:</strong> ' . $convenio . '</td>
    <td><strong>Nombre:</strong> ' . $nombre . '</td>
  </tr>
</table>
<table class="prod-table">
  <thead><tr>
    <th style="text-align:left;width:45%;">Producto</th>
    <th style="text-align:center;width:20%;">Cantidad</th>
    <th style="text-align:right;width:17%;">Precio unit.</th>
    <th style="text-align:right;width:18%;">Subtotal</th>
  </tr></thead>
  <tbody>' . $filas . '
    <tr class="total-row">
      <td colspan="3" style="text-align:right;">TOTAL</td>
      <td style="text-align:right;">$ ' . number_format($gran_total, 0, '.', ',') . '</td>
    </tr>
  </tbody>
</table>
<div class="footer">Si tienes alguna duda sobre tu pedido, contactanos directamente.<br>Toda la informacion se encuentra protegida para tu privacidad y seguridad. &copy; ' . $empresa . '</div>
</body></html>';

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_pago'])) {

    $id_metodo = intval($_POST['id_metodo_pago'] ?? 0);
    $rm = qry($conn, "SELECT * FROM metodos_pago WHERE id_metodo = ? AND activo = 1", 'i', [$id_metodo]);
    if (!$rm || $rm->num_rows === 0) {
        $_SESSION['error_pago'] = "Método de pago no válido.";
        header("Location: pasarela_pago.php"); exit();
    }
    $metodo = $rm->fetch_assoc();
    $tipo   = $metodo['tipo'];
    $total  = array_reduce($_SESSION['carrito'], fn($c, $i) => $c + $i['precio'] * $i['cantidad'], 0);

    $es_reintento   = isset($_SESSION['reintento_orden_id']);
    $id_orden_previo = $es_reintento ? intval($_SESSION['reintento_orden_id']) : null;
    unset($_SESSION['reintento_orden_id']); 

    $detalles = [];
    switch ($tipo) {
        case 'tarjeta':
            $num = preg_replace('/\s/', '', $_POST['num_tarjeta'] ?? '');
            $detalles = [
                'titular'      => strtoupper(trim($_POST['titular'] ?? '')),
                'num_tarjeta'  => '****' . substr($num, -4),
                'vencimiento'  => $_POST['vencimiento'] ?? '',
                'tipo_tarjeta' => strlen($num) === 15 ? 'AMEX' : (substr($num,0,1)==='4' ? 'VISA' : 'MASTERCARD'),
            ];
            break;
        case 'billetera':
            $detalles = [
                'telefono'  => preg_replace('/\D/', '', $_POST['telefono'] ?? ''),
                'proveedor' => $metodo['nombre_metodo'],
            ];
            break;
        case 'pse':
            $detalles = [
                'banco'          => $_POST['banco']         ?? '',
                'tipo_persona'   => $_POST['tipo_persona']  ?? '',
                'tipo_documento' => $_POST['tipo_doc']      ?? '',
                'documento'      => $_POST['documento_pse'] ?? '',
            ];
            break;
        case 'transferencia':
            $detalles = [
                'banco_origen'   => $_POST['banco_origen']      ?? '',
                'num_cuenta'     => $_POST['num_cuenta']        ?? '',
                'tipo_cuenta'    => $_POST['tipo_cuenta']       ?? '',
                'titular_cuenta' => strtoupper($_POST['titular_cuenta'] ?? ''),
                'documento'      => $_POST['doc_transferencia'] ?? '',
            ];
            break;
        case 'efectivo':
            $detalles = ['punto_pago' => 'Efecty / Sured'];
            break;
    }

    $conn->begin_transaction();
    try {

        if ($es_reintento && $id_orden_previo) {
            $vc = qry($conn,
                "SELECT id_orden FROM ordenes WHERE id_orden = ? AND id_comprador = ? AND estado = 'pendiente'",
                'ii', [$id_orden_previo, $usuario_id]
            );
            if (!$vc || $vc->num_rows === 0) {
                throw new Exception("La orden no es válida para reintentar el pago.");
            }
            $id_orden = $id_orden_previo;

        } else {
            $so = qry($conn, "INSERT INTO ordenes (id_comprador,total,estado) VALUES (?,?,'pendiente')", 'id', [$usuario_id, $total]);
            if (!$so) throw new Exception("Error al crear orden");
            $id_orden = $conn->insert_id;

            foreach ($_SESSION['carrito'] as $item) {
                $rs = qry($conn, "SELECT cantidad FROM productos WHERE id_producto=?", 'i', [$item['id_producto']]);
                $p  = $rs->fetch_assoc();
                if ($p['cantidad'] < $item['cantidad']) throw new Exception("Stock insuficiente: {$item['nombre']}");
                $sp = qry($conn,
                    "INSERT INTO pedidos (id_orden,id_producto,id_comprador,id_productor,cantidad,precio_unitario,estado) VALUES (?,?,?,?,?,?,'pendiente')",
                    'iiiidi', [$id_orden,$item['id_producto'],$usuario_id,$item['id_productor'],$item['cantidad'],$item['precio']]
                );
                if (!$sp) throw new Exception("Error pedido: {$item['nombre']}");
            }
        }

        $referencia       = 'QAG-' . strtoupper(substr(md5(uniqid()),0,10)) . '-' . $id_orden;
        $fecha_expiracion = match($tipo) {
          'efectivo'           => date('Y-m-d H:i:s', strtotime('+24 hours')),
          'billetera', 'pse'   => date('Y-m-d H:i:s', strtotime('+30 minutes')),
          default              => null
        };
        $detalles_json    = json_encode($detalles, JSON_UNESCAPED_UNICODE);

        $sp2 = qry($conn,
            "INSERT INTO pagos (id_orden,id_metodo_pago,referencia,valor,estado,fecha_expiracion,detalles_metodo) VALUES (?,?,?,?,'registrado',?,?)",
            'iisdss', [$id_orden,$id_metodo,$referencia,$total,$fecha_expiracion,$detalles_json]
        );
        if (!$sp2) throw new Exception("Error al registrar pago");
        $id_pago = $conn->insert_id;

        $conn->commit();

        $ru   = qry($conn, "SELECT nombre,email FROM usuarios WHERE id_usuario=?", 'i', [$usuario_id]);
        $user = $ru->fetch_assoc();
        $productos_lista     = $_SESSION['carrito'];
        $_SESSION['carrito'] = [];

        if ($tipo === 'efectivo') {
            $timestamp_correo = strtotime($fecha_expiracion);
            $fe = date('d', $timestamp_correo)
                . ' de ' . $meses_es[date('n', $timestamp_correo) - 1]
                . ' de ' . date('Y', $timestamp_correo)
                . ' a las ' . date('g:i A', $timestamp_correo);

            $pdf_bytes  = null;
            $nombre_pdf = 'Recibo-QuiliAgro-Orden' . $id_orden . '.pdf';
            try {
                $pdf_bytes = generarReciboPDFDompdf([
                    'convenio'       => EFECTY_CONVENIO,
                    'empresa'        => EFECTY_NOMBRE,
                    'referencia'     => $referencia,
                    'total'          => $total,
                    'fecha_vence'    => $fe,
                    'id_orden'       => $id_orden,
                    'nombre_cliente' => $user['nombre'],
                    'fecha_emision'  => date('d/m/Y H:i'),
                    'productos'      => $productos_lista,
                ]);
            } catch (Exception $pdfEx) {
                error_log('PDF recibo: ' . $pdfEx->getMessage());
            }

            $html_correo = _emailReciboEfectivo($user['nombre'], $referencia, $total, $fe, $id_orden, $productos_lista);

            if ($pdf_bytes) {
                enviarCorreoConAdjunto($user['email'], $user['nombre'],
                    "Factura de compra en QuiliAgro Connect",
                    $html_correo, $pdf_bytes, $nombre_pdf);
            } else {
                enviarCorreo($user['email'], $user['nombre'],
                    "Factura de compra en QuiliAgro Connect", $html_correo);
            }

            $_SESSION['recibo_efectivo'] = [
                'id_orden'         => $id_orden,
                'referencia'       => $referencia,
                'total'            => $total,
                'punto_pago'       => 'Efecty / Sured',
                'fecha_expiracion' => $fecha_expiracion,
            ];
            if ($pdf_bytes) {
                $_SESSION['recibo_efectivo_pdf']        = $pdf_bytes;
                $_SESSION['recibo_efectivo_pdf_nombre'] = $nombre_pdf;
            }

            $_SESSION['mensaje_temporal']      = "✅ Pedido registrado. Te enviamos el recibo de pago a tu correo con el PDF adjunto.";
            $_SESSION['tipo_mensaje_temporal'] = "success";
            header("Location: recibo_efectivo.php"); exit();

        } elseif ($tipo === 'tarjeta' || $tipo === 'transferencia') {
    $upd = qry($conn,
        "UPDATE pagos SET estado='confirmado', fecha_confirmacion=NOW() WHERE id_pago=?",
        'i', [$id_pago]
    );
    if (!$upd) throw new Exception("Error al confirmar pago con tarjeta");
    
    $upd2 = qry($conn,
    "UPDATE ordenes SET estado='pagado' WHERE id_orden=?",
    'i', [$id_orden]
    );
    if (!$upd2) throw new Exception("Error al actualizar orden");

        $_SESSION['pago_aprobado'] = [
            'referencia' => $referencia,
            'id_orden'   => $id_orden,
            'total'      => $total,
            'tipo'       => $tipo,
        ];

        $st_num = $conn->prepare("
            SELECT numero_orden FROM (
                SELECT id_orden,
                       ROW_NUMBER() OVER (PARTITION BY id_comprador ORDER BY fecha) AS numero_orden
                FROM ordenes WHERE id_comprador = ?
            ) t WHERE id_orden = ?
        ");
        $st_num->bind_param('ii', $usuario_id, $id_orden);
        $st_num->execute();
        $row_num     = $st_num->get_result()->fetch_assoc();
        $num_orden_u = $row_num ? $row_num['numero_orden'] : $id_orden;

        enviarCorreo(
            $user['email'],
            $user['nombre'],
            "¡Pago confirmado! — QuiliAgro Connect",
            _emailAprobado($user['nombre'], $referencia, $total, $num_orden_u)
        );

            header("Location: pago_aprobado.php"); exit();

        } else {
            $codigo = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $_SESSION['verificacion_pago'] = [
                'codigo_hash' => password_hash($codigo, PASSWORD_BCRYPT),
                'id_pago'     => $id_pago,
                'id_orden'    => $id_orden,
                'total'       => $total,
                'referencia'  => $referencia,
                'tipo'        => $tipo,
                'nombre'      => $user['nombre'],
                'email'       => $user['email'],
                'intentos'    => 0,
                'expira'      => time() + 600,
            ];

            enviarCorreo(
                $user['email'],
                $user['nombre'],
                "Código de verificación de pago — QuiliAgro Connect",
                _emailCodigo($user['nombre'], $codigo, $referencia, $total, $id_orden)
            );

            header("Location: verificar_codigo.php"); exit();
        }

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_pago'] = $e->getMessage();
        header("Location: pasarela_pago.php"); exit();
    }
}

if (!empty($_GET['metodo'])) {
    $_SESSION['metodo_seleccionado'] = intval($_GET['metodo']);
} elseif (!empty($_POST['id_metodo_pago']) && !isset($_POST['procesar_pago'])) {
    $_SESSION['metodo_seleccionado'] = intval($_POST['id_metodo_pago']);
}
$id_metodo_sel = intval($_SESSION['metodo_seleccionado'] ?? 0);

$rm_all  = qry($conn, "SELECT * FROM metodos_pago WHERE activo=1 ORDER BY nombre_metodo");
$metodos = [];
if ($rm_all) while ($m = $rm_all->fetch_assoc()) $metodos[] = $m;

$metodo_activo = null;
foreach ($metodos as $m) {
    if ($m['id_metodo'] == $id_metodo_sel) { $metodo_activo = $m; break; }
}
if (!$metodo_activo && !empty($metodos)) {
    $metodo_activo = $metodos[0];
    $_SESSION['metodo_seleccionado'] = $metodo_activo['id_metodo'];
}

$total_carrito = array_reduce($_SESSION['carrito'], fn($c,$i) => $c + $i['precio']*$i['cantidad'], 0);
$txn           = 'QAG-' . date('Ymd') . '-' . strtoupper(substr(md5(session_id()),0,6));
$error_pago    = $_SESSION['error_pago'] ?? '';
unset($_SESSION['error_pago']);

$tipo_actual = $metodo_activo['tipo'] ?? '';

$es_reintento_vista   = isset($_SESSION['reintento_orden_id']);
$id_orden_reintento   = $es_reintento_vista ? intval($_SESSION['reintento_orden_id']) : null;

$bancos = [
    'Banco Agrario','Bancolombia','Banco de Bogotá','Davivienda','Banco Popular',
    'Banco de Occidente','Banco Caja Social','Colpatria','Banco Falabella',
    'Banco Pichincha','Bancoomeva','DaviPlata',
];

$tipo_map = [
    'billetera'     => ['bi-phone',               '#d81b60', 'Billetera Digital'],
    'pse'           => ['bi-bank2',               '#1565c0', 'PSE — Débito Bancario'],
    'efectivo'      => ['bi-cash-stack',          '#e65100', 'Pago en Efectivo'],
    'transferencia' => ['bi-arrow-left-right',    '#2e7d32', 'Transferencia Bancaria'],
    'tarjeta'       => ['bi-credit-card-2-front', '#6a1b9a', 'Tarjeta de Crédito / Débito'],
];
$tinfo = $tipo_map[$tipo_actual] ?? ['bi-wallet2','#388e3c','Pago'];


function _emailReciboEfectivo($nombre, $referencia, $total, $vence, $id_orden, $productos) {
    $n_full  = htmlspecialchars(trim($nombre));
    $empresa = htmlspecialchars(EFECTY_NOMBRE);
    $ref     = htmlspecialchars($referencia);
    return "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f0f5f0;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f0f5f0;padding:24px 0;'>
<tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='max-width:560px;width:100%;'>
  <tr><td style='background:#2e7d32;border-radius:10px 10px 0 0;padding:16px 24px;'>
    <table width='100%' cellpadding='0' cellspacing='0'><tr>
      <td style='color:#fff;font-size:17px;font-weight:800;'>$empresa</td>
      <td style='text-align:right;'>
        <div style='font-size:11px;color:rgba(255,255,255,.7);'>Factura de tu pedido</div>
        <div style='font-size:19px;font-weight:800;color:#fff;font-family:monospace;letter-spacing:1px;'>$ref</div>
      </td>
    </tr></table>
  </td></tr>
  <tr><td style='background:#fff;padding:36px 36px 28px;'>
    <p style='margin:0 0 28px;font-size:20px;color:#222;'>Hola <strong style='color:#2e7d32;'>$n_full</strong></p>
    <table cellpadding='0' cellspacing='0' style='width:100%;border:1px solid #c8e6c9;border-radius:8px;background:#f1f8e9;'>
      <tr><td style='padding:16px 20px;font-size:14px;color:#2e7d32;'>📎 &nbsp;Tu factura está adjunta en este correo.</td></tr>
    </table>
  </td></tr>
  <tr><td style='background:#f8fbf8;border-top:1px solid #d6e8d6;border-radius:0 0 10px 10px;padding:14px 24px;text-align:center;font-size:11px;color:#888;'>
    Mensaje automático de <strong>$empresa</strong>. Por favor no respondas a este correo.
  </td></tr>
</table></td></tr></table></body></html>";
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
    <table cellpadding='0' cellspacing='6' style='margin:0 auto 24px;'>
      <tr>$cajas</tr>
    </table>
    <p style='font-size:12px;color:#e65100;font-weight:700;margin:0 0 24px;'>⏱ Válido por 10 minutos</p>
    <table cellpadding='0' cellspacing='0' style='width:100%;border:1px solid #e0e0e0;border-radius:10px;overflow:hidden;'>
      <tr style='background:#f5f5f5;'>
        <td style='padding:10px 16px;font-size:12px;color:#777;border-bottom:1px solid #eee;'>Referencia</td>
        <td style='padding:10px 16px;font-size:12px;font-weight:700;color:#222;text-align:right;font-family:monospace;border-bottom:1px solid #eee;'>$ref</td>
      </tr>
      <tr>
        <td style='padding:10px 16px;font-size:12px;color:#777;'>Total a pagar</td>
        <td style='padding:10px 16px;font-size:20px;font-weight:800;color:#2e7d32;text-align:right;'>$tv</td>
      </tr>
    </table>
    <p style='font-size:11px;color:#aaa;margin-top:20px;'>Si no realizaste esta solicitud, ignora este mensaje. Tu cuenta está segura.</p>
  </td></tr>
  <tr><td style='background:#f8fbf8;border-top:1px solid #d6e8d6;border-radius:0 0 10px 10px;padding:14px;text-align:center;font-size:11px;color:#888;'>
    Mensaje automático de <strong>QuiliAgro Connect</strong>. Por favor no respondas.
  </td></tr>
</table>
</td></tr></table></body></html>";
}


function _emailAprobado($n, $r, $t, $o) {
    $tv = '$ ' . number_format($t, 0, '.', ',');
    $n  = htmlspecialchars($n);
    $r  = htmlspecialchars($r);
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='font-family:Arial,sans-serif;background:#f5f7fa;margin:0;padding:20px;'>
<div style='max-width:540px;margin:auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1);'>
  <div style='background:linear-gradient(135deg,#2e7d32,#43a047);padding:30px;text-align:center;'>
    <div style='font-size:48px;margin-bottom:8px;'>✅</div>
    <h1 style='color:#fff;margin:0;font-size:21px;'>¡Pago Confirmado!</h1>
  </div>
  <div style='padding:30px;'>
    <p style='font-size:16px;'>Hola <strong>$n</strong>, tu pago fue verificado exitosamente.</p>
    <div style='background:#f1f8e9;border:2px solid #66bb6a;border-radius:10px;padding:18px;text-align:center;margin:18px 0;'>
      <p style='margin:0 0 4px;font-size:13px;color:#555;'>Ref: <strong>$r</strong></p>
      <p style='margin:0;font-size:26px;font-weight:800;color:#2e7d32;'>$tv</p>
    </div>
    <p style='font-size:13px;color:#666;'>Tu pedido está siendo procesado.</p>
  </div>
  <div style='background:#f8f9fa;padding:14px;text-align:center;font-size:11px;color:#aaa;'>
    QuiliAgro Connect
  </div>
</div></body></html>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pasarela de Pago — QuiliAgro Connect</title>
<link rel="icon" type="image/png" href="../../imagenes/logo.png">
<link rel="stylesheet" href="../../css/styles.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f0f5f0;--white:#ffffff;--surface:#f8fbf8;--border:#d6e8d6;--border-2:#c2d9c2;
  --green:#2e7d32;--green-l:#43a047;--green-xl:#e8f5e9;--green-xxl:#f1f9f1;
  --text:#1b2e1b;--text-2:#3d5c3d;--muted:#6b8c6b;
  --red:#c62828;--red-l:#ffebee;--orange:#e65100;--orange-l:#fff3e0;
  --blue:#1565c0;--blue-l:#e3f2fd;--purple:#6a1b9a;--purple-l:#f3e5f5;
  --pink:#c2185b;--pink-l:#fce4ec;
  --radius:16px;--radius-sm:10px;--radius-xs:7px;
  --shadow:0 2px 12px rgba(46,125,50,.08);--shadow-md:0 6px 28px rgba(46,125,50,.14);
  --transition:all .18s ease;
}
*{box-sizing:border-box;}
body{background:var(--bg);font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);margin:0;min-height:100vh;}
.hdr{background:var(--white);border-bottom:2px solid var(--border);padding:13px 28px;display:flex;align-items:center;gap:16px;box-shadow:var(--shadow);position:sticky;top:0;z-index:200;}
.hdr-logo{height:38px;}
.hdr-brand h1{margin:0;font-size:15px;font-weight:800;color:var(--green);}
.hdr-brand p{margin:1px 0 0;font-size:11px;color:var(--muted);}
.hdr-ssl{margin-left:auto;background:var(--green-xl);border:1.5px solid var(--border);color:var(--green);font-size:11px;font-weight:700;padding:6px 14px;border-radius:20px;display:flex;align-items:center;gap:6px;}
.layout-pay{display:flex;align-items:flex-start;gap:22px;max-width:1060px;margin:0 auto;padding:28px 16px 64px;}
.pay-left{flex:1;min-width:0;}
.pay-right{width:308px;flex-shrink:0;position:sticky;top:72px;}

.steps{display:flex;align-items:center;gap:6px;background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:14px 20px;margin-bottom:18px;box-shadow:var(--shadow);}
.stp{display:flex;align-items:center;gap:7px;}
.stp-n{width:26px;height:26px;border-radius:50%;background:#e8ece8;border:2px solid #cdd9cd;font-size:11px;font-weight:800;color:#9aaa9a;display:flex;align-items:center;justify-content:center;transition:var(--transition);}
.stp.done .stp-n{background:var(--green);border-color:var(--green);color:white;}
.stp.active .stp-n{background:var(--green-l);border-color:var(--green-l);color:white;box-shadow:0 0 0 3px rgba(67,160,71,.25);}
.stp-l{font-size:12px;font-weight:600;color:#aabcaa;}
.stp.done .stp-l{color:var(--green);}
.stp.active .stp-l{color:var(--text);}
.stp-sep{flex:1;height:2px;background:#dce8dc;border-radius:1px;min-width:14px;}
.stp.done+.stp-sep{background:var(--green);}

.card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:16px;box-shadow:var(--shadow);}
.card-title{display:flex;align-items:center;gap:9px;font-size:11px;font-weight:800;letter-spacing:.9px;text-transform:uppercase;color:var(--muted);margin:0 0 18px;padding-bottom:12px;border-bottom:1px solid var(--border);}
.card-title i{color:var(--green);font-size:15px;}

.metodo-fijo{display:flex;align-items:center;gap:16px;padding:16px 18px;border-radius:var(--radius-sm);border:1.5px solid var(--border-2);background:var(--surface);position:relative;}
.mf-icon-wrap{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;}
.mf-info{flex:1;}
.mf-nombre{font-size:17px;font-weight:800;color:var(--text);}
.mf-tipo{font-size:12px;font-weight:500;margin-top:2px;color:var(--muted);}
.mf-desc{font-size:12px;color:var(--muted);margin-top:3px;}
.mf-lock{background:var(--green-xl);border:1px solid var(--border);border-radius:20px;padding:5px 12px;font-size:11px;font-weight:700;display:flex;align-items:center;gap:5px;color:var(--green);}
.metodo-fijo-note{margin:10px 0 0;font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;}
.metodo-fijo-note a{color:var(--green);font-weight:700;text-decoration:none;}

.field{margin-bottom:15px;}
.f-label{display:block;font-size:12px;font-weight:700;color:var(--text-2);margin-bottom:7px;letter-spacing:.2px;}
.f-label .req{color:var(--red);margin-left:2px;}
.f-input,.f-select{width:100%;padding:11px 14px;background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-sm);color:var(--text);font-size:15px;font-family:'Plus Jakarta Sans',sans-serif;transition:var(--transition);}
.f-input:focus,.f-select:focus{outline:none;border-color:var(--green);background:var(--white);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.f-input::placeholder{color:#b5c5b5;}
.f-select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'%3E%3Cpath fill='%236b8c6b' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 13px center;background-size:11px;padding-right:36px;}
.f-hint{font-size:11px;color:var(--muted);margin-top:5px;display:flex;align-items:center;gap:4px;}
.f-row{display:grid;grid-template-columns:1fr 1fr;gap:13px;}

.card-vis{border-radius:15px;padding:22px;margin-bottom:20px;position:relative;overflow:hidden;min-height:162px;background:linear-gradient(135deg,#1a237e 0%,#283593 50%,#0d47a1 100%);box-shadow:0 10px 36px rgba(13,71,161,.4);transition:background .4s;}
.card-vis.visa{background:linear-gradient(135deg,#1565c0,#1976d2,#0d47a1);}
.card-vis.mastercard{background:linear-gradient(135deg,#880e4f,#ad1457,#6a1b9a);}
.card-vis.amex{background:linear-gradient(135deg,#004d40,#00695c,#00796b);}
.card-vis::before{content:'';position:absolute;top:-50px;right:-50px;width:220px;height:220px;background:rgba(255,255,255,.05);border-radius:50%;}
.cv-chip{width:38px;height:28px;background:linear-gradient(135deg,#ffd54f,#f9a825);border-radius:5px;margin-bottom:16px;position:relative;z-index:1;}
.cv-chip::after{content:'';position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:22px;height:16px;border:1.5px solid rgba(0,0,0,.25);border-radius:3px;}
.cv-num{font-family:'JetBrains Mono',monospace;font-size:18px;letter-spacing:4px;color:rgba(255,255,255,.92);margin-bottom:16px;position:relative;z-index:1;word-spacing:8px;}
.cv-foot{display:flex;justify-content:space-between;align-items:flex-end;position:relative;z-index:1;}
.cv-lbl{font-size:9px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;}
.cv-val{font-size:13px;color:rgba(255,255,255,.92);font-weight:700;}
.cv-brand{font-size:13px;font-weight:800;color:rgba(255,255,255,.9);letter-spacing:1px;}

.banner-metodo{display:flex;align-items:center;gap:14px;border-radius:var(--radius-sm);padding:14px 16px;margin-bottom:16px;border:1.5px solid;}
.banner-metodo i{font-size:28px;flex-shrink:0;}
.banner-title{font-size:14px;font-weight:800;}
.banner-sub{font-size:12px;margin-top:2px;}

.info{border-radius:var(--radius-xs);padding:11px 14px;font-size:13px;margin-bottom:14px;display:flex;align-items:flex-start;gap:9px;line-height:1.55;}
.info i{flex-shrink:0;margin-top:1px;font-size:14px;}
.info.blue{background:var(--blue-l);border:1px solid rgba(21,101,192,.2);color:var(--blue);}
.info.green{background:var(--green-xl);border:1px solid rgba(46,125,50,.2);color:var(--green);}
.info.orange{background:var(--orange-l);border:1px solid rgba(230,81,0,.2);color:var(--orange);}
.info.red{background:var(--red-l);border:1.5px solid rgba(198,40,40,.3);color:var(--red);}

.datos-banco{border-radius:var(--radius-sm);overflow:hidden;border:1.5px solid rgba(46,125,50,.25);margin-bottom:16px;}
.db-head{background:var(--green);color:white;padding:9px 14px;font-size:12px;font-weight:700;display:flex;align-items:center;gap:7px;}
.db-body{background:var(--green-xxl);}
.db-row{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid rgba(46,125,50,.1);font-size:13px;}
.db-row:last-child{border-bottom:none;}
.db-l{color:var(--muted);font-weight:500;}
.db-v{font-family:'JetBrains Mono',monospace;font-weight:700;font-size:12px;display:flex;align-items:center;gap:6px;color:var(--text);}
.copy-btn{background:none;border:none;color:var(--green);cursor:pointer;padding:3px 5px;border-radius:5px;font-size:13px;transition:var(--transition);}
.copy-btn:hover{background:rgba(46,125,50,.12);}

.efecty-wrap{margin-bottom:18px;}
.efecty-brands{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;background:#fff;border:1px solid #e0e0e0;border-bottom:none;border-radius:10px 10px 0 0;}
.brand-efecty{display:flex;align-items:center;gap:6px;}
.brand-efecty .efecty-circle{width:28px;height:28px;background:#f5a800;border-radius:50%;display:flex;align-items:center;justify-content:center;}
.brand-efecty .efecty-circle span{font-size:13px;font-weight:900;color:#fff;font-style:italic;}
.brand-efecty .efecty-text{font-size:20px;font-weight:900;color:#f5a800;font-style:italic;letter-spacing:-0.5px;}
.brand-sured{background:#0057a8;border-radius:6px;padding:5px 14px;}
.brand-sured span{font-size:14px;font-weight:900;color:#fff;letter-spacing:-0.3px;}
.efecty-proc{text-align:right;font-size:11px;color:#999;line-height:1.4;}
.efecty-proc strong{color:#555;display:block;font-size:12px;}
.efecty-body{border:1px solid #e0e0e0;border-top:2px solid #f5a800;padding:20px;background:#fff;}
.efecty-main-row{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;gap:16px;}
.efecty-convenio{font-size:13px;color:#555;margin-bottom:4px;}
.efecty-num-lbl{font-size:11px;color:#999;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;margin-top:12px;}
.efecty-num{font-size:28px;font-weight:900;color:#222;font-family:'JetBrains Mono',monospace;letter-spacing:2px;line-height:1;}
.efecty-right-col{text-align:right;}
.efecty-total-lbl{font-size:12px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;}
.efecty-total{font-size:26px;font-weight:900;color:#222;line-height:1;}
.efecty-vence-lbl{font-size:11px;color:#999;margin-top:8px;}
.efecty-vence{font-size:12px;font-weight:700;color:#e65100;}
.efecty-sep{border:none;border-top:1px solid #eee;margin:16px 0;}
.efecty-instruccion{background:#fffbe6;border:1px solid #ffe082;border-radius:6px;padding:12px 14px;font-size:13px;color:#7a4f00;line-height:1.7;margin-bottom:16px;}
.efecty-data-table{width:100%;border-collapse:collapse;margin-bottom:16px;}
.efecty-data-table th{background:#f5f5f5;padding:8px 12px;font-size:11px;font-weight:700;color:#555;text-align:left;text-transform:uppercase;letter-spacing:0.5px;border:1px solid #e0e0e0;}
.efecty-data-table td{padding:10px 12px;font-size:12px;color:#444;border:1px solid #e0e0e0;vertical-align:top;line-height:1.6;}
.efecty-data-table td strong{color:#222;display:block;}
.efecty-data-table td span{color:#666;}
.efecty-footer{border:1px solid #e0e0e0;border-top:none;background:#f9f9f9;padding:12px 20px;border-radius:0 0 10px 10px;font-size:11px;color:#888;text-align:center;line-height:1.6;}

.resumen{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow-md);margin-bottom:14px;}
.res-head{background:linear-gradient(135deg,var(--green),var(--green-l));color:white;padding:14px 18px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;display:flex;align-items:center;gap:8px;}
.res-body{padding:14px 18px;}
.res-item{display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px;gap:8px;}
.res-item:last-child{border-bottom:none;}
.ri-n strong{display:block;font-size:13px;color:var(--text);}
.ri-n span{font-size:11px;color:var(--muted);}
.ri-p{font-weight:700;color:var(--text);white-space:nowrap;}
.res-total{display:flex;justify-content:space-between;align-items:center;padding:12px 0 2px;border-top:2px solid var(--border);margin-top:6px;}
.rt-l{font-size:14px;font-weight:800;}
.rt-v{font-size:26px;font-weight:800;color:var(--green);}

.txn{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-xs);padding:10px 14px;display:flex;align-items:center;gap:8px;font-size:11px;color:var(--muted);margin-bottom:16px;}
.txn code{font-family:'JetBrains Mono',monospace;color:var(--green);font-size:12px;font-weight:700;}

.btn-pay{width:100%;padding:15px;border:none;border-radius:var(--radius-sm);font-size:15px;font-weight:800;cursor:pointer;transition:var(--transition);display:flex;align-items:center;justify-content:center;gap:10px;background:linear-gradient(135deg,var(--green),var(--green-l));color:white;box-shadow:0 4px 18px rgba(46,125,50,.3);font-family:'Plus Jakarta Sans',sans-serif;}
.btn-pay:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 26px rgba(46,125,50,.4);}
.btn-pay:disabled{opacity:.45;cursor:not-allowed;transform:none;}
.link-back{display:block;text-align:center;margin-top:10px;font-size:13px;color:var(--muted);text-decoration:none;padding:7px;}
.link-back:hover{color:var(--green);}
.sec-badges{display:flex;justify-content:center;gap:14px;flex-wrap:wrap;margin-top:12px;}
.sec-item{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);}
.sec-item i{color:var(--green);}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;}
.modal-overlay.on{display:flex;}
.modal-box{background:var(--white);border-radius:20px;padding:44px 36px;text-align:center;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:pop .3s ease;}
@keyframes pop{from{opacity:0;transform:scale(.9);}to{opacity:1;transform:scale(1);}}
.spinner{width:68px;height:68px;border:5px solid rgba(46,125,50,.15);border-top:5px solid var(--green);border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 20px;}
@keyframes spin{to{transform:rotate(360deg);}}
.modal-box h3{margin:0 0 8px;font-size:19px;color:var(--text);}
.modal-box p{color:var(--muted);font-size:13px;margin:0;}
.m-steps{margin-top:18px;text-align:left;}
.m-step{display:flex;align-items:center;gap:10px;padding:7px 0;font-size:13px;color:var(--muted);}
.m-step.ok{color:var(--green);font-weight:600;}

.confirm-total{font-size:26px;font-weight:900;color:var(--green);margin:12px 0;}
.confirm-subtitle{font-size:12px;color:var(--muted);margin-bottom:20px;}
.confirm-actions{display:flex;gap:10px;margin-top:4px;}
.btn-cancel-confirm{flex:1;padding:12px;border:2px solid var(--border-2);border-radius:var(--radius-sm);background:white;font-size:14px;font-weight:700;cursor:pointer;color:var(--muted);font-family:'Plus Jakarta Sans',sans-serif;transition:var(--transition);}
.btn-cancel-confirm:hover{border-color:var(--green);color:var(--green);}
.btn-ok-confirm{flex:1;padding:12px;border:none;border-radius:var(--radius-sm);background:linear-gradient(135deg,var(--green),var(--green-l));color:white;font-size:14px;font-weight:800;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:var(--transition);}
.btn-ok-confirm:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(46,125,50,.3);}

@media(max-width:880px){.layout-pay{flex-direction:column;padding:16px 12px 40px;}.pay-right{width:100%;position:static;}}
@media(max-width:520px){.f-row{grid-template-columns:1fr;}}

@media (max-width: 600px) {

    .hdr { padding: 10px 14px; gap: 10px; }
    .hdr-logo { height: 30px; }
    .hdr-brand h1 { font-size: 13px; }
    .hdr-brand p { display: none; }
    .hdr-ssl span { display: none; }
    .hdr-ssl { padding: 6px 10px; }

    .stp-l { display: none; }
    .steps { padding: 10px 12px; gap: 4px; }
    .stp-sep { min-width: 8px; }

    .txn { flex-wrap: wrap; gap: 4px; font-size: 10px; }
    .txn code { font-size: 10px; word-break: break-all; }

    .metodo-fijo { flex-wrap: wrap; gap: 10px; }
    .mf-lock { font-size: 10px; padding: 4px 8px; }

    .card-vis { min-height: 140px; padding: 16px; }
    .cv-num { font-size: 14px; letter-spacing: 2px; }
    .cv-val { font-size: 11px; }

    .efecty-brands { flex-direction: column; align-items: flex-start; gap: 10px; padding: 12px 14px; }
    .efecty-proc { text-align: left; }
    .efecty-body { padding: 14px; }
    .efecty-main-row { flex-direction: column; gap: 12px; }
    .efecty-right-col { text-align: left; }
    .efecty-num { font-size: 20px; }
    .efecty-total { font-size: 20px; }

    .efecty-data-table,
    .efecty-data-table thead,
    .efecty-data-table tbody,
    .efecty-data-table th,
    .efecty-data-table td,
    .efecty-data-table tr { display: block; width: 100%; }
    .efecty-data-table thead { display: none; }
    .efecty-data-table td { padding: 8px 12px; border-bottom: 1px solid #eee; }
    .efecty-data-table tr { border: 1px solid #e0e0e0; margin-bottom: 8px; border-radius: 8px; overflow: hidden; }

    .db-row { flex-direction: column; gap: 4px; align-items: flex-start; }
    .db-v { font-size: 13px; }

    .res-item { flex-wrap: wrap; }
    .rt-v { font-size: 20px; }

    .btn-pay { font-size: 13px; padding: 13px 10px; }

    .confirm-actions { flex-direction: column; }
    .btn-cancel-confirm,
    .btn-ok-confirm { width: 100%; }
    .confirm-total { font-size: 20px; }

    .layout-pay { padding: 12px 8px 40px; gap: 14px; }
    .card { padding: 16px; }
}

@media (max-width: 380px) {
    .stp-n { width: 22px; height: 22px; font-size: 10px; }
    .cv-num { font-size: 12px; letter-spacing: 1px; }
    .efecty-num { font-size: 17px; letter-spacing: 1px; }
}
</style>
</head>
<body>

<header class="hdr">
  <img src="../../imagenes/logo.png" alt="QuiliAgro" class="hdr-logo">
  <div class="hdr-brand"><h1>QuiliAgro Connect</h1><p>Pasarela de Pago Segura</p></div>
  <div class="hdr-ssl"><i class="bi bi-shield-check-fill"></i> SSL Seguro</div>
</header>

<div class="modal-overlay" id="modalConfirm">
  <div class="modal-box">
    <i class="bi bi-shield-lock-fill" style="font-size:50px;color:var(--green);display:block;margin-bottom:14px;"></i>
    <h3>¿Confirmar pago?</h3>
    <div class="confirm-total" id="confirmTotalText"></div>
    <div class="confirm-subtitle" id="confirmSubtitle">
      Se generará tu recibo PDF y se enviará a tu correo.
    </div>
    <div class="confirm-actions">
      <button class="btn-cancel-confirm" onclick="cancelarConfirm()">
        <i class="bi bi-x-lg"></i> Cancelar
      </button>
      <button class="btn-ok-confirm" onclick="confirmarPago()">
        <i class="bi bi-check-lg"></i> Sí, confirmar
      </button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modalProc">
  <div class="modal-box">
    <div class="spinner"></div>
    <h3>Procesando Pago...</h3>
    <p>No cierres esta ventana</p>
    <div class="m-steps">
      <div class="m-step" id="ms1"><i class="bi bi-circle"></i> Verificando información</div>
      <div class="m-step" id="ms2"><i class="bi bi-circle"></i> Validando método de pago</div>
      <div class="m-step" id="ms3"><i class="bi bi-circle"></i> Generando código de seguridad</div>
      <div class="m-step" id="ms4"><i class="bi bi-circle"></i> Enviando código a tu correo</div>
    </div>
  </div>
</div>

<div class="layout-pay">
  <div class="pay-left">

    <div class="steps">
      <div class="stp done"><div class="stp-n"><i class="bi bi-check-lg" style="font-size:10px;"></i></div><div class="stp-l">Carrito</div></div>
      <div class="stp-sep"></div>
      <div class="stp done"><div class="stp-n"><i class="bi bi-check-lg" style="font-size:10px;"></i></div><div class="stp-l">Revisar</div></div>
      <div class="stp-sep"></div>
      <div class="stp active"><div class="stp-n">3</div><div class="stp-l">Datos</div></div>
      <div class="stp-sep"></div>
      <div class="stp"><div class="stp-n">4</div><div class="stp-l">Verificar</div></div>
      <div class="stp-sep"></div>
      <div class="stp"><div class="stp-n">5</div><div class="stp-l">Listo</div></div>
    </div>

    <?php if ($error_pago): ?>
    <div class="info red" style="margin-bottom:16px;">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span><?php echo htmlspecialchars($error_pago); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($es_reintento_vista && $id_orden_reintento): ?>
    <div class="info orange" style="margin-bottom:16px;">
      <i class="bi bi-arrow-clockwise"></i>
      <span>Estás reintentando el pago de la <strong>Orden #<?php echo $id_orden_reintento; ?></strong>. No se creará una orden nueva; solo se registrará un nuevo pago en efectivo.</span>
    </div>
    <?php endif; ?>

    <div class="txn">
      <i class="bi bi-hash"></i>
      Sesión: <code><?php echo $txn; ?></code>
      <span style="margin-left:auto;font-size:10px;"><i class="bi bi-clock"></i> <?php echo date('d/m/Y g:i'); ?></span>
    </div>

    <!-- Método de pago (fijo) -->
    <div class="card">
      <div class="card-title"><i class="bi bi-credit-card-2-front"></i> Método de Pago</div>
      <div class="metodo-fijo">
        <div class="mf-icon-wrap" style="background:<?php echo $tinfo[1]; ?>18; color:<?php echo $tinfo[1]; ?>;">
          <i class="bi <?php echo $tinfo[0]; ?>" style="font-size:22px;"></i>
        </div>
        <div class="mf-info">
          <div class="mf-nombre"><?php echo htmlspecialchars($metodo_activo['nombre_metodo']); ?></div>
          <div class="mf-tipo"><?php echo $tinfo[2]; ?></div>
          <?php if (!empty($metodo_activo['descripcion'])): ?>
          <div class="mf-desc"><?php echo htmlspecialchars($metodo_activo['descripcion']); ?></div>
          <?php endif; ?>
        </div>
        <div class="mf-lock"><i class="bi bi-lock-fill"></i> Método fijo</div>
      </div>
      <div class="metodo-fijo-note">
        <i class="bi bi-info-circle"></i>
        El método fue seleccionado en el carrito.
        <?php if (!$es_reintento_vista): ?>
        <a href="checkout_carrito.php">¿Deseas cambiarlo?</a>
        <?php endif; ?>
      </div>
    </div>

    <form method="POST" action="pasarela_pago.php" id="formPago" autocomplete="off" novalidate>
      <input type="hidden" name="procesar_pago"  value="1">
      <input type="hidden" name="id_metodo_pago" value="<?php echo $metodo_activo['id_metodo']; ?>">

      <!-- ─── TARJETA ─── -->
      <?php if ($tipo_actual === 'tarjeta'): ?>
      <div class="card">
        <div class="card-title"><i class="bi bi-credit-card-2-front"></i> Datos de la tarjeta</div>
        <div class="card-vis" id="cardVis">
          <div class="cv-chip"></div>
          <div class="cv-num" id="cvNum">•••• &nbsp;•••• &nbsp;•••• &nbsp;••••</div>
          <div class="cv-foot">
            <div><div class="cv-lbl">Titular</div><div class="cv-val" id="cvTitular">NOMBRE APELLIDO</div></div>
            <div><div class="cv-lbl">Vence</div><div class="cv-val" id="cvVence">MM/AA</div></div>
            <div><div class="cv-brand" id="cvBrand">VISA</div></div>
          </div>
        </div>
        <div class="field">
          <label class="f-label">Número de tarjeta <span class="req">*</span></label>
          <input type="text" name="num_tarjeta" id="inpNT" class="f-input" placeholder="0000  0000  0000  0000" maxlength="22" inputmode="numeric" oninput="onNumTarjeta(this)" style="font-family:'JetBrains Mono',monospace;font-size:18px;letter-spacing:3px;" required>
          <div class="f-hint"><i class="bi bi-info-circle"></i> Visa / Mastercard</div>
        </div>
        <div class="field">
          <label class="f-label">Nombre del titular <span class="req">*</span></label>
          <input type="text" name="titular" id="inpTit" class="f-input" placeholder="Igual que aparece en la tarjeta" maxlength="50" style="text-transform:uppercase;" oninput="document.getElementById('cvTitular').textContent=this.value.toUpperCase()||'NOMBRE APELLIDO'" required>
        </div>
        <div class="f-row">
          <div class="field">
            <label class="f-label">Fecha de vencimiento <span class="req">*</span></label>
            <input type="text" name="vencimiento" id="inpVen" class="f-input" placeholder="MM/AA" maxlength="5" inputmode="numeric" oninput="onVence(this)" required>
          </div>
          <div class="field">
            <label class="f-label">Código de seguridad (CVV) <span class="req">*</span></label>
            <input type="password" name="cvv" id="inpCVV" class="f-input" placeholder="•••" maxlength="4" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'');" required>
            <div class="f-hint"><i class="bi bi-lock-fill"></i> 3 dígitos al dorso</div>
          </div>
        </div>
        <div class="info green"><i class="bi bi-shield-lock-fill"></i> Datos cifrados con SSL 256-bit. Tu pago será aprobado de forma inmediata.</div>
      </div>

      <!-- ─── BILLETERA ─── -->
      <?php elseif ($tipo_actual === 'billetera'): ?>
      <div class="card">
        <div class="card-title"><i class="bi bi-phone"></i> Datos de pago — <?php echo htmlspecialchars($metodo_activo['nombre_metodo']); ?></div>
        <div class="banner-metodo" style="background:var(--pink-l);border-color:rgba(194,24,91,.2);color:var(--pink);">
          <i class="bi bi-phone-fill"></i>
          <div>
            <div class="banner-title"><?php echo htmlspecialchars($metodo_activo['nombre_metodo']); ?></div>
            <div class="banner-sub" style="color:#a0446a;">Ingresa el número vinculado a tu cuenta</div>
          </div>
        </div>
        <div class="info blue"><i class="bi bi-envelope-at-fill"></i> Se enviará un código de 6 dígitos a tu correo para confirmar el pago.</div>
        <div class="field">
          <label class="f-label">Número de celular <span class="req">*</span></label>
          <input type="tel" name="telefono" id="inpTel" class="f-input" placeholder="300 000 0000" maxlength="10" minlength="10" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'');" style="font-family:'JetBrains Mono',monospace;font-size:18px;letter-spacing:4px;" required>
          <div class="f-hint"><i class="bi bi-phone"></i> 10 dígitos sin código de país</div>
        </div>
        <div class="info green" style="margin-top:4px;">
          <i class="bi bi-envelope-check-fill"></i>
          <span>El código de verificación se enviará al correo registrado en tu cuenta.</span>
        </div>
      </div>

      <!-- ─── PSE ─── -->
      <?php elseif ($tipo_actual === 'pse'): ?>
      <div class="card">
        <div class="card-title"><i class="bi bi-bank2"></i> Datos de pago — PSE</div>
        <div class="banner-metodo" style="background:var(--blue-l);border-color:rgba(21,101,192,.2);color:var(--blue);">
          <i class="bi bi-bank2"></i>
          <div>
            <div class="banner-title">Pagos Seguros en Línea — PSE</div>
            <div class="banner-sub" style="color:#3a6ea8;">Débito directo desde tu cuenta bancaria</div>
          </div>
        </div>
        <div class="info blue"><i class="bi bi-envelope-at-fill"></i> Se enviará un código de 6 dígitos a tu correo para confirmar el débito.</div>
        <div class="field">
          <label class="f-label">Entidad bancaria <span class="req">*</span></label>
          <select name="banco" class="f-select" required>
            <option value="">— Selecciona tu banco —</option>
            <?php foreach ($bancos as $b): ?><option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="f-row">
          <div class="field">
            <label class="f-label">Tipo de persona <span class="req">*</span></label>
            <select name="tipo_persona" class="f-select" required>
              <option value="">Seleccionar</option>
              <option value="natural">Persona Natural</option>
              <option value="juridica">Persona Jurídica</option>
            </select>
          </div>
          <div class="field">
            <label class="f-label">Tipo de documento <span class="req">*</span></label>
            <select name="tipo_doc" class="f-select" required>
              <option value="">Seleccionar</option>
              <option value="CC">Cédula de Ciudadanía (CC)</option>
              <option value="NIT">NIT</option>
            </select>
          </div>
        </div>
        <div class="field">
          <label class="f-label">Número de documento <span class="req">*</span></label>
          <input type="text" name="documento_pse" class="f-input" placeholder="Ej: 1030320442" maxlength="15" minlength="5" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'');" required>
        </div>
        <div class="info green" style="margin-top:4px;">
          <i class="bi bi-envelope-check-fill"></i>
          <span>El código de verificación se enviará al correo registrado en tu cuenta.</span>
        </div>
      </div>

      <!-- ─── TRANSFERENCIA ─── -->
      <?php elseif ($tipo_actual === 'transferencia'): ?>
      <div class="card">
        <div class="card-title"><i class="bi bi-arrow-left-right"></i> Datos de pago — Transferencia Bancaria</div>
        <div class="datos-banco">
          <div class="db-head"><i class="bi bi-bank2"></i> Transfiere exactamente a esta cuenta</div>
          <div class="db-body">
            <div class="db-row"><span class="db-l">Banco destino</span><span class="db-v">Bancolombia <button type="button" class="copy-btn" onclick="copiar('Bancolombia')" title="Copiar"><i class="bi bi-copy"></i></button></span></div>
            <div class="db-row"><span class="db-l">Tipo de cuenta</span><span class="db-v">Cuenta de Ahorros</span></div>
            <div class="db-row"><span class="db-l">Número de cuenta</span><span class="db-v">123-456789-00 <button type="button" class="copy-btn" onclick="copiar('123-456789-00')" title="Copiar"><i class="bi bi-copy"></i></button></span></div>
            <div class="db-row"><span class="db-l">Titular</span><span class="db-v">QuiliAgro SAS <button type="button" class="copy-btn" onclick="copiar('QuiliAgro SAS')" title="Copiar"><i class="bi bi-copy"></i></button></span></div>
            <div class="db-row"><span class="db-l">NIT</span><span class="db-v">900.123.456-7</span></div>
            <div class="db-row"><span class="db-l">Valor exacto</span>
              <span class="db-v" style="color:var(--green);font-size:14px;">$<?php echo number_format($total_carrito,0,'.',','); ?> <button type="button" class="copy-btn" onclick="copiar('<?php echo $total_carrito; ?>')" title="Copiar"><i class="bi bi-copy"></i></button></span>
            </div>
          </div>
        </div>
        <div class="field">
          <label class="f-label">Tu banco de origen <span class="req">*</span></label>
          <select name="banco_origen" class="f-select" required>
            <option value="">— Selecciona tu banco —</option>
            <?php foreach ($bancos as $b): ?><option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="f-row">
          <div class="field">
            <label class="f-label">Tipo de cuenta <span class="req">*</span></label>
            <select name="tipo_cuenta" class="f-select" required>
              <option value="">Seleccionar</option>
              <option value="ahorros">Cuenta de Ahorros</option>
              <option value="corriente">Cuenta Corriente</option>
            </select>
          </div>
          <div class="field">
            <label class="f-label">Número de tu cuenta <span class="req">*</span></label>
            <input type="text" name="num_cuenta" class="f-input" placeholder="Número de cuenta" maxlength="18" minlength="8" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'');" required>
          </div>
        </div>
        <div class="field">
          <label class="f-label">Nombre del titular <span class="req">*</span></label>
          <input type="text" name="titular_cuenta" class="f-input" placeholder="Nombre completo del titular" maxlength="60" style="text-transform:uppercase;" required>
        </div>
        <div class="field">
          <label class="f-label">Número de documento <span class="req">*</span></label>
          <input type="text" name="doc_transferencia" class="f-input" placeholder="Cédula o NIT del titular" maxlength="15" minlength="5" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'');" required>
        </div>
      </div>

      <!-- ─── EFECTIVO ─── -->
      <?php elseif ($tipo_actual === 'efectivo'):
      $timestamp_exp = strtotime('+24 hours');
      $fecha_exp_str = date('d', $timestamp_exp)
          . ' de ' . $meses_es[date('n', $timestamp_exp) - 1]
          . ' de ' . date('Y', $timestamp_exp)
          . ' a las ' . date('g:i A', $timestamp_exp);
      ?>
      <div class="card">
        <div class="card-title"><i class="bi bi-cash-stack"></i> Pago en Efectivo</div>
        <div class="info orange" style="margin-bottom:20px;">
          <i class="bi bi-clock-history"></i>
          <span>Tienes <strong>24 horas</strong> para completar el pago. Al confirmar recibirás el recibo PDF en tu correo.</span>
        </div>
        <div class="efecty-wrap">
          <div class="efecty-brands">
            <div style="display:flex;align-items:center;gap:16px;">
              <div class="brand-efecty">
                <div class="efecty-circle"><span>S</span></div>
                <span class="efecty-text">efecty</span>
              </div>
              <div class="brand-sured"><span>Sured</span></div>
            </div>
            <div class="efecty-proc">Procesado por<strong><?php echo htmlspecialchars(EFECTY_NOMBRE); ?></strong></div>
          </div>
          <div class="efecty-body">
            <div class="efecty-main-row">
              <div>
                <div class="efecty-convenio">Convenio No. <strong><?php echo EFECTY_CONVENIO; ?></strong> &nbsp;·&nbsp; A nombre de: <strong><?php echo htmlspecialchars(EFECTY_NOMBRE); ?></strong></div>
                <div class="efecty-num-lbl">Número de pago</div>
                <div class="efecty-num"><?php echo htmlspecialchars($txn); ?></div>
              </div>
              <div class="efecty-right-col">
                <div class="efecty-total-lbl">Total a pagar</div>
                <div class="efecty-total">$ <?php echo number_format($total_carrito,0,'.',','); ?></div>
                <div class="efecty-vence-lbl">Paga antes de:</div>
                <div class="efecty-vence"><?php echo $fecha_exp_str; ?></div>
              </div>
            </div>
            <hr class="efecty-sep">
            <div class="efecty-instruccion">
              Haz tu pago en cualquier punto <strong>EFECTY</strong> o <strong>Sured</strong> del país.<br>
              Indica el convenio <strong><?php echo EFECTY_CONVENIO; ?></strong> de <strong><?php echo htmlspecialchars(EFECTY_NOMBRE); ?></strong> al cajero.
            </div>
            <table class="efecty-data-table">
              <tr><th>Datos de la compra</th><th>Datos del pagador</th></tr>
              <tr>
                <td>
                  <strong>Tienda:</strong> <span><?php echo htmlspecialchars(EFECTY_NOMBRE); ?></span>
                  <strong style="margin-top:6px;">Fecha:</strong> <span><?php echo date('Y-m-d \a \l\a\s g:i A'); ?></span>
                  <strong style="margin-top:6px;">Convenio:</strong> <span><?php echo EFECTY_CONVENIO; ?></span>
                </td>
                <td><strong>Nombre:</strong> <span><?php echo htmlspecialchars($usuario_nombre); ?></span></td>
              </tr>
            </table>
          </div>
        </div>
        <div class="info blue">
          <i class="bi bi-file-earmark-pdf-fill"></i>
          <span>Al confirmar, el recibo PDF se <strong>descargará automáticamente</strong> y se enviará a tu correo.</span>
        </div>
      </div>

      <?php else: ?>
      <div class="card" style="text-align:center;padding:40px;">
        <i class="bi bi-exclamation-triangle" style="font-size:48px;color:var(--muted);margin-bottom:14px;display:block;"></i>
        <p style="color:var(--muted);">Método de pago no reconocido.</p>
        <a href="checkout_carrito.php" style="color:var(--green);font-weight:700;">Volver al carrito</a>
      </div>
      <?php endif; ?>

    </form>
  </div>

  <div class="pay-right">
    <div class="resumen">
      <div class="res-head"><i class="bi bi-receipt"></i> Resumen del Pedido</div>
      <div class="res-body">
        <?php foreach ($_SESSION['carrito'] as $item): ?>
        <div class="res-item">
          <div class="ri-n">
            <strong><?php echo htmlspecialchars($item['nombre']); ?></strong>
            <span><?php echo $item['cantidad']; ?> × $<?php echo number_format($item['precio'],0,'.',','); ?> / <?php echo htmlspecialchars($item['unidad_medida']); ?></span>
          </div>
          <div class="ri-p">$<?php echo number_format($item['precio']*$item['cantidad'],0,'.',','); ?></div>
        </div>
        <?php endforeach; ?>
        <div class="res-item">
          <span style="color:var(--muted);">Envío</span>
          <span style="font-weight:700;color:var(--green);">Gratis</span>
        </div>
        <div class="res-total">
          <span class="rt-l">Total a pagar</span>
          <span class="rt-v">$<?php echo number_format($total_carrito,0,'.',','); ?></span>
        </div>
      </div>
    </div>

    <button type="button" class="btn-pay" id="btnPay" onclick="iniciarPago()">
      <i class="bi bi-lock-fill"></i>
      <?php
        $btns = [
          'tarjeta'       => 'Pagar con Tarjeta',
          'billetera'     => 'Pagar con ' . htmlspecialchars($metodo_activo['nombre_metodo']),
          'pse'           => 'Continuar con PSE',
          'transferencia' => 'Registrar Transferencia',
          'efectivo'      => 'Confirmar y Recibir Recibo',
        ];
        echo ($btns[$tipo_actual] ?? 'Pagar') . ' — $' . number_format($total_carrito,0,'.',',');
      ?>
    </button>
    <a href="<?php echo $es_reintento_vista ? 'pedidos_comprador.php' : 'checkout_carrito.php'; ?>" class="link-back">
      <i class="bi bi-arrow-left"></i> <?php echo $es_reintento_vista ? 'Volver a mis pedidos' : 'Volver al carrito'; ?>
    </a>

    <div class="sec-badges">
      <div class="sec-item"><i class="bi bi-shield-check-fill"></i> SSL 256-bit</div>
      <div class="sec-item"><i class="bi bi-lock-fill"></i> Seguro</div>
      <div class="sec-item"><i class="bi bi-patch-check-fill"></i> Verificado</div>
    </div>
  </div>
</div>

<script>
const TIPO      = '<?php echo $tipo_actual; ?>';
const TOTAL_FMT = '$<?php echo number_format($total_carrito,0,'.',','); ?>';
const formEl    = document.getElementById('formPago');

function onNumTarjeta(inp) {
  let v = inp.value.replace(/\D/g,'').substring(0,16);
  inp.value = v.match(/.{1,4}/g)?.join('  ') || v;
  let d = '';
  for (let i=0;i<16;i++) { if(i&&i%4===0) d+=' '; d += i<v.length ? v[i] : '•'; }
  document.getElementById('cvNum').textContent = d;
  const map = {'4':['visa','VISA'],'5':['mastercard','MASTER'],'3':['amex','AMEX']};
  const [cls,lbl] = map[v[0]] || ['',''];
  document.getElementById('cardVis').className = 'card-vis'+(cls?' '+cls:'');
  document.getElementById('cvBrand').textContent = lbl || 'CARD';
}
function onVence(inp) {
  let v = inp.value.replace(/\D/g,'');
  if (v.length>=3) v = v.slice(0,2)+'/'+v.slice(2,4);
  inp.value = v;
  document.getElementById('cvVence').textContent = v||'MM/AA';
}

function copiar(txt) {
  navigator.clipboard?.writeText(txt).then(() => toast('Copiado: '+txt));
}
function toast(msg) {
  const t = document.createElement('div');
  t.textContent = msg;
  Object.assign(t.style, {position:'fixed',bottom:'22px',right:'22px',background:'#2e7d32',color:'white',padding:'10px 16px',borderRadius:'10px',fontSize:'13px',fontWeight:'700',zIndex:'9999',boxShadow:'0 4px 20px rgba(0,0,0,.15)',transition:'opacity .3s',fontFamily:'Plus Jakarta Sans,sans-serif'});
  document.body.appendChild(t);
  setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>t.remove(),300); }, 2000);
}

function validarFormulario() {
  let ok = true, msg = '';

  if (TIPO === 'tarjeta') {
    const num = (document.querySelector('[name=num_tarjeta]')?.value||'').replace(/\s/g,'');
    const cvv = document.querySelector('[name=cvv]')?.value||'';
    const ven = document.querySelector('[name=vencimiento]')?.value||'';
    const tit = (document.querySelector('[name=titular]')?.value||'').trim();
    const palabras = tit.split(/\s+/).filter(p => p.length >= 2);

    if (num.length < 15)
      { ok=false; msg='El número de tarjeta debe tener 15 o 16 dígitos.'; }
    else if (!tit || palabras.length < 2)
      { ok=false; msg='Ingresa el nombre y apellido del titular como aparece en la tarjeta.'; }
    else if (!/^\d{2}\/\d{2}$/.test(ven))
      { ok=false; msg='La fecha de vencimiento debe tener formato MM/AA.'; }
    else {
      const [mm, yy] = ven.split('/');
      if (parseInt(mm) < 1 || parseInt(mm) > 12)
        { ok=false; msg='El mes de vencimiento no es válido (01–12).'; }
      else if (new Date(2000+parseInt(yy), parseInt(mm)-1) < new Date())
        { ok=false; msg='La tarjeta está vencida.'; }
      else {
              const esAmex = num.length === 15;
      if (esAmex && cvv.length !== 4)
          { ok=false; msg='Las tarjetas AMEX tienen 4 dígitos de seguridad.'; }
      else if (!esAmex && (cvv.length < 3 || cvv.length > 4))
     { ok=false; msg='El código de seguridad debe tener 3 o 4 dígitos.'; }

      }
    }

  } else if (TIPO === 'billetera') {
    const tel = (document.querySelector('[name=telefono]')?.value||'').replace(/\D/g,'');
    if (tel.length !== 10)
      { ok=false; msg='El número de celular debe tener exactamente 10 dígitos.'; }
    else if (!/^3(0[0-9]|1[0-9]|2[0-9]|3[0-9]|5[0-9])\d{7}$/.test(tel))
      { ok=false; msg='Ingresa un número celular colombiano válido (ej: 3001234567).'; }

  } else if (TIPO === 'pse') {
    const banco  = document.querySelector('[name=banco]')?.value||'';
    const tiper  = document.querySelector('[name=tipo_persona]')?.value||'';
    const tdoc   = document.querySelector('[name=tipo_doc]')?.value||'';
    const doc    = (document.querySelector('[name=documento_pse]')?.value||'').trim();

    if (!banco)
      { ok=false; msg='Selecciona tu entidad bancaria.'; }
    else if (!tiper)
      { ok=false; msg='Selecciona el tipo de persona.'; }
    else if (!tdoc)
      { ok=false; msg='Selecciona el tipo de documento.'; }
    else if (!/^\d{7,10}$/.test(doc))
      { ok=false; msg='El número de documento debe tener entre 7 y 10 dígitos.'; }
    else if (/^(.)\1+$/.test(doc))
      { ok=false; msg='El número de documento no es válido.'; }

  } else if (TIPO === 'transferencia') {
    const banco  = document.querySelector('[name=banco_origen]')?.value||'';
    const tcta   = document.querySelector('[name=tipo_cuenta]')?.value||'';
    const cta    = (document.querySelector('[name=num_cuenta]')?.value||'').trim();
    const tit    = (document.querySelector('[name=titular_cuenta]')?.value||'').trim();
    const doc    = (document.querySelector('[name=doc_transferencia]')?.value||'').trim();
    const palabras = tit.split(/\s+/).filter(p => p.length >= 2);

    if (!banco)
      { ok=false; msg='Selecciona tu banco de origen.'; }
    else if (!tcta)
      { ok=false; msg='Selecciona el tipo de cuenta.'; }
    else if (!/^\d{8,18}$/.test(cta))
      { ok=false; msg='El número de cuenta debe tener entre 8 y 18 dígitos.'; }
    else if (palabras.length < 2)
      { ok=false; msg='Ingresa el nombre y apellido completo del titular de la cuenta.'; }
    else if (!/^\d{7,10}$/.test(doc))
      { ok=false; msg='El número de documento debe tener entre 7 y 10 dígitos.'; }
    else if (/^(.)\1+$/.test(doc))
      { ok=false; msg='El número de documento no es válido.'; }
  }

  return {ok, msg};
}
function mostrarError(msg) {
  let er = document.getElementById('inline-error');
  if (!er) {
    er = document.createElement('div'); er.id='inline-error'; er.className='info red'; er.style.marginBottom='16px';
    er.innerHTML='<i class="bi bi-exclamation-triangle-fill"></i><span></span>';
    formEl.parentNode.insertBefore(er, formEl);
  }
  er.style.display='flex'; er.querySelector('span').textContent=msg;
  er.scrollIntoView({behavior:'smooth',block:'center'});
}

function iniciarPago() {
  const {ok, msg} = validarFormulario();
  if (!ok) { mostrarError(msg); return; }
  const er = document.getElementById('inline-error');
  if (er) er.style.display='none';

  document.getElementById('confirmTotalText').textContent = TOTAL_FMT;
  const sub = document.getElementById('confirmSubtitle');
  if (TIPO === 'efectivo') {
    sub.innerHTML = 'Se generará tu recibo PDF y se enviará a tu correo.';
  } else if (TIPO === 'billetera' || TIPO === 'pse') {
    sub.innerHTML = 'Se enviará un código de 6 dígitos a tu correo registrado para verificar el pago.';
  } else {
    sub.innerHTML = 'Tu transferencia será registrada y procesada de forma <strong>inmediata</strong>.';
  }
  document.getElementById('modalConfirm').classList.add('on');
}

function cancelarConfirm() {
  document.getElementById('modalConfirm').classList.remove('on');
}

function confirmarPago() {
  document.getElementById('modalConfirm').classList.remove('on');
  document.getElementById('btnPay').disabled = true;
  document.getElementById('modalProc').classList.add('on');

  const enviaCodigo = (TIPO === 'billetera' || TIPO === 'pse');

  if (!enviaCodigo) {
    document.getElementById('ms3').style.display = 'none';
    document.getElementById('ms4').style.display = 'none';
  }

  const pasos = enviaCodigo ? ['ms1','ms2','ms3','ms4'] : ['ms1','ms2'];
  pasos.forEach((id, i) => {
    setTimeout(() => {
      const el = document.getElementById(id);
      el.classList.add('ok');
      el.querySelector('i').className = 'bi bi-check-circle-fill';
    }, i * 720);
  });

  setTimeout(() => formEl.submit(), enviaCodigo ? 3300 : 1800);
}
</script>
</body>
</html>