<?php
$pageTitle = 'Registrar Proveedor';
$currentModule = 'proveedores';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = clean_input($_POST['nombre'] ?? '');
    $contacto = clean_input($_POST['contacto'] ?? '');
    $telefono = clean_input($_POST['telefono'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $direccion = clean_input($_POST['direccion'] ?? '');

    // 1. Validación de campos obligatorios
    if (empty($nombre)) {
        $error = 'Por favor ingresa el nombre o razón social del proveedor.';
    }
    // 2. Validación de longitud de nombre
    elseif (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 150) {
        $error = 'El nombre del proveedor debe tener entre 2 y 150 caracteres.';
    }
    // 3. Validación de longitud de persona de contacto
    elseif (!empty($contacto) && mb_strlen($contacto) > 100) {
        $error = 'El nombre de la persona de contacto no puede exceder los 100 caracteres.';
    }
    // 4. Validación de formato de correo electrónico opcional
    elseif (!empty($email) && !is_valid_email($email)) {
        $error = 'El correo electrónico ingresado no tiene un formato válido.';
    }
    elseif (!empty($email) && mb_strlen($email) > 150) {
        $error = 'El correo electrónico no puede exceder los 150 caracteres.';
    }
    // 5. Validación de teléfono opcional
    elseif (!empty($telefono) && !is_valid_phone($telefono)) {
        $error = 'El número de teléfono debe contener únicamente dígitos numéricos (entre 7 y 12 dígitos).';
    } else {
        $supabase = new SupabaseClient();
        if ($supabase->isConfigured()) {
            $authToken = $_SESSION['user']['access_token'] ?? null;

            $insertData = [
                'nombre' => $nombre,
                'contacto' => !empty($contacto) ? $contacto : null,
                'telefono' => !empty($telefono) ? $telefono : null,
                'email' => !empty($email) ? $email : null,
                'direccion' => !empty($direccion) ? $direccion : null,
                'estado' => true
            ];

            $insertRes = $supabase->insert('proveedores', $insertData, $authToken);

            if ($insertRes['success']) {
                set_flash_message('success', 'Proveedor "' . htmlspecialchars($nombre) . '" registrado exitosamente.');
                redirect('/modules/proveedores/');
            } else {
                $rawError = $insertRes['error'] ?? 'Error desconocido al registrar el proveedor.';
                $error = 'Error al registrar el proveedor en Supabase: ' . $rawError;
            }
        } else {
            $error = 'Error de conexión: Supabase no está configurado.';
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
            <div class="d-flex align-items-center gap-3 mb-3 border-bottom pb-3">
                <div class="avatar-circle" style="width: 44px; height: 44px; background: linear-gradient(135deg, #2b9348, #55a630); font-size: 1.2rem;">
                    <i class="bi bi-truck text-white"></i>
                </div>
                <div>
                    <h4 class="fw-bold mb-0" style="color: var(--secondary-color);">Registrar Nuevo Proveedor</h4>
                    <p class="text-muted small mb-0">Ingresa la información comercial y de contacto del proveedor</p>
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
                               placeholder="Ej: Distribuidora Avícola San Juan S.R.L." 
                               required 
                               maxlength="150"
                               value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" 
                               autocomplete="off">
                        <small class="text-muted">Nombre legal o comercial de la empresa proveedora.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Persona de Contacto</label>
                        <input type="text" 
                               name="contacto" 
                               class="form-control" 
                               placeholder="Ej: Juan Pérez" 
                               maxlength="100"
                               value="<?= htmlspecialchars($_POST['contacto'] ?? '') ?>" 
                               autocomplete="off">
                        <small class="text-muted">Ejecutivo de ventas o representante.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Teléfono de Contacto</label>
                        <input type="tel" 
                               name="telefono" 
                               class="form-control" 
                               placeholder="Ej: 71234567" 
                               maxlength="30"
                               value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>" 
                               autocomplete="off">
                        <small class="text-muted">Solo números (7 a 12 dígitos).</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Correo Electrónico</label>
                        <input type="email" 
                               name="email" 
                               class="form-control" 
                               placeholder="Ej: ventas@proveedora.com" 
                               maxlength="150"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                               autocomplete="off">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Dirección / Ubicación</label>
                        <textarea name="direccion" 
                                  class="form-control" 
                                  rows="3" 
                                  placeholder="Ej: Av. Las Américas #450, Zona Industrial" 
                                  autocomplete="off"><?= htmlspecialchars($_POST['direccion'] ?? '') ?></textarea>
                    </div>

                    <div class="col-12 mt-4 d-flex justify-content-end gap-2">
                        <a href="<?= BASE_URL ?>/modules/proveedores/" class="btn btn-outline-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="bi bi-check-circle me-1"></i> Guardar Proveedor
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
