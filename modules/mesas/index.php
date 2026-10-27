<?php
$pageTitle = 'Gestión de Mesas';
$currentModule = 'mesas';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Recepcionista', 'Mesero']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;

// Limpieza automática de reservas vencidas (>30 min sin confirmación)
auto_expire_pending_reservas($supabase, $authToken);

$mesas = [];
$pedidosActivosPorMesa = [];
$reservasHoyPorMesa = [];

$userRole = $currentUser['rol_nombre'] ?? '';
$canManageMesas = in_array($userRole, ['Administrador', 'Recepcionista']);
$canTakeOrders = in_array($userRole, ['Administrador', 'Mesero']);

if ($supabase->isConfigured()) {
    // 1. Obtener listado de mesas
    $res = $supabase->select('mesas', '*', ['order' => 'numero.asc'], $authToken);
    if ($res['success']) {
        $mesas = $res['data'];
    }

    // 2. Obtener pedidos activos en salón
    $resPedidos = $supabase->select('pedidos', 'id,mesa_id,estado,total,created_at', [
        'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)',
        'order' => 'created_at.desc'
    ], $authToken);

    if ($resPedidos['success'] && !empty($resPedidos['data'])) {
        foreach ($resPedidos['data'] as $ped) {
            $mId = $ped['mesa_id'] ?? '';
            // Asociar el pedido activo más reciente a la mesa
            if ($mId && !isset($pedidosActivosPorMesa[$mId])) {
                $pedidosActivosPorMesa[$mId] = $ped;
            }
        }
    }

    // 3. Consultar reservas de hoy para las mesas
    $fechaHoy = date('Y-m-d');
    $resReservas = $supabase->select('reservas', 'id,mesa_id,hora,cantidad_personas,estado', [
        'fecha' => 'eq.' . $fechaHoy,
        'estado' => 'in.(Pendiente,Confirmada)',
        'order' => 'hora.asc'
    ], $authToken);

    if ($resReservas['success'] && !empty($resReservas['data'])) {
        foreach ($resReservas['data'] as $rv) {
            $mId = $rv['mesa_id'] ?? '';
            if ($mId && !isset($reservasHoyPorMesa[$mId])) {
                $reservasHoyPorMesa[$mId] = $rv;
            }
        }
    }
}
?>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <?php require_once __DIR__ . '/../../includes/alerts.php'; ?>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Gestión de Mesas & Salón</h3>
                <p class="text-muted mb-0">Monitoreo de distribución, capacidad, comandas activas y ocupación en tiempo real</p>
            </div>
            <div class="d-flex gap-2">
                <?php if ($canTakeOrders): ?>
                    <a href="<?= BASE_URL ?>/modules/pedidos/create.php" class="btn btn-primary-custom">
                        <i class="bi bi-cart-plus-fill me-1"></i> Tomar Pedido (POS)
                    </a>
                <?php endif; ?>
                <?php if ($canManageMesas): ?>
                    <a href="<?= BASE_URL ?>/modules/mesas/create.php" class="btn btn-outline-secondary">
                        <i class="bi bi-plus-circle me-1"></i> Registrar Nueva Mesa
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Vista en Tarjetas Grid / Distribución del Restaurante -->
        <div class="row g-4 mb-4">
            <?php if (empty($mesas)): ?>
                <div class="col-12 text-center py-5 text-muted bg-white rounded shadow-sm">
                    <i class="bi bi-table fs-1 d-block mb-2 text-secondary"></i>
                    No hay mesas configuradas actualmente en el sistema.
                </div>
            <?php else: ?>
                <?php foreach ($mesas as $mesa): ?>
                    <?php
                    $mesaId = $mesa['id'];
                    $pedidoActivo = $pedidosActivosPorMesa[$mesaId] ?? null;
                    $reservaHoy = $reservasHoyPorMesa[$mesaId] ?? null;

                    // Determinar estado visual integrado con pedidos activos
                    $estadoEfectivo = $mesa['estado'];
                    if ($pedidoActivo) {
                        $estadoEfectivo = 'ocupada';
                    } elseif ($reservaHoy && $estadoEfectivo === 'disponible') {
                        $estadoEfectivo = 'reservada';
                    }

                    $statusClass = [
                        'disponible' => 'border-success text-success bg-success-subtle',
                        'ocupada' => 'border-danger text-danger bg-danger-subtle',
                        'reservada' => 'border-warning text-warning bg-warning-subtle',
                        'mantenimiento' => 'border-secondary text-secondary bg-secondary-subtle'
                    ][$estadoEfectivo] ?? 'border-light';

                    $orderStateBadgeClass = [
                        'Pendiente' => 'bg-warning text-dark',
                        'En Preparación' => 'bg-primary text-white',
                        'Listo' => 'bg-info text-dark',
                        'Servido' => 'bg-success text-white'
                    ][$pedidoActivo['estado'] ?? ''] ?? 'bg-secondary text-white';
                    ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="card-custom p-3 text-center position-relative h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge bg-dark text-white">Mesa #<?= $mesa['numero'] ?></span>
                                    <span class="badge border <?= $statusClass ?> text-uppercase" style="font-size: 0.7rem;">
                                        <?= htmlspecialchars($estadoEfectivo) ?>
                                    </span>
                                </div>
                                
                                <div class="my-3">
                                    <i class="bi <?= $pedidoActivo ? 'bi-receipt-cutoff text-danger' : ($reservaHoy ? 'bi-calendar-check text-warning' : 'bi-ui-radios-grid text-primary') ?> display-5 d-block mb-2"></i>
                                    <h5 class="fw-bold mb-1">Capacidad: <?= $mesa['capacidad'] ?> pers.</h5>
                                    <small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($mesa['ubicacion'] ?? 'Salón') ?></small>
                                </div>

                                <!-- Información de Pedido Activo si existe -->
                                <?php if ($pedidoActivo): ?>
                                    <div class="p-2 border rounded bg-white shadow-xs mb-2 text-start">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="small fw-bold text-dark">Comanda #<?= substr($pedidoActivo['id'], 0, 8) ?></span>
                                            <span class="badge <?= $orderStateBadgeClass ?>" style="font-size: 0.65rem;"><?= htmlspecialchars($pedidoActivo['estado']) ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted"><?= date('H:i', strtotime($pedidoActivo['created_at'])) ?> hrs</small>
                                            <strong class="text-success small">Bs. <?= number_format((float)$pedidoActivo['total'], 2) ?></strong>
                                        </div>
                                    </div>
                                <?php elseif ($reservaHoy): ?>
                                    <div class="p-2 border rounded bg-warning-subtle mb-2 text-start small">
                                        <i class="bi bi-clock me-1 text-warning"></i> Reserva hoy: <strong><?= date('H:i', strtotime($reservaHoy['hora'])) ?></strong> (<?= $reservaHoy['cantidad_personas'] ?> pers.)
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Botones de Acción -->
                            <div class="border-top pt-2 mt-2">
                                <?php if ($pedidoActivo): ?>
                                    <a href="<?= BASE_URL ?>/modules/pedidos/view.php?id=<?= $pedidoActivo['id'] ?>" class="btn btn-sm btn-outline-primary w-100 mb-1">
                                        <i class="bi bi-receipt me-1"></i> Ver Comanda Activa
                                    </a>
                                <?php elseif ($canTakeOrders && $estadoEfectivo === 'disponible'): ?>
                                    <a href="<?= BASE_URL ?>/modules/pedidos/create.php?mesa_id=<?= $mesa['id'] ?>" class="btn btn-sm btn-outline-success w-100 mb-1">
                                        <i class="bi bi-cart-plus me-1"></i> Tomar Pedido
                                    </a>
                                <?php endif; ?>

                                <?php if ($canManageMesas): ?>
                                    <a href="<?= BASE_URL ?>/modules/mesas/edit.php?id=<?= $mesa['id'] ?>" class="btn btn-sm btn-link text-decoration-none p-0 text-secondary" style="font-size: 0.8rem;">
                                        <i class="bi bi-pencil me-1"></i> Editar Configuración
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
