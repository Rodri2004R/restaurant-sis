<?php
$pageTitle = 'Registrar Nueva Compra';
$currentModule = 'compras';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;

$proveedores = [];
$productos = [];
$error = null;

if ($supabase->isConfigured()) {
    // 1. Cargar proveedores activos
    $resProv = $supabase->select('proveedores', '*', [
        'estado' => 'eq.true',
        'order' => 'nombre.asc'
    ], $authToken);
    if ($resProv['success'] && !empty($resProv['data'])) {
        $proveedores = $resProv['data'];
    }

    // 2. Cargar productos activos con su categoría
    $resProd = $supabase->select('productos', '*,categorias(id,nombre)', [
        'estado' => 'eq.true',
        'order' => 'nombre.asc'
    ], $authToken);
    if ($resProd['success'] && !empty($resProd['data'])) {
        $productos = $resProd['data'];
    }
}

// 3. Procesamiento del Formulario (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $proveedor_id = clean_input($_POST['proveedor_id'] ?? '');
    $observaciones = clean_input($_POST['observaciones'] ?? '');

    $rawProductIds = $_POST['prod_id'] ?? [];
    $rawCantidades = $_POST['prod_cant'] ?? [];
    $rawCostos = $_POST['prod_costo'] ?? [];

    // Validar proveedor
    if (empty($proveedor_id) || !is_valid_uuid($proveedor_id)) {
        $error = 'Por favor selecciona un proveedor válido.';
    } else {
        // Verificar existencia y estado activo del proveedor en Supabase
        $provValido = false;
        foreach ($proveedores as $pr) {
            if ($pr['id'] === $proveedor_id && ($pr['estado'] ?? true)) {
                $provValido = true;
                break;
            }
        }
        if (!$provValido && $supabase->isConfigured()) {
            $chkProv = $supabase->select('proveedores', 'id,estado', [
                'id' => 'eq.' . $proveedor_id,
                'estado' => 'eq.true'
            ], $authToken);
            if ($chkProv['success'] && !empty($chkProv['data'])) {
                $provValido = true;
            }
        }

        if (!$provValido) {
            $error = 'El proveedor seleccionado no existe o se encuentra inactivo.';
        }
    }

    // Validar existencia de ítems en el detalle
    if (!$error && (empty($rawProductIds) || !is_array($rawProductIds) || count($rawProductIds) === 0)) {
        $error = 'Debes agregar al menos un producto al detalle de la compra.';
    }

    if (!$error) {
        $itemsValidados = [];
        $idsConsultar = [];
        $idsUnicos = [];

        for ($i = 0; $i < count($rawProductIds); $i++) {
            $pId = clean_input($rawProductIds[$i]);
            $pCantRaw = $rawCantidades[$i] ?? null;
            $pCostoRaw = $rawCostos[$i] ?? null;

            $pCant = (is_numeric($pCantRaw) && (int)$pCantRaw == $pCantRaw) ? (int)$pCantRaw : 0;
            $pCosto = is_numeric($pCostoRaw) ? (float)$pCostoRaw : 0.0;

            if (empty($pId) || !is_valid_uuid($pId)) {
                $error = 'Uno de los productos seleccionados tiene un identificador inválido.';
                break;
            }

            if (isset($idsUnicos[$pId])) {
                $error = 'No se permiten productos duplicados en el detalle de la compra. Modifica la cantidad en la fila existente.';
                break;
            }
            $idsUnicos[$pId] = true;

            if ($pCant <= 0) {
                $error = 'La cantidad de cada producto debe ser un número entero mayor a 0.';
                break;
            }

            if ($pCosto <= 0) {
                $error = 'El costo unitario de compra de cada producto debe ser mayor a 0.';
                break;
            }

            $itemsValidados[] = [
                'producto_id' => $pId,
                'cantidad' => $pCant,
                'costo_unitario' => round($pCosto, 2)
            ];
            $idsConsultar[] = $pId;
        }

        if (!$error && empty($itemsValidados)) {
            $error = 'No se recibieron productos válidos para procesar la compra.';
        }

        if (!$error) {
            // Verificar que los productos existan y estén activos en Supabase
            $resVerifProd = $supabase->select('productos', 'id,nombre,estado', [
                'id' => 'in.(' . implode(',', array_unique($idsConsultar)) . ')'
            ], $authToken);

            $productosDbMap = [];
            if ($resVerifProd['success'] && !empty($resVerifProd['data'])) {
                foreach ($resVerifProd['data'] as $pDb) {
                    $productosDbMap[$pDb['id']] = $pDb;
                }
            }

            foreach ($itemsValidados as $it) {
                $checkId = $it['producto_id'];
                if (!isset($productosDbMap[$checkId])) {
                    $error = 'Uno de los productos seleccionados no existe en el catálogo.';
                    break;
                }
                if (!($productosDbMap[$checkId]['estado'] ?? true)) {
                    $error = 'El producto "' . htmlspecialchars($productosDbMap[$checkId]['nombre']) . '" se encuentra inactivo y no puede ser comprado.';
                    break;
                }
            }

            if (!$error) {
                // Estructurar p_detalles según especificación exacta de la RPC
                $pDetalles = [];
                foreach ($itemsValidados as $it) {
                    $pDetalles[] = [
                        'producto_id' => $it['producto_id'],
                        'cantidad' => $it['cantidad'],
                        'costo_unitario' => $it['costo_unitario']
                    ];
                }

                $rpcParams = [
                    'p_proveedor_id' => $proveedor_id,
                    'p_detalles' => $pDetalles,
                    'p_observaciones' => !empty($observaciones) ? $observaciones : null
                ];

                // Ejecución atómica mediante RPC de PostgreSQL
                $resRpc = $supabase->rpc('registrar_compra', $rpcParams, $authToken);

                if ($resRpc['success'] && !empty($resRpc['data'])) {
                    $nuevoCompraId = null;
                    if (is_string($resRpc['data']) && is_valid_uuid($resRpc['data'])) {
                        $nuevoCompraId = $resRpc['data'];
                    } elseif (is_array($resRpc['data'])) {
                        if (isset($resRpc['data']['id']) && is_valid_uuid($resRpc['data']['id'])) {
                            $nuevoCompraId = $resRpc['data']['id'];
                        } elseif (isset($resRpc['data'][0])) {
                            $firstElem = $resRpc['data'][0];
                            if (is_string($firstElem) && is_valid_uuid($firstElem)) {
                                $nuevoCompraId = $firstElem;
                            } elseif (is_array($firstElem) && isset($firstElem['id']) && is_valid_uuid($firstElem['id'])) {
                                $nuevoCompraId = $firstElem['id'];
                            }
                        } else {
                            $firstVal = reset($resRpc['data']);
                            if (is_string($firstVal) && is_valid_uuid($firstVal)) {
                                $nuevoCompraId = $firstVal;
                            }
                        }
                    }

                    set_flash_message('success', 'Compra registrada correctamente.');
                    if (!empty($nuevoCompraId)) {
                        redirect('/modules/compras/view.php?id=' . $nuevoCompraId);
                    } else {
                        redirect('/modules/compras/');
                    }
                } else {
                    $rawErrorMsg = $resRpc['error'] ?? 'Error de comunicación con el servidor al registrar la compra.';
                    $error = 'No se pudo registrar la compra: ' . htmlspecialchars($rawErrorMsg);
                }
            }
        }
    }
}
?>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <div class="mb-4">
            <a href="<?= BASE_URL ?>/modules/compras/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Compras
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form id="form-compra" action="" method="POST" autocomplete="off">
            <div class="row g-4">
                <!-- Columna Izquierda: Proveedor y Observaciones -->
                <div class="col-lg-4">
                    <div class="card-custom p-4 mb-4">
                        <h5 class="fw-bold mb-3" style="color: var(--secondary-color);">
                            <i class="bi bi-truck me-2"></i>Datos del Proveedor
                        </h5>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Proveedor Activo <span class="text-danger">*</span></label>
                            <select name="proveedor_id" id="select-proveedor" class="form-select" required>
                                <option value="">-- Seleccionar Proveedor --</option>
                                <?php foreach ($proveedores as $prov): ?>
                                    <option value="<?= $prov['id'] ?>" <?= (($_POST['proveedor_id'] ?? '') === $prov['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prov['nombre']) ?> <?= !empty($prov['contacto']) ? '(' . htmlspecialchars($prov['contacto']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($proveedores)): ?>
                                <small class="text-danger d-block mt-1">
                                    <i class="bi bi-exclamation-circle me-1"></i>No hay proveedores activos disponibles.
                                    <a href="<?= BASE_URL ?>/modules/proveedores/create.php" target="_blank">Registrar proveedor</a>
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Observaciones / Factura</label>
                            <textarea name="observaciones" 
                                      class="form-control" 
                                      rows="4" 
                                      placeholder="Ej: Factura N° 004523, Lote de carnes y verduras frescas..."><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
                            <small class="text-muted">Notas opcionales sobre el comprobante o entrega.</small>
                        </div>
                    </div>

                    <!-- Resumen del Total -->
                    <div class="card-custom p-4 text-center">
                        <span class="text-muted fw-semibold text-uppercase small d-block mb-1">Monto Total de Compra</span>
                        <h2 class="fw-bold text-success mb-2" id="display-total">Bs. 0.00</h2>
                        <span class="badge bg-light text-muted border mb-3" id="display-items-count">0 productos agregados</span>

                        <button type="submit" class="btn btn-primary-custom w-100 py-2 fs-6 fw-bold" id="btn-submit-compra">
                            <i class="bi bi-check2-circle me-1"></i> Registrar Compra
                        </button>
                    </div>
                </div>

                <!-- Columna Derecha: Detalle Dinámico de Productos -->
                <div class="col-lg-8">
                    <div class="card-custom p-4 mb-4">
                        <h5 class="fw-bold mb-3" style="color: var(--secondary-color);">
                            <i class="bi bi-box-seam me-2"></i>Agregar Productos a la Compra
                        </h5>

                        <div class="row g-2 align-items-end p-3 bg-light rounded border mb-4">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small">Producto / Insumo</label>
                                <select id="input-selector-prod" class="form-select form-select-sm">
                                    <option value="">-- Seleccionar Producto --</option>
                                    <?php foreach ($productos as $pr): ?>
                                        <option value="<?= $pr['id'] ?>" 
                                                data-nombre="<?= htmlspecialchars($pr['nombre']) ?>"
                                                data-categoria="<?= htmlspecialchars($pr['categorias']['nombre'] ?? 'General') ?>"
                                                data-precio-venta="<?= (float)($pr['precio'] ?? 0) ?>">
                                            <?= htmlspecialchars($pr['nombre']) ?> — [Cat: <?= htmlspecialchars($pr['categorias']['nombre'] ?? 'General') ?>] (Ref. Venta: Bs. <?= number_format((float)($pr['precio'] ?? 0), 2) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-6 col-md-2">
                                <label class="form-label fw-semibold small">Cantidad</label>
                                <input type="number" id="input-cantidad" class="form-control form-control-sm" min="1" step="1" value="1">
                            </div>

                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small">Costo Unitario (Bs.)</label>
                                <input type="number" id="input-costo" class="form-control form-control-sm" min="0.01" step="0.01" placeholder="0.00">
                            </div>

                            <div class="col-12 col-md-2">
                                <button type="button" class="btn btn-primary-custom btn-sm w-100" id="btn-add-item">
                                    <i class="bi bi-plus-lg me-1"></i> Agregar
                                </button>
                            </div>
                        </div>

                        <div id="alert-item-error" class="alert alert-warning alert-dismissible fade show d-none mb-3 py-2" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> <span id="alert-item-text"></span>
                        </div>

                        <!-- Grilla de Ítems de Compra -->
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0" id="table-detalle-compra">
                                <thead class="table-light">
                                    <tr>
                                        <th>Producto</th>
                                        <th style="width: 120px;" class="text-center">Cantidad</th>
                                        <th style="width: 140px;" class="text-end">Costo Unit. (Bs.)</th>
                                        <th style="width: 140px;" class="text-end">Subtotal (Bs.)</th>
                                        <th style="width: 60px;" class="text-center">Acción</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-detalles">
                                    <tr id="row-empty">
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="bi bi-cart3 fs-3 d-block mb-2 text-secondary"></i>
                                            Aún no has agregado productos a la compra. Selecciona un producto arriba y haz clic en "Agregar".
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="table-light" id="tfoot-detalles" style="display: none;">
                                    <tr>
                                        <td colspan="3" class="text-end fw-bold fs-6">TOTAL COMPRA:</td>
                                        <td class="text-end fw-bold fs-5 text-success" id="tfoot-total">Bs. 0.00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const selectorProd = document.getElementById('input-selector-prod');
    const inputCant = document.getElementById('input-cantidad');
    const inputCosto = document.getElementById('input-costo');
    const btnAdd = document.getElementById('btn-add-item');
    const tbody = document.getElementById('tbody-detalles');
    const tfoot = document.getElementById('tfoot-detalles');
    const rowEmpty = document.getElementById('row-empty');
    const displayTotal = document.getElementById('display-total');
    const tfootTotal = document.getElementById('tfoot-total');
    const displayCount = document.getElementById('display-items-count');
    const alertBox = document.getElementById('alert-item-error');
    const alertText = document.getElementById('alert-item-text');
    const formCompra = document.getElementById('form-compra');

    // Almacén en memoria de ítems agregados
    const itemsMap = new Map();

    function showAlert(msg) {
        alertText.textContent = msg;
        alertBox.classList.remove('d-none');
        setTimeout(() => {
            alertBox.classList.add('d-none');
        }, 5000);
    }

    function renderTable() {
        if (itemsMap.size === 0) {
            tbody.innerHTML = '';
            tbody.appendChild(rowEmpty);
            tfoot.style.display = 'none';
            displayTotal.textContent = 'Bs. 0.00';
            tfootTotal.textContent = 'Bs. 0.00';
            displayCount.textContent = '0 productos agregados';
            return;
        }

        tbody.innerHTML = '';
        let totalGeneral = 0;

        itemsMap.forEach((item, prodId) => {
            const subtotal = item.cantidad * item.costo;
            totalGeneral += subtotal;

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <div class="fw-bold text-dark">${item.nombre}</div>
                    <small class="badge badge-role me-1">${item.categoria}</small>
                    <small class="text-muted">(Ref. Venta: Bs. ${item.precioVenta.toFixed(2)})</small>
                    <input type="hidden" name="prod_id[]" value="${prodId}">
                </td>
                <td class="text-center">
                    <input type="number" 
                           name="prod_cant[]" 
                           class="form-control form-control-sm text-center input-edit-cant" 
                           data-id="${prodId}" 
                           min="1" 
                           step="1" 
                           value="${item.cantidad}" 
                           required>
                </td>
                <td class="text-end">
                    <input type="number" 
                           name="prod_costo[]" 
                           class="form-control form-control-sm text-end input-edit-costo" 
                           data-id="${prodId}" 
                           min="0.01" 
                           step="0.01" 
                           value="${item.costo.toFixed(2)}" 
                           required>
                </td>
                <td class="text-end fw-bold text-dark">
                    Bs. ${subtotal.toFixed(2)}
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item" data-id="${prodId}" title="Eliminar ítem">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });

        tfoot.style.display = '';
        displayTotal.textContent = 'Bs. ' + totalGeneral.toFixed(2);
        tfootTotal.textContent = 'Bs. ' + totalGeneral.toFixed(2);
        displayCount.textContent = itemsMap.size + (itemsMap.size === 1 ? ' producto agregado' : ' productos agregados');

        // Escuchar cambios de edición rápida en la tabla
        document.querySelectorAll('.input-edit-cant').forEach(input => {
            input.addEventListener('change', (e) => {
                const id = e.target.getAttribute('data-id');
                const val = parseInt(e.target.value, 10);
                if (val > 0 && itemsMap.has(id)) {
                    itemsMap.get(id).cantidad = val;
                    renderTable();
                } else {
                    e.target.value = itemsMap.get(id).cantidad;
                }
            });
        });

        document.querySelectorAll('.input-edit-costo').forEach(input => {
            input.addEventListener('change', (e) => {
                const id = e.target.getAttribute('data-id');
                const val = parseFloat(e.target.value);
                if (val > 0 && itemsMap.has(id)) {
                    itemsMap.get(id).costo = val;
                    renderTable();
                } else {
                    e.target.value = itemsMap.get(id).costo.toFixed(2);
                }
            });
        });

        document.querySelectorAll('.btn-remove-item').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = btn.getAttribute('data-id');
                if (id && itemsMap.has(id)) {
                    itemsMap.delete(id);
                    renderTable();
                }
            });
        });
    }

    // Botón Agregar Producto
    btnAdd.addEventListener('click', () => {
        const prodId = selectorProd.value;
        if (!prodId) {
            showAlert('Por favor selecciona un producto de la lista.');
            selectorProd.focus();
            return;
        }

        if (itemsMap.has(prodId)) {
            showAlert('El producto "' + itemsMap.get(prodId).nombre + '" ya fue agregado al detalle de compra. Puedes modificar su cantidad o costo directamente en la tabla.');
            return;
        }

        const selectedOption = selectorProd.options[selectorProd.selectedIndex];
        const prodNombre = selectedOption.getAttribute('data-nombre') || 'Producto';
        const prodCat = selectedOption.getAttribute('data-categoria') || 'General';
        const prodPrecioVenta = parseFloat(selectedOption.getAttribute('data-precio-venta')) || 0;

        const cant = parseInt(inputCant.value, 10);
        if (isNaN(cant) || cant <= 0) {
            showAlert('La cantidad debe ser un número entero mayor a 0.');
            inputCant.focus();
            return;
        }

        const costo = parseFloat(inputCosto.value);
        if (isNaN(costo) || costo <= 0) {
            showAlert('Por favor ingresa un costo unitario válido de compra (mayor a 0.00).');
            inputCosto.focus();
            return;
        }

        // Agregar al mapa
        itemsMap.set(prodId, {
            nombre: prodNombre,
            categoria: prodCat,
            precioVenta: prodPrecioVenta,
            cantidad: cant,
            costo: costo
        });

        // Limpiar inputs
        selectorProd.value = '';
        inputCant.value = '1';
        inputCosto.value = '';

        renderTable();
    });

    // Validación final al enviar formulario
    formCompra.addEventListener('submit', (e) => {
        const provVal = document.getElementById('select-proveedor').value;
        if (!provVal) {
            e.preventDefault();
            showAlert('Por favor selecciona un proveedor activo para la compra.');
            document.getElementById('select-proveedor').focus();
            return;
        }

        if (itemsMap.size === 0) {
            e.preventDefault();
            showAlert('Debes agregar al menos un producto al detalle antes de registrar la compra.');
        }
    });
});
</script>
