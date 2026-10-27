<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/supabase.php';

if (isset($_SESSION['user']['access_token'])) {
    $supabase = new SupabaseClient();
    if ($supabase->isConfigured()) {
        $supabase->signOut($_SESSION['user']['access_token']);
    }
}

// Destruir sesión en PHP
$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

set_flash_message('info', 'Has cerrado sesión correctamente.');
redirect('/auth/login.php');
