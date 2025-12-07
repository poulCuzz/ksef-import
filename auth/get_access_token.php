<?php

function getAccessTokenWithUrl(string $baseUrl, string $authenticationToken): array
{
    $url = $baseUrl . "/api/v2/auth/token/redeem";

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $authenticationToken
    ];

    $payload = json_encode(new stdClass());

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

    if ($response === false || $httpCode !== 200) {
        throw new Exception("Błąd pobierania accessToken: HTTP $httpCode");
    }

    $data = json_decode($response, true);

    if (!isset($data['accessToken']['token'])) {
        throw new Exception("Brak accessToken w odpowiedzi");
    }

    return $data;
}
