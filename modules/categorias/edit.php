<?php
$pageTitle = 'Editar Categoría';
$currentModule = 'categorias';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$id = clean_input($_GET['id'] ?? '');

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'ID de categoría no especificado o formato de identificador inválido.');
    redirect('/modules/categorias/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;
$categoria = null;

if ($supabase->isConfigured()) {
    $res = $supabase->select('categorias', '*', ['id' => 'eq.' . $id], $authToken);
    if ($res['success'] && !empty($res['data'])) {
        $categoria = $res['data'][0];
    }
}

if (!$categoria) {
    set_flash_message('danger', 'Categoría no encontrada en la base de datos.');
    redirect('/modules/categorias/');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = clean_input($_POST['nombre'] ?? '');
    $descripcion = clean_input($_POST['descripcion'] ?? '');
    $estado = isset($_POST['estado']) ? (bool)$_POST['estado'] : true;

    // 2. Validación de nombre obligatorio
    if (empty($nombre)) {
        $error = 'Por favor ingresa el nombre de la categoría.';
    } 
    // 3. Validación de longitud
    elseif (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 50) {
        $error = 'El nombre de la categoría debe tener entre 3 y 50 caracteres.';
    } else {
        // 4. Detección de duplicados excluyendo la categoría actual
        if (mb_strtolower($nombre) !== mb_strtolower($categoria['nombre'] ?? '')) {
            $checkNombre = $supabase->select('categorias', 'id', [
                'nombre' => 'ilike.' . $nombre,
                'id' => 'neq.' . $id
            ], $authToken);

            if ($checkNombre['success'] && !empty($checkNombre['data'])) {
                $error = 'Ya existe otra categoría registrada con el nombre "' . htmlspecialchars($nombre) . '".';
            }
        }

        if (!$error) {
            // 5. Actualizar categoría
            $updateRes = $supabase->update('categorias', [
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'estado' => $estado
            ], 'id=eq.' . $id, $authToken);

            if ($updateRes['success']) {
                set_flash_message('success', 'Categoría "' . htmlspecialchars($nombre) . '" actualizada correctamente.');
                redirect('/modules/categorias/');
            } else {
                $rawError = $updateRes['error'] ?? 'Error al actualizar la categoría.';
                if (stripos($rawError, 'duplicate key') !== false || stripos($rawError, 'unique') !== false) {
                    $error = 'Ya existe una categoría registrada con ese nombre.';
                } else {
                    $error = 'Error al actualizar la categoría: ' . $rawError;
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
            <a href="<?= BASE_URL ?>/modules/categorias/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Categorías
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Editar Categoría</h4>
            <p class="text-muted fs-6 mb-4">Modificar nombre, descripción o estado de la categoría</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Nombre de la Categoría <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control" required minlength="3" maxlength="50" value="<?= htmlspecialchars($_POST['nombre'] ?? $categoria['nombre']) ?>" autocomplete="off">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Descripción</label>
                        <textarea name="descripcion" class="form-control" rows="3"><?= htmlspecialchars($_POST['descripcion'] ?? $categoria['descripcion']) ?></textarea>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Estado</label>
                        <select name="estado" class="form-select">
                            <?php $currEstado = isset($_POST['estado']) ? ($_POST['estado'] == '1') : ($categoria['estado'] ?? true); ?>
                            <option value="1" <?= $currEstado ? 'selected' : '' ?>>Activa (Visible en catálogo y menú)</option>
                            <option value="0" <?= !$currEstado ? 'selected' : '' ?>>Inactiva (Oculta)</option>
                        </select>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/categorias/" class="btn btn-light me-2">Cancelar</a>
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
