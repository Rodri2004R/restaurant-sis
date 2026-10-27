<?php
$pageTitle = 'Editar Reserva';
$currentModule = 'reservas';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Recepcionista', 'Mesero']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$id = clean_input($_GET['id'] ?? '');

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'ID de reserva no especificado o formato de identificador inválido.');
    redirect('/modules/reservas/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;
$reserva = null;
$clientes = [];
$mesas = [];

if ($supabase->isConfigured()) {
    $resRes = $supabase->select('reservas', '*,clientes(nombre,apellido,documento,telefono),mesas(numero,capacidad,ubicacion)', ['id' => 'eq.' . $id], $authToken);
    if ($resRes['success'] && !empty($resRes['data'])) {
        $reserva = $resRes['data'][0];
    }

    // Obtener clientes activos (US-006)
    $resCli = $supabase->select('clientes', '*', ['estado' => 'eq.true', 'order' => 'nombre.asc'], $authToken);
    if ($resCli['success']) {
        $clientes = $resCli['data'];
    }

    // Asegurar que el cliente actualmente asignado esté en la lista si no estuviera activo
    if (!empty($reserva['cliente_id'])) {
        $foundCurrent = false;
        foreach ($clientes as $c) {
            if ($c['id'] === $reserva['cliente_id']) {
                $foundCurrent = true;
                break;
            }
        }
        if (!$foundCurrent) {
            $resCurrentCli = $supabase->select('clientes', '*', ['id' => 'eq.' . $reserva['cliente_id']], $authToken);
            if ($resCurrentCli['success'] && !empty($resCurrentCli['data'])) {
                $clientes[] = $resCurrentCli['data'][0];
            }
        }
    }

    $resMesas = $supabase->select('mesas', '*', ['order' => 'numero.asc'], $authToken);
    if ($resMesas['success']) {
        $mesas = $resMesas['data'];
    }
}

if (!$reserva) {
    set_flash_message('danger', 'Reserva no encontrada en la base de datos.');
    redirect('/modules/reservas/');
}

// Validación backend (Problema 2): Impedir edición de reservas cuya fecha y hora ya pasaron o estén cerradas
$fechaResOriginal = $reserva['fecha'];
$horaResOriginal = !empty($reserva['hora']) ? date('H:i', strtotime($reserva['hora'])) : '00:00';
$reservaTimestampOriginal = strtotime($fechaResOriginal . ' ' . $horaResOriginal);
$ahoraTimestamp = time();

if ($reservaTimestampOriginal < $ahoraTimestamp) {
    set_flash_message('warning', 'No se puede editar una reserva cuya fecha y hora ya han transcurrido (' . date('d/m/Y', strtotime($fechaResOriginal)) . ' ' . $horaResOriginal . '). Las reservas pasadas se conservan únicamente para consulta histórica.');
    redirect('/modules/reservas/');
}

if (in_array($reserva['estado'] ?? '', ['Atendida', 'Cancelada'], true)) {
    set_flash_message('warning', 'No se puede editar una reserva que ya se encuentra en estado "' . $reserva['estado'] . '".');
    redirect('/modules/reservas/');
}

$estadosValidos = ['Pendiente', 'Confirmada', 'Atendida', 'Cancelada'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cliente_id = clean_input($_POST['cliente_id'] ?? '');
    $fecha = clean_input($_POST['fecha'] ?? '');
    $hora = clean_input($_POST['hora'] ?? '');
    $mesa_id = clean_input($_POST['mesa_id'] ?? '');
    $cantidad_personas = isset($_POST['cantidad_personas']) && is_numeric($_POST['cantidad_personas']) ? (int)$_POST['cantidad_personas'] : 0;
    $estado = clean_input($_POST['estado'] ?? 'Pendiente');
    $observaciones = clean_input($_POST['observaciones'] ?? '');

    $fechaActual = date('Y-m-d');
    $nuevoTimestamp = strtotime($fecha . ' ' . $hora);

    // 2. Validación de campos requeridos
    if (empty($cliente_id) || empty($fecha) || empty($hora) || empty($mesa_id) || $cantidad_personas <= 0) {
        $error = 'Por favor completa todos los campos requeridos (Cliente, Mesa, Fecha, Hora y Cantidad de personas).';
    }
    // 3. Validación de UUID para cliente_id
    elseif (!is_valid_uuid($cliente_id)) {
        $error = 'El cliente seleccionado no tiene un identificador válido.';
    }
    // 4. Validación de UUID para mesa_id
    elseif (!is_valid_uuid($mesa_id)) {
        $error = 'La mesa seleccionada no tiene un identificador válido.';
    }
    // 5. Validación de formato de fecha YYYY-MM-DD
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $error = 'El formato de fecha no es válido (debe ser AAAA-MM-DD).';
    }
    // 6. Rechazar fechas anteriores a la fecha actual
    elseif ($fecha < $fechaActual) {
        $error = 'No se permiten reservas con fecha anterior a la fecha actual (' . date('d/m/Y', strtotime($fechaActual)) . ').';
    }
    // 7. Validación de formato de hora HH:MM (00:00 a 23:59)
    elseif (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora)) {
        $error = 'El formato de hora debe ser HH:MM (entre 00:00 y 23:59).';
    }
    // 8. Rechazar fecha y hora pasadas combinadas
    elseif ($nuevoTimestamp < $ahoraTimestamp) {
        $error = 'No se pueden reprogramar reservas para una fecha u hora anterior al momento actual.';
    }
    // 8. Validación de cantidad de personas (1 a 100)
    elseif ($cantidad_personas < 1 || $cantidad_personas > 100) {
        $error = 'La cantidad de personas debe ser un número entre 1 y 100.';
    }
    // 9. Validación de estado mediante whitelist
    elseif (!in_array($estado, $estadosValidos, true)) {
        $error = 'El estado de la reserva seleccionado no es válido.';
    } else {
        // 10. Validar existencia y estado activo del cliente seleccionado
        $targetCliente = null;
        foreach ($clientes as $c) {
            if ($c['id'] === $cliente_id) {
                $targetCliente = $c;
                break;
            }
        }
        if (!$targetCliente && $supabase->isConfigured()) {
            $cRes = $supabase->select('clientes', '*', ['id' => 'eq.' . $cliente_id], $authToken);
            if ($cRes['success'] && !empty($cRes['data'])) {
                $targetCliente = $cRes['data'][0];
            }
        }

        if (!$targetCliente) {
            $error = 'El cliente seleccionado no fue encontrada en el sistema.';
        } elseif (isset($targetCliente['estado']) && ($targetCliente['estado'] === false || $targetCliente['estado'] === 'false')) {
            $error = 'El cliente seleccionado se encuentra inactivo.';
        } else {
            // 11. Consultar capacidad real de la mesa seleccionada
            $targetMesa = null;
            foreach ($mesas as $m) {
                if ($m['id'] === $mesa_id) {
                    $targetMesa = $m;
                    break;
                }
            }

            if (!$targetMesa && $supabase->isConfigured()) {
                $mRes = $supabase->select('mesas', '*', ['id' => 'eq.' . $mesa_id], $authToken);
                if ($mRes['success'] && !empty($mRes['data'])) {
                    $targetMesa = $mRes['data'][0];
                }
            }

            if (!$targetMesa) {
                $error = 'La mesa seleccionada no fue encontrada en el sistema.';
            } elseif ($cantidad_personas > (int)$targetMesa['capacidad']) {
                $error = 'Capacidad excedida: La Mesa #' . $targetMesa['numero'] . ' (' . $targetMesa['ubicacion'] . ') tiene capacidad máxima para ' . $targetMesa['capacidad'] . ' personas, pero especificaste ' . $cantidad_personas . ' personas.';
            } else {
                // 12. Detección de conflicto con otras reservas activas (excluyendo la reserva actual)
                if (in_array($estado, ['Pendiente', 'Confirmada'], true)) {
                    $conflictRes = $supabase->select('reservas', '*', [
                        'mesa_id' => 'eq.' . $mesa_id,
                        'fecha' => 'eq.' . $fecha,
                        'hora' => 'eq.' . $hora,
                        'estado' => 'in.(Pendiente,Confirmada)',
                        'id' => 'neq.' . $id
                    ], $authToken);

                    if ($conflictRes['success'] && !empty($conflictRes['data'])) {
                        $error = 'Disponibilidad Denegada: La Mesa #' . $targetMesa['numero'] . ' ya cuenta con otra reserva activa registrada para la fecha ' . date('d/m/Y', strtotime($fecha)) . ' a las ' . $hora . '.';
                    }
                }

                if (!$error) {
                    // 13. Actualizar reserva con cliente_id y access_token
                    $updateRes = $supabase->update('reservas', [
                        'cliente_id' => $cliente_id,
                        'fecha' => $fecha,
                        'hora' => $hora,
                        'mesa_id' => $mesa_id,
                        'cantidad_personas' => $cantidad_personas,
                        'estado' => $estado,
                        'observaciones' => $observaciones
                    ], 'id=eq.' . $id, $authToken);

                    if ($updateRes['success']) {
                        set_flash_message('success', 'Reserva actualizada correctamente.');
                        redirect('/modules/reservas/');
                    } else {
                        $rawError = $updateRes['error'] ?? 'Error al actualizar la reserva en Supabase.';
                        if (stripos($rawError, 'duplicate key') !== false || stripos($rawError, 'idx_unique_reserva_activa') !== false) {
                            $error = 'Disponibilidad Denegada: La Mesa #' . $targetMesa['numero'] . ' ya tiene una reserva activa registrada para ese horario.';
                        } else {
                            $error = 'Error al actualizar la reserva: ' . $rawError;
                        }
                    }
                }
            }
        }
    }
}

// Normalizar la hora para el input time (HH:MM)
$horaNormalizada = !empty($reserva['hora']) ? date('H:i', strtotime($reserva['hora'])) : '13:00';
?>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <div class="mb-4">
            <a href="<?= BASE_URL ?>/modules/reservas/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Reservas
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Editar / Actualizar Reserva</h4>
            <p class="text-muted fs-6 mb-4">Modificar cliente titular, estado, horario, mesa o detalles de la reserva</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Cliente / Titular <span class="text-danger">*</span></label>
                        <select name="cliente_id" class="form-select" required>
                            <option value="">-- Seleccionar Cliente --</option>
                            <?php foreach ($clientes as $cli): ?>
                                <option value="<?= $cli['id'] ?>" <?= (($_POST['cliente_id'] ?? $reserva['cliente_id']) === $cli['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cli['nombre'] . ' ' . $cli['apellido']) ?> (Doc: <?= htmlspecialchars($cli['documento']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">¿El cliente no está registrado? <a href="<?= BASE_URL ?>/modules/clientes/create.php" target="_blank">Registrar nuevo cliente aquí</a></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Fecha de Reserva <span class="text-danger">*</span></label>
                        <input type="date" name="fecha" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['fecha'] ?? $reserva['fecha']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Hora de Reserva <span class="text-danger">*</span></label>
                        <input type="time" name="hora" class="form-control" required value="<?= htmlspecialchars($_POST['hora'] ?? $horaNormalizada) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Mesa Asignada <span class="text-danger">*</span></label>
                        <select name="mesa_id" class="form-select" required>
                            <?php foreach ($mesas as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= (($_POST['mesa_id'] ?? $reserva['mesa_id']) === $m['id']) ? 'selected' : '' ?>>
                                    Mesa #<?= $m['numero'] ?> — <?= htmlspecialchars($m['ubicacion']) ?> (Cap. <?= $m['capacidad'] ?> pers.)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Cantidad de Personas <span class="text-danger">*</span></label>
                        <input type="number" name="cantidad_personas" class="form-control" required min="1" max="100" value="<?= htmlspecialchars($_POST['cantidad_personas'] ?? $reserva['cantidad_personas']) ?>">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Estado de Reserva <span class="text-danger">*</span></label>
                        <select name="estado" class="form-select" required>
                            <?php foreach ($estadosValidos as $st): ?>
                                <option value="<?= $st ?>" <?= (($_POST['estado'] ?? $reserva['estado']) === $st) ? 'selected' : '' ?>><?= $st ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Observaciones</label>
                        <?php 
                        $obsClean = trim(preg_replace('/\[PREORDEN:(.*?)\]$/s', '', $reserva['observaciones'] ?? ''));
                        ?>
                        <textarea name="observaciones" class="form-control" rows="3" placeholder="Notas o solicitudes especiales del cliente..."><?= htmlspecialchars($_POST['observaciones'] ?? $obsClean) ?></textarea>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/reservas/" class="btn btn-light me-2">Cancelar</a>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="bi bi-save me-2"></i> Guardar Cambios
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>

