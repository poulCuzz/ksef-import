<?php

require_once __DIR__ . '/generate_encryption.php';

function sendExportRequestWithUrl(string $baseUrl, string $accessToken, string $subjectType, string $dateFrom, string $dateTo): ?array
{
    $publicKeyPath = __DIR__ . '/public_key_symetric_encription.pem';
    $encryptionData = generateEncryptionDataForExport($publicKeyPath);

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

    $url = $baseUrl . "/api/v2/invoices/exports";

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
        throw new Exception("Błąd eksportu: HTTP $httpCode - $response");
    }

    return json_decode($response, true);
}
