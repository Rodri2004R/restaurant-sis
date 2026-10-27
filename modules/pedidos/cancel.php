<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/supabase.php';

require_auth();

$user = get_authenticated_user();
$userRole = $user['rol_nombre'] ?? '';

// Roles con permiso para cancelar
if (!in_array($userRole, ['Administrador', 'Mesero'])) {
    set_flash_message('danger', 'Acceso denegado. Tu rol de "' . htmlspecialchars($userRole) . '" no tiene permisos para cancelar comandas.');
    redirect('/modules/pedidos/');
}

$id = clean_input($_GET['id'] ?? '');

// 1. Validar identificador UUID
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'Identificador de comanda inválido.');
    redirect('/modules/pedidos/');
}

$supabase = new SupabaseClient();
if (!$supabase->isConfigured()) {
    set_flash_message('danger', 'Error de conexión con la base de datos.');
    redirect('/modules/pedidos/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;

// 2. Consultar pedido actual
$resPed = $supabase->select('pedidos', '*', ['id' => 'eq.' . $id], $authToken);
if (!$resPed['success'] || empty($resPed['data'])) {
    set_flash_message('danger', 'La comanda especificada no existe.');
    redirect('/modules/pedidos/');
}

$pedido = $resPed['data'][0];
$currentStatus = $pedido['estado'] ?? 'Pendiente';
$mesaId = $pedido['mesa_id'] ?? null;

// 3. Reglas RBAC de cancelación:
// - Mesero solo puede cancelar si el estado es 'Pendiente'
// - Administrador puede cancelar mientras no esté 'Pagado' ni 'Cancelado'
if ($userRole === 'Mesero' && $currentStatus !== 'Pendiente') {
    set_flash_message('danger', 'Como Mesero solo puedes cancelar pedidos en estado "Pendiente". Si el pedido ya está en preparación o servido, solicita asistencia al Administrador.');
    redirect('/modules/pedidos/view.php?id=' . $id);
}

if ($currentStatus === 'Pagado') {
    set_flash_message('danger', 'No se puede cancelar un pedido que ya fue pagado y cerrado en caja.');
    redirect('/modules/pedidos/view.php?id=' . $id);
}

if ($currentStatus === 'Cancelado') {
    set_flash_message('warning', 'Este pedido ya se encuentra cancelado.');
    redirect('/modules/pedidos/view.php?id=' . $id);
}

// 4. Actualizar estado a 'Cancelado'
$updateRes = $supabase->update('pedidos', ['estado' => 'Cancelado'], 'id=eq.' . $id, $authToken);

if ($updateRes['success']) {
    // 5. Verificar si la mesa puede liberarse
    if (!empty($mesaId)) {
        $resOtros = $supabase->select('pedidos', 'id', [
            'mesa_id' => 'eq.' . $mesaId,
            'id' => 'neq.' . $id,
            'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)'
        ], $authToken);

        if ($resOtros['success'] && empty($resOtros['data'])) {
            $supabase->update('mesas', ['estado' => 'disponible'], 'id=eq.' . $mesaId, $authToken);
        }
    }

    set_flash_message('success', 'El pedido #' . substr($id, 0, 8) . ' ha sido cancelado correctamente.');
} else {
    set_flash_message('danger', 'Error al cancelar el pedido: ' . ($updateRes['error'] ?? 'Error desconocido'));
}

redirect('/modules/pedidos/');
