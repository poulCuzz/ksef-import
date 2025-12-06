<?php
require 'encrypt_authentication_token.php';

function getChallenge($config) {
    $nip = $config['nip'];
    
    // KROK 1: Challenge
    $challengeUrl = "https://ksef-demo.mf.gov.pl/api/v2/auth/challenge";
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
        die("❌ Błąd pobierania challenge\n");
    }
    
    $challengeData = json_decode($challengeResponse, true);
    if (!isset($challengeData['challenge'])) {
        echo "❌ Odpowiedź API:\n";
        print_r($challengeData);
        die("❌ Brak challenge w odpowiedzi.\n");
    }
    
    $challenge = $challengeData['challenge'];
    $timestampString = $challengeData['timestamp']; // np. "2025-11-29T21:01:28.123456+00:00"
    
    // KONWERSJA JAK W BIBLIOTECE - używamy U.u (sekundy + mikrosekundy)
    $datetime = new DateTime($timestampString);
    $secondsWithMicro = (float) $datetime->format('U.u');
    $timestampMillis = (int) floor($secondsWithMicro * 1000);
    
    echo "✅ Challenge otrzymany\n";
    echo "   Timestamp string: $timestampString\n";
    echo "   Sekundy z mikro: $secondsWithMicro\n";
    echo "   Timestamp millis: $timestampMillis\n\n";
    
    return [$challenge, $timestampMillis];
}

function getAuthenticationToken(string $challenge, int $timestampChallange) : string 
{
    $config = require __DIR__ . '/../config.php';
    $nip = $config['nip'];
    $token = $config['ksef_token'];
    
    echo "🔐 Szyfrowanie tokena...\n";
    echo "   Dane do zaszyfrowania: {$token}|{$timestampChallange}\n\n";
    
    $encryptedToken = encryptToken($token, __DIR__ . '/public_key.pem', $timestampChallange);
    
    // KROK 2: Uwierzytelnianie
    $authData = [
        "challenge" => $challenge,
        "contextIdentifier" => [
            "type" => "Nip",
            "value" => $nip 
        ],
        "encryptedToken" => $encryptedToken 
    ];
    
    $postData = json_encode($authData);
    $authUrl = "https://ksef-demo.mf.gov.pl/api/v2/auth/ksef-token";
    
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
        die("❌ Błąd uwierzytelnienia.\n");
    }
    
    $result = json_decode($response, true);
    
    // DEBUGOWANIE
    echo "🔍 Odpowiedź uwierzytelnienia:\n";
    print_r($result);
    echo "\n";
    
    // Sprawdź status
    if (isset($result['status'])) {
        if ($result['status']['code'] == 100) {
            echo "✅ Status: Uwierzytelnianie w toku\n\n";
        } else {
            echo "❌ Błąd - Kod: " . $result['status']['code'] . "\n";
            echo "   Opis: " . $result['status']['description'] . "\n";
            if (isset($result['status']['details'])) {
                echo "   Szczegóły: " . implode(', ', $result['status']['details']) . "\n";
            }
            die();
        }
    }
    
    // Zwróć token uwierzytelniania
    if (isset($result['authenticationToken']['token'])) {
        $authenticationToken = $result['authenticationToken']['token'];
        
        echo "✅ Token uwierzytelniania otrzymany!\n";
        echo "   Ważny do: " . $result['authenticationToken']['validUntil'] . "\n\n";
        
        return $authenticationToken;
    } else {
        die("❌ Nie znaleziono tokenu w odpowiedzi.\n");
    }
}
?>