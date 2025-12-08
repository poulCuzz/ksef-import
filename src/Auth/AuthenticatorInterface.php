<?php

namespace KSeF\Auth;

interface AuthenticatorInterface {
    
    /**
     * Pobiera challenge z API KSeF
     * 
     * @param string $nip NIP podatnika (10 cyfr)
     * @return array [challenge, timestamp]
     */
    public function getChallenge(string $nip): array;
    
    /**
     * Pobiera token autoryzacyjny
     * 
     * @param string $challenge Challenge z API
     * @param string $encryptedToken Zaszyfrowany token
     * @param string $nip NIP podatnika
     * @return string Authentication token
     */
    public function getAuthToken(string $challenge, string $encryptedToken, string $nip): string;
    
    /**
     * Wymienia authentication token na access token
     * 
     * @param string $authenticationToken Token autoryzacyjny
     * @return array Dane z accessToken i refreshToken
     */
    public function getAccessToken(string $authenticationToken): array;
    
    /**
     * Odświeża access token
     * 
     * @param string $refreshToken Refresh token
     * @return string Nowy access token
     */
    public function refreshAccessToken(string $refreshToken): string;
}