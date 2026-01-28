<?php

namespace KSeF\Api\Actions;

use KSeF\Auth\CertificateAuthenticator;
use KSeF\Export\KsefExporter;
use Exception;

class StartImportWithCertificateAction
{
    public function execute(): void
    {
        try {
            // 1. Walidacja POST
            $this->validateRequest();

            // 2. Pobierz dane z formularza
            $nip = $_POST['nip'];
            $env = $_POST['env'] ?? 'demo';
            $password = $_POST['key_password'];
            $dateFrom = $_POST['date_from'];
            $dateTo = $_POST['date_to'];
            $subjectType = $_POST['subject_type'] ?? 'Subject1';

            // 3. Walidacja uploadowanych plików
            if (!isset($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Błąd uploadu certyfikatu');
            }

            if (!isset($_FILES['private_key']) || $_FILES['private_key']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Błąd uploadu klucza prywatnego');
            }

            // 4. Utwórz katalog sesji
            $sessionId = uniqid('cert_', true);
            // Katalog na pliki certyfikatu
            $certDir = dirname(__DIR__, 3) . '/temp/cert_' . $sessionId;
            if (!is_dir($certDir)) {
                mkdir($certDir, 0755, true);
            }

            // 5. Zapisz pliki tymczasowo
            $certPath = $certDir . '/certificate.crt';
            $keyPath = $certDir . '/private_key.key';

            move_uploaded_file($_FILES['certificate']['tmp_name'], $certPath);
            move_uploaded_file($_FILES['private_key']['tmp_name'], $keyPath);

            // 6. Uwierzytelnij certyfikatem
            $authenticator = new CertificateAuthenticator($env);
            $authData = $authenticator->authenticate(
                $certPath,
                $keyPath,
                $password,
                $nip
            );

            $accessToken = $authData['accessToken'];

            // 7. Konwertuj daty do ISO 8601
            $dateFromISO = $this->convertDateToISO($dateFrom);
            $dateToISO = $this->convertDateToISO($dateTo);

            // 8. Przygotuj ścieżki
            $baseUrl = $this->getBaseUrl($env);
            $publicKeyPath = dirname(__DIR__, 2) . '/Export/public_key_symetric_encription.pem';

            // 9. Uruchom eksport
            $exporter = new KsefExporter($baseUrl, $publicKeyPath);
            
            $result = $exporter->sendExportRequest(
                $accessToken,      // 1. accessToken
                $subjectType,      // 2. subjectType
                $dateFromISO,      // 3. dateFrom
                $dateToISO         // 4. dateTo
            );

            $referenceNumber = $result['referenceNumber'];

            // 10. Pobierz klucze szyfrowania
            $encFile = dirname(__DIR__, 2) . '/Export/last_export_encryption.json';
            $encData = json_decode(file_get_contents($encFile), true);

            // 11. Zapisz dane sesji
            $sessionData = [
                'sessionId' => $sessionId,
                'referenceNumber' => $referenceNumber,
                'accessToken' => $accessToken,
                'baseUrl' => $baseUrl,
                'rawSymmetricKey' => $encData['rawSymmetricKey'],
                'rawIV' => $encData['rawIV'],
                'auth_method' => 'certificate',
                'created_at' => date('Y-m-d H:i:s')
            ];

            // Zapisz sesję przez Helpers (kompatybilne z CheckStatus i Download)
            \KSeF\Api\Helpers::saveSession($sessionId, $sessionData);

            // 11. Odpowiedź sukcesu
            echo json_encode([
                'success' => true,
                'sessionId' => $sessionId,
                'referenceNumber' => $referenceNumber
            ]);

        } catch (Exception $e) {
            // Klasyfikuj błędy
            $errorType = $this->classifyError($e);
            
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => $errorType
            ]);
        }
    }

    private function validateRequest(): void
    {
        $required = ['nip', 'key_password', 'date_from', 'date_to'];
        
        foreach ($required as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                throw new Exception("Pole {$field} jest wymagane");
            }
        }
    }

    private function convertDateToISO(string $date): string
    {
        $timestamp = strtotime($date);
        return date('Y-m-d\TH:i:s.000+00:00', $timestamp);
    }

    private function getBaseUrl(string $environment): string
    {
        return match (strtolower($environment)) {
            'demo' => 'https://ksef-demo.mf.gov.pl',
            'test' => 'https://ksef-test.mf.gov.pl',
            'prod', 'production' => 'https://ksef.mf.gov.pl',
            default => throw new Exception("Nieznane środowisko: {$environment}")
        };
    }

    private function classifyError(Exception $e): string
    {
        $message = $e->getMessage();

        // Błędy użytkownika
        if (stripos($message, 'hasło') !== false ||
            stripos($message, 'password') !== false) {
            return 'user_error';
        }

        if (stripos($message, 'certyfikat') !== false ||
            stripos($message, 'certificate') !== false) {
            return 'user_error';
        }

        // Błędy aplikacji
        return 'app_error';
    }
}