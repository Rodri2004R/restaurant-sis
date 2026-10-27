<?php
$pageTitle = 'Registrar Usuario';
$currentModule = 'usuarios';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$roles = [];

if ($supabase->isConfigured()) {
    $res = $supabase->select('roles', '*', ['estado' => 'eq.true']);
    if ($res['success']) {
        $roles = $res['data'];
    }
}

if (empty($roles)) {
    $roles = [
        ['id' => 1, 'nombre' => 'Administrador'],
        ['id' => 2, 'nombre' => 'Recepcionista'],
        ['id' => 3, 'nombre' => 'Mesero'],
        ['id' => 4, 'nombre' => 'Cocinero'],
        ['id' => 5, 'nombre' => 'Cajero'],
    ];
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = clean_input($_POST['nombre'] ?? '');
    $apellido = clean_input($_POST['apellido'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ci = clean_input($_POST['ci'] ?? '');
    $telefono = clean_input($_POST['telefono'] ?? '');
    $rol_id = (int)($_POST['rol_id'] ?? 0);

    // 1. Campos obligatorios
    if (empty($nombre) || empty($apellido) || empty($email) || empty($password)) {
        $error = 'Por favor completa todos los campos obligatorios (Nombre, Apellido, Email y Contraseña).';
    } 
    // 2. Validación de formato de correo electrónico
    elseif (!is_valid_email($email)) {
        $error = 'El correo electrónico ingresado no tiene un formato válido.';
    } 
    // 3. Longitud mínima de contraseña
    elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } 
    // 4. Validación de documento de identidad (CI) si se proporcionó
    elseif (!empty($ci) && !is_valid_document($ci)) {
        $error = 'El documento de identidad (CI) no tiene un formato válido (5 a 30 caracteres alfanuméricos).';
    } 
    // 5. Validación de teléfono si se proporcionó
    elseif (!empty($telefono) && !is_valid_phone($telefono)) {
        $error = 'El número de teléfono debe contener únicamente números (entre 7 y 12 dígitos).';
    } else {
        // 6. Validar que el rol_id exista en los roles activos
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
            $authToken = $_SESSION['user']['access_token'] ?? null;

            // 7. Detectar email duplicado en public.perfiles antes de signUp
            $checkEmail = $supabase->select('perfiles', 'id', ['email' => 'eq.' . $email], $authToken);
            if ($checkEmail['success'] && !empty($checkEmail['data'])) {
                $error = 'El correo electrónico ya se encuentra registrado en el sistema.';
            } 
            // 8. Detectar CI duplicado en public.perfiles si fue ingresado
            elseif (!empty($ci)) {
                $checkCi = $supabase->select('perfiles', 'id', ['ci' => 'eq.' . $ci], $authToken);
                if ($checkCi['success'] && !empty($checkCi['data'])) {
                    $error = 'El documento de identidad (CI) ya está registrado en otro usuario.';
                }
            }

            if (!$error) {
                // 9. Registrar usuario en Supabase Auth
                $signUpRes = $supabase->signUp($email, $password, [
                    'nombre' => $nombre,
                    'apellido' => $apellido,
                    'ci' => $ci,
                    'telefono' => $telefono,
                    'rol_id' => $rol_id
                ]);

                if ($signUpRes['success']) {
                    $userId = $signUpRes['data']['user']['id'] ?? ($signUpRes['data']['id'] ?? null);
                    if ($userId) {
                        // Asegurar sincronización en tabla perfiles mediante upsert con access_token
                        $supabase->upsert('perfiles', [
                            'id' => $userId,
                            'rol_id' => $rol_id,
                            'nombre' => $nombre,
                            'apellido' => $apellido,
                            'ci' => $ci,
                            'telefono' => $telefono,
                            'email' => $email,
                            'estado' => true
                        ], $authToken);
                    }

                    set_flash_message('success', 'Usuario registrado exitosamente.');
                    redirect('/modules/usuarios/');
                } else {
                    $rawError = $signUpRes['error'] ?? 'Error al registrar el usuario en Supabase.';
                    // Traducción amigable de errores comunes de Supabase Auth
                    if (stripos($rawError, 'already registered') !== false || stripos($rawError, 'User already exists') !== false) {
                        $error = 'El correo electrónico ya se encuentra registrado en Supabase Auth.';
                    } elseif (stripos($rawError, 'at least 6 characters') !== false) {
                        $error = 'La contraseña debe tener al menos 6 caracteres.';
                    } elseif (stripos($rawError, 'rate limit') !== false || stripos($rawError, 'Too many requests') !== false) {
                        $error = 'Demasiados intentos. Por favor, intenta de nuevo más tarde.';
                    } else {
                        $error = 'Error de registro: ' . $rawError;
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
            <a href="<?= BASE_URL ?>/modules/usuarios/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Usuarios
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Registrar Nuevo Usuario</h4>
            <p class="text-muted fs-6 mb-4">Crea una cuenta en Supabase Auth y asigna su rol en el sistema</p>

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
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Juan" required value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Apellido <span class="text-danger">*</span></label>
                        <input type="text" name="apellido" class="form-control" placeholder="Ej: Pérez" required value="<?= htmlspecialchars($_POST['apellido'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Correo Electrónico <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" placeholder="usuario@restaurante.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Contraseña <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="Mínimo 6 caracteres" required minlength="6" autocomplete="new-password">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Documento de Identidad (CI)</label>
                        <input type="text" name="ci" class="form-control" placeholder="Ej: 12345678" value="<?= htmlspecialchars($_POST['ci'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Teléfono</label>
                        <input type="text" name="telefono" class="form-control" placeholder="Ej: 71234567" value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Rol de Usuario <span class="text-danger">*</span></label>
                        <select name="rol_id" class="form-select" required>
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['id'] ?>" <?= (($_POST['rol_id'] ?? '') == $r['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/usuarios/" class="btn btn-light me-2">Cancelar</a>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="bi bi-save me-2"></i> Guardar Usuario
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
