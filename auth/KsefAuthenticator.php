<?php

namespace KSeF\Auth;

use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

class KsefAuthenticator {
    private string $baseUrl;
    private string $publicKeyPath;
    private TokenEncryptor $encryptor;

    public function __construct(string $baseUrl, string $publicKeyPath) {
        $this->baseUrl = $baseUrl;
        $this->publicKeyPath = $publicKeyPath;
        $this->encryptor = new TokenEncryptor($publicKeyPath);
    }
    
    public function getChallenge(string $nip): array
{
    $challengeUrl = $this->baseUrl . "/api/v2/auth/challenge";
    $challengePayload = json_encode([
        "contextIdentifier" => [
            "type" => "Nip",
            "value" => $nip
        ]
    ]);
    
    $options = [
        "http" => [
            "header"  => "Content-Type: application/json\r\n",
            "method"  => "POST",
            "content" => $challengePayload,
            "ignore_errors" => true
        ]
    ];
    
    $context = stream_context_create($options);
    $challengeResponse = file_get_contents($challengeUrl, false, $context);
    
    if ($challengeResponse === FALSE) {
        throw new \Exception("Błąd pobierania challenge");
    }
    
    $challengeData = json_decode($challengeResponse, true);
    if (!isset($challengeData['challenge'])) {
        throw new \Exception("Brak challenge w odpowiedzi: " . json_encode($challengeData));
    }
    
    $challenge = $challengeData['challenge'];
    $timestampString = $challengeData['timestamp'];
    
    $datetime = new \DateTime($timestampString);
    $secondsWithMicro = (float) $datetime->format('U.u');
    $timestampMillis = (int) floor($secondsWithMicro * 1000);
    
    return [$challenge, $timestampMillis];
}

public function getAuthToken(string $challenge, string $encryptedToken, string $nip): string
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
    $authUrl = $this->baseUrl . "/api/v2/auth/ksef-token";
    
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
        throw new \Exception("Błąd uwierzytelnienia");
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['authenticationToken']['token'])) {
        throw new \Exception("Nie znaleziono tokenu w odpowiedzi: " . json_encode($result));
    }
    
    return $result['authenticationToken']['token'];
}

public function getAccessToken(string $authenticationToken): array
{
    $url = $this->baseUrl . "/api/v2/auth/token/redeem";

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $authenticationToken
    ];

    $payload = json_encode(new \stdClass());

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
        throw new \Exception("Błąd pobierania accessToken: HTTP $httpCode");
    }

    $data = json_decode($response, true);

    if (!isset($data['accessToken']['token'])) {
        throw new \Exception("Brak accessToken w odpowiedzi");
    }

    return $data;
}

public function refreshAccessToken(string $refreshToken): string
{
    $url = $this->baseUrl . "/api/v2/auth/token/refresh";

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $refreshToken
    ];

    $payload = json_encode(new \stdClass());

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

    if ($httpCode !== 200 || !isset($data['accessToken']['token'])) {
        throw new \Exception("Błąd odświeżania accessToken: HTTP $httpCode");
    }

    return $data['accessToken']['token'];
}

}