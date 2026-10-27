<?php
$pageTitle = 'Historial de Kardex & Movimientos';
$currentModule = 'kardex';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;

$productos = [];
$kardex = [];

$tipoNombres = [
    'entrada_compra' => 'Entrada por compra',
    'salida_venta' => 'Salida por venta',
    'ajuste_entrada' => 'Ajuste de entrada',
    'ajuste_salida' => 'Ajuste de salida',
    'devolucion_pedido' => 'Devolución de pedido',
    'devolucion_proveedor' => 'Devolución a proveedor',
    'merma' => 'Merma',
    'consumo' => 'Consumo'
];

$entradasTipos = ['entrada_compra', 'ajuste_entrada', 'devolucion_pedido'];
$salidasTipos = ['salida_venta', 'ajuste_salida', 'devolucion_proveedor', 'merma', 'consumo'];

// 1. Cargar productos para el selector de filtro
if ($supabase->isConfigured()) {
    $resProds = $supabase->select('productos', 'id,nombre', [
        'order' => 'nombre.asc'
    ], $authToken);
    if ($resProds['success'] && !empty($resProds['data'])) {
        $productos = $resProds['data'];
    }
}

// 2. Procesar filtros GET
$filtroProducto = clean_input($_GET['producto_id'] ?? '');
$filtroTipo = clean_input($_GET['tipo_movimiento'] ?? '');
$filtroDireccion = clean_input($_GET['direccion'] ?? '');

$params = ['order' => 'created_at.desc'];

if (!empty($filtroProducto) && is_valid_uuid($filtroProducto)) {
    $params['producto_id'] = 'eq.' . $filtroProducto;
}

if (!empty($filtroTipo) && isset($tipoNombres[$filtroTipo])) {
    $params['tipo_movimiento'] = 'eq.' . $filtroTipo;
} elseif (!empty($filtroDireccion)) {
    if ($filtroDireccion === 'entrada') {
        $params['tipo_movimiento'] = 'in.(' . implode(',', $entradasTipos) . ')';
    } elseif ($filtroDireccion === 'salida') {
        $params['tipo_movimiento'] = 'in.(' . implode(',', $salidasTipos) . ')';
    }
}

// 3. Consultar movimientos de Kardex con PostgREST
if ($supabase->isConfigured()) {
    $resKardex = $supabase->select('kardex', '*,productos(id,nombre),perfiles(id,nombre,apellido),compras(id,total),pedidos(id,total)', $params, $authToken);

    if ($resKardex['success'] && !empty($resKardex['data'])) {
        $kardex = $resKardex['data'];
    } else {
        // Fallback seguro en PHP si las relaciones anidadas requieren mapeo manual
        $resKardexRaw = $supabase->select('kardex', '*', $params, $authToken);
        if ($resKardexRaw['success'] && !empty($resKardexRaw['data'])) {
            $pMap = [];
            foreach ($productos as $pr) {
                $pMap[$pr['id']] = $pr;
            }

            $resPerfiles = $supabase->select('perfiles', 'id,nombre,apellido', [], $authToken);
            $perfMap = [];
            if ($resPerfiles['success'] && !empty($resPerfiles['data'])) {
                foreach ($resPerfiles['data'] as $pf) {
                    $perfMap[$pf['id']] = $pf;
                }
            }

            foreach ($resKardexRaw['data'] as $mov) {
                $pId = $mov['producto_id'] ?? '';
                $uId = $mov['usuario_id'] ?? '';
                $mov['productos'] = $pMap[$pId] ?? null;
                $mov['perfiles'] = $perfMap[$uId] ?? null;
                $kardex[] = $mov;
            }
        }
    }
}

// 4. Cálculo de métricas operativas
$totalMovimientos = count($kardex);
$totalEntradas = 0;
$totalSalidas = 0;
$ultimoMovimiento = !empty($kardex) && !empty($kardex[0]['created_at']) 
    ? date('d/m/Y H:i', strtotime($kardex[0]['created_at'])) 
    : 'Sin movimientos';

foreach ($kardex as $m) {
    $tipo = $m['tipo_movimiento'] ?? '';
    if (in_array($tipo, $entradasTipos, true)) {
        $totalEntradas++;
    } elseif (in_array($tipo, $salidasTipos, true)) {
        $totalSalidas++;
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
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Historial & Trazabilidad de Kardex</h3>
                <p class="text-muted mb-0">Auditoría cronológica de entradas, salidas, costos y saldos de inventario</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/modules/inventario/" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-box-seam me-1"></i> Ver Existencias en Inventario
                </a>
            </div>
        </div>

        <!-- Tarjetas de Resumen Operativo -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-muted small d-block mb-1">Movimientos Registrados</span>
                    <h4 class="fw-bold text-dark m-0"><?= $totalMovimientos ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Total de transacciones</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-success small d-block mb-1"><i class="bi bi-arrow-down-left-circle me-1"></i>Entradas</span>
                    <h4 class="fw-bold text-success m-0"><?= $totalEntradas ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Compras y devoluciones</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-danger small d-block mb-1"><i class="bi bi-arrow-up-right-circle me-1"></i>Salidas</span>
                    <h4 class="fw-bold text-danger m-0"><?= $totalSalidas ?></h4>
                    <small class="text-muted" style="font-size: 0.75rem;">Ventas, mermas y consumos</small>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="card-custom p-3 text-center">
                    <span class="text-primary small d-block mb-1"><i class="bi bi-clock-history me-1"></i>Último Movimiento</span>
                    <h6 class="fw-bold text-dark m-0 mt-1"><?= htmlspecialchars($ultimoMovimiento) ?></h6>
                    <small class="text-muted" style="font-size: 0.75rem;">Actividad más reciente</small>
                </div>
            </div>
        </div>

        <!-- Panel de Filtros -->
        <div class="card-custom p-3 mb-4">
            <form action="" method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Filtrar por Producto</label>
                    <select name="producto_id" class="form-select form-select-sm">
                        <option value="">-- Todos los Productos --</option>
                        <?php foreach ($productos as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($filtroProducto === $p['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Tipo de Movimiento</label>
                    <select name="tipo_movimiento" class="form-select form-select-sm">
                        <option value="">-- Todos los Tipos --</option>
                        <?php foreach ($tipoNombres as $clave => $etiqueta): ?>
                            <option value="<?= $clave ?>" <?= ($filtroTipo === $clave) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($etiqueta) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-semibold small">Dirección</label>
                    <select name="direccion" class="form-select form-select-sm">
                        <option value="">-- Todas --</option>
                        <option value="entrada" <?= ($filtroDireccion === 'entrada') ? 'selected' : '' ?>>Entradas (+)</option>
                        <option value="salida" <?= ($filtroDireccion === 'salida') ? 'selected' : '' ?>>Salidas (-)</option>
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary-custom btn-sm flex-fill">
                        <i class="bi bi-filter me-1"></i> Aplicar Filtros
                    </button>
                    <a href="<?= BASE_URL ?>/modules/kardex/" class="btn btn-outline-secondary btn-sm" title="Limpiar Filtros">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Buscador de Texto Rápido -->
        <div class="card-custom p-3 mb-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Buscar por producto, tipo, motivo, usuario o referencia..." data-table-search="table-kardex">
                    </div>
                </div>
                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                    <span class="text-muted small">Mostrando <strong><?= $totalMovimientos ?></strong> registros</span>
                </div>
            </div>
        </div>

        <!-- Tabla de Movimientos Kardex -->
        <div class="table-custom-wrapper">
            <table class="table table-custom mb-0" id="table-kardex">
                <thead>
                    <tr>
                        <th>Fecha & Hora</th>
                        <th>Producto</th>
                        <th>Tipo de Movimiento</th>
                        <th class="text-center">Cantidad</th>
                        <th class="text-center">Stock Ant.</th>
                        <th class="text-center">Stock Post.</th>
                        <th class="text-end">Costo Unit.</th>
                        <th>Motivo / Glosa</th>
                        <th>Usuario</th>
                        <th>Referencia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($kardex)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary"></i>
                                No se encontraron movimientos de Kardex para los criterios seleccionados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($kardex as $item): ?>
                            <?php
                            $tipoMov = $item['tipo_movimiento'] ?? '';
                            $nombreTipo = $tipoNombres[$tipoMov] ?? ucfirst(str_replace('_', ' ', $tipoMov));
                            $isEntrada = in_array($tipoMov, $entradasTipos, true);
                            
                            $prodNom = !empty($item['productos']['nombre']) ? $item['productos']['nombre'] : 'Producto No Identificado';
                            $cantNum = (float)($item['cantidad'] ?? 0);
                            $cantFormateada = ((int)$cantNum == $cantNum) ? (int)$cantNum : number_format($cantNum, 2);
                            
                            $stAnt = (float)($item['stock_anterior'] ?? 0);
                            $stPost = (float)($item['stock_posterior'] ?? 0);
                            $costoUnit = (float)($item['costo_unitario'] ?? 0);
                            $motivo = !empty($item['motivo']) ? $item['motivo'] : (!empty($item['observaciones']) ? $item['observaciones'] : '-');
                            
                            $usuarioNom = !empty($item['perfiles']) 
                                ? trim(($item['perfiles']['nombre'] ?? '') . ' ' . ($item['perfiles']['apellido'] ?? '')) 
                                : 'Personal';

                            $compraId = $item['compra_id'] ?? null;
                            $pedidoId = $item['pedido_id'] ?? null;
                            $fechaFormateada = !empty($item['created_at']) 
                                ? date('d/m/Y H:i', strtotime($item['created_at'])) 
                                : '-';
                            ?>
                            <tr>
                                <td>
                                    <span class="text-dark small"><i class="bi bi-calendar3 me-1 text-muted"></i><?= $fechaFormateada ?></span>
                                </td>
                                <td>
                                    <strong class="text-dark"><?= htmlspecialchars($prodNom) ?></strong>
                                </td>
                                <td>
                                    <?php if ($isEntrada): ?>
                                        <span class="badge bg-success-subtle text-success border border-success">
                                            <i class="bi bi-arrow-down-left-circle-fill me-1"></i> <?= htmlspecialchars($nombreTipo) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger">
                                            <i class="bi bi-arrow-up-right-circle-fill me-1"></i> <?= htmlspecialchars($nombreTipo) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($isEntrada): ?>
                                        <span class="badge bg-success-subtle text-success border border-success fw-bold fs-6">
                                            +<?= $cantFormateada ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger fw-bold fs-6">
                                            -<?= $cantFormateada ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center text-muted">
                                    <?= ((int)$stAnt == $stAnt) ? (int)$stAnt : number_format($stAnt, 2) ?>
                                </td>
                                <td class="text-center fw-bold text-dark">
                                    <?= ((int)$stPost == $stPost) ? (int)$stPost : number_format($stPost, 2) ?>
                                </td>
                                <td class="text-end text-dark">
                                    Bs. <?= number_format($costoUnit, 2) ?>
                                </td>
                                <td>
                                    <small class="text-muted"><?= htmlspecialchars($motivo) ?></small>
                                </td>
                                <td>
                                    <small class="text-muted"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($usuarioNom) ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($compraId) && is_valid_uuid($compraId)): ?>
                                        <a href="<?= BASE_URL ?>/modules/compras/view.php?id=<?= $compraId ?>" class="btn btn-sm btn-outline-info py-0 px-2 font-monospace" title="Ver Comprobante de Compra">
                                            <i class="bi bi-bag-check me-1"></i>Compra #<?= substr($compraId, 0, 8) ?>
                                        </a>
                                    <?php elseif (!empty($pedidoId) && is_valid_uuid($pedidoId)): ?>
                                        <a href="<?= BASE_URL ?>/modules/pedidos/view.php?id=<?= $pedidoId ?>" class="btn btn-sm btn-outline-primary py-0 px-2 font-monospace" title="Ver Comanda de Pedido">
                                            <i class="bi bi-receipt me-1"></i>Comanda #<?= substr($pedidoId, 0, 8) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
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
