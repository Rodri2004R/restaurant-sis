<?php
/**
 * Restaurant Smart - Entry Point & Router
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/auth/middleware.php';

if (is_authenticated()) {
    $user = get_authenticated_user();
    if (($user['rol_nombre'] ?? '') === 'Cliente') {
        redirect('/modules/reservas/online.php');
    } else {
        redirect('/modules/dashboard/');
    }
} else {
    redirect('/auth/login.php');
}
