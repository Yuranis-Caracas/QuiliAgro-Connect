<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';

function insertarAlerta(PDO $conn, int $idComprador, string $tipo, string $mensaje,
                        ?int $idProducto = null, ?int $idProductor = null): bool
{
    $condProd  = $idProducto  ? "AND id_producto = ?"  : "AND id_producto IS NULL";
    $condProdr = $idProductor ? "AND id_productor = ?" : "AND id_productor IS NULL";

    $dup = $conn->prepare(
        "SELECT id_alerta FROM alertas
         WHERE id_comprador = ? AND tipo_alerta = ?
           $condProd $condProdr
           AND fecha_creacion >= DATE_SUB(NOW(), INTERVAL 3 DAY)
         LIMIT 1"
    );
    $params = [$idComprador, $tipo];
    if ($idProducto)  $params[] = $idProducto;
    if ($idProductor) $params[] = $idProductor;
    $dup->execute($params);
    if ($dup->rowCount() > 0) return false;

    $conn->prepare(
        "INSERT INTO alertas (id_comprador, tipo_alerta, mensaje, id_producto, id_productor)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$idComprador, $tipo, $mensaje, $idProducto, $idProductor]);

    return true;
}

function enviarEmailAlertas(string $email, string $nombre, array $alertasNuevas): void
{
    if (empty($alertasNuevas) || empty($email)) return;

    $totalAlertas = count($alertasNuevas);
    $nombreEsc    = htmlspecialchars($nombre);

    $itemsHTML = '';
    $iconos = [
        'nuevo_producto'      => '🛒',
        'producto_disponible' => '✅',
        'nuevo_productor'     => '👨‍🌾',
        'promocion'           => '🏷️',
    ];

    foreach ($alertasNuevas as $alerta) {
        $icono = $iconos[$alerta['tipo']] ?? '🔔';
        $msg   = htmlspecialchars($alerta['mensaje']);
        $itemsHTML .= "
        <tr>
          <td style='padding:14px 0;border-bottom:1px solid #f0f0f0;'>
            <table cellpadding='0' cellspacing='0'>
              <tr>
                <td style='width:36px;vertical-align:top;font-size:20px;padding-top:2px;'>{$icono}</td>
                <td style='padding-left:12px;font-size:14px;color:#444;line-height:1.5;'>{$msg}</td>
              </tr>
            </table>
          </td>
        </tr>";
    }


    $html = "<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4f6f9;font-family:Segoe UI,Arial,sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f6f9;padding:30px 0;'>
    <tr><td align='center'>
      <table width='520' cellpadding='0' cellspacing='0'
             style='background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);'>
        <!-- Header -->
        <tr>
          <td style='background:linear-gradient(135deg,#28a745,#20c997);padding:28px 36px;text-align:center;'>
            <h1 style='color:#fff;margin:0;font-size:20px;font-weight:700;'>🌿 QuiliAgro Connect</h1>
            <p style='color:rgba(255,255,255,.9);margin:8px 0 0;font-size:14px;'>
              Tienes {$totalAlertas} notificacion" . ($totalAlertas > 1 ? 'es nuevas' : ' nueva') . "
            </p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style='padding:28px 36px;'>
            <p style='color:#333;font-size:15px;margin:0 0 6px;'>
              Hola, <strong>{$nombreEsc}</strong>
            </p>
            <p style='color:#888;font-size:13px;margin:0 0 24px;'>
              Basándonos en tus preferencias e historial, encontramos esto para ti:
            </p>
            <table width='100%' cellpadding='0' cellspacing='0'>
              {$itemsHTML}
            </table>
            <p style='text-align:center;margin-top:24px;color:#888;font-size:13px;'>
              Ingresa a la plataforma para ver todas tus notificaciones.
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style='background:#f8f9fa;padding:16px 36px;text-align:center;border-top:1px solid #eee;'>
            <p style='color:#bbb;font-size:12px;margin:0;'>
              © " . date('Y') . " QuiliAgro Connect &nbsp;·&nbsp;
              Estas notificaciones se generaron automáticamente según tus patrones de uso.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>";

    enviarCorreo($email, $nombre, "🔔 Tienes {$totalAlertas} notificacion" . ($totalAlertas > 1 ? 'es nuevas' : ' nueva') . " en QuiliAgro Connect", $html);
}

function generarAlertas(): array
{
    $database = new Database();
    $conn     = $database->getConnection();

    $generadas = 0;
    $log       = [];

    $compradores = $conn->query(
        "SELECT id_usuario, nombre, email FROM usuarios
         WHERE rol = 'comprador' AND estado = 'activo' AND eliminado = 0"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($compradores as $c) {
        $id              = (int)$c['id_usuario'];
        $alertasCreadas  = []; 
        $alertasPorComprador = 0; 

        $stComp = $conn->prepare(
            "SELECT DISTINCT p.id_categoria, pe.id_producto
             FROM pedidos pe
             JOIN productos p ON p.id_producto = pe.id_producto
             WHERE pe.id_comprador = ?"
        );
        $stComp->execute([$id]);
        $compras        = $stComp->fetchAll(PDO::FETCH_ASSOC);
        $catsCompra     = array_unique(array_column($compras, 'id_categoria'));
        $prodsComprados = array_unique(array_column($compras, 'id_producto'));
        $prodsStr       = empty($prodsComprados) ? '0' : implode(',', $prodsComprados);

        $stBusc = $conn->prepare(
            "SELECT DISTINCT id_categoria FROM historial_busquedas
             WHERE id_comprador = ?
               AND id_categoria IS NOT NULL
               AND fecha_busqueda >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $stBusc->execute([$id]);
        $catsBusc  = array_column($stBusc->fetchAll(), 'id_categoria');
        $todasCats = array_unique(array_merge($catsCompra, $catsBusc));

        // ══════════════════════════════════════════════════════════════════════
        // NIVEL A — Tiene historial: alertas personalizadas por patrón
        // ══════════════════════════════════════════════════════════════════════
        if (!empty($todasCats)) {
            $ph = implode(',', array_fill(0, count($todasCats), '?'));

            $stSim = $conn->prepare(
                "SELECT p.id_producto, p.nombre, p.id_productor,
                        cat.nombre_categoria, u.nombre AS nombre_productor
                 FROM productos p
                 JOIN categorias_productos cat ON cat.id_categoria = p.id_categoria
                 JOIN usuarios u ON u.id_usuario = p.id_productor
                 WHERE p.id_categoria IN ($ph)
                   AND p.estado = 'disponible'
                   AND p.id_producto NOT IN ($prodsStr)
                 ORDER BY p.fecha_creacion DESC
                 LIMIT 6"
            );
            $stSim->execute($todasCats);

            foreach ($stSim->fetchAll(PDO::FETCH_ASSOC) as $prod) {
                $msg = "Puede interesarte: \"{$prod['nombre']}\" "
                     . "en {$prod['nombre_categoria']} — {$prod['nombre_productor']}.";
                if ($alertasPorComprador < 5 && insertarAlerta($conn, $id, 'nuevo_producto', $msg,
                                   (int)$prod['id_producto'], (int)$prod['id_productor'])) {
                    $generadas++; $alertasPorComprador++;
                    $alertasCreadas[] = ['tipo' => 'nuevo_producto', 'mensaje' => $msg];
                    $log[] = "[P1] comprador=$id prod={$prod['id_producto']}";
                }
            }

            if (!empty($prodsComprados)) {
                $stDisp = $conn->prepare(
                    "SELECT DISTINCT p.id_producto, p.nombre, p.id_productor,
                            u.nombre AS nombre_productor
                     FROM pedidos pe
                     JOIN productos p ON p.id_producto = pe.id_producto
                     JOIN usuarios u ON u.id_usuario = p.id_productor
                     WHERE pe.id_comprador = ?
                       AND p.estado = 'disponible'
                       AND p.cantidad > 0"
                );
                $stDisp->execute([$id]);

                foreach ($stDisp->fetchAll(PDO::FETCH_ASSOC) as $prod) {
                    $msg = "¡Sigue disponible! \"{$prod['nombre']}\" "
                         . "de {$prod['nombre_productor']} que compraste antes.";
                    if ($alertasPorComprador < 5 && insertarAlerta($conn, $id, 'producto_disponible', $msg,
                                       (int)$prod['id_producto'], (int)$prod['id_productor'])) {
                        $generadas++; $alertasPorComprador++;
                        $alertasCreadas[] = ['tipo' => 'producto_disponible', 'mensaje' => $msg];
                        $log[] = "[P2] comprador=$id prod={$prod['id_producto']}";
                    }
                }
            }

            $stNuevo = $conn->prepare(
                "SELECT p.id_producto, p.nombre, p.id_productor,
                        cat.nombre_categoria, u.nombre AS nombre_productor
                 FROM productos p
                 JOIN categorias_productos cat ON cat.id_categoria = p.id_categoria
                 JOIN usuarios u ON u.id_usuario = p.id_productor
                 WHERE p.id_categoria IN ($ph)
                   AND p.estado = 'disponible'
                   AND p.fecha_creacion >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
                 ORDER BY p.fecha_creacion DESC
                 LIMIT 5"
            );
            $stNuevo->execute($todasCats);

            foreach ($stNuevo->fetchAll(PDO::FETCH_ASSOC) as $prod) {
                $msg = "Nuevo en tu categoría: \"{$prod['nombre']}\" "
                     . "— {$prod['nombre_productor']}.";
                if ($alertasPorComprador < 5 && insertarAlerta($conn, $id, 'nuevo_producto', $msg,
                                   (int)$prod['id_producto'], (int)$prod['id_productor'])) {
                    $generadas++; $alertasPorComprador++;
                    $alertasCreadas[] = ['tipo' => 'nuevo_producto', 'mensaje' => $msg];
                    $log[] = "[P3] comprador=$id prod={$prod['id_producto']}";
                }
            }

            $stProd = $conn->prepare(
                "SELECT DISTINCT u.id_usuario, u.nombre, pp.nombre_finca, pp.ubicacion
                 FROM usuarios u
                 JOIN perfiles_productor pp ON pp.id_productor = u.id_usuario
                 JOIN productos prod ON prod.id_productor = u.id_usuario
                 WHERE u.rol = 'productor' AND u.estado = 'activo' AND u.eliminado = 0
                   AND u.fecha_registro >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   AND prod.id_categoria IN ($ph)"
            );
            $stProd->execute($todasCats);

            foreach ($stProd->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $finca = $p['nombre_finca'] ? ", Finca {$p['nombre_finca']}" : '';
                $ubi   = $p['ubicacion']    ? " ({$p['ubicacion']})"         : '';
                $msg   = "Nuevo productor: {$p['nombre']}{$finca}{$ubi}"
                       . " — ofrece productos en tus categorías de interés.";
                if ($alertasPorComprador < 5 && insertarAlerta($conn, $id, 'nuevo_productor', $msg,
                                   null, (int)$p['id_usuario'])) {
                    $generadas++; $alertasPorComprador++;
                    $alertasCreadas[] = ['tipo' => 'nuevo_productor', 'mensaje' => $msg];
                    $log[] = "[P4] comprador=$id productor={$p['id_usuario']}";
                }
            }

            $stPrecio = $conn->prepare(
                "SELECT p.id_producto, p.nombre, p.precio, p.id_productor,
                        cat.nombre_categoria, u.nombre AS nombre_productor
                FROM productos p
                JOIN categorias_productos cat ON cat.id_categoria = p.id_categoria
                JOIN usuarios u ON u.id_usuario = p.id_productor
                WHERE p.id_categoria IN ($ph)
                  AND p.estado = 'disponible'
                  AND p.fecha_actualizacion >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
                  AND p.fecha_actualizacion != p.fecha_creacion"
            );
            $stPrecio->execute($todasCats);

            foreach ($stPrecio->fetchAll(PDO::FETCH_ASSOC) as $prod) {
                $precio = '$' . number_format($prod['precio'], 0, ',', '.');
                $msg = "Actualización de precio: {$prod['nombre']} en "
                    . "{$prod['nombre_categoria']} — {$prod['nombre_productor']}"
                    . " ahora a {$precio}.";
                if ($alertasPorComprador < 5 && insertarAlerta($conn, $id, 'nuevo_producto', $msg,
                                  (int)$prod['id_producto'], (int)$prod['id_productor'])) {
                    $generadas++;
                    $alertasPorComprador++;
                    $alertasCreadas[] = ['tipo' => 'nuevo_producto', 'mensaje' => $msg];
                    $log[] = "[P5-precio] comprador=$id prod={$prod['id_producto']}";
                }
            }

        } // fin NIVEL A
        // ══════════════════════════════════════════════════════════════════════
        // NIVEL B — Sin historial (comprador nuevo o inactivo)
        // ══════════════════════════════════════════════════════════════════════
else {
    $stPrev = $conn->prepare("SELECT COUNT(*) FROM alertas WHERE id_comprador = ?");
    $stPrev->execute([$id]);
    $yaRecibio = (int)$stPrev->fetchColumn() > 0;

    $prefijo = $yaRecibio ? "Novedades: " : "¡Bienvenid@! Disponible ahora: ";

    if ($yaRecibio) {
        $stNew = $conn->prepare(
            "SELECT p.id_producto, p.nombre, p.id_productor,
                    cat.nombre_categoria, u.nombre AS nombre_productor
             FROM productos p
             JOIN categorias_productos cat ON cat.id_categoria = p.id_categoria
             JOIN usuarios u ON u.id_usuario = p.id_productor
             WHERE p.estado = 'disponible'
               AND p.fecha_creacion >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY p.fecha_creacion DESC
             LIMIT 5"
        );
        $stNew->execute([]);
    } else {
        $stNew = $conn->prepare(
            "SELECT p.id_producto, p.nombre, p.id_productor,
                    cat.nombre_categoria, u.nombre AS nombre_productor
             FROM productos p
             JOIN categorias_productos cat ON cat.id_categoria = p.id_categoria
             JOIN usuarios u ON u.id_usuario = p.id_productor
             WHERE p.estado = 'disponible'
             ORDER BY p.fecha_creacion DESC
             LIMIT 5"
        );
        $stNew->execute([]);
    }

    foreach ($stNew->fetchAll(PDO::FETCH_ASSOC) as $prod) {
        $msg = $prefijo . "\"{$prod['nombre']}\" "
             . "en {$prod['nombre_categoria']} — {$prod['nombre_productor']}.";
        if (insertarAlerta($conn, $id, 'nuevo_producto', $msg,
                           (int)$prod['id_producto'], (int)$prod['id_productor'])) {
            $generadas++;
            $alertasCreadas[] = ['tipo' => 'nuevo_producto', 'mensaje' => $msg];
            $nivel = $yaRecibio ? 'inactivo' : 'nuevo';
            $log[] = "[B-$nivel] comprador=$id prod={$prod['id_producto']}";
        }
    }
}

        if (!empty($alertasCreadas) && !empty($c['email'])) {
            try {
                enviarEmailAlertas($c['email'], $c['nombre'], $alertasCreadas);
                $log[] = "[EMAIL] comprador=$id ({$c['email']}) alertas=" . count($alertasCreadas);
            } catch (Exception $e) {
                error_log("Email alertas comprador $id: " . $e->getMessage());
            }
        }
    }

    $conn->exec(
        "DELETE FROM alertas
         WHERE leida = 1
           AND fecha_creacion < DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );

    return ['generadas' => $generadas, 'log' => $log];
}

try {
    $resultado = generarAlertas();

    if (php_sapi_name() === 'cli') {
        echo "✅ Alertas generadas: {$resultado['generadas']}\n";
        foreach ($resultado['log'] as $l) echo "   $l\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'generadas' => $resultado['generadas']]);
    }
} catch (Exception $e) {
    error_log('generar_alertas: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo "❌ Error: " . $e->getMessage() . "\n";
    } else {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error interno']);
    }
}