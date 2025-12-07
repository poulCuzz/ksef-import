<?php

function getAuthTokenWithUrl(string $baseUrl, string $challenge, string $encryptedToken, string $nip): string
{
    $authData = [
        "challenge" => $challenge,
        "contextIdentifier" => [
            "type" => "Nip",
            "value" => $nip 
        ],
        "encryptedToken" => $encryptedToken 
    ];
    
    $postData = json_encode($authData);
    $authUrl = $baseUrl . "/api/v2/auth/ksef-token";
    
    $options = [
        "http" => [
            "header"  => "Content-Type: application/json\r\n",
            "method"  => "POST",
            "content" => $postData,
            "ignore_errors" => true 
        ]
    ];
    
    $context = stream_context_create($options);
    $response = file_get_contents($authUrl, false, $context);
    
    if ($response === FALSE) {
        throw new Exception("Błąd uwierzytelnienia");
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['authenticationToken']['token'])) {
        throw new Exception("Nie znaleziono tokenu w odpowiedzi: " . json_encode($result));
    }
    
    return $result['authenticationToken']['token'];
}