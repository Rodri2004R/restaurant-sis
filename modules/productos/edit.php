<?php
$pageTitle = 'Editar Producto';
$currentModule = 'productos';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$id = clean_input($_GET['id'] ?? '');

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'ID de producto no especificado o formato de identificador inválido.');
    redirect('/modules/productos/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;
$producto = null;
$categorias = [];

if ($supabase->isConfigured()) {
    $res = $supabase->select('productos', '*', ['id' => 'eq.' . $id], $authToken);
    if ($res['success'] && !empty($res['data'])) {
        $producto = $res['data'][0];
    }

    $resCat = $supabase->select('categorias', '*', ['order' => 'nombre.asc'], $authToken);
    if ($resCat['success']) {
        $categorias = $resCat['data'];
    }
}

if (!$producto) {
    set_flash_message('danger', 'Producto no encontrado en la base de datos.');
    redirect('/modules/productos/');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoria_id = clean_input($_POST['categoria_id'] ?? '');
    $nombre = clean_input($_POST['nombre'] ?? '');
    $descripcion = clean_input($_POST['descripcion'] ?? '');
    $precioInput = clean_input($_POST['precio'] ?? '');
    $disponible = isset($_POST['disponible']) ? ($_POST['disponible'] === '1') : true;
    $estado = isset($_POST['estado']) ? ($_POST['estado'] === '1') : true;

    // 2. Validación de campos obligatorios
    if (empty($categoria_id) || empty($nombre) || $precioInput === '') {
        $error = 'Por favor completa todos los campos requeridos (Categoría, Nombre y Precio).';
    }
    // 3. Validación de UUID de categoría
    elseif (!is_valid_uuid($categoria_id)) {
        $error = 'La categoría seleccionada no es válida.';
    }
    // 4. Validación de longitud de nombre
    elseif (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
        $error = 'El nombre del producto debe tener entre 2 y 100 caracteres.';
    }
    // 5. Validación de precio numérico >= 0
    elseif (!is_numeric($precioInput) || (float)$precioInput < 0) {
        $error = 'El precio debe ser un valor numérico mayor o igual a 0.';
    } else {
        $precio = round((float)$precioInput, 2);

        // 6. Validar que la categoría exista
        $categoriaValida = false;
        foreach ($categorias as $cat) {
            if ($cat['id'] === $categoria_id) {
                $categoriaValida = true;
                break;
            }
        }

        if (!$categoriaValida) {
            $error = 'La categoría seleccionada no existe.';
        } else {
            // 7. Actualización del producto
            $updateRes = $supabase->update('productos', [
                'categoria_id' => $categoria_id,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'precio' => $precio,
                'disponible' => $disponible,
                'estado' => $estado
            ], 'id=eq.' . $id, $authToken);

            if ($updateRes['success']) {
                set_flash_message('success', 'Producto "' . htmlspecialchars($nombre) . '" actualizado correctamente.');
                redirect('/modules/productos/');
            } else {
                $rawError = $updateRes['error'] ?? 'Error al actualizar el producto en Supabase.';
                $error = 'Error al actualizar el producto: ' . $rawError;
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
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Editar Producto</h4>
            <p class="text-muted fs-6 mb-4">Modificar datos, precio, categoría y disponibilidad</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Categoría <span class="text-danger">*</span></label>
                        <select name="categoria_id" class="form-select" required>
                            <?php foreach ($categorias as $cat): ?>
                                <?php $selCat = ($_POST['categoria_id'] ?? $producto['categoria_id']) === $cat['id']; ?>
                                <option value="<?= $cat['id'] ?>" <?= $selCat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['nombre']) ?> <?= ($cat['estado'] ?? true) ? '' : ' (Inactiva)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Precio Oficial (Bs.) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">Bs.</span>
                            <input type="number" step="0.50" min="0" name="precio" class="form-control" required value="<?= htmlspecialchars($_POST['precio'] ?? $producto['precio']) ?>">
                        </div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Nombre del Platillo / Producto <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control" required minlength="2" maxlength="100" value="<?= htmlspecialchars($_POST['nombre'] ?? $producto['nombre']) ?>">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Descripción e Ingredientes</label>
                        <textarea name="descripcion" class="form-control" rows="3"><?= htmlspecialchars($_POST['descripcion'] ?? ($producto['descripcion'] ?? '')) ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Disponibilidad en Cocina / Barra</label>
                        <?php 
                        $currDisp = isset($_POST['disponible']) ? ($_POST['disponible'] === '1') : (bool)($producto['disponible'] ?? true);
                        ?>
                        <select name="disponible" class="form-select">
                            <option value="1" <?= $currDisp ? 'selected' : '' ?>>Disponible para Venta</option>
                            <option value="0" <?= !$currDisp ? 'selected' : '' ?>>Agotado Temporalmente</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Estado en Catálogo</label>
                        <?php 
                        $currEst = isset($_POST['estado']) ? ($_POST['estado'] === '1') : (bool)($producto['estado'] ?? true);
                        ?>
                        <select name="estado" class="form-select">
                            <option value="1" <?= $currEst ? 'selected' : '' ?>>Activo</option>
                            <option value="0" <?= !$currEst ? 'selected' : '' ?>>Inactivo</option>
                        </select>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/productos/" class="btn btn-light me-2">Cancelar</a>
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
