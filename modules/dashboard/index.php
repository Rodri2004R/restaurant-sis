<?php
$pageTitle = 'Dashboard General';
$currentModule = 'dashboard';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Recepcionista', 'Mesero', 'Cocinero', 'Cajero']);
require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;

$totalClientes = 0;
$totalMesas = 0;
$mesasDisponibles = 0;
$mesasOcupadas = 0;
$totalReservasHoy = 0;
$totalProductosActivos = 0;
$totalPedidosPendientes = 0;
$totalPedidosEnCocina = 0;
$proximasReservas = [];
$pedidosActivos = [];

$userRole = $currentUser['rol_nombre'] ?? '';
$isAdmin = ($userRole === 'Administrador');
$isMesero = ($userRole === 'Mesero');

if ($supabase->isConfigured()) {
    // 1. Contador Clientes
    $resCli = $supabase->select('clientes', 'id', [], $authToken);
    if ($resCli['success']) $totalClientes = count($resCli['data']);

    // 2. Contador Mesas & Disponibilidad
    $resMesas = $supabase->select('mesas', 'id,estado', [], $authToken);
    if ($resMesas['success']) {
        $totalMesas = count($resMesas['data']);
        foreach ($resMesas['data'] as $m) {
            if ($m['estado'] === 'disponible') $mesasDisponibles++;
            elseif ($m['estado'] === 'ocupada') $mesasOcupadas++;
        }
    }

    // 3. Contador Reservas Hoy
    $fechaHoy = date('Y-m-d');
    $resResHoy = $supabase->select('reservas', 'id', ['fecha' => 'eq.' . $fechaHoy, 'estado' => 'in.(Pendiente,Confirmada)'], $authToken);
    if ($resResHoy['success']) $totalReservasHoy = count($resResHoy['data']);

    // 4. Contador Productos Activos (Sprint 2)
    $resProd = $supabase->select('productos', 'id', ['estado' => 'eq.true'], $authToken);
    if ($resProd['success']) $totalProductosActivos = count($resProd['data']);

    // 5. Contadores de Pedidos Activos (Sprint 2)
    $resPedPend = $supabase->select('pedidos', 'id', ['estado' => 'eq.Pendiente'], $authToken);
    if ($resPedPend['success']) $totalPedidosPendientes = count($resPedPend['data']);

    $resPedCocina = $supabase->select('pedidos', 'id', ['estado' => 'eq.En Preparación'], $authToken);
    if ($resPedCocina['success']) $totalPedidosEnCocina = count($resPedCocina['data']);

    // 6. Próximas reservas
    $resProx = $supabase->select('reservas', '*,clientes(nombre,apellido),mesas(numero)', [
        'order' => 'fecha.asc,hora.asc',
        'limit' => '5'
    ], $authToken);
    if ($resProx['success']) $proximasReservas = $resProx['data'];

    // 7. Pedidos activos recientes para visualización rápida
    $resPedAct = $supabase->select('pedidos', '*,mesas(numero,ubicacion),clientes(nombre,apellido)', [
        'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)',
        'order' => 'created_at.desc',
        'limit' => '5'
    ], $authToken);
    if ($resPedAct['success']) $pedidosActivos = $resPedAct['data'];
}
?>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <?php require_once __DIR__ . '/../../includes/alerts.php'; ?>

        <!-- Saludo y Botones de Acción Rápida -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">
                    Bienvenido, <?= htmlspecialchars(($currentUser['nombre'] ?? 'Usuario') . ' ' . ($currentUser['apellido'] ?? '')) ?> 👋
                </h3>
                <p class="text-muted mb-0">Panel operativo central — Control de salón, cocina, comandas y menú</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($isAdmin || $isMesero): ?>
                    <a href="<?= BASE_URL ?>/modules/pedidos/create.php" class="btn btn-primary-custom">
                        <i class="bi bi-cart-plus-fill me-1"></i> Nuevo Pedido (POS)
                    </a>
                <?php endif; ?>
                <?php if (in_array($userRole, ['Administrador', 'Recepcionista', 'Mesero'])): ?>
                    <a href="<?= BASE_URL ?>/modules/reservas/create.php" class="btn btn-outline-secondary">
                        <i class="bi bi-calendar-plus me-1"></i> Nueva Reserva
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tarjetas de Métricas en Tiempo Real (Sprints 1 y 2) -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-custom stat-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">Pendientes Cocina</div>
                            <div class="stat-value"><?= $totalPedidosPendientes ?></div>
                        </div>
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-custom stat-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">En Preparación</div>
                            <div class="stat-value"><?= $totalPedidosEnCocina ?></div>
                        </div>
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-fire"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-custom stat-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">Mesas Libres</div>
                            <div class="stat-value"><?= $mesasDisponibles ?> <small class="fs-6 text-muted">/ <?= $totalMesas ?></small></div>
                        </div>
                        <div class="stat-icon stat-success">
                            <i class="bi bi-shop"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-custom stat-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">Platillos Activos</div>
                            <div class="stat-value"><?= $totalProductosActivos ?></div>
                        </div>
                        <div class="stat-icon stat-info">
                            <i class="bi bi-journal-check"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Secciones Operativas -->
        <div class="row g-4">
            <!-- Comandas Activas & Reservas -->
            <div class="col-lg-8">
                <!-- Comandas Activas en Salón -->
                <div class="card-custom p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold m-0" style="color: var(--secondary-color);">
                            <i class="bi bi-clock-history me-2 text-primary"></i> Comandas Activas en Salón
                        </h5>
                        <a href="<?= BASE_URL ?>/modules/pedidos/" class="btn btn-sm btn-link text-decoration-none">Ver todas <i class="bi bi-arrow-right"></i></a>
                    </div>

                    <?php if (empty($pedidosActivos)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-check2-all fs-2 d-block mb-1 text-success"></i>
                            No hay pedidos activos pendientes en este momento. Todas las órdenes están despachadas.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Comanda</th>
                                        <th>Mesa</th>
                                        <th>Cliente</th>
                                        <th>Total</th>
                                        <th>Estado</th>
                                        <th class="text-end">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pedidosActivos as $p): ?>
                                        <?php
                                        $badgeSt = [
                                            'Pendiente' => 'bg-warning text-dark',
                                            'En Preparación' => 'bg-primary text-white',
                                            'Listo' => 'bg-info text-dark',
                                            'Servido' => 'bg-success text-white'
                                        ][$p['estado']] ?? 'bg-secondary';
                                        ?>
                                        <tr>
                                            <td><strong class="font-monospace">#<?= substr($p['id'], 0, 8) ?></strong></td>
                                            <td><span class="badge bg-dark">Mesa #<?= $p['mesas']['numero'] ?? '-' ?></span></td>
                                            <td><?= htmlspecialchars(!empty($p['clientes']) ? ($p['clientes']['nombre'] . ' ' . $p['clientes']['apellido']) : 'Cliente Ocasional') ?></td>
                                            <td><strong class="text-success">Bs. <?= number_format((float)$p['total'], 2) ?></strong></td>
                                            <td><span class="badge <?= $badgeSt ?>"><?= htmlspecialchars($p['estado']) ?></span></td>
                                            <td class="text-end">
                                                <a href="<?= BASE_URL ?>/modules/pedidos/view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Próximas Reservas Registradas -->
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold m-0" style="color: var(--secondary-color);">
                            <i class="bi bi-calendar-check me-2 text-warning"></i> Próximas Reservas
                        </h5>
                        <a href="<?= BASE_URL ?>/modules/reservas/" class="btn btn-sm btn-link text-decoration-none">Ver todas <i class="bi bi-arrow-right"></i></a>
                    </div>

                    <?php if (empty($proximasReservas)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-calendar-event fs-2 d-block mb-1 text-secondary"></i>
                            No hay reservas agendadas en este momento.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Mesa</th>
                                        <th>Fecha & Hora</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($proximasReservas as $r): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars(($r['clientes']['nombre'] ?? '') . ' ' . ($r['clientes']['apellido'] ?? '')) ?></strong></td>
                                            <td><span class="badge bg-dark">Mesa #<?= $r['mesas']['numero'] ?? '-' ?></span></td>
                                            <td><?= date('d/m/Y', strtotime($r['fecha'])) ?> - <?= date('H:i', strtotime($r['hora'])) ?></td>
                                            <td><span class="badge badge-warning"><?= htmlspecialchars($r['estado']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Accesos a Módulos y Próximos Sprints -->
            <div class="col-lg-4">
                <!-- Módulos Operativos Sprint 2 -->
                <div class="card-custom p-4 mb-4">
                    <h5 class="fw-bold mb-3" style="color: var(--secondary-color);">Módulos Activos (Sprint 2)</h5>
                    <div class="list-group list-group-flush">
                        <a href="<?= BASE_URL ?>/modules/pedidos/" class="list-group-item list-group-item-action bg-transparent px-0 py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-receipt text-primary me-2"></i>Pedidos & Comandas POS</span>
                            <span class="badge bg-primary rounded-pill"><?= $totalPedidosPendientes + $totalPedidosEnCocina ?> activos</span>
                        </a>
                        <a href="<?= BASE_URL ?>/modules/productos/" class="list-group-item list-group-item-action bg-transparent px-0 py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-journal-richtext text-success me-2"></i>Menú & Platillos</span>
                            <span class="badge bg-success rounded-pill"><?= $totalProductosActivos ?> platos</span>
                        </a>
                        <?php if ($isAdmin): ?>
                            <a href="<?= BASE_URL ?>/modules/categorias/" class="list-group-item list-group-item-action bg-transparent px-0 py-2 d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-tags text-warning me-2"></i>Categorías del Menú</span>
                                <i class="bi bi-chevron-right text-muted small"></i>
                            </a>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/modules/mesas/" class="list-group-item list-group-item-action bg-transparent px-0 py-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-table text-info me-2"></i>Monitoreo de Mesas</span>
                            <span class="badge bg-secondary rounded-pill"><?= $totalMesas ?> mesas</span>
                        </a>
                    </div>
                </div>

                <!-- Futuros Sprints -->
                <div class="card-custom p-4">
                    <h5 class="fw-bold mb-3" style="color: var(--secondary-color);">Preparado para Sprints 3 y 4</h5>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item bg-transparent px-0 py-2 d-flex justify-content-between align-items-center text-muted">
                            <span><i class="bi bi-cash-stack me-2"></i>Ventas & Facturación</span>
                            <span class="badge bg-secondary">Sprint 3</span>
                        </div>
                        <div class="list-group-item bg-transparent px-0 py-2 d-flex justify-content-between align-items-center text-muted">
                            <span><i class="bi bi-box-seam me-2"></i>Inventario & Insumos</span>
                            <span class="badge bg-secondary">Sprint 4</span>
                        </div>
                        <div class="list-group-item bg-transparent px-0 py-2 d-flex justify-content-between align-items-center text-muted">
                            <span><i class="bi bi-bar-chart-line me-2"></i>Reportes & Analítica</span>
                            <span class="badge bg-secondary">Sprint 4</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
