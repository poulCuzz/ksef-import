<?php

namespace KSeF\Api\Actions;

use KSeF\Api\Helpers;
use N1ebieski\KSEFClient\ClientBuilder;
use N1ebieski\KSEFClient\ValueObjects\Mode;
use N1ebieski\KSEFClient\ValueObjects\EncryptionKey;
use Exception;

class CheckStatusAction
{
    public function execute(): void
    {
        $sessionId = $_GET['session'] ?? '';
        
        if (empty($sessionId)) {
            Helpers::errorResponse('Brak sessionId', 'user_error');
        }
        
        $session = Helpers::loadSession($sessionId);
        if (!$session) {
            Helpers::errorResponse('Sesja wygasła lub nie istnieje', 'user_error');
        }
        
        // Sprawdź metodę uwierzytelniania
        $authMethod = $session['auth_method'] ?? 'token';
        
        if ($authMethod === 'certificate') {
            $this->checkStatusWithCertificate($session, $sessionId);
        } else {
            $this->checkStatusWithToken($session, $sessionId);
        }
    }
    
    /**
     * Sprawdzanie statusu dla uwierzytelniania certyfikatem (biblioteka n1ebieski)
     */
    private function checkStatusWithCertificate(array $session, string $sessionId): void
    {
        try {
            // Odtwórz klucz szyfrowania z sesji
            $encryptionKey = unserialize(base64_decode($session['encryptionKey']));
            
            // Utwórz klienta z biblioteką n1ebieski
            $client = (new ClientBuilder())
                ->withMode($this->getMode($session['env']))
                ->withCertificatePath($session['p12Path'], $session['p12Password'])
                ->withIdentifier($session['nip'])
                ->withEncryptionKey($encryptionKey)
                ->withValidateXml(false)
                ->build();
            
            // Sprawdź status eksportu
            $statusResponse = $client->invoices()->exports()->status(
                $session['referenceNumber']
            )->object();
            
            $statusCode = $statusResponse->status->code ?? 0;
            $isReady = ($statusCode === 200);
            
            $downloadLinks = [];
            if ($isReady && isset($statusResponse->package->parts)) {
                foreach ($statusResponse->package->parts as $part) {
                    $downloadLinks[] = $part->url;
                }
                
                // Zapisz linki do sesji
                $session['downloadLinks'] = $downloadLinks;
                
                // Zapisz rawSymmetricKey i rawIV do deszyfrowania
                $session['rawSymmetricKey'] = base64_encode($encryptionKey->getKey());
                $session['rawIV'] = base64_encode($encryptionKey->getIv());
                
                Helpers::saveSession($sessionId, $session);
            }
            
            Helpers::jsonResponse([
                'success' => true,
                'statusCode' => $statusCode,
                'statusDesc' => $statusResponse->status->description ?? ($isReady ? 'Import gotowy' : 'Przetwarzanie'),
                'ready' => $isReady,
                'filesCount' => count($downloadLinks),
                'message' => $isReady ? 'Pliki gotowe do pobrania' : 'Oczekiwanie na zakończenie importu...'
            ]);
            
        } catch (Exception $e) {
            Helpers::errorResponse('Błąd sprawdzania statusu: ' . $e->getMessage(), 'app_error');
        }
    }
    
    /**
     * Sprawdzanie statusu dla uwierzytelniania tokenem (stary KsefService)
     */
    private function checkStatusWithToken(array $session, string $sessionId): void
    {
        $ksef = Helpers::createKsefService($session['baseUrl']);
        
        $status = $ksef->checkStatus(
            $session['accessToken'],
            $session['referenceNumber']
        );
        
        $statusCode = $status['status']['code'] ?? 0;
        $isReady = ($statusCode === 200);
        
        $downloadLinks = [];
        if ($isReady && !empty($status['package']['parts'])) {
            $downloadLinks = array_map(fn($p) => $p['url'], $status['package']['parts']);
            
            // Zaktualizuj sesję z linkami
            $session['downloadLinks'] = $downloadLinks;
            Helpers::saveSession($sessionId, $session);
        }
        
        Helpers::jsonResponse([
            'success' => true,
            'statusCode' => $statusCode,
            'statusDesc' => $isReady ? 'Import gotowy' : 'Przetwarzanie',
            'ready' => $isReady,
            'filesCount' => count($downloadLinks),
            'message' => $isReady ? 'Pliki gotowe do pobrania' : 'Oczekiwanie na zakończenie importu...'
        ]);
    }
    
    private function getMode(string $env): Mode
    {
        return match (strtolower($env)) {
            'demo' => Mode::Demo,
            'test' => Mode::Test,
            'prod', 'production' => Mode::Production,
            default => Mode::Demo
        };
    }
}
