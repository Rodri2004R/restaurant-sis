<?php
if (!ob_get_level()) {
    ob_start();
}
/**
 * Restaurant Smart - Configuración Global
 * INF401 - Taller II - UAJMS
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Zona Horaria
date_default_timezone_set('America/La_Paz');

/**
 * Función simple para cargar archivo .env
 */
function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2) + [NULL, NULL];
        if ($name !== NULL && $value !== NULL) {
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Cargar .env si existe
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    loadEnv($envFile);
}

// Constantes Generales
define('APP_NAME', getenv('APP_NAME') ?: 'Restaurant Smart');
define('BASE_URL', getenv('APP_URL') ?: 'http://localhost:8000');

// Credenciales Supabase
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: ($_ENV['SUPABASE_URL'] ?? ($_SERVER['SUPABASE_URL'] ?? '')));
define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: ($_ENV['SUPABASE_ANON_KEY'] ?? ($_SERVER['SUPABASE_ANON_KEY'] ?? '')));

/**
 * Helper para redirigir
 */
function redirect($path) {
    header("Location: " . BASE_URL . '/' . ltrim($path, '/'));
    exit;
}

/**
 * Helper para mensajes flash (Alertas)
 */
function set_flash_message($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type, // success, danger, warning, info
        'message' => $message
    ];
}

function get_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $msg = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $msg;
    }
    return null;
}

/**
 * Sanitizar entradas
 */
function clean_input($data) {
    if (is_array($data)) {
        return array_map('clean_input', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

/**
 * ==============================================================================
 * HELPERS GLOBALES DE VALIDACIÓN (FASE 2.0)
 * ==============================================================================
 */

/**
 * Validar formato de correo electrónico
 *
 * @param mixed $email Cadena a validar
 * @return bool True si el correo es válido, False en caso contrario
 */
function is_valid_email($email): bool {
    if (!is_string($email) || trim($email) === '') {
        return false;
    }
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validar número de teléfono (solo dígitos numéricos, longitud 7 a 12)
 *
 * @param mixed $phone Cadena o número a validar
 * @return bool True si es un teléfono válido, False en caso contrario
 */
function is_valid_phone($phone): bool {
    if (!is_scalar($phone)) {
        return false;
    }
    $phoneStr = trim((string)$phone);
    return (bool)preg_match('/^[0-9]{7,12}$/', $phoneStr);
}

/**
 * Validar documento de identidad (CI / NIT)
 * Acepta caracteres alfanuméricos y guiones con longitud de 5 a 30 caracteres.
 *
 * @param mixed $document Cadena a validar
 * @return bool True si el formato del documento es válido, False en caso contrario
 */
function is_valid_document($document): bool {
    if (!is_scalar($document)) {
        return false;
    }
    $docStr = trim((string)$document);
    return (bool)preg_match('/^[a-zA-Z0-9-]{5,30}$/', $docStr);
}

/**
 * Validar identificador único universal (UUID v1-v5)
 *
 * @param mixed $uuid Cadena a validar
 * @return bool True si es un UUID válido, False en caso contrario
 */
function is_valid_uuid($uuid): bool {
    if (!is_string($uuid) || trim($uuid) === '') {
        return false;
    }
    return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', trim($uuid));
}

/**
 * Auto-cancelar reservas en estado 'Pendiente' que hayan superado el período de tolerancia (30 min)
 * o que pertenezcan a fechas pasadas no atendidas.
 *
 * @param mixed $supabase Instancia de SupabaseClient
 * @param string|null $authToken Token de autenticación
 * @return int Cantidad de reservas canceladas automáticamente
 */
function auto_expire_pending_reservas($supabase, ?string $authToken = null): int {
    if (!$supabase || !method_exists($supabase, 'isConfigured') || !$supabase->isConfigured()) {
        return 0;
    }

    $fechaHoy = date('Y-m-d');
    $ahoraTimestamp = time();
    $canceladas = 0;

    // Consultar reservas en estado Pendiente hasta la fecha de hoy
    $res = $supabase->select('reservas', 'id,fecha,hora,observaciones,mesa_id', [
        'estado' => 'eq.Pendiente',
        'fecha' => 'lte.' . $fechaHoy
    ], $authToken);

    if ($res['success'] && !empty($res['data'])) {
        foreach ($res['data'] as $rv) {
            $rvFecha = $rv['fecha'];
            $rvHora = !empty($rv['hora']) ? date('H:i:s', strtotime($rv['hora'])) : '00:00:00';
            $rvTimestamp = strtotime($rvFecha . ' ' . $rvHora);
            
            // Tolerancia estricta de 30 minutos (1800 segundos)
            $tiempoLimite = $rvTimestamp + (30 * 60);

            // Si la fecha es anterior a hoy O si es hoy pero ya pasaron más de 30 min desde la hora programada
            if ($rvTimestamp < $ahoraTimestamp && $ahoraTimestamp > $tiempoLimite) {
                $obsOriginal = $rv['observaciones'] ?? '';
                $motivoCancelacion = '[Cancelada automáticamente por superar los 30 min de tolerancia sin confirmación (' . date('d/m/Y H:i', $tiempoLimite) . ')]';
                
                $obsFinal = !empty($obsOriginal) ? (trim($obsOriginal) . ' ' . $motivoCancelacion) : $motivoCancelacion;
                
                $upd = $supabase->update('reservas', [
                    'estado' => 'Cancelada',
                    'observaciones' => $obsFinal
                ], 'id=eq.' . $rv['id'], $authToken);

                if ($upd['success']) {
                    $canceladas++;

                    // Liberación condicional y segura de la mesa asociada
                    $mesaId = $rv['mesa_id'] ?? null;
                    if (!empty($mesaId)) {
                        $resActOrders = $supabase->select('pedidos', 'id', [
                            'mesa_id' => 'eq.' . $mesaId,
                            'estado' => 'in.(Pendiente,En Preparación,Listo,Servido)'
                        ], $authToken);
                        $hasActOrders = ($resActOrders['success'] && !empty($resActOrders['data']));

                        $resActRes = $supabase->select('reservas', 'id', [
                            'mesa_id' => 'eq.' . $mesaId,
                            'fecha' => 'eq.' . $fechaHoy,
                            'estado' => 'in.(Pendiente,Confirmada)'
                        ], $authToken);
                        $hasActRes = ($resActRes['success'] && !empty($resActRes['data']));

                        if (!$hasActOrders && !$hasActRes) {
                            $resM = $supabase->select('mesas', 'id,estado', ['id' => 'eq.' . $mesaId], $authToken);
                            if ($resM['success'] && !empty($resM['data'])) {
                                $stM = $resM['data'][0]['estado'] ?? 'disponible';
                                if ($stM === 'reservada') {
                                    $supabase->update('mesas', ['estado' => 'disponible'], 'id=eq.' . $mesaId, $authToken);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    return $canceladas;
}


