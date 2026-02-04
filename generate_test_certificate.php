<?php
/**
 * Generowanie testowego certyfikatu self-signed dla środowiska KSeF TEST
 * 
 * Użycie:
 *   php generate_test_certificate.php
 * 
 * Wygeneruje plik: certs/test_certificate.p12 z hasłem: test123
 */

require_once __DIR__ . '/vendor/autoload.php';

use N1ebieski\KSEFClient\Factories\CertificateFactory;

// ============================================
// KONFIGURACJA - ZMIEŃ WEDŁUG POTRZEB
// ============================================

$nip = '1234567890';        // Dowolny NIP (10 cyfr) - na TEST nie ma znaczenia
$firstName = 'Test';        // Imię
$lastName = 'User';         // Nazwisko
$password = 'test123';      // Hasło do pliku .p12
$outputDir = __DIR__ . '/certs';
$outputFile = $outputDir . '/test_certificate.p12';

// ============================================
// GENEROWANIE
// ============================================

echo "===========================================\n";
echo "Generator testowego certyfikatu KSeF\n";
echo "===========================================\n\n";

try {
    // Utwórz katalog jeśli nie istnieje
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
        echo "✓ Utworzono katalog: {$outputDir}\n";
    }

    // Sprawdź czy biblioteka ma metodę do self-signed
    if (!class_exists('N1ebieski\KSEFClient\Factories\CertificateFactory')) {
        throw new Exception("Nie znaleziono klasy CertificateFactory. Sprawdź czy biblioteka n1ebieski/ksef-php-client jest zainstalowana.");
    }

    echo "Generowanie certyfikatu...\n";
    echo "  NIP: {$nip}\n";
    echo "  Imię: {$firstName}\n";
    echo "  Nazwisko: {$lastName}\n\n";

    // Metoda 1: Spróbuj użyć CertificateFactory
    if (method_exists(CertificateFactory::class, 'makeSelfSigned')) {
        $certificate = CertificateFactory::makeSelfSigned($nip, $firstName, $lastName);
        $p12Content = $certificate->toPkcs12($password);
    } 
    // Metoda 2: Ręczne generowanie przez OpenSSL
    else {
        echo "CertificateFactory::makeSelfSigned nie istnieje.\n";
        echo "Generuję ręcznie przez OpenSSL...\n\n";
        
        $p12Content = generateSelfSignedCertificate($nip, $firstName, $lastName, $password);
    }

    // Zapisz plik
    file_put_contents($outputFile, $p12Content);

    echo "✓ Certyfikat wygenerowany!\n";
    echo "  Plik: {$outputFile}\n";
    echo "  Hasło: {$password}\n\n";
    
    echo "===========================================\n";
    echo "INSTRUKCJA UŻYCIA:\n";
    echo "===========================================\n";
    echo "1. W formularzu wybierz środowisko: TEST\n";
    echo "2. Wpisz hasło certyfikatu: {$password}\n";
    echo "3. NIP: {$nip} (lub dowolny 10-cyfrowy)\n";
    echo "4. Kliknij 'Importuj faktury'\n\n";

} catch (Exception $e) {
    echo "✗ Błąd: " . $e->getMessage() . "\n\n";
    
    echo "Próbuję alternatywną metodę (czysty OpenSSL)...\n\n";
    
    try {
        $p12Content = generateSelfSignedCertificate($nip, $firstName, $lastName, $password);
        file_put_contents($outputFile, $p12Content);
        
        echo "✓ Certyfikat wygenerowany alternatywną metodą!\n";
        echo "  Plik: {$outputFile}\n";
        echo "  Hasło: {$password}\n";
    } catch (Exception $e2) {
        echo "✗ Błąd alternatywnej metody: " . $e2->getMessage() . "\n";
    }
}

/**
 * Generuje self-signed certyfikat przez OpenSSL
 */
function generateSelfSignedCertificate(string $nip, string $firstName, string $lastName, string $password): string
{
    // Konfiguracja certyfikatu zgodna z KSeF
    $config = [
        "digest_alg" => "sha256",
        "private_key_bits" => 2048,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    ];

    // Dane podmiotu (DN) - format zgodny z KSeF
    $dn = [
        "countryName" => "PL",
        "serialNumber" => "TINPL-{$nip}",  // Format dla NIP
        "givenName" => $firstName,
        "surname" => $lastName,
        "commonName" => "{$firstName} {$lastName}",
    ];

    // Generuj klucz prywatny
    $privateKey = openssl_pkey_new($config);
    if ($privateKey === false) {
        throw new Exception("Nie można wygenerować klucza prywatnego: " . openssl_error_string());
    }

    // Generuj CSR
    $csr = openssl_csr_new($dn, $privateKey, $config);
    if ($csr === false) {
        throw new Exception("Nie można wygenerować CSR: " . openssl_error_string());
    }

    // Podpisz certyfikat (self-signed, ważny 365 dni)
    $certificate = openssl_csr_sign($csr, null, $privateKey, 365, $config);
    if ($certificate === false) {
        throw new Exception("Nie można podpisać certyfikatu: " . openssl_error_string());
    }

    // Eksportuj do PKCS12
    $p12Content = null;
    $success = openssl_pkcs12_export($certificate, $p12Content, $privateKey, $password);
    
    if (!$success || empty($p12Content)) {
        throw new Exception("Nie można wyeksportować do PKCS12: " . openssl_error_string());
    }

    return $p12Content;
}
