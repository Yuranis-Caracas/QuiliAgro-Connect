<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/src/SMTP.php';
require_once __DIR__ . '/../phpmailer/src/Exception.php';

if (file_exists(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}
if (!defined('BREVO_API_KEY')) {
    define('BREVO_API_KEY', '');
}

function enviarCorreo($destinatario, $nombre, $asunto, $cuerpoHTML, $adjuntos = []) {
    $data = [
        'sender'     => ['name' => 'QuiliAgro Connect', 'email' => 'yuraniscaracas06@gmail.com'],
        'to'         => [['email' => $destinatario, 'name' => $nombre]],
        'subject'    => $asunto,
        'htmlContent'=> $cuerpoHTML,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("Brevo cURL error: " . $error);
        return ['success' => false, 'message' => $error];
    }

    if ($httpCode === 201) {
        return ['success' => true, 'message' => 'Correo enviado'];
    }

    error_log("Brevo API error: " . $response);
    return ['success' => false, 'message' => $response];
}

function enviarCorreoConAdjunto(string $email, string $nombre, string $asunto, string $html, string $pdf_bytes, string $pdf_nombre): bool {
    $data = [
        'sender'      => ['name' => 'QuiliAgro Connect', 'email' => 'yuraniscaracas06@gmail.com'],
        'to'          => [['email' => $email, 'name' => $nombre]],
        'subject'     => $asunto,
        'htmlContent' => $html,
        'attachment'  => [[
            'content' => base64_encode($pdf_bytes),
            'name'    => $pdf_nombre,
        ]],
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 201;
}