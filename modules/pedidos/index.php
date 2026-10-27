<?php
$pageTitle = 'Gestión de Pedidos & Comandas';
$currentModule = 'pedidos';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Mesero', 'Cocinero', 'Cajero', 'Recepcionista']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$pedidos = [];
$mesas = [];

$userRole = $currentUser['rol_nombre'] ?? '';
$isAdmin = ($userRole === 'Administrador');
$isMesero = ($userRole === 'Mesero');
$isCocinero = ($userRole === 'Cocinero');
$isCajero = ($userRole === 'Cajero');
$isRecepcionista = ($userRole === 'Recepcionista');

// Filtros GET
$filtroEstado = clean_input($_GET['estado'] ?? '');
$filtroMesa = clean_input($_GET['mesa_id'] ?? '');
$filtroFecha = clean_input($_GET['fecha'] ?? '');

$estadosValidos = ['Pendiente', 'En Preparación', 'Listo', 'Servido', 'Pagado', 'Cancelado'];

// Contadores rápidos para métricas superiores
$counts = [
    'Pendiente' => 0,
    'En Preparación' => 0,
    'Listo' => 0,
    'Servido' => 0,
    'Pagado' => 0,
    'Cancelado' => 0
];

if ($supabase->isConfigured()) {
    // 1. Obtener mesas para el filtro
    $resMesas = $supabase->select('mesas', 'id,numero,ubicacion', ['order' => 'numero.asc'], $authToken);
    if ($resMesas['success']) {
        $mesas = $resMesas['data'];
    }

    // 2. Construir parámetros para consulta de pedidos
    $params = ['order' => 'created_at.desc'];
    if (!empty($filtroEstado) && in_array($filtroEstado, $estadosValidos, true)) {
        $params['estado'] = 'eq.' . $filtroEstado;
    }
    if (!empty($filtroMesa) && is_valid_uuid($filtroMesa)) {
        $params['mesa_id'] = 'eq.' . $filtroMesa;
    }
    if (!empty($filtroFecha) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtroFecha)) {
        $params['created_at'] = 'gte.' . $filtroFecha . 'T00:00:00&created_at=lte.' . $filtroFecha . 'T23:59:59';
    }

    // Si es cocinero y no hay filtro explícito, priorizar comandas activas de cocina
    if ($isCocinero && empty($filtroEstado)) {
        $params['estado'] = 'in.(Pendiente,En Preparación,Listo)';
    }

    $resPedidos = $supabase->select('pedidos', '*,mesas(id,numero,ubicacion),clientes(id,nombre,apellido,documento),perfiles(id,nombre,apellido)', $params, $authToken);
    if ($resPedidos['success']) {
        $pedidos = $resPedidos['data'];
    }

    // 3. Obtener conteos generales de estados
    $resAll = $supabase->select('pedidos', 'id,estado', [], $authToken);
    if ($resAll['success'] && !empty($resAll['data'])) {
        foreach ($resAll['data'] as $p) {
            $st = $p['estado'] ?? '';
            if (isset($counts[$st])) {
                $counts[$st]++;
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
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Control de Pedidos & Comandas</h3>
                <p class="text-muted mb-0">Gestión de comandas en cocina, atención en sala y cobro en caja</p>
            </div>
            <?php if ($isAdmin || $isMesero): ?>
                <div>
                    <a href="<?= BASE_URL ?>/modules/pedidos/create.php" class="btn btn-primary-custom">
                        <i class="bi bi-cart-plus-fill me-2"></i> Nuevo Pedido (POS)
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tarjetas de Resumen Operativo -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>/modules/pedidos/?estado=Pendiente" class="text-decoration-none">
                    <div class="card-custom p-3 text-center <?= $filtroEstado === 'Pendiente' ? 'border-warning shadow-sm' : '' ?>">
                        <span class="badge bg-warning text-dark mb-1">Pendientes</span>
                        <h4 class="fw-bold m-0 text-dark"><?= $counts['Pendiente'] ?></h4>
                        <small class="text-muted" style="font-size: 0.75rem;">Por iniciar cocina</small>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>/modules/pedidos/?estado=En+Preparación" class="text-decoration-none">
                    <div class="card-custom p-3 text-center <?= $filtroEstado === 'En Preparación' ? 'border-primary shadow-sm' : '' ?>">
                        <span class="badge bg-primary mb-1">En Preparación</span>
                        <h4 class="fw-bold m-0 text-dark"><?= $counts['En Preparación'] ?></h4>
                        <small class="text-muted" style="font-size: 0.75rem;">En fogones</small>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>/modules/pedidos/?estado=Listo" class="text-decoration-none">
                    <div class="card-custom p-3 text-center <?= $filtroEstado === 'Listo' ? 'border-info shadow-sm' : '' ?>">
                        <span class="badge bg-info text-dark mb-1">Listos</span>
                        <h4 class="fw-bold m-0 text-dark"><?= $counts['Listo'] ?></h4>
                        <small class="text-muted" style="font-size: 0.75rem;">Listos para servir</small>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>/modules/pedidos/?estado=Servido" class="text-decoration-none">
                    <div class="card-custom p-3 text-center <?= $filtroEstado === 'Servido' ? 'border-success shadow-sm' : '' ?>">
                        <span class="badge bg-success-subtle text-success border border-success mb-1">Servidos</span>
                        <h4 class="fw-bold m-0 text-dark"><?= $counts['Servido'] ?></h4>
                        <small class="text-muted" style="font-size: 0.75rem;">En mesa / Por cobrar</small>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>/modules/pedidos/?estado=Pagado" class="text-decoration-none">
                    <div class="card-custom p-3 text-center <?= $filtroEstado === 'Pagado' ? 'border-secondary shadow-sm' : '' ?>">
                        <span class="badge bg-secondary mb-1">Pagados</span>
                        <h4 class="fw-bold m-0 text-dark"><?= $counts['Pagado'] ?></h4>
                        <small class="text-muted" style="font-size: 0.75rem;">Finalizados</small>
                    </div>
                </a>
            </div>

            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>/modules/pedidos/?estado=Cancelado" class="text-decoration-none">
                    <div class="card-custom p-3 text-center <?= $filtroEstado === 'Cancelado' ? 'border-danger shadow-sm' : '' ?>">
                        <span class="badge bg-danger-subtle text-danger border border-danger mb-1">Cancelados</span>
                        <h4 class="fw-bold m-0 text-dark"><?= $counts['Cancelado'] ?></h4>
                        <small class="text-muted" style="font-size: 0.75rem;">Anulados</small>
                    </div>
                </a>
            </div>
        </div>

        <!-- Filtros y Buscador -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" action="" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Buscar por comanda, mesa, cliente o mesero..." data-table-search="table-pedidos">
                    </div>
                </div>

                <div class="col-md-3">
                    <select name="estado" class="form-select bg-light" onchange="this.form.submit()">
                        <option value="">-- Todos los Estados --</option>
                        <?php foreach ($estadosValidos as $est): ?>
                            <option value="<?= $est ?>" <?= ($filtroEstado === $est) ? 'selected' : '' ?>>
                                <?= $est ?> (<?= $counts[$est] ?? 0 ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <select name="mesa_id" class="form-select bg-light" onchange="this.form.submit()">
                        <option value="">-- Todas las Mesas --</option>
                        <?php foreach ($mesas as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= ($filtroMesa === $m['id']) ? 'selected' : '' ?>>
                                Mesa #<?= $m['numero'] ?> (<?= htmlspecialchars($m['ubicacion']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 text-end">
                    <?php if ($filtroEstado !== '' || $filtroMesa !== '' || $filtroFecha !== ''): ?>
                        <a href="<?= BASE_URL ?>/modules/pedidos/" class="btn btn-outline-secondary w-100" title="Limpiar Filtros">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Ver Todos
                        </a>
                    <?php else: ?>
                        <span class="text-muted small"><strong><?= count($pedidos) ?></strong> pedidos</span>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Tabla de Pedidos -->
        <div class="table-custom-wrapper">
            <table class="table table-custom mb-0" id="table-pedidos">
                <thead>
                    <tr>
                        <th>N° Comanda</th>
                        <th>Mesa</th>
                        <th>Cliente</th>
                        <th>Atendido Por</th>
                        <th>Fecha & Hora</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pedidos)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-receipt fs-1 d-block mb-2 text-secondary"></i>
                                No se encontraron pedidos registrados con los filtros seleccionados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pedidos as $p): ?>
                            <?php
                            $mesaNum = $p['mesas']['numero'] ?? '-';
                            $mesaUbi = $p['mesas']['ubicacion'] ?? '';
                            $cliNombre = !empty($p['clientes']) ? trim(($p['clientes']['nombre'] ?? '') . ' ' . ($p['clientes']['apellido'] ?? '')) : 'Cliente Ocasional / Salón';
                            $usuarioNombre = !empty($p['perfiles']) ? trim(($p['perfiles']['nombre'] ?? '') . ' ' . ($p['perfiles']['apellido'] ?? '')) : 'Personal';
                            $estado = $p['estado'] ?? 'Pendiente';
                            $total = (float)($p['total'] ?? 0);
                            $fechaFormateada = date('d/m/Y H:i', strtotime($p['created_at']));
                            $orderShortId = substr($p['id'], 0, 8);

                            $badgeClass = [
                                'Pendiente' => 'bg-warning text-dark',
                                'En Preparación' => 'bg-primary text-white',
                                'Listo' => 'bg-info text-dark',
                                'Servido' => 'bg-success text-white',
                                'Pagado' => 'bg-secondary text-white',
                                'Cancelado' => 'bg-danger text-white'
                            ][$estado] ?? 'bg-light text-dark';
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar-circle" style="width: 32px; height: 32px; font-size: 0.75rem; background: rgba(30, 41, 59, 0.08); color: var(--secondary-color);">
                                            <i class="bi bi-receipt"></i>
                                        </span>
                                        <div>
                                            <strong class="text-dark font-monospace">#<?= $orderShortId ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-dark">Mesa #<?= $mesaNum ?></span>
                                    <small class="d-block text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($mesaUbi) ?></small>
                                </td>
                                <td>
                                    <span class="text-dark fw-semibold"><?= htmlspecialchars($cliNombre) ?></span>
                                </td>
                                <td>
                                    <span class="text-muted small"><i class="bi bi-person me-1"></i><?= htmlspecialchars($usuarioNombre) ?></span>
                                </td>
                                <td>
                                    <small class="text-muted"><?= $fechaFormateada ?></small>
                                </td>
                                <td>
                                    <strong class="text-success fs-6">Bs. <?= number_format($total, 2) ?></strong>
                                </td>
                                <td>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= htmlspecialchars($estado) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <!-- Ver Detalle / Comanda -->
                                    <a href="<?= BASE_URL ?>/modules/pedidos/view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary me-1" title="Ver Detalle / Comanda">
                                        <i class="bi bi-eye-fill"></i>
                                    </a>

                                    <!-- Acciones Rápidas según Rol y Estado -->
                                    <?php if ($isCocinero || $isAdmin): ?>
                                        <?php if ($estado === 'Pendiente'): ?>
                                            <a href="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=<?= $p['id'] ?>&status=En+Preparaci%C3%B3n" class="btn btn-sm btn-outline-primary me-1" title="Iniciar Preparación en Cocina">
                                                <i class="bi bi-fire"></i> Cocinar
                                            </a>
                                        <?php elseif ($estado === 'En Preparación'): ?>
                                            <a href="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=<?= $p['id'] ?>&status=Listo" class="btn btn-sm btn-outline-info me-1" title="Marcar como Listo">
                                                <i class="bi bi-check2-circle"></i> Listo
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($isMesero || $isAdmin): ?>
                                        <?php if ($estado === 'Listo'): ?>
                                            <a href="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=<?= $p['id'] ?>&status=Servido" class="btn btn-sm btn-outline-success me-1" title="Marcar como Servido a Mesa">
                                                <i class="bi bi-bell-fill"></i> Servir
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($estado === 'Pendiente'): ?>
                                            <a href="<?= BASE_URL ?>/modules/pedidos/cancel.php?id=<?= $p['id'] ?>" 
                                               class="btn btn-sm btn-outline-danger" 
                                               data-confirm="¿Está seguro de cancelar este pedido?" 
                                               title="Cancelar Pedido">
                                                <i class="bi bi-x-circle"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (($isCajero || $isAdmin) && $estado === 'Servido'): ?>
                                        <a href="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=<?= $p['id'] ?>&status=Pagado" class="btn btn-sm btn-success me-1" title="Cobrar y Registrar Pago">
                                            <i class="bi bi-cash-stack"></i> Cobrar
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($isAdmin && !in_array($estado, ['Pagado', 'Cancelado'])): ?>
                                        <?php if ($estado !== 'Pendiente'): ?>
                                            <a href="<?= BASE_URL ?>/modules/pedidos/cancel.php?id=<?= $p['id'] ?>" 
                                               class="btn btn-sm btn-outline-danger" 
                                               data-confirm="¿Está seguro de anular/cancelar este pedido?" 
                                               title="Anular Pedido">
                                                <i class="bi bi-slash-circle"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
