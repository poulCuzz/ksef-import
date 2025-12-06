<?php
/**
 * Funkcja do uzyskania odświeżonego accessToken z KSeF
 * 
 * Wykonuje pełny flow uwierzytelnienia:
 * 1. Pobranie challenge
 * 2. Uzyskanie authenticationToken
 * 3. Wymiana na accessToken + refreshToken
 * 4. Odświeżenie accessToken
 */

require_once __DIR__ . '/ksef_authentication.php';
require_once __DIR__ . '/get_access_token.php';
require_once __DIR__ . '/refresh_access_token.php';

/**
 * Uzyskuje odświeżony accessToken na podstawie tokena KSeF
 *
 * @param string $ksefToken - surowy token KSeF (z config.php lub innego źródła)
 * @param string $nip - NIP podmiotu
 * @return array|false - tablica z tokenami lub false w przypadku błędu
 *                       ['accessToken' => string, 'refreshToken' => string]
 */
function getRefreshedAccessToken(string $ksefToken, string $nip): array|false
{
    try {
        $config = [
            'nip' => $nip,
            'ksef_token' => $ksefToken
        ];

        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "🔐 Rozpoczynam proces uwierzytelnienia KSeF\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // KROK 1: Pobranie challenge
        echo "📋 Krok 1: Pobieranie challenge...\n";
        [$challenge, $timestamp] = getChallenge($config);

        // KROK 2: Uzyskanie authenticationToken
        echo "🔑 Krok 2: Uzyskiwanie authenticationToken...\n";
        $authenticationToken = getAuthenticationToken($challenge, $timestamp);

        if (empty($authenticationToken)) {
            echo "❌ Nie udało się uzyskać authenticationToken\n";
            return false;
        }

        // KROK 3: Wymiana na accessToken + refreshToken
        echo "🎫 Krok 3: Wymiana na accessToken...\n";
        $accessData = getAccessToken($authenticationToken);

        if ($accessData === false || !isset($accessData['accessToken']['token'])) {
            echo "❌ Nie udało się uzyskać accessToken\n";
            return false;
        }

        $accessToken = $accessData['accessToken']['token'];
        $refreshToken = $accessData['refreshToken']['token'] ?? null;

        if (empty($refreshToken)) {
            echo "❌ Brak refreshToken w odpowiedzi\n";
            return false;
        }

        echo "   ✅ Otrzymano accessToken i refreshToken\n\n";

        // KROK 4: Odświeżenie accessToken
        echo "🔄 Krok 4: Odświeżanie accessToken...\n";
        $refreshedAccessToken = refreshAccessToken($refreshToken);

        if ($refreshedAccessToken === false) {
            echo "❌ Nie udało się odświeżyć accessToken\n";
            return false;
        }

        echo "   ✅ AccessToken odświeżony pomyślnie\n\n";

        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "✅ SUKCES! Uwierzytelnienie zakończone\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        return [
            'accessToken' => $refreshedAccessToken,
            'refreshToken' => $refreshToken
        ];

    } catch (Exception $e) {
        echo "❌ Błąd podczas uwierzytelnienia: " . $e->getMessage() . "\n";
        return false;
    }
}
