<h1 align="center">KSeF Import</h1>

<p align="center">
  <strong>Importowanie faktur z Krajowego Systemu e-Faktur w kilka kliknięć</strong>
</p>

<p align="center">
  <a href="#funkcje">Funkcje</a> •
  <a href="#quick-start">Quick Start</a> •
  <a href="#instalacja">Instalacja</a> •
  <a href="#dokumentacja">Dokumentacja</a>
</p>

---

## Podgląd

<p align="center">
  <img src="docs/images/screenshot-success.png" alt="Sukces" width="400">
</p>

---

## Funkcje

- **Prosty interfejs** - import faktur w 3 krokach
- **Automatyczne odpytywanie** - nie musisz odświeżać strony
- **Bezpieczna autoryzacja** - szyfrowanie RSA-OAEP + AES-256
- **Pobieranie ZIP** - faktury pobierane bezpośrednio do przeglądarki
- **Inteligentne błędy** - jasne komunikaty co poszło nie tak
- **Logowanie** - pełna historia operacji API

---

## Quick Start

```bash
# 1. Sklonuj repozytorium
git clone https://github.com/poulCuzz/ksef-import.git

# 2. Zainstaluj zależności
cd ksef-import
composer install

# 3. Uruchom serwer
php -S localhost:8000

# 4. Otwórz http://localhost:8000
```

---

## Instalacja

### Wymagania

- PHP 8.0 lub nowszy
- Rozszerzenia PHP: `curl`, `openssl`, `json`
- Composer
- Token KSeF (wygenerowany w panelu Ministerstwa Finansów)

### Krok 1: Pobierz projekt

```bash
git clone https://github.com/poulCuzz/ksef-import.git
cd ksef-import
```

### Krok 2: Zainstaluj zależności

```bash
composer install
```

### Krok 3: Utwórz wymagane katalogi

```bash
mkdir -p logs temp
chmod 777 logs temp
```

### Krok 4: Uruchom

**Opcja A: Wbudowany serwer PHP (development)**
```bash
php -S localhost:8000
```

**Opcja B: Apache/Nginx (production)**

Skonfiguruj virtual host wskazujący na katalog projektu.

---

## Dokumentacja

### Jak uzyskać Token KSeF?

1. Wejdź na [ksef-demo.mf.gov.pl](https://ksef-demo.mf.gov.pl) (DEMO) lub [ksef-test.mf.gov.pl](https://ksef-test.mf.gov.pl) (TEST)
2. Zaloguj się przez profil zaufany lub certyfikat
3. Przejdź do **Tokeny** → **Generuj nowy token**
4. Skopiuj wygenerowany token

> **Ważne:** Token z DEMO działa tylko na środowisku DEMO. Token z TEST tylko na TEST.

### Struktura projektu

```
ksef-invoices-main/
│
├── api.php
├── index.html
├── styles.css
├── app.js
├── composer.json
├── LICENSE
├── README.md
├── .gitignore
│
├── src/
│   │
│   ├── Api/
│   │   ├── Helpers.php
│   │   ├── ErrorHandler.php
│   │   │
│   │   └── Actions/
│   │       ├── StartExportAction.php
│   │       ├── CheckStatusAction.php
│   │       └── DownloadAction.php
│   │
│   ├── Auth/
│   │   ├── AuthenticatorInterface.php
│   │   ├── KsefAuthenticator.php
│   │   ├── TokenEncryptor.php
│   │   └── public_key.pem
│   │
│   ├── Export/
│   │   ├── EncryptionHandler.php
│   │   ├── ExporterInterface.php
│   │   ├── FileDecryptor.php
│   │   ├── KsefExporter.php
│   │   ├── last_export_encryption.json
│   │   └── public_key_symetric_encryption.pem
│   │
│   ├── Http/
│   │   └── KsefClient.php
│   │
│   ├── Logger/
│   │   ├── JsonLogger.php
│   │   └── LoggerInterface.php
│   │
│   └── KsefService.php
│
├── docs/
├── logs/
├── temp/
└── vendor/
```

### API Endpoints

| Endpoint | Metoda | Opis |
|----------|--------|------|
| `api.php?action=start_export` | POST | Rozpoczyna export faktur z KSeF API|
| `api.php?action=check_status&session=XXX` | GET | Sprawdza status exportu z KSeF API |
| `api.php?action=download&session=XXX&part=0` | GET | Pobiera plik ZIP |

### Parametry importu (z perspektywy KSeF API eksportu)

| Parametr | Opis | Przykład |
|----------|------|----------|
| `env` | Środowisko (demo/test) | `demo` |
| `ksef_token` | Token autoryzacyjny | `20251124-EC-...` |
| `nip` | NIP firmy (10 cyfr) | `1234567890` |
| `subject_type` | Typ podmiotu | `Subject1` (sprzedawca) / `Subject2` (nabywca) |
| `date_from` | Data od | `2024-01-01` |
| `date_to` | Data do | `2024-12-31` |

---

## Rozwiązywanie problemów

### "Błąd autoryzacji"

- Sprawdź czy token jest poprawny
- Sprawdź czy NIP zgadza się z tokenem
- Sprawdź czy środowisko (DEMO/TEST) pasuje do tokena

### "Import nie jest jeszcze gotowy"

- To normalne na środowisku TEST - serwer przetwarza żądania w kolejce
- Aplikacja automatycznie odpytuje co 3 sekundy (max 3 minuty)

---

## Autor

**poulCuzz** - [GitHub](https://github.com/poulCuzz)

---

## Wsparcie projektu ☕️

Jeśli chcesz podziękować autorowi symboliczną kwotą na kawę, możesz użyć BLIKa:  

**nr tel do BLIK:** 603115866  

Dziękuję za wsparcie! 🙏

