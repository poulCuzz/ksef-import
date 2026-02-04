<?php
/**
 * Skrypt konwersji certyfikatu .crt + .key do formatu .p12
 * 
 * Użycie:
 * 1. Umieść ten plik w katalogu z plikami certyfikatu
 * 2. Uruchom: composer require n1ebieski/ksef-php-client guzzlehttp/guzzle
 * 3. Uruchom: php convert_to_p12.php
 */

require __DIR__ . '/../vendor/autoload.php';

use N1ebieski\KSEFClient\Actions\ConvertCertificateToPkcs12\ConvertCertificateToPkcs12Action;
use N1ebieski\KSEFClient\Actions\ConvertCertificateToPkcs12\ConvertCertificateToPkcs12Handler;
use N1ebieski\KSEFClient\Factories\CertificateFactory;

// ============================================
// KONFIGURACJA - dostosuj ścieżki do swoich plików
// ============================================

$certFile = __DIR__ . '/AkceptujFaktury_pl.crt';
$keyFile  = __DIR__ . '/AkceptujFaktury_pl.key';
$passFile = __DIR__ . '/AkceptujFaktury_pl_keypass.txt';

// Nazwa pliku wyjściowego .p12
$outputFile = __DIR__ . '/AkceptujFaktury_pl.p12';

// Hasło do nowego pliku .p12 (możesz zmienić)
$p12Password = 'ksef2025';

// ============================================
// KONWERSJA
// ============================================

echo "🔐 Konwersja certyfikatu do formatu .p12\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Sprawdź czy pliki istnieją
if (!file_exists($certFile)) {
    die("❌ Nie znaleziono pliku certyfikatu: $certFile\n");
}
if (!file_exists($keyFile)) {
    die("❌ Nie znaleziono pliku klucza: $keyFile\n");
}
if (!file_exists($passFile)) {
    die("❌ Nie znaleziono pliku z hasłem: $passFile\n");
}

echo "✅ Plik certyfikatu: $certFile\n";
echo "✅ Plik klucza: $keyFile\n";
echo "✅ Plik hasła: $passFile\n\n";

// Wczytaj pliki
$certificate = file_get_contents($certFile);
$privateKey = file_get_contents($keyFile);
$keyPassword = trim(file_get_contents($passFile));

echo "🔑 Hasło do klucza wczytane (długość: " . strlen($keyPassword) . " znaków)\n\n";

try {
    echo "⏳ Konwertuję...\n";
    
    // Utwórz obiekt certyfikatu z .crt + .key
    $certificateObject = CertificateFactory::makeFromPkcs8(
        $certificate, 
        $privateKey, 
        $keyPassword
    );
    
    // Konwertuj do .p12
    $p12Content = (new ConvertCertificateToPkcs12Handler())->handle(
        new ConvertCertificateToPkcs12Action(
            certificate: $certificateObject,
            passphrase: $p12Password
        )
    );
    
    // Zapisz plik .p12
    if (file_put_contents($outputFile, $p12Content) === false) {
        throw new Exception("Nie można zapisać pliku: $outputFile");
    }
    
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "✅ SUKCES!\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📁 Plik .p12 zapisany: $outputFile\n";
    echo "🔑 Hasło do .p12: $p12Password\n";
    echo "\n";
    echo "Teraz możesz użyć tego pliku w bibliotece:\n";
    echo "─────────────────────────────────────────\n";
    echo "\$client = (new ClientBuilder())\n";
    echo "    ->withCertificatePath('$outputFile', '$p12Password')\n";
    echo "    ->withIdentifier('TWÓJ_NIP')\n";
    echo "    ->withMode(Mode::Demo)\n";
    echo "    ->build();\n";
    
} catch (Exception $e) {
    echo "\n❌ BŁĄD: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}