<?php
/**
 * Debug script to test exactly what gets sent to the AI
 * Run: php test_ai_call.php
 */

define('CODERAI', true);

// Load everything
require_once __DIR__ . '/app/config/env.php';
Env::load(__DIR__ . '/app/.env');
require_once __DIR__ . '/app/services/RulesService.php';

echo "=== AI CALL DEBUG TEST ===\n\n";

// 1. Build system prompt for church
echo "1. Building system prompt for 'church' workspace...\n";
$systemPrompt = RulesService::buildSystemPrompt('church');
echo "   System prompt length: " . strlen($systemPrompt) . " chars\n";
echo "   First 300 chars:\n";
echo "   " . substr($systemPrompt, 0, 300) . "...\n\n";

// 2. Build messages array exactly like MessagesController does
echo "2. Building messages array...\n";
$messages = [];
$messages[] = ['role' => 'system', 'content' => $systemPrompt];
$messages[] = ['role' => 'user', 'content' => 'wat is water?'];

echo "   Messages count: " . count($messages) . "\n";
foreach ($messages as $i => $msg) {
    echo "   Message $i: role={$msg['role']}, length=" . strlen($msg['content']) . "\n";
}
echo "\n";

// 3. Build the exact payload that goes to the gateway
echo "3. Building gateway payload...\n";
$gatewayUrl = getenv('AI_GATEWAY_URL');
$gatewayKey = getenv('AI_GATEWAY_KEY');
$model = getenv('AI_MODEL_FAST') ?: 'qwen2.5-coder:7b';

echo "   Gateway URL: $gatewayUrl\n";
echo "   Gateway Key: " . substr($gatewayKey, 0, 20) . "...\n";
echo "   Model: $model\n\n";

$payload = [
    'model' => $model,
    'temperature' => 0.2,
    'messages' => $messages
];

echo "4. Payload JSON (first 500 chars):\n";
$payloadJson = json_encode($payload, JSON_PRETTY_PRINT);
echo substr($payloadJson, 0, 500) . "...\n\n";

// 5. Make the actual call
echo "5. Making API call to gateway...\n";
$ch = curl_init($gatewayUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-ai-key: ' . $gatewayKey
    ],
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "   HTTP Code: $httpCode\n";
if ($error) {
    echo "   cURL Error: $error\n";
}
echo "\n";

echo "6. Response:\n";
if ($response) {
    $decoded = json_decode($response, true);
    if ($decoded) {
        // Extract the actual response content
        $content = $decoded['message']['content']
            ?? $decoded['choices'][0]['message']['content']
            ?? $decoded['content']
            ?? 'NO CONTENT FOUND';

        echo "   AI Response:\n";
        echo "   " . substr($content, 0, 500) . "\n";
    } else {
        echo "   Raw response: " . substr($response, 0, 500) . "\n";
    }
} else {
    echo "   No response received\n";
}

echo "\n=== END DEBUG ===\n";
