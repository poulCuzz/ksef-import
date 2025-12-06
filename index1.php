<?php
/**
 * INDEX.PHP - Formularz do eksportu faktur z KSeF
 * 
 * Użytkownik podaje:
 * - Środowisko (demo/test)
 * - Token KSeF
 * - NIP
 * - Typ podmiotu (Subject1/Subject2)
 * - Zakres dat
 */

// Ustawienia
set_time_limit(300); // 5 minut na wykonanie
header('Content-Type: text/html; charset=utf-8');

// Autoloader i use statements
require_once __DIR__ . '/vendor/autoload.php';

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

// ============================================================================
// FUNKCJE POMOCNICZE (z dynamicznym baseUrl)
// ============================================================================

/**
 * Pobiera challenge z KSeF
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

/**
 * Szyfruje token RSA-OAEP
 */
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

/**
 * Uzyskuje authenticationToken
 */
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

/**
 * Wymienia authenticationToken na accessToken + refreshToken
 */
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

/**
 * Odświeża accessToken
 */
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

/**
 * Generuje dane szyfrowania dla eksportu
 */
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

/**
 * Wysyła żądanie eksportu
 */
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

/**
 * Pobiera linki do plików .aes
 */
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

/**
 * Pobiera plik .aes (bez autoryzacji)
 */
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

/**
 * Odczytuje klucze szyfrowania
 */
function getEncryptionKeysFromFile(): array
{
    $encFile = __DIR__ . '/export/last_export_encryption.json';

    if (!file_exists($encFile)) {
        throw new RuntimeException("Brak pliku last_export_encryption.json");
    }

    $encData = json_decode(file_get_contents($encFile), true);

    $key = base64_decode($encData['rawSymmetricKey']);
    $iv = base64_decode($encData['rawIV']);

    if (strlen($key) !== 32 || strlen($iv) !== 16) {
        throw new RuntimeException("Niepoprawne klucze szyfrowania");
    }

    return [$key, $iv];
}

/**
 * Deszyfruje dane AES
 */
function decryptAes(string $ciphertext, string $key, string $iv): string
{
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

    if ($plaintext === false) {
        throw new RuntimeException("Błąd deszyfrowania AES");
    }

    return $plaintext;
}

// ============================================================================
// OBSŁUGA FORMULARZA
// ============================================================================

$message = '';
$messageType = '';
$downloadedFiles = [];

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

        // Formatuj daty dla API
        $dateFromFormatted = $dateFrom . "T00:00:00.000+00:00";
        $dateToFormatted = $dateTo . "T23:59:59.999+00:00";

        // ====== KROK 1: AUTORYZACJA ======
        
        // 1.1 Challenge
        [$challenge, $timestamp] = getChallengeWithUrl($baseUrl, $nip);
        
        // 1.2 Szyfrowanie tokena
        $publicKeyPath = __DIR__ . '/auth/public_key.pem';
        $encryptedToken = encryptTokenData($ksefToken, $publicKeyPath, $timestamp);
        
        // 1.3 Authentication token
        $authToken = getAuthTokenWithUrl($baseUrl, $challenge, $encryptedToken, $nip);
        
        // 1.4 Access token + refresh token
        $accessData = getAccessTokenWithUrl($baseUrl, $authToken);
        $accessToken = $accessData['accessToken']['token'];
        $refreshToken = $accessData['refreshToken']['token'];
        
        // 1.5 Odświeżenie
        $accessToken = refreshAccessTokenWithUrl($baseUrl, $refreshToken);

        // ====== KROK 2: EKSPORT ======
        
        $exportResult = sendExportRequestWithUrl($baseUrl, $accessToken, $subjectType, $dateFromFormatted, $dateToFormatted);
        
        if (!isset($exportResult['referenceNumber'])) {
            throw new Exception("Brak referenceNumber w odpowiedzi eksportu");
        }
        
        $referenceNumber = $exportResult['referenceNumber'];

        // ====== KROK 3: POBIERANIE I DESZYFROWANIE ======
        
        $downloadLinks = getExportDownloadLinksWithUrl($baseUrl, $accessToken, $referenceNumber);
        
        if (empty($downloadLinks)) {
            throw new Exception("Eksport nie jest jeszcze gotowy. Reference: $referenceNumber. Spróbuj ponownie za chwilę.");
        }

        [$key, $iv] = getEncryptionKeysFromFile();
        
        $timestamp = date('Y-m-d_H-i-s');
        
        foreach ($downloadLinks as $index => $url) {
            $partNum = $index + 1;
            
            $aesData = downloadAesFileSimple($url);
            $zipData = decryptAes($aesData, $key, $iv);
            
            $outputPath = __DIR__ . "/export/export_{$referenceNumber}_part{$partNum}_{$timestamp}.zip";
            file_put_contents($outputPath, $zipData);
            
            $downloadedFiles[] = basename($outputPath);
        }

        $message = "Sukces! Pobrano " . count($downloadedFiles) . " plik(ów) ZIP.";
        $messageType = 'ok';

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'err';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>KSeF API v2 - Eksport Faktur</title>
    <style>
        body { font-family: sans-serif; background: #eef; padding: 20px; }
        .box { background: #fff; padding: 20px; max-width: 900px; margin: 0 auto; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .msg { padding: 10px; margin: 10px 0; border-radius: 4px; color: #fff; font-weight: bold; }
        .msg.ok { background: #28a745; }
        .msg.err { background: #dc3545; }
        input[type=text], input[type=date], select { width: 100%; padding: 10px; margin: 5px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background: #007bff; color: white; border: none; padding: 12px 20px; cursor: pointer; border-radius: 4px; font-size: 16px; width: 100%; margin-top: 10px; }
        button:hover { background: #0056b3; }
        .files { margin-top: 20px; padding: 15px; background: #e8f5e9; border-radius: 4px; }
        .files h4 { margin-top: 0; }
        .files ul { margin: 0; padding-left: 20px; }
    </style>
</head>
<body>

<div class="box">
    <h2>KSeF 2.0 - Eksport Faktur</h2>

    <?php if ($message): ?>
        <div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($downloadedFiles)): ?>
        <div class="files">
            <h4>Pobrane pliki:</h4>
            <ul>
                <?php foreach ($downloadedFiles as $file): ?>
                    <li><?= htmlspecialchars($file) ?></li>
                <?php endforeach; ?>
            </ul>
            <p><small>Pliki znajdują się w katalogu <code>export/</code></small></p>
        </div>
    <?php endif; ?>

    <form method="post">
        <label>Środowisko:</label>
        <select name="env">
            <option value="demo" <?= ($_POST['env'] ?? '') === 'demo' ? 'selected' : '' ?>>DEMO (ksef-demo.mf.gov.pl)</option>
            <option value="test" <?= ($_POST['env'] ?? '') === 'test' ? 'selected' : '' ?>>TEST (ksef-test.mf.gov.pl)</option>
        </select>

        <label>Token KSeF:</label>
        <input type="text" name="ksef_token" placeholder="Wklej swój token KSeF" value="<?= htmlspecialchars($_POST['ksef_token'] ?? '') ?>" required>

        <label>NIP:</label>
        <input type="text" name="nip" placeholder="NIP (10 cyfr)" value="<?= htmlspecialchars($_POST['nip'] ?? '') ?>" required>

        <label>Typ podmiotu:</label>
        <select name="subject_type">
            <option value="Subject1" <?= ($_POST['subject_type'] ?? '') === 'Subject1' ? 'selected' : '' ?>>Subject1 (Sprzedawca)</option>
            <option value="Subject2" <?= ($_POST['subject_type'] ?? '') === 'Subject2' ? 'selected' : '' ?>>Subject2 (Nabywca)</option>
        </select>

        <div style="display: flex; gap: 10px;">
            <div style="flex:1">
                <label>Data od:</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($_POST['date_from'] ?? '') ?>" required>
            </div>
            <div style="flex:1">
                <label>Data do:</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($_POST['date_to'] ?? '') ?>" required>
            </div>
        </div>

        <button type="submit">Eksportuj faktury</button>
    </form>
</div>

</body>
</html>
