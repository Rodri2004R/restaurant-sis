<header class="app-topbar">
    <div class="d-flex align-items-center gap-3">
        <h2 class="page-title"><?= $pageTitle ?? 'Panel de Control' ?></h2>
    </div>

    <div class="d-flex align-items-center gap-3">
        <div class="text-end d-none d-md-block">
            <small class="text-muted d-block" id="live-clock"><?= date('d/m/Y - H:i') ?></small>
            <span class="badge badge-role">
                <i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($currentUser['rol_nombre'] ?? 'Usuario') ?>
            </span>
        </div>
        
        <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">
            <i class="bi bi-box-arrow-right me-1"></i> Salir
        </a>
    </div>
</header>
