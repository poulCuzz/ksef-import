<?php

namespace KSeF\Api\Actions;

use N1ebieski\KSEFClient\ClientBuilder;
use N1ebieski\KSEFClient\ValueObjects\Mode;
use N1ebieski\KSEFClient\Factories\EncryptionKeyFactory;
use N1ebieski\KSEFClient\Support\Utility;
use Exception;
use DateTimeImmutable;

class StartImportWithCertificateAction
{
    public function execute(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            // 1. Walidacja POST
            $this->validateRequest();

            // 2. Pobierz dane z formularza
            $nip = $_POST['nip'];
            $env = $_POST['env'] ?? 'demo';
            $p12Password = $_POST['p12_password'];
            $dateFrom = $_POST['date_from'];
            $dateTo = $_POST['date_to'];
            $subjectType = $_POST['subject_type'] ?? 'Subject1';

            // 3. Ścieżka do certyfikatu .p12 (stała lokalizacja)
            $p12Path = dirname(__DIR__, 3) . '/certs/AkceptujFaktury_pl.p12';

            if (!file_exists($p12Path)) {
                throw new Exception("Nie znaleziono pliku certyfikatu .p12: {$p12Path}");
            }

            // 4. Utwórz unikalny klucz szyfrowania (potrzebny do eksportu/pobierania)
            $encryptionKey = EncryptionKeyFactory::makeRandom();

            // 5. Utwórz klienta KSeF z biblioteką n1ebieski
            $client = (new ClientBuilder())
                ->withMode($this->getMode($env))
                ->withCertificatePath($p12Path, $p12Password)
                ->withIdentifier($nip)
                ->withEncryptionKey($encryptionKey)
                ->withValidateXml(false) // wyłącz walidację dla szybkości
                ->build();

            // 6. Uruchom eksport faktur
            $initResponse = $client->invoices()->exports()->init([
                'filters' => [
                    'subjectType' => $subjectType,
                    'dateRange' => [
                        'dateType' => 'Invoicing',
                        'from' => new DateTimeImmutable($dateFrom . 'T00:00:00Z'),
                        'to' => new DateTimeImmutable($dateTo . 'T23:59:59Z')
                    ],
                ]
            ])->object();

            $referenceNumber = $initResponse->referenceNumber;

            // 7. Utwórz katalog sesji i zapisz dane
            $sessionId = uniqid('cert_', true);
            $sessionDir = dirname(__DIR__, 3) . '/sessions/' . $sessionId;
            
            if (!is_dir($sessionDir)) {
                mkdir($sessionDir, 0755, true);
            }

            // 8. Zapisz dane sesji (w tym klucz szyfrowania!)
            $sessionData = [
                'sessionId' => $sessionId,
                'referenceNumber' => $referenceNumber,
                'encryptionKey' => base64_encode(serialize($encryptionKey)),
                'baseUrl' => match($env) {
                    'test' => 'https://api-test.ksef.mf.gov.pl/v2',
                    'demo' => 'https://api-demo.ksef.mf.gov.pl/v2',
                    default => 'https://api.ksef.mf.gov.pl/v2'
                },
                'env' => $env,
                'nip' => $nip,
                'p12Path' => $p12Path,
                'p12Password' => $p12Password, // W produkcji zaszyfrować!
                'auth_method' => 'certificate',
                'created_at' => date('Y-m-d H:i:s')
            ];

            file_put_contents(
                $sessionDir . '/session.json',
                json_encode($sessionData, JSON_PRETTY_PRINT)
            );

            // 9. Odpowiedź sukcesu
            echo json_encode([
                'success' => true,
                'sessionId' => $sessionId,
                'referenceNumber' => $referenceNumber
            ]);

        } catch (Exception $e) {
            $errorType = $this->classifyError($e);
            
            http_response_code($errorType === 'user_error' ? 400 : 500);
            
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => $errorType,
                'trace' => $e->getTraceAsString(),  // DODAJ TO
                'full_error' => (string)$e          // I TO
            ]);
        }
    }

    private function validateRequest(): void
    {
        $required = ['nip', 'p12_password', 'date_from', 'date_to'];
        
        foreach ($required as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                throw new Exception("Pole {$field} jest wymagane");
            }
        }

        // Walidacja NIP
        if (!preg_match('/^\d{10}$/', $_POST['nip'])) {
            throw new Exception("NIP musi składać się z 10 cyfr");
        }
    }

    private function getMode(string $env): Mode
    {
        return match (strtolower($env)) {
            'demo' => Mode::Demo,
            'test' => Mode::Test,
            'prod', 'production' => Mode::Production,
            default => throw new Exception("Nieznane środowisko: {$env}")
        };
    }

    private function classifyError(Exception $e): string
    {
        $message = strtolower($e->getMessage());

        // Błędy użytkownika
        $userErrors = ['hasło', 'password', 'certyfikat', 'certificate', 'nip', 'uprawnie'];
        
        foreach ($userErrors as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return 'user_error';
            }
        }

        return 'app_error';
    }
}
