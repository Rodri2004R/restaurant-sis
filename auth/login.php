<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../auth/middleware.php';

// Si ya está autenticado, ir directo al dashboard
require_guest();
$flash = get_flash_message();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Restaurant Smart</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Estilos Personalizados -->
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="login-body">

<div class="login-card">
    <div class="text-center">
        <div class="login-logo">
            <i class="bi bi-shop"></i>
        </div>
        <h2 class="fw-bold mb-1" style="color: var(--secondary-color);">Restaurant Smart</h2>
        <p class="text-muted fs-6 mb-4">Ingresa tus credenciales para acceder al sistema</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= htmlspecialchars($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form action="<?= BASE_URL ?>/auth/process_login.php" method="POST">
        <div class="mb-3">
            <label for="email" class="form-label fw-semibold text-secondary">Correo Electrónico</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                <input type="email" class="form-control bg-light border-start-0" id="email" name="email" placeholder="ejemplo@restaurante.com" required autofocus>
            </div>
        </div>

        <div class="mb-4">
            <label for="password" class="form-label fw-semibold text-secondary">Contraseña</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
                <input type="password" class="form-control bg-light border-start-0" id="password" name="password" placeholder="••••••••" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary-custom w-100 py-2 fs-6">
            <i class="bi bi-box-arrow-in-right me-2"></i> Iniciar Sesión
        </button>
    </form>

    <div class="mt-4 pt-3 border-top text-center">
        <p class="text-muted small mb-2">
            ¿Eres un nuevo comensal? 
            <a href="<?= BASE_URL ?>/auth/register.php" class="fw-bold text-decoration-none" style="color: var(--primary-color);">Regístrate aquí para reservar mesa</a>
        </p>
        <small class="text-muted">
            <i class="bi bi-shield-check text-success me-1"></i> Sistema Integrado con Supabase Auth & PostgreSQL
        </small>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
