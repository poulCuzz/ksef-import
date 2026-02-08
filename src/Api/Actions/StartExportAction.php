<?php

namespace KSeF\Api\Actions;

use KSeF\Api\Helpers;

class StartExportAction
{
    public function execute(): void {
        $env = $_POST['env'] ?? 'demo';
        $ksefToken = trim($_POST['ksef_token'] ?? '');
        $nip = preg_replace('/[^0-9]/', '', $_POST['nip'] ?? '');
        $subjectType = $_POST['subject_type'] ?? 'Subject1';
        $dateFrom = $_POST['date_from'] ?? '';
        $dateTo = $_POST['date_to'] ?? '';
        
        // Walidacja
        if (empty($ksefToken)) {
            Helpers::errorResponse('Token KSeF jest wymagany', 'user_error');
        }
        if (strlen($nip) !== 10) {
            Helpers::errorResponse('NIP musi mieć 10 cyfr', 'user_error');
        }
        if (empty($dateFrom) || empty($dateTo)) {
            Helpers::errorResponse('Daty są wymagane', 'user_error');
        }
        
        // Buduj URL
        $baseUrl = ($env === 'demo')
            ? 'https://api-demo.ksef.mf.gov.pl/v2'
            : 'https://api-test.ksef.mf.gov.pl/v2'; // Zmień na produkcyjny URL, gdy będzie dostępny
        
        // Formatuj daty
        $dateFromFormatted = $dateFrom . 'T00:00:00.000+00:00';
        $dateToFormatted = $dateTo . 'T23:59:59.999+00:00';
        
        // Utwórz serwis
        $ksef = Helpers::createKsefService($baseUrl);
        
        // Autoryzacja
        $tokens = $ksef->authenticate($nip, $ksefToken);
        
        // Export
        $exportResult = $ksef->startExport(
            $tokens['accessToken'],
            $subjectType,
            $dateFromFormatted,
            $dateToFormatted
        );
        
        if (!isset($exportResult['referenceNumber'])) {
            Helpers::errorResponse('Brak referenceNumber w odpowiedzi', 'server_error');
        }
        
        // Zapisz sesję
        $sessionId = uniqid('ksef_', true);
        
        // Pobierz klucze szyfrowania
        $encFile = dirname(__DIR__, 3) . '/src/Export/last_export_encryption.json';
        $encData = json_decode(file_get_contents($encFile), true);
        
        Helpers::saveSession($sessionId, [
            'referenceNumber' => $exportResult['referenceNumber'],
            'accessToken' => $tokens['accessToken'],
            'refreshToken' => $tokens['refreshToken'],
            'baseUrl' => $baseUrl,
            'rawSymmetricKey' => $encData['rawSymmetricKey'],
            'rawIV' => $encData['rawIV'],
            'created' => time()
        ]);
        
        Helpers::jsonResponse([
            'success' => true,
            'sessionId' => $sessionId,
            'referenceNumber' => $exportResult['referenceNumber']
        ]);
    }
}
