<?php
$pageTitle = 'Gestión de Compras';
$currentModule = 'compras';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Cajero']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$compras = [];

if ($supabase->isConfigured()) {
    $resCompras = $supabase->select('compras', '*,proveedores(id,nombre),perfiles(id,nombre,apellido)', [
        'order' => 'created_at.desc'
    ], $authToken);

    if ($resCompras['success'] && !empty($resCompras['data'])) {
        $compras = $resCompras['data'];
    }
}

$userRole = $currentUser['rol_nombre'] ?? '';
$isAdmin = ($userRole === 'Administrador');

// Contadores y métricas operativas
$totalCompras = count($compras);
$registradasCount = 0;
$anuladasCount = 0;
$montoTotalRegistrado = 0.0;

foreach ($compras as $c) {
    $st = $c['estado'] ?? 'Registrada';
    if ($st === 'Registrada') {
        $registradasCount++;
        $montoTotalRegistrado += (float)($c['total'] ?? 0);
    } elseif ($st === 'Anulada') {
        $anuladasCount++;
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
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Historial de Compras & Abastecimiento</h3>
                <p class="text-muted mb-0">Control de adquisiciones, insumos y compras registradas a proveedores</p>
            </div>
            <?php if ($isAdmin): ?>
                <div>
                    <a href="<?= BASE_URL ?>/modules/compras/create.php" class="btn btn-primary-custom">
                        <i class="bi bi-cart-plus-fill me-2"></i> Nueva Compra
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tarjetas de Resumen Operativo -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-muted small d-block mb-1">Total Compras</span>
                    <h4 class="fw-bold text-dark m-0"><?= $totalCompras ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Historial acumulado</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-success small d-block mb-1"><i class="bi bi-check-circle me-1"></i>Registradas</span>
                    <h4 class="fw-bold text-success m-0"><?= $registradasCount ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Compras efectivas</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-secondary small d-block mb-1"><i class="bi bi-slash-circle me-1"></i>Anuladas</span>
                    <h4 class="fw-bold text-secondary m-0"><?= $anuladasCount ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Canceladas</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-primary small d-block mb-1"><i class="bi bi-cash-stack me-1"></i>Monto Invertido</span>
                    <h4 class="fw-bold text-primary m-0">Bs. <?= number_format($montoTotalRegistrado, 2) ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Total registrado</small>
                </div>
            </div>
        </div>

        <!-- Buscador -->
        <div class="card-custom p-3 mb-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Buscar compra por ID, proveedor o usuario..." data-table-search="table-compras">
                    </div>
                </div>
                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                    <span class="text-muted small">Mostrando <strong><?= $totalCompras ?></strong> compras registradas</span>
                </div>
            </div>
        </div>

        <!-- Tabla de Compras -->
        <div class="table-custom-wrapper">
            <table class="table table-custom mb-0" id="table-compras">
                <thead>
                    <tr>
                        <th>N° Compra</th>
                        <th>Fecha & Hora</th>
                        <th>Proveedor</th>
                        <th>Registrado Por</th>
                        <th class="text-end">Total</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($compras)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-cart-x fs-1 d-block mb-2 text-secondary"></i>
                                No se registran compras en el sistema.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($compras as $compra): ?>
                            <?php
                            $compraShortId = substr($compra['id'], 0, 8);
                            $provNombre = $compra['proveedores']['nombre'] ?? 'Proveedor Desconocido';
                            $usuarioNombre = !empty($compra['perfiles']) 
                                ? trim(($compra['perfiles']['nombre'] ?? '') . ' ' . ($compra['perfiles']['apellido'] ?? '')) 
                                : 'Personal';
                            $estadoCompra = $compra['estado'] ?? 'Registrada';
                            $isRegistrada = ($estadoCompra === 'Registrada');
                            $totalVal = (float)($compra['total'] ?? 0);
                            $fechaFormateada = !empty($compra['created_at']) 
                                ? date('d/m/Y H:i', strtotime($compra['created_at'])) 
                                : '-';
                            ?>
                            <tr class="<?= !$isRegistrada ? 'table-light opacity-75' : '' ?>">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-circle" style="width: 32px; height: 32px; font-size: 0.8rem; background: <?= $isRegistrada ? 'linear-gradient(135deg, #1d3557, #457b9d)' : '#6c757d' ?>;">
                                            <i class="bi bi-bag-check"></i>
                                        </div>
                                        <strong class="font-monospace text-dark">#<?= htmlspecialchars($compraShortId) ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-dark small"><i class="bi bi-calendar3 me-1 text-muted"></i><?= $fechaFormateada ?></span>
                                </td>
                                <td>
                                    <strong class="text-dark"><?= htmlspecialchars($provNombre) ?></strong>
                                </td>
                                <td>
                                    <small class="text-muted"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($usuarioNombre) ?></small>
                                </td>
                                <td class="text-end fw-bold text-dark fs-6">
                                    Bs. <?= number_format($totalVal, 2) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $isRegistrada ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= htmlspecialchars($estadoCompra) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= BASE_URL ?>/modules/compras/view.php?id=<?= $compra['id'] ?>" class="btn btn-sm btn-outline-primary" title="Ver Detalle de Compra">
                                        <i class="bi bi-eye-fill me-1"></i> Ver Detalle
                                    </a>
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
