<?php
$pageTitle = 'Detalle de Compra';
$currentModule = 'compras';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Cajero']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$id = clean_input($_GET['id'] ?? '');

// 1. Validar formato UUID de la compra
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'ID de compra no especificado o formato de identificador inválido.');
    redirect('/modules/compras/');
}

$compra = null;
$detalles = [];

if ($supabase->isConfigured()) {
    // 2. Consultar datos de la compra maestra
    $resCompra = $supabase->select('compras', '*,proveedores(id,nombre,contacto,telefono,email,direccion),perfiles(id,nombre,apellido,email)', [
        'id' => 'eq.' . $id
    ], $authToken);

    if ($resCompra['success'] && !empty($resCompra['data'])) {
        $compra = $resCompra['data'][0];
    }

    // 3. Consultar los detalles de ítems comprados
    $resDet = $supabase->select('detalles_compra', '*,productos(id,nombre,categorias(nombre))', [
        'compra_id' => 'eq.' . $id,
        'order' => 'created_at.asc'
    ], $authToken);

    if ($resDet['success'] && !empty($resDet['data'])) {
        $detalles = $resDet['data'];
    }
}

if (!$compra) {
    set_flash_message('danger', 'La compra solicitada no fue encontrada en el sistema.');
    redirect('/modules/compras/');
}

$userRole = $currentUser['rol_nombre'] ?? '';
$isAdmin = ($userRole === 'Administrador');

$estado = $compra['estado'] ?? 'Registrada';
$compraShortId = substr($compra['id'], 0, 8);
$fechaFormateada = !empty($compra['created_at']) 
    ? date('d/m/Y H:i:s', strtotime($compra['created_at'])) 
    : '-';

$provNombre = $compra['proveedores']['nombre'] ?? 'Proveedor Desconocido';
$provContacto = $compra['proveedores']['contacto'] ?? 'No registrado';
$provTel = $compra['proveedores']['telefono'] ?? 'No registrado';
$provEmail = $compra['proveedores']['email'] ?? 'No registrado';
$provDir = $compra['proveedores']['direccion'] ?? 'No registrada';

$usuarioNombre = !empty($compra['perfiles']) 
    ? trim(($compra['perfiles']['nombre'] ?? '') . ' ' . ($compra['perfiles']['apellido'] ?? '')) 
    : 'Personal del Restaurante';
$usuarioEmail = $compra['perfiles']['email'] ?? '';

$totalConsolidado = (float)($compra['total'] ?? 0);
$isRegistrada = ($estado === 'Registrada');
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
        max-width: 600px !important;
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
                <a href="<?= BASE_URL ?>/modules/compras/" class="btn btn-outline-secondary btn-sm mb-2">
                    <i class="bi bi-arrow-left me-1"></i> Volver al Historial de Compras
                </a>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">
                    Comprobante de Compra #<?= htmlspecialchars($compraShortId) ?>
                </h3>
                <p class="text-muted mb-0">Detalle oficial de adquisición de insumos y mercadería</p>
            </div>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-outline-secondary">
                    <i class="bi bi-printer me-1"></i> Imprimir Comprobante
                </button>
                <?php if ($isAdmin): ?>
                    <a href="<?= BASE_URL ?>/modules/compras/create.php" class="btn btn-primary-custom">
                        <i class="bi bi-cart-plus me-1"></i> Nueva Compra
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <!-- Comprobante / Ticket Principal -->
            <div class="col-lg-8">
                <div class="card-custom p-4 ticket-print-box">
                    <!-- Cabecera del Comprobante -->
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="fs-4 fw-bold text-dark">Restaurant Smart</span>
                            </div>
                            <small class="text-muted d-block">Módulo de Compras & Abastecimiento — INF401 Taller II</small>
                            <small class="text-muted d-block">Fecha & Hora de Emisión: <strong><?= $fechaFormateada ?></strong></small>
                        </div>
                        <div class="text-end">
                            <span class="badge <?= $isRegistrada ? 'badge-active' : 'badge-inactive' ?> fs-6 mb-1">
                                <?= htmlspecialchars($estado) ?>
                            </span>
                            <div class="font-monospace text-muted small">ID: <?= htmlspecialchars($compra['id']) ?></div>
                        </div>
                    </div>

                    <!-- Datos del Proveedor y Responsable -->
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Proveedor / Empresa</span>
                                <strong class="fs-6 text-dark d-block">
                                    <i class="bi bi-truck me-1 text-success"></i> <?= htmlspecialchars($provNombre) ?>
                                </strong>
                                <small class="text-muted d-block mt-1">Contacto: <strong><?= htmlspecialchars($provContacto) ?></strong></small>
                                <small class="text-muted d-block">Teléfono: <?= htmlspecialchars($provTel) ?></small>
                                <small class="text-muted d-block">Email: <?= htmlspecialchars($provEmail) ?></small>
                                <?php if (!empty($compra['proveedores']['direccion'])): ?>
                                    <small class="text-muted d-block">Dirección: <?= htmlspecialchars($provDir) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-sm-6">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="text-muted small d-block mb-1 text-uppercase fw-semibold">Registrado Por</span>
                                <strong class="fs-6 text-dark d-block">
                                    <i class="bi bi-person-badge me-1 text-primary"></i> <?= htmlspecialchars($usuarioNombre) ?>
                                </strong>
                                <small class="text-muted d-block mt-1">Personal Autorizado</small>
                                <?php if (!empty($usuarioEmail)): ?>
                                    <small class="text-muted d-block">Email: <?= htmlspecialchars($usuarioEmail) ?></small>
                                <?php endif; ?>
                                <small class="text-muted d-block mt-2">
                                    Estado: <span class="badge bg-success-subtle text-success border border-success">Registrada en Kardex</span>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Observaciones de la Compra -->
                    <?php if (!empty($compra['observaciones'])): ?>
                        <div class="alert alert-info py-2 px-3 mb-4">
                            <i class="bi bi-info-circle me-1"></i> <strong>Observaciones / Comprobante:</strong> <?= htmlspecialchars($compra['observaciones']) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Desglose de Productos Comprados -->
                    <h5 class="fw-bold mb-3" style="color: var(--secondary-color);">Detalle de Productos Comprados</h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;" class="text-center">Cant.</th>
                                    <th>Descripción del Producto</th>
                                    <th style="width: 140px;" class="text-end">Costo Unitario</th>
                                    <th style="width: 140px;" class="text-end">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($detalles)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted">
                                            <em>Sin desglose de productos registrado para esta compra.</em>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($detalles as $d): ?>
                                        <?php
                                        $prodNombre = $d['productos']['nombre'] ?? 'Producto Desconocido';
                                        $catNombre = $d['productos']['categorias']['nombre'] ?? 'General';
                                        $cant = (int)($d['cantidad'] ?? 1);
                                        $costoUnit = (float)($d['costo_unitario'] ?? 0);
                                        $subtotal = (float)($d['subtotal'] ?? ($cant * $costoUnit));
                                        ?>
                                        <tr>
                                            <td class="text-center fw-bold fs-6"><?= $cant ?></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($prodNombre) ?></div>
                                                <small class="badge badge-role me-1"><?= htmlspecialchars($catNombre) ?></small>
                                            </td>
                                            <td class="text-end">Bs. <?= number_format($costoUnit, 2) ?></td>
                                            <td class="text-end fw-bold text-dark">Bs. <?= number_format($subtotal, 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="3" class="text-end fw-bold fs-5">TOTAL COMPRA:</td>
                                    <td class="text-end fw-bold fs-4 text-success">Bs. <?= number_format($totalConsolidado, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="text-center border-top pt-3 text-muted small">
                        <p class="mb-0">Registro oficial de compras e inventarios — Restaurant Smart</p>
                    </div>
                </div>
            </div>

            <!-- Panel Lateral Informativo -->
            <div class="col-lg-4 no-print">
                <div class="card-custom p-4 mb-4">
                    <h5 class="fw-bold mb-3" style="color: var(--secondary-color);">
                        <i class="bi bi-info-square me-2"></i>Información de Auditoría
                    </h5>

                    <div class="mb-3">
                        <label class="text-muted small d-block">Estado de la Compra:</label>
                        <span class="badge <?= $isRegistrada ? 'badge-active' : 'badge-inactive' ?> fs-6 py-2 px-3">
                            <i class="bi <?= $isRegistrada ? 'bi-check-circle-fill' : 'bi-slash-circle' ?> me-1"></i>
                            <?= htmlspecialchars($estado) ?>
                        </span>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small d-block">Impacto en Inventario:</label>
                        <div class="p-2 border rounded bg-light small text-dark">
                            <i class="bi bi-box-seam text-success me-1"></i>
                            Stock ingresado atómicamente al inventario mediante RPC transaccional.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small d-block">Impacto en Kardex:</label>
                        <div class="p-2 border rounded bg-light small text-dark">
                            <i class="bi bi-journal-check text-primary me-1"></i>
                            Movimiento <code>COMPRA_ENTRADA</code> registrado para cada ítem.
                        </div>
                    </div>

                    <div class="alert alert-secondary small text-center mb-0">
                        <i class="bi bi-lock-fill me-1"></i> Esta compra se encuentra cerrada en estado <strong><?= htmlspecialchars($estado) ?></strong>.
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
