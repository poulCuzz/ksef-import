<?php

namespace KSeF\Auth;

use DOMDocument;
use DOMXPath;
use Exception;

/**
 * Uwierzytelnianie w KSeF 2.0 za pomocą certyfikatu i podpisu XAdES-BES
 * 
 * Proces:
 * 1. POST /auth/challenge - pobierz challenge + timestamp
 * 2. Zbuduj XML AuthTokenRequest
 * 3. Podpisz XML w formacie XAdES-BES
 * 4. POST /auth/xades-signature - wyślij podpisany XML
 * 5. GET /auth/{referenceNumber} - czekaj na status 200
 * 6. POST /auth/token/redeem - pobierz accessToken + refreshToken
 */
class CertificateAuthenticator
{
    private string $baseUrl;
    private string $certPath;
    private string $keyPath;
    private string $keyPassword;
    private string $nip;
    
    // Cache dla certyfikatu i klucza
    private $certificate = null;
    private $privateKey = null;

    public function __construct(string $environment = 'demo')
    {
        $this->baseUrl = match (strtolower($environment)) {
            'demo' => 'https://api-demo.ksef.mf.gov.pl',
            'test' => 'https://api-test.ksef.mf.gov.pl',
            'prod', 'production' => 'https://api.ksef.mf.gov.pl',
            default => throw new Exception("Nieznane środowisko: {$environment}")
        };
    }

    /**
     * Główna metoda uwierzytelniania
     * 
     * @return array ['accessToken' => string, 'refreshToken' => string, 'validUntil' => string]
     */
    public function authenticate(
        string $certPath,
        string $keyPath,
        string $keyPassword,
        string $nip
    ): array {
        $this->certPath = $certPath;
        $this->keyPath = $keyPath;
        $this->keyPassword = $keyPassword;
        $this->nip = $nip;

        // 1. Wczytaj certyfikat i klucz
        $this->loadCertificateAndKey();

        // 2. Pobierz challenge
        $challengeData = $this->getChallenge();
        $challenge = $challengeData['challenge'];
        $timestamp = $challengeData['timestamp'];

        // 3. Zbuduj XML AuthTokenRequest
        $xml = $this->buildAuthTokenRequest($challenge);

        // 4. Podpisz XML (XAdES-BES)
        $signedXml = $this->signXades($xml);

        // 5. Wyślij podpisany XML
        $authResponse = $this->submitSignedXml($signedXml);
        $referenceNumber = $authResponse['referenceNumber'];
        $authenticationToken = $authResponse['authenticationToken']['token'];

        // 6. Czekaj na status 200
        $this->waitForAuthStatus($referenceNumber, $authenticationToken);

        // 7. Pobierz accessToken
        $tokens = $this->redeemToken($authenticationToken);

        return $tokens;
    }

    /**
     * Wczytaj certyfikat i klucz prywatny
     */
    private function loadCertificateAndKey(): void
    {
        // Certyfikat
        $certContent = file_get_contents($this->certPath);
        if ($certContent === false) {
            throw new Exception("Nie można wczytać certyfikatu: {$this->certPath}");
        }
        
        $this->certificate = openssl_x509_read($certContent);
        if ($this->certificate === false) {
            throw new Exception("Nieprawidłowy format certyfikatu");
        }

        // Klucz prywatny
        $keyContent = file_get_contents($this->keyPath);
        if ($keyContent === false) {
            throw new Exception("Nie można wczytać klucza prywatnego: {$this->keyPath}");
        }

        $this->privateKey = openssl_pkey_get_private($keyContent, $this->keyPassword);
        if ($this->privateKey === false) {
            throw new Exception("Nieprawidłowe hasło do klucza prywatnego lub nieprawidłowy format klucza");
        }

        // Sprawdź czy klucz pasuje do certyfikatu
        if (!openssl_x509_check_private_key($this->certificate, $this->privateKey)) {
            throw new Exception("Klucz prywatny nie pasuje do certyfikatu");
        }
    }

    /**
     * Krok 1: Pobierz challenge z KSeF
     */
    private function getChallenge(): array
    {
        $url = "{$this->baseUrl}/v2/auth/challenge";
        
        $payload = json_encode([
            'contextIdentifier' => [
                'type' => 'nip',
                'identifier' => $this->nip
            ]
        ]);

        $response = $this->httpRequest('POST', $url, $payload, [
            'Content-Type: application/json'
        ]);

        if (!isset($response['challenge']) || !isset($response['timestamp'])) {
            throw new Exception("Nieprawidłowa odpowiedź challenge: " . json_encode($response));
        }

        return $response;
    }

    /**
     * Krok 2: Zbuduj XML AuthTokenRequest
     */
    private function buildAuthTokenRequest(string $challenge): string
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = false;
        
        // Root element
        $root = $xml->createElementNS('http://ksef.mf.gov.pl/schema/auth', 'AuthTokenRequest');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $xml->appendChild($root);

        // Challenge
        $challengeEl = $xml->createElement('Challenge', $challenge);
        $root->appendChild($challengeEl);

        // ContextIdentifier
        $contextEl = $xml->createElement('ContextIdentifier');
        $contextEl->appendChild($xml->createElement('Type', 'nip'));
        $contextEl->appendChild($xml->createElement('Identifier', $this->nip));
        $root->appendChild($contextEl);


        // SubjectIdentifierType
        $subjectEl = $xml->createElement('SubjectIdentifierType', 'subjectIdentifierByCertificate');
        $root->appendChild($subjectEl);

        return $xml->saveXML();
    }

    /**
     * Krok 3: Podpisz XML w formacie XAdES-BES
     */
    private function signXades(string $xml): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($xml);

        // Generuj unikalne ID
        $signatureId = 'Signature-' . $this->generateUuid();
        $signedPropsId = 'SignedProperties-' . $this->generateUuid();
        $keyInfoId = 'KeyInfo-' . $this->generateUuid();
        $signedPropsRefId = 'Reference-' . $this->generateUuid();

        // Pobierz dane certyfikatu
        $certData = openssl_x509_parse($this->certificate);
        $certDer = null;
        openssl_x509_export($this->certificate, $certPem);
        $certDer = $this->pemToDer($certPem);
        $certDigest = base64_encode(hash('sha256', $certDer, true));
        $certB64 = base64_encode($certDer);

        // Serial number i issuer
        $serialNumber = $certData['serialNumber'];
        $issuerDn = $this->formatIssuerDn($certData['issuer']);

        // Timestamp dla XAdES
        $signingTime = gmdate('Y-m-d\TH:i:s\Z');

        // Kanonizacja dokumentu i obliczenie digest
        $canonicalXml = $this->canonicalize($doc->documentElement);
        $docDigest = base64_encode(hash('sha256', $canonicalXml, true));

        // Zbuduj SignedProperties
        $signedPropsXml = $this->buildSignedProperties(
            $signedPropsId,
            $signingTime,
            $certDigest,
            $serialNumber,
            $issuerDn
        );

        // Oblicz digest SignedProperties
        $signedPropsDoc = new DOMDocument();
        $signedPropsDoc->loadXML($signedPropsXml);
        $canonicalSignedProps = $this->canonicalize($signedPropsDoc->documentElement);
        $signedPropsDigest = base64_encode(hash('sha256', $canonicalSignedProps, true));

        // Zbuduj SignedInfo
        $signedInfoXml = $this->buildSignedInfo($docDigest, $signedPropsDigest, $signedPropsId);

        // Oblicz podpis
        $signedInfoDoc = new DOMDocument();
        $signedInfoDoc->loadXML($signedInfoXml);
        $canonicalSignedInfo = $this->canonicalize($signedInfoDoc->documentElement);

        $signature = '';
        if (!openssl_sign($canonicalSignedInfo, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new Exception("Błąd tworzenia podpisu: " . openssl_error_string());
        }
        $signatureValue = base64_encode($signature);

        // Zbuduj kompletny podpis
        $signatureXml = $this->buildCompleteSignature(
            $signatureId,
            $signedInfoXml,
            $signatureValue,
            $keyInfoId,
            $certB64,
            $signedPropsXml
        );

        // Wstaw podpis do dokumentu
        $signedDoc = $this->insertSignature($doc, $signatureXml);

        return $signedDoc->saveXML();
    }

    /**
     * Buduje element SignedProperties (XAdES)
     */
    private function buildSignedProperties(
        string $id,
        string $signingTime,
        string $certDigest,
        string $serialNumber,
        string $issuerDn
    ): string {
        return <<<XML
<xades:SignedProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Id="{$id}">
  <xades:SignedSignatureProperties>
    <xades:SigningTime>{$signingTime}</xades:SigningTime>
    <xades:SigningCertificate>
      <xades:Cert>
        <xades:CertDigest>
          <ds:DigestMethod xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
          <ds:DigestValue xmlns:ds="http://www.w3.org/2000/09/xmldsig#">{$certDigest}</ds:DigestValue>
        </xades:CertDigest>
        <xades:IssuerSerial>
          <ds:X509IssuerName xmlns:ds="http://www.w3.org/2000/09/xmldsig#">{$issuerDn}</ds:X509IssuerName>
          <ds:X509SerialNumber xmlns:ds="http://www.w3.org/2000/09/xmldsig#">{$serialNumber}</ds:X509SerialNumber>
        </xades:IssuerSerial>
      </xades:Cert>
    </xades:SigningCertificate>
  </xades:SignedSignatureProperties>
</xades:SignedProperties>
XML;
    }

    /**
     * Buduje element SignedInfo
     */
    private function buildSignedInfo(string $docDigest, string $signedPropsDigest, string $signedPropsId): string
    {
        return <<<XML
<ds:SignedInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
  <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>
  <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>
  <ds:Reference URI="">
    <ds:Transforms>
      <ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>
      <ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>
    </ds:Transforms>
    <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
    <ds:DigestValue>{$docDigest}</ds:DigestValue>
  </ds:Reference>
  <ds:Reference URI="#{$signedPropsId}" Type="http://uri.etsi.org/01903#SignedProperties">
    <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
    <ds:DigestValue>{$signedPropsDigest}</ds:DigestValue>
  </ds:Reference>
</ds:SignedInfo>
XML;
    }

    /**
     * Buduje kompletny element Signature
     */
    private function buildCompleteSignature(
        string $signatureId,
        string $signedInfoXml,
        string $signatureValue,
        string $keyInfoId,
        string $certB64,
        string $signedPropsXml
    ): string {
        // Wyciągnij zawartość SignedInfo bez deklaracji XML
        $signedInfoContent = preg_replace('/<\?xml[^>]*\?>/', '', $signedInfoXml);
        $signedPropsContent = preg_replace('/<\?xml[^>]*\?>/', '', $signedPropsXml);

        return <<<XML
<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="{$signatureId}">
  {$signedInfoContent}
  <ds:SignatureValue>{$signatureValue}</ds:SignatureValue>
  <ds:KeyInfo Id="{$keyInfoId}">
    <ds:X509Data>
      <ds:X509Certificate>{$certB64}</ds:X509Certificate>
    </ds:X509Data>
  </ds:KeyInfo>
  <ds:Object>
    <xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="#{$signatureId}">
      {$signedPropsContent}
    </xades:QualifyingProperties>
  </ds:Object>
</ds:Signature>
XML;
    }

    /**
     * Wstawia podpis do dokumentu
     */
    private function insertSignature(DOMDocument $doc, string $signatureXml): DOMDocument
    {
        $sigDoc = new DOMDocument();
        $sigDoc->loadXML($signatureXml);
        
        $importedSig = $doc->importNode($sigDoc->documentElement, true);
        $doc->documentElement->appendChild($importedSig);

        return $doc;
    }

    /**
     * Krok 4: Wyślij podpisany XML do KSeF
     */
    private function submitSignedXml(string $signedXml): array
    {
        $url = "{$this->baseUrl}/v2/auth/xades-signature";

        $response = $this->httpRequest('POST', $url, $signedXml, [
            'Content-Type: application/xml'
        ]);

        if (!isset($response['referenceNumber']) || !isset($response['authenticationToken'])) {
            throw new Exception("Nieprawidłowa odpowiedź auth: " . json_encode($response));
        }

        return $response;
    }

    /**
     * Krok 5: Czekaj na status uwierzytelnienia
     */
    private function waitForAuthStatus(string $referenceNumber, string $authToken, int $maxAttempts = 30): void
    {
        $url = "{$this->baseUrl}/v2/auth/{$referenceNumber}";

        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->httpRequest('GET', $url, null, [
                "Authorization: Bearer {$authToken}"
            ]);

            $statusCode = $response['status']['code'] ?? 0;

            if ($statusCode === 200) {
                return; // Sukces
            }

            if ($statusCode >= 400) {
                $desc = $response['status']['description'] ?? 'Nieznany błąd';
                throw new Exception("Błąd uwierzytelnienia: {$statusCode} - {$desc}");
            }

            // Status 100 = w toku, czekaj
            sleep(2);
        }

        throw new Exception("Przekroczono limit czasu oczekiwania na uwierzytelnienie");
    }

    /**
     * Krok 6: Pobierz accessToken
     */
    private function redeemToken(string $authToken): array
    {
        $url = "{$this->baseUrl}/v2/auth/token/redeem";

        $response = $this->httpRequest('POST', $url, '{}', [
            'Content-Type: application/json',
            "Authorization: Bearer {$authToken}"
        ]);

        if (!isset($response['accessToken']) || !isset($response['refreshToken'])) {
            throw new Exception("Nieprawidłowa odpowiedź token/redeem: " . json_encode($response));
        }

        return [
            'accessToken' => $response['accessToken']['token'],
            'refreshToken' => $response['refreshToken']['token'],
            'accessTokenValidUntil' => $response['accessToken']['validUntil'] ?? null,
            'refreshTokenValidUntil' => $response['refreshToken']['validUntil'] ?? null,
        ];
    }

    /**
     * Kanonizacja XML (Exclusive C14N)
     */
    private function canonicalize(\DOMNode $node): string
    {
        return $node->C14N(true, true);
    }

    /**
     * Konwertuj PEM na DER
     */
    private function pemToDer(string $pem): string
    {
        $lines = explode("\n", $pem);
        $der = '';
        $inCert = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '-----BEGIN') !== false) {
                $inCert = true;
                continue;
            }
            if (strpos($line, '-----END') !== false) {
                break;
            }
            if ($inCert) {
                $der .= $line;
            }
        }
        
        return base64_decode($der);
    }

    /**
     * Formatuj DN issuera dla XAdES
     */
    private function formatIssuerDn(array $issuer): string
    {
        $parts = [];
        
        // Kolejność RFC 4514 (odwrotna)
        $order = ['CN', 'OU', 'O', 'L', 'ST', 'C'];
        
        foreach ($order as $key) {
            if (isset($issuer[$key])) {
                $value = $issuer[$key];
                if (is_array($value)) {
                    $value = implode('+', $value);
                }
                // Escape specjalnych znaków
                $value = str_replace(['\\', ',', '+', '"', '<', '>', ';'], 
                                    ['\\\\', '\\,', '\\+', '\\"', '\\<', '\\>', '\\;'], $value);
                $parts[] = "{$key}={$value}";
            }
        }
        
        return implode(', ', $parts);
    }

    /**
     * Generuj UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Wykonaj żądanie HTTP
     */
    private function httpRequest(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init();

        $curlHeaders = $headers;
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("Błąd cURL: {$error}");
        }

        $data = json_decode($response, true);

        if ($data === null && !empty($response)) {
            // Może to XML lub inny format
            throw new Exception("Nieprawidłowa odpowiedź JSON (HTTP {$httpCode}): " . substr($response, 0, 500));
        }

        if ($httpCode >= 400) {
            $errorMsg = $data['exception']['exceptionDescription'] ?? "HTTP Error {$httpCode}";
            $errorCode = $data['exception']['exceptionCode'] ?? $httpCode;
            throw new Exception("KSeF Error [{$errorCode}]: {$errorMsg}");
        }

        return $data ?? [];
    }
}
