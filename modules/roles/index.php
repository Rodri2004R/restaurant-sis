<?php
$pageTitle = 'Gestión de Roles y Permisos';
$currentModule = 'roles';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$roles = [];

if ($supabase->isConfigured()) {
    $res = $supabase->select('roles', '*', ['order' => 'id.asc']);
    if ($res['success']) {
        $roles = $res['data'];
    }
}

// Datos por defecto si aún no hay conexión a Supabase
if (empty($roles)) {
    $roles = [
        ['id' => 1, 'nombre' => 'Administrador', 'descripcion' => 'Acceso total al sistema, gestión de usuarios, roles, clientes y configuraciones.', 'estado' => true],
        ['id' => 2, 'nombre' => 'Recepcionista', 'descripcion' => 'Gestión de clientes, reservas, asignación de mesas y atención en recepción.', 'estado' => true],
        ['id' => 3, 'nombre' => 'Mesero', 'descripcion' => 'Atención de mesas, toma de pedidos y visualización de reservas.', 'estado' => true],
        ['id' => 4, 'nombre' => 'Cocinero', 'descripcion' => 'Visualización y gestión del estado de pedidos en cocina.', 'estado' => true],
        ['id' => 5, 'nombre' => 'Cajero', 'descripcion' => 'Cobro de pedidos, emisión de comprobantes y cierre de caja.', 'estado' => true],
    ];
}

// Matriz de permisos por rol para visualización interactiva
$permisos = [
    'Administrador' => ['Gestión de Usuarios', 'Gestión de Roles', 'Gestión de Clientes', 'Gestión de Mesas', 'Gestión de Reservas', 'Reportes Avanzados'],
    'Recepcionista' => ['Gestión de Clientes', 'Gestión de Mesas', 'Gestión de Reservas'],
    'Mesero' => ['Ver Mesas', 'Ver Reservas', 'Gestionar Pedidos'],
    'Cocinero' => ['Ver Pedidos', 'Cambiar Estado de Platillos'],
    'Cajero' => ['Ver Pedidos', 'Cobrar Ventas', 'Cierre de Caja']
];
?>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <?php require_once __DIR__ . '/../../includes/alerts.php'; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-1" style="color: var(--secondary-color);">Roles y Estructura de Permisos (US-003)</h3>
                <p class="text-muted mb-0">Control de acceso y capacidades según el rol asignado en Restaurant Smart</p>
            </div>
            <span class="badge bg-primary px-3 py-2 fs-6">Total Roles: <?= count($roles) ?></span>
        </div>

        <div class="row g-4">
            <?php foreach ($roles as $rol): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card-custom h-100 p-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="badge badge-role fs-6 px-3 py-1">
                                    <i class="bi bi-shield-check me-1 text-primary"></i> <?= htmlspecialchars($rol['nombre']) ?>
                                </span>
                                <span class="badge <?= $rol['estado'] ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $rol['estado'] ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </div>
                            <p class="text-muted fs-6 mb-3">
                                <?= htmlspecialchars($rol['descripcion']) ?>
                            </p>
                        </div>

                        <div>
                            <h6 class="fw-bold text-secondary mb-2" style="font-size: 0.82rem; text-transform: uppercase;">Permisos Asignados:</h6>
                            <div class="d-flex flex-wrap gap-1">
                                <?php 
                                $modulosRol = $permisos[$rol['nombre']] ?? ['Acceso Básico'];
                                foreach ($modulosRol as $permiso): 
                                ?>
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-check2 text-success me-1"></i><?= htmlspecialchars($permiso) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
