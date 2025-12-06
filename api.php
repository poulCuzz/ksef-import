<?php
/**
 * API.PHP - Backend do obsługi AJAX dla eksportu faktur KSeF
 * 
 * Endpointy:
 * - POST ?action=start_export - rozpoczyna eksport, zwraca referenceNumber
 * - GET ?action=check_status&ref=XXX - sprawdza status eksportu
 */

set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/logger.php';

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

// ============================================================================
// FUNKCJE API KSeF
// ============================================================================

function getChallengeWithUrl(string $baseUrl, string $nip): array
{
    $challengeUrl = $baseUrl . "/api/v2/auth/challenge";
    $challengePayload = json_encode([
        "contextIdentifier" => [
            "type" => "Nip",
            "value" => $nip
        ]
    ]);
    
    $options = [
        "http" => [
            "header"  => "Content-Type: application/json\r\n",
            "method"  => "POST",
            "content" => $challengePayload,
            "ignore_errors" => true
        ]
    ];
    
    $context = stream_context_create($options);
    $challengeResponse = file_get_contents($challengeUrl, false, $context);
    
    if ($challengeResponse === FALSE) {
        throw new Exception("Błąd pobierania challenge");
    }
    
    $challengeData = json_decode($challengeResponse, true);
    if (!isset($challengeData['challenge'])) {
        throw new Exception("Brak challenge w odpowiedzi: " . json_encode($challengeData));
    }
    
    $challenge = $challengeData['challenge'];
    $timestampString = $challengeData['timestamp'];
    
    $datetime = new DateTime($timestampString);
    $secondsWithMicro = (float) $datetime->format('U.u');
    $timestampMillis = (int) floor($secondsWithMicro * 1000);
    
    return [$challenge, $timestampMillis];
}

function encryptTokenData(string $token, string $publicKeyPath, int $timestamp): string
{
    $certContent = file_get_contents($publicKeyPath);
    
    if ($certContent === false) {
        throw new Exception("Nie można wczytać certyfikatu");
    }
    
    $x509 = new X509();
    $x509->loadX509($certContent);
    $publicKey = $x509->getPublicKey();
    
    if (!$publicKey) {
        throw new Exception("Nie można wyekstraktować klucza publicznego");
    }
    
    $data = "{$token}|{$timestamp}";
    
    $encrypted = $publicKey
        ->withPadding(RSA::ENCRYPTION_OAEP)
        ->withHash('sha256')
        ->withMGFHash('sha256')
        ->encrypt($data);
    
    if ($encrypted === false) {
        throw new Exception('Błąd szyfrowania tokena');
    }
    
    return base64_encode($encrypted);
}

function getAuthTokenWithUrl(string $baseUrl, string $challenge, string $encryptedToken, string $nip): string
{
    $authData = [
        "challenge" => $challenge,
        "contextIdentifier" => [
            "type" => "Nip",
            "value" => $nip 
        ],
        "encryptedToken" => $encryptedToken 
    ];
    
    $postData = json_encode($authData);
    $authUrl = $baseUrl . "/api/v2/auth/ksef-token";
    
    $options = [
        "http" => [
            "header"  => "Content-Type: application/json\r\n",
            "method"  => "POST",
            "content" => $postData,
            "ignore_errors" => true 
        ]
    ];
    
    $context = stream_context_create($options);
    $response = file_get_contents($authUrl, false, $context);
    
    if ($response === FALSE) {
        throw new Exception("Błąd uwierzytelnienia");
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['authenticationToken']['token'])) {
        throw new Exception("Nie znaleziono tokenu w odpowiedzi: " . json_encode($result));
    }
    
    return $result['authenticationToken']['token'];
}

function getAccessTokenWithUrl(string $baseUrl, string $authenticationToken): array
{
    $url = $baseUrl . "/api/v2/auth/token/redeem";

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $authenticationToken
    ];

    $payload = json_encode(new stdClass());

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        throw new Exception("Błąd pobierania accessToken: HTTP $httpCode");
    }

    $data = json_decode($response, true);

    if (!isset($data['accessToken']['token'])) {
        throw new Exception("Brak accessToken w odpowiedzi");
    }

    return $data;
}

function refreshAccessTokenWithUrl(string $baseUrl, string $refreshToken): string
{
    $url = $baseUrl . "/api/v2/auth/token/refresh";

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $refreshToken
    ];

    $payload = json_encode(new stdClass());

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['accessToken']['token'])) {
        throw new Exception("Błąd odświeżania accessToken: HTTP $httpCode");
    }

    return $data['accessToken']['token'];
}

function generateEncryptionDataForExport(string $publicKeyPath): array
{
    $symmetricKey = random_bytes(32);
    $iv = random_bytes(16);

    $certContent = file_get_contents($publicKeyPath);
    if ($certContent === false) {
        throw new Exception("Nie można wczytać certyfikatu MF");
    }

    $x509 = new X509();
    $x509->loadX509($certContent);
    $publicKey = $x509->getPublicKey();

    if (!$publicKey) {
        throw new Exception("Nie można wyciągnąć klucza publicznego z certyfikatu MF");
    }

    $encryptedKey = $publicKey
        ->withPadding(RSA::ENCRYPTION_OAEP)
        ->withHash('sha256')
        ->withMGFHash('sha256')
        ->encrypt($symmetricKey);

    if ($encryptedKey === false) {
        throw new Exception("Błąd szyfrowania klucza AES");
    }

    // Zapisz klucze do pliku
    $filePath = __DIR__ . '/export/last_export_encryption.json';
    $payload = [
        'generatedAt'     => date('c'),
        'rawSymmetricKey' => base64_encode($symmetricKey),
        'rawIV'           => base64_encode($iv),
    ];
    file_put_contents($filePath, json_encode($payload, JSON_PRETTY_PRINT));

    return [
        "encryptedSymmetricKey" => base64_encode($encryptedKey),
        "initializationVector"  => base64_encode($iv),
        "rawSymmetricKey" => base64_encode($symmetricKey),
        "rawIV"           => base64_encode($iv)
    ];
}

function sendExportRequestWithUrl(string $baseUrl, string $accessToken, string $subjectType, string $dateFrom, string $dateTo): ?array
{
    $publicKeyPath = __DIR__ . '/export/public_key_symetric_encription.pem';
    $encryptionData = generateEncryptionDataForExport($publicKeyPath);

    $filters = [
        "subjectType" => $subjectType,
        "dateRange" => [
            "dateType" => "Issue",
            "from" => $dateFrom,
            "to" => $dateTo
        ]
    ];

    $payload = json_encode([
        "encryption" => [
            "encryptedSymmetricKey" => $encryptionData['encryptedSymmetricKey'],
            "initializationVector" => $encryptionData['initializationVector']
        ],
        "filters" => $filters
    ]);

    $url = $baseUrl . "/api/v2/invoices/exports";

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer $accessToken"
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new Exception("Błąd eksportu: HTTP $httpCode - $response");
    }

    return json_decode($response, true);
}

function getExportStatus(string $baseUrl, string $accessToken, string $referenceNumber): array
{
    $url = $baseUrl . '/api/v2/invoices/exports/' . urlencode($referenceNumber);

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FAILONERROR    => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("Błąd cURL");
    }

    $data = json_decode($response, true);

    logExportLinks($url, $data, $httpCode);

    if ($httpCode !== 200) {
        $msg = $data['exception']['exceptionDescription'] ?? "Błąd HTTP $httpCode";
        throw new RuntimeException("KSeF error: $msg");
    }

    return $data;
}

function downloadAesFileSimple(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("Błąd cURL: $error");
    }

    if ($httpCode !== 200) {
        throw new RuntimeException("Błąd HTTP $httpCode podczas pobierania pliku AES");
    }

    return $response;
}

function decryptAes(string $ciphertext, string $key, string $iv): string
{
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

    if ($plaintext === false) {
        throw new RuntimeException("Błąd deszyfrowania AES");
    }

    return $plaintext;
}

// ============================================================================
// OBSŁUGA ŻĄDAŃ
// ============================================================================

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // ====== START EKSPORTU ======
        case 'start_export':
            $env = $_POST['env'] ?? 'demo';
            $ksefToken = trim($_POST['ksef_token'] ?? '');
            $nip = preg_replace('/[^0-9]/', '', $_POST['nip'] ?? '');
            $subjectType = $_POST['subject_type'] ?? 'Subject1';
            $dateFrom = $_POST['date_from'] ?? '';
            $dateTo = $_POST['date_to'] ?? '';

            // Walidacja
            if (empty($ksefToken)) throw new Exception("Token KSeF jest wymagany");
            if (strlen($nip) !== 10) throw new Exception("NIP musi mieć 10 cyfr");
            if (empty($dateFrom) || empty($dateTo)) throw new Exception("Daty są wymagane");

            $baseUrl = ($env === 'demo') 
                ? "https://ksef-demo.mf.gov.pl" 
                : "https://ksef-test.mf.gov.pl";

            logSessionStart($env, $nip);

            $dateFromFormatted = $dateFrom . "T00:00:00.000+00:00";
            $dateToFormatted = $dateTo . "T23:59:59.999+00:00";

            // Autoryzacja
            [$challenge, $timestamp] = getChallengeWithUrl($baseUrl, $nip);
            logChallenge($baseUrl . "/api/v2/auth/challenge", ["nip" => $nip], ["challenge" => $challenge, "timestamp" => $timestamp], 200);

            $publicKeyPath = __DIR__ . '/auth/public_key.pem';
            $encryptedToken = encryptTokenData($ksefToken, $publicKeyPath, $timestamp);
            logTokenEncryption($ksefToken, $timestamp, true);

            $authToken = getAuthTokenWithUrl($baseUrl, $challenge, $encryptedToken, $nip);
            logAuthenticationToken($baseUrl . "/api/v2/auth/ksef-token", ["challenge" => $challenge, "nip" => $nip], ["authenticationToken" => ["token" => $authToken]], 200);
            
            $accessData = getAccessTokenWithUrl($baseUrl, $authToken);
            $accessToken = $accessData['accessToken']['token'];
            $refreshToken = $accessData['refreshToken']['token'];
            logAccessToken($baseUrl . "/api/v2/auth/token/redeem", $accessData, 200);
            
            $accessToken = refreshAccessTokenWithUrl($baseUrl, $refreshToken);
            logRefreshToken($baseUrl . "/api/v2/auth/token/refresh", ["accessToken" => ["token" => $accessToken]], 200);

            // Eksport
            $exportResult = sendExportRequestWithUrl($baseUrl, $accessToken, $subjectType, $dateFromFormatted, $dateToFormatted);
            logExportRequest($baseUrl . "/api/v2/invoices/exports", ["subjectType" => $subjectType, "dateFrom" => $dateFromFormatted, "dateTo" => $dateToFormatted], $exportResult, 200);
            
            if (!isset($exportResult['referenceNumber'])) {
                throw new Exception("Brak referenceNumber w odpowiedzi eksportu");
            }
            
            $referenceNumber = $exportResult['referenceNumber'];

            // Zapisz sesję
            $sessionId = uniqid('ksef_', true);
            
            if (!is_dir(__DIR__ . '/temp')) {
                mkdir(__DIR__ . '/temp', 0755, true);
            }
            
            $encFile = __DIR__ . '/export/last_export_encryption.json';
            $encData = json_decode(file_get_contents($encFile), true);
            
            $sessionData = [
                'referenceNumber' => $referenceNumber,
                'accessToken' => $accessToken,
                'baseUrl' => $baseUrl,
                'rawSymmetricKey' => $encData['rawSymmetricKey'],
                'rawIV' => $encData['rawIV'],
                'created' => time(),
                'downloadLinks' => []
            ];
            
            file_put_contents(
                __DIR__ . "/temp/session_{$sessionId}.json",
                json_encode($sessionData)
            );

            echo json_encode([
                'success' => true,
                'sessionId' => $sessionId,
                'referenceNumber' => $referenceNumber,
                'message' => 'Eksport rozpoczęty. Sprawdzam status...'
            ]);
            break;

        // ====== SPRAWDŹ STATUS ======
        case 'check_status':
            $sessionId = $_GET['session'] ?? '';
            
            if (empty($sessionId)) {
                throw new Exception("Brak sessionId");
            }
            
            $sessionFile = __DIR__ . "/temp/session_{$sessionId}.json";
            
            if (!file_exists($sessionFile)) {
                throw new Exception("Sesja wygasła lub nie istnieje");
            }
            
            $sessionData = json_decode(file_get_contents($sessionFile), true);
            
            $statusData = getExportStatus(
                $sessionData['baseUrl'], 
                $sessionData['accessToken'], 
                $sessionData['referenceNumber']
            );
            
            $statusCode = $statusData['status']['code'] ?? 0;
            $statusDesc = $statusData['status']['description'] ?? 'Nieznany status';
            
            if ($statusCode === 200) {
                // Eksport gotowy
                $downloadLinks = [];
                if (!empty($statusData['package']['parts'])) {
                    $downloadLinks = array_map(fn($p) => $p['url'], $statusData['package']['parts']);
                }
                
                // Zapisz linki do sesji
                $sessionData['downloadLinks'] = $downloadLinks;
                file_put_contents($sessionFile, json_encode($sessionData));
                
                logSessionEnd(count($downloadLinks), true);
                
                echo json_encode([
                    'success' => true,
                    'ready' => true,
                    'statusCode' => $statusCode,
                    'statusDesc' => $statusDesc,
                    'filesCount' => count($downloadLinks),
                    'message' => 'Eksport gotowy! Znaleziono ' . count($downloadLinks) . ' plik(ów).'
                ]);
            } else {
                // Jeszcze w trakcie
                echo json_encode([
                    'success' => true,
                    'ready' => false,
                    'statusCode' => $statusCode,
                    'statusDesc' => $statusDesc,
                    'message' => $statusDesc
                ]);
            }
            break;

        // ====== POBIERZ PLIK ======
        case 'download':
            $sessionId = $_GET['session'] ?? '';
            $partIndex = (int)($_GET['part'] ?? 0);
            
            if (empty($sessionId)) {
                throw new Exception("Brak sessionId");
            }
            
            $sessionFile = __DIR__ . "/temp/session_{$sessionId}.json";
            
            if (!file_exists($sessionFile)) {
                throw new Exception("Sesja wygasła lub nie istnieje");
            }
            
            $sessionData = json_decode(file_get_contents($sessionFile), true);
            
            if (!isset($sessionData['downloadLinks'][$partIndex])) {
                throw new Exception("Nieprawidłowy indeks pliku");
            }
            
            $url = $sessionData['downloadLinks'][$partIndex];
            $key = base64_decode($sessionData['rawSymmetricKey']);
            $iv = base64_decode($sessionData['rawIV']);
            
            // Pobierz i odszyfruj
            $aesData = downloadAesFileSimple($url);
            logAesDownload($url, strlen($aesData), 200);
            
            $zipData = decryptAes($aesData, $key, $iv);
            logDecryption(strlen($aesData), strlen($zipData), true);
            
            // Wyślij do przeglądarki
            $filename = "faktury_ksef_part" . ($partIndex + 1) . "_" . date('Y-m-d_H-i-s') . ".zip";
            
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($zipData));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            
            echo $zipData;
            exit;

        default:
            throw new Exception("Nieznana akcja: $action");
    }
    
} catch (Exception $e) {
    logError('API_ERROR', $e->getMessage(), ['action' => $action]);
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
