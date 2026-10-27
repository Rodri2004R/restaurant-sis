<?php
$pageTitle = 'Detalle de Comanda / Pedido';
$currentModule = 'pedidos';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Mesero', 'Cocinero', 'Cajero', 'Recepcionista']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$id = clean_input($_GET['id'] ?? '');

// 1. Validar formato UUID de pedido
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'ID de pedido no especificado o formato de identificador inválido.');
    redirect('/modules/pedidos/');
}

$pedido = null;
$detalles = [];

if ($supabase->isConfigured()) {
    // 2. Consultar datos del pedido maestro
    $resPed = $supabase->select('pedidos', '*,mesas(id,numero,ubicacion,capacidad),clientes(id,nombre,apellido,documento,telefono,email),perfiles(id,nombre,apellido,email)', ['id' => 'eq.' . $id], $authToken);
    if ($resPed['success'] && !empty($resPed['data'])) {
        $pedido = $resPed['data'][0];
    }

    // 3. Consultar detalles de productos asociados
    $resDet = $supabase->select('detalles_pedido', '*,productos(id,nombre,descripcion,categoria_id,categorias(nombre))', [
        'pedido_id' => 'eq.' . $id,
        'order' => 'id.asc'
    ], $authToken);
    if ($resDet['success'] && !empty($resDet['data'])) {
        $detalles = $resDet['data'];
    }
}

if (!$pedido) {
    set_flash_message('danger', 'El pedido solicitado no fue encontrado en el sistema.');
    redirect('/modules/pedidos/');
}

$userRole = $currentUser['rol_nombre'] ?? '';
$isAdmin = ($userRole === 'Administrador');
$isMesero = ($userRole === 'Mesero');
$isCocinero = ($userRole === 'Cocinero');
$isCajero = ($userRole === 'Cajero');

$estado = $pedido['estado'] ?? 'Pendiente';
$orderShortId = substr($pedido['id'], 0, 8);
$fechaFormateada = date('d/m/Y H:i:s', strtotime($pedido['created_at']));
$mesaNum = $pedido['mesas']['numero'] ?? '-';
$mesaUbi = $pedido['mesas']['ubicacion'] ?? 'Salón';
$mesaCap = $pedido['mesas']['capacidad'] ?? '-';
$cliNombre = !empty($pedido['clientes']) ? trim(($pedido['clientes']['nombre'] ?? '') . ' ' . ($pedido['clientes']['apellido'] ?? '')) : 'Cliente Ocasional / Salón';
$cliDoc = $pedido['clientes']['documento'] ?? 'S/N';
$cliTel = $pedido['clientes']['telefono'] ?? 'No registrado';
$usuarioNombre = !empty($pedido['perfiles']) ? trim(($pedido['perfiles']['nombre'] ?? '') . ' ' . ($pedido['perfiles']['apellido'] ?? '')) : 'Personal';
$total = (float)($pedido['total'] ?? 0);

$badgeClass = [
    'Pendiente' => 'bg-warning text-dark',
    'En Preparación' => 'bg-primary text-white',
    'Listo' => 'bg-info text-dark',
    'Servido' => 'bg-success text-white',
    'Pagado' => 'bg-secondary text-white',
    'Cancelado' => 'bg-danger text-white'
][$estado] ?? 'bg-light text-dark';
?>

<style>
@media print {
    .app-sidebar, .app-navbar, .no-print, .btn, .sidebar-footer {
        display: none !important;
    }
    .app-content {
        margin: 0 !important;
        padding: 0 !important;
    }
    .main-container {
        padding: 0 !important;
    }
    .ticket-print-box {
        border: 1px dashed #000 !important;
        box-shadow: none !important;
        width: 100% !important;
        max-width: 480px !important;
        margin: 0 auto !important;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <?php require_once __DIR__ . '/../../includes/alerts.php'; ?>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 no-print">
            <div>
                <a href="<?= BASE_URL ?>/modules/pedidos/" class="btn btn-outline-secondary btn-sm mb-2">
                    <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Pedidos
                </a>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">
                    Comanda #<?= $orderShortId ?> — Mesa #<?= $mesaNum ?>
                </h3>
                <p class="text-muted mb-0">Detalle completo de comanda para cocina, atención y caja</p>
            </div>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-outline-secondary">
                    <i class="bi bi-printer me-1"></i> Imprimir Ticket
                </button>
                <?php if ($isAdmin || $isMesero): ?>
                    <a href="<?= BASE_URL ?>/modules/pedidos/create.php" class="btn btn-primary-custom">
                        <i class="bi bi-plus-circle me-1"></i> Nueva Comanda
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <!-- Comanda / Ticket Principal -->
            <div class="col-lg-8">
                <div class="card-custom p-4 ticket-print-box">
                    <!-- Cabecera de la Comanda -->
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="fs-4 fw-bold text-dark">Restaurant Smart</span>
                            </div>
                            <small class="text-muted d-block">INF401 - Taller II - UAJMS</small>
                            <small class="text-muted d-block">Fecha & Hora: <strong><?= $fechaFormateada ?></strong></small>
                        </div>
                        <div class="text-end">
                            <span class="badge <?= $badgeClass ?> fs-6 mb-1"><?= htmlspecialchars($estado) ?></span>
                            <div class="font-monospace text-muted small">ID: <?= $pedido['id'] ?></div>
                        </div>
                    </div>

                    <!-- Datos de Mesa y Atención -->
                    <div class="row g-3 mb-4">
                        <div class="col-sm-4">
                            <div class="p-2 border rounded bg-light">
                                <span class="text-muted small d-block">Ubicación de Salón</span>
                                <strong class="fs-6 text-dark"><i class="bi bi-table me-1"></i> Mesa #<?= $mesaNum ?></strong>
                                <small class="text-muted d-block"><?= htmlspecialchars($mesaUbi) ?> (Cap: <?= $mesaCap ?>)</small>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-2 border rounded bg-light">
                                <span class="text-muted small d-block">Cliente</span>
                                <strong class="fs-6 text-dark"><i class="bi bi-person me-1"></i> <?= htmlspecialchars($cliNombre) ?></strong>
                                <small class="text-muted d-block">Doc: <?= htmlspecialchars($cliDoc) ?></small>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-2 border rounded bg-light">
                                <span class="text-muted small d-block">Mesero / Atendido Por</span>
                                <strong class="fs-6 text-dark"><i class="bi bi-person-badge me-1"></i> <?= htmlspecialchars($usuarioNombre) ?></strong>
                                <small class="text-muted d-block">Personal de Servicio</small>
                            </div>
                        </div>
                    </div>

                    <!-- Observaciones de la Comanda -->
                    <?php if (!empty($pedido['observaciones'])): ?>
                        <div class="alert alert-info py-2 px-3 mb-4">
                            <i class="bi bi-info-circle me-1"></i> <strong>Observaciones generales:</strong> <?= htmlspecialchars($pedido['observaciones']) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Desglose de Platillos / Ítems -->
                    <h5 class="fw-bold mb-3" style="color: var(--secondary-color);">Platillos & Bebidas Ordenadas</h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;" class="text-center">Cant.</th>
                                    <th>Descripción del Platillo</th>
                                    <th style="width: 130px;" class="text-end">P. Unitario</th>
                                    <th style="width: 130px;" class="text-end">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($detalles)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4">
                                            <div class="text-muted mb-1">
                                                <i class="bi bi-clock-history text-secondary fs-4 d-block mb-1"></i>
                                                <em>Comanda histórica registrada sin desglose individual de platillos.</em>
                                            </div>
                                            <small class="text-muted d-block">
                                                (El importe consolidado registrado en la comanda es de <strong>Bs. <?= number_format($total, 2) ?></strong>)
                                            </small>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($detalles as $d): ?>
                                        <?php
                                        $prodNom = $d['productos']['nombre'] ?? 'Producto Desconocido';
                                        $catNom = $d['productos']['categorias']['nombre'] ?? 'General';
                                        $cant = (int)($d['cantidad'] ?? 1);
                                        $pUnit = (float)($d['precio_unitario'] ?? 0);
                                        $subt = (float)($d['subtotal'] ?? 0);
                                        $notas = trim($d['notas'] ?? '');
                                        ?>
                                        <tr>
                                            <td class="text-center fw-bold fs-6"><?= $cant ?></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($prodNom) ?></div>
                                                <small class="badge badge-role me-1"><?= htmlspecialchars($catNom) ?></small>
                                                <?php if (!empty($notas)): ?>
                                                    <small class="text-danger fw-semibold d-block mt-1">
                                                        <i class="bi bi-chat-left-text me-1"></i> Nota: <?= htmlspecialchars($notas) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">Bs. <?= number_format($pUnit, 2) ?></td>
                                            <td class="text-end fw-bold">Bs. <?= number_format($subt, 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="3" class="text-end fw-bold fs-5">TOTAL A PAGAR:</td>
                                    <td class="text-end fw-bold fs-4 text-success">Bs. <?= number_format($total, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="text-center border-top pt-3 text-muted small">
                        <p class="mb-0">¡Gracias por su preferencia en Restaurant Smart!</p>
                    </div>
                </div>
            </div>

            <!-- Panel Lateral de Control y Transición de Estados (RBAC) -->
            <div class="col-lg-4 no-print">
                <div class="card-custom p-4 mb-4">
                    <h5 class="fw-bold mb-3" style="color: var(--secondary-color);">
                        <i class="bi bi-sliders me-1"></i> Control de Estado (RBAC)
                    </h5>

                    <div class="mb-3">
                        <label class="text-muted small d-block">Estado Actual del Pedido:</label>
                        <span class="badge <?= $badgeClass ?> fs-6 py-2 px-3"><?= htmlspecialchars($estado) ?></span>
                    </div>

                    <!-- Flujo visual de transiciones -->
                    <div class="p-3 bg-light rounded border mb-4">
                        <small class="text-muted fw-bold d-block mb-2 text-uppercase" style="font-size: 0.7rem;">Línea de Vida de la Comanda:</small>
                        <div class="d-flex flex-column gap-2" style="font-size: 0.85rem;">
                            <div class="d-flex align-items-center <?= in_array($estado, ['Pendiente', 'En Preparación', 'Listo', 'Servido', 'Pagado']) ? 'text-success fw-bold' : 'text-muted' ?>">
                                <i class="bi <?= in_array($estado, ['En Preparación', 'Listo', 'Servido', 'Pagado']) ? 'bi-check-circle-fill' : 'bi-circle' ?> me-2"></i> 1. Pendiente (Recibido)
                            </div>
                            <div class="d-flex align-items-center <?= in_array($estado, ['En Preparación', 'Listo', 'Servido', 'Pagado']) ? 'text-success fw-bold' : 'text-muted' ?>">
                                <i class="bi <?= in_array($estado, ['Listo', 'Servido', 'Pagado']) ? 'bi-check-circle-fill' : 'bi-circle' ?> me-2"></i> 2. En Preparación (Cocina)
                            </div>
                            <div class="d-flex align-items-center <?= in_array($estado, ['Listo', 'Servido', 'Pagado']) ? 'text-success fw-bold' : 'text-muted' ?>">
                                <i class="bi <?= in_array($estado, ['Servido', 'Pagado']) ? 'bi-check-circle-fill' : 'bi-circle' ?> me-2"></i> 3. Listo (Despacho)
                            </div>
                            <div class="d-flex align-items-center <?= in_array($estado, ['Servido', 'Pagado']) ? 'text-success fw-bold' : 'text-muted' ?>">
                                <i class="bi <?= ($estado === 'Pagado') ? 'bi-check-circle-fill' : 'bi-circle' ?> me-2"></i> 4. Servido (En Mesa)
                            </div>
                            <div class="d-flex align-items-center <?= ($estado === 'Pagado') ? 'text-success fw-bold' : 'text-muted' ?>">
                                <i class="bi <?= ($estado === 'Pagado') ? 'bi-check-circle-fill' : 'bi-circle' ?> me-2"></i> 5. Pagado (Caja Cerrada)
                            </div>
                        </div>
                    </div>

                    <!-- Botones de Acción según Rol -->
                    <div class="d-grid gap-2">
                        <!-- Transición de Cocina (Cocinero / Administrador) -->
                        <?php if ($isCocinero || $isAdmin): ?>
                            <?php if ($estado === 'Pendiente'): ?>
                                <a href="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=<?= $pedido['id'] ?>&status=En+Preparaci%C3%B3n" class="btn btn-primary">
                                    <i class="bi bi-fire me-1"></i> Iniciar Preparación en Cocina
                                </a>
                            <?php elseif ($estado === 'En Preparación'): ?>
                                <a href="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=<?= $pedido['id'] ?>&status=Listo" class="btn btn-info text-dark fw-semibold">
                                    <i class="bi bi-check2-circle me-1"></i> Marcar como Listo en Barra/Cocina
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Transición de Servicio a Mesa (Mesero / Administrador) -->
                        <?php if ($isMesero || $isAdmin): ?>
                            <?php if ($estado === 'Listo'): ?>
                                <a href="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=<?= $pedido['id'] ?>&status=Servido" class="btn btn-success">
                                    <i class="bi bi-bell-fill me-1"></i> Confirmar Servido en Mesa
                                </a>
                            <?php endif; ?>

                            <?php if ($estado === 'Pendiente'): ?>
                                <a href="<?= BASE_URL ?>/modules/pedidos/cancel.php?id=<?= $pedido['id'] ?>" 
                                   class="btn btn-outline-danger" 
                                   data-confirm="¿Está seguro de cancelar este pedido pendiente?">
                                    <i class="bi bi-x-circle me-1"></i> Cancelar Pedido
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Transición de Cobro en Caja (Cajero / Administrador) -->
                        <?php if ($isCajero || $isAdmin): ?>
                            <?php if ($estado === 'Servido'): ?>
                                <a href="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=<?= $pedido['id'] ?>&status=Pagado" class="btn btn-success py-2 fw-bold">
                                    <i class="bi bi-cash-coin me-1"></i> Registrar Cobro & Pagado (Bs. <?= number_format($total, 2) ?>)
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Cancelación por Administrador -->
                        <?php if ($isAdmin && !in_array($estado, ['Pagado', 'Cancelado', 'Pendiente'])): ?>
                            <a href="<?= BASE_URL ?>/modules/pedidos/cancel.php?id=<?= $pedido['id'] ?>" 
                               class="btn btn-outline-danger" 
                               data-confirm="¿Está seguro de anular administrativamente este pedido?">
                                <i class="bi bi-slash-circle me-1"></i> Anular Pedido (Admin)
                            </a>
                        <?php endif; ?>

                        <?php if (in_array($estado, ['Pagado', 'Cancelado'])): ?>
                            <div class="alert alert-secondary text-center small mb-0">
                                <i class="bi bi-lock me-1"></i> Este pedido se encuentra finalizado en estado <strong><?= htmlspecialchars($estado) ?></strong>.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
