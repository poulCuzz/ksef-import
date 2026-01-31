// ============================================================================
// KONFIGURACJA
// ============================================================================

const CONFIG = {
    maxAttempts: 60,
    checkIntervalMs: 3000,
    apiEndpoint: 'api.php'
};

// ============================================================================
// ZMIENNE GLOBALNE
// ============================================================================

let sessionId = null;
let checkInterval = null;
let attemptCount = 0;

// ============================================================================
// ELEMENTY DOM
// ============================================================================

const elements = {
    form: document.getElementById('exportForm'),
    submitBtn: document.getElementById('submitBtn'),
    statusPanel: document.getElementById('statusPanel'),
    spinner: document.getElementById('spinner'),
    checkmark: document.getElementById('checkmark'),
    errorIcon: document.getElementById('errorIcon'),
    warningIcon: document.getElementById('warningIcon'),
    infoIcon: document.getElementById('infoIcon'),
    statusTitle: document.getElementById('statusTitle'),
    statusMessage: document.getElementById('statusMessage'),
    statusDetails: document.getElementById('statusDetails'),
    progressBar: document.getElementById('progressBar'),
    progressFill: document.getElementById('progressFill'),
    attemptCounter: document.getElementById('attemptCounter'),
    downloadList: document.getElementById('downloadList'),
    suggestionsList: document.getElementById('suggestionsList'),
    errorCode: document.getElementById('errorCode'),
    dateFrom: document.getElementById('date_from'),
    dateTo: document.getElementById('date_to'),
    // Elementy przełącznika metody uwierzytelniania
    authMethod: document.getElementById('auth_method'),
    tokenForm: document.getElementById('token-form'),
    certificateForm: document.getElementById('certificate-form'),
    // Nowe elementy dla listy faktur
    invoicesBox: document.getElementById('invoicesBox'),
    invoicesSummary: document.getElementById('invoicesSummary'),
    invoicesTableBody: document.getElementById('invoicesTableBody')
};

// ============================================================================
// INICJALIZACJA
// ============================================================================

// Ustaw domyślne daty (ostatni miesiąc)
function initializeDates() {
    const today = new Date();
    const monthAgo = new Date();
    monthAgo.setMonth(monthAgo.getMonth() - 1);
    
    elements.dateTo.value = today.toISOString().split('T')[0];
    elements.dateFrom.value = monthAgo.toISOString().split('T')[0];
}

// ============================================================================
// OBSŁUGA PRZEŁĄCZNIKA METODY UWIERZYTELNIANIA
// ============================================================================

function initializeAuthMethodSwitcher() {
    const methodButtons = document.querySelectorAll('.method-btn');
    
    methodButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault(); // Zapobiegaj domyślnej akcji
            
            const method = this.getAttribute('data-method');
            
            // Aktualizuj aktywny przycisk
            methodButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Ustaw wartość ukrytego pola
            if (elements.authMethod) {
                elements.authMethod.value = method;
            }
            
            // Przełącz widoczność formularzy
            if (method === 'token') {
                if (elements.tokenForm) {
                    elements.tokenForm.classList.add('active');
                }
                if (elements.certificateForm) {
                    elements.certificateForm.classList.remove('active');
                }
            } else if (method === 'certificate') {
                if (elements.tokenForm) {
                    elements.tokenForm.classList.remove('active');
                }
                if (elements.certificateForm) {
                    elements.certificateForm.classList.add('active');
                }
            }
        });
    });
}

// ============================================================================
// OBSŁUGA FORMULARZA
// ============================================================================

elements.form.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    // Reset stanu
    resetState();
    
    // UI - start
    setLoadingState();
    updateStatus('Łączenie z KSeF...', 'Autoryzacja i inicjacja importu');
    
    try {
        // Krok 1: Start importu
        const formData = new FormData(elements.form);
        formData.append('action', 'start_import');
        
        // Debug - pokaż co wysyłamy
        console.log('Wysyłam formularz:');
        for (let [key, value] of formData.entries()) {
            if (key === 'p12_password' || key === 'ksef_token') {
                console.log(`  ${key}: ***`);
            } else {
                console.log(`  ${key}: ${value}`);
            }
        }
        
        const response = await fetch(CONFIG.apiEndpoint, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        console.log('Odpowiedź API:', data);
        
        if (!data.success) {
            if (data.errorType || data.error_type) {
                showError({
                    errorType: data.errorType || data.error_type,
                    message: data.error || data.message,
                    title: data.title || 'Błąd',
                    suggestions: data.suggestions || []
                });
                return;
            }
            throw new Error(data.error || data.message || 'Nieznany błąd');
        }
        
        sessionId = data.sessionId;
        elements.progressFill.style.width = '30%';
        updateStatus('Import rozpoczęty', `Reference: ${data.referenceNumber}`);
        elements.statusDetails.textContent = `Reference Number: ${data.referenceNumber}`;
        
        // Krok 2: Sprawdzaj status co 3 sekundy
        checkInterval = setInterval(checkExportStatus, CONFIG.checkIntervalMs);
        checkExportStatus(); // Pierwsze sprawdzenie od razu
        
    } catch (error) {
        console.error('Błąd:', error);
        // Sprawdź czy to odpowiedź z API z klasyfikacją
        if (error.errorType) {
            showError(error);
        } else {
            showError({
                errorType: 'app_error',
                errorCode: 'UNKNOWN',
                title: 'Wystąpił błąd',
                message: error.message,
                suggestions: ['Spróbuj ponownie']
            });
        }
    }
});

// ============================================================================
// SPRAWDZANIE STATUSU EKSPORTU
// ============================================================================

async function checkExportStatus() {
    attemptCount++;
    
    if (attemptCount > CONFIG.maxAttempts) {
        clearInterval(checkInterval);
        showError({
            errorType: 'info',
            title: 'Przekroczono limit czasu',
            message: 'Przekroczono limit czasu oczekiwania (3 minuty). Spróbuj ponownie później.',
            suggestions: [
                'Sprawdź czy eksport nie jest zbyt duży',
                'Spróbuj zawęzić zakres dat',
                'Sprawdź status ręcznie za kilka minut'
            ]
        });
        return;
    }
    
    // Update progress
    const progress = 30 + (attemptCount / CONFIG.maxAttempts) * 60;
    elements.progressFill.style.width = `${Math.min(progress, 90)}%`;
    elements.attemptCounter.textContent = `Sprawdzanie statusu... (próba ${attemptCount}/${CONFIG.maxAttempts})`;
    
    try {
        const response = await fetch(`${CONFIG.apiEndpoint}?action=check_status&session=${encodeURIComponent(sessionId)}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Błąd sprawdzania statusu');
        }
        
        updateStatus(
            data.ready ? 'Import gotowy!' : 'Oczekiwanie na import...',
            data.message
        );
        elements.statusDetails.textContent = `Status: ${data.statusCode} - ${data.statusDesc}`;
        
        if (data.ready) {
            clearInterval(checkInterval);
            await downloadAllParts(data.filesCount);
        }
        
    } catch (error) {
        // Nie przerywaj przy pojedynczym błędzie - spróbuj ponownie
        console.error('Check status error:', error);
        updateStatus('Ponawiam sprawdzanie...', error.message);
    }
}

// ============================================================================
// POBIERANIE WSZYSTKICH CZĘŚCI
// ============================================================================

async function downloadAllParts(filesCount) {
    updateStatus('Pobieranie faktur...', `Pobieranie ${filesCount} paczek...`);
    elements.progressFill.style.width = '95%';
    
    try {
        // Pobierz wszystkie części sekwencyjnie
        for (let i = 0; i < filesCount; i++) {
            const url = `${CONFIG.apiEndpoint}?action=download&session=${encodeURIComponent(sessionId)}&part=${i}`;
            const response = await fetch(url);
            const result = await response.json();
            
            if (!result.success) {
                console.error(`Błąd pobierania części ${i}:`, result.message);
            }
        }
        
        // Po pobraniu wszystkich - pokaż sukces i załaduj listę faktur
        showSuccess(filesCount);
        await loadInvoicesList();
        
    } catch (error) {
        console.error('Download error:', error);
        showError({
            errorType: 'server_error',
            title: 'Błąd pobierania',
            message: error.message,
            suggestions: ['Spróbuj ponownie']
        });
    }
}

// ============================================================================
// ŁADOWANIE LISTY FAKTUR
// ============================================================================

async function loadInvoicesList() {
    if (!sessionId) return;
    if (!elements.invoicesBox) return; // Element może nie istnieć
    
    try {
        const response = await fetch(`${CONFIG.apiEndpoint}?action=list_invoices&session=${encodeURIComponent(sessionId)}`);
        const data = await response.json();
        
        if (!data.success || !data.invoices || data.invoices.length === 0) {
            elements.invoicesBox.style.display = 'none';
            return;
        }
        
        // Pokaż sekcję faktur
        elements.invoicesBox.style.display = 'block';
        
        // Podsumowanie
        const totalGross = data.invoices.reduce((sum, inv) => sum + (inv.grossAmount || 0), 0);
        const totalNet = data.invoices.reduce((sum, inv) => sum + (inv.netAmount || 0), 0);
        const totalVat = data.invoices.reduce((sum, inv) => sum + (inv.vatAmount || 0), 0);
        
        elements.invoicesSummary.innerHTML = `
            <div class="summary-row">
                <span>Liczba faktur: <strong>${data.count}</strong></span>
                <span>Suma netto: <strong>${formatCurrency(totalNet)}</strong></span>
                <span>VAT: <strong>${formatCurrency(totalVat)}</strong></span>
                <span>Suma brutto: <strong>${formatCurrency(totalGross)}</strong></span>
            </div>
        `;
        
        // Wypełnij tabelę
        elements.invoicesTableBody.innerHTML = '';
        
        data.invoices.forEach(invoice => {
            const row = document.createElement('tr');
            row.className = 'invoice-row';
            row.style.cursor = 'pointer';
            
            // Kliknięcie otwiera podgląd faktury
            row.addEventListener('click', () => {
                if (invoice.ksefNumber && invoice.ksefNumber !== '-') {
                    window.open(`invoice.html?ksef=${encodeURIComponent(invoice.ksefNumber)}`, '_blank');
                }
            });
            
            // Określ czy to faktura sprzedaży czy zakupu (na podstawie Subject1/Subject2)
            const subjectType = document.getElementById('subject_type').value;
            const kontrahent = subjectType === 'Subject1' 
                ? `${invoice.buyer} (${invoice.buyerNip})`
                : `${invoice.seller} (${invoice.sellerNip})`;
            
            row.innerHTML = `
                <td class="invoice-number">${escapeHtml(invoice.invoiceNumber)}</td>
                <td>${formatDate(invoice.issueDate)}</td>
                <td class="kontrahent-cell" title="${escapeHtml(kontrahent)}">${escapeHtml(truncate(kontrahent, 40))}</td>
                <td class="amount-col">${formatCurrency(invoice.grossAmount)} ${invoice.currency}</td>
            `;
            
            elements.invoicesTableBody.appendChild(row);
        });
        
        // Scroll do listy faktur
        elements.invoicesBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
        
    } catch (error) {
        console.error('Error loading invoices list:', error);
    }
}

// ============================================================================
// FUNKCJE POMOCNICZE - FORMATOWANIE
// ============================================================================

function formatCurrency(amount) {
    return new Intl.NumberFormat('pl-PL', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount || 0);
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('pl-PL');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function truncate(str, maxLength) {
    if (!str) return '';
    if (str.length <= maxLength) return str;
    return str.substring(0, maxLength - 3) + '...';
}

// ============================================================================
// ZARZĄDZANIE STANEM UI
// ============================================================================

function resetState() {
    attemptCount = 0;
    sessionId = null;
    if (checkInterval) clearInterval(checkInterval);
    
    // Ukryj listę faktur przy nowym imporcie
    if (elements.invoicesBox) {
        elements.invoicesBox.style.display = 'none';
    }
    if (elements.invoicesTableBody) {
        elements.invoicesTableBody.innerHTML = '';
    }
}

function setLoadingState() {
    elements.submitBtn.disabled = true;
    elements.submitBtn.textContent = 'Przetwarzanie...';
    elements.statusPanel.className = 'status-panel show';
    elements.spinner.style.display = 'block';
    elements.checkmark.style.display = 'none';
    elements.errorIcon.style.display = 'none';
    elements.warningIcon.style.display = 'none';
    elements.infoIcon.style.display = 'none';
    elements.progressBar.style.display = 'block';
    elements.progressFill.style.width = '10%';
    elements.downloadList.style.display = 'none';
    elements.downloadList.innerHTML = '';
    elements.suggestionsList.style.display = 'none';
    elements.suggestionsList.innerHTML = '';
    elements.errorCode.textContent = '';
}

function updateStatus(title, message) {
    elements.statusTitle.textContent = title;
    elements.statusMessage.textContent = message;
}

function hideAllIcons() {
    elements.spinner.style.display = 'none';
    elements.checkmark.style.display = 'none';
    elements.errorIcon.style.display = 'none';
    elements.warningIcon.style.display = 'none';
    elements.infoIcon.style.display = 'none';
}

// ============================================================================
// SUKCES
// ============================================================================

function showSuccess(filesCount) {
    elements.statusPanel.className = 'status-panel show success';
    hideAllIcons();
    elements.checkmark.style.display = 'flex';
    elements.progressFill.style.width = '100%';
    elements.progressBar.style.display = 'none';
    elements.attemptCounter.textContent = '';
    
    elements.statusTitle.textContent = 'Import zakończony!';
    elements.statusMessage.textContent = `Pobrano ${filesCount} paczek. Faktury zapisane w folderze xml/`;
    elements.statusDetails.textContent = '';
    
    // Odblokuj formularz
    elements.submitBtn.disabled = false;
    elements.submitBtn.textContent = 'Importuj ponownie';
}

// ============================================================================
// OBSŁUGA BŁĘDÓW
// ============================================================================

function showError(data) {
    if (checkInterval) clearInterval(checkInterval);
    
    hideAllIcons();
    elements.progressBar.style.display = 'none';
    elements.attemptCounter.textContent = '';
    
    // Obsługa prostego stringa (stary format)
    if (typeof data === 'string') {
        data = {
            errorType: 'unknown_error',
            errorCode: 'UNKNOWN',
            title: 'Wystąpił błąd',
            message: data,
            suggestions: ['Spróbuj ponownie']
        };
    }
    
    // Wybierz styl i ikonę w zależności od typu błędu
    switch (data.errorType) {
        case 'user_error':
            elements.statusPanel.className = 'status-panel show warning';
            elements.warningIcon.style.display = 'flex';
            elements.statusTitle.textContent = (data.title || 'Błąd danych');
            break;
        case 'info':
            elements.statusPanel.className = 'status-panel show info';
            elements.infoIcon.style.display = 'flex';
            elements.statusTitle.textContent = (data.title || 'Informacja');
            break;
        case 'server_error':
            elements.statusPanel.className = 'status-panel show error';
            elements.errorIcon.style.display = 'flex';
            elements.statusTitle.textContent = (data.title || 'Błąd serwera');
            break;
        case 'app_error':
            elements.statusPanel.className = 'status-panel show error';
            elements.errorIcon.style.display = 'flex';
            elements.statusTitle.textContent = (data.title || 'Błąd aplikacji');
            break;
        default:
            elements.statusPanel.className = 'status-panel show error';
            elements.errorIcon.style.display = 'flex';
            elements.statusTitle.textContent = (data.title || 'Wystąpił błąd');
    }
    
    elements.statusMessage.textContent = data.message || 'Nieznany błąd';
    elements.statusDetails.textContent = '';
    
    // Pokaż sugestie
    if (data.suggestions && data.suggestions.length > 0) {
        elements.suggestionsList.style.display = 'block';
        elements.suggestionsList.innerHTML = '<strong>Co sprawdzić:</strong><ul>' + 
            data.suggestions.map(s => `<li>${s}</li>`).join('') + 
            '</ul>';
    } else {
        elements.suggestionsList.style.display = 'none';
    }
    
    // Pokaż kod błędu
    if (data.errorCode) {
        elements.errorCode.textContent = `Kod błędu: ${data.errorCode}`;
    }
    
    // Odblokuj formularz
    elements.submitBtn.disabled = false;
    elements.submitBtn.textContent = 'Spróbuj ponownie';
}

// ============================================================================
// URUCHOMIENIE APLIKACJI
// ============================================================================

// Inicjalizuj daty przy załadowaniu strony
initializeDates();

// Inicjalizuj przełącznik metody uwierzytelniania
initializeAuthMethodSwitcher();

// ============================================================================
// LOGIKA DLA SETTINGS.HTML
// ============================================================================

// Sprawdź czy jesteśmy na stronie settings.html
if (document.getElementById('settingsForm')) {
    initializeSettings();
}

function initializeSettings() {
    const settingsElements = {
        form: document.getElementById('settingsForm'),
        saveBtn: document.getElementById('saveBtn'),
        saveMessage: document.getElementById('saveMessage'),
        companyName: document.getElementById('companyName'),
        companyNip: document.getElementById('companyNip'),
        companyToken: document.getElementById('companyToken'),
        companyEnv: document.getElementById('companyEnv')
    };

    // ============================================================================
    // ŁADOWANIE USTAWIEŃ
    // ============================================================================

    async function loadSettings() {
        try {
            const response = await fetch(`${CONFIG.apiEndpoint}?action=settings_get`);
            const data = await response.json();
            
            if (!data.success) {
                showSettingsMessage('error', 'Błąd ładowania: ' + (data.error || 'Nieznany błąd'));
                return;
            }
            
            settingsElements.companyName.value = data.data.company_name || '';
            settingsElements.companyNip.value = data.data.nip || '';
            settingsElements.companyToken.value = data.data.ksef_token || '';
            settingsElements.companyEnv.value = data.data.env || 'demo';
            
        } catch (error) {
            showSettingsMessage('error', 'Błąd połączenia z serwerem');
            console.error('Load settings error:', error);
        }
    }

    // ============================================================================
    // ZAPISYWANIE USTAWIEŃ
    // ============================================================================

    async function saveSettings(e) {
        e.preventDefault();
        
        // Walidacja NIP
        const nip = settingsElements.companyNip.value.replace(/[^0-9]/g, '');
        if (nip && nip.length !== 10) {
            showSettingsMessage('error', 'NIP musi mieć dokładnie 10 cyfr');
            return;
        }
        
        // Blokuj przycisk podczas zapisywania
        settingsElements.saveBtn.disabled = true;
        settingsElements.saveBtn.textContent = 'Zapisywanie...';
        
        try {
            const payload = {
                company_name: settingsElements.companyName.value,
                nip: nip,
                ksef_token: settingsElements.companyToken.value,
                env: settingsElements.companyEnv.value
            };
            
            const response = await fetch(`${CONFIG.apiEndpoint}?action=settings_save`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });
            
            const result = await response.json();
            
            if (result.success) {
                showSettingsMessage('success', '✓ Ustawienia zostały zapisane pomyślnie!');
            } else {
                showSettingsMessage('error', '✕ Błąd: ' + (result.error || 'Nieznany błąd'));
            }
            
        } catch (error) {
            showSettingsMessage('error', '✕ Błąd połączenia z serwerem');
            console.error('Save settings error:', error);
        } finally {
            settingsElements.saveBtn.disabled = false;
            settingsElements.saveBtn.textContent = 'Zapisz ustawienia';
        }
    }

    // ============================================================================
    // WYŚWIETLANIE KOMUNIKATÓW
    // ============================================================================

    function showSettingsMessage(type, message) {
        settingsElements.saveMessage.className = `save-message ${type} show`;
        settingsElements.saveMessage.textContent = message;
        
        // Ukryj komunikat po 5 sekundach
        setTimeout(() => {
            settingsElements.saveMessage.classList.remove('show');
        }, 5000);
    }

    // ============================================================================
    // FORMATOWANIE NIP (usuwa wszystko oprócz cyfr)
    // ============================================================================

    settingsElements.companyNip.addEventListener('input', (e) => {
        e.target.value = e.target.value.replace(/[^0-9]/g, '');
    });

    // ============================================================================
    // INICJALIZACJA SETTINGS
    // ============================================================================

    settingsElements.form.addEventListener('submit', saveSettings);
    loadSettings();
}
