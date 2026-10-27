<?php
$pageTitle = 'Control de Inventario';
$currentModule = 'inventario';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Cocinero', 'Cajero']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$inventario = [];

if ($supabase->isConfigured()) {
    // 1. Consultar registros de inventario junto con la relación a productos y categorías
    $resInv = $supabase->select('inventario', '*,productos(id,nombre,precio,disponible,estado,categorias(id,nombre))', [], $authToken);

    if ($resInv['success'] && !empty($resInv['data'])) {
        $inventario = $resInv['data'];
    } else {
        // Fallback seguro en PHP si la relación anidada directa no viniera poblada
        $resInvRaw = $supabase->select('inventario', '*', [], $authToken);
        if ($resInvRaw['success'] && !empty($resInvRaw['data'])) {
            $resProdAll = $supabase->select('productos', '*,categorias(id,nombre)', [], $authToken);
            $prodMap = [];
            if ($resProdAll['success'] && !empty($resProdAll['data'])) {
                foreach ($resProdAll['data'] as $p) {
                    $prodMap[$p['id']] = $p;
                }
            }
            foreach ($resInvRaw['data'] as $item) {
                $pId = $item['producto_id'] ?? '';
                $item['productos'] = $prodMap[$pId] ?? null;
                $inventario[] = $item;
            }
        }
    }
}

// 2. Ordenar alfabéticamente por nombre de producto en PHP
usort($inventario, function ($a, $b) {
    $nomA = $a['productos']['nombre'] ?? '';
    $nomB = $b['productos']['nombre'] ?? '';
    return strcasecmp($nomA, $nomB);
});

// 3. Cálculo de Métricas y Valorización Total
$totalProductosConInventario = count($inventario);
$stockMayorCero = 0;
$stockIgualCero = 0;
$valorTotalInventario = 0.0;

foreach ($inventario as $inv) {
    $stock = (float)($inv['stock_actual'] ?? 0);
    $costo = (float)($inv['costo_unitario'] ?? 0);
    $valorStock = $stock * $costo;
    $valorTotalInventario += $valorStock;

    if ($stock > 0) {
        $stockMayorCero++;
    } else {
        $stockIgualCero++;
    }
}
$isAdmin = (($currentUser['rol_nombre'] ?? '') === 'Administrador');
?>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <?php require_once __DIR__ . '/../../includes/alerts.php'; ?>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Control & Existencias de Inventario</h3>
                <p class="text-muted mb-0">Consulta en tiempo real de stock disponible, costos y valorización de insumos</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-muted border px-3 py-2">
                    <i class="bi bi-clock-history me-1"></i> Actualizado: <?= date('d/m/Y H:i') ?>
                </span>
                <?php if ($isAdmin): ?>
                    <a href="<?= BASE_URL ?>/modules/inventario/movement.php" class="btn btn-primary-custom btn-sm">
                        <i class="bi bi-arrow-left-right me-1"></i> Registrar Movimiento
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tarjetas de Métricas Operativas -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-muted small d-block mb-1">Productos en Inventario</span>
                    <h4 class="fw-bold text-dark m-0"><?= $totalProductosConInventario ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Ítems registrados</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-success small d-block mb-1"><i class="bi bi-check-circle me-1"></i>Stock Disponible (> 0)</span>
                    <h4 class="fw-bold text-success m-0"><?= $stockMayorCero ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Con existencias</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-secondary small d-block mb-1"><i class="bi bi-dash-circle me-1"></i>Stock Cero (= 0)</span>
                    <h4 class="fw-bold text-secondary m-0"><?= $stockIgualCero ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Agotados</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-primary small d-block mb-1"><i class="bi bi-cash-stack me-1"></i>Valor Total Inventario</span>
                    <h4 class="fw-bold text-primary m-0">Bs. <?= number_format($valorTotalInventario, 2) ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Total valorizado</small>
                </div>
            </div>
        </div>

        <!-- Buscador -->
        <div class="card-custom p-3 mb-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Buscar por producto, categoría o estado..." data-table-search="table-inventario">
                    </div>
                </div>
                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                    <span class="text-muted small">Total registros: <strong><?= $totalProductosConInventario ?></strong></span>
                </div>
            </div>
        </div>

        <!-- Tabla de Inventario -->
        <div class="table-custom-wrapper">
            <table class="table table-custom mb-0" id="table-inventario">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th class="text-center">Stock Actual</th>
                        <th class="text-end">Costo Unitario</th>
                        <th class="text-end">Valor del Stock</th>
                        <th>Estado del Producto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventario)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-box-seam fs-1 d-block mb-2 text-secondary"></i>
                                No hay registros de inventario disponibles.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($inventario as $item): ?>
                            <?php
                            $prod = $item['productos'] ?? [];
                            $prodNombre = !empty($prod['nombre']) ? $prod['nombre'] : 'Producto No Identificado';
                            $catNombre = !empty($prod['categorias']['nombre']) ? $prod['categorias']['nombre'] : 'General';
                            $isProdActivo = (bool)($prod['estado'] ?? true);
                            
                            $stockActual = (float)($item['stock_actual'] ?? 0);
                            $costoUnitario = (float)($item['costo_unitario'] ?? 0);
                            $valorStockFila = $stockActual * $costoUnitario;
                            ?>
                            <tr class="<?= ($stockActual <= 0) ? 'table-light opacity-75' : '' ?>">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-circle" style="width: 34px; height: 34px; font-size: 0.85rem; background: <?= ($stockActual > 0) ? 'linear-gradient(135deg, #1d3557, #457b9d)' : '#6c757d' ?>;">
                                            <i class="bi bi-box"></i>
                                        </div>
                                        <div>
                                            <strong class="d-block text-dark"><?= htmlspecialchars($prodNombre) ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-role"><?= htmlspecialchars($catNombre) ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($stockActual > 0): ?>
                                        <span class="badge bg-success-subtle text-success border border-success px-3 py-2 fs-6 fw-bold">
                                            <?= (int)$stockActual == $stockActual ? (int)$stockActual : number_format($stockActual, 2) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary px-3 py-2 fs-6">
                                            0
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-dark">
                                    Bs. <?= number_format($costoUnitario, 2) ?>
                                </td>
                                <td class="text-end fw-bold text-dark fs-6">
                                    Bs. <?= number_format($valorStockFila, 2) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $isProdActivo ? 'badge-active' : 'badge-inactive' ?>">
                                        <?= $isProdActivo ? 'Activo' : 'Inactivo' ?>
                                    </span>
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
