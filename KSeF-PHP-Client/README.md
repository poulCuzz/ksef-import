# KSeF PHP Client

Ten projekt zawiera **jednoplilkowy skrypt PHP**, który realizuje
kompletny przepływ komunikacji z KSeF w środowisku testowym:

1.  Uwierzytelnianie z użyciem podpisu **XAdES**,
2.  Zamianę `authenticationToken` na `accessToken` / `refreshToken`,
3.  Pobranie danych do wniosku certyfikacyjnego (**enrollments/data**),
4.  Wygenerowanie nowej pary kluczy + CSR,
5.  Złożenie wniosku o certyfikat (**enrollments**),
6.  Sprawdzenie statusu wniosku,
7.  Pobranie wystawionego certyfikatu i zapis do plików `.der` / `.crt`.

Docelowo ma z tego powstać **klient PHP do KSeF**, a obecny skrypt jest
działającym „proof of concept" / narzędziem pomocniczym.

------------------------------------------------------------------------

## Obecna funkcjonalność

Skrypt wykonuje kroki:

1.  **Wstępne sprawdzenia**
    -   Wymagane rozszerzenia PHP: `openssl`, `curl`, `dom`,
        `xmlwriter`, `hash`.
    -   Sprawdza, czy istnieją pliki:
        -   certyfikatu XAdES: `CERT_PATH`,
        -   klucza prywatnego XAdES: `KEY_PATH`,
        -   hasła do klucza: `PASS_PATH`.
    -   Jeśli brakuje `cacert.pem`, pobiera go z
        `https://curl.se/ca/cacert.pem`.
2.  **Wczytanie certyfikatu XAdES**
    -   Wczytuje certyfikat (`.crt`) i klucz prywatny (`.key`).
    -   Sprawdza, czy klucz prywatny pasuje do certyfikatu.
3.  **Uwierzytelnienie XAdES w KSeF**
    -   `POST /api/v2/auth/challenge` -- pobiera **challenge** na
        podstawie NIP.
    -   Buduje XML `AuthTokenRequest` z podpisem **XMLDSIG + XAdES**
        (pure PHP).
    -   Podpisany XML wysyła do `POST /api/v2/auth/xades-signature`.
    -   Z odpowiedzi odczytuje:
        -   `authenticationToken`,
        -   `validUntil`,
        -   `referenceNumber`.
4.  **Sprawdzenie statusu uwierzytelniania**
    -   W pętli odpyta `GET /api/v2/auth/{referenceNumber}` z nagłówkiem
        `Authorization: Bearer {authenticationToken}`.
    -   Czeka, aż `status.code == 200`, w przeciwnym razie zgłasza błąd.
5.  **Redeem token -- access/refresh**
    -   `POST /api/v2/auth/token/redeem` z
        `Authorization: Bearer {authenticationToken}`.
    -   Otrzymuje:
        -   `accessToken` (z datą ważności),
        -   `refreshToken` (z datą ważności).
6.  **Dane do wniosku certyfikacyjnego (CSR)**
    -   `GET /api/v2/certificates/enrollments/data` z
        `Authorization: Bearer {accessToken}`.
    -   Odpowiedź zawiera dane, które zostają użyte w **Subject DN**.
7.  **Generowanie nowej pary kluczy i CSR**
    -   Generuje nowy klucz RSA 2048.
    -   Buduje CSR zgodny z danymi z `enrollments/data` i zapisuje je
        wraz z kluczem.
8.  **Wysłanie wniosku certyfikacyjnego**
    -   `POST /api/v2/certificates/enrollments`
    -   Obsługuje błąd limitu certyfikatów (25007).
9.  **Sprawdzanie statusu wniosku**
    -   `GET /api/v2/certificates/enrollments/{referenceNumber}` aż
        `status.code == 200`.
10. **Pobranie wystawionego certyfikatu**
    -   `POST /api/v2/certificates/retrieve`
    -   Zapis certyfikatu do `.der`, `.crt` i JSON.

------------------------------------------------------------------------

## Wymagania

-   PHP 7.4+ z rozszerzeniami: `openssl`, `curl`, `dom`, `xmlwriter`,
    `hash`
-   Środowisko testowe KSeF: `https://ksef-test.mf.gov.pl`
-   Certyfikat + klucz prywatny XAdES

------------------------------------------------------------------------

## Konfiguracja

``` php
define('NIP', '1234567890');
define('CERT_PATH', __DIR__ . '/testowy/cert.crt');
define('KEY_PATH', __DIR__ . '/testowy/cert.key');
define('PASS_PATH', __DIR__ . '/testowy/pass.txt');
define('CACERT_PATH', __DIR__ . '/cacert.pem');
define('CSR_DIR', __DIR__ . '/wniosek_ksef');
```

------------------------------------------------------------------------

## Uruchomienie

### CLI

``` bash
php ksef_client.php
```

### Przeglądarka

Otwórz plik w przeglądarce -- skrypt wyświetli logi w HTML.

------------------------------------------------------------------------

## Bezpieczeństwo

Aktualny skrypt to narzędzie developerskie. Przed produkcją:

-   nie logować tokenów,
-   nie trzymać hasła do klucza w pliku,
-   ograniczyć uprawnienia katalogów,
-   dostarczać `cacert.pem` bez pobierania online.
