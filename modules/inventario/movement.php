<?php
$pageTitle = 'Registrar Movimiento de Inventario';
$currentModule = 'movimiento_inventario';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;

$productos = [];
$invMap = [];
$compras = [];
$detallesCompraMap = [];
$error = null;

// Preservar valores seleccionados en caso de error
$selectedProductoId = $_POST['producto_id'] ?? ($_GET['producto_id'] ?? '');
$selectedTipoMovimiento = $_POST['tipo_movimiento'] ?? '';
$selectedCantidad = $_POST['cantidad'] ?? '';
$selectedMotivo = $_POST['motivo'] ?? '';
$selectedCompraId = $_POST['compra_id'] ?? '';

if ($supabase->isConfigured()) {
    // 1. Cargar productos activos ordenados alfabéticamente
    $resProd = $supabase->select('productos', 'id,nombre,precio,disponible,estado,categorias(id,nombre)', [
        'estado' => 'eq.true',
        'order' => 'nombre.asc'
    ], $authToken);
    if ($resProd['success'] && !empty($resProd['data'])) {
        $productos = $resProd['data'];
    }

    // 2. Cargar datos de inventario (stock actual y costo unitario de compra)
    $resInv = $supabase->select('inventario', 'producto_id,stock_actual,costo_unitario', [], $authToken);
    if ($resInv['success'] && !empty($resInv['data'])) {
        foreach ($resInv['data'] as $inv) {
            $invMap[$inv['producto_id']] = [
                'stock_actual' => (int)($inv['stock_actual'] ?? 0),
                'costo_unitario' => (float)($inv['costo_unitario'] ?? 0.00)
            ];
        }
    }

    // 3. Cargar compras activas con estado 'Registrada' para posibles devoluciones
    $resCompras = $supabase->select('compras', 'id,total,estado,observaciones,created_at,proveedores(id,nombre)', [
        'estado' => 'eq.Registrada',
        'order' => 'created_at.desc'
    ], $authToken);
    if ($resCompras['success'] && !empty($resCompras['data'])) {
        $compras = $resCompras['data'];
    }

    // 4. Cargar detalles de compras para enriquecer la selección de devoluciones en el cliente
    $resDetalles = $supabase->select('detalles_compra', 'id,compra_id,producto_id,cantidad,costo_unitario,productos(id,nombre)', [], $authToken);
    if ($resDetalles['success'] && !empty($resDetalles['data'])) {
        foreach ($resDetalles['data'] as $det) {
            $cId = $det['compra_id'];
            if (!isset($detallesCompraMap[$cId])) {
                $detallesCompraMap[$cId] = [];
            }
            $detallesCompraMap[$cId][] = [
                'producto_id' => $det['producto_id'],
                'producto_nombre' => $det['productos']['nombre'] ?? 'Producto',
                'cantidad' => (int)($det['cantidad'] ?? 0),
                'costo_unitario' => (float)($det['costo_unitario'] ?? 0.00)
            ];
        }
    }
}

// 5. Procesamiento del Formulario (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $producto_id = clean_input($_POST['producto_id'] ?? '');
    $tipo_movimiento = clean_input($_POST['tipo_movimiento'] ?? '');
    $cantidad_raw = $_POST['cantidad'] ?? '';
    $motivo = clean_input($_POST['motivo'] ?? '');
    $compra_id_raw = clean_input($_POST['compra_id'] ?? '');

    $tiposPermitidos = [
        'ajuste_entrada',
        'ajuste_salida',
        'merma',
        'consumo',
        'devolucion_proveedor'
    ];

    // Validaciones en Servidor
    if (empty($producto_id) || !is_valid_uuid($producto_id)) {
        $error = 'Por favor selecciona un producto válido del catálogo.';
    } elseif (empty($tipo_movimiento) || !in_array($tipo_movimiento, $tiposPermitidos, true)) {
        $error = 'El tipo de movimiento seleccionado no es válido.';
    } elseif (!is_numeric($cantidad_raw) || (int)$cantidad_raw != $cantidad_raw || (int)$cantidad_raw <= 0) {
        $error = 'La cantidad debe ser un número entero mayor a 0.';
    } elseif (empty($motivo) || strlen(trim($motivo)) < 3) {
        $error = 'El motivo es obligatorio y debe contener al menos 3 caracteres.';
    } elseif ($tipo_movimiento === 'devolucion_proveedor' && (empty($compra_id_raw) || !is_valid_uuid($compra_id_raw))) {
        $error = 'Para una devolución a proveedor debes seleccionar la compra original registrada.';
    } else {
        $cantidad = (int)$cantidad_raw;
        $compra_id = ($tipo_movimiento === 'devolucion_proveedor') ? $compra_id_raw : null;

        // Estructurar los 5 parámetros requeridos por la RPC
        $rpcParams = [
            'p_producto_id' => $producto_id,
            'p_tipo_movimiento' => $tipo_movimiento,
            'p_cantidad' => $cantidad,
            'p_motivo' => trim($motivo),
            'p_compra_id' => $compra_id
        ];

        // Ejecución atómica exclusiva mediante la RPC en PostgreSQL
        $resRpc = $supabase->rpc('registrar_movimiento_inventario', $rpcParams, $authToken);

        if ($resRpc['success'] && !empty($resRpc['data'])) {
            $nombresTipos = [
                'ajuste_entrada' => 'Ajuste de entrada',
                'ajuste_salida' => 'Ajuste de salida',
                'merma' => 'Merma',
                'consumo' => 'Consumo interno',
                'devolucion_proveedor' => 'Devolución a proveedor'
            ];
            $tipoLabel = $nombresTipos[$tipo_movimiento] ?? $tipo_movimiento;

            set_flash_message('success', "Movimiento manual ({$tipoLabel}) registrado con éxito en Inventario y Kardex.");
            redirect('/modules/kardex/');
        } else {
            $rawError = $resRpc['error'] ?? 'Error desconocido al procesar el movimiento en la base de datos.';

            // Mapeo seguro y amigable de excepciones de la RPC
            if (stripos($rawError, 'Stock insuficiente') !== false) {
                $error = 'No hay stock suficiente para realizar este movimiento de salida.';
            } elseif (stripos($rawError, 'no existe en el cat') !== false) {
                $error = 'El producto seleccionado no existe en el catálogo.';
            } elseif (stripos($rawError, 'no tiene registro de inventario') !== false) {
                $error = 'El producto seleccionado no cuenta con registro inicial de inventario.';
            } elseif (stripos($rawError, 'ha sido anulada') !== false) {
                $error = 'No se puede realizar una devolución sobre una compra que ha sido anulada.';
            } elseif (stripos($rawError, 'no pertenece al detalle') !== false) {
                $error = 'El producto seleccionado no pertenece al detalle de la compra especificada.';
            } elseif (stripos($rawError, 'no puede superar la cantidad comprada') !== false) {
                $error = 'La cantidad a devolver supera la cantidad comprada en el comprobante seleccionado.';
            } elseif (stripos($rawError, 'Solo un Administrador') !== false) {
                $error = 'Permiso denegado: solo un Administrador puede registrar movimientos manuales.';
            } elseif (stripos($rawError, 'No se ha detectado una sesi') !== false) {
                $error = 'Sesión expirada o inválida. Por favor inicia sesión nuevamente.';
            } else {
                $error = 'No se pudo registrar el movimiento: ' . htmlspecialchars($rawError);
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

        <!-- Encabezado de la página -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <a href="<?= BASE_URL ?>/modules/inventario/" class="btn btn-outline-secondary btn-sm rounded-circle p-2" title="Volver a Inventario">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <h3 class="fw-bold m-0" style="color: var(--secondary-color);">Registrar movimiento de inventario</h3>
                </div>
                <p class="text-muted mb-0 ms-md-4 ps-md-2">Ajustes, mermas, consumos y devoluciones a proveedor</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>/modules/kardex/" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm">
                    <i class="bi bi-journal-text me-1"></i> Ver Kardex
                </a>
                <a href="<?= BASE_URL ?>/modules/inventario/" class="btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-sm">
                    <i class="bi bi-box-seam me-1"></i> Control de Stock
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                    <div><?= $error ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Formulario Principal -->
        <div class="row justify-content-center">
            <div class="col-12 col-xl-9">
                <div class="card-custom p-4 p-md-5 mb-4 shadow-sm border-0">
                    <form action="" method="POST" id="form-movimiento" novalidate>
                        
                        <!-- 1. Tipo de Movimiento -->
                        <div class="mb-4">
                            <label for="tipo_movimiento" class="form-label fw-bold text-dark">
                                <i class="bi bi-tag-fill me-1 text-primary"></i> Tipo de Movimiento <span class="text-danger">*</span>
                            </label>
                            <select class="form-select form-select-lg border-2" id="tipo_movimiento" name="tipo_movimiento" required>
                                <option value="" disabled <?= empty($selectedTipoMovimiento) ? 'selected' : '' ?>>-- Seleccione un tipo de movimiento --</option>
                                <option value="ajuste_entrada" <?= ($selectedTipoMovimiento === 'ajuste_entrada') ? 'selected' : '' ?>>
                                    🟢 Ajuste de entrada (Incrementa stock)
                                </option>
                                <option value="ajuste_salida" <?= ($selectedTipoMovimiento === 'ajuste_salida') ? 'selected' : '' ?>>
                                    🔴 Ajuste de salida (Descuenta stock)
                                </option>
                                <option value="merma" <?= ($selectedTipoMovimiento === 'merma') ? 'selected' : '' ?>>
                                    🟠 Merma (Pérdida / deterioro / vencimiento)
                                </option>
                                <option value="consumo" <?= ($selectedTipoMovimiento === 'consumo') ? 'selected' : '' ?>>
                                    🔵 Consumo (Uso interno / degustación)
                                </option>
                                <option value="devolucion_proveedor" <?= ($selectedTipoMovimiento === 'devolucion_proveedor') ? 'selected' : '' ?>>
                                    🟣 Devolución a proveedor (Requiere compra registrada)
                                </option>
                            </select>
                            <div class="form-text mt-2" id="tipo-help-text">
                                Seleccione la naturaleza operativa de la transacción de inventario.
                            </div>
                        </div>

                        <!-- 2. Selección de Producto -->
                        <div class="mb-4">
                            <label for="producto_id" class="form-label fw-bold text-dark">
                                <i class="bi bi-box-seam-fill me-1 text-primary"></i> Producto <span class="text-danger">*</span>
                            </label>
                            <select class="form-select form-select-lg border-2" id="producto_id" name="producto_id" required>
                                <option value="" disabled <?= empty($selectedProductoId) ? 'selected' : '' ?>>-- Seleccione un producto del catálogo --</option>
                                <?php foreach ($productos as $prod): ?>
                                    <?php
                                    $pId = $prod['id'];
                                    $pNombre = $prod['nombre'] ?? 'Producto';
                                    $catNombre = $prod['categorias']['nombre'] ?? 'General';
                                    $invData = $invMap[$pId] ?? ['stock_actual' => 0, 'costo_unitario' => 0.00];
                                    $stock = $invData['stock_actual'];
                                    $costo = $invData['costo_unitario'];
                                    ?>
                                    <option value="<?= htmlspecialchars($pId) ?>" 
                                            data-stock="<?= $stock ?>" 
                                            data-costo="<?= number_format($costo, 2, '.', '') ?>"
                                            data-categoria="<?= htmlspecialchars($catNombre) ?>"
                                            <?= ($selectedProductoId === $pId) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pNombre) ?> (<?= htmlspecialchars($catNombre) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Panel Informativo de Stock & Costo Actual -->
                        <div class="card bg-light border-0 rounded-4 p-3 mb-4" id="panel-info-stock" style="display: none;">
                            <div class="row g-3 align-items-center">
                                <div class="col-12 col-md-4">
                                    <span class="text-muted small d-block">Stock Actual en Almacén</span>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span class="badge px-3 py-2 fs-6 fw-bold" id="badge-stock-actual">
                                            0 unidades
                                        </span>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-muted small d-block">Costo Unitario de Adquisición</span>
                                    <div class="fs-6 fw-bold text-dark mt-1" id="label-costo-unitario">
                                        Bs. 0.00
                                    </div>
                                    <small class="text-muted" style="font-size: 0.75rem;">(Desde inventario.costo_unitario)</small>
                                </div>
                                <div class="col-12 col-md-4">
                                    <span class="text-muted small d-block">Stock Proyectado Resultante</span>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span class="badge bg-white text-dark border px-3 py-2 fs-6 fw-bold" id="badge-stock-proyectado">
                                            --
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Campo Dinámico: Compra Relacionada (solo para devolucion_proveedor) -->
                        <div class="mb-4" id="contenedor-compra" style="display: none;">
                            <label for="compra_id" class="form-label fw-bold text-dark">
                                <i class="bi bi-receipt-cutoff me-1 text-primary"></i> Compra Relacionada <span class="text-danger">*</span>
                            </label>
                            <select class="form-select border-2" id="compra_id" name="compra_id">
                                <option value="">-- Seleccione el comprobante de compra --</option>
                                <?php if (!empty($compras)): ?>
                                    <?php foreach ($compras as $c): ?>
                                        <?php
                                        $cId = $c['id'];
                                        $cFecha = !empty($c['fecha']) ? date('d/m/Y', strtotime($c['fecha'])) : date('d/m/Y', strtotime($c['created_at']));
                                        $cProv = $c['proveedores']['nombre'] ?? 'Proveedor General';
                                        $cTotal = number_format((float)($c['total'] ?? 0), 2);
                                        $shortId = substr($cId, 0, 8);
                                        ?>
                                        <option value="<?= htmlspecialchars($cId) ?>" <?= ($selectedCompraId === $cId) ? 'selected' : '' ?>>
                                            Compra #<?= htmlspecialchars($shortId) ?> — <?= $cFecha ?> — <?= htmlspecialchars($cProv) ?> — Total: Bs. <?= $cTotal ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No hay compras registradas disponibles</option>
                                <?php endif; ?>
                            </select>
                            <div class="form-text mt-2 text-primary" id="compra-help-text">
                                <i class="bi bi-info-circle me-1"></i> La devolución se vinculará a esta compra y verificará que el producto y la cantidad correspondan a su detalle.
                            </div>
                        </div>

                        <!-- 4. Cantidad y Motivo -->
                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-4">
                                <label for="cantidad" class="form-label fw-bold text-dark">
                                    <i class="bi bi-123 me-1 text-primary"></i> Cantidad <span class="text-danger">*</span>
                                </label>
                                <input type="number" 
                                       class="form-control form-control-lg border-2" 
                                       id="cantidad" 
                                       name="cantidad" 
                                       min="1" 
                                       step="1" 
                                       placeholder="Ej. 5"
                                       value="<?= htmlspecialchars($selectedCantidad) ?>"
                                       required>
                                <div class="form-text">Número entero mayor a 0.</div>
                            </div>
                            <div class="col-12 col-md-8">
                                <label for="motivo" class="form-label fw-bold text-dark">
                                    <i class="bi bi-chat-left-text-fill me-1 text-primary"></i> Motivo del Movimiento <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control border-2" 
                                          id="motivo" 
                                          name="motivo" 
                                          rows="2" 
                                          minlength="3" 
                                          placeholder="Describa la justificación clara de este movimiento (ej. Conteo físico trimestral, Producto vencido, etc.)"
                                          required><?= htmlspecialchars($selectedMotivo) ?></textarea>
                                <div class="form-text">Mínimo 3 caracteres descriptivos obligatorios.</div>
                            </div>
                        </div>

                        <hr class="my-4 text-muted opacity-25">

                        <!-- Botones de Acción -->
                        <div class="d-flex flex-column flex-sm-row justify-content-end align-items-center gap-3">
                            <a href="<?= BASE_URL ?>/modules/inventario/" class="btn btn-light rounded-pill px-4 py-2 border w-100 w-sm-auto text-center">
                                Cancelar
                            </a>
                            <button type="submit" id="btn-submit-movement" class="btn btn-primary-custom rounded-pill px-5 py-2 shadow-sm w-100 w-sm-auto">
                                <i class="bi bi-check-circle-fill me-2"></i> Registrar Movimiento
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>

<!-- Script interactivo de apoyo al Administrador -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tipoSelect = document.getElementById('tipo_movimiento');
    const prodSelect = document.getElementById('producto_id');
    const cantInput = document.getElementById('cantidad');
    const compraContenedor = document.getElementById('contenedor-compra');
    const compraSelect = document.getElementById('compra_id');
    const panelInfoStock = document.getElementById('panel-info-stock');
    const badgeStockActual = document.getElementById('badge-stock-actual');
    const labelCostoUnitario = document.getElementById('label-costo-unitario');
    const badgeStockProyectado = document.getElementById('badge-stock-proyectado');
    const tipoHelpText = document.getElementById('tipo-help-text');
    const formMovimiento = document.getElementById('form-movimiento');
    const btnSubmit = document.getElementById('btn-submit-movement');

    const detallesCompras = <?= json_encode($detallesCompraMap) ?>;

    const descripcionesTipos = {
        'ajuste_entrada': '🟢 Entrada manual: incrementará las existencias físicas en almacén.',
        'ajuste_salida': '🔴 Salida manual: descontará existencias físicas por corrección o descuadre.',
        'merma': '🟠 Salida por merma: descontará existencias por deterioro, rotura o vencimiento.',
        'consumo': '🔵 Salida por consumo: descontará existencias por uso interno del personal o preparación.',
        'devolucion_proveedor': '🟣 Devolución a proveedor: descontará existencias vinculadas a un comprobante de compra.'
    };

    function actualizarPanel() {
        const selectedProdOpt = prodSelect.options[prodSelect.selectedIndex];
        const tipo = tipoSelect.value;
        const cantVal = parseInt(cantInput.value, 10) || 0;

        if (selectedProdOpt && selectedProdOpt.value) {
            panelInfoStock.style.display = 'block';
            const stockActual = parseInt(selectedProdOpt.getAttribute('data-stock'), 10) || 0;
            const costoUnitario = parseFloat(selectedProdOpt.getAttribute('data-costo')) || 0.00;

            // Stock actual
            badgeStockActual.textContent = stockActual + ' unidades';
            if (stockActual > 0) {
                badgeStockActual.className = 'badge bg-success-subtle text-success border border-success px-3 py-2 fs-6 fw-bold';
            } else {
                badgeStockActual.className = 'badge bg-secondary-subtle text-secondary border border-secondary px-3 py-2 fs-6 fw-bold';
            }

            // Costo unitario
            labelCostoUnitario.textContent = 'Bs. ' + costoUnitario.toFixed(2);

            // Cálculo Proyectado
            if (tipo && cantVal > 0) {
                let stockProy = stockActual;
                if (tipo === 'ajuste_entrada') {
                    stockProy = stockActual + cantVal;
                    badgeStockProyectado.className = 'badge bg-success-subtle text-success border border-success px-3 py-2 fs-6 fw-bold';
                    badgeStockProyectado.textContent = stockActual + ' + ' + cantVal + ' = ' + stockProy + ' unidades';
                } else {
                    stockProy = stockActual - cantVal;
                    if (stockProy < 0) {
                        badgeStockProyectado.className = 'badge bg-danger-subtle text-danger border border-danger px-3 py-2 fs-6 fw-bold';
                        badgeStockProyectado.textContent = stockActual + ' - ' + cantVal + ' = ' + stockProy + ' (⚠️ Stock Insuficiente)';
                    } else {
                        badgeStockProyectado.className = 'badge bg-primary-subtle text-primary border border-primary px-3 py-2 fs-6 fw-bold';
                        badgeStockProyectado.textContent = stockActual + ' - ' + cantVal + ' = ' + stockProy + ' unidades';
                    }
                }
            } else {
                badgeStockProyectado.className = 'badge bg-white text-dark border px-3 py-2 fs-6 fw-bold';
                badgeStockProyectado.textContent = 'Ingrese cantidad y tipo';
            }
        } else {
            panelInfoStock.style.display = 'none';
        }
    }

    function actualizarTipo() {
        const tipo = tipoSelect.value;
        tipoHelpText.textContent = descripcionesTipos[tipo] || 'Seleccione la naturaleza operativa de la transacción de inventario.';

        if (tipo === 'devolucion_proveedor') {
            compraContenedor.style.display = 'block';
            compraSelect.setAttribute('required', 'required');
        } else {
            compraContenedor.style.display = 'none';
            compraSelect.removeAttribute('required');
            compraSelect.value = '';
        }

        actualizarPanel();
    }

    tipoSelect.addEventListener('change', actualizarTipo);
    prodSelect.addEventListener('change', actualizarPanel);
    cantInput.addEventListener('input', actualizarPanel);

    // Si se selecciona una compra para devolución, sugerir información
    compraSelect.addEventListener('change', function () {
        const compraId = this.value;
        const prodId = prodSelect.value;
        if (compraId && detallesCompras[compraId]) {
            const items = detallesCompras[compraId];
            const itemMatch = items.find(it => it.producto_id === prodId);
            if (itemMatch) {
                document.getElementById('compra-help-text').innerHTML = 
                    `<i class="bi bi-check-circle-fill text-success me-1"></i> Producto presente en la compra. Cantidad comprada: <strong>${itemMatch.cantidad} unidades</strong> (Costo: Bs. ${itemMatch.costo_unitario.toFixed(2)}).`;
            } else if (prodId) {
                document.getElementById('compra-help-text').innerHTML = 
                    `<i class="bi bi-exclamation-circle-fill text-warning me-1"></i> Aviso: El producto seleccionado actualmente no figura en los detalles de esta compra.`;
            }
        } else {
            document.getElementById('compra-help-text').innerHTML = 
                `<i class="bi bi-info-circle me-1"></i> La devolución se vinculará a esta compra y verificará que el producto y la cantidad correspondan a su detalle.`;
        }
    });

    // Manejo y confirmación de envío (PRG + prevención de doble submit)
    formMovimiento.addEventListener('submit', function (e) {
        if (!this.checkValidity()) {
            return;
        }

        const tipo = tipoSelect.value;
        const cantVal = parseInt(cantInput.value, 10) || 0;
        const selectedProdOpt = prodSelect.options[prodSelect.selectedIndex];
        const stockActual = selectedProdOpt ? (parseInt(selectedProdOpt.getAttribute('data-stock'), 10) || 0) : 0;

        let confirmText = '';
        if (tipo === 'ajuste_entrada') {
            confirmText = '¿Está seguro de registrar esta entrada?\nEl stock del producto aumentará.';
        } else {
            confirmText = '¿Está seguro de registrar este movimiento?\nEsta operación modificará el stock del producto.';
            if (cantVal > stockActual) {
                confirmText += '\n\n⚠️ ADVERTENCIA: La cantidad solicitada (' + cantVal + ') es mayor al stock actual (' + stockActual + '). La operación será rechazada por seguridad.';
            }
        }

        if (!confirm(confirmText)) {
            e.preventDefault();
            return false;
        }

        // Protección contra doble envío
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Procesando...';
    });

    // Inicializar estado visual al cargar
    actualizarTipo();
});
</script>
