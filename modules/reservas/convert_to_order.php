<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/supabase.php';

require_auth();
require_role(['Administrador', 'Recepcionista', 'Mesero']);

$id = clean_input($_GET['id'] ?? '');

// 1. Validar identificador UUID de la reserva
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'Identificador de reserva inválido.');
    redirect('/modules/reservas/');
}

$supabase = new SupabaseClient();
if (!$supabase->isConfigured()) {
    set_flash_message('danger', 'Error de conexión con la base de datos.');
    redirect('/modules/reservas/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;

// 2. Consultar reserva
$resRv = $supabase->select('reservas', '*,mesas(id,numero,ubicacion,capacidad),clientes(id,nombre,apellido,documento,telefono)', ['id' => 'eq.' . $id], $authToken);
if (!$resRv['success'] || empty($resRv['data'])) {
    set_flash_message('danger', 'La reserva especificada no fue encontrada.');
    redirect('/modules/reservas/');
}

$reserva = $resRv['data'][0];
$estadoReserva = $reserva['estado'] ?? 'Pendiente';
$mesaId = $reserva['mesa_id'] ?? null;

// 3. Control de reservas canceladas
if ($estadoReserva === 'Cancelada') {
    set_flash_message('warning', 'Esta reserva se encuentra cancelada y no puede ser atendida.');
    redirect('/modules/reservas/');
}

// 4. Control estricto de fecha, hora y vencimiento de 30 minutos (US-008)
$fechaHoy = date('Y-m-d');
$ahoraTimestamp = time();
$fechaRes = $reserva['fecha'] ?? '';
$horaRes = !empty($reserva['hora']) ? date('H:i:s', strtotime($reserva['hora'])) : '00:00:00';
$resTimestamp = strtotime($fechaRes . ' ' . $horaRes);
$tiempoLimite = $resTimestamp + (30 * 60);

// Rechazar fechas pasadas
if ($fechaRes < $fechaHoy) {
    if ($estadoReserva === 'Pendiente') {
        $supabase->update('reservas', [
            'estado' => 'Cancelada',
            'observaciones' => trim(($reserva['observaciones'] ?? '') . ' [Cancelada automáticamente: fecha pasada]')
        ], 'id=eq.' . $id, $authToken);
    }
    set_flash_message('danger', 'No se puede atender ni convertir una reserva de una fecha pasada (' . date('d/m/Y', strtotime($fechaRes)) . ').');
    redirect('/modules/reservas/');
}

// Rechazar fechas futuras
if ($fechaRes > $fechaHoy) {
    set_flash_message('warning', 'No se puede atender la reserva antes del día programado (' . date('d/m/Y', strtotime($fechaRes)) . ').');
    redirect('/modules/reservas/');
}

// Rechazar antes de la hora programada
if ($fechaRes === $fechaHoy && $ahoraTimestamp < $resTimestamp) {
    set_flash_message('warning', 'Aún no es la hora de la reserva (' . date('H:i', $resTimestamp) . '). La comanda podrá habilitarse cuando llegue el horario programado.');
    redirect('/modules/reservas/');
}

// Control de tolerancia de 30 minutos para reservas en estado Pendiente
if ($estadoReserva === 'Pendiente' && $ahoraTimestamp > $tiempoLimite) {
    // Cancelar automáticamente en base de datos
    $supabase->update('reservas', [
        'estado' => 'Cancelada',
        'observaciones' => trim(($reserva['observaciones'] ?? '') . ' [Cancelada automáticamente por superar los 30 min de tolerancia sin confirmación]')
    ], 'id=eq.' . $id, $authToken);

    set_flash_message('danger', 'La reserva programada para las ' . date('H:i', $resTimestamp) . ' ha vencido por superar los 30 minutos de tolerancia sin confirmación y fue cancelada automáticamente.');
    redirect('/modules/reservas/');
}

// 5. CONTROL DE DUPLICIDAD: Verificar si la mesa ya tiene una comanda activa en salón
if ($mesaId) {
    $resActivos = $supabase->select('pedidos', 'id,mesa_id,estado,total', [
        'mesa_id' => 'eq.' . $mesaId,
        'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)'
    ], $authToken);

    if ($resActivos['success'] && !empty($resActivos['data'])) {
        $existingOrder = $resActivos['data'][0];
        set_flash_message('info', 'La Mesa #' . ($reserva['mesas']['numero'] ?? '') . ' ya cuenta con una comanda activa en curso (#' . substr($existingOrder['id'], 0, 8) . '). Se redirigió a la orden existente.');
        redirect('/modules/pedidos/view.php?id=' . $existingOrder['id']);
    }
}

// 6. Si la reserva ya fue atendida previamente y no hay comanda activa
if ($estadoReserva === 'Atendida') {
    set_flash_message('warning', 'Esta reserva ya fue atendida y finalizada anteriormente.');
    redirect('/modules/reservas/');
}

// 7. Redirigir al POS integrando automáticamente la reserva, su mesa, su cliente y su preorden
redirect('/modules/pedidos/create.php?reserva_id=' . $id);
