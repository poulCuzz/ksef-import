<?php

namespace KSeF\Auth;

use Intermedia\Ksef\Apiv2\Client;
use Intermedia\Ksef\Apiv2\AuthTokenRequest;
use Intermedia\Ksef\Apiv2\Models\Components\TContextIdentifier;
use Intermedia\Ksef\Apiv2\Models\Components\TNip;
use Intermedia\Ksef\Apiv2\Models\Components\SubjectIdentifierTypeEnum;
use Exception;

/**
 * Uwierzytelnianie certyfikatem przez intermedia/ksef-api-v2
 * 
 * Instalacja: composer require intermedia/ksef-api-v2
 * Dokumentacja: https://github.com/tommekk83/ksef-api-v2
 */
class CertificateAuthenticator
{
    private string $baseUrl;

    public function __construct(string $environment = 'demo')
    {
        $this->baseUrl = $this->getBaseUrl($environment);
    }

    /**
     * Pełna autoryzacja certyfikatem
     * 
     * @param string $certPath Ścieżka do certyfikatu .crt/.pem
     * @param string $keyPath Ścieżka do klucza .key/.pem  
     * @param string $password Hasło do klucza prywatnego
     * @param string $nip NIP podatnika
     * @return array ['accessToken' => ..., 'refreshToken' => ...]
     */
    public function authenticate(
        string $certPath,
        string $keyPath,
        string $password,
        string $nip
    ): array {
        // Walidacja plików
        if (!file_exists($certPath)) {
            throw new Exception("Plik certyfikatu nie istnieje: {$certPath}");
        }
        
        if (!file_exists($keyPath)) {
            throw new Exception("Plik klucza nie istnieje: {$keyPath}");
        }

        try {
            // KROK 1: Utwórz klienta BEZ tokenu (jeszcze nie mamy)
            $sdk = Client::builder()
                ->setServerURL($this->baseUrl)
                ->build();

            // KROK 2: Pobierz challenge (inicjalizacja uwierzytelnienia)
            $challengeResponse = $sdk->auth->challenge(
                TContextIdentifier::fromNip(new TNip($nip))
            );

            if (!$challengeResponse->authorisationChallengeResponse) {
                throw new Exception("Brak challenge w odpowiedzi z KSeF");
            }

            $challenge = $challengeResponse->authorisationChallengeResponse->challenge;

            // KROK 3: Utwórz AuthTokenRequest i podpisz XAdES
            $authTokenRequest = new AuthTokenRequest(
                $challenge,
                TContextIdentifier::fromNip(new TNip($nip)),
                SubjectIdentifierTypeEnum::CertificateSubject
            );

            // MAGIA! Biblioteka ma wbudowane podpisywanie XAdES
            // PEM Signature (private key i certificate w osobnych plikach)
            $signedXml = $authTokenRequest->signWithXadesToString(
                $keyPath,      // ścieżka do klucza prywatnego .key
                $certPath,     // ścieżka do certyfikatu .crt
                $password      // hasło do klucza (jako 3ci argument dla PEM)
            );

            // KROK 4: Wyślij podpisany XML (uwierzytelnienie z XAdES)
            $authResponse = $sdk->auth->withXades($signedXml);

            if (!$authResponse->authorisationInitResponse) {
                throw new Exception("Brak odpowiedzi z inicjacji uwierzytelniania");
            }

            $referenceNumber = $authResponse->authorisationInitResponse->referenceNumber;

            // KROK 5: Czekaj na sessionToken (sprawdzaj status)
            $sessionToken = $this->waitForSessionToken($sdk, $referenceNumber);

            return [
                'accessToken' => $sessionToken,
                'refreshToken' => $sessionToken
            ];

        } catch (\Throwable $e) {
            // Klasyfikuj błędy
            $message = $e->getMessage();
            
            // Błąd hasła
            if (stripos($message, 'password') !== false || 
                stripos($message, 'decrypt') !== false ||
                stripos($message, 'private key') !== false ||
                stripos($message, 'PEM') !== false) {
                throw new Exception(
                    "Nieprawidłowe hasło do klucza prywatnego lub uszkodzony klucz",
                    0,
                    $e
                );
            }

            // Błąd certyfikatu
            if (stripos($message, 'certificate') !== false) {
                throw new Exception(
                    "Błąd certyfikatu - sprawdź czy plik jest poprawny",
                    0,
                    $e
                );
            }

            // Błąd KSeF API
            if (stripos($message, 'KSeF') !== false || stripos($message, 'API') !== false) {
                throw new Exception(
                    "Błąd API KSeF: " . $message,
                    $e->getCode(),
                    $e
                );
            }

            // Ogólny błąd
            throw new Exception(
                "Błąd uwierzytelniania certyfikatem: " . $message,
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Czeka na sessionToken sprawdzając status co 2 sekundy
     */
    private function waitForSessionToken(Client $sdk, string $referenceNumber): string
    {
        $maxAttempts = 30; // 30 × 2s = 60 sekund max
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            sleep(2);
            $attempt++;

            try {
                // Pobierz status uwierzytelniania
                $statusResponse = $sdk->auth->getStatus($referenceNumber);

                // Sprawdź czy jest sessionToken
                if ($statusResponse->authorisationAccessResponse &&
                    isset($statusResponse->authorisationAccessResponse->sessionToken) &&
                    isset($statusResponse->authorisationAccessResponse->sessionToken->token)) {
                    
                    return $statusResponse->authorisationAccessResponse->sessionToken->token;
                }

                // Sprawdź czy nie ma błędu
                if ($statusResponse->exceptionResponse) {
                    $error = $statusResponse->exceptionResponse;
                    throw new Exception(
                        "Błąd KSeF: " . 
                        ($error->exceptionDescription ?? 'Nieznany błąd') . 
                        " (kod: " . ($error->exceptionCode ?? 'UNKNOWN') . ")"
                    );
                }

            } catch (\Throwable $e) {
                // Jeśli to błąd KSeF, rzuć go dalej
                if (stripos($e->getMessage(), 'Błąd KSeF') !== false) {
                    throw $e;
                }
                // Inne błędy (np. network) - czekamy dalej
                continue;
            }
        }

        throw new Exception("Timeout - nie otrzymano sessionToken w ciągu 60 sekund");
    }

    /**
     * Zwraca URL bazowy dla środowiska
     */
    private function getBaseUrl(string $environment): string
    {
        return match (strtolower($environment)) {
            'demo' => 'https://ksef-demo.mf.gov.pl/api/v2',
            'test' => 'https://ksef-test.mf.gov.pl/api/v2',
            'prod', 'production' => 'https://ksef.mf.gov.pl/api/v2',
            default => throw new Exception("Nieznane środowisko: {$environment}")
        };
    }
}
