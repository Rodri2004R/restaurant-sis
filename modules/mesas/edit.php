<?php
$pageTitle = 'Editar Mesa';
$currentModule = 'mesas';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Recepcionista']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$id = clean_input($_GET['id'] ?? '');

$ubicacionesValidas = ['Salón Principal', 'Terraza', 'VIP', 'Bar / Barra'];
$estadosValidos = ['disponible', 'ocupada', 'reservada', 'mantenimiento'];

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'ID de mesa no especificado o formato de identificador inválido.');
    redirect('/modules/mesas/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;
$mesa = null;

if ($supabase->isConfigured()) {
    $res = $supabase->select('mesas', '*', ['id' => 'eq.' . $id], $authToken);
    if ($res['success'] && !empty($res['data'])) {
        $mesa = $res['data'][0];
    }
}

if (!$mesa) {
    set_flash_message('danger', 'Mesa no encontrada en la base de datos.');
    redirect('/modules/mesas/');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero = isset($_POST['numero']) && is_numeric($_POST['numero']) ? (int)$_POST['numero'] : 0;
    $capacidad = isset($_POST['capacidad']) && is_numeric($_POST['capacidad']) ? (int)$_POST['capacidad'] : 0;
    $ubicacion = clean_input($_POST['ubicacion'] ?? 'Salón Principal');
    $estado = clean_input($_POST['estado'] ?? 'disponible');

    // 2. Validación de número de mesa
    if ($numero <= 0 || $numero > 999) {
        $error = 'Por favor ingresa un número de mesa válido (entero entre 1 y 999).';
    } 
    // 3. Validación de capacidad de personas (1 a 100)
    elseif ($capacidad <= 0 || $capacidad > 100) {
        $error = 'La capacidad de personas debe ser un número entre 1 y 100.';
    } 
    // 4. Validación de ubicación mediante lista blanca
    elseif (!in_array($ubicacion, $ubicacionesValidas, true)) {
        $error = 'La ubicación seleccionada no es válida.';
    } 
    // 5. Validación de estado mediante lista blanca
    elseif (!in_array($estado, $estadosValidos, true)) {
        $error = 'El estado de la mesa seleccionado no es válido.';
    } else {
        // 6. Detección de número de mesa duplicado excluyendo a la mesa actual
        if ($numero !== (int)($mesa['numero'] ?? 0)) {
            $checkMesa = $supabase->select('mesas', 'id', ['numero' => 'eq.' . $numero, 'id' => 'neq.' . $id], $authToken);
            if ($checkMesa['success'] && !empty($checkMesa['data'])) {
                $error = 'El número de mesa #' . $numero . ' ya está asignado a otra mesa.';
            }
        }

        if (!$error) {
            // 7. Actualizar mesa enviando el access_token de la sesión
            $updateRes = $supabase->update('mesas', [
                'numero' => $numero,
                'capacidad' => $capacidad,
                'ubicacion' => $ubicacion,
                'estado' => $estado
            ], 'id=eq.' . $id, $authToken);

            if ($updateRes['success']) {
                set_flash_message('success', 'Mesa #' . $numero . ' actualizada correctamente.');
                redirect('/modules/mesas/');
            } else {
                $rawError = $updateRes['error'] ?? 'Error al actualizar la mesa.';
                if (stripos($rawError, 'duplicate key') !== false || stripos($rawError, 'unique constraint') !== false) {
                    $error = 'Ya existe una mesa registrada con el número #' . $numero . '.';
                } else {
                    $error = 'Error al actualizar la mesa: ' . $rawError;
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
            <a href="<?= BASE_URL ?>/modules/mesas/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Mesas
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Editar Mesa #<?= $mesa['numero'] ?></h4>
            <p class="text-muted fs-6 mb-4">Actualizar capacidad, ubicación y estado de la mesa</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Número de Mesa <span class="text-danger">*</span></label>
                        <input type="number" name="numero" class="form-control" required min="1" max="999" value="<?= htmlspecialchars($_POST['numero'] ?? $mesa['numero']) ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Capacidad de Personas <span class="text-danger">*</span></label>
                        <input type="number" name="capacidad" class="form-control" required min="1" max="100" value="<?= htmlspecialchars($_POST['capacidad'] ?? $mesa['capacidad']) ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Ubicación / Área</label>
                        <select name="ubicacion" class="form-select">
                            <?php foreach ($ubicacionesValidas as $ub): ?>
                                <option value="<?= $ub ?>" <?= (($mesa['ubicacion'] ?? '') === $ub) ? 'selected' : '' ?>><?= $ub ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Estado de la Mesa</label>
                        <select name="estado" class="form-select">
                            <?php foreach (['disponible' => 'Disponible', 'ocupada' => 'Ocupada', 'reservada' => 'Reservada', 'mantenimiento' => 'Mantenimiento'] as $k => $v): ?>
                                <option value="<?= $k ?>" <?= (($mesa['estado'] ?? '') === $k) ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/mesas/" class="btn btn-light me-2">Cancelar</a>
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
