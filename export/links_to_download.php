<?php
/**
 * Pobiera link(i) do pobrania paczki eksportu faktur.
 *
 * Zwraca:
 *   - tablicę URLi części paczki, jeśli eksport zakończony i dostępny
 *   - pustą tablicę, jeśli brak paczki lub wygasła
 *   - wyjątek w przypadku błędu HTTP lub problemu z API
 */
function getExportDownloadLinks(string $accessToken, string $referenceNumber, string $baseUrl): array
{
    $url = rtrim($baseUrl, '/') . '/api/v2/invoices/exports/' . urlencode($referenceNumber);

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FAILONERROR    => false,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("Błąd cURL: " . curl_error($ch));
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $msg = $data['exception']['exceptionDescription'] ?? "Błąd HTTP $httpCode";
        throw new RuntimeException("KSeF error: $msg");
    }

    // Jeśli eksport nie jest zakończony, nie ma paczki
    if (($data['status']['code'] ?? null) !== 200) {
        return [];
    }

    if (empty($data['package']['parts'])) {
        return []; // brak części paczki
    }

    // Pobierz tylko URL
    return array_map(
        fn($p) => $p['url'],
        $data['package']['parts']
    );
}
