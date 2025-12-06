<?php

/**
 * Pobiera acces token z KSeF (token/redeem)
 *
 * @param string $authenticationToken – otrzymany z kroku "inicjacja uwierzytelniania"
 * @return string|false – zwraca access token lub false jeśli błąd
 */
function getAccessToken(string $authenticationToken)
{
    $url = "https://ksef-demo.mf.gov.pl/api/v2/auth/token/redeem";

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $authenticationToken
    ];

    // zgodnie z dokumentacją body = pusty JSON {}
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
        return false;
    }

    $data = json_decode($response, true);

    if (!isset($data['accessToken']['token'])) {
        return false;
    }

    return $data;
}
