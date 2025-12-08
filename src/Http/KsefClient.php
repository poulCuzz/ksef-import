<?php

namespace KSeF\Http;

use KSeF\Logger\LoggerInterface;

class KsefClient {
    
    private string $baseUrl;
    private LoggerInterface $logger;
    private int $timeout = 30;
    
    public function __construct(LoggerInterface $logger, string $baseUrl = '') {
        $this->logger = $logger;
        $this->baseUrl = $baseUrl;
    }
    
    /**
     * Ustawia bazowy URL
     */
    public function setBaseUrl(string $baseUrl): void {
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    /**
     * Pobiera bazowy URL
     */
    public function getBaseUrl(): string {
        return $this->baseUrl;
    }
    
    /**
     * Wysyła żądanie POST
     * 
     * @param string $url Pełny URL lub relatywny (jeśli jest baseUrl)
     * @param array $data Dane do wysłania (zostanie zamienione na JSON)
     * @param array $headers Dodatkowe nagłówki
     * @return array Odpowiedź zdekodowana z JSON
     */
    public function post(string $url, array $data = [], array $headers = []): array {
        $fullUrl = $this->buildUrl($url);
        $jsonData = json_encode($data);
        
        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        $allHeaders = array_merge($defaultHeaders, $headers);
        
        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            $this->logger->error('cURL error', [
                'url' => $fullUrl,
                'error' => $error
            ]);
            throw new \RuntimeException("Błąd cURL: $error");
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('HTTP error', [
                'url' => $fullUrl,
                'httpCode' => $httpCode,
                'response' => $decoded
            ]);
            
            $errorMsg = $decoded['exception']['exceptionDescription'] ?? "HTTP error $httpCode";
            throw new \RuntimeException($errorMsg);
        }
        
        return $decoded ?? [];
    }
    
    /**
     * Wysyła żądanie GET
     * 
     * @param string $url Pełny URL lub relatywny
     * @param array $headers Dodatkowe nagłówki
     * @return array Odpowiedź zdekodowana z JSON
     */
    public function get(string $url, array $headers = []): array {
        $fullUrl = $this->buildUrl($url);
        
        $defaultHeaders = [
            'Accept: application/json'
        ];
        
        $allHeaders = array_merge($defaultHeaders, $headers);
        
        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            $this->logger->error('cURL error', [
                'url' => $fullUrl,
                'error' => $error
            ]);
            throw new \RuntimeException("Błąd cURL: $error");
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('HTTP error', [
                'url' => $fullUrl,
                'httpCode' => $httpCode,
                'response' => $decoded
            ]);
            
            $errorMsg = $decoded['exception']['exceptionDescription'] ?? "HTTP error $httpCode";
            throw new \RuntimeException($errorMsg);
        }
        
        return $decoded ?? [];
    }
    
    /**
     * Pobiera plik (zwraca raw data, nie JSON)
     * 
     * @param string $url Pełny URL
     * @return string Raw content
     */
    public function downloadFile(string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120 // Dłuższy timeout dla dużych plików
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            $this->logger->error('Download error', [
                'url' => $url,
                'error' => $error
            ]);
            throw new \RuntimeException("Błąd pobierania pliku: $error");
        }
        
        if ($httpCode !== 200) {
            $this->logger->error('Download HTTP error', [
                'url' => $url,
                'httpCode' => $httpCode
            ]);
            throw new \RuntimeException("Błąd HTTP $httpCode podczas pobierania pliku");
        }
        
        return $response;
    }
    
    /**
     * Buduje pełny URL
     */
    private function buildUrl(string $url): string {
        // Jeśli URL jest już pełny (zaczyna się od http)
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }
        
        // Jeśli nie ma baseUrl
        if (empty($this->baseUrl)) {
            throw new \RuntimeException("BaseUrl not set and URL is not absolute: $url");
        }
        
        // Połącz baseUrl z relatywnym URL
        return $this->baseUrl . '/' . ltrim($url, '/');
    }
}