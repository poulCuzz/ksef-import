<?php

require __DIR__ . '/../vendor/autoload.php';

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

function encryptTokenData(string $token, string $publicKeyPath, int $timestamp): string
{
    $certContent = file_get_contents($publicKeyPath);
    
    if ($certContent === false) {
        throw new Exception("Nie można wczytać certyfikatu");
    }
    
    $x509 = new X509();
    $x509->loadX509($certContent);
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
?>