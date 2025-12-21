<?php

namespace KSeF\Api;

class ErrorHandler
{
    public static function handle(\Exception $e): void {
        $message = $e->getMessage();
        
        // Klasyfikacja błędów
        $errorType = 'server_error';
        $suggestions = [];
        
        // Błędy autoryzacji (user_error)
        if (strpos($message, 'challenge') !== false || 
            strpos($message, 'token') !== false ||
            strpos($message, 'autoryzac') !== false ||
            strpos($message, 'uwierzytelni') !== false ||
            strpos($message, '401') !== false ||
            strpos($message, '403') !== false ||
            strpos($message, '400') !== false) {
            $errorType = 'user_error';
            $suggestions = [
                'Sprawdź czy token KSeF jest poprawny i aktualny',
                'Sprawdź czy środowisko (DEMO/TEST) pasuje do tokena',
                'Sprawdź czy NIP jest zgodny z tokenem'
            ];
        }
        // Błędy połączenia (server_error)
        elseif (strpos($message, 'cURL') !== false || 
                strpos($message, 'timeout') !== false ||
                strpos($message, 'connection') !== false ||
                strpos($message, '500') !== false ||
                strpos($message, '502') !== false ||
                strpos($message, '503') !== false) {
            $errorType = 'server_error';
            $suggestions = [
                'Serwer KSeF może być chwilowo niedostępny',
                'Spróbuj ponownie za kilka minut',
                'Sprawdź status serwera KSeF'
            ];
        }
        // Błędy walidacji NIP (user_error)
        elseif (strpos($message, 'NIP') !== false || 
                strpos($message, 'nip') !== false) {
            $errorType = 'user_error';
            $suggestions = [
                'Sprawdź czy NIP ma dokładnie 10 cyfr',
                'Sprawdź czy NIP jest zgodny z tokenem KSeF'
            ];
        }
        // Brak faktur (info)
        elseif (strpos($message, 'brak faktur') !== false || 
                strpos($message, 'nie znaleziono') !== false ||
                strpos($message, 'empty') !== false) {
            $errorType = 'info';
            $suggestions = [
                'Zmień zakres dat',
                'Sprawdź czy w wybranym okresie były wystawione faktury'
            ];
        }
        
        Helpers::jsonResponse([
            'success' => false,
            'errorType' => $errorType,
            'message' => $message,
            'suggestions' => $suggestions
        ], $errorType === 'user_error' ? 400 : 500);
    }
}
