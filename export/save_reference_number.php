<?php
/**
 * Zapisuje reference number do pliku JSON
 */
function saveReferenceNumber(string $referenceNumber, string $nip, string $dateFrom, string $dateTo, string $subjectType): void
{
    $filePath = __DIR__ . '/reference_numbers.json';
    
    // Wczytaj istniejące dane lub utwórz pustą tablicę
    $data = [];
    if (file_exists($filePath)) {
        $jsonContent = file_get_contents($filePath);
        $data = json_decode($jsonContent, true) ?? [];
    }
    
    // Dodaj nowy wpis
    $data[] = [
        'referenceNumber' => $referenceNumber,
        'nip' => $nip,
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'subjectType' => $subjectType,
        'createdAt' => date('Y-m-d H:i:s'),
        'timestamp' => time()
    ];
    
    // Zapisz z ładnym formatowaniem
    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}