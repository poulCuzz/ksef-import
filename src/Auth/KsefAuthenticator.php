<?php

namespace KSeF\Auth;

use KSeF\Http\KsefClient;
use KSeF\Logger\LoggerInterface;

class KsefAuthenticator implements AuthenticatorInterface {
    private KsefClient $client;
    private LoggerInterface $logger;
    private string $publicKeyPath;
    private TokenEncryptor $encryptor;

    public function __construct(
        KsefClient $client, 
        LoggerInterface $logger,
        string $publicKeyPath
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->publicKeyPath = $publicKeyPath;
        $this->encryptor = new TokenEncryptor($publicKeyPath);
    }
    
    public function getChallenge(string $nip): array {
        $url = "/api/v2/auth/challenge";
        $response = $this->client->post($url, [
            "contextIdentifier" => [
                "type" => "Nip",
                "value" => $nip
            ]
        ]);
        
        if (!isset($response['challenge'])) {
            throw new \Exception("Brak challenge w odpowiedzi");
        }
        
        $challenge = $response['challenge'];
        $timestampString = $response['timestamp'];
        
        $datetime = new \DateTime($timestampString);
        $secondsWithMicro = (float) $datetime->format('U.u');
        $timestampMillis = (int) floor($secondsWithMicro * 1000);
        
        $this->logger->logChallenge($this->client->getBaseUrl() . $url, ['nip' => $nip], $response, 200);
        
        return [$challenge, $timestampMillis];
    }
    
    public function getAuthToken(string $challenge, string $encryptedToken, string $nip): string {
        $url = "/api/v2/auth/ksef-token";
        $response = $this->client->post($url, [
            "challenge" => $challenge,
            "contextIdentifier" => [
                "type" => "Nip",
                "value" => $nip 
            ],
            "encryptedToken" => $encryptedToken 
        ]);
        
        if (!isset($response['authenticationToken']['token'])) {
            throw new \Exception("Nie znaleziono tokenu w odpowiedzi");
        }
        
        $this->logger->logAuthenticationToken($this->client->getBaseUrl() . $url, compact('challenge', 'nip'), $response, 200);
        
        return $response['authenticationToken']['token'];
    }
    
    public function getAccessToken(string $authenticationToken): array {
        $url = "/api/v2/auth/token/redeem";
        $response = $this->client->post($url, [], [
            "Authorization: Bearer " . $authenticationToken
        ]);
        
        if (!isset($response['accessToken']['token'])) {
            throw new \Exception("Brak accessToken w odpowiedzi");
        }
        
        $this->logger->logAccessToken($this->client->getBaseUrl() . $url, $response, 200);
        
        return $response;
    }
    
    public function refreshAccessToken(string $refreshToken): string {
        $url = "/api/v2/auth/token/refresh";
        $response = $this->client->post($url, [], [
            "Authorization: Bearer " . $refreshToken
        ]);
        
        if (!isset($response['accessToken']['token'])) {
            throw new \Exception("Błąd odświeżania accessToken");
        }
        
        $this->logger->logRefreshToken($this->client->getBaseUrl() . $url, $response, 200);
        
        return $response['accessToken']['token'];
    }
}