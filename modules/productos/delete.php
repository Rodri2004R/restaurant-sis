<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/supabase.php';

require_role(['Administrador']);

$id = clean_input($_GET['id'] ?? '');

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (!empty($id) && is_valid_uuid($id)) {
    $supabase = new SupabaseClient();
    if ($supabase->isConfigured()) {
        $authToken = $_SESSION['user']['access_token'] ?? null;

        // 2. Verificar si el producto tiene registros en detalles_pedido
        $checkOrders = $supabase->select('detalles_pedido', 'id', ['producto_id' => 'eq.' . $id], $authToken);

        if ($checkOrders['success'] && !empty($checkOrders['data'])) {
            set_flash_message('warning', 'No se puede eliminar este producto porque ya cuenta con pedidos registrados en el historial. Puedes desactivarlo o marcarlo como agotado para que no aparezca en nuevas comandas.');
        } else {
            // 3. Eliminar producto si no tiene historial
            $delRes = $supabase->delete('productos', 'id=eq.' . $id, $authToken);
            if ($delRes['success']) {
                set_flash_message('success', 'El producto ha sido eliminado del catálogo correctamente.');
            } else {
                set_flash_message('danger', 'Error al eliminar el producto: ' . ($delRes['error'] ?? 'Error desconocido'));
            }
        }
    }
} else {
    set_flash_message('danger', 'Identificador de producto no válido.');
}

redirect('/modules/productos/');
