<?php
require __DIR__ . '/../vendor/autoload.php';

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

function generateEncryptionData(string $publicKeyPath): array
{
    // 1. Losowy klucz AES-256 (32 bajty)
    $symmetricKey = random_bytes(32);

    // 2. Losowy IV (16 bajtów)
    $iv = random_bytes(16);

    // 3. Wczytaj certyfikat MF
    $certContent = file_get_contents($publicKeyPath);

    if ($certContent === false) {
        throw new Exception("Nie można wczytać certyfikatu MF");
    }

    // 4. Wyciągnięcie klucza publicznego
    $x509 = new X509();
    $cert = $x509->loadX509($certContent);
    $publicKey = $x509->getPublicKey();

    if (!$publicKey) {
        throw new Exception("Nie można wyciągnąć klucza publicznego z certyfikatu MF");
    }

    // 5. Szyfrowanie RSA-OAEP + SHA256 + MGF1-SHA256
    $encryptedKey = $publicKey
        ->withPadding(RSA::ENCRYPTION_OAEP)
        ->withHash('sha256')
        ->withMGFHash('sha256')
        ->encrypt($symmetricKey);

    if ($encryptedKey === false) {
        throw new Exception("Błąd szyfrowania klucza AES kluczem publicznym MF");
    }

     // 6. Przygotuj dane w Base64
    $rawSymmetricKeyB64 = base64_encode($symmetricKey);
    $rawIVB64           = base64_encode($iv);

    // 7. Zapisz rawSymmetricKey i rawIV do pliku (tworzy, jeśli nie istnieje, i nadpisuje)
    $filePath = __DIR__ . '/last_export_encryption.json';

    $payload = [
        'generatedAt'     => date('c'),
        'rawSymmetricKey' => $rawSymmetricKeyB64,
        'rawIV'           => $rawIVB64,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Nie udało się zserializować danych szyfrowania do JSON.');
    }

    if (file_put_contents($filePath, $json) === false) {
        throw new RuntimeException("Nie udało się zapisać pliku z danymi szyfrowania: {$filePath}");
    }

    // 8. Zwracamy zgodnie z formatem KSeF
    return [
        "encryptedSymmetricKey" => base64_encode($encryptedKey),
        "initializationVector"  => base64_encode($iv),

        // konieczne do odszyfrowania ZIP-a
        "rawSymmetricKey" => base64_encode($symmetricKey),
        "rawIV"           => base64_encode($iv)
    ];
}


/**
 * Tworzy payload JSON do wysyłki
 */
function createExportPayload(): array
{
    $publicKeySymetricPath = __DIR__ . '/public_key_symetric_encription.pem';
    $encryptionData = generateEncryptionData($publicKeySymetricPath);
    $config = require __DIR__ . '/../config.php';
    $filters = [
        "subjectType" => "Subject1",
        "dateRange" => [
            "dateType" => "Issue",
            "from" => $config['export_date_from'],
            "to" => $config['export_date_to']
        ]
    ];

    return [
        "encryption" => $encryptionData,
        "filters" => $filters
    ];
}

/**
 * Wysyła POST do KSeF i zwraca odpowiedź JSON
 */
function sendExportRequest(string $accessToken): ?array
{
    $url = "https://ksef-demo.mf.gov.pl/api/v2/invoices/exports";

    $payload = json_encode(createExportPayload());

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
        echo "Błąd HTTP: $httpCode\n";
        echo "Odpowiedź serwera:\n$response\n";
        return null;
    }

    return json_decode($response, true);
}


