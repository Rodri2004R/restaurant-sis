<?php
$pageTitle = 'Gestión de Reservas';
$currentModule = 'reservas';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Recepcionista', 'Mesero']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$filtroFecha = clean_input($_GET['fecha'] ?? '');
$fechaHoy = date('Y-m-d');
$ahoraTimestamp = time();
$reservas = [];
$detallesPorReserva = [];
$pedidosActivosPorMesa = [];

// 1. Ejecutar auto-cancelación de reservas pendientes vencidas (+30 min o fechas pasadas)
if ($supabase->isConfigured()) {
    auto_expire_pending_reservas($supabase, $authToken);
}

// 2. Acciones rápidas de staff (Confirmar reserva / Cancelar reserva)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = clean_input($_POST['action']);
    $reservaId = clean_input($_POST['reserva_id'] ?? '');

    if (!empty($reservaId) && is_valid_uuid($reservaId) && $supabase->isConfigured()) {
        if ($action === 'confirmar') {
            $supabase->update('reservas', ['estado' => 'Confirmada'], 'id=eq.' . $reservaId, $authToken);
            set_flash_message('success', 'Reserva #' . substr($reservaId, 0, 8) . ' confirmada exitosamente.');
        } elseif ($action === 'cancelar') {
            $supabase->update('reservas', ['estado' => 'Cancelada'], 'id=eq.' . $reservaId, $authToken);
            set_flash_message('success', 'Reserva #' . substr($reservaId, 0, 8) . ' ha sido cancelada.');
        }
        redirect('/modules/reservas/' . ($filtroFecha === 'hoy' ? '?fecha=hoy' : ''));
    }
}

if ($supabase->isConfigured()) {
    $queryParams = [
        'order' => 'fecha.desc,hora.desc'
    ];
    if ($filtroFecha === 'hoy') {
        $queryParams['fecha'] = 'eq.' . $fechaHoy;
    }

    $res = $supabase->select('reservas', '*,clientes(nombre,apellido,documento,telefono),mesas(numero,capacidad,ubicacion)', $queryParams, $authToken);
    if ($res['success']) {
        $reservas = $res['data'] ?? [];
    }

    // Consultar tabla detalles_reserva como FUENTE PRINCIPAL de preórdenes
    $resDetalles = $supabase->select('detalles_reserva', '*,productos(id,nombre,precio)', [], $authToken);
    if ($resDetalles['success'] && !empty($resDetalles['data'])) {
        foreach ($resDetalles['data'] as $detRow) {
            $rvId = $detRow['reserva_id'] ?? null;
            if ($rvId) {
                $detallesPorReserva[$rvId][] = $detRow;
            }
        }
    }

    // Consultar comandas activas para vincular reservas atendidas
    $resPedidosActivos = $supabase->select('pedidos', 'id,mesa_id,estado', [
        'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)'
    ], $authToken);
    if ($resPedidosActivos['success'] && !empty($resPedidosActivos['data'])) {
        foreach ($resPedidosActivos['data'] as $pAct) {
            $mId = $pAct['mesa_id'] ?? '';
            if ($mId && !isset($pedidosActivosPorMesa[$mId])) {
                $pedidosActivosPorMesa[$mId] = $pAct;
            }
        }
    }
}

// Pre-procesar preórdenes para cada reserva
foreach ($reservas as &$resItem) {
    $rId = $resItem['id'];
    $obs = $resItem['observaciones'] ?? '';
    $hasPreorder = false;
    $preorderData = null;

    // 1. FUENTE PRINCIPAL: Consultar detalles_reserva
    if (!empty($detallesPorReserva[$rId])) {
        $itemsDet = [];
        $totalCalculado = 0.0;
        foreach ($detallesPorReserva[$rId] as $rowDet) {
            $cant = max(1, (int)($rowDet['cantidad'] ?? 1));
            $precioUnit = (float)($rowDet['productos']['precio'] ?? $rowDet['precio_unitario'] ?? 0);
            $subtotal = round($cant * $precioUnit, 2);
            $totalCalculado += $subtotal;
            $itemsDet[] = [
                'producto_id' => $rowDet['producto_id'],
                'nombre' => $rowDet['productos']['nombre'] ?? 'Platillo',
                'cantidad' => $cant,
                'precio_unitario' => $precioUnit,
                'subtotal' => $subtotal,
                'notas' => $rowDet['notas'] ?? ''
            ];
        }
        if (!empty($itemsDet)) {
            $hasPreorder = true;
            $preorderData = [
                'total' => round($totalCalculado, 2),
                'items_count' => count($itemsDet),
                'items' => $itemsDet
            ];
        }
    }

    // 2. FUENTE DE RESPALDO: Parsear JSON [PREORDEN:...] en observaciones
    if (!$hasPreorder && preg_match('/\[PREORDEN:(.*?)\]$/s', $obs, $matches)) {
        $decodedJsonStr = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $preorderJson = json_decode($decodedJsonStr, true);
        if ($preorderJson && is_array($preorderJson) && !empty($preorderJson['items'])) {
            $hasPreorder = true;
            $preorderData = $preorderJson;
        }
    }

    $resItem['hasPreorder'] = $hasPreorder;
    $resItem['preorderData'] = $preorderData;
    $resItem['cleanObs'] = trim(preg_replace('/\[PREORDEN:(.*?)\]$/s', '', $obs));
}
unset($resItem);
?>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <?php require_once __DIR__ . '/../../includes/alerts.php'; ?>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Gestión de Reservas</h3>
                <p class="text-muted mb-0 small">Control de reservas, confirmación de asistencia (30 min) y atención de comensales</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/modules/reservas/create.php" class="btn btn-primary-custom">
                    <i class="bi bi-calendar-plus me-2"></i> Registrar Nueva Reserva
                </a>
            </div>
        </div>

        <!-- Buscador y Filtros Rápidos (US-008) -->
        <div class="card-custom p-3 mb-3">
            <div class="row align-items-center g-2">
                <div class="col-md-5">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Buscar por cliente, mesa, fecha o estado..." data-table-search="table-reservas">
                    </div>
                </div>
                <div class="col-md-4 text-md-center">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Filtro rápido de reservas">
                        <a href="<?= BASE_URL ?>/modules/reservas/" class="btn <?= ($filtroFecha !== 'hoy') ? 'btn-primary' : 'btn-outline-secondary' ?>">
                            <i class="bi bi-calendar3 me-1"></i> Todas
                        </a>
                        <a href="<?= BASE_URL ?>/modules/reservas/?fecha=hoy" class="btn <?= ($filtroFecha === 'hoy') ? 'btn-primary' : 'btn-outline-secondary' ?>">
                            <i class="bi bi-calendar-check-fill me-1"></i> Reservas de hoy
                            <span class="badge bg-light text-dark ms-1"><?= date('d/m/Y') ?></span>
                        </a>
                    </div>
                </div>
                <div class="col-md-3 text-md-end">
                    <span class="text-muted small">Total: <strong><?= count($reservas) ?></strong> reservas</span>
                </div>
            </div>
        </div>

        <!-- Tabla de Reservas Compacta y Profesional -->
        <div class="table-custom-wrapper shadow-xs border rounded bg-white overflow-hidden">
            <table class="table table-custom table-hover align-middle mb-0" id="table-reservas" style="font-size: 0.88rem;">
                <thead class="table-light text-secondary border-bottom">
                    <tr>
                        <th style="width: 100px;">Cód.</th>
                        <th style="min-width: 170px;">Cliente</th>
                        <th style="min-width: 130px;">Mesa & Salón</th>
                        <th style="min-width: 140px;">Fecha & Hora</th>
                        <th style="width: 80px;" class="text-center">Pers.</th>
                        <th style="min-width: 150px;">Preorden</th>
                        <th style="min-width: 120px;">Estado</th>
                        <th style="min-width: 160px;" class="text-end pe-3">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reservas)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-calendar-x fs-1 d-block mb-2 text-secondary"></i>
                                <?= ($filtroFecha === 'hoy') ? 'No hay reservas registradas para el día de hoy (' . date('d/m/Y') . ').' : 'No hay reservas registradas en el sistema.' ?>
                                <?php if ($filtroFecha === 'hoy'): ?>
                                    <div class="mt-2">
                                        <a href="<?= BASE_URL ?>/modules/reservas/" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-calendar3 me-1"></i> Ver todas las reservas
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reservas as $res): ?>
                            <?php
                            $rId = $res['id'];
                            $shortId = substr($rId, 0, 8);
                            $cliNombre = trim(($res['clientes']['nombre'] ?? 'Cliente') . ' ' . ($res['clientes']['apellido'] ?? ''));
                            $cliTel = $res['clientes']['telefono'] ?? '';
                            $mesaNum = $res['mesas']['numero'] ?? '-';
                            $mesaCap = $res['mesas']['capacidad'] ?? '-';
                            $mesaUbic = $res['mesas']['ubicacion'] ?? 'Salón';
                            $estado = $res['estado'] ?? 'Pendiente';
                            $hasPreorder = $res['hasPreorder'] ?? false;
                            $preorderData = $res['preorderData'] ?? null;
                            
                            // Control de Fecha, Hora y Reglas de Tolerancia de 30 minutos
                            $fechaRes = $res['fecha'];
                            $horaRes = !empty($res['hora']) ? date('H:i', strtotime($res['hora'])) : '00:00';
                            $resTimestamp = strtotime($fechaRes . ' ' . $horaRes);
                            $tiempoLimite = $resTimestamp + (30 * 60); // 30 minutos de tolerancia
                            $horaLimiteFmt = date('H:i', $tiempoLimite);

                            $esHoy = ($fechaRes === $fechaHoy);
                            $esPasada = ($fechaRes < $fechaHoy);
                            $esFutura = ($fechaRes > $fechaHoy);

                            // Estados de negocio
                            $isPendiente = ($estado === 'Pendiente');
                            $isConfirmada = ($estado === 'Confirmada');
                            $isAtendida = ($estado === 'Atendida');
                            $isCancelada = ($estado === 'Cancelada');

                            // Ventana activa de confirmación (hoy entre la hora programada y +30 min)
                            $enVentanaConfirmacion = ($isPendiente && $esHoy && ($ahoraTimestamp >= $resTimestamp) && ($ahoraTimestamp <= $tiempoLimite));
                            $estaVencida = ($isPendiente && ($esPasada || ($esHoy && $ahoraTimestamp > $tiempoLimite)));
                            $esperandoHora = ($isPendiente && $esHoy && ($ahoraTimestamp < $resTimestamp));
                            $esperandoFecha = ($isPendiente && $esFutura);

                            // Capacidad de atención
                            $puedeAtender = ($isConfirmada && $esHoy && ($ahoraTimestamp >= $resTimestamp)) || $enVentanaConfirmacion;
                            $puedeEditar = (($isPendiente || $isConfirmada) && !$esPasada && !$estaVencida);
                            $puedeCancelar = (($isPendiente || $isConfirmada) && !$isAtendida && !$isCancelada);

                            $statusBadgeClass = [
                                'Pendiente' => 'badge-warning text-dark',
                                'Confirmada' => 'bg-success text-white',
                                'Atendida' => 'bg-info text-dark',
                                'Cancelada' => 'bg-secondary text-white'
                            ][$estado] ?? 'bg-secondary text-white';

                            if ($estaVencida) {
                                $statusBadgeClass = 'bg-danger text-white';
                            }
                            ?>
                            <tr class="<?= $estaVencida ? 'opacity-75 bg-light' : '' ?>">
                                <td>
                                    <span class="font-monospace text-dark fw-bold" style="font-size: 0.8rem;">#<?= $shortId ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($cliNombre) ?>">
                                        <?= htmlspecialchars($cliNombre) ?>
                                    </div>
                                    <?php if (!empty($cliTel)): ?>
                                        <small class="text-muted d-block" style="font-size: 0.75rem;">
                                            <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($cliTel) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-dark me-1">Mesa #<?= $mesaNum ?></span>
                                    <small class="text-muted d-block text-truncate" style="max-width: 140px; font-size: 0.74rem;">
                                        <?= htmlspecialchars($mesaUbic) ?> (Cap. <?= $mesaCap ?>)
                                    </small>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark">
                                        <i class="bi bi-calendar-event text-primary me-1"></i><?= date('d/m/Y', strtotime($fechaRes)) ?>
                                    </div>
                                    <small class="text-muted d-block" style="font-size: 0.75rem;">
                                        <i class="bi bi-clock me-1"></i><?= $horaRes ?> hrs
                                        <?php if ($enVentanaConfirmacion): ?>
                                            <span class="text-danger fw-semibold">(hasta <?= $horaLimiteFmt ?>)</span>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border px-2 py-1">
                                        <i class="bi bi-people me-1"></i><?= $res['cantidad_personas'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($hasPreorder && !empty($preorderData['items'])): ?>
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-success py-0 px-2 rounded-pill d-inline-flex align-items-center gap-1 shadow-xs" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalStaffPreorder<?= $shortId ?>" 
                                                title="Ver desglose de preorden"
                                                style="font-size: 0.78rem; height: 26px;">
                                            <span>🍽️</span>
                                            <span class="fw-semibold"><?= count($preorderData['items']) ?> <?= (count($preorderData['items']) === 1) ? 'ítem' : 'ítems' ?> · Bs. <?= number_format((float)($preorderData['total'] ?? 0), 2) ?></span>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small" style="font-size: 0.78rem;">
                                            <i class="bi bi-dash text-secondary"></i> Sin preorden
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($estaVencida): ?>
                                        <span class="badge bg-danger text-white" title="Superó los 30 min sin confirmación">
                                            <i class="bi bi-hourglass-bottom me-1"></i>Vencida
                                        </span>
                                    <?php elseif ($enVentanaConfirmacion): ?>
                                        <span class="badge bg-warning text-dark border border-warning" title="En período de confirmación de 30 min">
                                            <i class="bi bi-bell-fill me-1"></i>Por confirmar
                                        </span>
                                    <?php else: ?>
                                        <span class="badge <?= $statusBadgeClass ?>">
                                            <?= htmlspecialchars($estado) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <!-- Acción: Atender (Enviar al POS) -->
                                        <?php if ($puedeAtender && !$estaVencida): ?>
                                            <a href="<?= BASE_URL ?>/modules/reservas/convert_to_order.php?id=<?= $rId ?>" 
                                               class="btn btn-sm btn-success py-1 px-2" 
                                               title="El comensal llegó: Abrir comanda en POS">
                                                <i class="bi bi-fire me-1"></i> Atender
                                            </a>
                                        <?php elseif ($enVentanaConfirmacion): ?>
                                            <!-- Botón rápido confirmar llegada -->
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="action" value="confirmar">
                                                <input type="hidden" name="reserva_id" value="<?= $rId ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success py-1 px-2" title="Confirmar asistencia del cliente">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        <?php elseif ($esperandoHora): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" disabled title="Disponible a la hora programada (<?= $horaRes ?>)">
                                                <i class="bi bi-clock me-1"></i> <?= $horaRes ?>
                                            </button>
                                        <?php elseif ($esperandoFecha): ?>
                                            <span class="badge bg-light text-secondary border py-1 px-2" title="Programada para <?= date('d/m/Y', strtotime($fechaRes)) ?>">
                                                <i class="bi bi-calendar-check me-1"></i> Programada
                                            </span>
                                        <?php elseif ($isAtendida): ?>
                                            <?php 
                                            $mesaIdRv = $res['mesa_id'] ?? '';
                                            $pedActivoRv = (!empty($mesaIdRv) && isset($pedidosActivosPorMesa[$mesaIdRv])) ? $pedidosActivosPorMesa[$mesaIdRv] : null;
                                            ?>
                                            <?php if ($pedActivoRv): ?>
                                                <a href="<?= BASE_URL ?>/modules/pedidos/view.php?id=<?= $pedActivoRv['id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2" title="Ver Comanda Activa en Salón/Cocina">
                                                    <i class="bi bi-receipt me-1"></i> Comanda
                                                </a>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border py-1 px-2" title="Atención completada">
                                                    <i class="bi bi-check2-all me-1"></i> Atendida
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <!-- Botón Editar -->
                                        <?php if ($puedeEditar): ?>
                                            <a href="<?= BASE_URL ?>/modules/reservas/edit.php?id=<?= $res['id'] ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Editar Reserva">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>

                                        <!-- Botón Cancelar -->
                                        <?php if ($puedeCancelar && !$estaVencida): ?>
                                            <a href="<?= BASE_URL ?>/modules/reservas/cancel.php?id=<?= $res['id'] ?>" 
                                               class="btn btn-sm btn-outline-danger py-1 px-2" 
                                               data-confirm="¿Desea cancelar esta reserva?" 
                                               title="Cancelar Reserva">
                                                <i class="bi bi-x-lg"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Modales de Detalle de Preorden -->
        <?php foreach ($reservas as $res): ?>
            <?php
            if (empty($res['hasPreorder']) || empty($res['preorderData']['items'])) continue;
            
            $rId = $res['id'];
            $shortId = substr($rId, 0, 8);
            $preorderData = $res['preorderData'];
            $cliNombre = trim(($res['clientes']['nombre'] ?? 'Cliente') . ' ' . ($res['clientes']['apellido'] ?? ''));
            $mesaNum = $res['mesas']['numero'] ?? '-';
            $fechaRes = $res['fecha'];
            $horaRes = !empty($res['hora']) ? date('H:i', strtotime($res['hora'])) : '00:00';
            $estado = $res['estado'] ?? 'Pendiente';

            $resTimestamp = strtotime($fechaRes . ' ' . $horaRes);
            $tiempoLimite = $resTimestamp + (30 * 60);
            $esHoy = ($fechaRes === $fechaHoy);
            $isPendiente = ($estado === 'Pendiente');
            $isConfirmada = ($estado === 'Confirmada');
            $enVentanaConfirmacion = ($isPendiente && $esHoy && ($ahoraTimestamp >= $resTimestamp) && ($ahoraTimestamp <= $tiempoLimite));
            $estaVencida = ($isPendiente && ($fechaRes < $fechaHoy || ($esHoy && $ahoraTimestamp > $tiempoLimite)));
            $puedeAtender = (($isConfirmada && $esHoy && ($ahoraTimestamp >= $resTimestamp)) || $enVentanaConfirmacion) && !$estaVencida;
            ?>
            <div class="modal fade" id="modalStaffPreorder<?= $shortId ?>" tabindex="-1" aria-labelledby="modalPreorderLabel<?= $shortId ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content shadow border-0">
                        <div class="modal-header bg-light border-bottom py-2 px-3">
                            <h6 class="modal-title fw-bold" id="modalPreorderLabel<?= $shortId ?>" style="color: var(--secondary-color);">
                                <i class="bi bi-receipt text-primary me-2"></i>Detalle de Preorden — Reserva #<?= $shortId ?>
                            </h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body p-3">
                            <!-- Resumen rápido -->
                            <div class="p-2 bg-light rounded mb-3 border d-flex flex-wrap justify-content-between align-items-center gap-2 small">
                                <div><strong class="text-secondary">Cliente:</strong> <span class="fw-semibold text-dark"><?= htmlspecialchars($cliNombre) ?></span></div>
                                <div><strong class="text-secondary">Mesa:</strong> <span class="badge bg-dark">#<?= $mesaNum ?></span></div>
                                <div><strong class="text-secondary">Fecha y Hora:</strong> <span class="text-dark"><?= date('d/m/Y', strtotime($fechaRes)) ?> <?= $horaRes ?></span></div>
                                <div><strong class="text-secondary">Estado:</strong> <span class="badge bg-secondary"><?= htmlspecialchars($estado) ?></span></div>
                            </div>

                            <!-- Tabla de productos -->
                            <div class="table-responsive rounded border">
                                <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                    <thead class="table-light text-secondary">
                                        <tr>
                                            <th class="ps-3 py-2">Producto</th>
                                            <th class="text-center py-2" style="width: 80px;">Cant.</th>
                                            <th class="text-end py-2" style="width: 120px;">P. Unitario</th>
                                            <th class="text-end py-2" style="width: 120px;">Subtotal</th>
                                            <th class="pe-3 py-2">Notas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($preorderData['items'] as $itemP): ?>
                                            <?php
                                            $cantItem = (int)($itemP['cantidad'] ?? 1);
                                            $pUnitItem = (float)($itemP['precio_unitario'] ?? 0);
                                            $subtotItem = (float)($itemP['subtotal'] ?? ($cantItem * $pUnitItem));
                                            $notaItem = trim($itemP['notas'] ?? '');
                                            ?>
                                            <tr>
                                                <td class="ps-3 py-2 fw-semibold text-dark"><?= htmlspecialchars($itemP['nombre'] ?? 'Platillo') ?></td>
                                                <td class="text-center py-2 fw-bold text-dark"><?= $cantItem ?></td>
                                                <td class="text-end py-2 text-muted">Bs. <?= number_format($pUnitItem, 2) ?></td>
                                                <td class="text-end py-2 fw-bold text-dark">Bs. <?= number_format($subtotItem, 2) ?></td>
                                                <td class="pe-3 py-2 small text-muted">
                                                    <?= !empty($notaItem) ? '<span class="badge bg-warning-subtle text-dark border"><i class="bi bi-chat-left-dots me-1"></i>' . htmlspecialchars($notaItem) . '</span>' : '<em>Sin notas</em>' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light border-top">
                                        <tr>
                                            <td colspan="3" class="text-end fw-bold py-2 text-secondary">TOTAL PREORDEN:</td>
                                            <td class="text-end fw-bold text-success fs-6 py-2">Bs. <?= number_format((float)($preorderData['total'] ?? 0), 2) ?></td>
                                            <td class="pe-3 py-2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer border-top bg-light py-2 px-3">
                            <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cerrar</button>
                            <?php if ($puedeAtender): ?>
                                <a href="<?= BASE_URL ?>/modules/reservas/convert_to_order.php?id=<?= $rId ?>" 
                                   class="btn btn-success btn-sm px-3">
                                    <i class="bi bi-fire me-1"></i> Atender (POS / Cocina)
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
