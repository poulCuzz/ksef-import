// ============================================================================
// ZMIENNE GLOBALNE
// ============================================================================

let sessionId = null;
let checkInterval = null;
let attemptCount = 0;
const maxAttempts = 60; // 60 prób × 3 sekundy = 3 minuty
const checkIntervalMs = 3000; // 3 sekundy

// ============================================================================
// ELEMENTY DOM
// ============================================================================

const form = document.getElementById('exportForm');
const submitBtn = document.getElementById('submitBtn');
const statusPanel = document.getElementById('statusPanel');
const spinner = document.getElementById('spinner');
const checkmark = document.getElementById('checkmark');
const errorIcon = document.getElementById('errorIcon');
const statusTitle = document.getElementById('statusTitle');
const statusMessage = document.getElementById('statusMessage');
const statusDetails = document.getElementById('statusDetails');
const progressBar = document.getElementById('progressBar');
const progressFill = document.getElementById('progressFill');
const attemptCounter = document.getElementById('attemptCounter');
const downloadList = document.getElementById('downloadList');
const warningIcon = document.getElementById('warningIcon');
const infoIcon = document.getElementById('infoIcon');
const suggestionsList = document.getElementById('suggestionsList');
const errorCodeEl = document.getElementById('errorCode');

// ============================================================================
// OBSŁUGA PRZEŁĄCZNIKA METODY UWIERZYTELNIANIA
// ============================================================================

function initializeAuthMethodSwitcher() {
    const methodButtons = document.querySelectorAll('.method-btn');
    const authMethodInput = document.getElementById('auth_method');
    const tokenForm = document.getElementById('token-form');
    const certificateForm = document.getElementById('certificate-form');
    
    if (!methodButtons.length) return; // Brak przełącznika w HTML
    
    methodButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const method = this.getAttribute('data-method');
            
            // Aktualizuj aktywny przycisk
            methodButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Ustaw wartość ukrytego pola
            if (authMethodInput) {
                authMethodInput.value = method;
            }
            
            // Przełącz widoczność formularzy
            if (method === 'token') {
                if (tokenForm) tokenForm.classList.add('active');
                if (certificateForm) certificateForm.classList.remove('active');
            } else if (method === 'certificate') {
                if (tokenForm) tokenForm.classList.remove('active');
                if (certificateForm) certificateForm.classList.add('active');
            }
        });
    });
}

// ============================================================================
// OBSŁUGA FORMULARZA
// ============================================================================

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    // Reset
    attemptCount = 0;
    sessionId = null;
    if (checkInterval) clearInterval(checkInterval);
    
    // UI - start
    submitBtn.disabled = true;
    submitBtn.textContent = 'Przetwarzanie...';
    statusPanel.className = 'status-panel show';
    spinner.style.display = 'block';
    checkmark.style.display = 'none';
    errorIcon.style.display = 'none';
    progressBar.style.display = 'block';
    progressFill.style.width = '10%';
    downloadList.style.display = 'none';
    downloadList.innerHTML = '';
    suggestionsList.style.display = 'none';
    suggestionsList.innerHTML = '';
    errorCodeEl.textContent = '';
    warningIcon.style.display = 'none';
    infoIcon.style.display = 'none';
    updateStatus('Łączenie z KSeF...', 'Autoryzacja i inicjacja importu');
    
    try {
        // Krok 1: Start importu
        const formData = new FormData(form);
        formData.append('action', 'start_import'); // ZMIANA: start_import zamiast start_export
        
        const response = await fetch('api.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
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
        progressFill.style.width = '30%';
        updateStatus('Import rozpoczęty', `Reference: ${data.referenceNumber}`);
        statusDetails.textContent = `Reference Number: ${data.referenceNumber}`;
        
        // Krok 2: Sprawdzaj status co 3 sekundy
        checkInterval = setInterval(checkExportStatus, checkIntervalMs);
        checkExportStatus(); // Pierwsze sprawdzenie od razu
        
    } catch (error) {
        // Sprawdź czy to odpowiedź z API z klasyfikacją
        if (error.errorType) {
            showError(error);
        } else {
            showError(error.message);
        }
    }
});

// ============================================================================
// SPRAWDZANIE STATUSU EKSPORTU
// ============================================================================

async function checkExportStatus() {
    attemptCount++;
    
    if (attemptCount > maxAttempts) {
        clearInterval(checkInterval);
        showError('Przekroczono limit czasu oczekiwania (3 minuty). Spróbuj ponownie później.');
        return;
    }
    
    // Update progress
    const progress = 30 + (attemptCount / maxAttempts) * 60;
    progressFill.style.width = `${Math.min(progress, 90)}%`;
    attemptCounter.textContent = `Sprawdzanie statusu... (próba ${attemptCount}/${maxAttempts})`;
    
    try {
        const response = await fetch(`api.php?action=check_status&session=${encodeURIComponent(sessionId)}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Błąd sprawdzania statusu');
        }
        
        updateStatus(
            data.ready ? 'Import gotowy!' : 'Oczekiwanie na import...',
            data.message
        );
        statusDetails.textContent = `Status: ${data.statusCode} - ${data.statusDesc}`;
        
        if (data.ready) {
            clearInterval(checkInterval);
            showSuccess(data.filesCount);
        }
        
    } catch (error) {
        // Nie przerywaj przy pojedynczym błędzie - spróbuj ponownie
        console.error('Check status error:', error);
        updateStatus('Ponawiam sprawdzanie...', error.message);
    }
}

// ============================================================================
// AKTUALIZACJA STATUSU
// ============================================================================

function updateStatus(title, message) {
    statusTitle.textContent = title;
    statusMessage.textContent = message;
}

// ============================================================================
// SUKCES - GENERUJE LINKI DO POBRANIA (działający mechanizm)
// ============================================================================

function showSuccess(filesCount) {
    statusPanel.className = 'status-panel show success';
    spinner.style.display = 'none';
    checkmark.style.display = 'flex';
    progressFill.style.width = '100%';
    progressBar.style.display = 'none';
    attemptCounter.textContent = '';
    
    statusTitle.textContent = 'Import zakończony!';
    statusMessage.textContent = `Znaleziono ${filesCount} plik(ów) do pobrania.`;
    
    // Generuj przyciski pobierania - DZIAŁAJĄCY MECHANIZM z linkami <a>
    downloadList.style.display = 'block';
    downloadList.innerHTML = '';
    
    for (let i = 0; i < filesCount; i++) {
        const item = document.createElement('div');
        item.className = 'download-item';
        item.innerHTML = `
            <span>📦 Plik ${i + 1} z ${filesCount}</span>
            <a href="api.php?action=download&session=${encodeURIComponent(sessionId)}&part=${i}" 
               class="download-btn" 
               download>
                Pobierz ZIP
            </a>
        `;
        downloadList.appendChild(item);
    }
    
    // Odblokuj formularz
    submitBtn.disabled = false;
    submitBtn.textContent = 'Importuj ponownie';
}

// ============================================================================
// OBSŁUGA BŁĘDÓW
// ============================================================================

function showError(data) {
    if (checkInterval) clearInterval(checkInterval);
    
    // Ukryj wszystkie ikony
    spinner.style.display = 'none';
    checkmark.style.display = 'none';
    errorIcon.style.display = 'none';
    warningIcon.style.display = 'none';
    infoIcon.style.display = 'none';
    progressBar.style.display = 'none';
    attemptCounter.textContent = '';
    
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
            statusPanel.className = 'status-panel show warning';
            warningIcon.style.display = 'flex';
            statusTitle.textContent = (data.title || 'Błąd danych');
            break;
        case 'info':
            statusPanel.className = 'status-panel show info';
            infoIcon.style.display = 'flex';
            statusTitle.textContent = (data.title || 'Informacja');
            break;
        case 'server_error':
            statusPanel.className = 'status-panel show error';
            errorIcon.style.display = 'flex';
            statusTitle.textContent = (data.title || 'Błąd serwera');
            break;
        case 'app_error':
            statusPanel.className = 'status-panel show error';
            errorIcon.style.display = 'flex';
            statusTitle.textContent = (data.title || 'Błąd aplikacji');
            break;
        default:
            statusPanel.className = 'status-panel show error';
            errorIcon.style.display = 'flex';
            statusTitle.textContent = (data.title || 'Wystąpił błąd');
    }
    
    statusMessage.textContent = data.message || 'Nieznany błąd';
    statusDetails.textContent = '';
    
    // Pokaż sugestie
    if (data.suggestions && data.suggestions.length > 0) {
        suggestionsList.style.display = 'block';
        suggestionsList.innerHTML = '<strong>Co sprawdzić:</strong><ul>' + 
            data.suggestions.map(s => `<li>${s}</li>`).join('') + 
            '</ul>';
    } else {
        suggestionsList.style.display = 'none';
    }
    
    // Pokaż kod błędu
    if (data.errorCode) {
        errorCodeEl.textContent = `Kod błędu: ${data.errorCode}`;
    }
    
    // Odblokuj formularz
    submitBtn.disabled = false;
    submitBtn.textContent = 'Spróbuj ponownie';
}

// ============================================================================
// INICJALIZACJA
// ============================================================================

// Ustaw domyślne daty (ostatni miesiąc)
const today = new Date();
const monthAgo = new Date();
monthAgo.setMonth(monthAgo.getMonth() - 1);

document.getElementById('date_to').value = today.toISOString().split('T')[0];
document.getElementById('date_from').value = monthAgo.toISOString().split('T')[0];

// Inicjalizuj przełącznik metody uwierzytelniania
initializeAuthMethodSwitcher();

// ============================================================================
// AUTOMATYCZNE WYKRYWANIE NIP Z TOKENA
// ============================================================================

document.getElementById('ksef_token').addEventListener('input', function(e) {
    const token = e.target.value;
    const nipMatch = token.match(/nip-(\d{10})/);
    
    if (nipMatch) {
        document.getElementById('nip').value = nipMatch[1];
    }
});
