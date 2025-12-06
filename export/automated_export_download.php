<?php
/**
 * Zautomatyzowany eksport i deszyfrowanie faktur z KSeF
 * 
 * Łączy cały przepływ: pobranie linków → pobranie .aes → deszyfrowanie → zapis .zip
 */

require_once __DIR__ . '/links_to_download.php';

/**
 * Pobiera plik .aes z podanego URL do pamięci (bez zapisu na dysk)
 *
 * @param string $url - URL do pliku .aes
 * @param string $accessToken - token dostępu do API
 * @return string - surowe dane binarne pliku .aes
 * @throws RuntimeException
 */
function downloadAesFile(string $url): string
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
        throw new RuntimeException("Błąd cURL podczas pobierania pliku AES: $error");
    }

    if ($httpCode !== 200) {
        throw new RuntimeException("Błąd HTTP $httpCode podczas pobierania pliku AES");
    }

    if (empty($response)) {
        throw new RuntimeException("Pobrano pusty plik AES");
    }

    return $response;
}

/**
 * Odczytuje klucz symetryczny i IV z pliku last_export_encryption.json
 *
 * @return array - [klucz AES (32 bajty), IV (16 bajtów)]
 * @throws RuntimeException
 */
function getEncryptionKeys(): array
{
    $encFile = __DIR__ . '/last_export_encryption.json';

    if (!file_exists($encFile)) {
        throw new RuntimeException(
            "Brak pliku last_export_encryption.json - musisz najpierw wykonać eksport (sendExportRequest)"
        );
    }

    $encJson = file_get_contents($encFile);
    $encData = json_decode($encJson, true);

    if (!$encData || empty($encData['rawSymmetricKey']) || empty($encData['rawIV'])) {
        throw new RuntimeException("Niepoprawne dane w last_export_encryption.json");
    }

    $key = base64_decode($encData['rawSymmetricKey']);
    $iv = base64_decode($encData['rawIV']);

    if (strlen($key) !== 32) {
        throw new RuntimeException(
            "Klucz AES ma złą długość: " . strlen($key) . " bajtów (wymagane 32 dla AES-256)"
        );
    }

    if (strlen($iv) !== 16) {
        throw new RuntimeException(
            "IV ma złą długość: " . strlen($iv) . " bajtów (wymagane 16)"
        );
    }

    return [$key, $iv];
}

/**
 * Deszyfruje dane AES-256-CBC w pamięci
 *
 * @param string $ciphertext - zaszyfrowane dane
 * @param string $key - klucz AES (32 bajty)
 * @param string $iv - wektor inicjalizacyjny (16 bajtów)
 * @return string - odszyfrowane dane (ZIP)
 * @throws RuntimeException
 */
function decryptAesData(string $ciphertext, string $key, string $iv): string
{
    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-cbc',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($plaintext === false) {
        throw new RuntimeException(
            "Błąd deszyfrowania AES - prawdopodobnie zły klucz/IV lub dane nie pochodzą z tego eksportu"
        );
    }

    return $plaintext;
}

/**
 * Pobiera i deszyfruje eksport faktur z KSeF
 *
 * @param string $accessToken - aktywny token dostępu
 * @param string $referenceNumber - numer referencyjny eksportu
 * @param string $baseUrl - bazowy URL API KSeF (domyślnie demo)
 * @return array - tablica ścieżek do zapisanych plików ZIP
 * @throws RuntimeException
 */
function downloadAndDecryptExport(
    string $accessToken,
    string $referenceNumber,
    string $baseUrl = "https://ksef-demo.mf.gov.pl"
): array {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "🚀 Pobieranie i deszyfrowanie eksportu\n";
    echo "   Reference: $referenceNumber\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    // KROK 1: Pobranie linków do plików .aes
    echo "🔗 Krok 1: Pobieranie linków do plików .aes...\n";
    $downloadLinks = getExportDownloadLinks($accessToken, $referenceNumber, $baseUrl);

    if (empty($downloadLinks)) {
        throw new RuntimeException(
            "Eksport nie jest jeszcze gotowy lub brak plików. Spróbuj ponownie za chwilę."
        );
    }

    echo "   ✅ Otrzymano " . count($downloadLinks) . " link(ów)\n\n";

    // KROK 2: Odczytanie kluczy szyfrowania
    echo "🔑 Krok 2: Odczytywanie kluczy szyfrowania...\n";
    [$key, $iv] = getEncryptionKeys();
    echo "   ✅ Klucze odczytane\n\n";

    // KROK 3: Pobieranie, deszyfrowanie i zapis każdej części
    $outputPaths = [];
    $timestamp = date('Y-m-d_H-i-s');

    foreach ($downloadLinks as $index => $url) {
        $partNum = $index + 1;
        
        echo "📥 Krok 3.$partNum: Pobieranie części $partNum...\n";
        echo "   URL: $url\n";

        // Pobranie pliku .aes do pamięci
        $aesData = downloadAesFile($url);
        echo "   ✅ Pobrano " . number_format(strlen($aesData)) . " bajtów\n";

        // Deszyfrowanie w pamięci
        echo "🔓 Deszyfrowanie części $partNum...\n";
        $zipData = decryptAesData($aesData, $key, $iv);
        echo "   ✅ Odszyfrowano " . number_format(strlen($zipData)) . " bajtów\n";

        // Generowanie nazwy pliku i zapis
        $outputPath = __DIR__ . "/export_{$referenceNumber}_part{$partNum}_{$timestamp}.zip";

        if (file_put_contents($outputPath, $zipData) === false) {
            throw new RuntimeException("Nie udało się zapisać pliku ZIP: $outputPath");
        }

        echo "💾 Zapisano: $outputPath\n\n";
        $outputPaths[] = $outputPath;

        // Zwolnienie pamięci
        unset($aesData, $zipData);
    }

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "✅ SUKCES! Pobrano i odszyfrowano " . count($outputPaths) . " plik(ów)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    return $outputPaths;
}


// ============================================================================
// PRZYKŁAD UŻYCIA - uruchom ten plik bezpośrednio
// ============================================================================

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0] ?? '')) {
    
    // Sprawdź czy podano argumenty
    if ($argc < 3) {
        echo "Użycie: php automated_export_download.php <accessToken> <referenceNumber>\n";
        echo "\nPrzykład:\n";
        echo "  php automated_export_download.php eyJhbGciOi... abc123-def456-ghi789\n";
        exit(1);
    }

    $accessToken = $argv[1];
    $referenceNumber = $argv[2];

    try {
        $files = downloadAndDecryptExport($accessToken, $referenceNumber);
        
        echo "\n📁 Zapisane pliki:\n";
        foreach ($files as $file) {
            echo "   - $file\n";
        }
    } catch (Exception $e) {
        echo "❌ Błąd: " . $e->getMessage() . "\n";
        exit(1);
    }
}