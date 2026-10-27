<?php
$flash = get_flash_message();
if ($flash):
    $alertType = $flash['type'] ?? 'info';
    $message = $flash['message'] ?? '';
    
    $iconMap = [
        'success' => 'bi-check-circle-fill',
        'danger' => 'bi-exclamation-triangle-fill',
        'warning' => 'bi-exclamation-circle-fill',
        'info' => 'bi-info-circle-fill'
    ];
    $icon = $iconMap[$alertType] ?? 'bi-info-circle-fill';
?>
    <div class="alert alert-<?= $alertType ?> alert-dismissible fade show border-0 shadow-sm d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="bi <?= $icon ?> fs-5"></i>
        <div><?= htmlspecialchars($message) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
