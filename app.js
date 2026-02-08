// ============================================================================
// KONFIGURACJA
// ============================================================================

let sessionId = null;
let checkInterval = null;
let attemptCount = 0;
const maxAttempts = 200;
const checkIntervalMs = 5000;

// ============================================================================
// ELEMENTY DOM
// ============================================================================

const $ = id => document.getElementById(id);
const form = $('exportForm');
const submitBtn = $('submitBtn');
const statusPanel = $('statusPanel');
const statusTitle = $('statusTitle');
const statusMessage = $('statusMessage');
const statusDetails = $('statusDetails');
const progressFill = $('progressFill');
const attemptCounter = $('attemptCounter');
const downloadList = $('downloadList');
const suggestionsList = $('suggestionsList');
const errorCodeEl = $('errorCode');

const icons = {
    spinner: $('spinner'),
    checkmark: $('checkmark'),
    error: $('errorIcon'),
    warning: $('warningIcon'),
    info: $('infoIcon')
};

// ============================================================================
// PRZEŁĄCZNIK METODY UWIERZYTELNIANIA
// ============================================================================

document.querySelectorAll('.method-btn').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault();
        const method = btn.dataset.method;
        
        document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        $('auth_method').value = method;
        $('token-form')?.classList.toggle('active', method === 'token');
        $('certificate-form')?.classList.toggle('active', method === 'certificate');
    });
});

// ============================================================================
// UPLOAD PLIKÓW
// ============================================================================

function setupFileUpload(inputId, boxId, selectedId, nameId, removeId) {
    const input = $(inputId), box = $(boxId), selected = $(selectedId), name = $(nameId), remove = $(removeId);
    if (!input || !box) return;

    const showFile = () => {
        if (input.files?.[0]) {
            name.textContent = input.files[0].name;
            box.style.display = 'none';
            selected.style.display = 'flex';
        }
    };

    const clearFile = () => {
        input.value = '';
        box.style.display = 'flex';
        selected.style.display = 'none';
    };

    input.addEventListener('change', showFile);
    box.addEventListener('click', () => input.click());
    remove?.addEventListener('click', e => { e.preventDefault(); clearFile(); });

    ['dragover', 'dragleave', 'drop'].forEach(event => {
        box.addEventListener(event, e => {
            e.preventDefault();
            box.classList.toggle('dragover', event === 'dragover');
            if (event === 'drop' && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                showFile();
            }
        });
    });
}

setupFileUpload('cert_file', 'cert_upload_box', 'cert_selected', 'cert_file_name', 'cert_remove');
setupFileUpload('key_file', 'key_upload_box', 'key_selected', 'key_file_name', 'key_remove');

// ============================================================================
// WALIDACJA
// ============================================================================

function validateForm() {
    const method = $('auth_method').value;
    const nip = $('nip').value;

    if (!nip || nip.length !== 10) return 'NIP musi składać się z 10 cyfr';
    if (!$('date_from').value || !$('date_to').value) return 'Daty są wymagane';
    
    if (method === 'token' && !$('ksef_token').value.trim()) {
        return 'Token KSeF jest wymagany';
    }
    if (method === 'certificate') {
        if (!$('cert_file').files[0]) return 'Wybierz plik certyfikatu (.crt)';
        if (!$('key_file').files[0]) return 'Wybierz plik klucza (.key)';
    }
    return null;
}

// ============================================================================
// UI HELPERS
// ============================================================================

function hideAllIcons() {
    Object.values(icons).forEach(icon => icon && (icon.style.display = 'none'));
}

function showIcon(name) {
    hideAllIcons();
    icons[name] && (icons[name].style.display = 'flex');
}

function setStatus(title, message, details = '') {
    statusTitle.textContent = title;
    statusMessage.textContent = message;
    statusDetails.textContent = details;
}

function resetUI() {
    submitBtn.disabled = true;
    submitBtn.textContent = 'Przetwarzanie...';
    statusPanel.className = 'status-panel show';
    showIcon('spinner');
    $('progressBar').style.display = 'block';
    progressFill.style.width = '10%';
    downloadList.style.display = 'none';
    downloadList.innerHTML = '';
    suggestionsList.style.display = 'none';
    errorCodeEl.textContent = '';
}

// ============================================================================
// OBSŁUGA BŁĘDÓW
// ============================================================================

function showError(data) {
    if (checkInterval) clearInterval(checkInterval);
    
    if (typeof data === 'string') {
        data = { errorType: 'unknown', message: data };
    }

    const types = {
        user_error: ['warning', 'warning', 'Błąd danych'],
        info: ['info', 'info', 'Informacja'],
        server_error: ['error', 'error', 'Błąd serwera'],
        app_error: ['error', 'error', 'Błąd aplikacji']
    };

    const [panelClass, iconName, defaultTitle] = types[data.errorType] || ['error', 'error', 'Wystąpił błąd'];
    
    statusPanel.className = `status-panel show ${panelClass}`;
    showIcon(iconName);
    $('progressBar').style.display = 'none';
    attemptCounter.textContent = '';
    
    setStatus(data.title || defaultTitle, data.message || 'Nieznany błąd');

    if (data.suggestions?.length) {
        suggestionsList.innerHTML = '<strong>Co sprawdzić:</strong><ul>' + 
            data.suggestions.map(s => `<li>${s}</li>`).join('') + '</ul>';
        suggestionsList.style.display = 'block';
    }

    if (data.errorCode) errorCodeEl.textContent = `Kod: ${data.errorCode}`;

    submitBtn.disabled = false;
    submitBtn.textContent = 'Spróbuj ponownie';
}

// ============================================================================
// SUKCES
// ============================================================================

function showSuccess(filesCount) {
    statusPanel.className = 'status-panel show success';
    showIcon('checkmark');
    $('progressBar').style.display = 'none';
    attemptCounter.textContent = '';
    
    setStatus('Import zakończony!', `Znaleziono ${filesCount} plik(ów) do pobrania.`);

    downloadList.style.display = 'block';
    downloadList.innerHTML = Array.from({length: filesCount}, (_, i) => `
        <div class="download-item">
            <span>📦 Plik ${i + 1}/${filesCount}</span>
            <a href="api.php?action=download&session=${encodeURIComponent(sessionId)}&part=${i}" 
               class="download-btn" download>Pobierz ZIP</a>
        </div>
    `).join('');

    submitBtn.disabled = false;
    submitBtn.textContent = 'Importuj ponownie';
}

// ============================================================================
// SPRAWDZANIE STATUSU
// ============================================================================

async function checkExportStatus() {
    attemptCount++;
    
    if (attemptCount > maxAttempts) {
        clearInterval(checkInterval);
        showError('Przekroczono limit czasu oczekiwania. Spróbuj ponownie później.');
        return;
    }

    progressFill.style.width = `${Math.min(30 + (attemptCount / maxAttempts) * 60, 90)}%`;
    attemptCounter.textContent = `Sprawdzanie... (${attemptCount}/${maxAttempts})`;

    try {
        const res = await fetch(`api.php?action=check_status&session=${encodeURIComponent(sessionId)}`);
        const data = await res.json();

        if (!data.success) throw new Error(data.message || 'Błąd sprawdzania statusu');

        setStatus(
            data.ready ? 'Import gotowy!' : 'Oczekiwanie na import...',
            data.message,
            `Status: ${data.statusCode} - ${data.statusDesc}`
        );

        if (data.ready) {
            clearInterval(checkInterval);
            showSuccess(data.filesCount);
        }
    } catch (error) {
        setStatus('Ponawiam sprawdzanie...', error.message);
    }
}

// ============================================================================
// SUBMIT FORMULARZA
// ============================================================================

form.addEventListener('submit', async e => {
    e.preventDefault();

    const error = validateForm();
    if (error) {
        showError({ errorType: 'user_error', message: error, title: 'Błąd walidacji' });
        return;
    }

    attemptCount = 0;
    sessionId = null;
    if (checkInterval) clearInterval(checkInterval);
    
    resetUI();
    setStatus('Łączenie z KSeF...', 'Autoryzacja i inicjacja importu');

    try {
        const formData = new FormData(form);
        const action = $('auth_method').value === 'certificate' ? 'start_import_certificate' : 'start_import';
        formData.append('action', action);

        const res = await fetch('api.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (!data.success) {
            showError({
                errorType: data.errorType || 'unknown',
                message: data.message || data.error,
                title: data.title,
                suggestions: data.suggestions
            });
            return;
        }

        sessionId = data.sessionId;
        progressFill.style.width = '30%';
        setStatus('Import rozpoczęty', `Reference: ${data.referenceNumber}`, `Reference: ${data.referenceNumber}`);

        checkInterval = setInterval(checkExportStatus, checkIntervalMs);
        checkExportStatus();

    } catch (error) {
        showError(error.message);
    }
});

// ============================================================================
// INICJALIZACJA
// ============================================================================

// Domyślne daty (ostatni miesiąc)
const today = new Date();
const monthAgo = new Date();
monthAgo.setMonth(monthAgo.getMonth() - 1);
$('date_to').value = today.toISOString().split('T')[0];
$('date_from').value = monthAgo.toISOString().split('T')[0];

// Auto-wykrywanie NIP z tokena
$('ksef_token')?.addEventListener('input', e => {
    const match = e.target.value.match(/nip-(\d{10})/);
    if (match) $('nip').value = match[1];
});
