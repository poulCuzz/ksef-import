<?php

namespace KSeF\Logger;

class JsonLogger implements LoggerInterface {
    
    private string $logDir;
    private string $logFile;
    
    public function __construct(string $logDir = null) {
        $this->logDir = $logDir ?? __DIR__ . '/../../logs';
        
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        
        $this->logFile = $this->logDir . '/ksef_' . date('Y-m-d') . '.log';
    }
    
    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }
    
    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->log('WARNING', $message, $context);
    }
    
    public function logChallenge(string $url, array $request, array $response, int $httpCode): void {
        $this->log('CHALLENGE', 'Challenge request', [
            'url' => $url,
            'request' => $request,
            'response' => $response,
            'httpCode' => $httpCode
        ]);
    }
    
    public function logTokenEncryption(string $token, int $timestamp, bool $success): void {
        $this->log('TOKEN_ENCRYPTION', 'Token encrypted', [
            'tokenLength' => strlen($token),
            'timestamp' => $timestamp,
            'success' => $success
        ]);
    }
    
    public function logAuthenticationToken(string $url, array $request, array $response, int $httpCode): void {
        $this->log('AUTH_TOKEN', 'Authentication token received', [
            'url' => $url,
            'request' => $request,
            'response' => $response,
            'httpCode' => $httpCode
        ]);
    }
    
    public function logAccessToken(string $url, array $response, int $httpCode): void {
        $this->log('ACCESS_TOKEN', 'Access token received', [
            'url' => $url,
            'response' => $response,
            'httpCode' => $httpCode
        ]);
    }
    
    public function logRefreshToken(string $url, array $response, int $httpCode): void {
        $this->log('REFRESH_TOKEN', 'Token refreshed', [
            'url' => $url,
            'response' => $response,
            'httpCode' => $httpCode
        ]);
    }
    
    public function logExportRequest(string $url, array $payload, array $response, int $httpCode): void {
        $this->log('EXPORT_REQUEST', 'Export request sent', [
            'url' => $url,
            'payload' => $payload,
            'response' => $response,
            'httpCode' => $httpCode
        ]);
    }
    
    public function logSessionStart(string $environment, string $nip): void {
        $this->log('SESSION_START', 'Export session started', [
            'environment' => $environment,
            'nip' => $nip
        ]);
    }
    
    public function logSessionEnd(int $filesCount, bool $success): void {
        $this->log('SESSION_END', 'Export session ended', [
            'filesCount' => $filesCount,
            'success' => $success
        ]);
    }
    
    public function logAesDownload(string $url, int $size, int $httpCode): void {
        $this->log('AES_DOWNLOAD', 'AES file downloaded', [
            'url' => $url,
            'size' => $size,
            'httpCode' => $httpCode
        ]);
    }
    
    public function logDecryption(int $encryptedSize, int $decryptedSize, bool $success): void {
        $this->log('DECRYPTION', 'File decrypted', [
            'encryptedSize' => $encryptedSize,
            'decryptedSize' => $decryptedSize,
            'success' => $success
        ]);
    }
    
    /**
     * Główna metoda logowania
     */
    private function log(string $level, string $message, array $context = []): void {
        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
        
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
}
