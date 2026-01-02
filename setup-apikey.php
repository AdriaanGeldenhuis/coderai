<?php
/**
 * One-time setup script for API keys
 * DIRECT DATABASE INSERT - bypasses encryption issues
 */

define('CODERAI', true);
require_once __DIR__ . '/app/bootstrap.php';

$app = new Bootstrap();
$app->init();

$db = Bootstrap::getDB();
$results = [];

// Check if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $openaiKey = trim($_POST['openai_key'] ?? '');
    $anthropicKey = trim($_POST['anthropic_key'] ?? '');

    if (!empty($openaiKey)) {
        try {
            // Direct insert/update - store as JSON string, NOT encrypted
            $stmt = $db->prepare("UPDATE settings SET value_json = ?, is_encrypted = 0 WHERE setting_key = 'openai_api_key'");
            $stmt->execute([json_encode($openaiKey)]);

            if ($stmt->rowCount() === 0) {
                // Insert if not exists
                $stmt = $db->prepare("INSERT INTO settings (setting_key, value_json, is_encrypted, description) VALUES ('openai_api_key', ?, 0, 'OpenAI API Key')");
                $stmt->execute([json_encode($openaiKey)]);
            }
            $results[] = "✅ OpenAI API key saved!";
        } catch (Exception $e) {
            $results[] = "❌ OpenAI error: " . $e->getMessage();
        }
    }

    if (!empty($anthropicKey)) {
        try {
            $stmt = $db->prepare("UPDATE settings SET value_json = ?, is_encrypted = 0 WHERE setting_key = 'anthropic_api_key'");
            $stmt->execute([json_encode($anthropicKey)]);

            if ($stmt->rowCount() === 0) {
                $stmt = $db->prepare("INSERT INTO settings (setting_key, value_json, is_encrypted, description) VALUES ('anthropic_api_key', ?, 0, 'Anthropic API Key')");
                $stmt->execute([json_encode($anthropicKey)]);
            }
            $results[] = "✅ Anthropic API key saved!";
        } catch (Exception $e) {
            $results[] = "❌ Anthropic error: " . $e->getMessage();
        }
    }
}

// Check current state
$stmt = $db->query("SELECT setting_key, value_json, is_encrypted FROM settings WHERE setting_key IN ('openai_api_key', 'anthropic_api_key')");
$current = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>CoderAI - API Key Setup</title>
    <style>
        body { font-family: system-ui; max-width: 600px; margin: 50px auto; padding: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #7c3aed; }
        form { background: #16213e; padding: 20px; border-radius: 10px; }
        label { display: block; margin: 15px 0 5px; font-weight: bold; }
        input[type="text"] { width: 100%; padding: 10px; border: 1px solid #444; border-radius: 5px; background: #0f0f23; color: #fff; font-family: monospace; box-sizing: border-box; }
        button { margin-top: 20px; padding: 12px 24px; background: #7c3aed; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        button:hover { background: #6d28d9; }
        .result { margin: 20px 0; padding: 15px; background: #0f0f23; border-radius: 5px; }
        .result p { margin: 5px 0; }
        .current { background: #1e3a5f; padding: 15px; border-radius: 5px; margin-top: 20px; }
        .current h3 { margin-top: 0; color: #60a5fa; }
        .warning { color: #f59e0b; margin-top: 20px; padding: 15px; background: #422006; border-radius: 5px; }
        code { background: #333; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🔐 CoderAI API Key Setup</h1>

    <?php if (!empty($results)): ?>
    <div class="result">
        <?php foreach ($results as $r): ?>
        <p><?= $r ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="current">
        <h3>Current Status:</h3>
        <?php foreach ($current as $row): ?>
        <p>
            <strong><?= htmlspecialchars($row['setting_key']) ?>:</strong>
            <?php if (empty($row['value_json']) || $row['value_json'] === 'null'): ?>
                <span style="color: #ef4444;">NOT SET</span>
            <?php else: ?>
                <span style="color: #22c55e;">SET</span>
                (encrypted: <?= $row['is_encrypted'] ? 'yes' : 'no' ?>)
            <?php endif; ?>
        </p>
        <?php endforeach; ?>
    </div>

    <form method="POST">
        <label for="openai_key">OpenAI API Key:</label>
        <input type="text" name="openai_key" id="openai_key" placeholder="sk-...">

        <label for="anthropic_key">Anthropic API Key (optional):</label>
        <input type="text" name="anthropic_key" id="anthropic_key" placeholder="sk-ant-...">

        <button type="submit">💾 Save API Keys</button>
    </form>

    <div class="warning">
        ⚠️ <strong>DELETE this file after use!</strong><br>
        <code>rm setup-apikey.php</code>
    </div>
</body>
</html>
