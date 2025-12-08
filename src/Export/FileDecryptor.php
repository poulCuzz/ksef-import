<?php

namespace KSeF\Export;

class FileDecryptor {
    
    public function download(string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Błąd cURL: $error");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Błąd HTTP $httpCode podczas pobierania pliku AES");
        }

        return $response;
    }

    public function decrypt(string $ciphertext, string $key, string $iv): string {
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new \RuntimeException("Błąd deszyfrowania AES");
        }

        return $plaintext;
    }

    public function downloadAndDecrypt(string $url, string $base64Key, string $base64Iv): string {
        $encryptedData = $this->download($url);
        
        $key = base64_decode($base64Key);
        $iv = base64_decode($base64Iv);
        
        return $this->decrypt($encryptedData, $key, $iv);
    }
}