<?php
require __DIR__ . '/../vendor/autoload.php';

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\File\X509;

function encryptToken(string $token, string $publicKeyPath, int $timestamp): string 
{
    try {
        // Wczytaj certyfikat
        $certContent = file_get_contents($publicKeyPath);
        
        if ($certContent === false) {
            throw new Exception("Nie można wczytać certyfikatu");
        }
        
        // Wyekstraktuj klucz publiczny z certyfikatu (JAK W BIBLIOTECE)
        $x509 = new X509();
        $cert = $x509->loadX509($certContent);
        $publicKey = $x509->getPublicKey();
        
        if (!$publicKey) {
            throw new Exception("Nie można wyekstraktować klucza publicznego");
        }
        
        // Szyfrowanie RSA-OAEP z SHA-256 (DOKŁADNIE JAK W BIBLIOTECE)
        $data = "{$token}|{$timestamp}";
        
        /** @var string|false $encrypted */
        $encrypted = $publicKey
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->encrypt($data);
        
        if ($encrypted === false) {
            throw new Exception('Unable to encrypt token');
        }
        
        return base64_encode($encrypted);
        
    } catch (Exception $e) {
        die("❌ Błąd szyfrowania: " . $e->getMessage() . "\n");
    }
}
?>