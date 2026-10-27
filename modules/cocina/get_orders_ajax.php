<?php
/**
 * Restaurant Smart - Endpoint AJAX para Panel de Cocina (KDS)
 * Retorna las comandas activas estructuradas para el sondeo en vivo
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/middleware.php';
require_once __DIR__ . '/../../config/supabase.php';

// Encabezados JSON y no-cache
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sesión no autenticada.']);
    exit;
}

$currentUser = get_authenticated_user();
$userRole = $currentUser['rol_nombre'] ?? '';

if (!in_array($userRole, ['Administrador', 'Cocinero', 'Mesero', 'Cajero', 'Recepcionista'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso denegado al módulo de cocina.']);
    exit;
}

$supabase = new SupabaseClient();
if (!$supabase->isConfigured()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Base de datos no configurada.']);
    exit;
}

$authToken = $_SESSION['user']['access_token'] ?? null;
$filtroEstado = clean_input($_GET['estado'] ?? 'activas');

// Parámetros de consulta
$params = ['order' => 'created_at.asc'];

if ($filtroEstado === 'Pendiente') {
    $params['estado'] = 'eq.Pendiente';
} elseif ($filtroEstado === 'En Preparación' || $filtroEstado === 'En_Preparacion') {
    $params['estado'] = 'eq.En Preparación';
} elseif ($filtroEstado === 'Listo') {
    $params['estado'] = 'eq.Listo';
} elseif ($filtroEstado === 'todas') {
    $params['estado'] = 'in.(Pendiente,En Preparación,Listo)';
} else {
    // Por defecto: comandas activas que requieren atención de cocina
    $params['estado'] = 'in.(Pendiente,En Preparación)';
}

// 1. Consultar pedidos
$resPedidos = $supabase->select('pedidos', 'id,mesa_id,cliente_id,usuario_id,total,estado,observaciones,created_at,mesas(id,numero,ubicacion),clientes(id,nombre,apellido),perfiles(id,nombre,apellido)', $params, $authToken);

$pedidos = [];
if ($resPedidos['success'] && !empty($resPedidos['data'])) {
    $pedidosRaw = $resPedidos['data'];

    // 2. Extraer IDs para consultar detalles de todos los pedidos en lote
    $pedidoIds = array_column($pedidosRaw, 'id');

    $detallesMap = [];
    if (!empty($pedidoIds)) {
        // Consultar detalles de los pedidos activos
        $inIds = implode(',', $pedidoIds);
        $resDet = $supabase->select('detalles_pedido', 'id,pedido_id,producto_id,cantidad,precio_unitario,subtotal,notas,productos(id,nombre,categoria_id,categorias(nombre))', [
            'pedido_id' => 'in.(' . $inIds . ')',
            'order' => 'id.asc'
        ], $authToken);

        if ($resDet['success'] && !empty($resDet['data'])) {
            foreach ($resDet['data'] as $det) {
                $pId = $det['pedido_id'];
                if (!isset($detallesMap[$pId])) {
                    $detallesMap[$pId] = [];
                }
                $detallesMap[$pId][] = [
                    'id' => $det['id'],
                    'producto_id' => $det['producto_id'],
                    'producto_nombre' => $det['productos']['nombre'] ?? 'Platillo',
                    'categoria_nombre' => $det['productos']['categorias']['nombre'] ?? '',
                    'cantidad' => (int)($det['cantidad'] ?? 1),
                    'notas' => trim($det['notas'] ?? '')
                ];
            }
        }
    }

    $now = time();

    // 3. Estructurar respuesta para KDS
    foreach ($pedidosRaw as $p) {
        $pId = $p['id'];
        $createdTimestamp = strtotime($p['created_at']);
        $minutosTranscurridos = max(0, floor(($now - $createdTimestamp) / 60));

        $mesaNumero = $p['mesas']['numero'] ?? '-';
        $mesaUbicacion = $p['mesas']['ubicacion'] ?? 'Salón';
        $clienteNombre = !empty($p['clientes']) ? trim(($p['clientes']['nombre'] ?? '') . ' ' . ($p['clientes']['apellido'] ?? '')) : 'Cliente Salón';
        $meseroNombre = !empty($p['perfiles']) ? trim(($p['perfiles']['nombre'] ?? '') . ' ' . ($p['perfiles']['apellido'] ?? '')) : 'Personal';

        $pedidos[] = [
            'id' => $pId,
            'short_id' => substr($pId, 0, 8),
            'mesa_numero' => $mesaNumero,
            'mesa_ubicacion' => $mesaUbicacion,
            'cliente_nombre' => $clienteNombre,
            'mesero_nombre' => $meseroNombre,
            'estado' => $p['estado'] ?? 'Pendiente',
            'observaciones' => trim($p['observaciones'] ?? ''),
            'hora_creacion' => date('H:i', $createdTimestamp),
            'fecha_creacion' => date('d/m/Y', $createdTimestamp),
            'minutos_transcurridos' => $minutosTranscurridos,
            'detalles' => $detallesMap[$pId] ?? []
        ];
    }
}

// Conteo rápido de métricas de cocina
$resCounts = $supabase->select('pedidos', 'id,estado', ['estado' => 'in.(Pendiente,En Preparación,Listo)'], $authToken);
$counts = ['Pendiente' => 0, 'En Preparación' => 0, 'Listo' => 0];
if ($resCounts['success'] && !empty($resCounts['data'])) {
    foreach ($resCounts['data'] as $c) {
        $st = $c['estado'] ?? '';
        if (isset($counts[$st])) {
            $counts[$st]++;
        }
    }
}

echo json_encode([
    'success' => true,
    'timestamp' => date('H:i:s'),
    'counts' => $counts,
    'total' => count($pedidos),
    'data' => $pedidos
], JSON_UNESCAPED_UNICODE);
