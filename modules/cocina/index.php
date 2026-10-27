<?php
$pageTitle = 'Monitor de Cocina (KDS)';
$currentModule = 'cocina';

require_once __DIR__ . '/../../includes/header.php';
require_role(['Administrador', 'Cocinero', 'Mesero', 'Cajero', 'Recepcionista']);

require_once __DIR__ . '/../../config/supabase.php';

$supabase = new SupabaseClient();
$authToken = $_SESSION['user']['access_token'] ?? null;
$userRole = $currentUser['rol_nombre'] ?? '';
$isAdmin = ($userRole === 'Administrador');
$isCocinero = ($userRole === 'Cocinero');
$isMesero = ($userRole === 'Mesero');

$filtroEstado = clean_input($_GET['estado'] ?? 'activas');

// 1. Cargar comandas activas iniciales mediante servidor PHP
$pedidosIniciales = [];
$counts = ['Pendiente' => 0, 'En Preparación' => 0, 'Listo' => 0];

if ($supabase->isConfigured()) {
    $params = ['order' => 'created_at.asc'];
    if ($filtroEstado === 'Pendiente') {
        $params['estado'] = 'eq.Pendiente';
    } elseif ($filtroEstado === 'En Preparación') {
        $params['estado'] = 'eq.En Preparación';
    } elseif ($filtroEstado === 'Listo') {
        $params['estado'] = 'eq.Listo';
    } else {
        $params['estado'] = 'in.(Pendiente,En Preparación)';
    }

    $resPedidos = $supabase->select('pedidos', 'id,mesa_id,cliente_id,usuario_id,total,estado,observaciones,created_at,mesas(id,numero,ubicacion),clientes(id,nombre,apellido),perfiles(id,nombre,apellido)', $params, $authToken);

    if ($resPedidos['success'] && !empty($resPedidos['data'])) {
        $pedidosRaw = $resPedidos['data'];
        $pedidoIds = array_column($pedidosRaw, 'id');

        $detallesMap = [];
        if (!empty($pedidoIds)) {
            $inIds = implode(',', $pedidoIds);
            $resDet = $supabase->select('detalles_pedido', 'id,pedido_id,producto_id,cantidad,precio_unitario,subtotal,notas,productos(id,nombre,categoria_id,categorias(nombre))', [
                'pedido_id' => 'in.(' . $inIds . ')',
                'order' => 'id.asc'
            ], $authToken);

            if ($resDet['success'] && !empty($resDet['data'])) {
                foreach ($resDet['data'] as $det) {
                    $pId = $det['pedido_id'];
                    if (!isset($detallesMap[$pId])) {
                        $detallesMap[$pId] = [];
                    }
                    $detallesMap[$pId][] = [
                        'id' => $det['id'],
                        'producto_id' => $det['producto_id'],
                        'producto_nombre' => $det['productos']['nombre'] ?? 'Platillo',
                        'categoria_nombre' => $det['productos']['categorias']['nombre'] ?? '',
                        'cantidad' => (int)($det['cantidad'] ?? 1),
                        'notas' => trim($det['notas'] ?? '')
                    ];
                }
            }
        }

        $now = time();
        foreach ($pedidosRaw as $p) {
            $pId = $p['id'];
            $createdTimestamp = strtotime($p['created_at']);
            $pedidosIniciales[] = [
                'id' => $pId,
                'short_id' => substr($pId, 0, 8),
                'mesa_numero' => $p['mesas']['numero'] ?? '-',
                'mesa_ubicacion' => $p['mesas']['ubicacion'] ?? 'Salón',
                'cliente_nombre' => !empty($p['clientes']) ? trim(($p['clientes']['nombre'] ?? '') . ' ' . ($p['clientes']['apellido'] ?? '')) : 'Cliente Salón',
                'mesero_nombre' => !empty($p['perfiles']) ? trim(($p['perfiles']['nombre'] ?? '') . ' ' . ($p['perfiles']['apellido'] ?? '')) : 'Personal',
                'estado' => $p['estado'] ?? 'Pendiente',
                'observaciones' => trim($p['observaciones'] ?? ''),
                'hora_creacion' => date('H:i', $createdTimestamp),
                'fecha_creacion' => date('d/m/Y', $createdTimestamp),
                'minutos_transcurridos' => max(0, floor(($now - $createdTimestamp) / 60)),
                'detalles' => $detallesMap[$pId] ?? []
            ];
        }
    }

    $resCounts = $supabase->select('pedidos', 'id,estado', ['estado' => 'in.(Pendiente,En Preparación,Listo)'], $authToken);
    if ($resCounts['success'] && !empty($resCounts['data'])) {
        foreach ($resCounts['data'] as $c) {
            $st = $c['estado'] ?? '';
            if (isset($counts[$st])) {
                $counts[$st]++;
            }
        }
    }
}
?>

<style>
/* Estilos KDS específicos para Cocina */
.kds-header {
    background: #ffffff;
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
    margin-bottom: 1.5rem;
}

.kds-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.25rem;
}

.kds-card {
    background: #ffffff;
    border-radius: 14px;
    border: 2px solid #e2e8f0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.2s ease;
}

.kds-card:hover {
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
}

.kds-card.state-pendiente {
    border-top: 6px solid #f59e0b;
}

.kds-card.state-en-preparacion {
    border-top: 6px solid #3b82f6;
}

.kds-card.state-listo {
    border-top: 6px solid #10b981;
}

.kds-card-header {
    padding: 1rem 1.25rem;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.kds-table-badge {
    font-size: 1.1rem;
    font-weight: 800;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.kds-timer-badge {
    font-size: 0.8rem;
    font-weight: 600;
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    background: #e2e8f0;
    color: #475569;
}

.kds-timer-urgent {
    background: #fee2e2 !important;
    color: #dc2626 !important;
    animation: pulse-urgent 1.5s infinite;
}

@keyframes pulse-urgent {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

.kds-card-body {
    padding: 1.25rem;
    flex: 1;
    display: flex;
    flex-direction: column;
}

.kds-item-row {
    padding: 0.6rem 0;
    border-bottom: 1px dashed #e2e8f0;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.kds-item-row:last-child {
    border-bottom: none;
}

.kds-qty-badge {
    background: #1e293b;
    color: #ffffff;
    font-weight: 800;
    font-size: 0.95rem;
    padding: 0.2rem 0.6rem;
    border-radius: 6px;
    min-width: 34px;
    text-align: center;
    flex-shrink: 0;
}

.kds-item-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.3;
    margin-bottom: 0.15rem;
}

.kds-item-note {
    background: #fef3c7;
    color: #92400e;
    font-size: 0.82rem;
    font-weight: 600;
    padding: 0.25rem 0.5rem;
    border-radius: 6px;
    display: inline-block;
    margin-top: 0.25rem;
    border-left: 3px solid #f59e0b;
}

.kds-card-footer {
    padding: 1rem 1.25rem;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
}

.kds-btn-action {
    font-size: 1rem;
    font-weight: 700;
    padding: 0.7rem 1.25rem;
    border-radius: 8px;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: all 0.15s ease;
    border: none;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}

.kds-btn-start {
    background: #f59e0b;
    color: #ffffff;
}

.kds-btn-start:hover {
    background: #d97706;
    color: #ffffff;
}

.kds-btn-ready {
    background: #10b981;
    color: #ffffff;
}

.kds-btn-ready:hover {
    background: #059669;
    color: #ffffff;
}

.kds-btn-done {
    background: #e2e8f0;
    color: #64748b;
    cursor: default;
}

.kds-obs-banner {
    background: #f1f5f9;
    color: #334155;
    font-size: 0.82rem;
    padding: 0.4rem 0.75rem;
    border-radius: 6px;
    margin-top: 0.75rem;
}

.live-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: #10b981;
}

.live-dot {
    width: 9px;
    height: 9px;
    background: #10b981;
    border-radius: 50%;
    display: inline-block;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: live-pulse 2s infinite;
}

@keyframes live-pulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
</style>

<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="app-content">
    <?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

    <main class="main-container">
        <?php require_once __DIR__ . '/../../includes/alerts.php'; ?>

        <!-- Encabezado del Monitor de Cocina -->
        <div class="kds-header">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="avatar-circle" style="width: 36px; height: 36px; font-size: 1.1rem; background: #fee2e2; color: #dc2626;">
                            <i class="bi bi-fire"></i>
                        </span>
                        <h3 class="fw-bold m-0" style="color: var(--secondary-color);">Monitor de Cocina (KDS)</h3>
                        <span class="live-indicator ms-2">
                            <span class="live-dot"></span> EN VIVO
                        </span>
                    </div>
                    <p class="text-muted mb-0">Visualización y preparación de comandas en tiempo real</p>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="text-muted small d-none d-sm-inline" id="last-update-text">
                        <i class="bi bi-clock-history me-1"></i>Última actualización: <strong id="update-clock"><?= date('H:i:s') ?></strong>
                    </span>
                    <button class="btn btn-outline-secondary btn-sm" id="btn-manual-refresh" title="Actualizar ahora">
                        <i class="bi bi-arrow-clockwise" id="refresh-icon"></i> Refrescar
                    </button>
                    <a href="<?= BASE_URL ?>/modules/pedidos/" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-receipt me-1"></i> Lista General POS
                    </a>
                </div>
            </div>

            <!-- Filtros de Pestañas de Cocina -->
            <div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
                <a href="<?= BASE_URL ?>/modules/cocina/?estado=activas" 
                   class="btn btn-sm <?= $filtroEstado === 'activas' ? 'btn-primary-custom' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-fire me-1"></i> Comandas Activas (<span id="count-activas"><?= $counts['Pendiente'] + $counts['En Preparación'] ?></span>)
                </a>

                <a href="<?= BASE_URL ?>/modules/cocina/?estado=Pendiente" 
                   class="btn btn-sm <?= $filtroEstado === 'Pendiente' ? 'btn-warning text-dark fw-bold' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-hourglass-split me-1"></i> Por Cocinar (<span id="count-pendientes"><?= $counts['Pendiente'] ?></span>)
                </a>

                <a href="<?= BASE_URL ?>/modules/cocina/?estado=En+Preparaci%C3%B3n" 
                   class="btn btn-sm <?= $filtroEstado === 'En Preparación' ? 'btn-primary fw-bold' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-egg-fried me-1"></i> En Preparación (<span id="count-en-preparacion"><?= $counts['En Preparación'] ?></span>)
                </a>

                <a href="<?= BASE_URL ?>/modules/cocina/?estado=Listo" 
                   class="btn btn-sm <?= $filtroEstado === 'Listo' ? 'btn-success fw-bold' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-check2-circle me-1"></i> Listos para Servir (<span id="count-listos"><?= $counts['Listo'] ?></span>)
                </a>
            </div>
        </div>

        <!-- Contenedor KDS de Tarjetas de Comandas -->
        <div id="kds-container" class="kds-grid" data-filter="<?= htmlspecialchars($filtroEstado) ?>">
            <?php if (empty($pedidosIniciales)): ?>
                <div class="col-12 text-center py-5 bg-white rounded-3 border w-100" id="kds-empty-state" style="grid-column: 1 / -1;">
                    <i class="bi bi-check2-circle text-success fs-1 d-block mb-2"></i>
                    <h4 class="fw-bold text-dark mb-1">¡Cocina al día!</h4>
                    <p class="text-muted mb-0">No hay comandas pendientes de preparación en este momento.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pedidosIniciales as $pedido): ?>
                    <?php
                    $pId = $pedido['id'];
                    $st = $pedido['estado'];
                    $stClass = ($st === 'Pendiente') ? 'state-pendiente' : (($st === 'En Preparación') ? 'state-en-preparacion' : 'state-listo');
                    $isUrgent = ($pedido['minutos_transcurridos'] >= 20 && $st !== 'Listo');
                    ?>
                    <div class="kds-card <?= $stClass ?>" id="order-card-<?= $pId ?>" data-order-id="<?= $pId ?>">
                        <!-- Cabecera de Comanda -->
                        <div class="kds-card-header">
                            <div>
                                <div class="kds-table-badge">
                                    <i class="bi bi-table text-primary"></i> Mesa #<?= htmlspecialchars($pedido['mesa_numero']) ?>
                                </div>
                                <small class="text-muted d-block" style="font-size: 0.78rem;">
                                    <?= htmlspecialchars($pedido['mesa_ubicacion']) ?> &bull; <span class="font-monospace">#<?= $pedido['short_id'] ?></span>
                                </small>
                            </div>
                            <div class="text-end">
                                <span class="kds-timer-badge <?= $isUrgent ? 'kds-timer-urgent' : '' ?>">
                                    <i class="bi bi-stopwatch me-1"></i><?= $pedido['hora_creacion'] ?> (<?= $pedido['minutos_transcurridos'] ?>m)
                                </span>
                            </div>
                        </div>

                        <!-- Lista de Platillos -->
                        <div class="kds-card-body">
                            <div class="kds-items-list mb-auto">
                                <?php foreach ($pedido['detalles'] as $item): ?>
                                    <div class="kds-item-row">
                                        <span class="kds-qty-badge"><?= $item['cantidad'] ?>x</span>
                                        <div class="flex-grow-1">
                                            <div class="kds-item-title"><?= htmlspecialchars($item['producto_nombre']) ?></div>
                                            <?php if (!empty($item['notas'])): ?>
                                                <div class="kds-item-note">
                                                    <i class="bi bi-pencil-fill me-1"></i><?= htmlspecialchars($item['notas']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (!empty($pedido['observaciones'])): ?>
                                <div class="kds-obs-banner">
                                    <i class="bi bi-info-circle-fill me-1 text-primary"></i>
                                    <strong>Obs:</strong> <?= htmlspecialchars($pedido['observaciones']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Botón de Transición Rápida KDS -->
                        <div class="kds-card-footer">
                            <?php if ($st === 'Pendiente'): ?>
                                <button type="button" 
                                        class="kds-btn-action kds-btn-start btn-status-trigger" 
                                        data-id="<?= $pId ?>" 
                                        data-status="En Preparación"
                                        data-url="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=<?= $pId ?>&status=En+Preparaci%C3%B3n&return_to=cocina">
                                    <i class="bi bi-fire"></i> Iniciar Preparación
                                </button>
                            <?php elseif ($st === 'En Preparación'): ?>
                                <button type="button" 
                                        class="kds-btn-action kds-btn-ready btn-status-trigger" 
                                        data-id="<?= $pId ?>" 
                                        data-status="Listo"
                                        data-url="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=<?= $pId ?>&status=Listo&return_to=cocina">
                                    <i class="bi bi-check2-circle"></i> Marcar como Listo
                                </button>
                            <?php else: ?>
                                <div class="kds-btn-action kds-btn-done">
                                    <i class="bi bi-check-all me-1"></i> Comanda Lista para Servir
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>

<!-- Script de Polling y Gestión KDS en Tiempo Real -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const kdsContainer = document.getElementById('kds-container');
    const filterEstado = kdsContainer.getAttribute('data-filter') || 'activas';
    const clockElement = document.getElementById('update-clock');
    const refreshBtn = document.getElementById('btn-manual-refresh');
    const refreshIcon = document.getElementById('refresh-icon');

    const countActivas = document.getElementById('count-activas');
    const countPendientes = document.getElementById('count-pendientes');
    const countEnPrep = document.getElementById('count-en-preparacion');
    const countListos = document.getElementById('count-listos');

    let isPolling = false;
    const POLL_INTERVAL_MS = 10000; // 10 segundos

    // Función para renderizar una tarjeta de comanda
    function createOrderCardHTML(pedido) {
        const st = pedido.estado;
        const stClass = (st === 'Pendiente') ? 'state-pendiente' : ((st === 'En Preparación') ? 'state-en-preparacion' : 'state-listo');
        const isUrgent = (pedido.minutos_transcurridos >= 20 && st !== 'Listo');

        let itemsHtml = '';
        if (Array.isArray(pedido.detalles)) {
            pedido.detalles.forEach(item => {
                const noteHtml = item.notas ? `<div class="kds-item-note"><i class="bi bi-pencil-fill me-1"></i>${escapeHtml(item.notas)}</div>` : '';
                itemsHtml += `
                    <div class="kds-item-row">
                        <span class="kds-qty-badge">${item.cantidad}x</span>
                        <div class="flex-grow-1">
                            <div class="kds-item-title">${escapeHtml(item.producto_nombre)}</div>
                            ${noteHtml}
                        </div>
                    </div>
                `;
            });
        }

        const obsHtml = pedido.observaciones ? `
            <div class="kds-obs-banner">
                <i class="bi bi-info-circle-fill me-1 text-primary"></i>
                <strong>Obs:</strong> ${escapeHtml(pedido.observaciones)}
            </div>
        ` : '';

        let actionBtnHtml = '';
        if (st === 'Pendiente') {
            actionBtnHtml = `
                <button type="button" 
                        class="kds-btn-action kds-btn-start btn-status-trigger" 
                        data-id="${pedido.id}" 
                        data-status="En Preparación"
                        data-url="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=${pedido.id}&status=En+Preparaci%C3%B3n&return_to=cocina">
                    <i class="bi bi-fire"></i> Iniciar Preparación
                </button>
            `;
        } else if (st === 'En Preparación') {
            actionBtnHtml = `
                <button type="button" 
                        class="kds-btn-action kds-btn-ready btn-status-trigger" 
                        data-id="${pedido.id}" 
                        data-status="Listo"
                        data-url="<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=${pedido.id}&status=Listo&return_to=cocina">
                    <i class="bi bi-check2-circle"></i> Marcar como Listo
                </button>
            `;
        } else {
            actionBtnHtml = `
                <div class="kds-btn-action kds-btn-done">
                    <i class="bi bi-check-all me-1"></i> Comanda Lista para Servir
                </div>
            `;
        }

        return `
            <div class="kds-card ${stClass}" id="order-card-${pedido.id}" data-order-id="${pedido.id}">
                <div class="kds-card-header">
                    <div>
                        <div class="kds-table-badge">
                            <i class="bi bi-table text-primary"></i> Mesa #${escapeHtml(pedido.mesa_numero)}
                        </div>
                        <small class="text-muted d-block" style="font-size: 0.78rem;">
                            ${escapeHtml(pedido.mesa_ubicacion)} &bull; <span class="font-monospace">#${pedido.short_id}</span>
                        </small>
                    </div>
                    <div class="text-end">
                        <span class="kds-timer-badge ${isUrgent ? 'kds-timer-urgent' : ''}">
                            <i class="bi bi-stopwatch me-1"></i>${pedido.hora_creacion} (${pedido.minutos_transcurridos}m)
                        </span>
                    </div>
                </div>

                <div class="kds-card-body">
                    <div class="kds-items-list mb-auto">
                        ${itemsHtml}
                    </div>
                    ${obsHtml}
                </div>

                <div class="kds-card-footer">
                    ${actionBtnHtml}
                </div>
            </div>
        `;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Función de sondeo AJAX
    async function fetchKitchenOrders() {
        if (isPolling) return;
        isPolling = true;

        if (refreshIcon) refreshIcon.classList.add('spin-animation');

        try {
            const url = `<?= BASE_URL ?>/modules/cocina/get_orders_ajax.php?estado=${encodeURIComponent(filterEstado)}&t=${Date.now()}`;
            const res = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!res.ok) throw new Error('Error HTTP ' + res.status);
            const data = await res.json();

            if (data && data.success) {
                if (clockElement) clockElement.textContent = data.timestamp || new Date().toLocaleTimeString();

                // Actualizar contadores
                if (data.counts) {
                    const pend = data.counts['Pendiente'] || 0;
                    const prep = data.counts['En Preparación'] || 0;
                    const listos = data.counts['Listo'] || 0;

                    if (countActivas) countActivas.textContent = pend + prep;
                    if (countPendientes) countPendientes.textContent = pend;
                    if (countEnPrep) countEnPrep.textContent = prep;
                    if (countListos) countListos.textContent = listos;
                }

                // Renderizar pedidos evitando duplicados
                const pedidos = data.data || [];
                if (pedidos.length === 0) {
                    kdsContainer.innerHTML = `
                        <div class="col-12 text-center py-5 bg-white rounded-3 border w-100" id="kds-empty-state" style="grid-column: 1 / -1;">
                            <i class="bi bi-check2-circle text-success fs-1 d-block mb-2"></i>
                            <h4 class="fw-bold text-dark mb-1">¡Cocina al día!</h4>
                            <p class="text-muted mb-0">No hay comandas pendientes de preparación en este momento.</p>
                        </div>
                    `;
                } else {
                    let fullHtml = '';
                    pedidos.forEach(p => {
                        fullHtml += createOrderCardHTML(p);
                    });
                    kdsContainer.innerHTML = fullHtml;
                }

                // Re-vincular eventos a los nuevos botones
                bindActionButtons();
            }
        } catch (err) {
            console.error('Error al actualizar pedidos de cocina:', err);
        } finally {
            isPolling = false;
            if (refreshIcon) refreshIcon.classList.remove('spin-animation');
        }
    }

    // Vincular clic a los botones de transición de estado
    function bindActionButtons() {
        const buttons = document.querySelectorAll('.btn-status-trigger');
        buttons.forEach(btn => {
            btn.onclick = async (e) => {
                e.preventDefault();
                const orderId = btn.getAttribute('data-id');
                const newStatus = btn.getAttribute('data-status');
                const fallbackUrl = btn.getAttribute('data-url');

                btn.disabled = true;
                btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status"></span> Actualizando...`;

                try {
                    const apiUrl = `<?= BASE_URL ?>/modules/pedidos/edit_status.php?id=${encodeURIComponent(orderId)}&status=${encodeURIComponent(newStatus)}&format=json&return_to=cocina`;
                    const res = await fetch(apiUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const resData = await res.json();

                    if (resData && resData.success) {
                        // Refrescar inmediatamente
                        await fetchKitchenOrders();
                    } else {
                        // Fallback con redirección normal
                        window.location.href = fallbackUrl;
                    }
                } catch (err) {
                    // Fallback directo en caso de error de red
                    window.location.href = fallbackUrl;
                }
            };
        });
    }

    // Inicializar eventos y timer de sondeo
    bindActionButtons();

    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            fetchKitchenOrders();
        });
    }

    // Intervalo de sondeo regular de 10 segundos
    setInterval(fetchKitchenOrders, POLL_INTERVAL_MS);
});
</script>
