<?php
require_once __DIR__ . '/../config/config.php';

/**
 * Comprobar si el usuario tiene sesión activa
 */
function is_authenticated(): bool {
    return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

/**
 * Exigir autenticación (Redirigir a login si no hay sesión)
 */
function require_auth() {
    if (!is_authenticated()) {
        set_flash_message('warning', 'Debes iniciar sesión para acceder al sistema.');
        redirect('/auth/login.php');
    }
}

/**
 * Exigir que sea visitante (Redirigir al panel correspondiente si ya está autenticado)
 */
function require_guest() {
    if (is_authenticated()) {
        $user = get_authenticated_user();
        if (($user['rol_nombre'] ?? '') === 'Cliente') {
            redirect('/modules/reservas/online.php');
        } else {
            redirect('/modules/dashboard/');
        }
    }
}

/**
 * Exigir un conjunto de roles específicos (RBAC)
 */
function require_role(array $allowedRoles) {
    require_auth();
    $user = get_authenticated_user();
    $userRole = $user['rol_nombre'] ?? '';

    if (!in_array($userRole, $allowedRoles)) {
        set_flash_message('danger', 'Acceso denegado. Tu rol de "' . htmlspecialchars($userRole) . '" no tiene permisos para esta sección.');
        if ($userRole === 'Cliente') {
            redirect('/modules/reservas/online.php');
        } else {
            redirect('/modules/dashboard/');
        }
    }
}

/**
 * Obtener datos del usuario autenticado
 */
function get_authenticated_user(): array {
    return $_SESSION['user'] ?? [];
}

/**
 * Obtener ID de cliente del usuario autenticado (si es cliente)
 */
function get_current_cliente_id(): ?string {
    return $_SESSION['user']['cliente_id'] ?? null;
}
