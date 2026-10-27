<?php
$pageTitle = 'Menú & Catálogo de Productos';
$currentModule = 'productos';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Mesero', 'Cocinero', 'Cajero', 'Recepcionista', 'Cliente']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$productos = [];
$categorias = [];

// Filtros GET
$filtroCategoria = clean_input($_GET['categoria_id'] ?? '');
$filtroDisponible = clean_input($_GET['disponible'] ?? '');
$filtroEstado = clean_input($_GET['estado'] ?? '');

if ($supabase->isConfigured()) {
    // 1. Obtener categorías activas para el filtro
    $resCat = $supabase->select('categorias', '*', ['order' => 'nombre.asc'], $authToken);
    if ($resCat['success']) {
        $categorias = $resCat['data'];
    }

    // 2. Construir parámetros para consulta de productos
    $params = ['order' => 'nombre.asc'];
    if (!empty($filtroCategoria) && is_valid_uuid($filtroCategoria)) {
        $params['categoria_id'] = 'eq.' . $filtroCategoria;
    }
    if ($filtroDisponible !== '') {
        $params['disponible'] = 'eq.' . ($filtroDisponible === '1' ? 'true' : 'false');
    }
    if ($filtroEstado !== '') {
        $params['estado'] = 'eq.' . ($filtroEstado === '1' ? 'true' : 'false');
    }

    $resProd = $supabase->select('productos', '*,categorias(id,nombre)', $params, $authToken);
    if ($resProd['success']) {
        $productos = $resProd['data'];
    }
}

$isAdmin = ($currentUser['rol_nombre'] ?? '') === 'Administrador';
?>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <?php require_once __DIR__ . '/../../includes/alerts.php'; ?>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Menú & Catálogo de Productos</h3>
                <p class="text-muted mb-0">Gestión de platillos, bebidas, precios y disponibilidad en cocina</p>
            </div>
            <?php if ($isAdmin): ?>
                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>/modules/categorias/" class="btn btn-outline-secondary">
                        <i class="bi bi-tags me-1"></i> Categorías
                    </a>
                    <a href="<?= BASE_URL ?>/modules/productos/create.php" class="btn btn-primary-custom">
                        <i class="bi bi-plus-circle me-1"></i> Registrar Producto
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filtros y Buscador -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" action="" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Buscar por nombre o descripción..." data-table-search="table-productos">
                    </div>
                </div>

                <div class="col-md-3">
                    <select name="categoria_id" class="form-select bg-light" onchange="this.form.submit()">
                        <option value="">-- Todas las Categorías --</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($filtroCategoria === $cat['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <select name="disponible" class="form-select bg-light" onchange="this.form.submit()">
                        <option value="">-- Disponibilidad --</option>
                        <option value="1" <?= ($filtroDisponible === '1') ? 'selected' : '' ?>>Disponible</option>
                        <option value="0" <?= ($filtroDisponible === '0') ? 'selected' : '' ?>>Agotado</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <select name="estado" class="form-select bg-light" onchange="this.form.submit()">
                        <option value="">-- Estado --</option>
                        <option value="1" <?= ($filtroEstado === '1') ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= ($filtroEstado === '0') ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>

                <div class="col-md-1 text-end">
                    <?php if ($filtroCategoria !== '' || $filtroDisponible !== '' || $filtroEstado !== ''): ?>
                        <a href="<?= BASE_URL ?>/modules/productos/" class="btn btn-outline-secondary w-100" title="Limpiar Filtros">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    <?php else: ?>
                        <span class="text-muted small"><strong><?= count($productos) ?></strong> ítems</span>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Tabla de Productos -->
        <div class="table-custom-wrapper">
            <table class="table table-custom mb-0" id="table-productos">
                <thead>
                    <tr>
                        <th>Platillo / Producto</th>
                        <th>Categoría</th>
                        <th>Precio Oficial</th>
                        <th>Disponibilidad</th>
                        <th>Estado</th>
                        <?php if ($isAdmin): ?>
                            <th class="text-end">Acciones</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productos)): ?>
                        <tr>
                            <td colspan="<?= $isAdmin ? 6 : 5 ?>" class="text-center py-5 text-muted">
                                <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary"></i>
                                No hay productos registrados que coincidan con los criterios.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productos as $prod): ?>
                            <?php
                            $catNombre = $prod['categorias']['nombre'] ?? 'Sin Categoría';
                            $precio = (float)($prod['precio'] ?? 0);
                            $disponible = (bool)($prod['disponible'] ?? true);
                            $estado = (bool)($prod['estado'] ?? true);
                            ?>
                            <tr>
                                <td>
                                    <strong class="d-block text-dark"><?= htmlspecialchars($prod['nombre']) ?></strong>
                                    <small class="text-muted"><?= htmlspecialchars($prod['descripcion'] ?: 'Sin descripción') ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-role">
                                        <i class="bi bi-tag me-1"></i><?= htmlspecialchars($catNombre) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success fs-6">
                                        Bs. <?= number_format($precio, 2) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($disponible): ?>
                                        <span class="badge bg-success-subtle text-success border border-success">
                                            <i class="bi bi-check-circle me-1"></i> Disponible
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning">
                                            <i class="bi bi-slash-circle me-1"></i> Agotado
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $estado ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= $estado ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                                <?php if ($isAdmin): ?>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>/modules/productos/edit.php?id=<?= $prod['id'] ?>" class="btn btn-sm btn-outline-secondary me-1" title="Editar Producto">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/productos/toggle_status.php?id=<?= $prod['id'] ?>&type=disponible&status=<?= $disponible ? '0' : '1' ?>" 
                                           class="btn btn-sm <?= $disponible ? 'btn-outline-warning' : 'btn-outline-success' ?> me-1" 
                                           title="<?= $disponible ? 'Marcar como Agotado' : 'Marcar como Disponible' ?>">
                                            <i class="bi <?= $disponible ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/productos/toggle_status.php?id=<?= $prod['id'] ?>&type=estado&status=<?= $estado ? '0' : '1' ?>" 
                                           class="btn btn-sm <?= $estado ? 'btn-outline-danger' : 'btn-outline-success' ?> me-1" 
                                           title="<?= $estado ? 'Desactivar Producto' : 'Activar Producto' ?>">
                                            <i class="bi <?= $estado ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                        </a>
                                        <a href="<?= BASE_URL ?>/modules/productos/delete.php?id=<?= $prod['id'] ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           data-confirm="¿Desea eliminar este producto? Si tiene pedidos históricos, solo se podrá desactivar."
                                           title="Eliminar Producto">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
