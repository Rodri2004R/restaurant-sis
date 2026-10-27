<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/supabase.php';

require_auth();

$user = get_authenticated_user();
$userRole = $user['rol_nombre'] ?? '';

// Roles con permiso para actualizar estados
if (!in_array($userRole, ['Administrador', 'Mesero', 'Cocinero', 'Cajero'])) {
    set_flash_message('danger', 'Acceso denegado. Tu rol no tiene permisos para modificar estados de comanda.');
    redirect('/modules/pedidos/');
}

$id = clean_input($_GET['id'] ?? '');
$newStatus = clean_input($_GET['status'] ?? '');

$estadosValidos = [
    'Pendiente',
    'En Preparación',
    'Listo',
    'Servido',
    'Pagado',
    'Cancelado'
];

// 1. Validar identificador UUID y nuevo estado
if (
    empty($id) ||
    !is_valid_uuid($id) ||
    empty($newStatus) ||
    !in_array($newStatus, $estadosValidos, true)
) {
    set_flash_message('danger', 'Identificador de pedido o estado solicitado inválido.');
    redirect('/modules/pedidos/');
}

$supabase = new SupabaseClient();

if (!$supabase->isConfigured()) {
    set_flash_message('danger', 'Error de conexión con la base de datos.');
    redirect('/modules/pedidos/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;

// 2. Consultar el estado actual del pedido
$resPed = $supabase->select(
    'pedidos',
    '*',
    ['id' => 'eq.' . $id],
    $authToken
);

if (!$resPed['success'] || empty($resPed['data'])) {
    set_flash_message('danger', 'El pedido especificado no fue encontrado.');
    redirect('/modules/pedidos/');
}

$pedido = $resPed['data'][0];
$currentStatus = $pedido['estado'] ?? 'Pendiente';
$mesaId = $pedido['mesa_id'] ?? null;

// 3. Validación estricta de transiciones y RBAC
$permitido = false;
$motivoRechazo = '';

if ($userRole === 'Administrador') {

    // Administrador tiene control sobre las transiciones
    $permitido = true;

} elseif ($userRole === 'Cocinero') {

    if ($currentStatus === 'Pendiente' && $newStatus === 'En Preparación') {
        $permitido = true;
    } elseif ($currentStatus === 'En Preparación' && $newStatus === 'Listo') {
        $permitido = true;
    } else {
        $motivoRechazo = 'Como Cocinero solo puedes pasar pedidos de "Pendiente" a "En Preparación" o de "En Preparación" a "Listo".';
    }

} elseif ($userRole === 'Mesero') {

    if ($currentStatus === 'Listo' && $newStatus === 'Servido') {
        $permitido = true;
    } else {
        $motivoRechazo = 'Como Mesero solo puedes marcar como "Servido" aquellos pedidos que ya estén en estado "Listo".';
    }

} elseif ($userRole === 'Cajero') {

    if ($currentStatus === 'Servido' && $newStatus === 'Pagado') {
        $permitido = true;
    } else {
        $motivoRechazo = 'Como Cajero solo puedes cobrar y marcar como "Pagado" los pedidos que hayan sido previamente "Servido".';
    }
}

if (!$permitido) {

    $msg = 'Transición no permitida: ' .
        ($motivoRechazo ?: 'No tienes permisos para cambiar de "' . $currentStatus . '" a "' . $newStatus . '".');

    $isJsonReq =
        (isset($_GET['format']) && $_GET['format'] === 'json') ||
        (
            isset($_SERVER['HTTP_ACCEPT']) &&
            stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        );

    if ($isJsonReq) {
        header('Content-Type: application/json', true, 403);
        echo json_encode([
            'success' => false,
            'error' => $msg
        ]);
        exit;
    }

    set_flash_message('danger', $msg);

    if (($_GET['return_to'] ?? '') === 'cocina') {
        redirect('/modules/cocina/');
    }

    redirect('/modules/pedidos/view.php?id=' . $id);
}

// 4. Procesar cambio de estado
//
// Caso especial:
// Servido -> Pagado
//
// En esta transición se utiliza la RPC atómica
// registrar_salida_venta, que:
// - verifica stock
// - descuenta inventario
// - registra Kardex
// - cambia el pedido a Pagado
//
// Todo dentro de una misma transacción de PostgreSQL.

if ($currentStatus === 'Servido' && $newStatus === 'Pagado') {

    $rpcParams = [
        'p_pedido_id' => $id
    ];

    $resRpc = $supabase->rpc(
        'registrar_salida_venta',
        $rpcParams,
        $authToken
    );

    if (!$resRpc['success']) {

        $errMsg = $resRpc['error'] ??
            'No se pudo procesar la salida de inventario por venta.';

        $isJson =
            (isset($_GET['format']) && $_GET['format'] === 'json') ||
            (
                isset($_SERVER['HTTP_ACCEPT']) &&
                stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
            );

        if ($isJson) {
            header('Content-Type: application/json', true, 500);
            echo json_encode([
                'success' => false,
                'error' => $errMsg
            ]);
            exit;
        }

        set_flash_message(
            'danger',
            'No se pudo procesar la venta: ' . $errMsg
        );

        redirect('/modules/pedidos/view.php?id=' . $id);
    }

    // La RPC puede devolver un objeto JSON directamente
    // o una estructura dentro del primer elemento.
    $rpcData = $resRpc['data'];

    if (
        is_array($rpcData) &&
        isset($rpcData[0]) &&
        is_array($rpcData[0])
    ) {
        $rpcData = $rpcData[0];
    }

    // Verificar respuesta de la RPC
    if (
        is_array($rpcData) &&
        isset($rpcData['success']) &&
        $rpcData['success'] !== true
    ) {

        $errMsg = $rpcData['error'] ??
            'La RPC rechazó el procesamiento de la venta.';

        $isJson =
            (isset($_GET['format']) && $_GET['format'] === 'json') ||
            (
                isset($_SERVER['HTTP_ACCEPT']) &&
                stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
            );

        if ($isJson) {
            header('Content-Type: application/json', true, 400);
            echo json_encode([
                'success' => false,
                'error' => $errMsg
            ]);
            exit;
        }

        set_flash_message(
            'danger',
            'No se pudo procesar la venta: ' . $errMsg
        );

        redirect('/modules/pedidos/view.php?id=' . $id);
    }

    // 5. Liberar mesa si corresponde
    if (!empty($mesaId)) {

        $resOtrosPedidos = $supabase->select(
            'pedidos',
            'id',
            [
                'mesa_id' => 'eq.' . $mesaId,
                'id' => 'neq.' . $id,
                'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)'
            ],
            $authToken
        );

        if (
            $resOtrosPedidos['success'] &&
            empty($resOtrosPedidos['data'])
        ) {
            $supabase->update(
                'mesas',
                ['estado' => 'disponible'],
                'id=eq.' . $mesaId,
                $authToken
            );
        }
    }

    $returnTo = clean_input($_GET['return_to'] ?? '');

    $isJson =
        (isset($_GET['format']) && $_GET['format'] === 'json') ||
        (
            isset($_SERVER['HTTP_ACCEPT']) &&
            stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        );

    if ($isJson) {
        header('Content-Type: application/json');

        echo json_encode([
            'success' => true,
            'message' => 'Pedido procesado y marcado como Pagado. Stock actualizado.',
            'pedido_id' => $id,
            'new_status' => 'Pagado',
            'rpc' => $rpcData
        ]);

        exit;
    }

    set_flash_message(
        'success',
        'El pedido #' . substr($id, 0, 8) .
        ' ha sido cobrado y el stock fue actualizado correctamente.'
    );

    if ($returnTo === 'cocina') {
        redirect('/modules/cocina/');
    }

    if ($returnTo === 'pedidos') {
        redirect('/modules/pedidos/');
    }

    redirect('/modules/pedidos/view.php?id=' . $id);
}

// 6. Comportamiento normal para las demás transiciones
$updateRes = $supabase->update(
    'pedidos',
    ['estado' => $newStatus],
    'id=eq.' . $id,
    $authToken
);

if ($updateRes['success']) {

    // 7. Gestión de mesa
    //
    // Si el pedido se marca como Pagado o Cancelado,
    // verificar si quedan otros pedidos activos en esa mesa.

    if (
        in_array($newStatus, ['Pagado', 'Cancelado'], true) &&
        !empty($mesaId)
    ) {

        $resOtrosPedidos = $supabase->select(
            'pedidos',
            'id',
            [
                'mesa_id' => 'eq.' . $mesaId,
                'id' => 'neq.' . $id,
                'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)'
            ],
            $authToken
        );

        if (
            $resOtrosPedidos['success'] &&
            empty($resOtrosPedidos['data'])
        ) {

            $supabase->update(
                'mesas',
                ['estado' => 'disponible'],
                'id=eq.' . $mesaId,
                $authToken
            );
        }
    }

    $returnTo = clean_input($_GET['return_to'] ?? '');

    $isJson =
        (isset($_GET['format']) && $_GET['format'] === 'json') ||
        (
            isset($_SERVER['HTTP_ACCEPT']) &&
            stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        );

    if ($isJson) {

        header('Content-Type: application/json');

        echo json_encode([
            'success' => true,
            'message' => 'Estado actualizado exitosamente a ' . $newStatus,
            'pedido_id' => $id,
            'new_status' => $newStatus
        ]);

        exit;
    }

    set_flash_message(
        'success',
        'El estado del pedido #' . substr($id, 0, 8) .
        ' ha sido actualizado a "' . $newStatus . '".'
    );

} else {

    $errorMsg = $updateRes['error'] ??
        'Error desconocido al actualizar el estado del pedido.';

    $isJson =
        (isset($_GET['format']) && $_GET['format'] === 'json') ||
        (
            isset($_SERVER['HTTP_ACCEPT']) &&
            stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        );

    if ($isJson) {

        header('Content-Type: application/json', true, 400);

        echo json_encode([
            'success' => false,
            'error' => $errorMsg
        ]);

        exit;
    }

    set_flash_message(
        'danger',
        'Error al actualizar el estado del pedido: ' . $errorMsg
    );
}

// 8. Redirección final
$returnTo = clean_input($_GET['return_to'] ?? '');

if ($returnTo === 'cocina') {
    redirect('/modules/cocina/');
}

if ($returnTo === 'pedidos') {
    redirect('/modules/pedidos/');
}

redirect('/modules/pedidos/view.php?id=' . $id);