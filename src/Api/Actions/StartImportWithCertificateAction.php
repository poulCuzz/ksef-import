<?php

namespace KSeF\Api\Actions;

use KSeF\Api\Helpers;
use KSeF\Auth\CertificateAuthenticator;
use KSeF\Export\KsefExporter;
use KSeF\Export\FileDecryptor;
use KSeF\Logger\FileLogger;
use KSeF\Http\CurlHttpClient;
use Exception;

/**
 * Akcja: Start importu z uwierzytelnianiem certyfikatem
 */
class StartImportWithCertificateAction
{
    public function execute(): void
    {
        // Walidacja metody
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Helpers::errorResponse('Metoda musi być POST', 'user_error', 405);
        }

        // Walidacja pól formularza
        $nip = $_POST['nip'] ?? '';
        $env = $_POST['env'] ?? 'demo';
        $subjectType = $_POST['subject_type'] ?? 'Subject1';
        $dateFrom = $_POST['date_from'] ?? '';
        $dateTo = $_POST['date_to'] ?? '';
        $keyPassword = $_POST['key_password'] ?? '';

        // Walidacja wymaganych pól tekstowych
        if (empty($nip)) {
            Helpers::errorResponse('Brak NIP', 'user_error', 400, [
                'Pole NIP jest wymagane'
            ]);
        }

        if (empty($keyPassword)) {
            Helpers::errorResponse('Brak hasła do klucza prywatnego', 'user_error', 400, [
                'Hasło jest wymagane do odszyfrowania klucza prywatnego'
            ]);
        }

        // Walidacja uploadowanych plików
        if (!isset($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
            Helpers::errorResponse('Brak pliku certyfikatu', 'user_error', 400, [
                'Upewnij się, że wybrałeś plik certyfikatu (.crt lub .pem)',
                'Maksymalny rozmiar pliku: ' . ini_get('upload_max_filesize')
            ]);
        }

        if (!isset($_FILES['private_key']) || $_FILES['private_key']['error'] !== UPLOAD_ERR_OK) {
            Helpers::errorResponse('Brak pliku klucza prywatnego', 'user_error', 400, [
                'Upewnij się, że wybrałeś plik klucza (.key lub .pem)',
                'Maksymalny rozmiar pliku: ' . ini_get('upload_max_filesize')
            ]);
        }

        // Zapisz pliki tymczasowe
        $sessionId = Helpers::generateSessionId();
        $sessionDir = __DIR__ . '/../../sessions/' . $sessionId;
        
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0755, true);
        }

        $certPath = $sessionDir . '/certificate.crt';
        $keyPath = $sessionDir . '/private_key.key';

        try {
            // Przenieś uploadowane pliki
            if (!move_uploaded_file($_FILES['certificate']['tmp_name'], $certPath)) {
                throw new Exception('Nie udało się zapisać pliku certyfikatu');
            }

            if (!move_uploaded_file($_FILES['private_key']['tmp_name'], $keyPath)) {
                throw new Exception('Nie udało się zapisać pliku klucza prywatnego');
            }

            // KROK 1: Uwierzytelnienie certyfikatem
            $authenticator = new CertificateAuthenticator($env);
            
            $authData = $authenticator->authenticate(
                $certPath,
                $keyPath,
                $keyPassword,
                $nip
            );

            $accessToken = $authData['accessToken'];
            $refreshToken = $authData['refreshToken'] ?? null;

            // KROK 2: Konwertuj daty do formatu ISO 8601 z timezone
            $dateFromISO = date('Y-m-d\T00:00:00.000+00:00', strtotime($dateFrom));
            $dateToISO = date('Y-m-d\T23:59:59.999+00:00', strtotime($dateTo));

            // KROK 3: Rozpocznij eksport (import z perspektywy aplikacji)
            $httpClient = new CurlHttpClient();
            $logger = new FileLogger(__DIR__ . '/../../logs/ksef.log');
            
            $exporter = new KsefExporter($httpClient, $logger, $env);
            
            $exportResult = $exporter->sendExportRequest(
                $accessToken,
                $subjectType,
                $dateFromISO,
                $dateToISO
            );

            if (!$exportResult || !isset($exportResult['referenceNumber'])) {
                throw new Exception('Nie otrzymano referenceNumber z KSeF');
            }

            // KROK 4: Zapisz dane sesji
            $sessionData = [
                'sessionId' => $sessionId,
                'referenceNumber' => $exportResult['referenceNumber'],
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
                'nip' => $nip,
                'env' => $env,
                'authMethod' => 'certificate',
                'startedAt' => date('Y-m-d H:i:s'),
                'encryption' => $exportResult['encryption'] ?? null
            ];

            file_put_contents(
                $sessionDir . '/session.json',
                json_encode($sessionData, JSON_PRETTY_PRINT)
            );

            // KROK 5: Zwróć sukces
            Helpers::successResponse([
                'sessionId' => $sessionId,
                'referenceNumber' => $exportResult['referenceNumber'],
                'message' => 'Import rozpoczęty pomyślnie (uwierzytelnienie certyfikatem)'
            ]);

        } catch (Exception $e) {
            // Usuń sesję w przypadku błędu
            if (isset($sessionDir) && is_dir($sessionDir)) {
                array_map('unlink', glob($sessionDir . '/*'));
                rmdir($sessionDir);
            }

            // Klasyfikacja błędu
            $errorMessage = $e->getMessage();
            
            // Błędy użytkownika (hasło, certyfikat)
            if (
                stripos($errorMessage, 'password') !== false ||
                stripos($errorMessage, 'incorrect') !== false ||
                stripos($errorMessage, 'decrypt') !== false
            ) {
                Helpers::errorResponse(
                    'Nieprawidłowe hasło do klucza prywatnego',
                    'user_error',
                    400,
                    [
                        'Sprawdź czy hasło zostało wpisane poprawnie',
                        'Upewnij się że to hasło pasuje do tego klucza prywatnego'
                    ]
                );
            }

            if (
                stripos($errorMessage, 'certificate') !== false ||
                stripos($errorMessage, 'cert') !== false
            ) {
                Helpers::errorResponse(
                    'Błąd w pliku certyfikatu lub klucza',
                    'user_error',
                    400,
                    [
                        'Upewnij się że wybrałeś poprawny certyfikat (.crt)',
                        'Upewnij się że wybrałeś poprawny klucz prywatny (.key)',
                        'Sprawdź czy pliki nie są uszkodzone'
                    ]
                );
            }

            // Błąd ogólny
            Helpers::errorResponse(
                $errorMessage,
                'app_error',
                500,
                ['Sprawdź logi aplikacji dla szczegółów']
            );
        }
    }
}
