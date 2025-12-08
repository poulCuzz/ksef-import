<?php

namespace KSeF;

use KSeF\Auth\KsefAuthenticator;
use KSeF\Auth\TokenEncryptor;
use KSeF\Export\KsefExporter;
use KSeF\Export\FileDecryptor;
use KSeF\Logger\LoggerInterface;

class KsefService {
    
    private KsefAuthenticator $authenticator;
    private TokenEncryptor $encryptor;
    private KsefExporter $exporter;
    private FileDecryptor $decryptor;
    private LoggerInterface $logger;
    
    public function __construct(
        KsefAuthenticator $authenticator,
        TokenEncryptor $encryptor,
        KsefExporter $exporter,
        FileDecryptor $decryptor,
        LoggerInterface $logger
    ) {
        $this->authenticator = $authenticator;
        $this->encryptor = $encryptor;
        $this->exporter = $exporter;
        $this->decryptor = $decryptor;
        $this->logger = $logger;
    }
    
    /**
     * Pełna autoryzacja - od challenge do accessToken
     * 
     * @param string $nip NIP podatnika
     * @param string $ksefToken Token KSeF z panelu MF
     * @return array ['accessToken' => ..., 'refreshToken' => ...]
     */
    public function authenticate(string $nip, string $ksefToken): array {
        // 1. Pobierz challenge
        [$challenge, $timestamp] = $this->authenticator->getChallenge($nip);
        
        // 2. Zaszyfruj token
        $encryptedToken = $this->encryptor->encrypt($ksefToken, $timestamp);
        
        // 3. Pobierz authentication token
        $authToken = $this->authenticator->getAuthToken($challenge, $encryptedToken, $nip);
        
        // 4. Wymień na access token
        $tokenData = $this->authenticator->getAccessToken($authToken);
        
        // 5. Odśwież token (opcjonalne, ale zwiększa ważność)
        $accessToken = $this->authenticator->refreshAccessToken($tokenData['refreshToken']['token']);
        
        return [
            'accessToken' => $accessToken,
            'refreshToken' => $tokenData['refreshToken']['token']
        ];
    }
    
    /**
     * Rozpoczyna eksport faktur
     * 
     * @param string $accessToken Token dostępowy
     * @param string $subjectType Subject1 (sprzedawca) lub Subject2 (nabywca)
     * @param string $dateFrom Data od (ISO 8601)
     * @param string $dateTo Data do (ISO 8601)
     * @return array Odpowiedź z referenceNumber
     */
    public function startExport(
        string $accessToken,
        string $subjectType,
        string $dateFrom,
        string $dateTo
    ): array {
        $result = $this->exporter->sendExportRequest(
            $accessToken,
            $subjectType,
            $dateFrom,
            $dateTo
        );
        
        return $result ?? [];
    }
    
    /**
     * Sprawdza status eksportu
     * 
     * @param string $accessToken Token dostępowy
     * @param string $referenceNumber Numer referencyjny eksportu
     * @return array Status i linki do pobrania
     */
    public function checkStatus(string $accessToken, string $referenceNumber): array {
        return $this->exporter->getExportStatus($accessToken, $referenceNumber);
    }
    
    /**
     * Pobiera i odszyfrowuje plik
     * 
     * @param string $url URL pliku .aes
     * @param string $key Klucz AES (base64)
     * @param string $iv Wektor IV (base64)
     * @return string Odszyfrowana zawartość (ZIP)
     */
    public function downloadAndDecrypt(string $url, string $key, string $iv): string {
        return $this->decryptor->downloadAndDecrypt($url, $key, $iv);
    }
}