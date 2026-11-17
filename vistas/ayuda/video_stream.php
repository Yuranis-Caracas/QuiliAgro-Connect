<?php
/* Servidor de video con soporte Range requests*/

$file = $_GET['f'] ?? '';

$file = basename($file);

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!in_array($ext, ['mp4', 'webm', 'ogg'])) {
    http_response_code(400);
    exit('Tipo no permitido');
}


$path = __DIR__ . '/../../uploads/tutoriales/' . $file;

$path      = realpath($path);
$base_dir  = realpath(__DIR__ . '/../../uploads/tutoriales');

if ($path === false || $base_dir === false || strpos($path, $base_dir) !== 0) {
    http_response_code(403);
    exit('Acceso denegado');
}

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit('Video no encontrado');
}

$mime_types = [
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'ogg'  => 'video/ogg',
];
$mime = $mime_types[$ext] ?? 'video/mp4';

$size      = filesize($path);
$start     = 0;
$end       = $size - 1;
$chunkSize = 1024 * 1024; 

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_RANGE'])) {
    $range = $_SERVER['HTTP_RANGE'];

    if (!preg_match('/bytes=(\d*)-(\d*)/i', $range, $matches)) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit();
    }

    $start = $matches[1] !== '' ? intval($matches[1]) : 0;
    $end   = $matches[2] !== '' ? intval($matches[2]) : $size - 1;

    if ($start > $end || $end >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit();
    }

    $length = $end - $start + 1;

    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
    header("Content-Length: $length");
} else {
    header("Content-Length: $size");
}

$fp = fopen($path, 'rb');
fseek($fp, $start);

$remaining = $end - $start + 1;
while (!feof($fp) && $remaining > 0 && !connection_aborted()) {
    $toRead     = min($chunkSize, $remaining);
    echo fread($fp, $toRead);
    $remaining -= $toRead;
    flush();
}
fclose($fp);
exit();