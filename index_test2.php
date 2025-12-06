<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
/**
 * INDEX.PHP - Formularz do eksportu faktur z KSeF
 * Pliki ZIP pobierane bezpośrednio przez przeglądarkę użytkownika
 */

set_time_limit(300);
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/export/save_reference_number.php';
require_once __DIR__ . '/logger.php';


use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

// ============================================================================
// FUNKCJE POMOCNICZE
// ============================================================================
/**
 * Zapisuje reference number do pliku JSON
 */

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
    $cert = $x509->loadX509($certContent);
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

function getExportDownloadLinksWithUrl(string $baseUrl, string $accessToken, string $referenceNumber): array
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

    if (($data['status']['code'] ?? null) !== 200) {
        return [];
    }

    if (empty($data['package']['parts'])) {
        return [];
    }

    return array_map(fn($p) => $p['url'], $data['package']['parts']);
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

/**
 * Czeka aż eksport będzie gotowy (polling)
 */
function waitForExportReady(string $baseUrl, string $accessToken, string $referenceNumber, int $maxAttempts = 30, int $sleepSeconds = 5): array
{
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        logError('POLLING', "Próba $attempt/$maxAttempts - czekam na eksport...", ['referenceNumber' => $referenceNumber]);
        
        $downloadUrls = getExportDownloadLinksWithUrl($baseUrl, $accessToken, $referenceNumber);
        
        if (!empty($downloadUrls)) {
            logError('POLLING', "Eksport gotowy po $attempt próbach", ['links_count' => count($downloadUrls)]);
            return $downloadUrls;
        }
        
        // Jeszcze nie gotowe - czekaj
        if ($attempt < $maxAttempts) {
            sleep($sleepSeconds);
        }
    }
    
    // Przekroczono limit prób
    return [];
}
// ============================================================================
// OBSŁUGA POBIERANIA PLIKU
// ============================================================================

if (isset($_GET['download']) && isset($_GET['session'])) {
    $sessionId = $_GET['session'];
    $partIndex = (int)$_GET['download'];
    
    $sessionFile = __DIR__ . "/temp/session_{$sessionId}.json";
    
    if (!file_exists($sessionFile)) {
        http_response_code(404);
        die('Sesja wygasła lub nie istnieje');
    }
    
    $sessionData = json_decode(file_get_contents($sessionFile), true);
    
    if (!isset($sessionData['downloadLinks'][$partIndex])) {
        http_response_code(404);
        die('Nieprawidłowy indeks pliku');
    }
    
    try {
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
        logSessionEnd(1, true);
        exit;
        
    } catch (Exception $e) {
        logError('DOWNLOAD_FILE', $e->getMessage(), ['url' => $url ?? 'unknown']);
        http_response_code(500);
        die('Błąd pobierania pliku: ' . $e->getMessage());
    }
}

// ============================================================================
// OBSŁUGA FORMULARZA
// ============================================================================

$message = '';
$messageType = '';
$downloadLinks = [];
$sessionId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Pobierz dane z formularza
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

        // Buduj baseUrl
        $baseUrl = ($env === 'demo') 
            ? "https://ksef-demo.mf.gov.pl" 
            : "https://ksef-test.mf.gov.pl";

        // Start logowania
        logSessionStart($env, $nip);

        // TEST - sprawdź czy logowanie działa
        logError('TEST', 'Logowanie działa!', ['test' => true]);

        // Formatuj daty dla API
        $dateFromFormatted = $dateFrom . "T00:00:00.000+00:00";
        $dateToFormatted = $dateTo . "T23:59:59.999+00:00";

        // ====== KROK 1: AUTORYZACJA ======
        
        [$challenge, $timestamp] = getChallengeWithUrl($baseUrl, $nip);
        logChallenge($baseUrl . "/api/v2/auth/challenge", ["nip" => $nip], ["challenge" => $challenge, "timestamp" => $timestamp], 200);

        $publicKeyPath = __DIR__ . '/auth/public_key.pem';
        $encryptedToken = encryptTokenData($ksefToken, $publicKeyPath, $timestamp);
        logTokenEncryption($ksefToken, $timestamp, true);

        $authToken = getAuthTokenWithUrl($baseUrl, $challenge, $encryptedToken, $nip);
        logAuthenticationToken($baseUrl . "/api/v2/auth/ksef-token", ["challenge" => $challenge, "nip" => $nip, "encryptedToken" => $encryptedToken], ["authenticationToken" => ["token" => $authToken]], 200);
        
        $accessData = getAccessTokenWithUrl($baseUrl, $authToken);
        $accessToken = $accessData['accessToken']['token'];
        $refreshToken = $accessData['refreshToken']['token'];
        logAccessToken($baseUrl . "/api/v2/auth/token/redeem", $accessData, 200);
        
        $accessToken = refreshAccessTokenWithUrl($baseUrl, $refreshToken);
        logRefreshToken($baseUrl . "/api/v2/auth/token/refresh", ["accessToken" => ["token" => $accessToken]], 200);

        // ====== KROK 2: EKSPORT ======
        
        $exportResult = sendExportRequestWithUrl($baseUrl, $accessToken, $subjectType, $dateFromFormatted, $dateToFormatted);
        logExportRequest($baseUrl . "/api/v2/invoices/exports", ["subjectType" => $subjectType, "dateFrom" => $dateFromFormatted, "dateTo" => $dateToFormatted], $exportResult, 200);
        
        if (!isset($exportResult['referenceNumber'])) {
            throw new Exception("Brak referenceNumber w odpowiedzi eksportu");
        }
        
        $referenceNumber = $exportResult['referenceNumber'];

        // Zapisz reference number do pliku
        saveReferenceNumber($referenceNumber, $nip, $dateFrom, $dateTo, $subjectType);

        // ====== KROK 3: POBIERANIE LINKÓW ======
        
        $downloadUrls = waitForExportReady($baseUrl, $accessToken, $referenceNumber, 30, 5);
        
        logSessionEnd(0, false);
        if (empty($downloadUrls)) {
            throw new Exception("Eksport nie jest jeszcze gotowy. Reference: $referenceNumber. Spróbuj ponownie za chwilę.");
        }

        // ====== ZAPISZ SESJĘ ======
        
        $sessionId = uniqid('ksef_', true);
        
        // Upewnij się, że katalog temp istnieje
        if (!is_dir(__DIR__ . '/temp')) {
            mkdir(__DIR__ . '/temp', 0755, true);
        }
        
        // Odczytaj klucze z pliku zapisanego przez generateEncryptionDataForExport
        $encFile = __DIR__ . '/export/last_export_encryption.json';
        $encData = json_decode(file_get_contents($encFile), true);
        
        $sessionData = [
            'downloadLinks' => $downloadUrls,
            'rawSymmetricKey' => $encData['rawSymmetricKey'],
            'rawIV' => $encData['rawIV'],
            'created' => time(),
            'referenceNumber' => $referenceNumber
        ];
        
        file_put_contents(
            __DIR__ . "/temp/session_{$sessionId}.json",
            json_encode($sessionData)
        );
        
        $downloadLinks = $downloadUrls;
        $message = "Sukces! Znaleziono " . count($downloadUrls) . " plik(ów) do pobrania.";
        $messageType = 'ok';

   } catch (Exception $e) {
        logError('EXCEPTION', $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        // logSessionEnd
        $message = $e->getMessage();
        $messageType = 'err';
    }
}

// Czyszczenie starych sesji (starsze niż 1 godzinę)
$tempDir = __DIR__ . '/temp';
if (is_dir($tempDir)) {
    $files = glob($tempDir . '/session_*.json');
    foreach ($files as $file) {
        if (filemtime($file) < time() - 3600) {
            unlink($file);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KSeF API v2 - Eksport Faktur</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; padding: 20px; min-height: 100vh; }
        .container { max-width: 900px; margin: 0 auto; }
        .box { background: rgba(255, 255, 255, 0.95); padding: 30px; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px; border: 1px solid #e0e0e0; }
        h2 { color: #333; margin-bottom: 20px; font-size: 24px; font-weight: 600; }
        
        .msg { padding: 12px 15px; margin-bottom: 20px; border-radius: 4px; font-size: 14px; border-left: 4px solid; }
        .msg.ok { background: #e8f5e9; color: #2e7d32; border-color: #4caf50; }
        .msg.err { background: #ffebee; color: #c62828; border-color: #f44336; }
        
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; color: #555; font-weight: 500; font-size: 13px; }
        input[type=text], input[type=date], select { 
            width: 100%; 
            padding: 10px 12px; 
            border: 1px solid #d0d0d0; 
            border-radius: 3px; 
            font-size: 14px; 
            background: #fff;
            transition: border-color 0.2s;
        }
        input[type=text]:focus, input[type=date]:focus, select:focus { 
            outline: none; 
            border-color: #5dade2;
        }
        
        .date-row { display: flex; gap: 15px; }
        .date-row > div { flex: 1; }
        
        button { 
            background: #3498db; 
            color: white; 
            border: none; 
            padding: 12px 24px; 
            cursor: pointer; 
            border-radius: 3px; 
            font-size: 15px; 
            font-weight: 500; 
            width: 100%; 
            margin-top: 10px; 
            transition: background 0.2s;
        }
        button:hover { background: #2980b9; }
        button:active { background: #1c5d87; }
        
        .downloads { 
            background: rgba(255, 255, 255, 0.95); 
            padding: 20px; 
            border-radius: 4px; 
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .downloads h3 { 
            margin-bottom: 15px; 
            font-size: 18px; 
            color: #333;
            font-weight: 600;
        }
        .download-item { 
            background: #f9f9f9; 
            padding: 12px 15px; 
            margin-bottom: 10px; 
            border-radius: 3px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            border: 1px solid #e8e8e8;
        }
        .download-item:last-child { margin-bottom: 0; }
        .download-item span { color: #555; font-size: 14px; }
        .download-btn { 
            background: #3498db; 
            color: white; 
            border: none; 
            padding: 8px 16px; 
            border-radius: 3px; 
            cursor: pointer; 
            font-weight: 500; 
            text-decoration: none; 
            display: inline-block; 
            font-size: 13px;
            transition: background 0.2s;
        }
        .download-btn:hover { background: #2980b9; }
        
        .info { 
            background: rgba(255, 255, 255, 0.95); 
            padding: 15px; 
            border-radius: 4px; 
            color: #555; 
            font-size: 13px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .info strong { color: #333; }
        
        @media (max-width: 600px) {
            .date-row { flex-direction: column; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="box">
        <h2>KSeF 2.0 - Eksport Faktur</h2>

        <?php if ($message): ?>
            <div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!empty($downloadLinks) && $sessionId): ?>
            <div class="downloads">
                <h3>Pliki gotowe do pobrania</h3>
                <p style="margin-bottom: 15px; color: #666; font-size: 13px;">Kliknij przycisk, aby pobrać plik ZIP z fakturami:</p>
                <?php foreach ($downloadLinks as $index => $url): ?>
                    <div class="download-item">
                        <span>Plik <?= $index + 1 ?> z <?= count($downloadLinks) ?></span>
                        <a href="?download=<?= $index ?>&session=<?= urlencode($sessionId) ?>" class="download-btn">
                            Pobierz
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <br>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label>Środowisko:</label>
                <select name="env">
                    <option value="demo" <?= ($_POST['env'] ?? '') === 'demo' ? 'selected' : '' ?>>DEMO (ksef-demo.mf.gov.pl)</option>
                    <option value="test" <?= ($_POST['env'] ?? '') === 'test' ? 'selected' : '' ?>>TEST (ksef-test.mf.gov.pl)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Token KSeF:</label>
                <input type="text" name="ksef_token" placeholder="Wklej swój token KSeF" value="<?= htmlspecialchars($_POST['ksef_token'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label>NIP:</label>
                <input type="text" name="nip" placeholder="NIP (10 cyfr)" value="<?= htmlspecialchars($_POST['nip'] ?? '') ?>" required pattern="[0-9]{10}">
            </div>

            <div class="form-group">
                <label>Typ podmiotu:</label>
                <select name="subject_type">
                    <option value="Subject1" <?= ($_POST['subject_type'] ?? '') === 'Subject1' ? 'selected' : '' ?>>Subject1 (Sprzedawca)</option>
                    <option value="Subject2" <?= ($_POST['subject_type'] ?? '') === 'Subject2' ? 'selected' : '' ?>>Subject2 (Nabywca)</option>
                </select>
            </div>

            <div class="date-row">
                <div class="form-group">
                    <label>Data od:</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($_POST['date_from'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Data do:</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($_POST['date_to'] ?? '') ?>" required>
                </div>
            </div>

            <button type="submit">Zaloguj i Pobierz faktury</button>
        </form>
    </div>
    
</div>

</body>
</html>