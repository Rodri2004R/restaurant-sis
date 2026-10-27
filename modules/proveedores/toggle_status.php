<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/supabase.php';

require_role(['Administrador']);

// Exigir estrictamente método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash_message('danger', 'Método de solicitud no permitido.');
    redirect('/modules/proveedores/');
}

$id = clean_input($_POST['id'] ?? '');

// 1. Validar estrictamente el UUID recibido por $_POST['id']
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'Identificador de proveedor no especificado o formato inválido.');
    redirect('/modules/proveedores/');
}

$supabase = new SupabaseClient();

if (!$supabase->isConfigured()) {
    set_flash_message('danger', 'Error de conexión con la base de datos.');
    redirect('/modules/proveedores/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;

// 2. Consultar el proveedor actual para alternar su estado
$checkRes = $supabase->select('proveedores', 'id,nombre,estado', ['id' => 'eq.' . $id], $authToken);

if (!$checkRes['success'] || empty($checkRes['data'])) {
    set_flash_message('danger', 'El proveedor especificado no existe en la base de datos.');
    redirect('/modules/proveedores/');
}

$proveedor = $checkRes['data'][0];
$currentStatus = (bool)($proveedor['estado'] ?? true);
$newStatus = !$currentStatus;

// 3. Actualizar el estado lógico sin eliminar registros físicos
$updateRes = $supabase->update('proveedores', ['estado' => $newStatus], 'id=eq.' . $id, $authToken);

if ($updateRes['success']) {
    $accionTexto = $newStatus ? 'activado' : 'desactivado';
    set_flash_message('success', 'El proveedor "' . htmlspecialchars($proveedor['nombre'] ?? '') . '" ha sido ' . $accionTexto . ' correctamente.');
} else {
    set_flash_message('danger', 'Error al cambiar el estado del proveedor: ' . ($updateRes['error'] ?? 'Error desconocido'));
}

redirect('/modules/proveedores/');
