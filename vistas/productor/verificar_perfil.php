<?php
require_once '../../config/conexion.php';

function perfilCompleto($conn, $id_productor) {
    $query = "SELECT * FROM perfiles_productor WHERE id_productor = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id_productor);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return false; 
    }
    
    $perfil = $result->fetch_assoc();
    
    $campos_requeridos = [
        'nombre_finca',
        'descripcion',
        'ubicacion',
        'tipo_produccion',
        'experiencia'
    ];
    
    foreach ($campos_requeridos as $campo) {
        if (empty($perfil[$campo]) || trim($perfil[$campo]) === '') {
            return false;
        }
    }
    
    return true;
}

function camposFaltantes($conn, $id_productor) {
    $query = "SELECT * FROM perfiles_productor WHERE id_productor = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id_productor);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $faltantes = [];
    
    if ($result->num_rows === 0) {
        return [
            'Nombre de la Finca',
            'Descripción',
            'Ubicación',
            'Tipo de Producción',
            'Experiencia'
        ];
    }
    
    $perfil = $result->fetch_assoc();
    
    $campos = [
        'nombre_finca' => 'Nombre de la Finca',
        'descripcion' => 'Descripción',
        'ubicacion' => 'Ubicación',
        'tipo_produccion' => 'Tipo de Producción',
        'experiencia' => 'Experiencia'
    ];
    
    foreach ($campos as $campo => $nombre) {
        if (empty($perfil[$campo]) || trim($perfil[$campo]) === '') {
            $faltantes[] = $nombre;
        }
    }
    
    return $faltantes;
}

function porcentajeCompletitud($conn, $id_productor) {
    $query = "SELECT * FROM perfiles_productor WHERE id_productor = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id_productor);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return 0;
    }
    
    $perfil = $result->fetch_assoc();
    $total_campos = 6; 
    $completados = 0;
    
    $campos = [
        'nombre_finca',
        'descripcion',
        'ubicacion',
        'tipo_produccion',
        'experiencia',
        'foto_perfil'
    ];
    
    foreach ($campos as $campo) {
        if (!empty($perfil[$campo]) && trim($perfil[$campo]) !== '') {
            $completados++;
        }
    }
    
    return round(($completados / $total_campos) * 100);
}
?>