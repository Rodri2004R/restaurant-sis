<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/supabase.php';

require_role(['Administrador', 'Recepcionista']);

$id = clean_input($_GET['id'] ?? '');
$newStatus = (($_GET['status'] ?? '1') === '1');

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (!empty($id) && is_valid_uuid($id)) {
    $supabase = new SupabaseClient();
    if ($supabase->isConfigured()) {
        $authToken = $_SESSION['user']['access_token'] ?? null;
        $res = $supabase->update('clientes', ['estado' => $newStatus], 'id=eq.' . $id, $authToken);
        if ($res['success']) {
            set_flash_message('success', 'El estado del cliente ha sido ' . ($newStatus ? 'activado' : 'desactivado') . ' correctamente.');
        } else {
            set_flash_message('danger', 'Error al cambiar el estado del cliente: ' . ($res['error'] ?? 'Error desconocido'));
        }
    }
} else {
    set_flash_message('danger', 'Identificador de cliente no válido.');
}

redirect('/modules/clientes/');

