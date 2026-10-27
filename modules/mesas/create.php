<?php
$pageTitle = 'Registrar Mesa';
$currentModule = 'mesas';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Recepcionista']);

require_once __DIR__ . '/../../config/supabase.php';

$ubicacionesValidas = ['Salón Principal', 'Terraza', 'VIP', 'Bar / Barra'];
$estadosValidos = ['disponible', 'ocupada', 'reservada', 'mantenimiento'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero = isset($_POST['numero']) && is_numeric($_POST['numero']) ? (int)$_POST['numero'] : 0;
    $capacidad = isset($_POST['capacidad']) && is_numeric($_POST['capacidad']) ? (int)$_POST['capacidad'] : 0;
    $ubicacion = clean_input($_POST['ubicacion'] ?? 'Salón Principal');
    $estado = clean_input($_POST['estado'] ?? 'disponible');

    // 1. Validación de número de mesa positivo y razonable
    if ($numero <= 0 || $numero > 999) {
        $error = 'Por favor ingresa un número de mesa válido (entero entre 1 y 999).';
    } 
    // 2. Validación de capacidad de personas (1 a 100)
    elseif ($capacidad <= 0 || $capacidad > 100) {
        $error = 'La capacidad de personas debe ser un número entre 1 y 100.';
    } 
    // 3. Validación de ubicación mediante lista blanca
    elseif (!in_array($ubicacion, $ubicacionesValidas, true)) {
        $error = 'La ubicación seleccionada no es válida.';
    } 
    // 4. Validación de estado mediante lista blanca
    elseif (!in_array($estado, $estadosValidos, true)) {
        $error = 'El estado inicial seleccionado no es válido.';
    } else {
        $supabase = new SupabaseClient();
        if ($supabase->isConfigured()) {
            $authToken = $_SESSION['user']['access_token'] ?? null;

            // 5. Detección preventiva de número de mesa duplicado
            $checkMesa = $supabase->select('mesas', 'id', ['numero' => 'eq.' . $numero], $authToken);
            if ($checkMesa['success'] && !empty($checkMesa['data'])) {
                $error = 'Ya existe una mesa con el número #' . $numero . '. Por favor elige otro número.';
            } else {
                // 6. Inserción en Supabase enviando el access_token de la sesión
                $insertRes = $supabase->insert('mesas', [
                    'numero' => $numero,
                    'capacidad' => $capacidad,
                    'ubicacion' => $ubicacion,
                    'estado' => $estado
                ], $authToken);

                if ($insertRes['success']) {
                    set_flash_message('success', 'Mesa #' . $numero . ' registrada exitosamente.');
                    redirect('/modules/mesas/');
                } else {
                    $rawError = $insertRes['error'] ?? 'Error al guardar la mesa en Supabase.';
                    if (stripos($rawError, 'duplicate key') !== false || stripos($rawError, 'unique constraint') !== false) {
                        $error = 'Ya existe una mesa registrada con el número #' . $numero . '.';
                    } else {
                        $error = 'Error al registrar la mesa: ' . $rawError;
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
            <a href="<?= BASE_URL ?>/modules/mesas/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Mesas
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Registrar Nueva Mesa</h4>
            <p class="text-muted fs-6 mb-4">Define el número, capacidad máxima y ubicación física en el restaurante</p>

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
                        <input type="number" name="numero" class="form-control" placeholder="Ej: 11" required min="1" max="999" value="<?= htmlspecialchars($_POST['numero'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Capacidad de Personas <span class="text-danger">*</span></label>
                        <input type="number" name="capacidad" class="form-control" placeholder="Ej: 4" required min="1" max="100" value="<?= htmlspecialchars($_POST['capacidad'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Ubicación / Área</label>
                        <select name="ubicacion" class="form-select">
                            <?php foreach ($ubicacionesValidas as $ub): ?>
                                <option value="<?= $ub ?>" <?= (($_POST['ubicacion'] ?? '') === $ub) ? 'selected' : '' ?>><?= $ub ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Estado Inicial</label>
                        <select name="estado" class="form-select">
                            <?php foreach (['disponible' => 'Disponible', 'ocupada' => 'Ocupada', 'reservada' => 'Reservada', 'mantenimiento' => 'Mantenimiento'] as $k => $v): ?>
                                <option value="<?= $k ?>" <?= (($_POST['estado'] ?? 'disponible') === $k) ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/mesas/" class="btn btn-light me-2">Cancelar</a>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="bi bi-save me-2"></i> Guardar Mesa
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
