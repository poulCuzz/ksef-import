<?php
/**
 * INDEX.PHP - Formularz do eksportu faktur z KSeF
 * Pliki ZIP pobierane bezpośrednio przez przeglądarkę użytkownika
 */

set_time_limit(300);
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

// ============================================================================
// FUNKCJE POMOCNICZE
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

    return [
        "encryptedSymmetricKey" => base64_encode($encryptedKey),
        "initializationVector"  => base64_encode($iv),
        "rawSymmetricKey" => base64_encode($symmetricKey),
        "rawIV"           => base64_encode($iv)
    ];
}

function sendExportRequestWithUrl(string $baseUrl, string $accessToken, string $subjectType, string $dateFrom, string $dateTo, array $encryptionData): ?array
{
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
        $zipData = decryptAes($aesData, $key, $iv);
        
        // Wyślij do przeglądarki
        $filename = "faktury_ksef_part" . ($partIndex + 1) . "_" . date('Y-m-d_H-i-s') . ".zip";
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($zipData));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        echo $zipData;
        exit;
        
    } catch (Exception $e) {
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

        // Formatuj daty dla API
        $dateFromFormatted = $dateFrom . "T00:00:00.000+00:00";
        $dateToFormatted = $dateTo . "T23:59:59.999+00:00";

        // ====== KROK 1: AUTORYZACJA ======
        
        [$challenge, $timestamp] = getChallengeWithUrl($baseUrl, $nip);
        
        $publicKeyPath = __DIR__ . '/auth/public_key.pem';
        $encryptedToken = encryptTokenData($ksefToken, $publicKeyPath, $timestamp);
        
        $authToken = getAuthTokenWithUrl($baseUrl, $challenge, $encryptedToken, $nip);
        
        $accessData = getAccessTokenWithUrl($baseUrl, $authToken);
        $accessToken = $accessData['accessToken']['token'];
        $refreshToken = $accessData['refreshToken']['token'];
        
        $accessToken = refreshAccessTokenWithUrl($baseUrl, $refreshToken);

        // ====== KROK 2: EKSPORT ======
        
        $publicKeyPathMF = __DIR__ . '/export/public_key_symetric_encription.pem';
        $encryptionData = generateEncryptionDataForExport($publicKeyPathMF);
        
        $exportResult = sendExportRequestWithUrl($baseUrl, $accessToken, $subjectType, $dateFromFormatted, $dateToFormatted, $encryptionData);
        
        if (!isset($exportResult['referenceNumber'])) {
            throw new Exception("Brak referenceNumber w odpowiedzi eksportu");
        }
        
        $referenceNumber = $exportResult['referenceNumber'];

        // ====== KROK 3: POBIERANIE LINKÓW ======
        
        $downloadUrls = getExportDownloadLinksWithUrl($baseUrl, $accessToken, $referenceNumber);
        
        if (empty($downloadUrls)) {
            throw new Exception("Eksport nie jest jeszcze gotowy. Reference: $referenceNumber. Spróbuj ponownie za chwilę.");
        }

        // ====== ZAPISZ SESJĘ ======
        
        $sessionId = uniqid('ksef_', true);
        
        // Upewnij się, że katalog temp istnieje
        if (!is_dir(__DIR__ . '/temp')) {
            mkdir(__DIR__ . '/temp', 0755, true);
        }
        
        $sessionData = [
            'downloadLinks' => $downloadUrls,
            'rawSymmetricKey' => $encryptionData['rawSymmetricKey'],
            'rawIV' => $encryptionData['rawIV'],
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
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; min-height: 100vh; }
        .container { max-width: 900px; margin: 0 auto; }
        .box { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); margin-bottom: 20px; }
        h2 { color: #333; margin-bottom: 20px; font-size: 28px; }
        .msg { padding: 15px; margin-bottom: 20px; border-radius: 8px; color: #fff; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .msg.ok { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .msg.err { background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%); }
        .msg::before { content: ''; width: 24px; height: 24px; background-size: contain; flex-shrink: 0; }
        .msg.ok::before { content: '✓'; font-size: 24px; font-weight: bold; }
        .msg.err::before { content: '✕'; font-size: 24px; font-weight: bold; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #555; font-weight: 500; font-size: 14px; }
        input[type=text], input[type=date], select { width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 15px; transition: all 0.3s; }
        input[type=text]:focus, input[type=date]:focus, select:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        
        .date-row { display: flex; gap: 15px; }
        .date-row > div { flex: 1; }
        
        button { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 15px 30px; cursor: pointer; border-radius: 8px; font-size: 16px; font-weight: 600; width: 100%; margin-top: 10px; transition: transform 0.2s, box-shadow 0.2s; }
        button:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4); }
        button:active { transform: translateY(0); }
        
        .downloads { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 25px; border-radius: 12px; color: white; }
        .downloads h3 { margin-bottom: 15px; font-size: 22px; }
        .download-item { background: rgba(255,255,255,0.2); padding: 15px; margin-bottom: 10px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; backdrop-filter: blur(10px); }
        .download-item:last-child { margin-bottom: 0; }
        .download-btn { background: white; color: #f5576c; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; transition: all 0.2s; }
        .download-btn:hover { transform: scale(1.05); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        
        .info { background: #e3f2fd; padding: 15px; border-radius: 8px; color: #1976d2; margin-bottom: 20px; border-left: 4px solid #2196f3; }
        
        @media (max-width: 600px) {
            .date-row { flex-direction: column; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="box">
        <h2>🧾 KSeF 2.0 - Eksport Faktur</h2>

        <?php if ($message): ?>
            <div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!empty($downloadLinks) && $sessionId): ?>
            <div class="downloads">
                <h3>📦 Pliki gotowe do pobrania</h3>
                <p style="margin-bottom: 15px; opacity: 0.9;">Kliknij przycisk, aby pobrać plik ZIP z fakturami:</p>
                <?php foreach ($downloadLinks as $index => $url): ?>
                    <div class="download-item">
                        <span>📄 Plik <?= $index + 1 ?> z <?= count($downloadLinks) ?></span>
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
                    <option value="demo" <?= ($_POST['env'] ?? '') === 'demo' ? 'selected' : '' ?>>🧪 DEMO (ksef-demo.mf.gov.pl)</option>
                    <option value="test" <?= ($_POST['env'] ?? '') === 'test' ? 'selected' : '' ?>>🔧 TEST (ksef-test.mf.gov.pl)</option>
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

            <button type="submit">🚀 Eksportuj faktury</button>
        </form>
    </div>
    
    <div class="info">
        <strong>ℹ️ Jak to działa?</strong><br>
        Po wypełnieniu formularza system połączy się z KSeF, wygeneruje eksport faktur, a następnie udostępni linki do pobrania plików ZIP bezpośrednio w Twojej przeglądarce. Pliki nie są zapisywane na serwerze.
    </div>
</div>

</body>
</html>