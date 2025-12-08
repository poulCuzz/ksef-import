<?php

namespace KSeF\Export;

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

class EncryptionHandler {
    private string $publicKeyPath;

    public function __construct(string $publicKeyPath) {
        $this->publicKeyPath = $publicKeyPath;
    }

    public function generateKeys(): array {
        $symmetricKey = random_bytes(32);
        $iv = random_bytes(16);

        $certContent = file_get_contents($this->publicKeyPath);
        if ($certContent === false) {
            throw new \Exception("Nie można wczytać certyfikatu MF");
        }

        $x509 = new X509();
        $x509->loadX509($certContent);
        $publicKey = $x509->getPublicKey();

        if (!$publicKey) {
            throw new \Exception("Nie można wyciągnąć klucza publicznego z certyfikatu MF");
        }

        $encryptedKey = $publicKey
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->encrypt($symmetricKey);

        if ($encryptedKey === false) {
            throw new \Exception("Błąd szyfrowania klucza AES");
        }

        // Zapisz klucze do pliku
        $filePath = __DIR__ . '/last_export_encryption.json';
        $payload = [
            'generatedAt'     => date('c'),
            'rawSymmetricKey' => base64_encode($symmetricKey),
            'rawIV'           => base64_encode($iv),
        ];
        file_put_contents($filePath, json_encode($payload, JSON_PRETTY_PRINT));

        return [
            "encryptedSymmetricKey" => base64_encode($encryptedKey),
            "initializationVector"  => base64_encode($iv),
            "rawSymmetricKey"       => base64_encode($symmetricKey),
            "rawIV"                 => base64_encode($iv)
        ];
    }
}