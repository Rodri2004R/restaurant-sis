<?php
$pageTitle = 'Editar Usuario';
$currentModule = 'usuarios';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$id = clean_input($_GET['id'] ?? '');

// 1. Validar estrictamente el UUID recibido por $_GET['id']
if (empty($id) || !is_valid_uuid($id)) {
    set_flash_message('danger', 'ID de usuario no especificado o formato de identificador inválido.');
    redirect('/modules/usuarios/');
}

$authToken = $_SESSION['user']['access_token'] ?? null;
$user = null;
$roles = [];

if ($supabase->isConfigured()) {
    $resUser = $supabase->select('perfiles', '*', ['id' => 'eq.' . $id], $authToken);
    if ($resUser['success'] && !empty($resUser['data'])) {
        $user = $resUser['data'][0];
    }
    
    $resRoles = $supabase->select('roles', '*', ['estado' => 'eq.true'], $authToken);
    if ($resRoles['success']) {
        $roles = $resRoles['data'];
    }
}

if (!$user) {
    set_flash_message('danger', 'Usuario no encontrado en la base de datos.');
    redirect('/modules/usuarios/');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = clean_input($_POST['nombre'] ?? '');
    $apellido = clean_input($_POST['apellido'] ?? '');
    $ci = clean_input($_POST['ci'] ?? '');
    $telefono = clean_input($_POST['telefono'] ?? '');
    $rol_id = (int)($_POST['rol_id'] ?? $user['rol_id']);

    // 2. Validación de campos obligatorios
    if (empty($nombre) || empty($apellido)) {
        $error = 'Por favor completa los campos obligatorios (Nombre y Apellido).';
    } 
    // 3. Validación de formato de documento (CI) si se proporcionó
    elseif (!empty($ci) && !is_valid_document($ci)) {
        $error = 'El documento de identidad (CI) no tiene un formato válido (5 a 30 caracteres alfanuméricos).';
    } 
    // 4. Validación de formato de teléfono si se proporcionó
    elseif (!empty($telefono) && !is_valid_phone($telefono)) {
        $error = 'El número de teléfono debe contener únicamente números (entre 7 y 12 dígitos).';
    } else {
        // 5. Validar que el rol_id exista en la lista de roles activos
        $validRole = false;
        foreach ($roles as $r) {
            if ((int)$r['id'] === $rol_id) {
                $validRole = true;
                break;
            }
        }

        if (!$validRole) {
            $error = 'El rol seleccionado no es válido o no se encuentra activo.';
        } else {
            // 6. Comprobar CI duplicado excluyendo al propio usuario actual
            if (!empty($ci) && $ci !== ($user['ci'] ?? '')) {
                $checkCi = $supabase->select('perfiles', 'id', ['ci' => 'eq.' . $ci, 'id' => 'neq.' . $id], $authToken);
                if ($checkCi['success'] && !empty($checkCi['data'])) {
                    $error = 'El documento de identidad (CI) ya pertenece a otro usuario registrado.';
                }
            }

            if (!$error) {
                // 7. Actualizar perfil enviando el access_token del administrador
                $updateRes = $supabase->update('perfiles', [
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'ci' => $ci,
                    'telefono' => $telefono,
                    'rol_id' => $rol_id
                ], 'id=eq.' . $id, $authToken);

                if ($updateRes['success']) {
                    set_flash_message('success', 'Usuario actualizado correctamente.');
                    redirect('/modules/usuarios/');
                } else {
                    $error = $updateRes['error'] ?? 'Error al actualizar el usuario.';
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
            <a href="<?= BASE_URL ?>/modules/usuarios/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Usuarios
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Editar Usuario</h4>
            <p class="text-muted fs-6 mb-4">Modificar información personal y rol asignado</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control" required value="<?= htmlspecialchars($_POST['nombre'] ?? $user['nombre']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Apellido <span class="text-danger">*</span></label>
                        <input type="text" name="apellido" class="form-control" required value="<?= htmlspecialchars($_POST['apellido'] ?? $user['apellido']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Correo Electrónico (Solo Lectura)</label>
                        <input type="email" class="form-control bg-light" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Documento (CI)</label>
                        <input type="text" name="ci" class="form-control" value="<?= htmlspecialchars($_POST['ci'] ?? $user['ci']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Teléfono</label>
                        <input type="text" name="telefono" class="form-control" value="<?= htmlspecialchars($_POST['telefono'] ?? $user['telefono']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Rol Asignado <span class="text-danger">*</span></label>
                        <select name="rol_id" class="form-select" required>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['id'] ?>" <?= (($user['rol_id'] == $r['id'])) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/usuarios/" class="btn btn-light me-2">Cancelar</a>
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
