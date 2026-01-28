<?php

namespace KSeF\Auth;

use DOMDocument;
use DOMXPath;
use Exception;

/**
 * Uwierzytelnianie certyfikatem - własna implementacja XAdES-BES
 * 
 * Oparte na analizie:
 * - https://gist.github.com/N1ebieski/7a98f886af26cc9922af8246eddf73c7
 * - https://github.com/CIRFMF/ksef-docs/blob/main/uwierzytelnianie.md
 */
class CertificateAuthenticator
{
    private string $baseUrl;

    public function __construct(string $environment = 'demo')
    {
        $this->baseUrl = $this->getBaseUrl($environment);
    }

    /**
     * Pełna autoryzacja certyfikatem
     */
    public function authenticate(
        string $certPath,
        string $keyPath,
        string $password,
        string $nip
    ): array {
        // Walidacja plików
        if (!file_exists($certPath)) {
            throw new Exception("Plik certyfikatu nie istnieje: {$certPath}");
        }
        
        if (!file_exists($keyPath)) {
            throw new Exception("Plik klucza nie istnieje: {$keyPath}");
        }

        try {
            // KROK 1: Pobierz challenge
            $challengeData = $this->getChallenge($nip);
            $challenge = $challengeData['challenge'];

            // KROK 2: Wczytaj certyfikat i klucz
            $cert = $this->loadCertificate($certPath);
            $privateKey = $this->loadPrivateKey($keyPath, $password);

            // KROK 3: Utwórz niepodpisany XML AuthTokenRequest
            $unsignedXml = $this->createAuthTokenRequestXml($challenge, $nip);

            // KROK 4: Podpisz XML używając XAdES-BES
            $signedXml = $this->signXmlWithXAdES(
                $unsignedXml,
                $cert,
                $privateKey
            );

            // KROK 5: Wyślij podpisany XML do KSeF
            $authResponse = $this->sendXAdESSignature($signedXml);
            $referenceNumber = $authResponse['referenceNumber'];

            // KROK 6: Czekaj na sessionToken
            $sessionToken = $this->waitForSessionToken($referenceNumber);

            return [
                'accessToken' => $sessionToken,
                'refreshToken' => $sessionToken
            ];

        } catch (\Throwable $e) {
            // Klasyfikuj błędy
            throw $this->classifyError($e);
        }
    }

    /**
     * KROK 1: Pobierz challenge z KSeF
     */
    private function getChallenge(string $nip): array
    {
        $url = $this->baseUrl . '/auth/challenge';
        
        $payload = json_encode([
            'contextIdentifier' => [
                'type' => 'Nip',
                'identifier' => $nip
            ]
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new Exception("Błąd pobierania challenge: HTTP $httpCode, URL: $url, Response: $response");
        }

        $data = json_decode($response, true);
        
        if (!isset($data['challenge'])) {
            throw new Exception("Brak challenge w odpowiedzi");
        }

        return $data;
    }

    /**
     * KROK 2a: Wczytaj certyfikat
     */
    private function loadCertificate(string $certPath): array
    {
        $certContent = file_get_contents($certPath);
        $cert = openssl_x509_read($certContent);
        
        if ($cert === false) {
            throw new Exception("Nie można wczytać certyfikatu");
        }

        // Pobierz informacje z certyfikatu
        $certInfo = openssl_x509_parse($cert);
        
        // Certyfikat w Base64 (bez BEGIN/END)
        openssl_x509_export($cert, $certPem);
        $certBase64 = $this->extractCertificateBase64($certPem);

        // SHA-256 digest certyfikatu
        $certDigest = base64_encode(openssl_x509_fingerprint($cert, 'sha256', true));

        return [
            'resource' => $cert,
            'info' => $certInfo,
            'base64' => $certBase64,
            'digest' => $certDigest
        ];
    }

    /**
     * KROK 2b: Wczytaj klucz prywatny
     */
    private function loadPrivateKey(string $keyPath, string $password)
    {
        $keyContent = file_get_contents($keyPath);
        $privateKey = openssl_pkey_get_private($keyContent, $password);
        
        if ($privateKey === false) {
            throw new Exception("Nie można wczytać klucza prywatnego - sprawdź hasło");
        }

        return $privateKey;
    }

    /**
     * KROK 3: Utwórz niepodpisany XML AuthTokenRequest
     */
    private function createAuthTokenRequestXml(string $challenge, string $nip): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<AuthTokenRequest xmlns="http://ksef.mf.gov.pl/auth/token/2.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <Challenge>$challenge</Challenge>
    <ContextIdentifier>
        <Nip>$nip</Nip>
    </ContextIdentifier>
    <SubjectIdentifierType>certificateSubject</SubjectIdentifierType>
</AuthTokenRequest>
XML;
    }

    /**
     * KROK 4: Podpisz XML używając XAdES-BES
     * 
     * To jest najbardziej skomplikowana część!
     */
    private function signXmlWithXAdES(
        string $unsignedXml,
        array $cert,
        $privateKey
    ): string {
        // Wczytaj XML do DOMDocument
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        $doc->loadXML($unsignedXml);

        // Generuj unikalne ID
        $signatureId = 'ID-' . $this->generateGUID();
        $signedInfoId = 'ID-' . $this->generateGUID();
        $referenceId = 'ID-' . $this->generateGUID();
        $signedPropertiesReferenceId = 'ID-' . $this->generateGUID();
        $signatureValueId = 'ID-' . $this->generateGUID();
        $qualifyingPropertiesId = 'ID-' . $this->generateGUID();
        $signedPropertiesId = 'ID-' . $this->generateGUID();

        // 1. Canonicalize document (dla DigestValue)
        $canonicalXml = $doc->C14N(false, false);
        $digestValue = base64_encode(hash('sha256', $canonicalXml, true));

        // 2. Utwórz SignedProperties
        $signingTime = gmdate('Y-m-d\TH:i:s\Z');
        $signedPropertiesXml = $this->createSignedProperties(
            $signedPropertiesId,
            $signatureId,
            $signingTime,
            $cert
        );

        // 3. Canonicalize SignedProperties
        $signedPropertiesDoc = new DOMDocument();
        $signedPropertiesDoc->loadXML($signedPropertiesXml);
        // Użyj exclusive canonicalization
        $canonicalSignedProperties = $signedPropertiesDoc->documentElement->C14N(true, false);
        $signedPropertiesDigest = base64_encode(hash('sha256', $canonicalSignedProperties, true));

        // 4. Utwórz SignedInfo
        $signedInfoXml = $this->createSignedInfo(
            $signedInfoId,
            $digestValue,
            $referenceId,
            $signedPropertiesDigest,
            $signedPropertiesReferenceId,
            $signedPropertiesId
        );

        // 5. Canonicalize SignedInfo i podpisz
        $signedInfoDoc = new DOMDocument();
        $signedInfoDoc->loadXML($signedInfoXml);
        $canonicalSignedInfo = $signedInfoDoc->C14N(false, false);

        // Podpisz za pomocą RSA-SHA256
        openssl_sign($canonicalSignedInfo, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $signatureValue = base64_encode($signature);

        // 6. Zbuduj pełny <Signature>
        $signatureXml = $this->createFullSignature(
            $signatureId,
            $signedInfoXml,
            $signatureValue,
            $signatureValueId,
            $cert['base64'],
            $signedPropertiesXml,
            $qualifyingPropertiesId,
            $signatureId
        );

        // 7. Wstaw <Signature> do dokumentu
        $signatureDoc = new DOMDocument();
        $signatureDoc->loadXML($signatureXml);
        $signatureNode = $doc->importNode($signatureDoc->documentElement, true);
        $doc->documentElement->appendChild($signatureNode);

        return $doc->saveXML();
    }

    /**
     * Tworzy element SignedProperties
     */
    private function createSignedProperties(
        string $id,
        string $target,
        string $signingTime,
        array $cert
    ): string {
        $issuerName = $this->formatIssuerName($cert['info']['issuer']);
        $serialNumber = $cert['info']['serialNumber'];

        return <<<XML
<xades:SignedProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="$id">
    <xades:SignedSignatureProperties>
        <xades:SigningTime>$signingTime</xades:SigningTime>
        <xades:SigningCertificate>
            <xades:Cert>
                <xades:CertDigest>
                    <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                    <ds:DigestValue>{$cert['digest']}</ds:DigestValue>
                </xades:CertDigest>
                <xades:IssuerSerial>
                    <ds:X509IssuerName>$issuerName</ds:X509IssuerName>
                    <ds:X509SerialNumber>$serialNumber</ds:X509SerialNumber>
                </xades:IssuerSerial>
            </xades:Cert>
        </xades:SigningCertificate>
    </xades:SignedSignatureProperties>
</xades:SignedProperties>
XML;
    }

    /**
     * Tworzy element SignedInfo
     */
    private function createSignedInfo(
        string $id,
        string $digestValue,
        string $referenceId,
        string $signedPropertiesDigest,
        string $signedPropertiesReferenceId,
        string $signedPropertiesId
    ): string {
        return <<<XML
<ds:SignedInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="$id">
    <ds:CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
    <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>
    <ds:Reference Id="$referenceId" URI="">
        <ds:Transforms>
            <ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>
        </ds:Transforms>
        <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
        <ds:DigestValue>$digestValue</ds:DigestValue>
    </ds:Reference>
    <ds:Reference Id="$signedPropertiesReferenceId" Type="http://uri.etsi.org/01903#SignedProperties" URI="#$signedPropertiesId">
        <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
        <ds:DigestValue>$signedPropertiesDigest</ds:DigestValue>
    </ds:Reference>
</ds:SignedInfo>
XML;
    }

    /**
     * Tworzy pełny element Signature
     */
    private function createFullSignature(
        string $signatureId,
        string $signedInfoXml,
        string $signatureValue,
        string $signatureValueId,
        string $certBase64,
        string $signedPropertiesXml,
        string $qualifyingPropertiesId,
        string $target
    ): string {
        // Wyciągnij tylko zawartość SignedInfo (bez XML declaration)
        $signedInfoContent = preg_replace('/<\?xml[^>]+\?>/', '', $signedInfoXml);
        $signedPropertiesContent = preg_replace('/<\?xml[^>]+\?>/', '', $signedPropertiesXml);

        return <<<XML
<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="$signatureId">
    $signedInfoContent
    <ds:SignatureValue Id="$signatureValueId">$signatureValue</ds:SignatureValue>
    <ds:KeyInfo>
        <ds:X509Data>
            <ds:X509Certificate>$certBase64</ds:X509Certificate>
        </ds:X509Data>
    </ds:KeyInfo>
    <ds:Object>
        <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Id="$qualifyingPropertiesId" Target="#$target">
            $signedPropertiesContent
        </xades:QualifyingProperties>
    </ds:Object>
</ds:Signature>
XML;
    }

    /**
     * Pomocnicze funkcje
     */
    private function extractCertificateBase64(string $certPem): string
    {
        $lines = explode("\n", $certPem);
        $base64 = '';
        foreach ($lines as $line) {
            if (strpos($line, '-----') === false) {
                $base64 .= trim($line);
            }
        }
        return $base64;
    }

    private function formatIssuerName(array $issuer): string
    {
        $parts = [];
        
        $order = ['L', 'C', 'O', 'serialNumber', 'GN', 'SN', 'CN'];
        
        foreach ($order as $key) {
            if (isset($issuer[$key])) {
                $parts[] = "$key=" . $issuer[$key];
            }
        }
        
        return implode(', ', $parts);
    }

    private function generateGUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * KROK 5: Wyślij podpisany XML
     */
    private function sendXAdESSignature(string $signedXml): array
    {
        $url = $this->baseUrl . '/auth/xades-signature';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/xml'],
            CURLOPT_POSTFIELDS => $signedXml
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 202) {
            throw new Exception("Błąd wysyłania XAdES: HTTP $httpCode - $response");
        }

        $data = json_decode($response, true);
        
        if (!isset($data['referenceNumber'])) {
            throw new Exception("Brak referenceNumber w odpowiedzi");
        }

        return $data;
    }

    /**
     * KROK 6: Czekaj na sessionToken
     */
    private function waitForSessionToken(string $referenceNumber): string
    {
        $url = $this->baseUrl . '/auth/' . urlencode($referenceNumber);
        $maxAttempts = 30;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            sleep(2);
            $attempt++;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json']
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                
                if (isset($data['sessionToken']['token'])) {
                    return $data['sessionToken']['token'];
                }
            }
        }

        throw new Exception("Timeout - nie udało się uzyskać sessionToken");
    }

    private function getBaseUrl(string $environment): string
    {
        return match (strtolower($environment)) {
            'demo' => 'https://api-demo.ksef.mf.gov.pl/api/v2',
            'test' => 'https://api-test.ksef.mf.gov.pl/api/v2',
            'prod', 'production' => 'https://api.ksef.mf.gov.pl/api/v2',
            default => throw new Exception("Nieznane środowisko: {$environment}")
        };
    }

    private function classifyError(\Throwable $e): Exception
    {
        $message = $e->getMessage();
        
        if (stripos($message, 'password') !== false || 
            stripos($message, 'private key') !== false) {
            return new Exception(
                "Nieprawidłowe hasło do klucza prywatnego",
                0,
                $e
            );
        }

        if (stripos($message, 'certificate') !== false) {
            return new Exception(
                "Błąd certyfikatu - sprawdź czy plik jest poprawny",
                0,
                $e
            );
        }

        return new Exception(
            "Błąd uwierzytelniania: " . $message,
            $e->getCode(),
            $e
        );
    }
}