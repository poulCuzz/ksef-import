<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KSeF 2.0 - Eksport Faktur</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #ffffff;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
        }
        
        .box { 
            background: #fff; 
            padding: 30px; 
            border-radius: 12px; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); 
        }
        
        h2 { 
            color: #333; 
            margin-bottom: 25px; 
            font-size: 24px; 
            font-weight: 600;
            text-align: center;
        }
        
        .form-group { 
            margin-bottom: 20px; 
        }
        
        label { 
            display: block; 
            margin-bottom: 6px; 
            color: #555; 
            font-weight: 500; 
            font-size: 14px; 
        }
        
        input[type=text], input[type=date], select { 
            width: 100%; 
            padding: 12px 14px; 
            border: 2px solid #e0e0e0; 
            border-radius: 8px; 
            font-size: 14px; 
            background: #fafafa;
            transition: all 0.3s;
        }
        
        input[type=text]:focus, input[type=date]:focus, select:focus { 
            outline: none; 
            border-color: #667eea;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .date-row { 
            display: flex; 
            gap: 15px; 
        }
        
        .date-row > div { 
            flex: 1; 
        }
        
        button { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
            border: none; 
            padding: 14px 28px; 
            cursor: pointer; 
            border-radius: 8px; 
            font-size: 16px; 
            font-weight: 600; 
            width: 100%; 
            margin-top: 10px; 
            transition: all 0.3s;
        }
        
        button:hover:not(:disabled) { 
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Status Panel */
        .status-panel {
            display: none;
            margin-top: 25px;
            padding: 20px;
            border-radius: 8px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
        }
        
        .status-panel.show {
            display: block;
        }
        
        .status-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        /* Spinner */
        .spinner {
            width: 24px;
            height: 24px;
            border: 3px solid #e9ecef;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .status-title {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }
        
        .status-message {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .status-details {
            font-size: 12px;
            color: #888;
            font-family: monospace;
            background: #fff;
            padding: 8px 12px;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
        }
        
        /* Progress bar */
        .progress-bar {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            margin-top: 15px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 3px;
            transition: width 0.3s;
            animation: progress-pulse 2s ease-in-out infinite;
        }
        
        @keyframes progress-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        /* Success state */
        .status-panel.success {
            background: #d4edda;
            border-color: #c3e6cb;
        }
        
        .status-panel.success .status-title {
            color: #155724;
        }
        
        .status-panel.success .status-message {
            color: #155724;
        }
        
        /* Error state */
        .status-panel.error {
            background: #f8d7da;
            border-color: #f5c6cb;
        }
        
        .status-panel.error .status-title {
            color: #721c24;
        }
        
        .status-panel.error .status-message {
            color: #721c24;
        }
        
                /* Warning state (błąd użytkownika) */
        .status-panel.warning {
            background: #fff3cd;
            border-color: #ffc107;
        }

        .status-panel.warning .status-title {
            color: #856404;
        }

        .status-panel.warning .status-message {
            color: #856404;
        }

        /* Info state */
        .status-panel.info {
            background: #d1ecf1;
            border-color: #bee5eb;
        }

        .status-panel.info .status-title {
            color: #0c5460;
        }

        .status-panel.info .status-message {
            color: #0c5460;
        }

        /* Suggestions list */
        .suggestions-list {
            margin-top: 12px;
            padding: 12px 15px;
            background: rgba(255,255,255,0.7);
            border-radius: 6px;
            font-size: 13px;
        }

        .suggestions-list ul {
            margin: 0;
            padding-left: 20px;
        }

        .suggestions-list li {
            margin-bottom: 5px;
            color: #555;
        }

        .suggestions-list li:last-child {
            margin-bottom: 0;
        }

        .error-code {
            margin-top: 10px;
            font-size: 11px;
            color: #888;
            font-family: monospace;
        }

        /* Warning icon */
        .warning-icon {
            width: 24px;
            height: 24px;
            background: #ffc107;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #856404;
            font-size: 14px;
            font-weight: bold;
        }

        /* Info icon */
        .info-icon {
            width: 24px;
            height: 24px;
            background: #17a2b8;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            font-weight: bold;
        }
        
        /* Download buttons */
        .download-list {
            margin-top: 15px;
        }
        
        .download-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: #fff;
            border-radius: 6px;
            margin-bottom: 8px;
            border: 1px solid #c3e6cb;
        }
        
        .download-item span {
            color: #155724;
            font-weight: 500;
        }
        
        .download-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .download-btn:hover {
            background: #218838;
            transform: translateY(-1px);
        }
        
        /* Checkmark icon */
        .checkmark {
            width: 24px;
            height: 24px;
            background: #28a745;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }
        
        /* Error icon */
        .error-icon {
            width: 24px;
            height: 24px;
            background: #dc3545;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            font-weight: bold;
        }
        
        /* Attempt counter */
        .attempt-counter {
            font-size: 12px;
            color: #888;
            margin-top: 10px;
            text-align: center;
        }
        
        @media (max-width: 600px) {
            .date-row { 
                flex-direction: column; 
            }
            
            body {
                padding: 10px;
            }
            
            .box {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="box">
        <h2>🧾 KSeF 2.0 - Eksport Faktur</h2>

        <form id="exportForm">
            <div class="form-group">
                <label>Środowisko:</label>
                <select name="env" id="env">
                    <option value="demo">DEMO (ksef-demo.mf.gov.pl)</option>
                    <option value="test">TEST (ksef-test.mf.gov.pl)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Token KSeF:</label>
                <input type="text" name="ksef_token" id="ksef_token" placeholder="Wklej swój token KSeF" required>
            </div>

            <div class="form-group">
                <label>NIP:</label>
                <input type="text" name="nip" id="nip" placeholder="NIP (10 cyfr)" required pattern="[0-9]{10}" maxlength="10">
            </div>

            <div class="form-group">
                <label>Typ podmiotu:</label>
                <select name="subject_type" id="subject_type">
                    <option value="Subject1">Subject1 (Sprzedawca)</option>
                    <option value="Subject2">Subject2 (Nabywca)</option>
                </select>
            </div>

            <div class="date-row">
                <div class="form-group">
                    <label>Data od:</label>
                    <input type="date" name="date_from" id="date_from" required>
                </div>
                <div class="form-group">
                    <label>Data do:</label>
                    <input type="date" name="date_to" id="date_to" required>
                </div>
            </div>

            <button type="submit" id="submitBtn">Eksportuj faktury</button>
        </form>

        <!-- Status Panel -->
        <div class="status-panel" id="statusPanel">
            <div class="status-header">
                <div class="spinner" id="spinner"></div>
                <div class="checkmark" id="checkmark" style="display:none;">✓</div>
                <div class="error-icon" id="errorIcon" style="display:none;">✕</div>
                <div class="warning-icon" id="warningIcon" style="display:none;">!</div>
                <div class="info-icon" id="infoIcon" style="display:none;">i</div>
                <span class="status-title" id="statusTitle">Przetwarzanie...</span>
            </div>
            <div class="status-message" id="statusMessage">Łączenie z KSeF...</div>
            <div class="status-details" id="statusDetails"></div>
            <div class="progress-bar" id="progressBar">
                <div class="progress-fill" id="progressFill" style="width: 0%"></div>
            </div>
            <div class="attempt-counter" id="attemptCounter"></div>
            <!-- Suggestions (pokazuje się przy błędach) -->
            <div class="suggestions-list" id="suggestionsList" style="display:none;"></div>
            <div class="error-code" id="errorCode"></div>
            <!-- Download list (pokazuje się gdy gotowe) -->
            <div class="download-list" id="downloadList" style="display:none;"></div>
        </div>
    </div>
</div>

<script>
let sessionId = null;
let checkInterval = null;
let attemptCount = 0;
const maxAttempts = 60; // 60 prób × 3 sekundy = 3 minuty
const checkIntervalMs = 3000; // 3 sekundy

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
    updateStatus('Łączenie z KSeF...', 'Autoryzacja i inicjacja eksportu');
    
    try {
        // Krok 1: Start eksportu
        const formData = new FormData(form);
        formData.append('action', 'start_export');
        
        const response = await fetch('api.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (!data.success) {
            if (data.errorType) {
                showError(data);
                return;
            }
            throw new Error(data.error || data.message || 'Nieznany błąd');
        }
        
        sessionId = data.sessionId;
        progressFill.style.width = '30%';
        updateStatus('Eksport rozpoczęty', `Reference: ${data.referenceNumber}`);
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
            data.ready ? 'Eksport gotowy!' : 'Oczekiwanie na eksport...',
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

function updateStatus(title, message) {
    statusTitle.textContent = title;
    statusMessage.textContent = message;
}

function showSuccess(filesCount) {
    statusPanel.className = 'status-panel show success';
    spinner.style.display = 'none';
    checkmark.style.display = 'flex';
    progressFill.style.width = '100%';
    progressBar.style.display = 'none';
    attemptCounter.textContent = '';
    
    statusTitle.textContent = 'Eksport zakończony!';
    statusMessage.textContent = `Znaleziono ${filesCount} plik(ów) do pobrania.`;
    
    // Generuj przyciski pobierania
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
    submitBtn.textContent = 'Eksportuj ponownie';
}

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
// Ustaw domyślne daty (ostatni miesiąc)
const today = new Date();
const monthAgo = new Date();
monthAgo.setMonth(monthAgo.getMonth() - 1);

document.getElementById('date_to').value = today.toISOString().split('T')[0];
document.getElementById('date_from').value = monthAgo.toISOString().split('T')[0];
// Automatyczne wykrywanie NIP z tokena
document.getElementById('ksef_token').addEventListener('input', function(e) {
    const token = e.target.value;
    const nipMatch = token.match(/nip-(\d{10})/);
    
    if (nipMatch) {
        document.getElementById('nip').value = nipMatch[1];
    }
});
</script>

</body>
</html>
