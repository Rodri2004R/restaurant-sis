<?php
$pageTitle = 'Registrar Categoría';
$currentModule = 'categorias';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = clean_input($_POST['nombre'] ?? '');
    $descripcion = clean_input($_POST['descripcion'] ?? '');

    // 1. Validación de nombre obligatorio
    if (empty($nombre)) {
        $error = 'Por favor ingresa el nombre de la categoría.';
    } 
    // 2. Validación de longitud
    elseif (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 50) {
        $error = 'El nombre de la categoría debe tener entre 3 y 50 caracteres.';
    } else {
        $supabase = new SupabaseClient();
        if ($supabase->isConfigured()) {
            $authToken = $_SESSION['user']['access_token'] ?? null;

            // 3. Detección de duplicados en nombre
            $checkNombre = $supabase->select('categorias', 'id', ['nombre' => 'ilike.' . $nombre], $authToken);
            if ($checkNombre['success'] && !empty($checkNombre['data'])) {
                $error = 'Ya existe una categoría registrada con el nombre "' . htmlspecialchars($nombre) . '".';
            } else {
                // 4. Inserción de la categoría
                $insertRes = $supabase->insert('categorias', [
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'estado' => true
                ], $authToken);

                if ($insertRes['success']) {
                    set_flash_message('success', 'Categoría "' . htmlspecialchars($nombre) . '" registrada exitosamente.');
                    redirect('/modules/categorias/');
                } else {
                    $rawError = $insertRes['error'] ?? 'Error al guardar la categoría.';
                    if (stripos($rawError, 'duplicate key') !== false || stripos($rawError, 'unique') !== false) {
                        $error = 'Ya existe una categoría registrada con ese nombre.';
                    } else {
                        $error = 'Error al registrar la categoría: ' . $rawError;
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
            <a href="<?= BASE_URL ?>/modules/categorias/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Categorías
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Registrar Nueva Categoría</h4>
            <p class="text-muted fs-6 mb-4">Define una categoría para clasificar los platos y bebidas del menú</p>

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
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Entradas, Platos Fuertes, Bebidas, Postres..." required minlength="3" maxlength="50" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" autocomplete="off" autofocus>
                        <div class="form-text">Debe tener entre 3 y 50 caracteres.</div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Descripción (Opcional)</label>
                        <textarea name="descripcion" class="form-control" rows="3" placeholder="Ej: Variedad de platillos principales elaborados en cocina caliente..."><?= htmlspecialchars($_POST['descripcion'] ?? '') ?></textarea>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/categorias/" class="btn btn-light me-2">Cancelar</a>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="bi bi-save me-2"></i> Guardar Categoría
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
