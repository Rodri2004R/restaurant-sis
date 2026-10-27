<?php
$pageTitle = 'Reservas Online & Preorden de Menú';
$currentModule = 'reservas_online';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Cliente', 'Administrador', 'Recepcionista', 'Mesero']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$currentUser = get_authenticated_user();
$isCliente = (($currentUser['rol_nombre'] ?? '') === 'Cliente');

// Resolver cliente_id
$clienteId = get_current_cliente_id();

// Si es cliente y no tiene cliente_id en sesión, buscarlo o crearlo
if ($isCliente && empty($clienteId) && $supabase->isConfigured()) {
    $userEmail = $currentUser['email'] ?? '';
    $userDoc = $currentUser['ci'] ?? '';

    $resCliFind = $supabase->select('clientes', '*', ['email' => 'eq.' . $userEmail], $authToken);
    if ($resCliFind['success'] && !empty($resCliFind['data'])) {
        $clienteId = $resCliFind['data'][0]['id'];
        $_SESSION['user']['cliente_id'] = $clienteId;
    } else {
        $newCliRes = $supabase->insert('clientes', [
            'nombre' => $currentUser['nombre'] ?? 'Cliente',
            'apellido' => $currentUser['apellido'] ?? '',
            'documento' => !empty($userDoc) ? $userDoc : ('CLI-' . substr($currentUser['id'], 0, 8)),
            'telefono' => $currentUser['telefono'] ?? '',
            'email' => $userEmail,
            'estado' => true
        ], $authToken);

        if ($newCliRes['success'] && !empty($newCliRes['data'])) {
            $clienteId = $newCliRes['data'][0]['id'];
            $_SESSION['user']['cliente_id'] = $clienteId;
        }
    }
}

$mesas = [];
$categorias = [];
$productos = [];
$clientesStaff = [];
$reservasActivas = [];
$error = null;

if ($supabase->isConfigured()) {
    // 1. Cargar mesas
    $resMesas = $supabase->select('mesas', '*', ['order' => 'numero.asc'], $authToken);
    if ($resMesas['success']) {
        $mesas = $resMesas['data'] ?? [];
    }

    // 2. Cargar categorías activas
    $resCat = $supabase->select('categorias', '*', ['estado' => 'eq.true', 'order' => 'nombre.asc'], $authToken);
    if ($resCat['success']) {
        $categorias = $resCat['data'] ?? [];
    }

    // 3. Cargar productos activos
    $resProd = $supabase->select('productos', '*,categorias(id,nombre)', [
        'estado' => 'eq.true',
        'order' => 'nombre.asc'
    ], $authToken);
    if ($resProd['success']) {
        $productos = $resProd['data'] ?? [];
    }

    // 4. Si es personal interno, cargar clientes para asignación
    if (!$isCliente) {
        $resCliList = $supabase->select('clientes', '*', ['estado' => 'eq.true', 'order' => 'nombre.asc'], $authToken);
        if ($resCliList['success']) {
            $clientesStaff = $resCliList['data'] ?? [];
        }
    }

    // 5. Cargar reservas activas para comprobación interactiva de disponibilidad
    $resActivas = $supabase->select('reservas', 'id,mesa_id,fecha,hora,estado', [
        'estado' => 'in.(Pendiente,Confirmada)',
        'fecha' => 'gte.' . date('Y-m-d')
    ], $authToken);
    if ($resActivas['success']) {
        $reservasActivas = $resActivas['data'] ?? [];
    }
}

// Procesar Confirmación de Reserva con Preorden (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha = clean_input($_POST['fecha'] ?? '');
    $hora = clean_input($_POST['hora'] ?? '');
    $mesa_id = clean_input($_POST['mesa_id'] ?? '');
    $cantidad_personas = isset($_POST['cantidad_personas']) && is_numeric($_POST['cantidad_personas']) ? (int)$_POST['cantidad_personas'] : 0;
    $observaciones_base = clean_input($_POST['observaciones'] ?? '');

    // Para personal interno, permitir seleccionar cliente
    $clienteIdPost = $clienteId;
    if (!$isCliente) {
        $staffCliInput = clean_input($_POST['cliente_id'] ?? '');
        if (!empty($staffCliInput) && is_valid_uuid($staffCliInput)) {
            $clienteIdPost = $staffCliInput;
        }
    }

    // Recibir arrays de productos seleccionados para la preorden
    $rawProductIds = $_POST['prod_id'] ?? [];
    $rawCantidades = $_POST['prod_cant'] ?? [];
    $rawNotas = $_POST['prod_notas'] ?? [];

    $fechaActual = date('Y-m-d');

    // 1. Validaciones básicas de reserva
    if (empty($clienteIdPost) || !is_valid_uuid($clienteIdPost)) {
        $error = 'No se pudo identificar el cliente titular para la reserva.';
    } elseif (empty($mesa_id) || !is_valid_uuid($mesa_id)) {
        $error = 'Por favor selecciona una mesa para tu reserva.';
    } elseif (empty($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $error = 'La fecha de reserva seleccionada no es válida.';
    } elseif ($fecha < $fechaActual) {
        $error = 'No se pueden realizar reservas para fechas pasadas.';
    } elseif (empty($hora) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora)) {
        $error = 'La hora de reserva debe tener formato HH:MM (entre 00:00 y 23:59).';
    } elseif ($cantidad_personas < 1 || $cantidad_personas > 100) {
        $error = 'La cantidad de personas debe ser un número entre 1 y 100.';
    } else {
        // 2. Validar capacidad de la mesa
        $targetMesa = null;
        foreach ($mesas as $m) {
            if ($m['id'] === $mesa_id) {
                $targetMesa = $m;
                break;
            }
        }

        if (!$targetMesa) {
            $error = 'La mesa seleccionada no existe en el restaurante.';
        } elseif ($cantidad_personas > (int)$targetMesa['capacidad']) {
            $error = 'Capacidad superada: La Mesa #' . $targetMesa['numero'] . ' tiene capacidad para ' . $targetMesa['capacidad'] . ' personas, pero solicitaste para ' . $cantidad_personas . ' comensales.';
        } else {
            // 3. Detección de conflicto horario en Supabase
            $conflictRes = $supabase->select('reservas', '*', [
                'mesa_id' => 'eq.' . $mesa_id,
                'fecha' => 'eq.' . $fecha,
                'hora' => 'eq.' . $hora,
                'estado' => 'in.(Pendiente,Confirmada)'
            ], $authToken);

            if ($conflictRes['success'] && !empty($conflictRes['data'])) {
                $error = 'Disponibilidad Denegada: La Mesa #' . $targetMesa['numero'] . ' ya cuenta con una reserva activa para el ' . date('d/m/Y', strtotime($fecha)) . ' a las ' . $hora . '. Por favor selecciona otra mesa u horario.';
            } else {
                // 4. Procesar Preorden Opcional (si se enviaron productos)
                $tienePreorden = (!empty($rawProductIds) && is_array($rawProductIds) && count($rawProductIds) > 0);
                $preordenValida = [];
                $idsConsultar = [];
                $totalPreordenCalculado = 0.0;

                if ($tienePreorden) {
                    for ($i = 0; $i < count($rawProductIds); $i++) {
                        $pId = clean_input($rawProductIds[$i]);
                        $pCantRaw = $rawCantidades[$i] ?? null;
                        $pCant = (is_numeric($pCantRaw) && (int)$pCantRaw == $pCantRaw) ? (int)$pCantRaw : 0;
                        $pNota = isset($rawNotas[$i]) ? clean_input($rawNotas[$i]) : '';

                        if (empty($pId) || !is_valid_uuid($pId)) {
                            $error = 'Uno de los productos seleccionados tiene un identificador inválido.';
                            break;
                        }

                        if ($pCant <= 0) {
                            $error = 'La cantidad de cada platillo en la preorden debe ser un número entero mayor o igual a 1.';
                            break;
                        }

                        $preordenValida[] = [
                            'producto_id' => $pId,
                            'cantidad' => $pCant,
                            'notas' => $pNota
                        ];
                        $idsConsultar[] = $pId;
                    }

                    if (!$error && !empty($preordenValida)) {
                        // 5. SEGURIDAD DE PRECIOS: Consultar precios oficiales en Supabase
                        $dbProdMap = [];
                        $resCheckProd = $supabase->select('productos', '*', [
                            'id' => 'in.(' . implode(',', array_unique($idsConsultar)) . ')'
                        ], $authToken);

                        if ($resCheckProd['success'] && !empty($resCheckProd['data'])) {
                            foreach ($resCheckProd['data'] as $dbP) {
                                $dbProdMap[$dbP['id']] = $dbP;
                            }
                        }

                        $detallesFinales = [];
                        foreach ($preordenValida as $itemPre) {
                            $pId = $itemPre['producto_id'];
                            if (!isset($dbProdMap[$pId])) {
                                $error = 'Uno de los platillos seleccionados no existe en el catálogo.';
                                break;
                            }

                            $infoP = $dbProdMap[$pId];
                            if (!($infoP['estado'] ?? true)) {
                                $error = 'El platillo "' . htmlspecialchars($infoP['nombre']) . '" se encuentra inactivo.';
                                break;
                            }
                            if (!($infoP['disponible'] ?? true)) {
                                $error = 'El platillo "' . htmlspecialchars($infoP['nombre']) . '" está agotado temporalmente.';
                                break;
                            }

                            $precioOficial = (float)($infoP['precio'] ?? 0);
                            $subtotal = round($itemPre['cantidad'] * $precioOficial, 2);
                            $totalPreordenCalculado += $subtotal;

                            $detallesFinales[] = [
                                'producto_id' => $pId,
                                'nombre' => $infoP['nombre'],
                                'cantidad' => $itemPre['cantidad'],
                                'precio_unitario' => $precioOficial,
                                'subtotal' => $subtotal,
                                'notas' => $itemPre['notas']
                            ];
                        }
                        $preordenValida = $detallesFinales;
                    }
                }

                if (!$error) {
                    $totalPreordenCalculado = round($totalPreordenCalculado, 2);

                    // 6. Insertar Reserva en Supabase (observaciones limpias)
                    $reservaData = [
                        'cliente_id' => $clienteIdPost,
                        'mesa_id' => $mesa_id,
                        'fecha' => $fecha,
                        'hora' => $hora,
                        'cantidad_personas' => $cantidad_personas,
                        'estado' => 'Pendiente',
                        'observaciones' => $observaciones_base
                    ];

                    $insertRes = $supabase->insert('reservas', $reservaData, $authToken);

                    if ($insertRes['success'] && !empty($insertRes['data'])) {
                        $nuevaReserva = $insertRes['data'][0];
                        $reservaId = $nuevaReserva['id'];

                        // 7. Insertar cada platillo en la tabla estructurada public.detalles_reserva
                        if ($tienePreorden && !empty($preordenValida)) {
                            $todosDetallesOk = true;
                            foreach ($preordenValida as $det) {
                                $resDet = $supabase->insert('detalles_reserva', [
                                    'reserva_id' => $reservaId,
                                    'producto_id' => $det['producto_id'],
                                    'cantidad' => $det['cantidad'],
                                    'precio_unitario' => $det['precio_unitario'],
                                    'subtotal' => $det['subtotal'],
                                    'notas' => $det['notas']
                                ], $authToken);

                                if (!$resDet['success']) {
                                    $todosDetallesOk = false;
                                }
                            }
                        }

                        $msgSuccess = '¡Reserva #' . substr($reservaId, 0, 8) . ' registrada exitosamente para el ' . date('d/m/Y', strtotime($fecha)) . ' a las ' . $hora . '!';
                        if ($tienePreorden) {
                            $msgSuccess .= ' Se asociaron ' . count($preordenValida) . ' platillo(s) en preorden por un total de Bs. ' . number_format($totalPreordenCalculado, 2) . '.';
                        }

                        set_flash_message('success', $msgSuccess);

                        if ($isCliente) {
                            redirect('/modules/reservas/mis_reservas.php');
                        } else {
                            redirect('/modules/reservas/');
                        }
                    } else {
                        $error = 'Error al registrar la reserva en Supabase: ' . ($insertRes['error'] ?? 'Error desconocido');
                    }
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
        <!-- Encabezado de Reservas Online -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Reservar Mesa Online & Preordenar Menú</h3>
                <p class="text-muted mb-0">Elige tu horario, selecciona tu mesa favorita y pide tus platillos por anticipado</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>/modules/reservas/mis_reservas.php" class="btn btn-outline-secondary">
                    <i class="bi bi-bookmark-check me-1"></i> Ver Mis Reservas
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4 shadow-sm">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form action="" method="POST" id="onlineBookingForm" autocomplete="off">
            <div class="row g-4">
                <!-- Columna Izquierda: Parámetros de Reserva + Catálogo de Menú -->
                <div class="col-lg-7 col-xl-8">
                    <!-- PASO 1: Datos de Fecha, Horario, Comensales y Mesa -->
                    <div class="card-custom p-4 mb-4">
                        <div class="d-flex align-items-center gap-2 border-bottom pb-2 mb-3">
                            <span class="badge bg-primary rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">1</span>
                            <h5 class="fw-bold m-0" style="color: var(--secondary-color);">Datos de tu Reserva</h5>
                        </div>

                        <div class="row g-3">
                            <?php if (!$isCliente): ?>
                                <div class="col-md-12">
                                    <label class="form-label fw-semibold small">Cliente Titular <span class="text-danger">*</span></label>
                                    <select name="cliente_id" class="form-select" required>
                                        <option value="">-- Seleccionar Cliente Registrado --</option>
                                        <?php foreach ($clientesStaff as $cs): ?>
                                            <option value="<?= $cs['id'] ?>">
                                                <?= htmlspecialchars($cs['nombre'] . ' ' . $cs['apellido']) ?> (CI: <?= htmlspecialchars($cs['documento']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold small" for="inputFecha">
                                    <i class="bi bi-calendar-event me-1 text-primary"></i> Fecha <span class="text-danger">*</span>
                                </label>
                                <input type="date" name="fecha" id="inputFecha" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['fecha'] ?? date('Y-m-d')) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold small" for="inputHora">
                                    <i class="bi bi-clock me-1 text-primary"></i> Hora <span class="text-danger">*</span>
                                </label>
                                <select name="hora" id="inputHora" class="form-select" required>
                                    <?php 
                                    $horasDisponibles = ['12:00', '12:30', '13:00', '13:30', '14:00', '14:30', '15:00', '19:00', '19:30', '20:00', '20:30', '21:00', '21:30', '22:00'];
                                    $horaSel = $_POST['hora'] ?? '13:00';
                                    ?>
                                    <?php foreach ($horasDisponibles as $h): ?>
                                        <option value="<?= $h ?>" <?= ($horaSel === $h) ? 'selected' : '' ?>><?= $h ?> hrs</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold small" for="inputPersonas">
                                    <i class="bi bi-people me-1 text-primary"></i> Personas <span class="text-danger">*</span>
                                </label>
                                <input type="number" name="cantidad_personas" id="inputPersonas" class="form-control" min="1" max="50" required value="<?= htmlspecialchars($_POST['cantidad_personas'] ?? '2') ?>">
                            </div>

                            <div class="col-md-12">
                                <label class="form-label fw-semibold small" for="selectMesa">
                                    <i class="bi bi-table me-1 text-primary"></i> Seleccionar Mesa de Salón <span class="text-danger">*</span>
                                </label>
                                <select name="mesa_id" id="selectMesa" class="form-select" required>
                                    <option value="">-- Seleccionar Mesa --</option>
                                    <?php foreach ($mesas as $m): ?>
                                        <option value="<?= $m['id'] ?>" data-cap="<?= $m['capacidad'] ?>" <?= (($_POST['mesa_id'] ?? '') === $m['id']) ? 'selected' : '' ?>>
                                            Mesa #<?= $m['numero'] ?> (<?= htmlspecialchars($m['ubicacion'] ?? 'Salón') ?>) — Capacidad: <?= $m['capacidad'] ?> personas
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" style="font-size: 0.75rem;">El sistema valida automáticamente que la mesa no tenga reservas activas en tu horario.</small>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label fw-semibold small" for="inputObs">Observaciones Adicionales (Opcional):</label>
                                <textarea name="observaciones" id="inputObs" class="form-control form-control-sm" rows="2" placeholder="Ej: Mesa cerca a la ventana, cena de aniversario, silla para bebé..."><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- PASO 2: Catálogo de Platillos para Preorden Opcional -->
                    <div class="card-custom p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-success rounded-circle p-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">2</span>
                                <div>
                                    <h5 class="fw-bold m-0" style="color: var(--secondary-color);">Preordenar Menú (Opcional)</h5>
                                    <small class="text-muted">Añade platillos si deseas que cocina los tenga preparados al llegar</small>
                                </div>
                            </div>
                            <span class="badge bg-success-subtle text-success border border-success px-3 py-1">
                                Opcional
                            </span>
                        </div>

                        <!-- Filtros y Búsqueda -->
                        <div class="row g-2 align-items-center mb-3">
                            <div class="col-md-5">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light"><i class="bi bi-search text-muted"></i></span>
                                    <input type="text" id="searchMenuInput" class="form-control bg-light" placeholder="Buscar platillo o bebida...">
                                </div>
                            </div>
                            <div class="col-md-7">
                                <div class="d-flex gap-1 overflow-auto pb-1" id="menuCatContainer">
                                    <button type="button" class="btn btn-sm btn-primary menu-cat-btn active px-3" data-cat="all">Todos</button>
                                    <?php foreach ($categorias as $cat): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary menu-cat-btn text-nowrap" data-cat="<?= htmlspecialchars($cat['id']) ?>">
                                            <?= htmlspecialchars($cat['nombre']) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Grid de Productos -->
                        <div class="row g-3" id="menuProductsGrid" style="max-height: 480px; overflow-y: auto; padding-right: 4px;">
                            <?php if (empty($productos)): ?>
                                <div class="col-12 text-center py-4 text-muted">
                                    <i class="bi bi-journal-x fs-2 d-block mb-1 text-secondary"></i>
                                    No hay platillos disponibles en el menú actualmente.
                                </div>
                            <?php else: ?>
                                <?php foreach ($productos as $prod): ?>
                                    <?php
                                    $pId = $prod['id'];
                                    $pNom = $prod['nombre'];
                                    $pDesc = $prod['descripcion'] ?? '';
                                    $pPre = (float)$prod['precio'];
                                    $pCatId = $prod['categoria_id'] ?? '';
                                    $pCatNom = $prod['categorias']['nombre'] ?? 'General';
                                    $pDisp = (bool)($prod['disponible'] ?? true);
                                    ?>
                                    <div class="col-sm-6 col-xl-4 menu-product-card" data-name="<?= strtolower(htmlspecialchars($pNom . ' ' . $pDesc)) ?>" data-category="<?= htmlspecialchars($pCatId) ?>">
                                        <div class="card-custom h-100 p-3 d-flex flex-column justify-content-between border <?= !$pDisp ? 'opacity-75 bg-light' : '' ?>">
                                            <div>
                                                <div class="d-flex justify-content-between align-items-start mb-1">
                                                    <span class="badge badge-role" style="font-size: 0.68rem;"><?= htmlspecialchars($pCatNom) ?></span>
                                                    <span class="fw-bold text-success">Bs. <?= number_format($pPre, 2) ?></span>
                                                </div>
                                                <h6 class="fw-bold text-dark mb-1 small"><?= htmlspecialchars($pNom) ?></h6>
                                                <p class="text-muted small mb-2 text-truncate-2" style="font-size: 0.75rem; min-height: 32px;">
                                                    <?= htmlspecialchars($pDesc ?: 'Platillo especial de la casa elaborado al momento.') ?>
                                                </p>
                                            </div>

                                            <div class="pt-2 border-top">
                                                <?php if ($pDisp): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary w-100 btn-add-preorder"
                                                            data-id="<?= $pId ?>"
                                                            data-name="<?= htmlspecialchars($pNom) ?>"
                                                            data-price="<?= $pPre ?>">
                                                        <i class="bi bi-plus-circle me-1"></i> Agregar a Preorden
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-secondary w-100" disabled>
                                                        <i class="bi bi-slash-circle me-1"></i> Agotado
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha: Resumen de Reserva & Carrito de Preorden -->
                <div class="col-lg-5 col-xl-4">
                    <div class="card-custom p-3 sticky-top" style="top: 20px;">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                            <h5 class="fw-bold m-0" style="color: var(--secondary-color);">
                                <i class="bi bi-card-checklist me-1 text-primary"></i> Tu Preorden
                            </h5>
                            <button type="button" id="btnClearPreorder" class="btn btn-sm btn-link text-danger text-decoration-none p-0">
                                <i class="bi bi-trash"></i> Vaciar
                            </button>
                        </div>

                        <!-- Lista de Ítems en Carrito de Preorden -->
                        <div id="preorderItemsContainer" class="overflow-auto mb-3 pe-1" style="max-height: 320px; min-height: 180px;">
                            <div id="preorderEmptyState" class="text-center py-4 text-muted">
                                <i class="bi bi-bag-plus fs-2 d-block mb-1 text-secondary"></i>
                                <p class="mb-0 small fw-semibold">Sin platillos preordenados</p>
                                <small class="text-muted" style="font-size: 0.72rem;">La preorden es opcional. Puedes reservar tu mesa con o sin comida anticipada.</small>
                            </div>
                            <div id="preorderList" class="d-flex flex-column gap-2 d-none">
                                <!-- Ítems dinámicos JS -->
                            </div>
                        </div>

                        <!-- Resumen Financiero de la Preorden -->
                        <div class="border-top pt-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1 small text-muted">
                                <span>Total Platillos:</span>
                                <strong id="preorderTotalQty" class="text-dark">0</strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="fs-6 fw-bold text-dark">Total Preorden:</span>
                                <span class="fs-5 fw-bold text-success" id="preorderTotalAmount">Bs. 0.00</span>
                            </div>

                            <button type="submit" id="btnSubmitOnlineBooking" class="btn btn-primary-custom w-100 py-2 fs-6">
                                <i class="bi bi-calendar-check-fill me-2"></i> Confirmar Reserva
                            </button>
                            <small class="d-block text-center text-muted mt-2" style="font-size: 0.72rem;">
                                <i class="bi bi-shield-check me-1 text-success"></i> Precios oficiales garantizados
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let preorderCart = [];
    let isSubmitting = false;

    const searchInput = document.getElementById('searchMenuInput');
    const catBtns = document.querySelectorAll('.menu-cat-btn');
    const preorderList = document.getElementById('preorderList');
    const preorderEmptyState = document.getElementById('preorderEmptyState');
    const preorderTotalQty = document.getElementById('preorderTotalQty');
    const preorderTotalAmount = document.getElementById('preorderTotalAmount');
    const btnSubmitBooking = document.getElementById('btnSubmitOnlineBooking');
    const btnClearPreorder = document.getElementById('btnClearPreorder');
    const bookingForm = document.getElementById('onlineBookingForm');
    const selectMesa = document.getElementById('selectMesa');
    const inputFecha = document.getElementById('inputFecha');
    const inputHora = document.getElementById('inputHora');
    const inputPersonas = document.getElementById('inputPersonas');

    // 1. Filtrado de menú
    let curCat = 'all';
    let curSearch = '';

    function filterMenu() {
        const cards = document.querySelectorAll('.menu-product-card');
        cards.forEach(c => {
            const name = c.getAttribute('data-name') || '';
            const cat = c.getAttribute('data-category') || '';
            const matchC = (curCat === 'all' || cat === curCat);
            const matchS = (curSearch === '' || name.includes(curSearch));
            c.classList.toggle('d-none', !(matchC && matchS));
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            curSearch = this.value.trim().toLowerCase();
            filterMenu();
        });
    }

    catBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            catBtns.forEach(b => {
                b.classList.remove('btn-primary', 'active');
                b.classList.add('btn-outline-secondary');
            });
            this.classList.remove('btn-outline-secondary');
            this.classList.add('btn-primary', 'active');
            curCat = this.getAttribute('data-cat');
            filterMenu();
        });
    });

    // 2. Agregar a la Preorden
    document.querySelectorAll('.btn-add-preorder').forEach(btn => {
        btn.addEventListener('click', function() {
            const pId = this.getAttribute('data-id');
            const pNom = this.getAttribute('data-name');
            const pPre = parseFloat(this.getAttribute('data-price')) || 0;

            const existingIdx = preorderCart.findIndex(i => i.id === pId);
            if (existingIdx > -1) {
                preorderCart[existingIdx].cantidad += 1;
            } else {
                preorderCart.push({
                    id: pId,
                    nombre: pNom,
                    precio: pPre,
                    cantidad: 1,
                    notas: ''
                });
            }
            renderPreorder();
        });
    });

    // 3. Renderizar Carrito de Preorden
    function renderPreorder() {
        preorderList.innerHTML = '';

        if (preorderCart.length === 0) {
            preorderEmptyState.classList.remove('d-none');
            preorderList.classList.add('d-none');
            preorderTotalQty.textContent = '0';
            preorderTotalAmount.textContent = 'Bs. 0.00';
            btnSubmitBooking.innerHTML = '<i class="bi bi-calendar-check-fill me-2"></i> Confirmar Reserva (Sin Menú)';
            return;
        }

        preorderEmptyState.classList.add('d-none');
        preorderList.classList.remove('d-none');
        btnSubmitBooking.innerHTML = '<i class="bi bi-calendar-check-fill me-2"></i> Confirmar Reserva + Preorden';

        let totalQ = 0;
        let grandT = 0;

        preorderCart.forEach((item, index) => {
            const subt = item.cantidad * item.precio;
            totalQ += item.cantidad;
            grandT += subt;

            const itemCard = document.createElement('div');
            itemCard.className = 'p-2 border rounded bg-white shadow-xs';
            itemCard.innerHTML = `
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div style="max-width: 70%;">
                        <strong class="text-dark small d-block text-truncate" title="${escapeHtml(item.nombre)}">${escapeHtml(item.nombre)}</strong>
                        <div class="text-muted" style="font-size: 0.75rem;">Bs. ${item.precio.toFixed(2)} c/u</div>
                    </div>
                    <div class="text-end">
                        <strong class="text-success small">Bs. ${subt.toFixed(2)}</strong>
                        <button type="button" class="btn btn-sm text-danger p-0 ms-2 btn-del-item" data-index="${index}" title="Quitar">
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 mt-2">
                    <div class="input-group input-group-sm" style="width: 100px;">
                        <button type="button" class="btn btn-outline-secondary btn-min" data-index="${index}">-</button>
                        <input type="number" min="1" class="form-control text-center p-1 qty-inp" data-index="${index}" value="${item.cantidad}" style="font-size: 0.8rem;">
                        <button type="button" class="btn btn-outline-secondary btn-pls" data-index="${index}">+</button>
                    </div>
                    <div class="flex-grow-1">
                        <input type="text" class="form-control form-control-sm note-inp" data-index="${index}" placeholder="Instrucción (ej: sin cebolla)..." value="${escapeHtml(item.notas)}" style="font-size: 0.72rem;">
                    </div>
                </div>

                <input type="hidden" name="prod_id[]" value="${item.id}">
                <input type="hidden" name="prod_cant[]" value="${item.cantidad}">
                <input type="hidden" name="prod_notas[]" value="${escapeHtml(item.notas)}">
            `;
            preorderList.appendChild(itemCard);
        });

        preorderTotalQty.textContent = totalQ;
        preorderTotalAmount.textContent = 'Bs. ' + grandT.toFixed(2);

        attachCartEvents();
    }

    function attachCartEvents() {
        document.querySelectorAll('.btn-min').forEach(btn => {
            btn.addEventListener('click', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                if (preorderCart[idx].cantidad > 1) {
                    preorderCart[idx].cantidad -= 1;
                } else {
                    preorderCart.splice(idx, 1);
                }
                renderPreorder();
            });
        });

        document.querySelectorAll('.btn-pls').forEach(btn => {
            btn.addEventListener('click', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                preorderCart[idx].cantidad += 1;
                renderPreorder();
            });
        });

        document.querySelectorAll('.qty-inp').forEach(inp => {
            inp.addEventListener('change', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                let v = parseInt(this.value);
                if (isNaN(v) || v < 1) v = 1;
                preorderCart[idx].cantidad = v;
                renderPreorder();
            });
        });

        document.querySelectorAll('.note-inp').forEach(inp => {
            inp.addEventListener('input', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                preorderCart[idx].notas = this.value;
                const hNote = preorderList.querySelectorAll('input[name="prod_notas[]"]')[idx];
                if (hNote) hNote.value = this.value;
            });
        });

        document.querySelectorAll('.btn-del-item').forEach(btn => {
            btn.addEventListener('click', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                preorderCart.splice(idx, 1);
                renderPreorder();
            });
        });
    }

    if (btnClearPreorder) {
        btnClearPreorder.addEventListener('click', function() {
            if (preorderCart.length > 0) {
                if (confirm('¿Desea vaciar los platillos seleccionados de la preorden?')) {
                    preorderCart = [];
                    renderPreorder();
                }
            }
        });
    }

    // 4. Validar envío del formulario (Anti doble envío)
    if (bookingForm) {
        bookingForm.addEventListener('submit', function(e) {
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }

            if (!inputFecha.value) {
                e.preventDefault();
                alert('Por favor selecciona una fecha válida.');
                inputFecha.focus();
                return false;
            }

            if (!selectMesa.value) {
                e.preventDefault();
                alert('Por favor selecciona una mesa disponible.');
                selectMesa.focus();
                return false;
            }

            const optSel = selectMesa.options[selectMesa.selectedIndex];
            const capMesa = parseInt(optSel.getAttribute('data-cap')) || 100;
            const cantPers = parseInt(inputPersonas.value) || 1;

            if (cantPers > capMesa) {
                e.preventDefault();
                alert('La mesa seleccionada tiene capacidad para ' + capMesa + ' personas, pero especificaste ' + cantPers + ' comensales.');
                inputPersonas.focus();
                return false;
            }

            isSubmitting = true;
            btnSubmitBooking.disabled = true;
            btnSubmitBooking.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Confirmando reserva...';
            return true;
        });
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});
</script>
