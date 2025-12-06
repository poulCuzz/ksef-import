<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>KSeF API v2 - Eksport Faktur</title>
    <style>
        body { font-family: sans-serif; background: #eef; padding: 20px; }
        .box { background: #fff; padding: 20px; max-width: 900px; margin: 0 auto; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .msg { padding: 10px; margin: 10px 0; border-radius: 4px; color: #fff; font-weight: bold; }
        .msg.ok { background: #28a745; } .msg.err { background: #dc3545; } .msg.warn { background: #ffc107; color: #333; }
        input[type=text], input[type=date], select { width: 100%; padding: 10px; margin: 5px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background: #007bff; color: white; border: none; padding: 12px 20px; cursor: pointer; border-radius: 4px; font-size: 16px; width: 100%; margin-top: 10px; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>

<div class="box">
    <h2>KSeF 2.0 - Eksport Faktur</h2>

    <form method="post">
        <label>Środowisko:</label>
        <select name="env">
            <option value="demo">DEMO (ksef-demo.mf.gov.pl)</option>
            <option value="test">TEST (ksef-test.mf.gov.pl)</option>
        </select>

        <label>Token KSeF:</label>
        <input type="text" name="ksef_token" placeholder="Wklej swój token KSeF" required>

        <label>NIP:</label>
        <input type="text" name="nip" placeholder="NIP (10 cyfr)" required>

        <label>Typ podmiotu:</label>
        <select name="subject_type">
            <option value="Subject1">Subject1 (Sprzedawca)</option>
            <option value="Subject2">Subject2 (Nabywca)</option>
        </select>

        <div style="display: flex; gap: 10px;">
            <div style="flex:1"><label>Data od:</label><input type="date" name="date_from" required></div>
            <div style="flex:1"><label>Data do:</label><input type="date" name="date_to" required></div>
        </div>

        <button type="submit">Eksportuj faktury</button>
    </form>
</div>

</body>
</html>