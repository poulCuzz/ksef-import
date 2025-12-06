<?php
// decrypt_export_file.php
//
// Ustaw tylko tę jedną zmienną poniżej:

$inputPath = __DIR__ . '/';   // <<< TU WPISZ RĘCZNIE PLIK .aes

// ------------------------------------------------------------
// Dalej już NIC nie zmieniasz
// ------------------------------------------------------------

// 1. Sprawdzenie pliku AES
if (!file_exists($inputPath)) {
    die("❌ Plik AES nie istnieje: {$inputPath}\n");
}

// 2. Wczytywanie kluczy z last_export_encryption.json
$encFile = __DIR__ . '/last_export_encryption.json';

if (!file_exists($encFile)) {
    die("❌ Brak pliku last_export_encryption.json – musisz wykonać eksport (generateEncryptionData).\n");
}

$encJson = file_get_contents($encFile);
$encData = json_decode($encJson, true);

if (!$encData || empty($encData['rawSymmetricKey']) || empty($encData['rawIV'])) {
    die("❌ Niepoprawne dane w last_export_encryption.json.\n");
}

$keyB64 = $encData['rawSymmetricKey'];
$ivB64  = $encData['rawIV'];

// 3. Dekodowanie Base64
$key = base64_decode($keyB64);
$iv  = base64_decode($ivB64);

// 4. Walidacja długości kluczy
if (strlen($key) !== 32) {
    die("❌ rawSymmetricKey ma złą długość (".strlen($key)." bajtów) — powinno być 32 dla AES-256.\n");
}
if (strlen($iv) !== 16) {
    die("❌ rawIV ma złą długość (".strlen($iv)." bajtów) — powinno być 16 dla AES IV.\n");
}

// 5. Wczytanie zaszyfrowanego pliku
$ciphertext = file_get_contents($inputPath);
if ($ciphertext === false) {
    die("❌ Nie mogę odczytać pliku AES.\n");
}

// 6. Odszyfrowanie AES-256-CBC
$plaintext = openssl_decrypt(
    $ciphertext,
    'aes-256-cbc',
    $key,
    OPENSSL_RAW_DATA,
    $iv
);

if ($plaintext === false) {
    die("❌ Błąd odszyfrowania — najczęściej zły klucz/IV lub plik AES nie z tej sesji eksportu.\n");
}

// 7. Wyznaczenie nazwy ZIP
$outputPath = preg_replace('/\.aes$/', '', $inputPath) . '.zip';

// 8. Zapis pliku ZIP
if (file_put_contents($outputPath, $plaintext) === false) {
    die("❌ Nie udało się zapisać pliku ZIP: {$outputPath}\n");
}

// 9. Informacja końcowa
echo "✅ Odszyfrowano poprawnie!\n";
echo "AES  →  {$inputPath}\n";
echo "ZIP  →  {$outputPath}\n";
