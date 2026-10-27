<?php
$pageTitle = 'Gestión de Usuarios';
$currentModule = 'usuarios';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$usuarios = [];

if ($supabase->isConfigured()) {
    $res = $supabase->select('perfiles', '*,roles(id,nombre)', [], $_SESSION['user']['access_token'] ?? null);
    if ($res['success']) {
        $usuarios = $res['data'];
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
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Gestión de Usuarios (US-002)</h3>
                <p class="text-muted mb-0">Registrar, editar, activar/desactivar y controlar acceso de usuarios</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/modules/usuarios/create.php" class="btn btn-primary-custom">
                    <i class="bi bi-person-plus-fill me-2"></i> Registrar Nuevo Usuario
                </a>
            </div>
        </div>

        <!-- Buscador -->
        <div class="card-custom p-3 mb-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Buscar por nombre, email, CI o rol..." data-table-search="table-usuarios">
                    </div>
                </div>
                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                    <span class="text-muted small">Total Registrados: <strong><?= count($usuarios) ?></strong></span>
                </div>
            </div>
        </div>

        <!-- Tabla de Usuarios -->
        <div class="table-custom-wrapper">
            <table class="table table-custom mb-0" id="table-usuarios">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Documento (CI)</th>
                        <th>Teléfono</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-person-x fs-1 d-block mb-2 text-secondary"></i>
                                No hay usuarios registrados o no se ha conectado Supabase.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $usr): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="avatar-circle" style="width: 36px; height: 36px; font-size: 0.85rem;">
                                            <?= strtoupper(substr($usr['nombre'] ?? 'U', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <strong class="d-block text-dark"><?= htmlspecialchars(($usr['nombre'] ?? '') . ' ' . ($usr['apellido'] ?? '')) ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($usr['email'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($usr['ci'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($usr['telefono'] ?? '-') ?></td>
                                <td>
                                    <span class="badge badge-role">
                                        <?= htmlspecialchars($usr['roles']['nombre'] ?? 'Sin rol') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= ($usr['estado'] ?? true) ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= ($usr['estado'] ?? true) ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= BASE_URL ?>/modules/usuarios/edit.php?id=<?= $usr['id'] ?>" class="btn btn-sm btn-outline-secondary me-1" title="Editar">
                                        <i class="bi bi-pencil-fill"></i>
                                    </a>
                                    <a href="<?= BASE_URL ?>/modules/usuarios/toggle_status.php?id=<?= $usr['id'] ?>&status=<?= ($usr['estado'] ?? true) ? '0' : '1' ?>" 
                                       class="btn btn-sm <?= ($usr['estado'] ?? true) ? 'btn-outline-danger' : 'btn-outline-success' ?>" 
                                       data-confirm="¿Está seguro de cambiar el estado de este usuario?"
                                       title="<?= ($usr['estado'] ?? true) ? 'Desactivar' : 'Activar' ?>">
                                        <i class="bi <?= ($usr['estado'] ?? true) ? 'bi-person-x-fill' : 'bi-person-check-fill' ?>"></i>
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
