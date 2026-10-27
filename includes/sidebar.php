<?php
$currentModule = $currentModule ?? 'dashboard';
$roleName = $currentUser['rol_nombre'] ?? 'Usuario';
?>
<aside class="app-sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand-icon">
            <i class="bi bi-shop"></i>
        </div>
        <div>
            <h1 class="sidebar-brand-text">Restaurant Smart</h1>
        </div>
    </div>

    <nav class="sidebar-menu">
        <?php if ($roleName === 'Cliente'): ?>
            <!-- Menú para Clientes Comensales -->
            <div class="menu-category">Reservas & Salón</div>
            <a href="<?= BASE_URL ?>/modules/reservas/online.php" class="nav-link-custom <?= $currentModule === 'reservas_online' ? 'active' : '' ?>">
                <i class="bi bi-calendar-plus-fill"></i>
                <span>Reservar Mesa</span>
            </a>

            <a href="<?= BASE_URL ?>/modules/reservas/mis_reservas.php" class="nav-link-custom <?= $currentModule === 'mis_reservas' ? 'active' : '' ?>">
                <i class="bi bi-bookmark-check-fill"></i>
                <span>Mis Reservas</span>
            </a>

            <div class="menu-category">Gastronomía</div>
            <a href="<?= BASE_URL ?>/modules/productos/" class="nav-link-custom <?= $currentModule === 'productos' ? 'active' : '' ?>">
                <i class="bi bi-journal-richtext"></i>
                <span>Menú & Platillos</span>
            </a>
        <?php else: ?>
            <!-- Menú para Personal del Restaurante -->
            <div class="menu-category">General</div>
            <a href="<?= BASE_URL ?>/modules/dashboard/" class="nav-link-custom <?= $currentModule === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>

            <!-- Operaciones, Menú & Comandas (Sprint 2) -->
            <?php if (in_array($roleName, ['Administrador', 'Mesero', 'Cocinero', 'Cajero', 'Recepcionista'])): ?>
                <div class="menu-category">Operaciones & Menú</div>

                <a href="<?= BASE_URL ?>/modules/pedidos/" class="nav-link-custom <?= $currentModule === 'pedidos' ? 'active' : '' ?>">
                    <i class="bi bi-receipt"></i>
                    <span>Pedidos & POS</span>
                </a>

                <?php if (in_array($roleName, ['Administrador', 'Cocinero', 'Mesero'])): ?>
                    <a href="<?= BASE_URL ?>/modules/cocina/" class="nav-link-custom <?= $currentModule === 'cocina' ? 'active' : '' ?>">
                        <i class="bi bi-fire"></i>
                        <span>Cocina / KDS</span>
                    </a>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>/modules/productos/" class="nav-link-custom <?= $currentModule === 'productos' ? 'active' : '' ?>">
                    <i class="bi bi-journal-richtext"></i>
                    <span>Menú & Platillos</span>
                </a>

                <?php if ($roleName === 'Administrador'): ?>
                    <a href="<?= BASE_URL ?>/modules/categorias/" class="nav-link-custom <?= $currentModule === 'categorias' ? 'active' : '' ?>">
                        <i class="bi bi-tags-fill"></i>
                        <span>Categorías</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Atención & Reservas (Sprint 1) -->
            <?php if (in_array($roleName, ['Administrador', 'Recepcionista', 'Mesero'])): ?>
                <div class="menu-category">Atención & Salón</div>
                
                <a href="<?= BASE_URL ?>/modules/reservas/" class="nav-link-custom <?= $currentModule === 'reservas' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-check-fill"></i>
                    <span>Reservas</span>
                </a>

                <a href="<?= BASE_URL ?>/modules/clientes/" class="nav-link-custom <?= $currentModule === 'clientes' ? 'active' : '' ?>">
                    <i class="bi bi-people-fill"></i>
                    <span>Clientes</span>
                </a>

                <a href="<?= BASE_URL ?>/modules/mesas/" class="nav-link-custom <?= $currentModule === 'mesas' ? 'active' : '' ?>">
                    <i class="bi bi-table"></i>
                    <span>Mesas & Salón</span>
                </a>
            <?php endif; ?>

            <!-- Abastecimiento & Compras (Sprint 3) -->
            <?php if (in_array($roleName, ['Administrador', 'Cocinero', 'Cajero'])): ?>
                <div class="menu-category">Abastecimiento & Compras</div>
                
                <?php if ($roleName === 'Administrador'): ?>
                    <a href="<?= BASE_URL ?>/modules/proveedores/" class="nav-link-custom <?= $currentModule === 'proveedores' ? 'active' : '' ?>">
                        <i class="bi bi-truck"></i>
                        <span>Proveedores</span>
                    </a>
                <?php endif; ?>

                <?php if (in_array($roleName, ['Administrador', 'Cajero'])): ?>
                    <a href="<?= BASE_URL ?>/modules/compras/" class="nav-link-custom <?= $currentModule === 'compras' ? 'active' : '' ?>">
                        <i class="bi bi-bag-check-fill"></i>
                        <span>Compras</span>
                    </a>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>/modules/inventario/" class="nav-link-custom <?= $currentModule === 'inventario' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam-fill"></i>
                    <span>Inventario</span>
                </a>

                <?php if ($roleName === 'Administrador'): ?>
                    <a href="<?= BASE_URL ?>/modules/inventario/movement.php" class="nav-link-custom <?= $currentModule === 'movimiento_inventario' ? 'active' : '' ?>">
                        <i class="bi bi-arrow-left-right"></i>
                        <span>Registrar Movimiento</span>
                    </a>

                    <a href="<?= BASE_URL ?>/modules/kardex/" class="nav-link-custom <?= $currentModule === 'kardex' ? 'active' : '' ?>">
                        <i class="bi bi-journal-text"></i>
                        <span>Kardex</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Administración (Sprint 1) -->
            <?php if ($roleName === 'Administrador'): ?>
                <div class="menu-category">Administración</div>
                
                <a href="<?= BASE_URL ?>/modules/usuarios/" class="nav-link-custom <?= $currentModule === 'usuarios' ? 'active' : '' ?>">
                    <i class="bi bi-person-badge-fill"></i>
                    <span>Usuarios</span>
                </a>
                
                <a href="<?= BASE_URL ?>/modules/roles/" class="nav-link-custom <?= $currentModule === 'roles' ? 'active' : '' ?>">
                    <i class="bi bi-shield-lock-fill"></i>
                    <span>Roles & Permisos</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-profile-badge">
            <div class="avatar-circle">
                <?= strtoupper(substr($currentUser['nombre'] ?? 'U', 0, 1)) ?>
            </div>
            <div class="user-info-text">
                <p class="user-name"><?= htmlspecialchars(($currentUser['nombre'] ?? '') . ' ' . ($currentUser['apellido'] ?? '')) ?></p>
                <p class="user-role"><?= htmlspecialchars($roleName) ?></p>
            </div>
            <a href="<?= BASE_URL ?>/auth/logout.php" class="text-danger ms-auto fs-5" title="Cerrar Sesión">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</aside>
