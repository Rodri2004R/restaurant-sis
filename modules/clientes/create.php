<?php
$pageTitle = 'Registrar Cliente';
$currentModule = 'clientes';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Recepcionista']);

require_once __DIR__ . '/../../config/supabase.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = clean_input($_POST['nombre'] ?? '');
    $apellido = clean_input($_POST['apellido'] ?? '');
    $documento = clean_input($_POST['documento'] ?? '');
    $telefono = clean_input($_POST['telefono'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $crearCuenta = !empty($_POST['crear_cuenta']);
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // 1. Validación de campos obligatorios base
    if (empty($nombre) || empty($apellido) || empty($documento)) {
        $error = 'Por favor completa todos los campos obligatorios (Nombre, Apellido y Documento de Identidad).';
    } 
    // 2. Validación de formato de documento (CI / NIT)
    elseif (!is_valid_document($documento)) {
        $error = 'El documento (CI / NIT) no tiene un formato válido (5 a 30 caracteres alfanuméricos y guiones).';
    } 
    // 3. Validación de teléfono si se proporcionó
    elseif (!empty($telefono) && !is_valid_phone($telefono)) {
        $error = 'El número de teléfono debe contener únicamente números (entre 7 y 12 dígitos).';
    } 
    // 4. Validaciones específicas cuando se solicita crear cuenta de acceso
    elseif ($crearCuenta) {
        if (empty($email)) {
            $error = 'Para crear una cuenta de acceso es obligatorio ingresar un correo electrónico.';
        } elseif (!is_valid_email($email)) {
            $error = 'El correo electrónico ingresado no tiene un formato válido.';
        } elseif (empty($password)) {
            $error = 'Por favor ingresa una contraseña para la cuenta de acceso.';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres por seguridad.';
        } elseif ($password !== $password_confirm) {
            $error = 'Las contraseñas no coinciden. Por favor verifícalas.';
        }
    } 
    // 5. Validación de correo opcional cuando NO se crea cuenta
    elseif (!empty($email) && !is_valid_email($email)) {
        $error = 'El correo electrónico ingresado no tiene un formato válido.';
    }

    if (!$error) {
        $supabase = new SupabaseClient();
        if ($supabase->isConfigured()) {
            $authToken = $_SESSION['user']['access_token'] ?? null;

            // 6. Detección preventiva de documento duplicado en public.clientes
            $checkDoc = $supabase->select('clientes', 'id', ['documento' => 'eq.' . $documento], $authToken);
            if ($checkDoc['success'] && !empty($checkDoc['data'])) {
                $error = 'Ya existe un cliente registrado con este documento de identidad (CI / NIT).';
            } 
            // 7. Si se crea cuenta, comprobar que el correo no esté duplicado en perfiles ni en clientes
            elseif ($crearCuenta) {
                $checkEmailPerfil = $supabase->select('perfiles', 'id', ['email' => 'eq.' . $email], $authToken);
                if ($checkEmailPerfil['success'] && !empty($checkEmailPerfil['data'])) {
                    $error = 'Ya existe una cuenta registrada con este correo electrónico en el sistema.';
                } else {
                    $checkEmailCli = $supabase->select('clientes', 'id', ['email' => 'eq.' . $email], $authToken);
                    if ($checkEmailCli['success'] && !empty($checkEmailCli['data'])) {
                        $error = 'Ya existe un cliente registrado con este correo electrónico.';
                    }
                }
            }

            if (!$error) {
                if ($crearCuenta) {
                    // 8. Crear usuario en Supabase Auth
                    $userMeta = [
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'ci' => $documento,
                        'telefono' => $telefono,
                        'rol_id' => 6 // Rol Cliente
                    ];

                    $signUpRes = $supabase->signUp($email, $password, $userMeta);

                    if (!$signUpRes['success']) {
                        $rawErr = $signUpRes['error'] ?? 'Error al registrar la cuenta de usuario en Supabase Auth.';
                        if (stripos($rawErr, 'already registered') !== false || stripos($rawErr, 'User already exists') !== false) {
                            $error = 'Ya existe una cuenta registrada con este correo electrónico en Supabase Auth.';
                        } elseif (stripos($rawErr, 'at least 6 characters') !== false) {
                            $error = 'La contraseña debe tener al menos 6 caracteres.';
                        } elseif (stripos($rawErr, 'rate limit') !== false || stripos($rawErr, 'Too many requests') !== false) {
                            $error = 'Demasiados intentos de registro. Por favor, intenta más tarde.';
                        } else {
                            $error = 'Error de autenticación: ' . $rawErr;
                        }
                    } else {
                        $authData = $signUpRes['data'] ?? [];
                        $userId = $authData['user']['id'] ?? ($authData['id'] ?? null);

                        // Sincronizar en public.perfiles con rol_id = 6 (Cliente)
                        if ($userId) {
                            $supabase->upsert('perfiles', [
                                'id' => $userId,
                                'rol_id' => 6,
                                'nombre' => $nombre,
                                'apellido' => $apellido,
                                'email' => $email,
                                'ci' => $documento,
                                'telefono' => $telefono,
                                'estado' => true
                            ], $authToken);
                        }

                        // Insertar en public.clientes
                        $insertRes = $supabase->insert('clientes', [
                            'nombre' => $nombre,
                            'apellido' => $apellido,
                            'documento' => $documento,
                            'telefono' => $telefono,
                            'email' => $email,
                            'estado' => true
                        ], $authToken);

                        if ($insertRes['success']) {
                            set_flash_message('success', 'Cliente "' . htmlspecialchars($nombre . ' ' . $apellido) . '" registrado exitosamente con cuenta de acceso.');
                            redirect('/modules/clientes/');
                        } else {
                            $rawError = $insertRes['error'] ?? 'Error al guardar el cliente en la base de datos.';
                            $error = 'Error al registrar el cliente: ' . $rawError;
                        }
                    }
                } else {
                    // 9. Registro estándar de cliente presencial (sin cuenta de Auth)
                    $insertRes = $supabase->insert('clientes', [
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'documento' => $documento,
                        'telefono' => $telefono,
                        'email' => $email,
                        'estado' => true
                    ], $authToken);

                    if ($insertRes['success']) {
                        set_flash_message('success', 'Cliente "' . htmlspecialchars($nombre . ' ' . $apellido) . '" registrado exitosamente.');
                        redirect('/modules/clientes/');
                    } else {
                        $rawError = $insertRes['error'] ?? 'Error al guardar el cliente en Supabase.';
                        if (stripos($rawError, 'duplicate key') !== false || stripos($rawError, 'unique constraint') !== false) {
                            $error = 'Ya existe un cliente con este documento en la base de datos.';
                        } else {
                            $error = 'Error al registrar el cliente: ' . $rawError;
                        }
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
            <a href="<?= BASE_URL ?>/modules/clientes/" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Volver a Lista de Clientes
            </a>
        </div>

        <div class="card-custom p-4 max-w-700 mx-auto">
            <h4 class="fw-bold mb-3" style="color: var(--secondary-color);">Registrar Nuevo Cliente</h4>
            <p class="text-muted fs-6 mb-4">Ingresa la información personal del cliente para historial, reservas y acceso</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST" autocomplete="off" id="clienteForm">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control" placeholder="Ej: María" required value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Apellido <span class="text-danger">*</span></label>
                        <input type="text" name="apellido" class="form-control" placeholder="Ej: Fernández" required value="<?= htmlspecialchars($_POST['apellido'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Documento / CI / NIT <span class="text-danger">*</span></label>
                        <input type="text" name="documento" class="form-control" placeholder="Ej: 87654321" required value="<?= htmlspecialchars($_POST['documento'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Teléfono / Celular</label>
                        <input type="text" name="telefono" class="form-control" placeholder="Ej: 72345678" value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>" autocomplete="off">
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold" id="email_label">
                            Correo Electrónico <?= !empty($_POST['crear_cuenta']) ? '<span class="text-danger">*</span>' : '' ?>
                        </label>
                        <input type="email" name="email" id="email_input" class="form-control" placeholder="maria.fernandez@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" <?= !empty($_POST['crear_cuenta']) ? 'required' : '' ?> autocomplete="off">
                    </div>

                    <div class="col-md-12">
                        <div class="form-check form-switch p-3 bg-light rounded border">
                            <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="crear_cuenta" name="crear_cuenta" value="1" <?= !empty($_POST['crear_cuenta']) ? 'checked' : '' ?> onchange="togglePasswordFields()">
                            <label class="form-check-label fw-semibold" for="crear_cuenta">
                                <i class="bi bi-person-badge me-1 text-primary"></i> Crear cuenta de acceso en línea para este cliente
                            </label>
                            <div class="form-text text-muted ms-4">
                                Permite que el cliente inicie sesión en el sistema para realizar pedidos y reservas online.
                            </div>
                        </div>
                    </div>

                    <div id="password_fields_container" class="col-12" style="display: <?= !empty($_POST['crear_cuenta']) ? 'block' : 'none' ?>;">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Contraseña <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="password" id="password_input" class="form-control" placeholder="Mínimo 6 caracteres" minlength="6" autocomplete="new-password" <?= !empty($_POST['crear_cuenta']) ? 'required' : '' ?>>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('password_input', 'eye_icon_1')">
                                        <i class="bi bi-eye" id="eye_icon_1"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Confirmar Contraseña <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="password_confirm" id="password_confirm_input" class="form-control" placeholder="Repite la contraseña" minlength="6" autocomplete="new-password" <?= !empty($_POST['crear_cuenta']) ? 'required' : '' ?>>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('password_confirm_input', 'eye_icon_2')">
                                        <i class="bi bi-eye" id="eye_icon_2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12 mt-4 text-end">
                        <a href="<?= BASE_URL ?>/modules/clientes/" class="btn btn-light me-2">Cancelar</a>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="bi bi-save me-2"></i> Registrar Cliente
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>

<script>
function togglePasswordFields() {
    const isChecked = document.getElementById('crear_cuenta').checked;
    const container = document.getElementById('password_fields_container');
    const pwdInput = document.getElementById('password_input');
    const pwdConfirmInput = document.getElementById('password_confirm_input');
    const emailInput = document.getElementById('email_input');
    const emailLabel = document.getElementById('email_label');

    if (isChecked) {
        container.style.display = 'block';
        pwdInput.setAttribute('required', 'required');
        pwdConfirmInput.setAttribute('required', 'required');
        emailInput.setAttribute('required', 'required');
        emailLabel.innerHTML = 'Correo Electrónico <span class="text-danger">*</span>';
    } else {
        container.style.display = 'none';
        pwdInput.removeAttribute('required');
        pwdConfirmInput.removeAttribute('required');
        emailInput.removeAttribute('required');
        emailLabel.innerHTML = 'Correo Electrónico';
    }
}

function togglePasswordVisibility(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}
</script>
