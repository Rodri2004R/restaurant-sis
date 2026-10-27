<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/supabase.php';

require_role(['Administrador']);

$id = clean_input($_GET['id'] ?? '');
$type = clean_input($_GET['type'] ?? 'estado');
$newStatus = (($_GET['status'] ?? '1') === '1');

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (!empty($id) && is_valid_uuid($id)) {
    $supabase = new SupabaseClient();
    if ($supabase->isConfigured()) {
        $authToken = $_SESSION['user']['access_token'] ?? null;
        
        $updateData = [];
        if ($type === 'disponible') {
            $updateData['disponible'] = $newStatus;
            $msgType = $newStatus ? 'marcado como disponible' : 'marcado como agotado';
        } else {
            $updateData['estado'] = $newStatus;
            $msgType = $newStatus ? 'activado' : 'desactivado';
        }

        $res = $supabase->update('productos', $updateData, 'id=eq.' . $id, $authToken);
        if ($res['success']) {
            set_flash_message('success', 'El producto ha sido ' . $msgType . ' correctamente.');
        } else {
            set_flash_message('danger', 'Error al actualizar el producto: ' . ($res['error'] ?? 'Error desconocido'));
        }
    }
} else {
    set_flash_message('danger', 'Identificador de producto no válido.');
}

redirect('/modules/productos/');
