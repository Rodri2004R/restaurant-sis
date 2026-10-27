<?php
$pageTitle = 'Mis Reservas & Preórdenes';
$currentModule = 'mis_reservas';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Cliente', 'Administrador', 'Recepcionista', 'Mesero']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$currentUser = get_authenticated_user();
$isCliente = (($currentUser['rol_nombre'] ?? '') === 'Cliente');
$clienteId = get_current_cliente_id();

// Ejecutar limpieza automática de reservas vencidas (>30 min sin confirmación)
auto_expire_pending_reservas($supabase, $authToken);

// Si es cliente y no tiene cliente_id aún, resolverlo
if ($isCliente && empty($clienteId) && $supabase->isConfigured()) {
    $userEmail = $currentUser['email'] ?? '';
    $resCliFind = $supabase->select('clientes', '*', ['email' => 'eq.' . $userEmail], $authToken);
    if ($resCliFind['success'] && !empty($resCliFind['data'])) {
        $clienteId = $resCliFind['data'][0]['id'];
        $_SESSION['user']['cliente_id'] = $clienteId;
    }
}

// Procesar Acciones del Cliente (Confirmar / Cancelar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = clean_input($_POST['action']);
    $reservaId = clean_input($_POST['reserva_id'] ?? '');

    if (empty($reservaId) || !is_valid_uuid($reservaId)) {
        set_flash_message('danger', 'Identificador de reserva inválido.');
    } else {
        // Validar propiedad estricta de la reserva si es cliente
        $paramsCheck = ['id' => 'eq.' . $reservaId];
        if ($isCliente) {
            $paramsCheck['cliente_id'] = 'eq.' . $clienteId;
        }

        $resCheck = $supabase->select('reservas', '*', $paramsCheck, $authToken);

        if (!$resCheck['success'] || empty($resCheck['data'])) {
            set_flash_message('danger', 'No tienes permisos para gestionar esta reserva o la reserva no existe.');
        } else {
            $resData = $resCheck['data'][0];
            $stActual = $resData['estado'] ?? '';

            if ($action === 'confirmar') {
                if ($stActual === 'Confirmada') {
                    set_flash_message('info', 'Esta reserva ya se encuentra confirmada.');
                } elseif ($stActual === 'Atendida') {
                    set_flash_message('info', 'Esta reserva ya fue atendida.');
                } elseif ($stActual === 'Cancelada') {
                    set_flash_message('warning', 'Esta reserva está cancelada y no puede ser confirmada.');
                } elseif ($stActual === 'Pendiente') {
                    $resTimestamp = strtotime($resData['fecha'] . ' ' . $resData['hora']);
                    $ahoraTimestamp = time();

                    // Si pasaron más de 30 minutos, se cancela automáticamente
                    if ($ahoraTimestamp > ($resTimestamp + 1800)) {
                        $supabase->update('reservas', ['estado' => 'Cancelada'], 'id=eq.' . $reservaId, $authToken);
                        set_flash_message('danger', 'El tiempo de confirmación de 30 minutos ha expirado. La reserva ha sido cancelada automáticamente.');
                    } else {
                        $updRes = $supabase->update('reservas', ['estado' => 'Confirmada'], 'id=eq.' . $reservaId, $authToken);
                        if ($updRes['success']) {
                            set_flash_message('success', '¡Excelente! Tu reserva #' . substr($reservaId, 0, 8) . ' ha sido confirmada con éxito. ¡Te esperamos en mesa!');
                        } else {
                            set_flash_message('danger', 'Error al confirmar la reserva: ' . ($updRes['error'] ?? 'Error desconocido'));
                        }
                    }
                }
            } elseif ($action === 'cancelar') {
                if (in_array($stActual, ['Atendida', 'Cancelada'], true)) {
                    set_flash_message('warning', 'Esta reserva ya se encuentra en estado "' . $stActual . '" y no puede ser cancelada.');
                } else {
                    $updRes = $supabase->update('reservas', ['estado' => 'Cancelada'], 'id=eq.' . $reservaId, $authToken);
                    if ($updRes['success']) {
                        set_flash_message('success', 'Tu reserva #' . substr($reservaId, 0, 8) . ' ha sido cancelada correctamente.');
                    } else {
                        set_flash_message('danger', 'Error al cancelar la reserva: ' . ($updRes['error'] ?? 'Error desconocido'));
                    }
                }
            }
        }
    }
    redirect('/modules/reservas/mis_reservas.php');
}

$reservas = [];
$counts = ['total' => 0, 'Pendiente' => 0, 'Confirmada' => 0, 'Atendida' => 0, 'Cancelada' => 0];
$reservasRequierenConfirmacion = [];
$detallesPorReserva = [];

if ($supabase->isConfigured()) {
    $queryParams = ['order' => 'fecha.desc,hora.desc'];
    if ($isCliente && !empty($clienteId)) {
        $queryParams['cliente_id'] = 'eq.' . $clienteId;
    }

    $res = $supabase->select('reservas', '*,mesas(id,numero,ubicacion,capacidad),clientes(id,nombre,apellido,documento,telefono)', $queryParams, $authToken);
    if ($res['success']) {
        $reservas = $res['data'] ?? [];
        $counts['total'] = count($reservas);
        $ahoraTs = time();

        foreach ($reservas as $rv) {
            $st = $rv['estado'] ?? '';
            if (isset($counts[$st])) {
                $counts[$st]++;
            }

            // Identificar reservas que están en ventana de confirmación (desde la hora hasta hora + 30 min)
            if ($st === 'Pendiente') {
                $resTs = strtotime($rv['fecha'] . ' ' . $rv['hora']);
                if ($ahoraTs >= $resTs && $ahoraTs <= ($resTs + 1800)) {
                    $reservasRequierenConfirmacion[] = $rv;
                }
            }
        }
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

        <!-- Alerta de Notificación de Confirmación en Sala (Ventana de 30 min) -->
        <?php if (!empty($reservasRequierenConfirmacion)): ?>
            <?php foreach ($reservasRequierenConfirmacion as $rConf): ?>
                <?php
                $rConfId = $rConf['id'];
                $rConfShort = substr($rConfId, 0, 8);
                $rConfMesa = $rConf['mesas']['numero'] ?? '-';
                $rConfHora = date('H:i', strtotime($rConf['hora']));
                $limiteTs = strtotime($rConf['fecha'] . ' ' . $rConf['hora']) + 1800;
                $minRestantes = max(1, ceil(($limiteTs - time()) / 60));
                ?>
                <div class="alert alert-warning border-warning shadow-sm mb-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <h6 class="alert-heading fw-bold mb-1">
                            <i class="bi bi-alarm-fill text-warning me-2"></i> ¡Confirmación requerida para tu Reserva #<?= $rConfShort ?> (Mesa #<?= $rConfMesa ?>)!
                        </h6>
                        <p class="mb-0 small text-dark">
                            La hora programada (<strong><?= $rConfHora ?> hrs</strong>) ha llegado. Dispones de <strong><?= $minRestantes ?> minutos</strong> para confirmar tu asistencia o la reserva se cancelará automáticamente.
                        </p>
                    </div>
                    <div class="d-flex gap-2 flex-shrink-0">
                        <form method="POST" action="" class="d-inline">
                            <input type="hidden" name="action" value="confirmar">
                            <input type="hidden" name="reserva_id" value="<?= $rConfId ?>">
                            <button type="submit" class="btn btn-success btn-sm fw-bold">
                                <i class="bi bi-check-circle-fill me-1"></i> Confirmar Llegada
                            </button>
                        </form>
                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('¿Deseas cancelar esta reserva?');">
                            <input type="hidden" name="action" value="cancelar">
                            <input type="hidden" name="reserva_id" value="<?= $rConfId ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-x-circle me-1"></i> Cancelar
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Mis Reservas & Preórdenes</h3>
                <p class="text-muted mb-0 small">Consulta el estado de tus mesas reservadas y platillos preordenados</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/modules/reservas/online.php" class="btn btn-primary-custom">
                    <i class="bi bi-plus-circle me-1"></i> Nueva Reserva Online
                </a>
            </div>
        </div>

        <!-- Tarjetas de Métricas Rápidas -->
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="badge bg-secondary mb-1">Total Registradas</span>
                    <h4 class="fw-bold m-0 text-dark"><?= $counts['total'] ?></h4>
                    <small class="text-muted" style="font-size: 0.72rem;">Historial completo</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="badge bg-warning text-dark mb-1">Pendientes</span>
                    <h4 class="fw-bold m-0 text-dark"><?= $counts['Pendiente'] ?></h4>
                    <small class="text-muted" style="font-size: 0.72rem;">Por confirmar</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="badge bg-success mb-1">Confirmadas</span>
                    <h4 class="fw-bold m-0 text-dark"><?= $counts['Confirmada'] ?></h4>
                    <small class="text-muted" style="font-size: 0.72rem;">Listas para recibirte</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="badge bg-info text-dark mb-1">Atendidas</span>
                    <h4 class="fw-bold m-0 text-dark"><?= $counts['Atendida'] ?></h4>
                    <small class="text-muted" style="font-size: 0.72rem;">Completadas con éxito</small>
                </div>
            </div>
        </div>

        <!-- Tabla de Reservas Compacta y Profesional -->
        <div class="table-custom-wrapper shadow-xs border rounded bg-white overflow-hidden">
            <table class="table table-custom table-hover align-middle mb-0" id="table-mis-reservas" style="font-size: 0.88rem;">
                <thead class="table-light text-secondary border-bottom">
                    <tr>
                        <th style="width: 100px;">Cód.</th>
                        <th style="min-width: 140px;">Fecha & Hora</th>
                        <th style="min-width: 130px;">Mesa & Salón</th>
                        <th style="width: 80px;" class="text-center">Pers.</th>
                        <th style="min-width: 170px;">Preorden de Menú</th>
                        <th style="min-width: 120px;">Estado</th>
                        <th style="min-width: 150px;" class="text-end pe-3">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reservas)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-calendar2-x fs-1 d-block mb-2 text-secondary"></i>
                                No tienes reservas registradas en este momento.
                                <div class="mt-3">
                                    <a href="<?= BASE_URL ?>/modules/reservas/online.php" class="btn btn-sm btn-primary-custom">
                                        <i class="bi bi-calendar-plus me-1"></i> Reservar mi primera mesa
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reservas as $rv): ?>
                            <?php
                            $rId = $rv['id'];
                            $shortId = substr($rId, 0, 8);
                            $fechaFmt = date('d/m/Y', strtotime($rv['fecha']));
                            $horaFmt = date('H:i', strtotime($rv['hora']));
                            $mesaNum = $rv['mesas']['numero'] ?? '-';
                            $mesaUbic = $rv['mesas']['ubicacion'] ?? 'Salón';
                            $mesaCap = $rv['mesas']['capacidad'] ?? '-';
                            $cantP = (int)($rv['cantidad_personas'] ?? 1);
                            $estado = $rv['estado'] ?? 'Pendiente';
                            $hasPreorder = $rv['hasPreorder'] ?? false;
                            $preorderData = $rv['preorderData'] ?? null;
                            $cleanObs = $rv['cleanObs'] ?? '';

                            // Control de fechas y tolerancia
                            $resTs = strtotime($rv['fecha'] . ' ' . $rv['hora']);
                            $ahoraTs = time();
                            $limiteTs = $resTs + 1800;
                            $enVentanaConfirmacion = ($estado === 'Pendiente' && $ahoraTs >= $resTs && $ahoraTs <= $limiteTs);
                            $estaVencida = ($estado === 'Pendiente' && ($rv['fecha'] < date('Y-m-d') || ($rv['fecha'] === date('Y-m-d') && $ahoraTs > $limiteTs)));
                            $puedeCancelar = in_array($estado, ['Pendiente', 'Confirmada'], true) && !$estaVencida;

                            $badgeClass = [
                                'Pendiente' => 'badge-warning text-dark',
                                'Confirmada' => 'bg-success text-white',
                                'Atendida' => 'bg-info text-dark',
                                'Cancelada' => 'bg-secondary text-white'
                            ][$estado] ?? 'bg-secondary text-white';

                            if ($estaVencida) {
                                $badgeClass = 'bg-danger text-white';
                            }
                            ?>
                            <tr class="<?= $estaVencida ? 'opacity-75 bg-light' : '' ?>">
                                <td>
                                    <span class="font-monospace text-dark fw-bold" style="font-size: 0.8rem;">#<?= $shortId ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark">
                                        <i class="bi bi-calendar-event text-primary me-1"></i><?= $fechaFmt ?>
                                    </div>
                                    <small class="text-muted d-block" style="font-size: 0.75rem;">
                                        <i class="bi bi-clock me-1"></i><?= $horaFmt ?> hrs
                                        <?php if ($enVentanaConfirmacion): ?>
                                            <span class="text-danger fw-semibold">(hasta <?= date('H:i', $limiteTs) ?>)</span>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge bg-dark me-1">Mesa #<?= $mesaNum ?></span>
                                    <small class="text-muted d-block text-truncate" style="max-width: 130px; font-size: 0.74rem;">
                                        <?= htmlspecialchars($mesaUbic) ?> (Cap. <?= $mesaCap ?>)
                                    </small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border px-2 py-1">
                                        <i class="bi bi-people me-1"></i><?= $cantP ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($hasPreorder && !empty($preorderData['items'])): ?>
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-success py-0 px-2 rounded-pill d-inline-flex align-items-center gap-1 shadow-xs" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalReserva<?= $shortId ?>" 
                                                title="Ver desglose de preorden"
                                                style="font-size: 0.78rem; height: 26px;">
                                            <span>🍽️</span>
                                            <span class="fw-semibold"><?= count($preorderData['items']) ?> <?= (count($preorderData['items']) === 1) ? 'platillo' : 'platillos' ?> · Bs. <?= number_format((float)($preorderData['total'] ?? 0), 2) ?></span>
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
                                        <span class="badge <?= $badgeClass ?>">
                                            <?= htmlspecialchars($estado) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <?php if ($enVentanaConfirmacion): ?>
                                            <!-- Botón Confirmar Llegada Rápido -->
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="action" value="confirmar">
                                                <input type="hidden" name="reserva_id" value="<?= $rId ?>">
                                                <button type="submit" class="btn btn-sm btn-success py-1 px-2" title="Confirmar Llegada">
                                                    <i class="bi bi-check-lg me-1"></i> Confirmar
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Botón Ver Detalle (Modal) -->
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalReserva<?= $shortId ?>" 
                                                title="Ver Detalle Completo">
                                            <i class="bi bi-eye"></i> Detalle
                                        </button>

                                        <!-- Botón Cancelar Reserva -->
                                        <?php if ($puedeCancelar): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('¿Estás seguro de que deseas cancelar esta reserva?');">
                                                <input type="hidden" name="action" value="cancelar">
                                                <input type="hidden" name="reserva_id" value="<?= $rId ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Cancelar Reserva">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Modales de Detalle de Reserva y Preorden -->
        <?php foreach ($reservas as $rv): ?>
            <?php
            $rId = $rv['id'];
            $shortId = substr($rId, 0, 8);
            $fechaFmt = date('d/m/Y', strtotime($rv['fecha']));
            $horaFmt = date('H:i', strtotime($rv['hora']));
            $mesaNum = $rv['mesas']['numero'] ?? '-';
            $mesaUbic = $rv['mesas']['ubicacion'] ?? 'Salón';
            $mesaCap = $rv['mesas']['capacidad'] ?? '-';
            $cantP = (int)($rv['cantidad_personas'] ?? 1);
            $estado = $rv['estado'] ?? 'Pendiente';
            $hasPreorder = $rv['hasPreorder'] ?? false;
            $preorderData = $rv['preorderData'] ?? null;
            $cleanObs = $rv['cleanObs'] ?? '';

            $resTs = strtotime($rv['fecha'] . ' ' . $rv['hora']);
            $ahoraTs = time();
            $limiteTs = $resTs + 1800;
            $enVentanaConfirmacion = ($estado === 'Pendiente' && $ahoraTs >= $resTs && $ahoraTs <= $limiteTs);
            ?>
            <div class="modal fade" id="modalReserva<?= $shortId ?>" tabindex="-1" aria-labelledby="modalResLabel<?= $shortId ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content shadow border-0">
                        <div class="modal-header bg-light border-bottom py-2 px-3">
                            <h6 class="modal-title fw-bold" id="modalResLabel<?= $shortId ?>" style="color: var(--secondary-color);">
                                <i class="bi bi-bookmark-star-fill text-primary me-2"></i>Detalle de Reserva #<?= $shortId ?>
                            </h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body p-3">
                            <!-- Datos Generales -->
                            <div class="p-2 bg-light rounded mb-3 border d-flex flex-wrap justify-content-between align-items-center gap-2 small">
                                <div><strong class="text-secondary">Fecha y Hora:</strong> <span class="text-dark fw-semibold"><?= $fechaFmt ?> <?= $horaFmt ?> hrs</span></div>
                                <div><strong class="text-secondary">Mesa:</strong> <span class="badge bg-dark">Mesa #<?= $mesaNum ?></span> (<?= htmlspecialchars($mesaUbic) ?> · Cap. <?= $mesaCap ?>)</div>
                                <div><strong class="text-secondary">Comensales:</strong> <span class="badge bg-light text-dark border"><?= $cantP ?> personas</span></div>
                                <div><strong class="text-secondary">Estado:</strong> <span class="badge bg-secondary"><?= htmlspecialchars($estado) ?></span></div>
                            </div>

                            <?php if (!empty($cleanObs)): ?>
                                <div class="alert alert-info py-2 px-3 small mb-3">
                                    <i class="bi bi-info-circle me-1"></i> <strong>Instrucciones / Notas:</strong> <?= htmlspecialchars($cleanObs) ?>
                                </div>
                            <?php endif; ?>

                            <!-- Desglose de Preorden -->
                            <h6 class="fw-bold mb-2 text-dark small"><i class="bi bi-receipt me-1 text-primary"></i>Platillos y Bebidas Preordenadas:</h6>
                            <?php if ($hasPreorder && !empty($preorderData['items'])): ?>
                                <div class="table-responsive rounded border">
                                    <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                        <thead class="table-light text-secondary">
                                            <tr>
                                                <th class="ps-3 py-2">Platillo / Bebida</th>
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
                            <?php else: ?>
                                <p class="text-muted small mb-0 p-3 bg-light rounded border">No se seleccionó comida anticipada para esta reserva. Podrás ordenar directamente en mesa al llegar al restaurante.</p>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer border-top bg-light py-2 px-3">
                            <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cerrar</button>
                            <?php if ($enVentanaConfirmacion): ?>
                                <form method="POST" action="" class="d-inline">
                                    <input type="hidden" name="action" value="confirmar">
                                    <input type="hidden" name="reserva_id" value="<?= $rId ?>">
                                    <button type="submit" class="btn btn-success btn-sm px-3">
                                        <i class="bi bi-check-circle-fill me-1"></i> Confirmar Llegada
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
