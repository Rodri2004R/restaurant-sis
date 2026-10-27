<?php
$pageTitle = 'Editar Cliente';
$currentModule = 'clientes';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Recepcionista']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$id = clean_input($_GET['id'] ?? '');

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'ID de cliente no especificado o formato de identificador inválido.');
    redirect('/modules/clientes/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;
$cliente = null;

if ($supabase->isConfigured()) {
    $res = $supabase->select('clientes', '*', ['id' => 'eq.' . $id], $authToken);
    if ($res['success'] && !empty($res['data'])) {
        $cliente = $res['data'][0];
    }
}

if (!$cliente) {
    set_flash_message('danger', 'Cliente no encontrado en la base de datos.');
    redirect('/modules/clientes/');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = clean_input($_POST['nombre'] ?? '');
    $apellido = clean_input($_POST['apellido'] ?? '');
    $documento = clean_input($_POST['documento'] ?? '');
    $telefono = clean_input($_POST['telefono'] ?? '');
    $email = clean_input($_POST['email'] ?? '');

    // 2. Validación de campos obligatorios
    if (empty($nombre) || empty($apellido) || empty($documento)) {
        $error = 'Por favor completa todos los campos obligatorios (Nombre, Apellido y Documento).';
    } 
    // 3. Validación de formato de documento (CI / NIT)
    elseif (!is_valid_document($documento)) {
        $error = 'El documento (CI / NIT) no tiene un formato válido (5 a 30 caracteres alfanuméricos y guiones).';
    } 
    // 4. Validación de correo electrónico opcional
    elseif (!empty($email) && !is_valid_email($email)) {
        $error = 'El correo electrónico ingresado no tiene un formato válido.';
    } 
    // 5. Validación de teléfono opcional
    elseif (!empty($telefono) && !is_valid_phone($telefono)) {
        $error = 'El número de teléfono debe contener únicamente números (entre 7 y 12 dígitos).';
    } else {
        // 6. Comprobar documento duplicado excluyendo al propio cliente actual
        if ($documento !== ($cliente['documento'] ?? '')) {
            $checkDoc = $supabase->select('clientes', 'id', ['documento' => 'eq.' . $documento, 'id' => 'neq.' . $id], $authToken);
            if ($checkDoc['success'] && !empty($checkDoc['data'])) {
                $error = 'El documento (CI / NIT) ya pertenece a otro cliente registrado.';
            }
        }

        if (!$error) {
            // 7. Actualizar cliente enviando el access_token de la sesión
            $updateRes = $supabase->update('clientes', [
                'nombre' => $nombre,
                'apellido' => $apellido,
                'documento' => $documento,
                'telefono' => $telefono,
                'email' => $email
            ], 'id=eq.' . $id, $authToken);

            if ($updateRes['success']) {
                set_flash_message('success', 'Información del cliente actualizada correctamente.');
                redirect('/modules/clientes/');
            } else {
                $rawError = $updateRes['error'] ?? 'Error al actualizar el cliente.';
                if (stripos($rawError, 'duplicate key') !== false || stripos($rawError, 'unique constraint') !== false) {
                    $error = 'Ya existe un cliente registrado con este documento en el sistema.';
                } else {
                    $error = 'Error al actualizar el cliente: ' . $rawError;
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
            <a href="<?= BASE_URL ?>/modules/clientes/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Clientes
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Editar Cliente</h4>
            <p class="text-muted fs-6 mb-4">Actualizar datos de contacto e identificación</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control" required value="<?= htmlspecialchars($_POST['nombre'] ?? $cliente['nombre']) ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Apellido <span class="text-danger">*</span></label>
                        <input type="text" name="apellido" class="form-control" required value="<?= htmlspecialchars($_POST['apellido'] ?? $cliente['apellido']) ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Documento / CI / NIT <span class="text-danger">*</span></label>
                        <input type="text" name="documento" class="form-control" required value="<?= htmlspecialchars($_POST['documento'] ?? $cliente['documento']) ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Teléfono</label>
                        <input type="text" name="telefono" class="form-control" value="<?= htmlspecialchars($_POST['telefono'] ?? $cliente['telefono']) ?>" autocomplete="off">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Correo Electrónico</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? $cliente['email']) ?>" autocomplete="off">
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/clientes/" class="btn btn-light me-2">Cancelar</a>
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
