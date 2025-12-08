<?php

namespace KSeF\Logger;

interface LoggerInterface {
    
    /**
     * Loguje informację
     */
    public function info(string $message, array $context = []): void;
    
    /**
     * Loguje błąd
     */
    public function error(string $message, array $context = []): void;
    
    /**
     * Loguje ostrzeżenie
     */
    public function warning(string $message, array $context = []): void;
    
    /**
     * Loguje challenge
     */
    public function logChallenge(string $url, array $request, array $response, int $httpCode): void;
    
    /**
     * Loguje szyfrowanie tokena
     */
    public function logTokenEncryption(string $token, int $timestamp, bool $success): void;
    
    /**
     * Loguje authentication token
     */
    public function logAuthenticationToken(string $url, array $request, array $response, int $httpCode): void;
    
    /**
     * Loguje access token
     */
    public function logAccessToken(string $url, array $response, int $httpCode): void;
    
    /**
     * Loguje refresh token
     */
    public function logRefreshToken(string $url, array $response, int $httpCode): void;
    
    /**
     * Loguje żądanie eksportu
     */
    public function logExportRequest(string $url, array $payload, array $response, int $httpCode): void;
    
    /**
     * Loguje początek sesji
     */
    public function logSessionStart(string $environment, string $nip): void;
    
    /**
     * Loguje koniec sesji
     */
    public function logSessionEnd(int $filesCount, bool $success): void;
    
    /**
     * Loguje pobranie pliku AES
     */
    public function logAesDownload(string $url, int $size, int $httpCode): void;
    
    /**
     * Loguje deszyfrowanie
     */
    public function logDecryption(int $encryptedSize, int $decryptedSize, bool $success): void;
}