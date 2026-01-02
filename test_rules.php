<?php
/**
 * Quick test script to check if rules load correctly
 * Run: php test_rules.php
 * Or visit: https://coderai.co.za/test_rules.php
 */

define('CODERAI', true);

// Load the RulesService
require_once __DIR__ . '/app/services/RulesService.php';

echo "=== RULES TEST ===\n\n";

// Check rules path
$rulesPath = __DIR__ . '/app/rules';
echo "1. Rules path: {$rulesPath}\n";
echo "   Exists: " . (is_dir($rulesPath) ? 'YES' : 'NO') . "\n\n";

// List rules files
echo "2. Rules files:\n";
foreach (glob($rulesPath . '/*.json') as $file) {
    echo "   - " . basename($file) . " (" . filesize($file) . " bytes)\n";
}
echo "\n";

// Test each workspace
$workspaces = ['normal', 'church', 'coder'];

foreach ($workspaces as $ws) {
    echo "3. Testing workspace: {$ws}\n";

    $file = $rulesPath . '/' . $ws . '.json';
    echo "   File: {$file}\n";
    echo "   Exists: " . (file_exists($file) ? 'YES' : 'NO') . "\n";

    if (file_exists($file)) {
        $content = file_get_contents($file);
        $json = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "   JSON ERROR: " . json_last_error_msg() . "\n";
        } else {
            echo "   JSON: VALID\n";
            echo "   Name: " . ($json['name'] ?? 'N/A') . "\n";
            echo "   System prompt length: " . strlen($json['system_prompt'] ?? '') . " chars\n";
            echo "   First 100 chars: " . substr($json['system_prompt'] ?? '', 0, 100) . "...\n";
        }
    }

    echo "\n";
}

// Test buildSystemPrompt
echo "4. Testing buildSystemPrompt('church'):\n";
try {
    $prompt = RulesService::buildSystemPrompt('church');
    echo "   Length: " . strlen($prompt) . " chars\n";
    echo "   First 200 chars:\n";
    echo "   " . substr($prompt, 0, 200) . "...\n";
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== END TEST ===\n";
