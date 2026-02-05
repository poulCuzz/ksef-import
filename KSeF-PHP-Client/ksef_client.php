<?php
/**
 * XAdES + redeem token + wniosek certyfikacyjny KSeF
 * - autoryzacja XAdES
 * - sprawdzenie statusu uwierzytelniania
 * - token/redeem (access/refresh)
 * - enrollments/data (dane do CSR)
 * - enrollments (wniosek certyfikacyjny)
 * - sprawdzenie statusu wniosku certyfikacyjnego
 * - pobranie certyfikatu i zapis do plików .der/.crt
 */

// ============================================
// TRYB URUCHOMIENIA (CLI vs PRZEGLĄDARKA)
// ============================================

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
}

// ============================================
// KONFIGURACJA
// ============================================

define('NIP', '7811882150');

define('CERT_PATH', __DIR__ . '/../certs/KSeF_Online.crt');
define('KEY_PATH', __DIR__ . '/../certs/KSeF_Online.key');
define('PASS_PATH', __DIR__ . '/../certs/KseF_Online_pass.txt');

define('CACERT_PATH', __DIR__ . '/cacert.pem');

define('CSR_DIR', __DIR__ . '/wniosek_ksef');
define('CSR_KEY_PATH', CSR_DIR . '/new_cert_key.pem');
define('CSR_INFO_PATH', CSR_DIR . '/new_cert_info.txt');

define('KSEF_BASE', 'https://api.ksef.mf.gov.pl');

define('URL_CHALLENGE',    KSEF_BASE . '/api/v2/auth/challenge');
define('URL_XADES',        KSEF_BASE . '/api/v2/auth/xades-signature?verifyCertificateChain=false');
define('URL_AUTH_STATUS',  KSEF_BASE . '/api/v2/auth');
define('URL_TOKEN_REDEEM', KSEF_BASE . '/api/v2/auth/token/redeem');

define('URL_ENROLL_DATA',  KSEF_BASE . '/api/v2/certificates/enrollments/data');
define('URL_ENROLL',       KSEF_BASE . '/api/v2/certificates/enrollments');
define('URL_ENROLL_STATUS',KSEF_BASE . '/api/v2/certificates/enrollments');
define('URL_CERT_RETRIEVE',KSEF_BASE . '/api/v2/certificates/retrieve');

// ============================================
// FUNKCJE POMOCNICZE
// ============================================

function logStep($message) {
    global $isCli;

    if ($isCli) {
        echo $message . PHP_EOL;
    } else {
        echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "<br>\n";
    }
    flush();
}

function exitWithError($message) {
    global $isCli;

    if ($isCli) {
        fwrite(STDERR, "BŁĄD: " . $message . PHP_EOL);
        exit(1);
    } else {
        http_response_code(500);
        echo "<strong>BŁĄD:</strong> " . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        exit;
    }
}

function ensureFile($path, $description) {
    if (!file_exists($path)) {
        exitWithError("Brak pliku $description: $path");
    }
}

function preChecks() {
    $required = ['openssl', 'curl', 'dom', 'xmlwriter', 'hash'];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            exitWithError("Wymagane rozszerzenie PHP: $ext");
        }
    }

    ensureFile(CERT_PATH, 'certyfikatu do XAdES');
    ensureFile(KEY_PATH, 'klucza prywatnego do XAdES');
    ensureFile(PASS_PATH, 'hasła do klucza prywatnego');

    if (!file_exists(CACERT_PATH)) {
        logStep('Pobieram cacert.pem...');
        downloadCACert();
    }

    if (!is_dir(CSR_DIR)) {
        if (!mkdir(CSR_DIR, 0777, true) && !is_dir(CSR_DIR)) {
            exitWithError("Nie można utworzyć katalogu: " . CSR_DIR);
        }
    }
}

function downloadCACert() {
    $url = 'https://curl.se/ca/cacert.pem';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$content) {
        exitWithError("Nie można pobrać cacert.pem z $url");
    }

    file_put_contents(CACERT_PATH, $content);
    logStep('Zapisano cacert.pem');
}

// ============================================
// WCZYTANIE CERTYFIKATU I WALIDACJA
// ============================================

function loadCertificate() {
    $certContent = file_get_contents(CERT_PATH);
    $cert = openssl_x509_read($certContent);

    if (!$cert) {
        exitWithError("Nie można wczytać certyfikatu: " . openssl_error_string());
    }

    return $cert;
}

function loadPrivateKey() {
    $password = trim(file_get_contents(PASS_PATH));
    $keyContent = file_get_contents(KEY_PATH);

    $privateKey = openssl_pkey_get_private($keyContent, $password ?: null);

    if (!$privateKey) {
        exitWithError("Nie można wczytać klucza prywatnego: " . openssl_error_string());
    }

    return $privateKey;
}

function validateKeyAndCert($cert, $privateKey) {
    if (!openssl_x509_check_private_key($cert, $privateKey)) {
        exitWithError("Klucz prywatny nie pasuje do certyfikatu");
    }
}

// ============================================
// DANE CERTYFIKATU
// ============================================

function getCertDigestB64($cert) {
    openssl_x509_export($cert, $certPem);
    $certDer = base64_decode(preg_replace('/-----[^-]+-----/', '', $certPem));
    return base64_encode(hash('sha256', $certDer, true));
}

function getCertIssuerName($cert) {
    $certInfo = openssl_x509_parse($cert);
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

function getCertSerialDec($cert) {
    $certInfo = openssl_x509_parse($cert);

    if (isset($certInfo['serialNumber'])) {
        return (string)$certInfo['serialNumber'];
    }

    if (isset($certInfo['serialNumberHex']) && ctype_xdigit($certInfo['serialNumberHex'])) {
        return base_convert($certInfo['serialNumberHex'], 16, 10);
    }

    exitWithError("Brak numeru seryjnego certyfikatu");
}

function getCertBase64Body() {
    $certContent = file_get_contents(CERT_PATH);

    if (strpos($certContent, '-----BEGIN CERTIFICATE-----') !== false) {
        if (!preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $certContent, $matches)) {
            exitWithError("Nie udało się wyciągnąć certyfikatu PEM");
        }
        return trim($matches[1]);
    }

    return trim(chunk_split(base64_encode($certContent), 64, "\n"));
}

// ============================================
// POBRANIE CHALLENGE
// ============================================

function fetchChallenge() {
    logStep('[1/7] Pobieram challenge...');

    $payload = json_encode([
        'contextIdentifier' => [
            'nip' => NIP
        ]
    ]);

    $ch = curl_init(URL_CHALLENGE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CAINFO, CACERT_PATH);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        exitWithError("CURL error: $error");
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        exitWithError("HTTP $httpCode podczas pobierania challenge:\n$response");
    }

    $data = json_decode($response, true);
    $challenge = $data['challenge'] ?? null;

    if (!$challenge) {
        exitWithError("Nie udało się pobrać challenge. Odpowiedź:\n$response");
    }

    logStep("CHALLENGE=$challenge");
    return $challenge;
}

// ============================================
// BUDOWA XML (AuthTokenRequest dla XAdES)
// ============================================

function buildAuthXML($challenge, $cert) {
    logStep('[2/7] Przygotowuję dane do XAdES...');

    $certDigest = getCertDigestB64($cert);
    $issuer = getCertIssuerName($cert);
    $serialDec = getCertSerialDec($cert);
    $signingTime = gmdate('Y-m-d\TH:i:s\Z');
    $certB64Body = getCertBase64Body();

    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = false;
    $xml->preserveWhiteSpace = false;

    $root = $xml->createElementNS('http://ksef.mf.gov.pl/auth/token/2.0', 'AuthTokenRequest');
    $xml->appendChild($root);

    $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
    $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xades', 'http://uri.etsi.org/01903/v1.3.2#');

    $challengeEl = $xml->createElement('Challenge', $challenge);
    $root->appendChild($challengeEl);

    $ctxId = $xml->createElement('ContextIdentifier');
    $root->appendChild($ctxId);
    $nipEl = $xml->createElement('Nip', NIP);
    $ctxId->appendChild($nipEl);

    $subjType = $xml->createElement('SubjectIdentifierType', 'certificateSubject');
    $root->appendChild($subjType);

    $signature = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Signature');
    $signature->setAttribute('Id', 'Sig-1');
    $root->appendChild($signature);

    $signedInfo = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignedInfo');
    $signature->appendChild($signedInfo);

    $canonMethod = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:CanonicalizationMethod');
    $canonMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
    $signedInfo->appendChild($canonMethod);

    $sigMethod = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureMethod');
    $sigMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256');
    $signedInfo->appendChild($sigMethod);

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

    $ref2 = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Reference');
    $ref2->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
    $ref2->setAttribute('URI', '#SignedProperties-1');
    $signedInfo->appendChild($ref2);

    $digestMethod2 = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestMethod');
    $digestMethod2->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
    $ref2->appendChild($digestMethod2);

    $digestValue2 = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestValue');
    $ref2->appendChild($digestValue2);

    $sigValue = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureValue');
    $signature->appendChild($sigValue);

    $keyInfo = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:KeyInfo');
    $signature->appendChild($keyInfo);

    $x509Data = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509Data');
    $keyInfo->appendChild($x509Data);

    $x509Cert = $xml->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509Certificate', $certB64Body);
    $x509Data->appendChild($x509Cert);

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

// ============================================
// PODPIS XML - PURE PHP
// ============================================

function signXMLPurePHP($xml, $privateKey) {
    logStep('[3/7] Podpisuję XML (pure PHP)...');

    file_put_contents(__DIR__ . '/debug_before_sign.xml', $xml->saveXML());

    $xpath = new DOMXPath($xml);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

    $signatureNode = $xpath->query('//ds:Signature')->item(0);
    $signedInfoNode = $xpath->query('//ds:SignedInfo')->item(0);

    $docCopy = $xml->cloneNode(true);
    $xpathCopy = new DOMXPath($docCopy);
    $xpathCopy->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $signatureNodeCopy = $xpathCopy->query('//ds:Signature')->item(0);
    $signatureNodeCopy->parentNode->removeChild($signatureNodeCopy);

    $docCanon = $docCopy->C14N(true, false);
    $docDigest = base64_encode(hash('sha256', $docCanon, true));

    $digestValue1 = $xpath->query('//ds:Reference[@URI=""]/ds:DigestValue')->item(0);
    $digestValue1->nodeValue = $docDigest;

    $signedPropsNode = $xpath->query('//xades:SignedProperties[@Id="SignedProperties-1"]')->item(0);

    if (!$signedPropsNode) {
        exitWithError("Nie znaleziono xades:SignedProperties");
    }

    $signedPropsCanon = $signedPropsNode->C14N(false, false);

    file_put_contents(__DIR__ . '/debug_signedprops_canon.txt', $signedPropsCanon);
    logStep("DEBUG: Digest SignedProperties: " . base64_encode(hash('sha256', $signedPropsCanon, true)));
    logStep("DEBUG: Długość canon: " . strlen($signedPropsCanon));

    $signedPropsDigest = base64_encode(hash('sha256', $signedPropsCanon, true));

    $digestValue2 = $xpath->query('//ds:Reference[@URI="#SignedProperties-1"]/ds:DigestValue')->item(0);
    $digestValue2->nodeValue = $signedPropsDigest;

    $signedInfoCanon = $signedInfoNode->C14N(true, false);

    $signature = '';
    $signAlgo = OPENSSL_ALGO_SHA256;

    if (!openssl_sign($signedInfoCanon, $signature, $privateKey, $signAlgo)) {
        exitWithError("Nie można podpisać: " . openssl_error_string());
    }

    $keyDetails = openssl_pkey_get_details($privateKey);
    if ($keyDetails['type'] === OPENSSL_KEYTYPE_EC) {
        $signature = convertECDSASignatureDERtoRaw($signature);
    }

    $sigValueNode = $xpath->query('//ds:SignatureValue')->item(0);
    $sigValueNode->nodeValue = base64_encode($signature);

    file_put_contents(__DIR__ . '/debug_final_signed.xml', $xml->saveXML());
    logStep("DEBUG: Zapisano XMLe debug");

    return $xml->saveXML();
}

function convertECDSASignatureDERtoRaw($derSignature) {
    $offset = 0;
    $length = strlen($derSignature);

    if (ord($derSignature[$offset++]) !== 0x30) {
        exitWithError("Nieprawidłowy format podpisu DER ECDSA (brak SEQUENCE)");
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
        exitWithError("Nieprawidłowy format podpisu DER ECDSA (brak INTEGER dla r)");
    }

    $rLen = ord($derSignature[$offset++]);
    $r = substr($derSignature, $offset, $rLen);
    $offset += $rLen;

    $r = ltrim($r, "\x00");

    if (ord($derSignature[$offset++]) !== 0x02) {
        exitWithError("Nieprawidłowy format podpisu DER ECDSA (brak INTEGER dla s)");
    }

    $sLen = ord($derSignature[$offset++]);
    $s = substr($derSignature, $offset, $sLen);

    $s = ltrim($s, "\x00");

    $targetLen = 32;

    $r = str_pad($r, $targetLen, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $targetLen, "\x00", STR_PAD_LEFT);

    return $r . $s;
}

// ============================================
// WYSYŁKA PODPISANEGO XML do KSeF (XAdES)
// ============================================

function sendSignedXMLAndGetAuthToken($signedXml) {
    global $isCli;

    logStep('[4/7] Wysyłam podpis do KSeF (XAdES)...');

    $ch = curl_init(URL_XADES);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $signedXml);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/xml',
        'Content-Length: ' . strlen($signedXml)
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CAINFO, CACERT_PATH);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        exitWithError("CURL error (XAdES): $error");
    }

    curl_close($ch);

    if (!in_array($httpCode, [200, 202])) {
        exitWithError("HTTP $httpCode z KSeF (XAdES):\n$response");
    }

    $data = json_decode($response, true);

    if (!$data) {
        exitWithError("Odpowiedź nie jest JSON (XAdES):\n$response");
    }

    $token          = $data['authenticationToken']['token'] ?? null;
    $validUntil     = $data['authenticationToken']['validUntil'] ?? null;
    $referenceNumber = $data['referenceNumber'] ?? null;

    if ($token && $referenceNumber) {
        logStep('OK: Otrzymano authenticationToken.');
        logStep("Numer operacji uwierzytelniania: $referenceNumber");

        if ($isCli) {
            echo "AUTHENTICATION_TOKEN=$token" . PHP_EOL;
            if ($validUntil) {
                echo "AUTH_TOKEN_VALID_UNTIL=$validUntil" . PHP_EOL;
            }
            echo "AUTH_REFERENCE_NUMBER=$referenceNumber" . PHP_EOL;
        } else {
            echo "<hr>";
            echo "<p><strong>AUTHENTICATION TOKEN:</strong><br><textarea style=\"width:100%;height:120px;\">"
                . htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . "</textarea></p>";
            if ($validUntil) {
                echo "<p><strong>AUTH_TOKEN VALID_UNTIL:</strong> "
                    . htmlspecialchars($validUntil, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
            }
            echo "<p><strong>AUTH_REFERENCE_NUMBER:</strong> "
                . htmlspecialchars($referenceNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
        }

        return [
            'authenticationToken' => $token,
            'validUntil'          => $validUntil,
            'referenceNumber'     => $referenceNumber,
            'rawResponse'         => $response,
        ];
    } else {
        exitWithError("Brak authenticationToken lub referenceNumber. Odpowiedź serwera (XAdES):\n$response");
    }
}

// ============================================
// CZEKANIE NA STATUS = 200 (sukces uwierzytelniania)
// ============================================

function waitForAuthSuccess(string $referenceNumber, string $authenticationToken, int $maxAttempts = 10, int $delaySeconds = 1) {
    global $isCli;

    logStep("Sprawdzam status uwierzytelniania (referenceNumber=$referenceNumber)...");

    for ($i = 1; $i <= $maxAttempts; $i++) {
        $ch = curl_init(URL_AUTH_STATUS . '/' . rawurlencode($referenceNumber));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $authenticationToken,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => CACERT_PATH,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            exitWithError("CURL error (auth status): $err");
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            exitWithError("HTTP $httpCode z KSeF (auth status):\n$response");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            exitWithError("Niepoprawny JSON z auth status:\n$response");
        }

        $statusCode = $data['status']['code'] ?? null;
        $statusDesc = $data['status']['description'] ?? '';
        $details    = $data['status']['details'] ?? [];

        if ($isCli) {
            echo "  Próba $i: status.code=$statusCode, description=$statusDesc" . PHP_EOL;
        }

        if ($statusCode === 200) {
            logStep("Uwierzytelnianie zakończone sukcesem.");
            return; 
        }

        if (in_array($statusCode, [400, 450, 460, 470, 500, 550], true)) {
            $detStr = is_array($details) ? implode(" | ", $details) : (string)$details;
            exitWithError("Uwierzytelnianie zakończone niepowodzeniem (code=$statusCode, desc=$statusDesc, details=$detStr)");
        }

        if ($i < $maxAttempts) {
            sleep($delaySeconds);
        }
    }

    exitWithError("Przekroczono limit prób sprawdzenia statusu uwierzytelniania – nadal brak kodu 200.");
}

// ============================================
// TOKEN REDEEM (AuthenticationToken -> Access/Refresh)
// ============================================

function redeemAuthToken($authenticationToken) {
    global $isCli;

    logStep('[5/7] Redeem authenticationToken -> accessToken/refreshToken...');

    $ch = curl_init(URL_TOKEN_REDEEM);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $authenticationToken,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO => CACERT_PATH,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        exitWithError("CURL error (token/redeem): $error");
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        exitWithError("HTTP $httpCode z KSeF (token/redeem):\n$response");
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        exitWithError("Niepoprawny JSON z token/redeem:\n$response");
    }

    $access = $data['accessToken']['token'] ?? null;
    $accessValid = $data['accessToken']['validUntil'] ?? null;
    $refresh = $data['refreshToken']['token'] ?? null;
    $refreshValid = $data['refreshToken']['validUntil'] ?? null;

    if (!$access || !$refresh) {
        exitWithError("Brak accessToken lub refreshToken w odpowiedzi:\n$response");
    }

    logStep("OK: Otrzymano accessToken i refreshToken.");

    if ($isCli) {
        echo "ACCESS_TOKEN=$access" . PHP_EOL;
        echo "ACCESS_TOKEN_VALID_UNTIL=$accessValid" . PHP_EOL;
        echo "REFRESH_TOKEN=$refresh" . PHP_EOL;
        echo "REFRESH_TOKEN_VALID_UNTIL=$refreshValid" . PHP_EOL;
    } else {
        echo "<p><strong>ACCESS TOKEN:</strong><br><textarea style=\"width:100%;height:120px;\">"
            . htmlspecialchars($access, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "</textarea></p>";
        echo "<p><strong>ACCESS_TOKEN VALID_UNTIL:</strong> "
            . htmlspecialchars($accessValid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
        echo "<p><strong>REFRESH TOKEN:</strong><br><textarea style=\"width:100%;height:120px;\">"
            . htmlspecialchars($refresh, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "</textarea></p>";
        echo "<p><strong>REFRESH_TOKEN VALID_UNTIL:</strong> "
            . htmlspecialchars($refreshValid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
    }

    return $data;
}

// ============================================
// GET /certificates/enrollments/data
// ============================================

function getEnrollmentData($accessToken) {
    logStep('[6/7] Pobieram dane do wniosku certyfikacyjnego (enrollments/data)...');

    $ch = curl_init(URL_ENROLL_DATA);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO => CACERT_PATH,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        exitWithError("CURL error (enrollments/data): $error");
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        exitWithError("HTTP $httpCode z KSeF (enrollments/data):\n$response");
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        exitWithError("Niepoprawny JSON z enrollments/data:\n$response");
    }

    logStep("OK: Dane do CSR pobrane.");
    return $data;
}

// ============================================
// CONFIG OpenSSL dla CSR (bez domyślnego O/ST)
// ============================================

function createKsefOpensslConfig() {
    $config = <<<CONF
distinguished_name = req_distinguished_name
prompt = no

[ req_distinguished_name ]
C  = Country Name
CN = Common Name
serialNumber = Serial Number
givenName = Given Name
surname = Surname
emailAddress = Email Address
L  = Locality Name
O  = Organization Name
organizationIdentifier = Organization Identifier
x500UniqueIdentifier = Unique Identifier
CONF;

    $tmp = tempnam(sys_get_temp_dir(), 'ksef_csr_') . '.cnf';
    file_put_contents($tmp, $config);
    return $tmp;
}


// ============================================
// GENEROWANIE NOWEJ PARY KLUCZY + CSR (RSA 2048)
// ============================================

function generateCsrFromEnrollmentData(array $enrollData, string $certificateName) {
    global $isCli;

    logStep('[7/7] Generuję nową parę kluczy i CSR (RSA 2048, zgodny z enrollments/data)...');

    if ($isCli) {
        echo "ENROLL_DATA=" . json_encode($enrollData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }

    $dn = [];

    $dn['C']  = $enrollData['countryName'] ?? 'PL';
    $dn['CN'] = $enrollData['commonName']  ?? $certificateName;

    if (!empty($enrollData['serialNumber'])) {
        $dn['serialNumber'] = $enrollData['serialNumber'];
    }

    if (!empty($enrollData['givenName'])) {
        $dn['givenName'] = $enrollData['givenName'];
    }
    if (!empty($enrollData['surname'])) {
        $dn['surname'] = $enrollData['surname'];
    }

    if (!empty($enrollData['emailAddress'])) {
        $dn['emailAddress'] = $enrollData['emailAddress'];
    }

    if (!empty($enrollData['localityName'])) {
        $dn['L'] = $enrollData['localityName'];
    }
    if (!empty($enrollData['organizationName'])) {
        $dn['O'] = $enrollData['organizationName'];
    }
    if (!empty($enrollData['organizationIdentifier'])) {
        $dn['organizationIdentifier'] = $enrollData['organizationIdentifier'];
    }

    if (!empty($enrollData['uniqueIdentifier'])) {
        $dn['x500UniqueIdentifier'] = $enrollData['uniqueIdentifier'];
    }

    $configPath = createKsefOpensslConfig();

    $privKeyConfig = [
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
        "private_key_bits" => 2048,
        "config"           => $configPath,
    ];

    $privKey = openssl_pkey_new($privKeyConfig);
    if (!$privKey) {
        exitWithError("Nie udało się wygenerować klucza dla nowego certyfikatu: " . openssl_error_string());
    }

    $csrConfig = [
        "digest_alg" => "sha256",
        "config"     => $configPath,
    ];

    $csr = openssl_csr_new($dn, $privKey, $csrConfig);
    if (!$csr) {
        exitWithError("Nie udało się wygenerować CSR: " . openssl_error_string());
    }

    openssl_csr_export($csr, $csrPem);

    file_put_contents(__DIR__ . '/debug_csr.pem', $csrPem);
    if ($isCli) {
        echo "DEBUG: CSR PEM zapisany do debug_csr.pem" . PHP_EOL;
    }

    if (!preg_match('/-----BEGIN CERTIFICATE REQUEST-----(.+)-----END CERTIFICATE REQUEST-----/s', $csrPem, $m)) {
        exitWithError("Nie udało się wyciągnąć CSR z PEM");
    }

    $csrDer = base64_decode(trim($m[1]), true);
    if ($csrDer === false) {
        exitWithError("Błąd dekodowania CSR DER");
    }

    $csrDerBase64 = base64_encode($csrDer);

    openssl_pkey_export($privKey, $privKeyPem, null, ["config" => $configPath]);

    file_put_contents(CSR_KEY_PATH, $privKeyPem);

    $infoTxt  = "CERTIFICATE NAME: " . $certificateName . PHP_EOL;
    $infoTxt .= "SUBJECT (DN):" . PHP_EOL;
    foreach ($dn as $k => $v) {
        $infoTxt .= "  $k=$v" . PHP_EOL;
    }
    $infoTxt .= PHP_EOL . "CSR (DER Base64):" . PHP_EOL . $csrDerBase64 . PHP_EOL;

    file_put_contents(CSR_INFO_PATH, $infoTxt);

    if ($isCli) {
        echo "Nowy klucz prywatny zapisano w: " . CSR_KEY_PATH . PHP_EOL;
        echo "Info o certyfikacie/CSR zapisano w: " . CSR_INFO_PATH . PHP_EOL;
        echo "CSR (DER Base64):" . PHP_EOL . $csrDerBase64 . PHP_EOL;
    } else {
        echo "<hr>";
        echo "<p><strong>Nowy klucz prywatny zapisano w:</strong> "
            . htmlspecialchars(CSR_KEY_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
        echo "<p><strong>Info o certyfikacie/CSR zapisano w:</strong> "
            . htmlspecialchars(CSR_INFO_PATH, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
        echo "<p><strong>CSR (DER Base64):</strong><br><textarea style=\"width:100%;height:120px;\">"
            . htmlspecialchars($csrDerBase64, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "</textarea></p>";
    }

    return [
        'csrDerBase64'  => $csrDerBase64,
        'privateKeyPem' => $privKeyPem,
        'subjectDn'     => $dn,
    ];
}

// ============================================
// WYSYŁKA WNIOSKU CERTYFIKACYJNEGO
// ============================================

function sendCertificateEnrollment(string $accessToken, string $csrDerBase64, string $certificateName) {
    global $isCli;

    logStep("Wysyłam wniosek certyfikacyjny (enrollments)...");

    $payload = [
        "certificateName" => $certificateName,
        "certificateType" => "Authentication",
        "csr"             => $csrDerBase64,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init(URL_ENROLL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO => CACERT_PATH,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        exitWithError("CURL error (enrollments): $error");
    }

    curl_close($ch);

	if ($httpCode !== 202) {
		$data = json_decode($response, true);
		$exCode = $data['exception']['exceptionDetailList'][0]['exceptionCode'] ?? null;

		if ($httpCode === 400 && (int)$exCode === 25007) {
			logStep("INFO: Osiągnięto limit certyfikatów w KSeF (exceptionCode=25007).");
			return $data;
		}

		exitWithError("HTTP $httpCode z KSeF (enrollments):\n$response");
	}

    $data = json_decode($response, true);
    if (!is_array($data)) {
        exitWithError("Niepoprawny JSON z enrollments:\n$response");
    }

    $ref = $data['referenceNumber'] ?? null;
    $ts  = $data['timestamp'] ?? null;

    logStep("OK: Wniosek przyjęty. referenceNumber=$ref");

    if ($isCli) {
        echo "CERT_ENROLL_REFERENCE=$ref" . PHP_EOL;
        echo "CERT_ENROLL_TIMESTAMP=$ts" . PHP_EOL;
    } else {
        echo "<p><strong>Wniosek certyfikacyjny przyjęty.</strong></p>";
        echo "<p><strong>referenceNumber:</strong> "
            . htmlspecialchars($ref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
        echo "<p><strong>timestamp:</strong> "
            . htmlspecialchars($ts, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
    }

    return $data;
}

// ============================================
// STATUS WNIOSKU CERTYFIKACYJNEGO
// ============================================

function waitForCertificateEnrollmentStatus(string $accessToken, string $referenceNumber, int $maxAttempts = 30, int $delaySeconds = 2) {
    global $isCli;

    logStep("Sprawdzam status wniosku certyfikacyjnego (referenceNumber=$referenceNumber)...");

    for ($i = 1; $i <= $maxAttempts; $i++) {
        $url = URL_ENROLL_STATUS . '/' . rawurlencode($referenceNumber);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => CACERT_PATH,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            exitWithError("CURL error (enrollments status): $err");
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            exitWithError("HTTP $httpCode z KSeF (enrollments status):\n$response");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            exitWithError("Niepoprawny JSON z enrollments status:\n$response");
        }

        $statusCode = $data['status']['code'] ?? null;
        $statusDesc = $data['status']['description'] ?? '';
        $statusDetails = $data['status']['details'] ?? [];
        $certSerial = $data['certificateSerialNumber'] ?? null;

        if ($isCli) {
            echo "  Próba $i: status.code=$statusCode, description=$statusDesc" . PHP_EOL;
        }

        if ($statusCode === 200) {
            logStep("Wniosek certyfikacyjny przetworzony (code=200).");
            if ($certSerial) {
                logStep("Numer seryjny certyfikatu: $certSerial");
            } else {
                logStep("Uwaga: code=200, ale brak certificateSerialNumber w odpowiedzi.");
            }
            return $data;
        }

        if ($statusCode === 100) {
            if ($i < $maxAttempts) {
                sleep($delaySeconds);
                continue;
            }
            exitWithError("Po $maxAttempts próbach wniosek nadal w statusie 100 (w toku).");
        }

        if (in_array($statusCode, [400, 500, 550], true)) {
            $detStr = is_array($statusDetails) ? implode(" | ", $statusDetails) : (string)$statusDetails;
            exitWithError("Wniosek certyfikacyjny odrzucony/niepowodzenie (code=$statusCode, desc=$statusDesc, details=$detStr)");
        }

        if ($i < $maxAttempts) {
            sleep($delaySeconds);
        }
    }

    exitWithError("Przekroczono limit prób sprawdzenia statusu wniosku certyfikacyjnego.");
}

// ============================================
// POBRANIE CERTYFIKATU PO NUMERZE SERYJNYM
// ============================================

function retrieveCertificateBySerial(string $accessToken, string $certificateSerialNumber) {
    global $isCli;

    logStep("Pobieram certyfikat (retrieve) dla numeru seryjnego: $certificateSerialNumber...");

    $payload = [
        "certificateSerialNumbers" => [$certificateSerialNumber],
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init(URL_CERT_RETRIEVE);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO => CACERT_PATH,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        exitWithError("CURL error (certificates/retrieve): $error");
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        exitWithError("HTTP $httpCode z KSeF (certificates/retrieve):\n$response");
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        exitWithError("Niepoprawny JSON z certificates/retrieve:\n$response");
    }

    $list = $data['certificates'] ?? [];
    if (empty($list)) {
        exitWithError("Brak certyfikatów w odpowiedzi certificates/retrieve");
    }

    $item = $list[0];

    $certB64 = $item['certificate'] ?? null;
    $certName = $item['certificateName'] ?? '';
    $certSerial = $item['certificateSerialNumber'] ?? $certificateSerialNumber;
    $certType = $item['certificateType'] ?? '';

    if (!$certB64) {
        exitWithError("Brak pola 'certificate' w odpowiedzi certificates/retrieve");
    }

    $certB64Clean = preg_replace('/\s+/', '', $certB64);

    $certDer = base64_decode($certB64Clean, true);
    if ($certDer === false) {
        exitWithError("Błąd dekodowania certyfikatu DER z Base64");
    }

    $pemBody = chunk_split($certB64Clean, 64, "\n");
    $certPem = "-----BEGIN CERTIFICATE-----\n" . $pemBody . "-----END CERTIFICATE-----\n";

    $baseName = 'ksef_cert_' . $certSerial;
    $derPath = CSR_DIR . '/' . $baseName . '.der';
    $crtPath = CSR_DIR . '/' . $baseName . '.crt';
    $metaPath = CSR_DIR . '/' . $baseName . '_meta.json';

    file_put_contents($derPath, $certDer);
    file_put_contents($crtPath, $certPem);

    $meta = [
        'certificateName'         => $certName,
        'certificateSerialNumber' => $certSerial,
        'certificateType'         => $certType,
        'fileDer'                 => $derPath,
        'filePem'                 => $crtPath,
        'retrievedAtUtc'          => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    logStep("Certyfikat zapisany:");
    logStep("  DER : $derPath");
    logStep("  PEM : $crtPath");
    logStep("  META: $metaPath");

    if (!$isCli) {
        echo "<hr>";
        echo "<p><strong>Certyfikat KSeF pobrany i zapisany.</strong></p>";
        echo "<p><strong>DER:</strong> " . htmlspecialchars($derPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
        echo "<p><strong>CRT (PEM):</strong> " . htmlspecialchars($crtPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
        echo "<p><strong>Meta:</strong> " . htmlspecialchars($metaPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
    }

    return [
        'paths' => [
            'der'  => $derPath,
            'pem'  => $crtPath,
            'meta' => $metaPath,
        ],
        'raw' => $data,
    ];
}

// ============================================
// MAIN
// ============================================

function main() {
    preChecks();

    $cert = loadCertificate();
    $privateKey = loadPrivateKey();
    validateKeyAndCert($cert, $privateKey);

    $challenge = fetchChallenge();
    $xml = buildAuthXML($challenge, $cert);
    $signedXml = signXMLPurePHP($xml, $privateKey);

    $authResult = sendSignedXMLAndGetAuthToken($signedXml);
    $authToken  = $authResult['authenticationToken'];
    $authRef    = $authResult['referenceNumber'];

    waitForAuthSuccess($authRef, $authToken);

    $tokens      = redeemAuthToken($authToken);
    $accessToken = $tokens['accessToken']['token'];

    $enrollData = getEnrollmentData($accessToken);

    $certificateName = 'Cert-KSeF-' . date('Ymd-His');
    $csrInfo = generateCsrFromEnrollmentData($enrollData, $certificateName);

    $enrollResp = sendCertificateEnrollment($accessToken, $csrInfo['csrDerBase64'], $certificateName);
    $enrollRef  = $enrollResp['referenceNumber'] ?? null;

    if ($enrollRef) {
        $statusResp = waitForCertificateEnrollmentStatus($accessToken, $enrollRef);
        $certSerial = $statusResp['certificateSerialNumber'] ?? null;

        if ($certSerial) {
            retrieveCertificateBySerial($accessToken, $certSerial);
        } else {
            logStep("Brak certificateSerialNumber w statusie – nie pobieram certyfikatu.");
        }
    } else {
        logStep("Brak referenceNumber z enrollments – nie sprawdzam statusu wniosku.");
    }
}

main();
