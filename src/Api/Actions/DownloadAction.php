<?php

namespace KSeF\Api\Actions;

use KSeF\Api\Helpers;

class DownloadAction
{
    public function execute(): void {
        $sessionId = $_GET['session'] ?? '';
        $partIndex = (int)($_GET['part'] ?? 0);
        
        if (empty($sessionId)) {
            Helpers::errorResponse('Brak sessionId', 'user_error');
        }
        
        $session = Helpers::loadSession($sessionId);
        if (!$session) {
            Helpers::errorResponse('Sesja wygasła lub nie istnieje', 'user_error');
        }
        
        if (!isset($session['downloadLinks'][$partIndex])) {
            Helpers::errorResponse('Nieprawidłowy indeks pliku', 'user_error');
        }
        
        $ksef = Helpers::createKsefService($session['baseUrl']);
        
        $zipData = $ksef->downloadAndDecrypt(
            $session['downloadLinks'][$partIndex],
            $session['rawSymmetricKey'],
            $session['rawIV']
        );
        
        // Wyślij plik
        $filename = 'faktury_ksef_part' . ($partIndex + 1) . '_' . date('Y-m-d_H-i-s') . '.zip';
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($zipData));
        
        echo $zipData;
        exit;
    }
}