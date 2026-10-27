<?php
$pageTitle = 'Gestión de Categorías';
$currentModule = 'categorias';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$categorias = [];
$productCounts = [];

if ($supabase->isConfigured()) {
    $res = $supabase->select('categorias', '*', ['order' => 'nombre.asc'], $authToken);
    if ($res['success']) {
        $categorias = $res['data'];
    }

    // Contabilizar productos por categoría
    $resProd = $supabase->select('productos', 'id,categoria_id', [], $authToken);
    if ($resProd['success'] && !empty($resProd['data'])) {
        foreach ($resProd['data'] as $p) {
            $catId = $p['categoria_id'] ?? '';
            if ($catId) {
                $productCounts[$catId] = ($productCounts[$catId] ?? 0) + 1;
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
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Gestión de Categorías</h3>
                <p class="text-muted mb-0">Organización y clasificación del menú y productos del restaurante</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/modules/categorias/create.php" class="btn btn-primary-custom">
                    <i class="bi bi-tag-fill me-2"></i> Registrar Nueva Categoría
                </a>
            </div>
        </div>

        <!-- Buscador -->
        <div class="card-custom p-3 mb-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Buscar categoría por nombre o descripción..." data-table-search="table-categorias">
                    </div>
                </div>
                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                    <span class="text-muted small">Total Categorías: <strong><?= count($categorias) ?></strong></span>
                </div>
            </div>
        </div>

        <!-- Tabla de Categorías -->
        <div class="table-custom-wrapper">
            <table class="table table-custom mb-0" id="table-categorias">
                <thead>
                    <tr>
                        <th>Categoría</th>
                        <th>Descripción</th>
                        <th>Productos Asociados</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categorias)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="bi bi-tags fs-1 d-block mb-2 text-secondary"></i>
                                No hay categorías registradas en el sistema.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categorias as $cat): ?>
                            <?php 
                            $cantProductos = $productCounts[$cat['id']] ?? 0;
                            $esActivo = ($cat['estado'] ?? true);
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar-circle" style="width: 32px; height: 32px; font-size: 0.8rem; background: rgba(255, 107, 53, 0.15); color: var(--primary-color);">
                                            <i class="bi bi-bookmark-fill"></i>
                                        </span>
                                        <strong class="text-dark"><?= htmlspecialchars($cat['nombre']) ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-muted"><?= htmlspecialchars($cat['descripcion'] ?: 'Sin descripción') ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-box-seam me-1 text-primary"></i> <?= $cantProductos ?> platillo(s)
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $esActivo ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= $esActivo ? 'Activa' : 'Inactiva' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= BASE_URL ?>/modules/categorias/edit.php?id=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-secondary me-1" title="Editar">
                                        <i class="bi bi-pencil-fill"></i>
                                    </a>
                                    <a href="<?= BASE_URL ?>/modules/categorias/toggle_status.php?id=<?= $cat['id'] ?>&status=<?= $esActivo ? '0' : '1' ?>" 
                                       class="btn btn-sm <?= $esActivo ? 'btn-outline-danger' : 'btn-outline-success' ?>" 
                                       data-confirm="¿Está seguro de <?= $esActivo ? 'desactivar' : 'activar' ?> esta categoría?"
                                       title="<?= $esActivo ? 'Desactivar' : 'Activar' ?>">
                                        <i class="bi <?= $esActivo ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
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
