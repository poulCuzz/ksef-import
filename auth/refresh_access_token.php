<?php

function refreshAccessToken(string $refreshToken)
{
    $url = "https://ksef-demo.mf.gov.pl/api/v2/auth/token/refresh";

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $refreshToken
    ];

    // zgodnie z dokumentacją — body = pusty JSON
    $payload = json_encode(new stdClass());

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    // 🚨 Obsługa błędów HTTP
    if ($httpCode !== 200) {
        echo "Błąd HTTP: $httpCode\n";
        echo "Odpowiedź serwera:\n$response\n";
        return false;
    }

    // 🚨 Walidacja odpowiedzi
    if (!isset($data['accessToken']['token'])) {
        echo "Brak nowego access tokena w odpowiedzi!\n";
        echo "Odpowiedź serwera:\n$response\n";
        return false;
    }

    // 🎉 Zwracamy nowy access token
    return $data['accessToken']['token'];
}
