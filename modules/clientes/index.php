<?php
$pageTitle = 'Gestión de Clientes';
$currentModule = 'clientes';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Recepcionista']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$clientes = [];

if ($supabase->isConfigured()) {
    $res = $supabase->select('clientes', '*', ['order' => 'created_at.desc']);
    if ($res['success']) {
        $clientes = $res['data'];
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
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Gestión de Clientes (US-004)</h3>
                <p class="text-muted mb-0">Registrar, buscar y gestionar el directorio de clientes del restaurante</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/modules/clientes/create.php" class="btn btn-primary-custom">
                    <i class="bi bi-person-badge me-2"></i> Registrar Cliente
                </a>
            </div>
        </div>

        <!-- Buscador -->
        <div class="card-custom p-3 mb-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Buscar cliente por documento, nombre o correo..." data-table-search="table-clientes">
                    </div>
                </div>
                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                    <span class="text-muted small">Total Clientes: <strong><?= count($clientes) ?></strong></span>
                </div>
            </div>
        </div>

        <!-- Tabla de Clientes -->
        <div class="table-custom-wrapper">
            <table class="table table-custom mb-0" id="table-clientes">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Documento (CI / NIT)</th>
                        <th>Teléfono</th>
                        <th>Email</th>
                        <th>Estado</th>
                        <th>Fecha Registro</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clientes)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-people fs-1 d-block mb-2 text-secondary"></i>
                                No hay clientes registrados en la base de datos.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clientes as $cli): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="avatar-circle" style="width: 36px; height: 36px; font-size: 0.85rem; background: linear-gradient(135deg, #ff6b35, #ff9f1c);">
                                            <?= strtoupper(substr($cli['nombre'] ?? 'C', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <strong class="d-block text-dark"><?= htmlspecialchars(($cli['nombre'] ?? '') . ' ' . ($cli['apellido'] ?? '')) ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($cli['documento'] ?? '-') ?></span></td>
                                <td><?= htmlspecialchars($cli['telefono'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($cli['email'] ?? '-') ?></td>
                                <td>
                                    <span class="badge <?= ($cli['estado'] ?? true) ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= ($cli['estado'] ?? true) ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                                <td><?= date('d/m/Y', strtotime($cli['created_at'] ?? 'now')) ?></td>
                                <td class="text-end">
                                    <a href="<?= BASE_URL ?>/modules/clientes/edit.php?id=<?= $cli['id'] ?>" class="btn btn-sm btn-outline-secondary me-1" title="Editar">
                                        <i class="bi bi-pencil-fill"></i>
                                    </a>
                                    <a href="<?= BASE_URL ?>/modules/clientes/toggle_status.php?id=<?= $cli['id'] ?>&status=<?= ($cli['estado'] ?? true) ? '0' : '1' ?>" 
                                       class="btn btn-sm <?= ($cli['estado'] ?? true) ? 'btn-outline-danger' : 'btn-outline-success' ?>" 
                                       data-confirm="¿Desea cambiar el estado de este cliente?"
                                       title="<?= ($cli['estado'] ?? true) ? 'Desactivar' : 'Activar' ?>">
                                        <i class="bi <?= ($cli['estado'] ?? true) ? 'bi-person-x' : 'bi-person-check' ?>"></i>
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
