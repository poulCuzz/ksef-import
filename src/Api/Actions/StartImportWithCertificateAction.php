<?php

namespace KSeF\Api\Actions;

use KSeF\Api\Helpers;
use KSeF\Auth\CertificateAuthenticator;
use KSeF\Auth\KsefAuthenticator;
use KSeF\Export\KsefExporter;
use KSeF\Http\KsefClient;
use KSeF\Logger\JsonLogger;
use Exception;

/**
 * Akcja importu faktur z uwierzytelnianiem certyfikatem (.crt + .key)
 * Pliki certyfikatu są uploadowane przez użytkownika w formularzu.
 * 
 * Flow:
 * 1. Zapisz uploadowane pliki .crt i .key do temp/
 * 2. CertificateAuthenticator->authenticate() → authenticationToken [NOWY KOD]
 * 3. KsefAuthenticator->getAccessToken()      → accessToken         [ISTNIEJĄCY]
 * 4. KsefAuthenticator->refreshAccessToken()  → odświeżony token    [ISTNIEJĄCY]
 * 5. KsefExporter->sendExportRequest()        → referenceNumber     [ISTNIEJĄCY]
 * 6. Usuń tymczasowe pliki certyfikatu
 */
class StartImportWithCertificateAction
{
    private ?string $tempCertPath = null;
    private ?string $tempKeyPath = null;

    public function execute(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            // 1. Walidacja
            $this->validateRequest();

            // 2. Pobierz dane z formularza
            $nip = $_POST['nip'];
            $env = $_POST['env'] ?? 'demo';
            $keyPassword = $_POST['key_password'] ?? ''; // hasło do klucza .key (może być puste)
            $dateFrom = $_POST['date_from'];
            $dateTo = $_POST['date_to'];
            $subjectType = $_POST['subject_type'] ?? 'Subject1';

            // 3. Ścieżki
            $baseDir = dirname(__DIR__, 3);
            $authPublicKey = $baseDir . '/src/Auth/public_key.pem';
            $exportPublicKey = $baseDir . '/src/Export/public_key_symetric_encription.pem';

            // 4. Zapisz uploadowane pliki do temp/
            $this->saveUploadedFiles($baseDir);

            // 5. Pobierz baseUrl
            $baseUrl = $this->getBaseUrl($env);

            // 6. Utwórz zależności (te same co w Helpers::createKsefService)
            $logger = new JsonLogger($baseDir . '/logs');
            $client = new KsefClient($logger, $baseUrl);
            $authenticator = new KsefAuthenticator($client, $logger, $authPublicKey);
            $exporter = new KsefExporter($baseUrl, $exportPublicKey);

            // ============================================================
            // 7. NOWY KOD: Uwierzytelnienie certyfikatem (XAdES)
            // ============================================================
            $certAuth = new CertificateAuthenticator(
                $this->tempCertPath,
                $this->tempKeyPath,
                $keyPassword,
                $nip,
                $baseUrl
            );

            $authResult = $certAuth->authenticate();
            $authenticationToken = $authResult['authenticationToken'];

            // ============================================================
            // 8. ISTNIEJĄCY KOD: Wymiana authToken → accessToken
            // ============================================================
            $tokenData = $authenticator->getAccessToken($authenticationToken);
            
            // 9. ISTNIEJĄCY KOD: Odśwież token (zwiększa ważność)
            $accessToken = $authenticator->refreshAccessToken($tokenData['refreshToken']['token']);
            $refreshToken = $tokenData['refreshToken']['token'];

            // ============================================================
            // 10. ISTNIEJĄCY KOD: Uruchom eksport faktur
            // ============================================================
            $dateFromFormatted = $dateFrom . 'T00:00:00.000+00:00';
            $dateToFormatted = $dateTo . 'T23:59:59.999+00:00';

            $exportResult = $exporter->sendExportRequest(
                $accessToken,
                $subjectType,
                $dateFromFormatted,
                $dateToFormatted
            );

            if (!isset($exportResult['referenceNumber'])) {
                throw new Exception('Brak referenceNumber w odpowiedzi');
            }

            $referenceNumber = $exportResult['referenceNumber'];

            // ============================================================
            // 11. Zapisz sesję (tak samo jak StartExportAction)
            // ============================================================
            $sessionId = uniqid('cert_', true);

            // Pobierz klucze szyfrowania (zapisane przez EncryptionHandler)
            $encFile = $baseDir . '/src/Export/last_export_encryption.json';
            $encData = json_decode(file_get_contents($encFile), true);

            Helpers::saveSession($sessionId, [
                'referenceNumber' => $referenceNumber,
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
                'baseUrl' => $baseUrl,
                'rawSymmetricKey' => $encData['rawSymmetricKey'],
                'rawIV' => $encData['rawIV'],
                'auth_method' => 'certificate',
                'created' => time()
            ]);

            // 12. Usuń tymczasowe pliki certyfikatu (bezpieczeństwo!)
            $this->cleanupTempFiles();

            // 13. Odpowiedź sukcesu
            Helpers::jsonResponse([
                'success' => true,
                'sessionId' => $sessionId,
                'referenceNumber' => $referenceNumber
            ]);

        } catch (Exception $e) {
            // Zawsze usuń temp pliki przy błędzie
            $this->cleanupTempFiles();
            
            $errorType = $this->classifyError($e);
            Helpers::errorResponse($e->getMessage(), $errorType);
        }
    }

    /**
     * Zapisuje uploadowane pliki .crt i .key do katalogu temp/
     */
    private function saveUploadedFiles(string $baseDir): void
    {
        $tempDir = $baseDir . '/temp/certs';
        
        // Utwórz katalog jeśli nie istnieje
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Unikalny prefix dla tej sesji
        $uniqueId = uniqid('cert_', true);

        // Zapisz plik .crt
        if (!isset($_FILES['cert_file']) || $_FILES['cert_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Błąd uploadu pliku certyfikatu (.crt)');
        }
        
        $this->tempCertPath = $tempDir . '/' . $uniqueId . '.crt';
        if (!move_uploaded_file($_FILES['cert_file']['tmp_name'], $this->tempCertPath)) {
            throw new Exception('Nie można zapisać pliku certyfikatu');
        }

        // Zapisz plik .key
        if (!isset($_FILES['key_file']) || $_FILES['key_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Błąd uploadu pliku klucza (.key)');
        }
        
        $this->tempKeyPath = $tempDir . '/' . $uniqueId . '.key';
        if (!move_uploaded_file($_FILES['key_file']['tmp_name'], $this->tempKeyPath)) {
            throw new Exception('Nie można zapisać pliku klucza');
        }

        // Walidacja zawartości plików
        $certContent = file_get_contents($this->tempCertPath);
        $keyContent = file_get_contents($this->tempKeyPath);

        if (strpos($certContent, '-----BEGIN CERTIFICATE-----') === false) {
            throw new Exception('Plik .crt nie zawiera prawidłowego certyfikatu PEM');
        }

        if (strpos($keyContent, '-----BEGIN') === false || strpos($keyContent, 'PRIVATE KEY-----') === false) {
            throw new Exception('Plik .key nie zawiera prawidłowego klucza prywatnego PEM');
        }
    }

    /**
     * Usuwa tymczasowe pliki certyfikatu (bezpieczeństwo!)
     */
    private function cleanupTempFiles(): void
    {
        if ($this->tempCertPath && file_exists($this->tempCertPath)) {
            unlink($this->tempCertPath);
        }
        if ($this->tempKeyPath && file_exists($this->tempKeyPath)) {
            unlink($this->tempKeyPath);
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

        if (!preg_match('/^\d{10}$/', $_POST['nip'])) {
            throw new Exception("NIP musi składać się z 10 cyfr");
        }

        // Sprawdź czy pliki zostały uploadowane
        if (!isset($_FILES['cert_file']) || $_FILES['cert_file']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception("Wybierz plik certyfikatu (.crt)");
        }

        if (!isset($_FILES['key_file']) || $_FILES['key_file']['error'] === UPLOAD_ERR_NO_FILE) {
            throw new Exception("Wybierz plik klucza prywatnego (.key)");
        }
    }

    private function getBaseUrl(string $env): string
    {
        return match (strtolower($env)) {
            'demo' => 'https://api-demo.ksef.mf.gov.pl/v2',
            'test' => 'https://api-test.ksef.mf.gov.pl/v2',
            'prod', 'production' => 'https://api.ksef.mf.gov.pl',
            default => 'https://ksef-demo.mf.gov.pl'
        };
    }

    private function classifyError(Exception $e): string
    {
        $message = strtolower($e->getMessage());

        $userErrors = [
            'hasło', 'password', 'certyfikat', 'certificate', 
            'nip', 'uprawnie', '21115', 'klucz', 'key',
            'plik', 'file', 'upload', 'pem', 'wybierz'
        ];
        
        foreach ($userErrors as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return 'user_error';
            }
        }

        return 'app_error';
    }
}
