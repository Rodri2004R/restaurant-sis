<?php
$pageTitle = 'Punto de Venta (POS) - Registrar Pedido';
$currentModule = 'pedidos';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Mesero', 'Recepcionista']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;

$mesas = [];
$clientes = [];
$categorias = [];
$productos = [];
$mesasOcupadasMap = [];
$error = null;

// Contexto de Reserva si proviene del flujo de reservas
$reserva_id = clean_input($_GET['reserva_id'] ?? ($_POST['reserva_id'] ?? ''));
$preselectedMesaId = clean_input($_GET['mesa_id'] ?? '');
$reservaActiva = null;
$initialCart = [];
$prefilledClienteId = '';
$prefilledObservaciones = '';

if ($supabase->isConfigured()) {
    // 1. Cargar todas las mesas
    $resMesas = $supabase->select('mesas', '*', ['order' => 'numero.asc'], $authToken);
    if ($resMesas['success']) {
        $mesas = $resMesas['data'] ?? [];
    }

    // 2. Cargar comandas activas en salón para detectar mesas ocupadas
    $resActivos = $supabase->select('pedidos', 'id,mesa_id,estado,total', [
        'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)'
    ], $authToken);
    if ($resActivos['success'] && !empty($resActivos['data'])) {
        foreach ($resActivos['data'] as $act) {
            $mId = $act['mesa_id'] ?? '';
            if ($mId && !isset($mesasOcupadasMap[$mId])) {
                $mesasOcupadasMap[$mId] = $act;
            }
        }
    }

    // 3. Cargar clientes activos
    $resCli = $supabase->select('clientes', '*', ['estado' => 'eq.true', 'order' => 'nombre.asc'], $authToken);
    if ($resCli['success']) {
        $clientes = $resCli['data'] ?? [];
    }

    // 4. Cargar categorías activas
    $resCat = $supabase->select('categorias', '*', ['estado' => 'eq.true', 'order' => 'nombre.asc'], $authToken);
    if ($resCat['success']) {
        $categorias = $resCat['data'] ?? [];
    }

    // 5. Cargar todos los productos activos
    $resProd = $supabase->select('productos', '*,categorias(id,nombre)', [
        'estado' => 'eq.true',
        'order' => 'nombre.asc'
    ], $authToken);
    if ($resProd['success']) {
        $productos = $resProd['data'] ?? [];
    }

    // 6. RESOLVER CONTEXTO DE RESERVA (Herencia estricta de Mesa, Cliente y Preorden)
    if (!empty($reserva_id) && is_valid_uuid($reserva_id)) {
        $resRv = $supabase->select('reservas', '*,mesas(id,numero,ubicacion,capacidad,estado),clientes(id,nombre,apellido,documento,telefono)', [
            'id' => 'eq.' . $reserva_id
        ], $authToken);

        if ($resRv['success'] && !empty($resRv['data'])) {
            $reservaActiva = $resRv['data'][0];
            $mesaIdReserva = $reservaActiva['mesa_id'] ?? '';
            $clienteIdReserva = $reservaActiva['cliente_id'] ?? '';

            // CONTROL DE DUPLICIDAD: Verificar si la mesa ya tiene una comanda activa en salón
            if (!empty($mesaIdReserva) && isset($mesasOcupadasMap[$mesaIdReserva])) {
                $existingOrder = $mesasOcupadasMap[$mesaIdReserva];
                set_flash_message('info', 'La Mesa #' . ($reservaActiva['mesas']['numero'] ?? '') . ' ya cuenta con una comanda activa en curso (#' . substr($existingOrder['id'], 0, 8) . '). Se redirigió a la comanda existente.');
                redirect('/modules/pedidos/view.php?id=' . $existingOrder['id']);
            }

            // CONTROL DE FECHA Y VENCIMIENTO (Regla de Tolerancia de 30 minutos)
            $fechaHoy = date('Y-m-d');
            $ahoraTs = time();
            $fechaRes = $reservaActiva['fecha'] ?? '';
            $horaRes = !empty($reservaActiva['hora']) ? date('H:i:s', strtotime($reservaActiva['hora'])) : '00:00:00';
            $resTs = strtotime($fechaRes . ' ' . $horaRes);
            $limiteTs = $resTs + (30 * 60);

            // Control de reservas canceladas o atendidas
            if (($reservaActiva['estado'] ?? '') === 'Cancelada') {
                set_flash_message('warning', 'Esta reserva se encuentra cancelada y no puede ser atendida.');
                redirect('/modules/reservas/');
            }

            if (($reservaActiva['estado'] ?? '') === 'Atendida') {
                set_flash_message('warning', 'Esta reserva ya fue atendida y finalizada anteriormente.');
                redirect('/modules/reservas/');
            }

            // Rechazar reservas de fechas pasadas
            if ($fechaRes < $fechaHoy) {
                if (($reservaActiva['estado'] ?? '') === 'Pendiente') {
                    $supabase->update('reservas', [
                        'estado' => 'Cancelada',
                        'observaciones' => trim(($reservaActiva['observaciones'] ?? '') . ' [Cancelada automáticamente: fecha pasada]')
                    ], 'id=eq.' . $reserva_id, $authToken);
                }
                set_flash_message('danger', 'No se puede atender ni convertir una reserva de una fecha pasada (' . date('d/m/Y', strtotime($fechaRes)) . ').');
                redirect('/modules/reservas/');
            }

            // Rechazar reservas de fechas futuras
            if ($fechaRes > $fechaHoy) {
                set_flash_message('warning', 'No se puede atender la reserva antes del día programado (' . date('d/m/Y', strtotime($fechaRes)) . ').');
                redirect('/modules/reservas/');
            }

            // Rechazar antes de la hora si está Pendiente
            if (($reservaActiva['estado'] ?? '') === 'Pendiente' && $ahoraTs < $resTs) {
                set_flash_message('warning', 'Aún no es la hora de la reserva (' . date('H:i', $resTs) . '). La comanda podrá habilitarse cuando llegue el horario programado.');
                redirect('/modules/reservas/');
            }

            // Autocancelar y rechazar si superó los 30 min sin confirmación
            if (($reservaActiva['estado'] ?? '') === 'Pendiente' && $ahoraTs > $limiteTs) {
                $supabase->update('reservas', [
                    'estado' => 'Cancelada',
                    'observaciones' => trim(($reservaActiva['observaciones'] ?? '') . ' [Cancelada automáticamente por superar los 30 min de tolerancia sin confirmación]')
                ], 'id=eq.' . $reserva_id, $authToken);

                set_flash_message('danger', 'La reserva ha vencido por superar los 30 minutos de tolerancia sin confirmación y fue cancelada automáticamente.');
                redirect('/modules/reservas/');
            }

            // Asignar datos del servidor (Inmutables por manipulación de formulario)
            $preselectedMesaId = $mesaIdReserva;
            $prefilledClienteId = $clienteIdReserva;
            $prefilledObservaciones = trim(preg_replace('/\[PREORDEN:(.*?)\]$/s', '', $reservaActiva['observaciones'] ?? ''));

            // Asegurar que el cliente de la reserva esté en la lista si no estuviera activo
            if (!empty($clienteIdReserva)) {
                $foundCli = false;
                foreach ($clientes as $c) {
                    if ($c['id'] === $clienteIdReserva) {
                        $foundCli = true;
                        break;
                    }
                }
                if (!$foundCli && !empty($reservaActiva['clientes'])) {
                    $clientes[] = $reservaActiva['clientes'];
                }
            }

            // CARGAR PLATILLOS DE LA PREORDEN (Fuente Principal: detalles_reserva)
            $resDet = $supabase->select('detalles_reserva', '*,productos(id,nombre,precio,disponible,estado)', [
                'reserva_id' => 'eq.' . $reserva_id
            ], $authToken);

            if ($resDet['success'] && !empty($resDet['data'])) {
                $cartMerged = [];
                foreach ($resDet['data'] as $detRow) {
                    $pId = $detRow['producto_id'];
                    $pName = $detRow['productos']['nombre'] ?? 'Platillo';
                    $pPrice = (float)($detRow['productos']['precio'] ?? $detRow['precio_unitario'] ?? 0);
                    $cant = max(1, (int)($detRow['cantidad'] ?? 1));
                    $notas = $detRow['notas'] ?? '';
                    
                    $key = $pId . '_' . $notas;
                    if (isset($cartMerged[$key])) {
                        $cartMerged[$key]['cantidad'] += $cant;
                    } else {
                        $cartMerged[$key] = [
                            'id' => $pId,
                            'nombre' => $pName,
                            'precio' => $pPrice,
                            'cantidad' => $cant,
                            'notas' => $notas
                        ];
                    }
                }
                $initialCart = array_values($cartMerged);
            } elseif (preg_match('/\[PREORDEN:(.*?)\]$/s', $reservaActiva['observaciones'] ?? '', $matches)) {
                // Fallback para reservas heredadas con formato JSON en observaciones
                $decodedJsonStr = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $parsedJson = json_decode($decodedJsonStr, true);
                if ($parsedJson && !empty($parsedJson['items']) && is_array($parsedJson['items'])) {
                    $pIds = array_map(function($i) { return $i['producto_id']; }, $parsedJson['items']);
                    $resProds = $supabase->select('productos', 'id,nombre,precio', [
                        'id' => 'in.(' . implode(',', array_unique($pIds)) . ')'
                    ], $authToken);
                    $pMap = [];
                    if ($resProds['success'] && !empty($resProds['data'])) {
                        foreach ($resProds['data'] as $pr) {
                            $pMap[$pr['id']] = $pr;
                        }
                    }
                    $cartMerged = [];
                    foreach ($parsedJson['items'] as $it) {
                        $pId = $it['producto_id'];
                        $pInfo = $pMap[$pId] ?? null;
                        $pName = $pInfo['nombre'] ?? ($it['nombre'] ?? 'Platillo');
                        $pPrice = $pInfo ? (float)$pInfo['precio'] : (float)($it['precio_unitario'] ?? 0);
                        $cant = max(1, (int)($it['cantidad'] ?? 1));
                        $notas = $it['notas'] ?? '';
                        
                        $key = $pId . '_' . $notas;
                        if (isset($cartMerged[$key])) {
                            $cartMerged[$key]['cantidad'] += $cant;
                        } else {
                            $cartMerged[$key] = [
                                'id' => $pId,
                                'nombre' => $pName,
                                'precio' => $pPrice,
                                'cantidad' => $cant,
                                'notas' => $notas
                            ];
                        }
                    }
                    $initialCart = array_values($cartMerged);
                }
            }
        }
    } elseif (empty($reserva_id) && !empty($preselectedMesaId) && is_valid_uuid($preselectedMesaId)) {
        // Si se accede con mesa_id y la mesa ya tiene comanda activa en curso, redirigir
        if (isset($mesasOcupadasMap[$preselectedMesaId])) {
            $existingOrder = $mesasOcupadasMap[$preselectedMesaId];
            $mNumero = '';
            foreach ($mesas as $m) {
                if ($m['id'] === $preselectedMesaId) {
                    $mNumero = $m['numero'];
                    break;
                }
            }
            set_flash_message('info', 'La Mesa #' . $mNumero . ' ya cuenta con una comanda activa en curso (#' . substr($existingOrder['id'], 0, 8) . '). Se redirigió a la comanda existente.');
            redirect('/modules/pedidos/view.php?id=' . $existingOrder['id']);
        }
    }
}

// Procesar Guardado del Pedido (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reserva_id_post = clean_input($_POST['reserva_id'] ?? '');
    $mesa_id_post = clean_input($_POST['mesa_id'] ?? '');
    $cliente_id_input = clean_input($_POST['cliente_id'] ?? '');
    $observaciones = clean_input($_POST['observaciones'] ?? '');
    
    // Recibir arrays de productos enviados desde el carrito
    $rawProductIds = $_POST['prod_id'] ?? [];
    $rawCantidades = $_POST['prod_cant'] ?? [];
    $rawNotas = $_POST['prod_notas'] ?? [];

    $usuario_id = $_SESSION['user']['id'] ?? null;
    $mesa_id_final = null;
    $cliente_id_final = null;
    $esDesdeReserva = false;

    // 1. Validar autenticación de usuario
    if (empty($usuario_id) || !is_valid_uuid($usuario_id)) {
        $error = 'No se pudo identificar la sesión del usuario para registrar la comanda.';
    }

    // 2. SEGURIDAD REGLA 6: Si proviene de una reserva, la mesa y el cliente se obtienen del servidor
    if (!$error && !empty($reserva_id_post)) {
        if (!is_valid_uuid($reserva_id_post)) {
            $error = 'El identificador de reserva proporcionado no es válido.';
        } else {
            $resRvPost = $supabase->select('reservas', '*,mesas(id,numero),clientes(id,nombre,apellido)', [
                'id' => 'eq.' . $reserva_id_post
            ], $authToken);

            if (!$resRvPost['success'] || empty($resRvPost['data'])) {
                $error = 'La reserva asociada no fue encontrada en la base de datos.';
            } else {
                $reservaDb = $resRvPost['data'][0];
                $fechaHoyPost = date('Y-m-d');
                $ahoraTsPost = time();
                $fechaResDb = $reservaDb['fecha'] ?? '';
                $horaResDb = !empty($reservaDb['hora']) ? date('H:i:s', strtotime($reservaDb['hora'])) : '00:00:00';
                $resTsDb = strtotime($fechaResDb . ' ' . $horaResDb);
                $limiteTsDb = $resTsDb + (30 * 60);

                if (($reservaDb['estado'] ?? '') === 'Atendida') {
                    $error = 'Esta reserva ya fue atendida previamente y cuenta con una comanda generada.';
                } elseif (($reservaDb['estado'] ?? '') === 'Cancelada') {
                    $error = 'No se puede generar una comanda a partir de una reserva cancelada.';
                } elseif ($fechaResDb < $fechaHoyPost) {
                    if (($reservaDb['estado'] ?? '') === 'Pendiente') {
                        $supabase->update('reservas', [
                            'estado' => 'Cancelada',
                            'observaciones' => trim(($reservaDb['observaciones'] ?? '') . ' [Cancelada automáticamente: fecha pasada]')
                        ], 'id=eq.' . $reserva_id_post, $authToken);
                    }
                    $error = 'No se puede atender ni convertir una reserva de una fecha pasada (' . date('d/m/Y', strtotime($fechaResDb)) . ').';
                } elseif ($fechaResDb > $fechaHoyPost) {
                    $error = 'No se puede atender la reserva antes del día programado (' . date('d/m/Y', strtotime($fechaResDb)) . ').';
                } elseif (($reservaDb['estado'] ?? '') === 'Pendiente' && $ahoraTsPost > $limiteTsDb) {
                    $supabase->update('reservas', [
                        'estado' => 'Cancelada',
                        'observaciones' => trim(($reservaDb['observaciones'] ?? '') . ' [Cancelada automáticamente por superar los 30 min de tolerancia sin confirmación]')
                    ], 'id=eq.' . $reserva_id_post, $authToken);
                    $error = 'La reserva ha vencido por superar los 30 minutos de tolerancia sin confirmación y fue cancelada automáticamente.';
                } else {
                    $mesa_id_final = $reservaDb['mesa_id'];
                    $cliente_id_final = $reservaDb['cliente_id'] ?: null;
                    $esDesdeReserva = true;
                }
            }
        }
    } else {
        // Pedido manual sin reserva
        $mesa_id_final = $mesa_id_post;
        $cliente_id_final = (!empty($cliente_id_input) && is_valid_uuid($cliente_id_input)) ? $cliente_id_input : null;
    }

    // 3. Validar mesa asignada
    if (!$error) {
        if (empty($mesa_id_final) || !is_valid_uuid($mesa_id_final)) {
            $error = 'Por favor selecciona una mesa válida para el pedido.';
        } else {
            // Validar que la mesa no tenga ya una comanda activa en curso (integridad)
            $resCheckMesa = $supabase->select('pedidos', 'id,estado', [
                'mesa_id' => 'eq.' . $mesa_id_final,
                'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)'
            ], $authToken);

            if ($resCheckMesa['success'] && !empty($resCheckMesa['data'])) {
                $error = 'La mesa seleccionada ya cuenta con una comanda activa en curso. Para registrar un nuevo pedido, la mesa debe estar disponible.';
            }
        }
    }

    // 4. Validar cliente si se especificó manualmente
    if (!$error && !$esDesdeReserva && !empty($cliente_id_input) && !is_valid_uuid($cliente_id_input)) {
        $error = 'El cliente seleccionado no tiene un identificador válido.';
    }

    // 5. Validar que exista al menos un producto en la comanda
    if (!$error && (empty($rawProductIds) || !is_array($rawProductIds) || count($rawProductIds) === 0)) {
        $error = 'El pedido debe contener al menos un producto o platillo.';
    }

    if (!$error) {
        // 6. Validar y estructurar cada ítem del carrito
        $itemsAProcesar = [];
        $idsParaConsultar = [];

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
                $error = 'La cantidad de cada producto debe ser un número entero mayor o igual a 1.';
                break;
            }

            $itemsAProcesar[] = [
                'producto_id' => $pId,
                'cantidad' => $pCant,
                'notas' => $pNota
            ];
            $idsParaConsultar[] = $pId;
        }

        if (!$error && empty($itemsAProcesar)) {
            $error = 'No se recibieron productos válidos para registrar el pedido.';
        }

        if (!$error) {
            // 7. SEGURIDAD CRÍTICA DE PRECIOS: Consultar precios y estados REALES desde Supabase
            $productosDbMap = [];
            $resVerif = $supabase->select('productos', '*', [
                'id' => 'in.(' . implode(',', array_unique($idsParaConsultar)) . ')'
            ], $authToken);

            if ($resVerif['success'] && !empty($resVerif['data'])) {
                foreach ($resVerif['data'] as $pDb) {
                    $productosDbMap[$pDb['id']] = $pDb;
                }
            }

            $totalCalculado = 0.0;
            $detallesValidados = [];

            foreach ($itemsAProcesar as $item) {
                $prodId = $item['producto_id'];
                
                if (!isset($productosDbMap[$prodId])) {
                    $error = 'Uno de los productos solicitados no existe en el catálogo.';
                    break;
                }

                $prodInfo = $productosDbMap[$prodId];

                // Verificar estado activo
                if (!($prodInfo['estado'] ?? true)) {
                    $error = 'El producto "' . htmlspecialchars($prodInfo['nombre']) . '" se encuentra inactivo y no puede ser ordenado.';
                    break;
                }

                // Verificar disponibilidad en cocina
                if (!($prodInfo['disponible'] ?? true)) {
                    $error = 'El producto "' . htmlspecialchars($prodInfo['nombre']) . '" está agotado temporalmente en cocina.';
                    break;
                }

                $precioOficial = (float)($prodInfo['precio'] ?? 0);
                $subtotal = round($item['cantidad'] * $precioOficial, 2);
                $totalCalculado += $subtotal;

                $detallesValidados[] = [
                    'producto_id' => $prodId,
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $precioOficial,
                    'subtotal' => $subtotal,
                    'notas' => $item['notas']
                ];
            }

            if (!$error) {
                $totalCalculado = round($totalCalculado, 2);

                // 8. Insertar el Pedido maestro en Supabase
                $obsFinal = $observaciones;
                if ($esDesdeReserva) {
                    $prefijoObs = 'Reserva #' . substr($reserva_id_post, 0, 8);
                    if (!empty($obsFinal)) {
                        $obsFinal = '[' . $prefijoObs . '] ' . $obsFinal;
                    } else {
                        $obsFinal = 'Comanda generada desde ' . $prefijoObs;
                    }
                }

                $pedidoData = [
                    'mesa_id' => $mesa_id_final,
                    'usuario_id' => $usuario_id,
                    'total' => $totalCalculado,
                    'estado' => 'Pendiente',
                    'observaciones' => $obsFinal
                ];
                if ($cliente_id_final) {
                    $pedidoData['cliente_id'] = $cliente_id_final;
                }

                $resInsertPedido = $supabase->insert('pedidos', $pedidoData, $authToken);

                if ($resInsertPedido['success'] && !empty($resInsertPedido['data'])) {
                    $nuevoPedido = $resInsertPedido['data'][0];
                    $nuevoPedidoId = $nuevoPedido['id'];
                    $detallesExitosos = true;

                    // 9. Insertar cada detalle de la comanda
                    foreach ($detallesValidados as $det) {
                        $detData = [
                            'pedido_id' => $nuevoPedidoId,
                            'producto_id' => $det['producto_id'],
                            'cantidad' => $det['cantidad'],
                            'precio_unitario' => $det['precio_unitario'],
                            'subtotal' => $det['subtotal'],
                            'notas' => $det['notas']
                        ];

                        $resDet = $supabase->insert('detalles_pedido', $detData, $authToken);
                        if (!$resDet['success'] || empty($resDet['data'])) {
                            $detallesExitosos = false;
                            $error = 'Error al registrar los ítems de la comanda: ' . ($resDet['error'] ?? 'Error desconocido');
                            break;
                        }
                    }

                    if ($detallesExitosos) {
                        // 10. Actualizar estado de la reserva a 'Atendida' si proviene de una reserva
                        if ($esDesdeReserva) {
                            $supabase->update('reservas', ['estado' => 'Atendida'], 'id=eq.' . $reserva_id_post, $authToken);
                        }

                        // 11. Actualizar estado de la mesa a 'ocupada'
                        $supabase->update('mesas', ['estado' => 'ocupada'], 'id=eq.' . $mesa_id_final, $authToken);

                        set_flash_message('success', 'Comanda #' . substr($nuevoPedidoId, 0, 8) . ' registrada exitosamente por un total de Bs. ' . number_format($totalCalculado, 2) . ' y enviada a cocina.');
                        redirect('/modules/pedidos/view.php?id=' . $nuevoPedidoId);
                    } else {
                        // ROLLBACK / INTEGRIDAD: Si falló algún detalle, eliminar registros creados
                        $supabase->delete('detalles_pedido', 'pedido_id=eq.' . $nuevoPedidoId, $authToken);
                        $supabase->delete('pedidos', 'id=eq.' . $nuevoPedidoId, $authToken);
                    }
                } else {
                    $error = 'Error al registrar el pedido en Supabase: ' . ($resInsertPedido['error'] ?? 'Error de conexión');
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
        <!-- Encabezado del POS -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div>
                <a href="<?= BASE_URL ?>/modules/pedidos/" class="btn btn-outline-secondary btn-sm mb-2">
                    <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Pedidos
                </a>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Punto de Venta & Comanda (POS)</h3>
                <p class="text-muted mb-0">Selecciona los platillos y bebidas para registrar la orden en cocina y salón</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-secondary border px-3 py-2">
                    <i class="bi bi-calendar-event me-1"></i> <?= date('d/m/Y') ?>
                </span>
                <span class="badge bg-success-subtle text-success border border-success px-3 py-2">
                    <i class="bi bi-person-check-fill me-1"></i> <?= htmlspecialchars($currentUser['nombre'] ?? 'Mesero') ?>
                </span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-3 shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
            </div>
        <?php endif; ?>

        <!-- Banner Informativo si la comanda proviene de una Reserva -->
        <?php if ($reservaActiva): ?>
            <?php
            $cliResNombre = trim(($reservaActiva['clientes']['nombre'] ?? 'Cliente') . ' ' . ($reservaActiva['clientes']['apellido'] ?? ''));
            $mesaResNumero = $reservaActiva['mesas']['numero'] ?? '-';
            $mesaResUbic = $reservaActiva['mesas']['ubicacion'] ?? 'Salón';
            $mesaResCap = $reservaActiva['mesas']['capacidad'] ?? '-';
            $fechaResForm = date('d/m/Y', strtotime($reservaActiva['fecha']));
            $horaResForm = !empty($reservaActiva['hora']) ? date('H:i', strtotime($reservaActiva['hora'])) : '';
            ?>
            <div class="card-custom p-3 mb-3 border-start border-primary border-4 bg-white shadow-xs">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <span class="avatar-circle" style="background: rgba(37, 99, 235, 0.12); color: var(--primary-color); width: 44px; height: 44px; font-size: 1.25rem;">
                            <i class="bi bi-calendar-check-fill"></i>
                        </span>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <strong class="text-dark fs-6">Comanda vinculada a Reserva #<?= substr($reservaActiva['id'], 0, 8) ?></strong>
                                <span class="badge bg-primary text-white">Mesa #<?= $mesaResNumero ?> Asignada</span>
                            </div>
                            <small class="text-muted d-block">
                                Cliente: <strong class="text-dark"><?= htmlspecialchars($cliResNombre) ?></strong> 
                                | Mesa: <strong>#<?= $mesaResNumero ?> (<?= htmlspecialchars($mesaResUbic) ?> · Cap. <?= $mesaResCap ?> pers.)</strong> 
                                | Horario: <strong><?= $fechaResForm ?> <?= $horaResForm ?></strong>
                            </small>
                        </div>
                    </div>
                    <div>
                        <?php if (!empty($initialCart)): ?>
                            <span class="badge bg-success-subtle text-success border border-success px-3 py-2">
                                <i class="bi bi-bag-check me-1"></i> Preorden Cargada (<?= count($initialCart) ?> ítems)
                            </span>
                        <?php else: ?>
                            <span class="badge bg-light text-secondary border px-3 py-2">
                                <i class="bi bi-cart-dash me-1"></i> Sin Preorden Previa (Comanda Abierta)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form action="" method="POST" id="posForm" autocomplete="off">
            <?php if ($reservaActiva): ?>
                <!-- Campo oculto para asegurar la relación inmutable con la reserva -->
                <input type="hidden" name="reserva_id" value="<?= htmlspecialchars($reservaActiva['id']) ?>">
                <input type="hidden" name="mesa_id" value="<?= htmlspecialchars($reservaActiva['mesa_id']) ?>">
                <input type="hidden" name="cliente_id" value="<?= htmlspecialchars($reservaActiva['cliente_id'] ?? '') ?>">
            <?php endif; ?>

            <div class="row g-4">
                <!-- Columna Izquierda: Catálogo de Productos y Filtros -->
                <div class="col-lg-7 col-xl-8">
                    <!-- Configuración de Mesa y Cliente -->
                    <div class="card-custom p-3 mb-3">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="selectMesa">
                                    <i class="bi bi-table me-1 text-primary"></i> Mesa de Salón <span class="text-danger">*</span>
                                </label>
                                <?php if ($reservaActiva): ?>
                                    <!-- Mesa bloqueada e inmutable heredada directamente de la reserva -->
                                    <div class="input-group">
                                        <span class="input-group-text bg-light text-primary"><i class="bi bi-lock-fill"></i></span>
                                        <input type="text" class="form-control bg-light fw-bold text-dark" readonly 
                                               value="Mesa #<?= $reservaActiva['mesas']['numero'] ?? '-' ?> (<?= htmlspecialchars($reservaActiva['mesas']['ubicacion'] ?? 'Salón') ?> · Cap: <?= $reservaActiva['mesas']['capacidad'] ?? '-' ?> pers.) — RESERVA">
                                    </div>
                                    <small class="text-muted" style="font-size: 0.75rem;">Mesa asignada automáticamente por la reserva.</small>
                                <?php else: ?>
                                    <!-- Selector libre de mesas para pedidos normales -->
                                    <select name="mesa_id" id="selectMesa" class="form-select" required>
                                        <option value="">-- Seleccionar Mesa Disponible --</option>
                                        <?php foreach ($mesas as $m): ?>
                                            <?php 
                                            $mId = $m['id'];
                                            $tienePedidoActivo = isset($mesasOcupadasMap[$mId]);
                                            $estaOcupada = ($m['estado'] === 'ocupada') || $tienePedidoActivo;
                                            $isSelected = ($preselectedMesaId === $mId) || (($_POST['mesa_id'] ?? '') === $mId);
                                            ?>
                                            <?php if ($estaOcupada): ?>
                                                <option value="<?= $mId ?>" disabled class="text-danger bg-light">
                                                    Mesa #<?= $m['numero'] ?> (<?= htmlspecialchars($m['ubicacion'] ?? 'Salón') ?> - Cap: <?= $m['capacidad'] ?>) — [OCUPADA - Comanda en curso]
                                                </option>
                                            <?php else: ?>
                                                <option value="<?= $mId ?>" <?= $isSelected ? 'selected' : '' ?>>
                                                    Mesa #<?= $m['numero'] ?> (<?= htmlspecialchars($m['ubicacion'] ?? 'Salón') ?> - Cap: <?= $m['capacidad'] ?> pers.) — DISPONIBLE
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted" style="font-size: 0.75rem;">Solo se pueden abrir comandas en mesas disponibles.</small>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="selectCliente">
                                    <i class="bi bi-person me-1 text-secondary"></i> Cliente (Opcional)
                                </label>
                                <?php if ($reservaActiva): ?>
                                    <!-- Cliente bloqueado de la reserva -->
                                    <div class="input-group">
                                        <span class="input-group-text bg-light text-secondary"><i class="bi bi-person-check-fill"></i></span>
                                        <input type="text" class="form-control bg-light fw-bold text-dark" readonly 
                                               value="<?= htmlspecialchars(trim(($reservaActiva['clientes']['nombre'] ?? 'Cliente') . ' ' . ($reservaActiva['clientes']['apellido'] ?? ''))) ?> (Doc: <?= htmlspecialchars($reservaActiva['clientes']['documento'] ?? 'S/N') ?>)">
                                    </div>
                                    <small class="text-muted" style="font-size: 0.75rem;">Titular de la reserva asignado automáticamente.</small>
                                <?php else: ?>
                                    <select name="cliente_id" id="selectCliente" class="form-select">
                                        <option value="">-- Cliente Ocasional / Salón --</option>
                                        <?php foreach ($clientes as $cli): ?>
                                            <?php
                                            $cliSelected = ($prefilledClienteId === $cli['id']) || (($_POST['cliente_id'] ?? '') === $cli['id']);
                                            ?>
                                            <option value="<?= $cli['id'] ?>" <?= $cliSelected ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cli['nombre'] . ' ' . $cli['apellido']) ?> (Doc: <?= htmlspecialchars($cli['documento'] ?: 'S/N') ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted" style="font-size: 0.75rem;">Si no se selecciona cliente, se asignará a Salón.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Filtro por Categorías y Búsqueda en Vivo -->
                    <div class="card-custom p-3 mb-3">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-5">
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                                    <input type="text" id="searchProductInput" class="form-control bg-light border-start-0" placeholder="Buscar por nombre o descripción...">
                                </div>
                            </div>
                            <div class="col-md-7">
                                <div class="d-flex gap-1 overflow-auto pb-1" id="categoryFilterContainer" style="scrollbar-width: thin;">
                                    <button type="button" class="btn btn-sm btn-primary category-btn active px-3" data-category="all">
                                        Todos (<?= count($productos) ?>)
                                    </button>
                                    <?php foreach ($categorias as $cat): ?>
                                        <?php
                                        $catProdCount = 0;
                                        foreach ($productos as $pCheck) {
                                            if (($pCheck['categoria_id'] ?? '') === $cat['id']) {
                                                $catProdCount++;
                                            }
                                        }
                                        ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary category-btn text-nowrap" data-category="<?= htmlspecialchars($cat['id']) ?>">
                                            <?= htmlspecialchars($cat['nombre']) ?> (<?= $catProdCount ?>)
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cuadrícula de Platillos & Bebidas -->
                    <div class="row g-3" id="productsGrid" style="max-height: 560px; overflow-y: auto; padding-right: 4px;">
                        <?php if (empty($productos)): ?>
                            <div class="col-12 text-center py-5 text-muted bg-white rounded shadow-sm">
                                <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary"></i>
                                No hay productos registrados en el menú actualmente.
                            </div>
                        <?php else: ?>
                            <?php foreach ($productos as $prod): ?>
                                <?php
                                $pId = $prod['id'];
                                $pNombre = $prod['nombre'];
                                $pDesc = $prod['descripcion'] ?? '';
                                $pPrecio = (float)$prod['precio'];
                                $pCatId = $prod['categoria_id'] ?? '';
                                $pCatNombre = $prod['categorias']['nombre'] ?? 'General';
                                $pDisponible = (bool)($prod['disponible'] ?? true);
                                ?>
                                <div class="col-sm-6 col-xl-4 product-card-wrapper" 
                                     data-name="<?= strtolower(htmlspecialchars($pNombre . ' ' . $pDesc)) ?>" 
                                     data-category="<?= htmlspecialchars($pCatId) ?>">
                                    <div class="card-custom h-100 p-3 d-flex flex-column justify-content-between border position-relative transition <?= !$pDisponible ? 'opacity-75 bg-light' : '' ?>" style="border-radius: var(--radius-md);">
                                        <div>
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <span class="badge badge-role" style="font-size: 0.7rem;">
                                                    <i class="bi bi-tag me-1"></i><?= htmlspecialchars($pCatNombre) ?>
                                                </span>
                                                <?php if ($pDisponible): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success" style="font-size: 0.68rem;">
                                                        <i class="bi bi-check-circle me-1"></i>Disponible
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning border border-warning" style="font-size: 0.68rem;">
                                                        <i class="bi bi-slash-circle me-1"></i>Agotado
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($pNombre) ?></h6>
                                            <p class="text-muted small mb-2 text-truncate-2" style="font-size: 0.78rem; min-height: 36px;">
                                                <?= htmlspecialchars($pDesc ?: 'Platillo preparado al momento con los mejores ingredientes.') ?>
                                            </p>
                                        </div>

                                        <div class="pt-2 border-top">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <small class="text-muted">Precio:</small>
                                                <span class="fw-bold text-success fs-5">Bs. <?= number_format($pPrecio, 2) ?></span>
                                            </div>

                                            <?php if ($pDisponible): ?>
                                                <button type="button" class="btn btn-sm btn-primary-custom w-100 btn-add-product" 
                                                        data-id="<?= $pId ?>" 
                                                        data-name="<?= htmlspecialchars($pNombre) ?>" 
                                                        data-price="<?= $pPrecio ?>">
                                                    <i class="bi bi-plus-circle me-1"></i> Agregar al Pedido
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-secondary w-100" disabled>
                                                    <i class="bi bi-slash-circle me-1"></i> Agotado en Cocina
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Columna Derecha: Comanda Actual & Carrito -->
                <div class="col-lg-5 col-xl-4">
                    <div class="card-custom p-3 sticky-top" style="top: 20px;">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                            <h5 class="fw-bold m-0" style="color: var(--secondary-color);">
                                <i class="bi bi-receipt me-1 text-primary"></i> Comanda Actual
                            </h5>
                            <button type="button" id="btnClearCart" class="btn btn-sm btn-link text-danger text-decoration-none p-0" title="Vaciar todos los productos">
                                <i class="bi bi-trash me-1"></i> Vaciar
                            </button>
                        </div>

                        <!-- Lista de Ítems en Carrito -->
                        <div id="cartItemsContainer" class="overflow-auto mb-3 pe-1" style="max-height: 380px; min-height: 220px;">
                            <div id="cartEmptyState" class="text-center py-5 text-muted">
                                <i class="bi bi-cart-x fs-1 d-block mb-2 text-secondary"></i>
                                <p class="mb-1 fw-semibold">Comanda Vacía</p>
                                <small class="text-muted">Haz clic en <strong>"Agregar al Pedido"</strong> en cualquier producto disponible del catálogo.</small>
                            </div>
                            <div id="cartList" class="d-flex flex-column gap-2 d-none">
                                <!-- Ítems dinámicos inyectados por JavaScript -->
                            </div>
                        </div>

                        <!-- Observaciones Generales de la Mesa -->
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted" for="inputObservaciones">
                                <i class="bi bi-chat-left-text me-1"></i> Observaciones Generales de la Mesa:
                            </label>
                            <textarea name="observaciones" id="inputObservaciones" class="form-control form-control-sm" rows="2" placeholder="Ej: Servir bebidas primero, comensal alérgico al maní, para llevar..."><?= htmlspecialchars($_POST['observaciones'] ?? $prefilledObservaciones) ?></textarea>
                        </div>

                        <!-- Resumen de Totales y Envío a Cocina -->
                        <div class="border-top pt-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Total Ítems:</span>
                                <strong id="cartTotalItems" class="text-dark">0</strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="fs-5 fw-bold text-dark">Total Comanda:</span>
                                <span class="fs-4 fw-bold text-success" id="cartTotalAmount">Bs. 0.00</span>
                            </div>

                            <button type="submit" id="btnSubmitOrder" class="btn btn-primary-custom w-100 py-2 fs-6" disabled>
                                <i class="bi bi-send-check me-2"></i> Confirmar y Enviar a Cocina
                            </button>
                            <small class="d-block text-center text-muted mt-2" style="font-size: 0.72rem;">
                                <i class="bi bi-shield-lock me-1"></i> Precios oficiales validados en Supabase
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
    // Inicializar Carrito (cargando preorden si proviene de una reserva)
    let cart = <?= json_encode($initialCart, JSON_UNESCAPED_UNICODE) ?> || [];
    let isSubmitting = false;

    const productsGrid = document.getElementById('productsGrid');
    const searchInput = document.getElementById('searchProductInput');
    const categoryBtns = document.querySelectorAll('.category-btn');
    const cartList = document.getElementById('cartList');
    const cartEmptyState = document.getElementById('cartEmptyState');
    const cartTotalItems = document.getElementById('cartTotalItems');
    const cartTotalAmount = document.getElementById('cartTotalAmount');
    const btnSubmitOrder = document.getElementById('btnSubmitOrder');
    const btnClearCart = document.getElementById('btnClearCart');
    const posForm = document.getElementById('posForm');
    const selectMesa = document.getElementById('selectMesa');

    // 1. Filtrado dinámico por búsqueda y categorías
    let currentCategory = 'all';
    let currentSearch = '';

    function filterProducts() {
        const productWrappers = document.querySelectorAll('.product-card-wrapper');
        let visibleCount = 0;

        productWrappers.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const cat = card.getAttribute('data-category') || '';

            const matchCat = (currentCategory === 'all' || cat === currentCategory);
            const matchSearch = (currentSearch === '' || name.includes(currentSearch));

            if (matchCat && matchSearch) {
                card.classList.remove('d-none');
                visibleCount++;
            } else {
                card.classList.add('d-none');
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            currentSearch = this.value.trim().toLowerCase();
            filterProducts();
        });
    }

    categoryBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            categoryBtns.forEach(b => {
                b.classList.remove('btn-primary', 'active');
                b.classList.add('btn-outline-secondary');
            });
            this.classList.remove('btn-outline-secondary');
            this.classList.add('btn-primary', 'active');

            currentCategory = this.getAttribute('data-category');
            filterProducts();
        });
    });

    // 2. Agregar Producto al Carrito
    document.querySelectorAll('.btn-add-product').forEach(btn => {
        btn.addEventListener('click', function() {
            const pId = this.getAttribute('data-id');
            const pName = this.getAttribute('data-name');
            const pPrice = parseFloat(this.getAttribute('data-price')) || 0;

            const existingIndex = cart.findIndex(item => item.id === pId);
            if (existingIndex > -1) {
                cart[existingIndex].cantidad += 1;
            } else {
                cart.push({
                    id: pId,
                    nombre: pName,
                    precio: pPrice,
                    cantidad: 1,
                    notas: ''
                });
            }

            renderCart();
        });
    });

    // 3. Renderizar Carrito y controles de comanda
    function renderCart() {
        cartList.innerHTML = '';

        if (!cart || cart.length === 0) {
            cartEmptyState.classList.remove('d-none');
            cartList.classList.add('d-none');
            btnSubmitOrder.disabled = true;
            cartTotalItems.textContent = '0';
            cartTotalAmount.textContent = 'Bs. 0.00';
            return;
        }

        cartEmptyState.classList.add('d-none');
        cartList.classList.remove('d-none');
        btnSubmitOrder.disabled = false;

        let totalQty = 0;
        let grandTotal = 0;

        cart.forEach((item, index) => {
            const cant = parseInt(item.cantidad) || 1;
            const precio = parseFloat(item.precio) || 0;
            const subtotal = cant * precio;
            totalQty += cant;
            grandTotal += subtotal;

            const itemDiv = document.createElement('div');
            itemDiv.className = 'p-2 border rounded bg-white shadow-xs';
            itemDiv.innerHTML = `
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div style="max-width: 68%;">
                        <strong class="text-dark small d-block text-truncate" title="${escapeHtml(item.nombre)}">${escapeHtml(item.nombre)}</strong>
                        <div class="text-muted small">Bs. ${precio.toFixed(2)} c/u</div>
                    </div>
                    <div class="text-end">
                        <strong class="text-success small">Bs. ${subtotal.toFixed(2)}</strong>
                        <button type="button" class="btn btn-sm text-danger p-0 ms-2 btn-remove-item" data-index="${index}" title="Eliminar del pedido">
                            <i class="bi bi-trash-fill"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 mt-2">
                    <div class="input-group input-group-sm" style="width: 105px;">
                        <button type="button" class="btn btn-outline-secondary btn-minus" data-index="${index}">-</button>
                        <input type="number" min="1" step="1" class="form-control text-center p-1 cart-qty-input" data-index="${index}" value="${cant}" style="font-size: 0.85rem;">
                        <button type="button" class="btn btn-outline-secondary btn-plus" data-index="${index}">+</button>
                    </div>
                    <div class="flex-grow-1">
                        <input type="text" class="form-control form-control-sm cart-note-input" data-index="${index}" placeholder="Nota (ej: sin cebolla)..." value="${escapeHtml(item.notas || '')}" style="font-size: 0.75rem;">
                    </div>
                </div>

                <!-- Inputs ocultos para envío seguro al backend PHP -->
                <input type="hidden" name="prod_id[]" value="${item.id}">
                <input type="hidden" name="prod_cant[]" value="${cant}">
                <input type="hidden" name="prod_notas[]" value="${escapeHtml(item.notas || '')}">
            `;
            cartList.appendChild(itemDiv);
        });

        cartTotalItems.textContent = totalQty;
        cartTotalAmount.textContent = 'Bs. ' + grandTotal.toFixed(2);

        attachCartEvents();
    }

    function attachCartEvents() {
        // Botón menos (-)
        document.querySelectorAll('.btn-minus').forEach(btn => {
            btn.addEventListener('click', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                if (cart[idx].cantidad > 1) {
                    cart[idx].cantidad -= 1;
                    renderCart();
                } else {
                    cart.splice(idx, 1);
                    renderCart();
                }
            });
        });

        // Botón más (+)
        document.querySelectorAll('.btn-plus').forEach(btn => {
            btn.addEventListener('click', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                cart[idx].cantidad = (parseInt(cart[idx].cantidad) || 1) + 1;
                renderCart();
            });
        });

        // Modificación directa de cantidad
        document.querySelectorAll('.cart-qty-input').forEach(input => {
            input.addEventListener('change', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                let val = parseInt(this.value);
                if (isNaN(val) || val < 1) val = 1;
                cart[idx].cantidad = val;
                renderCart();
            });
        });

        // Modificación de notas individuales
        document.querySelectorAll('.cart-note-input').forEach(input => {
            input.addEventListener('input', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                cart[idx].notas = this.value;
                const hiddenNote = cartList.querySelectorAll('input[name="prod_notas[]"]')[idx];
                if (hiddenNote) hiddenNote.value = this.value;
            });
        });

        // Eliminar ítem
        document.querySelectorAll('.btn-remove-item').forEach(btn => {
            btn.addEventListener('click', function() {
                const idx = parseInt(this.getAttribute('data-index'));
                cart.splice(idx, 1);
                renderCart();
            });
        });
    }

    // 4. Vaciar Carrito completo
    if (btnClearCart) {
        btnClearCart.addEventListener('click', function() {
            if (cart.length > 0) {
                if (confirm('¿Desea vaciar todos los productos de la comanda?')) {
                    cart = [];
                    renderCart();
                }
            }
        });
    }

    // 5. Validaciones y Protección contra Doble Envío
    if (posForm) {
        posForm.addEventListener('submit', function(e) {
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }

            if (selectMesa && !selectMesa.value) {
                e.preventDefault();
                alert('Por favor selecciona una mesa disponible antes de enviar la comanda.');
                selectMesa.focus();
                return false;
            }

            if (!cart || cart.length === 0) {
                e.preventDefault();
                alert('Debes agregar al menos un producto a la comanda.');
                return false;
            }

            // Validar que todas las cantidades sean válidas
            for (let i = 0; i < cart.length; i++) {
                if (cart[i].cantidad < 1 || isNaN(cart[i].cantidad)) {
                    e.preventDefault();
                    alert('Todas las cantidades deben ser números enteros mayores o iguales a 1.');
                    return false;
                }
            }

            // Activar bloqueo de doble clic y feedback visual
            isSubmitting = true;
            btnSubmitOrder.disabled = true;
            btnSubmitOrder.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Guardando comanda...';
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

    // Renderizar inicialmente el carrito (cargando preorden si existiera)
    renderCart();
});
</script>
