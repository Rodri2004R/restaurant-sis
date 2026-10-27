<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/supabase.php';

require_role(['Administrador', 'Recepcionista', 'Mesero']);

$id = clean_input($_GET['id'] ?? '');

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'ID de reserva no especificado o formato de identificador inválido.');
    redirect('/modules/reservas/');
}

$supabase = new SupabaseClient();

if (!$supabase->isConfigured()) {
    set_flash_message('warning', 'Supabase no está configurado.');
    redirect('/modules/reservas/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;

// 2. Consultar y verificar que la reserva exista antes de cancelarla
$checkRes = $supabase->select('reservas', 'id,mesa_id,estado,fecha,hora', ['id' => 'eq.' . $id], $authToken);

if (!$checkRes['success'] || empty($checkRes['data'])) {
    set_flash_message('danger', 'La reserva no fue encontrada en el sistema.');
    redirect('/modules/reservas/');
}

$reserva = $checkRes['data'][0];
$estadoActual = $reserva['estado'] ?? '';
$mesaId = $reserva['mesa_id'] ?? null;

// 3. Si la reserva ya está Cancelada, informar al usuario sin actualizar
if ($estadoActual === 'Cancelada') {
    set_flash_message('info', 'La reserva ya se encontraba cancelada.');
    redirect('/modules/reservas/');
}

// 4. Si la reserva ya fue Atendida, impedir su cancelación
if ($estadoActual === 'Atendida') {
    set_flash_message('warning', 'No se puede cancelar una reserva que ya ha sido atendida.');
    redirect('/modules/reservas/');
}

// 5. Para reservas activas (Pendiente / Confirmada), cambiar el estado a 'Cancelada'
$res = $supabase->update('reservas', ['estado' => 'Cancelada'], 'id=eq.' . $id, $authToken);

if ($res['success']) {
    // 6. US-007: Liberación condicional y segura de la mesa asociada
    if (!empty($mesaId)) {
        // Verificar si la mesa tiene pedidos activos en cocina/salón
        $resActiveOrders = $supabase->select('pedidos', 'id', [
            'mesa_id' => 'eq.' . $mesaId,
            'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)'
        ], $authToken);
        $hasActiveOrders = ($resActiveOrders['success'] && !empty($resActiveOrders['data']));

        // Verificar si la mesa tiene otras reservas activas para la fecha actual
        $fechaHoy = date('Y-m-d');
        $resOtherReservas = $supabase->select('reservas', 'id', [
            'mesa_id' => 'eq.' . $mesaId,
            'id' => 'neq.' . $id,
            'fecha' => 'eq.' . $fechaHoy,
            'estado' => 'in.(Pendiente,Confirmada)'
        ], $authToken);
        $hasOtherReservas = ($resOtherReservas['success'] && !empty($resOtherReservas['data']));

        // Si no hay pedidos ni otras reservas activas hoy, verificar estado de la mesa y liberar
        if (!$hasActiveOrders && !$hasOtherReservas) {
            $resMesaInfo = $supabase->select('mesas', 'id,estado', ['id' => 'eq.' . $mesaId], $authToken);
            if ($resMesaInfo['success'] && !empty($resMesaInfo['data'])) {
                $estadoMesa = $resMesaInfo['data'][0]['estado'] ?? 'disponible';
                // No alterar mesas en mantenimiento; liberar si estaba ocupada o reservada
                if ($estadoMesa !== 'mantenimiento' && $estadoMesa !== 'disponible') {
                    $supabase->update('mesas', ['estado' => 'disponible'], 'id=eq.' . $mesaId, $authToken);
                }
            }
        }
    }

    set_flash_message('success', 'La reserva ha sido cancelada correctamente.');
} else {
    set_flash_message('danger', 'Error al cancelar la reserva: ' . ($res['error'] ?? 'Error desconocido'));
}

redirect('/modules/reservas/');
