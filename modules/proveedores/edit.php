<?php
$pageTitle = 'Editar Proveedor';
$currentModule = 'proveedores';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$id = clean_input($_GET['id'] ?? '');

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'ID de proveedor no especificado o formato de identificador inválido.');
    redirect('/modules/proveedores/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;
$proveedor = null;

if ($supabase->isConfigured()) {
    $res = $supabase->select('proveedores', '*', ['id' => 'eq.' . $id], $authToken);
    if ($res['success'] && !empty($res['data'])) {
        $proveedor = $res['data'][0];
    }
}

if (!$proveedor) {
    set_flash_message('danger', 'El proveedor solicitado no fue encontrado en la base de datos.');
    redirect('/modules/proveedores/');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = clean_input($_POST['nombre'] ?? '');
    $contacto = clean_input($_POST['contacto'] ?? '');
    $telefono = clean_input($_POST['telefono'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $direccion = clean_input($_POST['direccion'] ?? '');

    // 2. Validación de campos obligatorios
    if (empty($nombre)) {
        $error = 'Por favor ingresa el nombre o razón social del proveedor.';
    }
    // 3. Validación de longitud de nombre
    elseif (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 150) {
        $error = 'El nombre del proveedor debe tener entre 2 y 150 caracteres.';
    }
    // 4. Validación de longitud de persona de contacto
    elseif (!empty($contacto) && mb_strlen($contacto) > 100) {
        $error = 'El nombre de la persona de contacto no puede exceder los 100 caracteres.';
    }
    // 5. Validación de formato de correo electrónico opcional
    elseif (!empty($email) && !is_valid_email($email)) {
        $error = 'El correo electrónico ingresado no tiene un formato válido.';
    }
    elseif (!empty($email) && mb_strlen($email) > 150) {
        $error = 'El correo electrónico no puede exceder los 150 caracteres.';
    }
    // 6. Validación de teléfono opcional
    elseif (!empty($telefono) && !is_valid_phone($telefono)) {
        $error = 'El número de teléfono debe contener únicamente dígitos numéricos (entre 7 y 12 dígitos).';
    } else {
        $updateData = [
            'nombre' => $nombre,
            'contacto' => !empty($contacto) ? $contacto : null,
            'telefono' => !empty($telefono) ? $telefono : null,
            'email' => !empty($email) ? $email : null,
            'direccion' => !empty($direccion) ? $direccion : null
        ];

        $updateRes = $supabase->update('proveedores', $updateData, 'id=eq.' . $id, $authToken);

        if ($updateRes['success']) {
            set_flash_message('success', 'Proveedor "' . htmlspecialchars($nombre) . '" actualizado correctamente.');
            redirect('/modules/proveedores/');
        } else {
            $rawError = $updateRes['error'] ?? 'Error desconocido al actualizar el proveedor.';
            $error = 'Error al actualizar el proveedor: ' . $rawError;
        }
    }
}
?>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <div class="mb-4">
            <a href="<?= BASE_URL ?>/modules/proveedores/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Proveedores
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="avatar-circle" style="width: 44px; height: 44px; background: linear-gradient(135deg, #2b9348, #55a630); font-size: 1.2rem;">
                        <i class="bi bi-truck text-white"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0" style="color: var(--secondary-color);">Editar Proveedor</h4>
                        <p class="text-muted small mb-0">Modificar datos comerciales del proveedor</p>
                    </div>
                </div>
                <div>
                    <span class="badge <?= ($proveedor['estado'] ?? true) ? 'badge-active' : 'badge-inactive' ?> fs-6">
                        <?= ($proveedor['estado'] ?? true) ? 'Activo' : 'Inactivo' ?>
                    </span>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST" autocomplete="off">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Nombre o Razón Social <span class="text-danger">*</span></label>
                        <input type="text" 
                               name="nombre" 
                               class="form-control" 
                               required 
                               maxlength="150"
                               value="<?= htmlspecialchars($_POST['nombre'] ?? ($proveedor['nombre'] ?? '')) ?>" 
                               autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Persona de Contacto</label>
                        <input type="text" 
                               name="contacto" 
                               class="form-control" 
                               maxlength="100"
                               value="<?= htmlspecialchars($_POST['contacto'] ?? ($proveedor['contacto'] ?? '')) ?>" 
                               autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Teléfono de Contacto</label>
                        <input type="tel" 
                               name="telefono" 
                               class="form-control" 
                               maxlength="30"
                               value="<?= htmlspecialchars($_POST['telefono'] ?? ($proveedor['telefono'] ?? '')) ?>" 
                               autocomplete="off">
                        <small class="text-muted">Solo números (7 a 12 dígitos).</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Correo Electrónico</label>
                        <input type="email" 
                               name="email" 
                               class="form-control" 
                               maxlength="150"
                               value="<?= htmlspecialchars($_POST['email'] ?? ($proveedor['email'] ?? '')) ?>" 
                               autocomplete="off">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Dirección / Ubicación</label>
                        <textarea name="direccion" 
                                  class="form-control" 
                                  rows="3" 
                                  autocomplete="off"><?= htmlspecialchars($_POST['direccion'] ?? ($proveedor['direccion'] ?? '')) ?></textarea>
                    </div>

                    <div class="col-12 mt-4 d-flex justify-content-end gap-2">
                        <a href="<?= BASE_URL ?>/modules/proveedores/" class="btn btn-outline-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="bi bi-save me-1"></i> Actualizar Proveedor
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
