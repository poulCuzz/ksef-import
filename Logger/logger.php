<?php
/**
 * LOGGER.PHP - Logowanie odpowiedzi API KSeF do pliku JSON
 * 
 * Każdy wpis zawiera:
 * - timestamp
 * - krok (nazwa operacji)
 * - request (wysłane dane)
 * - response (odpowiedź API)
 * - status (success/error)
 * - http_code
 * 
 * UWAGA: Wrażliwe dane (tokeny, klucze) są maskowane dla bezpieczeństwa
 */

define('LOG_FILE', __DIR__ . '/logs/ksef_api_log.json');

/**
 * Maskuje wrażliwe dane - pokazuje tylko pierwsze i ostatnie znaki
 */
function maskSensitiveData(string $value, int $visibleStart = 10, int $visibleEnd = 4): string
{
    $length = strlen($value);
    
    if ($length <= $visibleStart + $visibleEnd + 3) {
        // Za krótkie do maskowania - pokaż tylko początek
        return substr($value, 0, $visibleStart) . '***';
    }
    
    return substr($value, 0, $visibleStart) . '***' . substr($value, -$visibleEnd);
}

/**
 * Rekurencyjnie maskuje wrażliwe pola w tablicy
 */
function maskArray(mixed $data): mixed
{
    if (!is_array($data)) {
        return $data;
    }
    
    $sensitiveKeys = [
        'token', 
        'accessToken', 
        'refreshToken', 
        'authenticationToken',
        'encryptedToken',
        'encryptedSymmetricKey',
        'rawSymmetricKey',
        'rawIV',
        'initializationVector'
    ];
    
    foreach ($data as $key => $value) {
        // Sprawdź czy klucz jest wrażliwy
        if (in_array($key, $sensitiveKeys) && is_string($value)) {
            $data[$key] = maskSensitiveData($value);
        }
        // Sprawdź zagnieżdżone tablice
        elseif (is_array($value)) {
            $data[$key] = maskArray($value);
        }
    }
    
    return $data;
}

/**
 * Inicjalizuje plik logów (tworzy katalog i plik jeśli nie istnieją)
 */
function initLogger(): void
{
    $logDir = dirname(LOG_FILE);
    
    if (!file_exists($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    if (!file_exists(LOG_FILE)) {
        file_put_contents(LOG_FILE, json_encode([], JSON_PRETTY_PRINT));
    }
}

/**
 * Dodaje wpis do logu
 */
function addLogEntry(string $step, mixed $request, mixed $response, string $status = 'success', int $httpCode = 200): void
{
    initLogger();
    
    $logs = json_decode(file_get_contents(LOG_FILE), true) ?? [];
    
    // Maskuj wrażliwe dane
    $maskedRequest = maskArray($request);
    $maskedResponse = maskArray($response);
    
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'step' => $step,
        'status' => $status,
        'http_code' => $httpCode,
        'request' => $maskedRequest,
        'response' => $maskedResponse
    ];
    
    $logs[] = $entry;
    
    file_put_contents(LOG_FILE, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Rozpoczyna nową sesję logowania (dodaje separator)
 */
function logSessionStart(string $env, string $nip): void
{
    addLogEntry('SESSION_START', [
        'environment' => $env,
        'nip' => maskSensitiveData($nip, 3, 2),
        'session_id' => uniqid('sess_')
    ], null, 'info', 0);
}

/**
 * Loguje pobranie challenge
 */
function logChallenge(string $url, array $requestPayload, mixed $response, int $httpCode): void
{
    $status = isset($response['challenge']) ? 'success' : 'error';
    
    addLogEntry('GET_CHALLENGE', [
        'url' => $url,
        'payload' => maskArray($requestPayload)
    ], $response, $status, $httpCode);
}

/**
 * Loguje szyfrowanie tokena
 */
function logTokenEncryption(string $token, int $timestamp, bool $success): void
{
    addLogEntry('ENCRYPT_TOKEN', [
        'token' => maskSensitiveData($token, 15, 6),
        'timestamp' => $timestamp
    ], [
        'encrypted' => $success
    ], $success ? 'success' : 'error', $success ? 200 : 500);
}

/**
 * Loguje uzyskanie authenticationToken
 */
function logAuthenticationToken(string $url, array $requestPayload, mixed $response, int $httpCode): void
{
    $status = isset($response['authenticationToken']['token']) ? 'success' : 'error';
    
    addLogEntry('GET_AUTH_TOKEN', [
        'url' => $url,
        'payload' => maskArray($requestPayload)
    ], $response, $status, $httpCode);
}

/**
 * Loguje uzyskanie accessToken
 */
function logAccessToken(string $url, mixed $response, int $httpCode): void
{
    $status = isset($response['accessToken']['token']) ? 'success' : 'error';
    
    addLogEntry('GET_ACCESS_TOKEN', [
        'url' => $url
    ], $response, $status, $httpCode);
}

/**
 * Loguje odświeżenie accessToken
 */
function logRefreshToken(string $url, mixed $response, int $httpCode): void
{
    $status = isset($response['accessToken']['token']) ? 'success' : 'error';
    
    addLogEntry('REFRESH_ACCESS_TOKEN', [
        'url' => $url
    ], $response, $status, $httpCode);
}

/**
 * Loguje zlecenie eksportu
 */
function logExportRequest(string $url, array $filters, mixed $response, int $httpCode): void
{
    $status = isset($response['referenceNumber']) ? 'success' : 'error';
    
    addLogEntry('EXPORT_REQUEST', [
        'url' => $url,
        'filters' => $filters
    ], $response, $status, $httpCode);
}

/**
 * Loguje pobranie linków do plików .aes
 */
function logExportLinks(string $url, mixed $response, int $httpCode): void
{
    $status = ($httpCode === 200) ? 'success' : 'error';
    
    addLogEntry('GET_EXPORT_LINKS', [
        'url' => $url
    ], $response, $status, $httpCode);
}

/**
 * Loguje pobranie pliku .aes
 */
function logAesDownload(string $url, int $fileSize, int $httpCode): void
{
    $status = ($httpCode === 200 && $fileSize > 0) ? 'success' : 'error';
    
    addLogEntry('DOWNLOAD_AES', [
        'url' => $url
    ], [
        'file_size_bytes' => $fileSize
    ], $status, $httpCode);
}

/**
 * Loguje deszyfrowanie
 */
function logDecryption(int $inputSize, int $outputSize, bool $success): void
{
    addLogEntry('DECRYPT_AES', [
        'input_size_bytes' => $inputSize
    ], [
        'output_size_bytes' => $outputSize,
        'decrypted' => $success
    ], $success ? 'success' : 'error', $success ? 200 : 500);
}

/**
 * Loguje zakończenie sesji
 */
function logSessionEnd(int $filesCount, bool $success): void
{
    addLogEntry('SESSION_END', [
        'files_downloaded' => $filesCount
    ], [
        'completed' => $success
    ], $success ? 'success' : 'error', $success ? 200 : 500);
}

/**
 * Loguje błąd
 */
function logError(string $step, string $errorMessage, mixed $context = null): void
{
    addLogEntry($step, $context, [
        'error' => $errorMessage
    ], 'error', 500);
}

/**
 * Pobiera wszystkie logi
 */
function getLogs(): array
{
    initLogger();
    return json_decode(file_get_contents(LOG_FILE), true) ?? [];
}

/**
 * Czyści logi
 */
function clearLogs(): void
{
    file_put_contents(LOG_FILE, json_encode([], JSON_PRETTY_PRINT));
}

/**
 * Pobiera logi z ostatniej sesji
 */
function getLastSessionLogs(): array
{
    $logs = getLogs();
    $lastSessionLogs = [];
    
    // Znajdź ostatni SESSION_START i pobierz wszystko od niego
    for ($i = count($logs) - 1; $i >= 0; $i--) {
        array_unshift($lastSessionLogs, $logs[$i]);
        if ($logs[$i]['step'] === 'SESSION_START') {
            break;
        }
    }
    
    return $lastSessionLogs;
}