<?php

/**
 * API.PHP - Backend dla eksportu faktur z KSeF
 * 
 * Endpointy:
 *   POST ?action=start_export  - Rozpoczyna eksport
 *   GET  ?action=check_status  - Sprawdza status eksportu
 *   GET  ?action=download      - Pobiera plik ZIP
 */

require_once __DIR__ . '/vendor/autoload.php';

use KSeF\KsefService;
use KSeF\Auth\KsefAuthenticator;
use KSeF\Auth\TokenEncryptor;
use KSeF\Export\KsefExporter;
use KSeF\Export\FileDecryptor;
use KSeF\Http\KsefClient;
use KSeF\Logger\JsonLogger;

header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// INICJALIZACJA SERWISÓW
// ============================================================================

function createKsefService(string $baseUrl): KsefService {
    $logger = new JsonLogger(__DIR__ . '/logs');
    $client = new KsefClient($logger, $baseUrl);
    
    $authPublicKey = __DIR__ . '/src/Auth/public_key.pem';
    $exportPublicKey = __DIR__ . '/src/Export/public_key_symetric_encription.pem';
    
    $authenticator = new KsefAuthenticator($client, $logger, $authPublicKey);
    $encryptor = new TokenEncryptor($authPublicKey);
    $exporter = new KsefExporter($baseUrl, $exportPublicKey);
    $decryptor = new FileDecryptor();
    
    return new KsefService($authenticator, $encryptor, $exporter, $decryptor, $logger);
}

// ============================================================================
// OBSŁUGA SESJI
// ============================================================================

function saveSession(string $sessionId, array $data): void {
    $tempDir = __DIR__ . '/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    file_put_contents("$tempDir/session_$sessionId.json", json_encode($data));
}

function loadSession(string $sessionId): ?array {
    $file = __DIR__ . "/temp/session_$sessionId.json";
    if (!file_exists($file)) {
        return null;
    }
    return json_decode(file_get_contents($file), true);
}

function cleanOldSessions(): void {
    $tempDir = __DIR__ . '/temp';
    if (!is_dir($tempDir)) return;
    
    foreach (glob("$tempDir/session_*.json") as $file) {
        if (filemtime($file) < time() - 3600) {
            unlink($file);
        }
    }
}

// ============================================================================
// ODPOWIEDZI
// ============================================================================

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function errorResponse(string $message, string $errorType = 'error', int $code = 400): void {
    jsonResponse([
        'success' => false,
        'errorType' => $errorType,
        'message' => $message
    ], $code);
}

// ============================================================================
// ROUTING
// ============================================================================

cleanOldSessions();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // ====================================================================
        // START EXPORT
        // ====================================================================
        case 'start_export':
            $env = $_POST['env'] ?? 'demo';
            $ksefToken = trim($_POST['ksef_token'] ?? '');
            $nip = preg_replace('/[^0-9]/', '', $_POST['nip'] ?? '');
            $subjectType = $_POST['subject_type'] ?? 'Subject1';
            $dateFrom = $_POST['date_from'] ?? '';
            $dateTo = $_POST['date_to'] ?? '';
            
            // Walidacja
            if (empty($ksefToken)) {
                errorResponse('Token KSeF jest wymagany', 'user_error');
            }
            if (strlen($nip) !== 10) {
                errorResponse('NIP musi mieć 10 cyfr', 'user_error');
            }
            if (empty($dateFrom) || empty($dateTo)) {
                errorResponse('Daty są wymagane', 'user_error');
            }
            
            // Buduj URL
            $baseUrl = ($env === 'demo')
                ? 'https://ksef-demo.mf.gov.pl'
                : 'https://ksef-test.mf.gov.pl';
            
            // Formatuj daty
            $dateFromFormatted = $dateFrom . 'T00:00:00.000+00:00';
            $dateToFormatted = $dateTo . 'T23:59:59.999+00:00';
            
            // Utwórz serwis
            $ksef = createKsefService($baseUrl);
            
            // Autoryzacja
            $tokens = $ksef->authenticate($nip, $ksefToken);
            
            // Eksport
            $exportResult = $ksef->startExport(
                $tokens['accessToken'],
                $subjectType,
                $dateFromFormatted,
                $dateToFormatted
            );
            
            if (!isset($exportResult['referenceNumber'])) {
                errorResponse('Brak referenceNumber w odpowiedzi', 'server_error');
            }
            
            // Zapisz sesję
            $sessionId = uniqid('ksef_', true);
            
            // Pobierz klucze szyfrowania
            $encFile = __DIR__ . '/src/Export/last_export_encryption.json';
            $encData = json_decode(file_get_contents($encFile), true);
            
            saveSession($sessionId, [
                'referenceNumber' => $exportResult['referenceNumber'],
                'accessToken' => $tokens['accessToken'],
                'refreshToken' => $tokens['refreshToken'],
                'baseUrl' => $baseUrl,
                'rawSymmetricKey' => $encData['rawSymmetricKey'],
                'rawIV' => $encData['rawIV'],
                'created' => time()
            ]);
            
            jsonResponse([
                'success' => true,
                'sessionId' => $sessionId,
                'referenceNumber' => $exportResult['referenceNumber']
            ]);
            break;
        
        // ====================================================================
        // CHECK STATUS
        // ====================================================================
        case 'check_status':
            $sessionId = $_GET['session'] ?? '';
            
            if (empty($sessionId)) {
                errorResponse('Brak sessionId', 'user_error');
            }
            
            $session = loadSession($sessionId);
            if (!$session) {
                errorResponse('Sesja wygasła lub nie istnieje', 'user_error');
            }
            
            $ksef = createKsefService($session['baseUrl']);
            
            $status = $ksef->checkStatus(
                $session['accessToken'],
                $session['referenceNumber']
            );
            
            $statusCode = $status['status']['code'] ?? 0;
            $isReady = ($statusCode === 200);
            
            $downloadLinks = [];
            if ($isReady && !empty($status['package']['parts'])) {
                $downloadLinks = array_map(fn($p) => $p['url'], $status['package']['parts']);
                
                // Zaktualizuj sesję z linkami
                $session['downloadLinks'] = $downloadLinks;
                saveSession($sessionId, $session);
            }
            
            jsonResponse([
                'success' => true,
                'statusCode' => $statusCode,
                'statusDesc' => $isReady ? 'Eksport gotowy' : 'Przetwarzanie',
                'ready' => $isReady,
                'filesCount' => count($downloadLinks),
                'message' => $isReady ? 'Pliki gotowe do pobrania' : 'Oczekiwanie na zakończenie eksportu...'
            ]);
            break;
        
        // ====================================================================
        // DOWNLOAD
        // ====================================================================
        case 'download':
            $sessionId = $_GET['session'] ?? '';
            $partIndex = (int)($_GET['part'] ?? 0);
            
            if (empty($sessionId)) {
                errorResponse('Brak sessionId', 'user_error');
            }
            
            $session = loadSession($sessionId);
            if (!$session) {
                errorResponse('Sesja wygasła lub nie istnieje', 'user_error');
            }
            
            if (!isset($session['downloadLinks'][$partIndex])) {
                errorResponse('Nieprawidłowy indeks pliku', 'user_error');
            }
            
            $ksef = createKsefService($session['baseUrl']);
            
            $zipData = $ksef->downloadAndDecrypt(
                $session['downloadLinks'][$partIndex],
                $session['rawSymmetricKey'],
                $session['rawIV']
            );
            
            // Wyślij plik
            $filename = 'faktury_ksef_part' . ($partIndex + 1) . '_' . date('Y-m-d_H-i-s') . '.zip';
            
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($zipData));
            
            echo $zipData;
            exit;
        
        // ====================================================================
        // DEFAULT
        // ====================================================================
        default:
            errorResponse('Nieznana akcja: ' . $action, 'user_error', 404);
    }
    
} catch (\Exception $e) {
    $message = $e->getMessage();
    
    // Klasyfikacja błędów
    $errorType = 'server_error';
    $suggestions = [];
    
    // Błędy autoryzacji (user_error)
    if (strpos($message, 'challenge') !== false || 
        strpos($message, 'token') !== false ||
        strpos($message, 'autoryzac') !== false ||
        strpos($message, 'uwierzytelni') !== false ||
        strpos($message, '401') !== false ||
        strpos($message, '403') !== false ||
        strpos($message, '400') !== false) {
        $errorType = 'user_error';
        $suggestions = [
            'Sprawdź czy token KSeF jest poprawny i aktualny',
            'Sprawdź czy środowisko (DEMO/TEST) pasuje do tokena',
            'Sprawdź czy NIP jest zgodny z tokenem'
        ];
    }
    // Błędy połączenia (server_error)
    elseif (strpos($message, 'cURL') !== false || 
            strpos($message, 'timeout') !== false ||
            strpos($message, 'connection') !== false ||
            strpos($message, '500') !== false ||
            strpos($message, '502') !== false ||
            strpos($message, '503') !== false) {
        $errorType = 'server_error';
        $suggestions = [
            'Serwer KSeF może być chwilowo niedostępny',
            'Spróbuj ponownie za kilka minut',
            'Sprawdź status serwera KSeF'
        ];
    }
    // Błędy walidacji NIP (user_error)
    elseif (strpos($message, 'NIP') !== false || 
            strpos($message, 'nip') !== false) {
        $errorType = 'user_error';
        $suggestions = [
            'Sprawdź czy NIP ma dokładnie 10 cyfr',
            'Sprawdź czy NIP jest zgodny z tokenem KSeF'
        ];
    }
    // Brak faktur (info)
    elseif (strpos($message, 'brak faktur') !== false || 
            strpos($message, 'nie znaleziono') !== false ||
            strpos($message, 'empty') !== false) {
        $errorType = 'info';
        $suggestions = [
            'Zmień zakres dat',
            'Sprawdź czy w wybranym okresie były wystawione faktury'
        ];
    }
    
    jsonResponse([
        'success' => false,
        'errorType' => $errorType,
        'message' => $message,
        'suggestions' => $suggestions
    ], $errorType === 'user_error' ? 400 : 500);
}
