<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/supabase.php';

require_role(['Administrador']);

$id = clean_input($_GET['id'] ?? '');
$newStatus = (($_GET['status'] ?? '1') === '1');
$currentUser = get_authenticated_user();

if (!empty($id)) {
    // Impedir que el usuario actualmente autenticado pueda desactivar su propia cuenta
    if (!$newStatus && isset($currentUser['id']) && (string)$currentUser['id'] === (string)$id) {
        set_flash_message('warning', 'No puedes desactivar tu propia cuenta.');
        redirect('/modules/usuarios/');
    }

    $supabase = new SupabaseClient();
    if ($supabase->isConfigured()) {
        $authToken = $currentUser['access_token'] ?? null;
        $res = $supabase->update('perfiles', ['estado' => $newStatus], 'id=eq.' . urlencode($id), $authToken);
        if ($res['success']) {
            set_flash_message('success', 'El estado del usuario ha sido ' . ($newStatus ? 'activado' : 'desactivado') . ' correctamente.');
        } else {
            set_flash_message('danger', 'Error al cambiar el estado del usuario: ' . ($res['error'] ?? 'Error desconocido'));
        }
    }
}

redirect('/modules/usuarios/');
