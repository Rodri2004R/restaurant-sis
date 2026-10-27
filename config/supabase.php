<?php
/**
 * Restaurant Smart - Cliente Supabase PHP
 * Interfaz cURL para Supabase Auth y PostgREST API
 */

require_once __DIR__ . '/config.php';

class SupabaseClient {
    private string $url;
    private string $apiKey;

    public function __construct() {
        $this->url = rtrim(SUPABASE_URL, '/');
        $this->apiKey = SUPABASE_ANON_KEY;
    }

    /**
     * Verificar si Supabase está configurado
     */
    public function isConfigured(): bool {
        return !empty($this->url) && !empty($this->apiKey) && $this->url !== 'https://tu-proyecto.supabase.co';
    }

    /**
     * Ejecutar petición cURL a Supabase
     */
    private function request(string $endpoint, string $method = 'GET', array $data = [], array $extraHeaders = [], ?string $authToken = null) {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Supabase no está configurado. Por favor configura las variables SUPABASE_URL y SUPABASE_ANON_KEY en tu archivo .env'
            ];
        }

        $fullUrl = $this->url . $endpoint;
        $ch = curl_init();

        $bearer = $authToken ?: $this->apiKey;

        $headers = array_merge([
            'apikey: ' . $this->apiKey,
            'Authorization: Bearer ' . $bearer,
            'Content-Type: application/json',
            'Prefer: return=representation'
        ], $extraHeaders);

        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desarrollo local

        // Evitar que una solicitud a Supabase quede esperando indefinidamente
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (!empty($data) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'error' => 'Error de conexión: ' . $curlError
            ];
        }

        $resultData = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => $resultData,
                'status' => $httpCode
            ];
        }

        $errorMsg = 'Error en la solicitud (' . $httpCode . ')';
        if (is_array($resultData)) {
            if (isset($resultData['msg'])) $errorMsg = $resultData['msg'];
            elseif (isset($resultData['message'])) $errorMsg = $resultData['message'];
            elseif (isset($resultData['error_description'])) $errorMsg = $resultData['error_description'];
            elseif (isset($resultData['hint'])) $errorMsg = $resultData['message'] . ' - ' . $resultData['hint'];
        }

        return [
            'success' => false,
            'error' => $errorMsg,
            'status' => $httpCode,
            'raw' => $resultData
        ];
    }

    // ==================== AUTHENTICATION ====================

    /**
     * Iniciar sesión con Email y Contraseña (Supabase Auth)
     */
    public function signIn(string $email, string $password) {
        $data = [
            'email' => $email,
            'password' => $password
        ];
        return $this->request('/auth/v1/token?grant_type=password', 'POST', $data);
    }

    /**
     * Registrar nuevo usuario en Supabase Auth
     */
    public function signUp(string $email, string $password, array $userMetadata = []) {
        $data = [
            'email' => $email,
            'password' => $password,
            'data' => $userMetadata
        ];
        return $this->request('/auth/v1/signup', 'POST', $data);
    }

    /**
     * Obtener datos del usuario por Token de Acceso
     */
    public function getUser(string $accessToken) {
        return $this->request('/auth/v1/user', 'GET', [], [], $accessToken);
    }

    /**
     * Cerrar sesión en Supabase (Revocar Token)
     */
    public function signOut(string $accessToken) {
        return $this->request('/auth/v1/logout', 'POST', [], [], $accessToken);
    }

    // ==================== REST API (PostgREST) ====================

    /**
     * Consultar registros (SELECT)
     */
    public function select(string $table, string $query = '*', array $params = [], ?string $authToken = null) {
        $queryString = '?select=' . urlencode($query);
        foreach ($params as $key => $val) {
            $queryString .= '&' . urlencode($key) . '=' . urlencode($val);
        }
        return $this->request('/rest/v1/' . $table . $queryString, 'GET', [], [], $authToken);
    }

    /**
     * Insertar registro (INSERT)
     */
    public function insert(string $table, array $data, ?string $authToken = null) {
        return $this->request('/rest/v1/' . $table, 'POST', $data, [], $authToken);
    }

    /**
     * Insertar o actualizar registro (UPSERT)
     */
    public function upsert(string $table, array $data, ?string $authToken = null) {
        return $this->request('/rest/v1/' . $table, 'POST', $data, ['Prefer: return=representation,resolution=merge-duplicates'], $authToken);
    }

    /**
     * Actualizar registros (UPDATE / PATCH)
     */
    public function update(string $table, array $data, string $conditionQuery, ?string $authToken = null) {
        return $this->request('/rest/v1/' . $table . '?' . $conditionQuery, 'PATCH', $data, [], $authToken);
    }

    /**
     * Eliminar registros (DELETE)
     */
    public function delete(string $table, string $conditionQuery, ?string $authToken = null) {
        return $this->request('/rest/v1/' . $table . '?' . $conditionQuery, 'DELETE', [], [], $authToken);
    }

    /**
     * Ejecutar función RPC de PostgreSQL
     */
    public function rpc(string $functionName, array $params = [], ?string $authToken = null) {
        return $this->request('/rest/v1/rpc/' . $functionName, 'POST', $params, [], $authToken);
    }
}
