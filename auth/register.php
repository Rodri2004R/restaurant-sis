<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../config/supabase.php';

// Si ya está autenticado, redirigir
if (is_authenticated()) {
    $user = get_authenticated_user();
    if (($user['rol_nombre'] ?? '') === 'Cliente') {
        redirect('/modules/reservas/online.php');
    } else {
        redirect('/modules/dashboard/');
    }
}

$error = null;
$nombre = '';
$apellido = '';
$documento = '';
$telefono = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = clean_input($_POST['nombre'] ?? '');
    $apellido = clean_input($_POST['apellido'] ?? '');
    $documento = clean_input($_POST['documento'] ?? '');
    $telefono = clean_input($_POST['telefono'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // 1. Validaciones básicas
    if (empty($nombre) || empty($apellido) || empty($documento) || empty($telefono) || empty($email) || empty($password)) {
        $error = 'Por favor completa todos los campos requeridos para crear tu cuenta.';
    } elseif (!is_valid_email($email)) {
        $error = 'Por favor ingresa un correo electrónico válido.';
    } elseif (!is_valid_phone($telefono)) {
        $error = 'El número de teléfono debe contener únicamente dígitos numéricos (entre 7 y 12 dígitos).';
    } elseif (!is_valid_document($documento)) {
        $error = 'El documento de identidad (CI / NIT) no tiene un formato válido.';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres por seguridad.';
    } elseif ($password !== $password_confirm) {
        $error = 'Las contraseñas ingresadas no coinciden. Por favor verifícalas.';
    } else {
        $supabase = new SupabaseClient();
        if (!$supabase->isConfigured()) {
            $error = 'No se pudo conectar con el servicio de autenticación. Intenta más tarde.';
        } else {
            // 2. Registrar usuario en Supabase Auth
            $userMeta = [
                'nombre' => $nombre,
                'apellido' => $apellido,
                'ci' => $documento,
                'telefono' => $telefono,
                'rol_id' => 6 // Rol Cliente
            ];

            $signUpRes = $supabase->signUp($email, $password, $userMeta);

            if (!$signUpRes['success']) {
                $rawErr = $signUpRes['error'] ?? 'Error al registrar el usuario.';
                if (stripos($rawErr, 'already registered') !== false || stripos($rawErr, 'User already registered') !== false) {
                    $error = 'Ya existe una cuenta registrada con este correo electrónico. Por favor inicia sesión.';
                } else {
                    $error = 'Error en el registro: ' . $rawErr;
                }
            } else {
                $authData = $signUpRes['data'] ?? [];
                $userId = $authData['id'] ?? ($authData['user']['id'] ?? null);

                // 3. Iniciar sesión automáticamente
                $signInRes = $supabase->signIn($email, $password);
                $accessToken = null;
                if ($signInRes['success'] && !empty($signInRes['data']['access_token'])) {
                    $accessToken = $signInRes['data']['access_token'];
                    $userId = $signInRes['data']['user']['id'] ?? $userId;
                }

                // 4. Sincronizar / Crear registro en la tabla clientes
                $clienteId = null;
                $checkCli = $supabase->select('clientes', '*', ['documento' => 'eq.' . $documento], $accessToken);
                if ($checkCli['success'] && !empty($checkCli['data'])) {
                    $clienteId = $checkCli['data'][0]['id'];
                    // Actualizar datos del cliente existente
                    $supabase->update('clientes', [
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'telefono' => $telefono,
                        'email' => $email,
                        'estado' => true
                    ], 'id=eq.' . $clienteId, $accessToken);
                } else {
                    // Insertar nuevo cliente
                    $insertCli = $supabase->insert('clientes', [
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'documento' => $documento,
                        'telefono' => $telefono,
                        'email' => $email,
                        'estado' => true
                    ], $accessToken);

                    if ($insertCli['success'] && !empty($insertCli['data'])) {
                        $clienteId = $insertCli['data'][0]['id'];
                    }
                }

                // 5. Sincronizar tabla perfiles
                if ($userId) {
                    $supabase->upsert('perfiles', [
                        'id' => $userId,
                        'rol_id' => 6, // Rol Cliente
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'email' => $email,
                        'ci' => $documento,
                        'telefono' => $telefono,
                        'estado' => true
                    ], $accessToken);
                }

                // 6. Guardar sesión y redirigir
                if ($accessToken && $userId) {
                    $_SESSION['user'] = [
                        'id' => $userId,
                        'email' => $email,
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'ci' => $documento,
                        'telefono' => $telefono,
                        'rol_id' => 6,
                        'rol_nombre' => 'Cliente',
                        'cliente_id' => $clienteId,
                        'access_token' => $accessToken
                    ];

                    set_flash_message('success', '¡Bienvenido a Restaurant Smart, ' . htmlspecialchars($nombre) . '! Tu cuenta fue creada exitosamente. Ya puedes reservar tu mesa.');
                    redirect('/modules/reservas/online.php');
                } else {
                    set_flash_message('success', 'Cuenta creada exitosamente. Por favor inicia sesión con tus credenciales.');
                    redirect('/auth/login.php');
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Cliente - Restaurant Smart</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Estilos Personalizados -->
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="login-body">

<div class="login-card" style="max-width: 520px;">
    <div class="text-center">
        <div class="login-logo">
            <i class="bi bi-person-plus-fill"></i>
        </div>
        <h2 class="fw-bold mb-1" style="color: var(--secondary-color);">Crear Cuenta de Cliente</h2>
        <p class="text-muted fs-6 mb-4">Regístrate para reservar mesas online y preordenar tu menú</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form action="" method="POST" autocomplete="off">
        <div class="row g-3">
            <div class="col-sm-6">
                <label for="nombre" class="form-label fw-semibold text-secondary small">Nombre <span class="text-danger">*</span></label>
                <input type="text" class="form-control bg-light" id="nombre" name="nombre" placeholder="Ej: Juan" required value="<?= htmlspecialchars($nombre) ?>" autofocus>
            </div>

            <div class="col-sm-6">
                <label for="apellido" class="form-label fw-semibold text-secondary small">Apellido <span class="text-danger">*</span></label>
                <input type="text" class="form-control bg-light" id="apellido" name="apellido" placeholder="Ej: Pérez" required value="<?= htmlspecialchars($apellido) ?>">
            </div>

            <div class="col-sm-6">
                <label for="documento" class="form-label fw-semibold text-secondary small">Documento / CI <span class="text-danger">*</span></label>
                <input type="text" class="form-control bg-light" id="documento" name="documento" placeholder="Ej: 1234567" required value="<?= htmlspecialchars($documento) ?>">
            </div>

            <div class="col-sm-6">
                <label for="telefono" class="form-label fw-semibold text-secondary small">Teléfono / Celular <span class="text-danger">*</span></label>
                <input type="tel" class="form-control bg-light" id="telefono" name="telefono" placeholder="Ej: 71234567" required value="<?= htmlspecialchars($telefono) ?>">
            </div>

            <div class="col-12">
                <label for="email" class="form-label fw-semibold text-secondary small">Correo Electrónico <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                    <input type="email" class="form-control bg-light border-start-0" id="email" name="email" placeholder="juan.perez@email.com" required value="<?= htmlspecialchars($email) ?>">
                </div>
            </div>

            <div class="col-sm-6">
                <label for="password" class="form-label fw-semibold text-secondary small">Contraseña <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
                    <input type="password" class="form-control bg-light border-start-0" id="password" name="password" placeholder="Mínimo 6 caracteres" required minlength="6">
                </div>
            </div>

            <div class="col-sm-6">
                <label for="password_confirm" class="form-label fw-semibold text-secondary small">Confirmar Contraseña <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-shield-lock text-muted"></i></span>
                    <input type="password" class="form-control bg-light border-start-0" id="password_confirm" name="password_confirm" placeholder="Repite tu contraseña" required minlength="6">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary-custom w-100 py-2 fs-6 mt-4">
            <i class="bi bi-check2-circle me-2"></i> Crear Cuenta y Continuar
        </button>
    </form>

    <div class="mt-4 pt-3 border-top text-center">
        <p class="text-muted small mb-0">
            ¿Ya tienes una cuenta registrada? 
            <a href="<?= BASE_URL ?>/auth/login.php" class="fw-bold text-decoration-none" style="color: var(--primary-color);">Iniciar Sesión</a>
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
