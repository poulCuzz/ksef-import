<?php

namespace KSeF\Api\Actions;

use KSeF\Auth\CertificateAuthenticator;
use KSeF\Export\KsefExporter;
use KSeF\Api\Helpers;
use Exception;

/**
 * Akcja importu faktur z KSeF przy użyciu certyfikatu (.crt + .key)
 */
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
            $keyPassword = $_POST['key_password'] ?? '';
            $dateFrom = $_POST['date_from'];
            $dateTo = $_POST['date_to'];
            $subjectType = $_POST['subject_type'] ?? 'Subject1';

            // 3. Walidacja uploadowanych plików
            if (!isset($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Błąd uploadu certyfikatu (.crt)');
            }

            if (!isset($_FILES['private_key']) || $_FILES['private_key']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Błąd uploadu klucza prywatnego (.key)');
            }

            // 4. Utwórz katalog sesji
            $sessionId = uniqid('cert_', true);
            $sessionDir = dirname(__DIR__, 3) . '/sessions/' . $sessionId;
            
            if (!is_dir($sessionDir)) {
                mkdir($sessionDir, 0755, true);
            }

            // 5. Zapisz uploadowane pliki
            $certPath = $sessionDir . '/certificate.crt';
            $keyPath = $sessionDir . '/private_key.key';

            move_uploaded_file($_FILES['certificate']['tmp_name'], $certPath);
            move_uploaded_file($_FILES['private_key']['tmp_name'], $keyPath);

            // 6. Uwierzytelnij certyfikatem (XAdES)
            $authenticator = new CertificateAuthenticator($env);
            $authData = $authenticator->authenticate(
                $certPath,
                $keyPath,
                $keyPassword,
                $nip
            );

            $accessToken = $authData['accessToken'];
            $refreshToken = $authData['refreshToken'];

            // 7. Konwertuj daty do ISO 8601
            $dateFromISO = $this->convertDateToISO($dateFrom);
            $dateToISO = $this->convertDateToISO($dateTo);

            // 8. Pobierz ścieżki
            $baseUrl = $this->getBaseUrl($env);
            $publicKeyPath = dirname(__DIR__, 3) . '/public_key_symetric_encription.pem';

            // 9. Uruchom eksport faktur
            $exporter = new KsefExporter($baseUrl, $publicKeyPath);
            
            $result = $exporter->sendExportRequest(
                $accessToken,
                $subjectType,
                $dateFromISO,
                $dateToISO
            );

            $referenceNumber = $result['referenceNumber'];

            // 10. Zapisz dane sesji
            $sessionData = [
                'sessionId' => $sessionId,
                'referenceNumber' => $referenceNumber,
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
                'baseUrl' => $baseUrl,
                'rawSymmetricKey' => $result['rawSymmetricKey'] ?? null,
                'rawIV' => $result['rawIV'] ?? null,
                'env' => $env,
                'nip' => $nip,
                'auth_method' => 'certificate',
                'created_at' => date('Y-m-d H:i:s')
            ];

            file_put_contents(
                $sessionDir . '/session.json',
                json_encode($sessionData, JSON_PRETTY_PRINT)
            );

            // 11. Odpowiedź sukcesu
            Helpers::jsonResponse([
                'success' => true,
                'sessionId' => $sessionId,
                'referenceNumber' => $referenceNumber
            ]);

        } catch (Exception $e) {
            $errorType = $this->classifyError($e);
            
            Helpers::errorResponse(
                $e->getMessage(),
                $errorType,
                $errorType === 'user_error' ? 400 : 500
            );
        }
    }

    private function validateRequest(): void
    {
        $required = ['nip', 'date_from', 'date_to'];
        
        foreach ($required as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                throw new Exception("Pole {$field} jest wymagane");
            }
        }

        // Walidacja NIP
        if (!preg_match('/^\d{10}$/', $_POST['nip'])) {
            throw new Exception("NIP musi składać się z 10 cyfr");
        }

        // Walidacja zakresu dat (max 3 miesiące)
        $dateFrom = strtotime($_POST['date_from']);
        $dateTo = strtotime($_POST['date_to']);
        $threeMonths = 90 * 24 * 60 * 60; // 90 dni w sekundach

        if (($dateTo - $dateFrom) > $threeMonths) {
            throw new Exception("Zakres dat nie może przekraczać 3 miesięcy");
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
            'demo' => 'https://api-demo.ksef.mf.gov.pl',
            'test' => 'https://api-test.ksef.mf.gov.pl',
            'prod', 'production' => 'https://api-prod.ksef.mf.gov.pl',
            default => throw new Exception("Nieznane środowisko: {$environment}")
        };
    }

    private function classifyError(Exception $e): string
    {
        $message = strtolower($e->getMessage());

        // Błędy użytkownika
        $userErrors = [
            'hasło', 'password', 
            'certyfikat', 'certificate',
            'klucz', 'key',
            'nip', 
            'uprawnie', 'permission',
            'brak przypisanych',
            '21115', // Kod błędu - brak uprawnień certyfikatu
            '21117', // Nieprawidłowy identyfikator podmiotu
        ];
        
        foreach ($userErrors as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return 'user_error';
            }
        }

        return 'app_error';
    }
}
