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

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/auth/get_challenge.php';
require_once __DIR__ . '/auth/encrypt_token.php';
require_once __DIR__ . '/auth/get_auth_token.php';
require_once __DIR__ . '/auth/get_access_token.php';
require_once __DIR__ . '/auth/refresh_access_token.php';
require_once __DIR__ . '/export/generate_encryption.php';
require_once __DIR__ . '/export/send_export_request.php';
require_once __DIR__ . '/export/get_export_status.php';
require_once __DIR__ . '/export/download_aes.php';
require_once __DIR__ . '/error/classify_error.php';    
require_once __DIR__ . '/error/error_response.php';


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
    echo json_encode(errorResponse($e->getMessage()));
}
