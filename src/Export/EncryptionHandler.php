<?php

namespace KSeF\Export;

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

class EncryptionHandler {
    private string $baseUrl;
    private ?string $publicKeyContent = null;
    
    // Katalog cache dla kluczy
    private const CACHE_DIR = __DIR__ . '/keys_cache';
    private const CACHE_TTL = 86400; // 24 godziny

    /**
     * @param string $baseUrl - np. 'https://api.ksef.mf.gov.pl/v2'
     */
    public function __construct(string $baseUrl) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function generateKeys(): array {
        $symmetricKey = random_bytes(32);
        $iv = random_bytes(16);

        // Pobierz klucz publiczny (z cache lub z API)
        $certContent = $this->getPublicKey();

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

        // Zapisz klucze do pliku (potrzebne do deszyfrowania)
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

    /**
     * Pobiera klucz publiczny - najpierw z cache, potem z API
     */
    private function getPublicKey(): string {
        if ($this->publicKeyContent !== null) {
            return $this->publicKeyContent;
        }

        // Sprawdź cache
        $cached = $this->loadFromCache();
        if ($cached !== null) {
            $this->publicKeyContent = $cached;
            return $cached;
        }

        // Pobierz z API
        $key = $this->fetchPublicKeyFromApi();
        
        // Zapisz do cache
        $this->saveToCache($key);
        
        $this->publicKeyContent = $key;
        return $key;
    }

    /**
     * Pobiera klucz publiczny z API KSeF
     */
    private function fetchPublicKeyFromApi(): string {
        $url = $this->baseUrl . '/security/public-key-certificates';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("Błąd pobierania klucza publicznego: $error");
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception("Błąd HTTP $httpCode przy pobieraniu klucza publicznego");
        }

        $data = json_decode($response, true);
        
        if (!$data) {
            throw new \Exception("Nieprawidłowa odpowiedź API: $response");
        }

        // Znajdź klucz do szyfrowania (encryption)
        $encryptionKey = $this->findEncryptionKey($data);
        
        if (!$encryptionKey) {
            throw new \Exception("Nie znaleziono klucza szyfrowania w odpowiedzi API");
        }

        return $encryptionKey;
    }

    /**
     * Znajduje klucz do szyfrowania w odpowiedzi API
     * 
     * Struktura odpowiedzi KSeF:
     * [
     *   {"certificate": "...", "usage": ["KsefTokenEncryption"], ...},
     *   {"certificate": "...", "usage": ["SymmetricKeyEncryption"], ...}
     * ]
     */
    private function findEncryptionKey(array $data): ?string {
        // API zwraca tablicę certyfikatów na najwyższym poziomie
        foreach ($data as $cert) {
            if (!is_array($cert)) {
                continue;
            }
            
            // Szukaj certyfikatu do szyfrowania klucza symetrycznego
            $usage = $cert['usage'] ?? [];
            if (in_array('SymmetricKeyEncryption', $usage)) {
                $certBase64 = $cert['certificate'] ?? null;
                if ($certBase64) {
                    // Konwertuj Base64 na format PEM
                    return $this->convertToPem($certBase64);
                }
            }
        }
        
        // Fallback: weź pierwszy certyfikat jeśli nie znaleziono SymmetricKeyEncryption
        if (!empty($data[0]['certificate'])) {
            return $this->convertToPem($data[0]['certificate']);
        }

        return null;
    }

    /**
     * Konwertuje certyfikat Base64 na format PEM
     */
    private function convertToPem(string $certBase64): string {
        // Usuń ewentualne białe znaki
        $certBase64 = trim($certBase64);
        
        // Jeśli już jest w formacie PEM, zwróć bez zmian
        if (strpos($certBase64, '-----BEGIN CERTIFICATE-----') !== false) {
            return $certBase64;
        }
        
        // Dodaj nagłówki PEM i podziel na linie po 64 znaki
        $pem = "-----BEGIN CERTIFICATE-----\n";
        $pem .= chunk_split($certBase64, 64, "\n");
        $pem .= "-----END CERTIFICATE-----";
        
        return $pem;
    }

    /**
     * Ładuje klucz z cache
     */
    private function loadFromCache(): ?string {
        $cacheFile = $this->getCacheFilePath();
        
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        // Sprawdź czy cache nie wygasł
        if (filemtime($cacheFile) < time() - self::CACHE_TTL) {
            unlink($cacheFile);
            return null;
        }
        
        $content = file_get_contents($cacheFile);
        return $content !== false ? $content : null;
    }

    /**
     * Zapisuje klucz do cache
     */
    private function saveToCache(string $key): void {
        if (!is_dir(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
        
        file_put_contents($this->getCacheFilePath(), $key);
    }

    /**
     * Generuje ścieżkę do pliku cache na podstawie środowiska
     */
    private function getCacheFilePath(): string {
        // Wyciągnij nazwę środowiska z URL
        $env = 'prod';
        if (strpos($this->baseUrl, 'api-demo') !== false) {
            $env = 'demo';
        } elseif (strpos($this->baseUrl, 'api-test') !== false) {
            $env = 'test';
        }
        
        return self::CACHE_DIR . "/public_key_{$env}.pem";
    }

    /**
     * Czyści cache kluczy (np. gdy trzeba wymusić pobranie nowego)
     */
    public static function clearCache(): void {
        if (!is_dir(self::CACHE_DIR)) {
            return;
        }
        
        foreach (glob(self::CACHE_DIR . '/*.pem') as $file) {
            unlink($file);
        }
    }
}
