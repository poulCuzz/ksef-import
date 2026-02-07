<?php
/**
 * CertificateAuthenticator - uwierzytelnianie XAdES dla KSeF
 * 
 * Wczytuje .crt + .key i zwraca authenticationToken,
 * który można wymienić na accessToken za pomocą istniejącego getAccessToken()
 */

namespace KSeF\Auth;

class CertificateAuthenticator
{
    private string $certPath;
    private string $keyPath;
    private string $keyPassword;
    private string $nip;
    private string $baseUrl;
    
    private $cert;
    private $privateKey;
    
    public function __construct(
        string $certPath,
        string $keyPath,
        string $keyPassword,
        string $nip,
        string $baseUrl = 'https://api.ksef.mf.gov.pl'
    ) {
        $this->certPath = $certPath;
        $this->keyPath = $keyPath;
        $this->keyPassword = $keyPassword;
        $this->nip = $nip;
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    /**
     * Główna metoda - zwraca authenticationToken
     */
    public function authenticate(): array
    {
        $this->loadCertificate();
        $this->loadPrivateKey();
        $this->validateKeyAndCert();
        
        $challenge = $this->fetchChallenge();
        $xml = $this->buildAuthXML($challenge);
        $signedXml = $this->signXML($xml);
        
        return $this->sendSignedXML($signedXml);
    }
    
    // ========================================
    // WCZYTANIE CERTYFIKATU I KLUCZA
    // ========================================
    
    private function loadCertificate(): void
    {
        if (!file_exists($this->certPath)) {
            throw new \Exception("Brak pliku certyfikatu: {$this->certPath}");
        }
        
        $certContent = file_get_contents($this->certPath);
        $this->cert = openssl_x509_read($certContent);
        
        if (!$this->cert) {
            throw new \Exception("Nie można wczytać certyfikatu: " . openssl_error_string());
        }
    }
    
    private function loadPrivateKey(): void
    {
        if (!file_exists($this->keyPath)) {
            throw new \Exception("Brak pliku klucza: {$this->keyPath}");
        }
        
        $keyContent = file_get_contents($this->keyPath);
        $this->privateKey = openssl_pkey_get_private($keyContent, $this->keyPassword ?: null);
        
        if (!$this->privateKey) {
            throw new \Exception("Nie można wczytać klucza prywatnego: " . openssl_error_string());
        }
    }
    
    private function validateKeyAndCert(): void
    {
        if (!openssl_x509_check_private_key($this->cert, $this->privateKey)) {
            throw new \Exception("Klucz prywatny nie pasuje do certyfikatu");
        }
    }
    
    // ========================================
    // DANE CERTYFIKATU
    // ========================================
    
    private function getCertDigestB64(): string
    {
        openssl_x509_export($this->cert, $certPem);
        $certDer = base64_decode(preg_replace('/-----[^-]+-----/', '', $certPem));
        return base64_encode(hash('sha256', $certDer, true));
    }
    
    private function getCertIssuerName(): string
    {
        $certInfo = openssl_x509_parse($this->cert);
        $issuer = $certInfo['issuer'];
        
        $parts = [];
        $order = ['CN', 'OU', 'O', 'L', 'ST', 'C'];
        
        foreach ($order as $key) {
            if (isset($issuer[$key])) {
                $value = is_array($issuer[$key]) ? $issuer[$key][0] : $issuer[$key];
                $parts[] = "$key=$value";
            }
        }
        
        return implode(',', $parts);
    }
    
    private function getCertSerialDec(): string
    {
        $certInfo = openssl_x509_parse($this->cert);
        
        if (isset($certInfo['serialNumber'])) {
            return (string)$certInfo['serialNumber'];
        }
        
        if (isset($certInfo['serialNumberHex']) && ctype_xdigit($certInfo['serialNumberHex'])) {
            return base_convert($certInfo['serialNumberHex'], 16, 10);
        }
        
        throw new \Exception("Brak numeru seryjnego certyfikatu");
    }
    
    private function getCertBase64Body(): string
    {
        $certContent = file_get_contents($this->certPath);
        
        if (strpos($certContent, '-----BEGIN CERTIFICATE-----') !== false) {
            if (!preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $certContent, $matches)) {
                throw new \Exception("Nie udało się wyciągnąć certyfikatu PEM");
            }
            return trim($matches[1]);
        }
        
        return trim(chunk_split(base64_encode($certContent), 64, "\n"));
    }
    
    // ========================================
    // POBRANIE CHALLENGE
    // ========================================
    
    private function fetchChallenge(): string
    {
        $url = $this->baseUrl . '/api/v2/auth/challenge';
        
        $payload = json_encode([
            'contextIdentifier' => [
                'nip' => $this->nip
            ]
        ]);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("CURL error: $error");
        }
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \Exception("HTTP $httpCode podczas pobierania challenge: $response");
        }
        
        $data = json_decode($response, true);
        $challenge = $data['challenge'] ?? null;
        
        if (!$challenge) {
            throw new \Exception("Brak challenge w odpowiedzi: $response");
        }
        
        return $challenge;
    }
    
    // ========================================
    // BUDOWA XML (AuthTokenRequest dla XAdES)
    // ========================================
    
    private function buildAuthXML(string $challenge): \DOMDocument
    {
        $certDigest = $this->getCertDigestB64();
        $issuer = $this->getCertIssuerName();
        $serialDec = $this->getCertSerialDec();
        $signingTime = gmdate('Y-m-d\TH:i:s\Z');
        $certB64Body = $this->getCertBase64Body();
        
        // Sprawdź typ klucza (RSA vs ECDSA)
        $keyDetails = openssl_pkey_get_details($this->privateKey);
        $isEcdsa = ($keyDetails['type'] === OPENSSL_KEYTYPE_EC);
        $sigAlgorithm = $isEcdsa 
            ? 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256'
            : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
        
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = false;
        $xml->preserveWhiteSpace = false;
        
        $root = $xml->createElementNS('http://ksef.mf.gov.pl/auth/token/2.0', 'AuthTokenRequest');
        $xml->appendChild($root);
        
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xades', 'http://uri.etsi.org/01903/v1.3.2#');
        
        // Challenge
        $challengeEl = $xml->createElement('Challenge', $challenge);
        $root->appendChild($challengeEl);
        
        // ContextIdentifier
        $ctxId = $xml->createElement('ContextIdentifier');
        $root->appendChild($ctxId);
        $nipEl = $xml->createElement('Nip', $this->nip);
        $ctxId->appendChild($nipEl);
        
        // SubjectIdentifierType
        $subjType = $xml->createElement('SubjectIdentifierType', 'certificateSubject');
        $root->appendChild($subjType);
        
        // Signature
        $signature = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Signature');
        $signature->setAttribute('Id', 'Sig-1');
        $root->appendChild($signature);
        
        // SignedInfo
        $signedInfo = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignedInfo');
        $signature->appendChild($signedInfo);
        
        $canonMethod = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:CanonicalizationMethod');
        $canonMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $signedInfo->appendChild($canonMethod);
        
        $sigMethod = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureMethod');
        $sigMethod->setAttribute('Algorithm', $sigAlgorithm);
        $signedInfo->appendChild($sigMethod);
        
        // Reference 1 - dokument
        $ref1 = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Reference');
        $ref1->setAttribute('URI', '');
        $signedInfo->appendChild($ref1);
        
        $transforms1 = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Transforms');
        $ref1->appendChild($transforms1);
        
        $transform1a = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Transform');
        $transform1a->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms1->appendChild($transform1a);
        
        $transform1b = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Transform');
        $transform1b->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $transforms1->appendChild($transform1b);
        
        $digestMethod1 = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestMethod');
        $digestMethod1->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $ref1->appendChild($digestMethod1);
        
        $digestValue1 = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestValue');
        $ref1->appendChild($digestValue1);
        
        // Reference 2 - SignedProperties
        $ref2 = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Reference');
        $ref2->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
        $ref2->setAttribute('URI', '#SignedProperties-1');
        $signedInfo->appendChild($ref2);
        
        $digestMethod2 = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestMethod');
        $digestMethod2->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $ref2->appendChild($digestMethod2);
        
        $digestValue2 = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestValue');
        $ref2->appendChild($digestValue2);
        
        // SignatureValue
        $sigValue = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureValue');
        $signature->appendChild($sigValue);
        
        // KeyInfo
        $keyInfo = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:KeyInfo');
        $signature->appendChild($keyInfo);
        
        $x509Data = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509Data');
        $keyInfo->appendChild($x509Data);
        
        $x509Cert = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509Certificate', $certB64Body);
        $x509Data->appendChild($x509Cert);
        
        // Object z XAdES
        $object = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Object');
        $signature->appendChild($object);
        
        $qualProps = $xml->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'xades:QualifyingProperties');
        $qualProps->setAttribute('Target', '#Sig-1');
        $object->appendChild($qualProps);
        
        $signedProps = $xml->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'xades:SignedProperties');
        $signedProps->setAttribute('Id', 'SignedProperties-1');
        $signedProps->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $qualProps->appendChild($signedProps);
        
        $signedSigProps = $xml->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'xades:SignedSignatureProperties');
        $signedProps->appendChild($signedSigProps);
        
        $signingTimeEl = $xml->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'xades:SigningTime', $signingTime);
        $signedSigProps->appendChild($signingTimeEl);
        
        $signingCert = $xml->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'xades:SigningCertificate');
        $signedSigProps->appendChild($signingCert);
        
        $certEl = $xml->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'xades:Cert');
        $signingCert->appendChild($certEl);
        
        $certDigestEl = $xml->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'xades:CertDigest');
        $certEl->appendChild($certDigestEl);
        
        $digestMethodCert = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestMethod');
        $digestMethodCert->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $certDigestEl->appendChild($digestMethodCert);
        
        $digestValueCert = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestValue', $certDigest);
        $certDigestEl->appendChild($digestValueCert);
        
        $issuerSerial = $xml->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'xades:IssuerSerial');
        $certEl->appendChild($issuerSerial);
        
        $x509IssuerName = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509IssuerName', $issuer);
        $issuerSerial->appendChild($x509IssuerName);
        
        $x509SerialNumber = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509SerialNumber', $serialDec);
        $issuerSerial->appendChild($x509SerialNumber);
        
        return $xml;
    }
    
    // ========================================
    // PODPIS XML
    // ========================================
    
    private function signXML(\DOMDocument $xml): string
    {
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');
        
        $signedInfoNode = $xpath->query('//ds:SignedInfo')->item(0);
        
        // Digest dokumentu (bez Signature)
        $docCopy = $xml->cloneNode(true);
        $xpathCopy = new \DOMXPath($docCopy);
        $xpathCopy->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $signatureNodeCopy = $xpathCopy->query('//ds:Signature')->item(0);
        $signatureNodeCopy->parentNode->removeChild($signatureNodeCopy);
        
        $docCanon = $docCopy->C14N(true, false);
        $docDigest = base64_encode(hash('sha256', $docCanon, true));
        
        $digestValue1 = $xpath->query('//ds:Reference[@URI=""]/ds:DigestValue')->item(0);
        $digestValue1->nodeValue = $docDigest;
        
        // Digest SignedProperties
        $signedPropsNode = $xpath->query('//xades:SignedProperties[@Id="SignedProperties-1"]')->item(0);
        if (!$signedPropsNode) {
            throw new \Exception("Nie znaleziono xades:SignedProperties");
        }
        
        $signedPropsCanon = $signedPropsNode->C14N(false, false);
        $signedPropsDigest = base64_encode(hash('sha256', $signedPropsCanon, true));
        
        $digestValue2 = $xpath->query('//ds:Reference[@URI="#SignedProperties-1"]/ds:DigestValue')->item(0);
        $digestValue2->nodeValue = $signedPropsDigest;
        
        // Podpis SignedInfo
        $signedInfoCanon = $signedInfoNode->C14N(true, false);
        
        $signature = '';
        if (!openssl_sign($signedInfoCanon, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \Exception("Nie można podpisać: " . openssl_error_string());
        }
        
        // Konwersja ECDSA DER -> Raw (jeśli ECDSA)
        $keyDetails = openssl_pkey_get_details($this->privateKey);
        if ($keyDetails['type'] === OPENSSL_KEYTYPE_EC) {
            $signature = $this->convertECDSASignatureDERtoRaw($signature);
        }
        
        $sigValueNode = $xpath->query('//ds:SignatureValue')->item(0);
        $sigValueNode->nodeValue = base64_encode($signature);
        
        return $xml->saveXML();
    }
    
    private function convertECDSASignatureDERtoRaw(string $derSignature): string
    {
        $offset = 0;
        
        if (ord($derSignature[$offset++]) !== 0x30) {
            throw new \Exception("Nieprawidłowy format podpisu DER ECDSA");
        }
        
        $seqLen = ord($derSignature[$offset++]);
        if ($seqLen & 0x80) {
            $lenBytes = $seqLen & 0x7F;
            $seqLen = 0;
            for ($i = 0; $i < $lenBytes; $i++) {
                $seqLen = ($seqLen << 8) | ord($derSignature[$offset++]);
            }
        }
        
        if (ord($derSignature[$offset++]) !== 0x02) {
            throw new \Exception("Nieprawidłowy format podpisu DER ECDSA (brak INTEGER dla r)");
        }
        
        $rLen = ord($derSignature[$offset++]);
        $r = substr($derSignature, $offset, $rLen);
        $offset += $rLen;
        $r = ltrim($r, "\x00");
        
        if (ord($derSignature[$offset++]) !== 0x02) {
            throw new \Exception("Nieprawidłowy format podpisu DER ECDSA (brak INTEGER dla s)");
        }
        
        $sLen = ord($derSignature[$offset++]);
        $s = substr($derSignature, $offset, $sLen);
        $s = ltrim($s, "\x00");
        
        $targetLen = 32;
        $r = str_pad($r, $targetLen, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, $targetLen, "\x00", STR_PAD_LEFT);
        
        return $r . $s;
    }
    
    // ========================================
    // WYSYŁKA PODPISANEGO XML
    // ========================================
    
    private function sendSignedXML(string $signedXml): array
    {
        $url = $this->baseUrl . '/api/v2/auth/xades-signature?verifyCertificateChain=false';
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $signedXml,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml',
                'Content-Length: ' . strlen($signedXml)
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("CURL error (XAdES): $error");
        }
        curl_close($ch);
        
        if (!in_array($httpCode, [200, 202])) {
            throw new \Exception("HTTP $httpCode z KSeF (XAdES): $response");
        }
        
        $data = json_decode($response, true);
        if (!$data) {
            throw new \Exception("Odpowiedź nie jest JSON: $response");
        }
        
        $token = $data['authenticationToken']['token'] ?? null;
        $validUntil = $data['authenticationToken']['validUntil'] ?? null;
        $referenceNumber = $data['referenceNumber'] ?? null;
        
        if (!$token || !$referenceNumber) {
            throw new \Exception("Brak authenticationToken lub referenceNumber: $response");
        }
        
        return [
            'authenticationToken' => $token,
            'validUntil' => $validUntil,
            'referenceNumber' => $referenceNumber
        ];
    }
}
