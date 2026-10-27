<?php
$pageTitle = 'Registrar Producto';
$currentModule = 'productos';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$categorias = [];

if ($supabase->isConfigured()) {
    $resCat = $supabase->select('categorias', '*', ['estado' => 'eq.true', 'order' => 'nombre.asc'], $authToken);
    if ($resCat['success']) {
        $categorias = $resCat['data'];
    }
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoria_id = clean_input($_POST['categoria_id'] ?? '');
    $nombre = clean_input($_POST['nombre'] ?? '');
    $descripcion = clean_input($_POST['descripcion'] ?? '');
    $precioInput = clean_input($_POST['precio'] ?? '');
    $disponible = isset($_POST['disponible']) ? ($_POST['disponible'] === '1') : true;
    $estado = isset($_POST['estado']) ? ($_POST['estado'] === '1') : true;

    // 1. Validación de campos obligatorios
    if (empty($categoria_id) || empty($nombre) || $precioInput === '') {
        $error = 'Por favor completa todos los campos requeridos (Categoría, Nombre y Precio).';
    }
    // 2. Validación de UUID de categoría
    elseif (!is_valid_uuid($categoria_id)) {
        $error = 'La categoría seleccionada no es válida.';
    }
    // 3. Validación de longitud de nombre
    elseif (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
        $error = 'El nombre del producto debe tener entre 2 y 100 caracteres.';
    }
    // 4. Validación de precio numérico >= 0
    elseif (!is_numeric($precioInput) || (float)$precioInput < 0) {
        $error = 'El precio debe ser un valor numérico mayor o igual a 0.';
    } else {
        $precio = round((float)$precioInput, 2);

        // 5. Validar que la categoría exista y esté activa
        $categoriaValida = false;
        foreach ($categorias as $cat) {
            if ($cat['id'] === $categoria_id) {
                $categoriaValida = true;
                break;
            }
        }

        if (!$categoriaValida) {
            $catCheck = $supabase->select('categorias', 'id', ['id' => 'eq.' . $categoria_id, 'estado' => 'eq.true'], $authToken);
            if ($catCheck['success'] && !empty($catCheck['data'])) {
                $categoriaValida = true;
            }
        }

        if (!$categoriaValida) {
            $error = 'La categoría seleccionada no existe o se encuentra inactiva.';
        } else {
            // 6. Inserción del producto
            $insertRes = $supabase->insert('productos', [
                'categoria_id' => $categoria_id,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'precio' => $precio,
                'disponible' => $disponible,
                'estado' => $estado
            ], $authToken);

            if ($insertRes['success']) {
                set_flash_message('success', 'Producto "' . htmlspecialchars($nombre) . '" registrado exitosamente a Bs. ' . number_format($precio, 2) . '.');
                redirect('/modules/productos/');
            } else {
                $rawError = $insertRes['error'] ?? 'Error al registrar el producto en Supabase.';
                $error = 'Error al guardar el producto: ' . $rawError;
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
            <a href="<?= BASE_URL ?>/modules/productos/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Productos
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Registrar Nuevo Producto</h4>
            <p class="text-muted fs-6 mb-4">Ingresa la información del platillo o bebida para el catálogo del restaurante</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($categorias)): ?>
                <div class="alert alert-warning mb-4">
                    <i class="bi bi-exclamation-circle-fill me-2"></i> No tienes categorías registradas aún. 
                    <a href="<?= BASE_URL ?>/modules/categorias/create.php" class="alert-link">Crear una categoría primero</a>.
                </div>
            <?php endif; ?>

            <form action="" method="POST" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Categoría <span class="text-danger">*</span></label>
                        <select name="categoria_id" class="form-select" required>
                            <option value="">-- Seleccionar Categoría --</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= (($_POST['categoria_id'] ?? '') === $cat['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Precio Oficial (Bs.) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">Bs.</span>
                            <input type="number" step="0.50" min="0" name="precio" class="form-control" placeholder="Ej: 35.00" required value="<?= htmlspecialchars($_POST['precio'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Nombre del Platillo / Producto <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Saice Tarijeño Especial, Costillitas BBQ, Limonada..." required minlength="2" maxlength="100" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Descripción e Ingredientes</label>
                        <textarea name="descripcion" class="form-control" rows="3" placeholder="Ej: Carne picada en salsa tradicional, acompañado de arroz, papa y sarsa..."><?= htmlspecialchars($_POST['descripcion'] ?? '') ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Disponibilidad en Cocina / Barra</label>
                        <select name="disponible" class="form-select">
                            <option value="1" <?= (($_POST['disponible'] ?? '1') === '1') ? 'selected' : '' ?>>Disponible para Venta</option>
                            <option value="0" <?= (($_POST['disponible'] ?? '') === '0') ? 'selected' : '' ?>>Agotado Temporalmente</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Estado en Catálogo</label>
                        <select name="estado" class="form-select">
                            <option value="1" <?= (($_POST['estado'] ?? '1') === '1') ? 'selected' : '' ?>>Activo</option>
                            <option value="0" <?= (($_POST['estado'] ?? '') === '0') ? 'selected' : '' ?>>Inactivo</option>
                        </select>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/productos/" class="btn btn-light me-2">Cancelar</a>
                        <button type="submit" class="btn btn-primary-custom" <?= empty($categorias) ? 'disabled' : '' ?>>
                            <i class="bi bi-save me-2"></i> Guardar Producto
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
