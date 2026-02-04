<?php

/**
 * API.PHP - Backend dla importu faktur z KSeF
 * 
 * UWAGA: Z perspektywy KSeF API jest to "export" (wysyłanie danych),
 * ale z perspektywy aplikacji to "import" (pobieranie faktur).
 * 
 * Dostępne endpointy:
 *   POST ?action=start_import  - Rozpoczyna import (token lub certyfikat)
 *   GET  ?action=check_status  - Sprawdza status importu
 *   GET  ?action=download      - Pobiera zaszyfrowany plik ZIP
 * 
 */

require_once __DIR__ . '/vendor/autoload.php';

use KSeF\Api\Helpers;
use KSeF\Api\ErrorHandler;
use KSeF\Api\Actions\StartExportAction;
use KSeF\Api\Actions\StartImportWithCertificateAction;
use KSeF\Api\Actions\CheckStatusAction;
use KSeF\Api\Actions\DownloadAction;

header('Content-Type: application/json; charset=utf-8');

Helpers::cleanOldSessions();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'start_import':
            $authMethod = $_POST['auth_method'] ?? 'token';
            
            if ($authMethod === 'certificate') {
                
                (new KSeF\Api\Actions\StartImportWithCertificateAction())->execute();
            } else {
                // Token (stara ścieżka)
                (new KSeF\Api\Actions\StartExportAction())->execute();
            }
            break;
        
        case 'check_status':
            (new CheckStatusAction())->execute();
            break;
        
        case 'download':
            (new DownloadAction())->execute();
            break;
        
        default:
            Helpers::errorResponse('Nieznana akcja: ' . $action, 'user_error', 404);
    }
    
} catch (\Exception $e) {
    ErrorHandler::handle($e);
}
