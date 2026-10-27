<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/supabase.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/auth/login.php');
}

$email = clean_input($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    set_flash_message('danger', 'Por favor ingresa tu correo electrónico y contraseña.');
    redirect('/auth/login.php');
}

$supabase = new SupabaseClient();

if (!$supabase->isConfigured()) {
    set_flash_message('warning', 'Supabase no está configurado. Revisa tu archivo .env para agregar SUPABASE_URL y SUPABASE_ANON_KEY.');
    redirect('/auth/login.php');
}

// Intentar autenticación mediante Supabase Auth API
$authResponse = $supabase->signIn($email, $password);

if (!$authResponse['success']) {
    $errorMsg = $authResponse['error'] ?? 'Credenciales inválidas. Verifica tu correo y contraseña.';
    
    // Mapeo amigable de errores comunes de Supabase Auth
    if (stripos($errorMsg, 'Invalid login credentials') !== false) {
        $errorMsg = 'Correo electrónico o contraseña incorrectos.';
    } elseif (stripos($errorMsg, 'Email not confirmed') !== false) {
        $errorMsg = 'El correo electrónico no ha sido confirmado en el sistema.';
    } elseif (stripos($errorMsg, 'Too many requests') !== false) {
        $errorMsg = 'Demasiados intentos fallidos. Por favor, intenta más tarde.';
    }
    
    set_flash_message('danger', $errorMsg);
    redirect('/auth/login.php');
}

$authData = $authResponse['data'];
$accessToken = $authData['access_token'] ?? '';
$userId = $authData['user']['id'] ?? '';

// Consultar perfil del usuario en la tabla perfiles de PostgreSQL
$profileResponse = $supabase->select('perfiles', '*,roles(id,nombre)', ['id' => 'eq.' . $userId], $accessToken);

$userProfile = [];
if ($profileResponse['success'] && !empty($profileResponse['data'])) {
    $userProfile = $profileResponse['data'][0];
} else {
    // Sincronización automática: si el perfil no existe en public.perfiles, crearlo/actualizarlo con la metadata
    $userMeta = $authData['user']['user_metadata'] ?? [];
    $nombre = $userMeta['nombre'] ?? explode('@', $email)[0];
    $apellido = $userMeta['apellido'] ?? '';
    $ci = $userMeta['ci'] ?? '';
    $telefono = $userMeta['telefono'] ?? '';
    $rolId = (int)($userMeta['rol_id'] ?? 2); // Por defecto rol base 2 (Recepcionista), nunca 1 (Administrador)

    $upsertRes = $supabase->upsert('perfiles', [
        'id' => $userId,
        'rol_id' => $rolId,
        'nombre' => $nombre,
        'apellido' => $apellido,
        'email' => $authData['user']['email'] ?? $email,
        'ci' => $ci,
        'telefono' => $telefono,
        'estado' => true
    ], $accessToken);

    if ($upsertRes['success']) {
        $profileReload = $supabase->select('perfiles', '*,roles(id,nombre)', ['id' => 'eq.' . $userId], $accessToken);
        if ($profileReload['success'] && !empty($profileReload['data'])) {
            $userProfile = $profileReload['data'][0];
        }
    }
}

// 1. Verificación crítica: Bloquear inicio de sesión a usuarios inactivos
$estado = $userProfile['estado'] ?? false;
if ($estado === false || $estado === 'false' || $estado === 0 || $estado === '0' || $estado === 'f') {
    if (!empty($accessToken)) {
        $supabase->signOut($accessToken);
    }
    set_flash_message('danger', 'Tu cuenta se encuentra inactiva. Contacta al administrador.');
    redirect('/auth/login.php');
}

// 2. Verificación crítica de rol: Denegar acceso si no existe un rol válido (sin fallback inseguro)
$rolNombre = $userProfile['roles']['nombre'] ?? null;
$rolId = $userProfile['rol_id'] ?? null;

if (empty($rolNombre) || empty($rolId)) {
    if (!empty($accessToken)) {
        $supabase->signOut($accessToken);
    }
    set_flash_message('danger', 'Tu usuario no tiene un rol válido asignado. Contacta al administrador.');
    redirect('/auth/login.php');
}

// 3. Vincular ID de cliente si el usuario tiene rol Cliente
$clienteId = null;
if ($rolNombre === 'Cliente') {
    $docSearch = $userProfile['ci'] ?? '';
    $userEmail = $authData['user']['email'] ?? $email;

    $cliRes = $supabase->select('clientes', '*', ['email' => 'eq.' . $userEmail], $accessToken);
    if ($cliRes['success'] && !empty($cliRes['data'])) {
        $clienteId = $cliRes['data'][0]['id'];
    } elseif (!empty($docSearch)) {
        $cliResDoc = $supabase->select('clientes', '*', ['documento' => 'eq.' . $docSearch], $accessToken);
        if ($cliResDoc['success'] && !empty($cliResDoc['data'])) {
            $clienteId = $cliResDoc['data'][0]['id'];
        }
    }

    // Si aún no tiene registro en clientes, crearlo
    if (!$clienteId) {
        $newCli = $supabase->insert('clientes', [
            'nombre' => $userProfile['nombre'] ?? 'Cliente',
            'apellido' => $userProfile['apellido'] ?? '',
            'documento' => !empty($docSearch) ? $docSearch : ('CLI-' . substr($userId, 0, 8)),
            'telefono' => $userProfile['telefono'] ?? '',
            'email' => $userEmail,
            'estado' => true
        ], $accessToken);

        if ($newCli['success'] && !empty($newCli['data'])) {
            $clienteId = $newCli['data'][0]['id'];
        }
    }
}

// Guardar datos en sesión
$_SESSION['user'] = [
    'id' => $userId,
    'email' => $authData['user']['email'] ?? $email,
    'nombre' => $userProfile['nombre'] ?? ($authData['user']['user_metadata']['nombre'] ?? 'Usuario'),
    'apellido' => $userProfile['apellido'] ?? ($authData['user']['user_metadata']['apellido'] ?? ''),
    'ci' => $userProfile['ci'] ?? '',
    'telefono' => $userProfile['telefono'] ?? '',
    'rol_id' => $rolId,
    'rol_nombre' => $rolNombre,
    'cliente_id' => $clienteId,
    'access_token' => $accessToken,
    'refresh_token' => $authData['refresh_token'] ?? ''
];

set_flash_message('success', 'Bienvenido de nuevo, ' . htmlspecialchars($_SESSION['user']['nombre']) . '!');

if ($rolNombre === 'Cliente') {
    redirect('/modules/reservas/online.php');
} else {
    redirect('/modules/dashboard/');
}
