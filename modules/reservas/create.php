<?php
$pageTitle = 'Registrar Reserva';
$currentModule = 'reservas';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Recepcionista', 'Mesero']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$clientes = [];
$mesas = [];

if ($supabase->isConfigured()) {
    // Obtener clientes activos
    $resCli = $supabase->select('clientes', '*', ['estado' => 'eq.true', 'order' => 'nombre.asc'], $authToken);
    if ($resCli['success']) {
        $clientes = $resCli['data'];
    }

    // Obtener mesas
    $resMesas = $supabase->select('mesas', '*', ['order' => 'numero.asc'], $authToken);
    if ($resMesas['success']) {
        $mesas = $resMesas['data'];
    }
}

$estadosValidos = ['Pendiente', 'Confirmada'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cliente_id = clean_input($_POST['cliente_id'] ?? '');
    $mesa_id = clean_input($_POST['mesa_id'] ?? '');
    $fecha = clean_input($_POST['fecha'] ?? '');
    $hora = clean_input($_POST['hora'] ?? '');
    $cantidad_personas = isset($_POST['cantidad_personas']) && is_numeric($_POST['cantidad_personas']) ? (int)$_POST['cantidad_personas'] : 0;
    $estado = clean_input($_POST['estado'] ?? 'Pendiente');
    $observaciones = clean_input($_POST['observaciones'] ?? '');

    $fechaActual = date('Y-m-d');

    // 1. Validación de campos requeridos
    if (empty($cliente_id) || empty($mesa_id) || empty($fecha) || empty($hora) || $cantidad_personas <= 0) {
        $error = 'Por favor completa todos los campos requeridos (Cliente, Mesa, Fecha, Hora y Cantidad de personas).';
    }
    // 2. Validación de UUID para cliente_id
    elseif (!is_valid_uuid($cliente_id)) {
        $error = 'El cliente seleccionado no tiene un identificador válido.';
    }
    // 3. Validación de UUID para mesa_id
    elseif (!is_valid_uuid($mesa_id)) {
        $error = 'La mesa seleccionada no tiene un identificador válido.';
    }
    // 4. Validación de formato de fecha YYYY-MM-DD
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $error = 'El formato de fecha no es válido (debe ser AAAA-MM-DD).';
    }
    // 5. Rechazar fechas anteriores a la fecha actual
    elseif ($fecha < $fechaActual) {
        $error = 'No se permiten reservas con fecha anterior a la fecha actual (' . date('d/m/Y', strtotime($fechaActual)) . ').';
    }
    // 6. Validación de formato de hora HH:MM (00:00 a 23:59)
    elseif (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora)) {
        $error = 'El formato de hora debe ser HH:MM (entre 00:00 y 23:59).';
    }
    // 7. Validación de cantidad de personas (1 a 100)
    elseif ($cantidad_personas < 1 || $cantidad_personas > 100) {
        $error = 'La cantidad de personas debe ser un número entre 1 y 100.';
    }
    // 8. Validación de estado mediante whitelist
    elseif (!in_array($estado, $estadosValidos, true)) {
        $error = 'El estado de la reserva seleccionado no es válido.';
    } else {
        // 9. Consultar capacidad real de la mesa seleccionada
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
            // 10. Detección de conflicto: reserva activa existente para misma mesa, misma fecha, misma hora y estado Pendiente/Confirmada
            $conflictRes = $supabase->select('reservas', '*', [
                'mesa_id' => 'eq.' . $mesa_id,
                'fecha' => 'eq.' . $fecha,
                'hora' => 'eq.' . $hora,
                'estado' => 'in.(Pendiente,Confirmada)'
            ], $authToken);

            if ($conflictRes['success'] && !empty($conflictRes['data'])) {
                $error = 'Disponibilidad Denegada: La Mesa #' . $targetMesa['numero'] . ' ya cuenta con una reserva activa registrada para la fecha ' . date('d/m/Y', strtotime($fecha)) . ' a las ' . $hora . '.';
            } else {
                // 11. Inserción de la reserva con access_token
                $insertRes = $supabase->insert('reservas', [
                    'cliente_id' => $cliente_id,
                    'mesa_id' => $mesa_id,
                    'fecha' => $fecha,
                    'hora' => $hora,
                    'cantidad_personas' => $cantidad_personas,
                    'estado' => $estado,
                    'observaciones' => $observaciones
                ], $authToken);

                if ($insertRes['success']) {
                    set_flash_message('success', 'Reserva registrada exitosamente para la fecha ' . date('d/m/Y', strtotime($fecha)) . ' a las ' . $hora . '.');
                    redirect('/modules/reservas/');
                } else {
                    $rawError = $insertRes['error'] ?? 'Error al registrar la reserva en Supabase.';
                    if (stripos($rawError, 'duplicate key') !== false || stripos($rawError, 'idx_unique_reserva_activa') !== false) {
                        $error = 'Disponibilidad Denegada: La Mesa #' . $targetMesa['numero'] . ' ya tiene una reserva activa registrada para ese horario.';
                    } else {
                        $error = 'Error al registrar la reserva: ' . $rawError;
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
        <div class="mb-4">
            <a href="<?= BASE_URL ?>/modules/reservas/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Reservas
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Registrar Nueva Reserva</h4>
            <p class="text-muted fs-6 mb-4">Selecciona el cliente, mesa, fecha y horario de reserva</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Cliente <span class="text-danger">*</span></label>
                        <select name="cliente_id" class="form-select" required>
                            <option value="">-- Seleccionar Cliente --</option>
                            <?php foreach ($clientes as $cli): ?>
                                <option value="<?= $cli['id'] ?>" <?= (($_POST['cliente_id'] ?? '') === $cli['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cli['nombre'] . ' ' . $cli['apellido']) ?> (Doc: <?= htmlspecialchars($cli['documento']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">¿El cliente no está registrado? <a href="<?= BASE_URL ?>/modules/clientes/create.php" target="_blank">Registrar nuevo cliente aquí</a></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Fecha de Reserva <span class="text-danger">*</span></label>
                        <input type="date" name="fecha" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['fecha'] ?? date('Y-m-d')) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Hora de Reserva <span class="text-danger">*</span></label>
                        <input type="time" name="hora" class="form-control" required value="<?= htmlspecialchars($_POST['hora'] ?? '13:00') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Mesa Asignada <span class="text-danger">*</span></label>
                        <select name="mesa_id" class="form-select" required>
                            <option value="">-- Seleccionar Mesa --</option>
                            <?php foreach ($mesas as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= (($_POST['mesa_id'] ?? '') === $m['id']) ? 'selected' : '' ?>>
                                    Mesa #<?= $m['numero'] ?> — <?= htmlspecialchars($m['ubicacion']) ?> (Capacidad: <?= $m['capacidad'] ?> pers.)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Cantidad de Personas <span class="text-danger">*</span></label>
                        <input type="number" name="cantidad_personas" class="form-control" placeholder="Ej: 4" required min="1" max="100" value="<?= htmlspecialchars($_POST['cantidad_personas'] ?? '2') ?>">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Estado de la Reserva</label>
                        <select name="estado" class="form-select">
                            <option value="Pendiente" <?= (($_POST['estado'] ?? 'Pendiente') === 'Pendiente') ? 'selected' : '' ?>>Pendiente</option>
                            <option value="Confirmada" <?= (($_POST['estado'] ?? '') === 'Confirmada') ? 'selected' : '' ?>>Confirmada</option>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Observaciones / Notas Adicionales</label>
                        <textarea name="observaciones" class="form-control" rows="3" placeholder="Ej: Cumpleaños, requiere silla alta para bebé, mesa cerca a ventana..."><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/reservas/" class="btn btn-light me-2">Cancelar</a>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="bi bi-calendar-check me-2"></i> Confirmar y Guardar Reserva
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
