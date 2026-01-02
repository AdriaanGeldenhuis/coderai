<?php
/**
 * FIX SCRIPT: Update project workspace_slugs based on project names
 * Run: php fix_workspaces.php
 */

define('CODERAI', true);

// Load environment
require_once __DIR__ . '/app/config/env.php';
Env::load(__DIR__ . '/app/.env');

// Database connection
$host = getenv('DB_HOST');
$db = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$pass = getenv('DB_PASSWORD');

try {
    $pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Database connected\n\n";
} catch (Exception $e) {
    die("Database error: " . $e->getMessage() . "\n");
}

// Show all projects
echo "=== CURRENT PROJECTS ===\n";
$stmt = $pdo->query("SELECT id, name, workspace_slug, created_at FROM projects ORDER BY id");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($projects as $p) {
    echo "ID: {$p['id']} | Name: {$p['name']} | Workspace: {$p['workspace_slug']}\n";
}

echo "\n=== FIXING PROJECTS ===\n";

// Fix projects that should be 'church' based on name
$churchKeywords = ['geestelik', 'church', 'kerk', 'spiritual', 'bybel', 'bible', 'scripture'];
$coderKeywords = ['coder', 'code', 'dev', 'programming'];

$fixCount = 0;

foreach ($projects as $p) {
    $name = strtolower($p['name']);
    $currentSlug = $p['workspace_slug'];
    $newSlug = null;

    // Check for church keywords
    foreach ($churchKeywords as $keyword) {
        if (strpos($name, $keyword) !== false && $currentSlug !== 'church') {
            $newSlug = 'church';
            break;
        }
    }

    // Check for coder keywords
    if (!$newSlug) {
        foreach ($coderKeywords as $keyword) {
            if (strpos($name, $keyword) !== false && $currentSlug !== 'coder') {
                $newSlug = 'coder';
                break;
            }
        }
    }

    if ($newSlug) {
        echo "Fixing: '{$p['name']}' from '{$currentSlug}' to '{$newSlug}'... ";
        $stmt = $pdo->prepare("UPDATE projects SET workspace_slug = ? WHERE id = ?");
        $stmt->execute([$newSlug, $p['id']]);
        echo "DONE\n";
        $fixCount++;
    }
}

if ($fixCount === 0) {
    echo "No projects needed fixing.\n";
} else {
    echo "\n✓ Fixed {$fixCount} project(s)\n";
}

// Show updated projects
echo "\n=== UPDATED PROJECTS ===\n";
$stmt = $pdo->query("SELECT id, name, workspace_slug FROM projects ORDER BY id");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($projects as $p) {
    $icon = match($p['workspace_slug']) {
        'church' => '⛪',
        'coder' => '💻',
        default => '💬'
    };
    echo "{$icon} ID: {$p['id']} | {$p['name']} | workspace: {$p['workspace_slug']}\n";
}

echo "\n✓ Done!\n";
