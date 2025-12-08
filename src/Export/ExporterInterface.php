<?php

namespace KSeF\Export;

interface ExporterInterface {
    
    /**
     * Wysyła żądanie eksportu faktur
     * 
     * @param string $accessToken Token dostępowy
     * @param string $subjectType Subject1 (sprzedawca) lub Subject2 (nabywca)
     * @param string $dateFrom Data od (ISO 8601)
     * @param string $dateTo Data do (ISO 8601)
     * @return array|null Odpowiedź z referenceNumber
     */
    public function sendExportRequest(
        string $accessToken, 
        string $subjectType, 
        string $dateFrom, 
        string $dateTo
    ): ?array;
    
    /**
     * Sprawdza status eksportu
     * 
     * @param string $accessToken Token dostępowy
     * @param string $referenceNumber Numer referencyjny eksportu
     * @return array Status i linki do pobrania
     */
    public function getExportStatus(string $accessToken, string $referenceNumber): array;
}