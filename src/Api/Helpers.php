<?php

namespace KSeF\Api;

use KSeF\KsefService;
use KSeF\Auth\KsefAuthenticator;
use KSeF\Auth\TokenEncryptor;
use KSeF\Export\KsefExporter;
use KSeF\Export\FileDecryptor;
use KSeF\Http\KsefClient;
use KSeF\Logger\JsonLogger;

class Helpers
{
    // ========================================================================
    // INICJALIZACJA SERWISÓW
    // ========================================================================

    public static function createKsefService(string $baseUrl): KsefService {
        $baseDir = dirname(__DIR__, 2);
        $logger = new JsonLogger($baseDir . '/logs');
        $client = new KsefClient($logger, $baseUrl);
        
        $authPublicKey = $baseDir . '/src/Auth/public_key.pem';
        $exportPublicKey = $baseDir . '/src/Export/public_key_symetric_encription.pem';
        
        $authenticator = new KsefAuthenticator($client, $logger, $authPublicKey);
        $encryptor = new TokenEncryptor($authPublicKey);
        $exporter = new KsefExporter($baseUrl, $exportPublicKey);
        $decryptor = new FileDecryptor();
        
        return new KsefService($authenticator, $encryptor, $exporter, $decryptor, $logger);
    }

    // ========================================================================
    // OBSŁUGA SESJI
    // ========================================================================

    public static function saveSession(string $sessionId, array $data): void {
        $tempDir = dirname(__DIR__, 3) . '/temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        file_put_contents("$tempDir/session_$sessionId.json", json_encode($data));
    }

    public static function loadSession(string $sessionId): ?array {
        $file = dirname(__DIR__, 3) . "/temp/session_$sessionId.json";
        if (!file_exists($file)) {
            return null;
        }
        return json_decode(file_get_contents($file), true);
    }

    public static function cleanOldSessions(): void {
        $tempDir = dirname(__DIR__, 3) . '/temp';
        if (!is_dir($tempDir)) return;
        
        foreach (glob("$tempDir/session_*.json") as $file) {
            if (filemtime($file) < time() - 3600) {
                unlink($file);
            }
        }
    }

    // ========================================================================
    // ODPOWIEDZI
    // ========================================================================

    public static function jsonResponse(array $data, int $code = 200): void {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function errorResponse(string $message, string $errorType = 'error', int $code = 400): void {
        self::jsonResponse([
            'success' => false,
            'errorType' => $errorType,
            'message' => $message
        ], $code);
    }
}
