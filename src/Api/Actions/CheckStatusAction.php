<?php

namespace KSeF\Api\Actions;

use KSeF\Api\Helpers;

class CheckStatusAction
{
    public function execute(): void {
        $sessionId = $_GET['session'] ?? '';
        
        if (empty($sessionId)) {
            Helpers::errorResponse('Brak sessionId', 'user_error');
        }
        
        $session = Helpers::loadSession($sessionId);
        if (empty($session['accessToken']) || empty($session['referenceNumber'])) {
            Helpers::errorResponse('Niekompletna sesja – brak tokenu lub numeru referencyjnego', 'app_error');
        }
        if (!$session) {
            Helpers::errorResponse('Sesja wygasła lub nie istnieje', 'user_error');
        }
        
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
}