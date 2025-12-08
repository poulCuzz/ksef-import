<?php

namespace KSeF\Export;

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;
use KSeF\Export\EncryptionHandler;

class KsefExporter implements ExporterInterface {
    private string $baseUrl;
    private string $publicKeyPath;
    private EncryptionHandler $encryptionHandler;

    public function __construct(string $baseUrl, string $publicKeyPath) {
        $this->baseUrl = $baseUrl;
        $this->publicKeyPath = $publicKeyPath;
        $this->encryptionHandler = new EncryptionHandler($publicKeyPath);
    }


    public function sendExportRequest(string $accessToken, string $subjectType, string $dateFrom, string $dateTo): ?array
    {
        $encryptionData = $this->encryptionHandler->generateKeys();

        $filters = [
            "subjectType" => $subjectType,
            "dateRange" => [
                "dateType" => "Issue",
                "from" => $dateFrom,
                "to" => $dateTo
            ]
        ];

        $payload = json_encode([
            "encryption" => [
                "encryptedSymmetricKey" => $encryptionData['encryptedSymmetricKey'],
                "initializationVector" => $encryptionData['initializationVector']
            ],
            "filters" => $filters
        ]);

        $url = $this->baseUrl . "/api/v2/invoices/exports";

        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer $accessToken"
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new \Exception("Błąd eksportu: HTTP $httpCode - $response");
        }

        return json_decode($response, true);
    }


    public function getExportStatus(string $accessToken, string $referenceNumber): array
    {
        $url = $this->baseUrl . '/api/v2/invoices/exports/' . urlencode($referenceNumber);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FAILONERROR    => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Błąd cURL");
        }

        $data = json_decode($response, true);


        if ($httpCode !== 200) {
            $msg = $data['exception']['exceptionDescription'] ?? "Błąd HTTP $httpCode";
            throw new \RuntimeException("KSeF error: $msg");
        }

        return $data;
    }
}