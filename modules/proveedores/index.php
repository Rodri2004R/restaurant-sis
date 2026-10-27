<?php
$pageTitle = 'Gestión de Proveedores';
$currentModule = 'proveedores';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$proveedores = [];

if ($supabase->isConfigured()) {
    $res = $supabase->select('proveedores', '*', ['order' => 'created_at.desc'], $authToken);
    if ($res['success'] && !empty($res['data'])) {
        $proveedores = $res['data'];
    }
}

$totalProveedores = count($proveedores);
$activosCount = 0;
$inactivosCount = 0;

foreach ($proveedores as $p) {
    if ($p['estado'] ?? true) {
        $activosCount++;
    } else {
        $inactivosCount++;
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
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Gestión de Proveedores</h3>
                <p class="text-muted mb-0">Directorio de empresas y contactos para abastecimiento e insumos</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/modules/proveedores/create.php" class="btn btn-primary-custom">
                    <i class="bi bi-plus-circle me-2"></i> Nuevo Proveedor
                </a>
            </div>
        </div>

        <!-- Tarjetas de Resumen Operativo -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-4">
                <div class="card-custom p-3 text-center">
                    <span class="text-muted small d-block mb-1">Total Proveedores</span>
                    <h3 class="fw-bold text-dark m-0"><?= $totalProveedores ?></h3>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="card-custom p-3 text-center">
                    <span class="text-success small d-block mb-1"><i class="bi bi-check-circle me-1"></i>Activos</span>
                    <h3 class="fw-bold text-success m-0"><?= $activosCount ?></h3>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="card-custom p-3 text-center">
                    <span class="text-secondary small d-block mb-1"><i class="bi bi-slash-circle me-1"></i>Inactivos</span>
                    <h3 class="fw-bold text-secondary m-0"><?= $inactivosCount ?></h3>
                </div>
            </div>
        </div>

        <!-- Buscador -->
        <div class="card-custom p-3 mb-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Buscar por nombre, contacto, teléfono, email o dirección..." data-table-search="table-proveedores">
                    </div>
                </div>
                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                    <span class="text-muted small">Mostrando <strong><?= $totalProveedores ?></strong> registros</span>
                </div>
            </div>
        </div>

        <!-- Tabla de Proveedores -->
        <div class="table-custom-wrapper">
            <table class="table table-custom mb-0" id="table-proveedores">
                <thead>
                    <tr>
                        <th>Proveedor / Empresa</th>
                        <th>Contacto</th>
                        <th>Teléfono</th>
                        <th>Email</th>
                        <th>Dirección</th>
                        <th>Estado</th>
                        <th>Fecha Registro</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($proveedores)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-truck fs-1 d-block mb-2 text-secondary"></i>
                                No hay proveedores registrados en el sistema.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($proveedores as $prov): ?>
                            <?php $isActivo = (bool)($prov['estado'] ?? true); ?>
                            <tr class="<?= !$isActivo ? 'table-light opacity-75' : '' ?>">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-circle" style="width: 36px; height: 36px; font-size: 0.85rem; background: <?= $isActivo ? 'linear-gradient(135deg, #2b9348, #55a630)' : '#adb5bd' ?>;">
                                            <?= strtoupper(substr($prov['nombre'] ?? 'P', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <strong class="d-block text-dark"><?= htmlspecialchars($prov['nombre'] ?? 'Sin Nombre') ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?= !empty($prov['contacto']) ? htmlspecialchars($prov['contacto']) : '<span class="text-muted">-</span>' ?>
                                </td>
                                <td>
                                    <?= !empty($prov['telefono']) ? htmlspecialchars($prov['telefono']) : '<span class="text-muted">-</span>' ?>
                                </td>
                                <td>
                                    <?= !empty($prov['email']) ? htmlspecialchars($prov['email']) : '<span class="text-muted">-</span>' ?>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= !empty($prov['direccion']) ? htmlspecialchars($prov['direccion']) : '-' ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge <?= $isActivo ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= $isActivo ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted"><?= !empty($prov['created_at']) ? date('d/m/Y', strtotime($prov['created_at'])) : '-' ?></small>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 align-items-center">
                                        <a href="<?= BASE_URL ?>/modules/proveedores/edit.php?id=<?= $prov['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Editar Proveedor">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>

                                        <!-- Desactivación / Activación vía POST -->
                                        <form action="<?= BASE_URL ?>/modules/proveedores/toggle_status.php" method="POST" class="d-inline">
                                            <input type="hidden" name="id" value="<?= htmlspecialchars($prov['id']) ?>">
                                            <button type="submit" 
                                                    class="btn btn-sm <?= $isActivo ? 'btn-outline-danger' : 'btn-outline-success' ?>" 
                                                    data-confirm="¿Está seguro de <?= $isActivo ? 'desactivar' : 'activar' ?> al proveedor &quot;<?= htmlspecialchars($prov['nombre'] ?? '') ?>&quot;?"
                                                    title="<?= $isActivo ? 'Desactivar Proveedor' : 'Activar Proveedor' ?>">
                                                <i class="bi <?= $isActivo ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                                            </button>
                                        </form>
                                    </div>
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
