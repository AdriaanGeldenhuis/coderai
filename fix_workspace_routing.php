<?php
/**
 * Fix workspace routing issues
 *
 * This script fixes projects that have the wrong workspace_slug
 * and ensures each project uses its correct rules.
 *
 * Run from command line: php fix_workspace_routing.php
 */

define('CODERAI', true);
require_once __DIR__ . '/app/bootstrap.php';

echo "=== CoderAI Workspace Routing Fix ===\n\n";

$db = Bootstrap::getDB();

// 1. Show current state
echo "1. Current project workspace distribution:\n";
$stmt = $db->query("
    SELECT workspace_slug, COUNT(*) as count
    FROM projects
    GROUP BY workspace_slug
    ORDER BY workspace_slug
");
$distribution = $stmt->fetchAll();

foreach ($distribution as $row) {
    echo "   - {$row['workspace_slug']}: {$row['count']} projects\n";
}
echo "\n";

// 2. Check for any projects with NULL workspace_slug
echo "2. Checking for projects with NULL workspace_slug...\n";
$stmt = $db->query("SELECT id, name FROM projects WHERE workspace_slug IS NULL");
$nullProjects = $stmt->fetchAll();

if (count($nullProjects) > 0) {
    echo "   Found " . count($nullProjects) . " projects with NULL workspace_slug:\n";
    foreach ($nullProjects as $project) {
        echo "   - ID {$project['id']}: {$project['name']}\n";
    }

    // Fix by setting to 'normal'
    $stmt = $db->prepare("UPDATE projects SET workspace_slug = 'normal' WHERE workspace_slug IS NULL");
    $stmt->execute();
    echo "   ✅ Fixed: Set workspace_slug to 'normal' for these projects\n";
} else {
    echo "   ✅ No projects with NULL workspace_slug\n";
}
echo "\n";

// 3. Show valid workspace slugs
echo "3. Valid workspace slugs:\n";
echo "   - normal (Chat)\n";
echo "   - church (Church)\n";
echo "   - coder (Coder)\n\n";

// 4. Check for invalid workspace_slugs
echo "4. Checking for projects with invalid workspace_slug...\n";
$stmt = $db->query("
    SELECT id, name, workspace_slug
    FROM projects
    WHERE workspace_slug NOT IN ('normal', 'church', 'coder')
");
$invalidProjects = $stmt->fetchAll();

if (count($invalidProjects) > 0) {
    echo "   Found " . count($invalidProjects) . " projects with invalid workspace_slug:\n";
    foreach ($invalidProjects as $project) {
        echo "   - ID {$project['id']}: {$project['name']} (was: {$project['workspace_slug']})\n";
    }

    // Fix by setting to 'normal'
    $stmt = $db->prepare("
        UPDATE projects
        SET workspace_slug = 'normal'
        WHERE workspace_slug NOT IN ('normal', 'church', 'coder')
    ");
    $stmt->execute();
    echo "   ✅ Fixed: Set workspace_slug to 'normal' for these projects\n";
} else {
    echo "   ✅ All projects have valid workspace_slug\n";
}
echo "\n";

// 5. Show final state
echo "5. Final project workspace distribution:\n";
$stmt = $db->query("
    SELECT workspace_slug, COUNT(*) as count
    FROM projects
    GROUP BY workspace_slug
    ORDER BY workspace_slug
");
$distribution = $stmt->fetchAll();

foreach ($distribution as $row) {
    echo "   - {$row['workspace_slug']}: {$row['count']} projects\n";
}
echo "\n";

// 6. Show model configuration summary
echo "6. Model Configuration Summary:\n";
echo "   ┌────────────────────┬───────────────────────────┬─────────────────┐\n";
echo "   │ Workspace          │ Model                     │ User Selection  │\n";
echo "   ├────────────────────┼───────────────────────────┼─────────────────┤\n";
echo "   │ CHAT (normal)      │ qwen2.5:14b-instruct      │ No (fixed)      │\n";
echo "   │ CHURCH (church)    │ qwen2.5:14b-instruct      │ No (fixed)      │\n";
echo "   │ CODER (coder)      │ qwen2.5-coder:7b (def)    │ Yes (dropdown)  │\n";
echo "   │                    │ qwen2.5-coder:14b         │                 │\n";
echo "   │                    │ qwen2.5:14b-instruct      │                 │\n";
echo "   └────────────────────┴───────────────────────────┴─────────────────┘\n";
echo "\n";

echo "=== Fix Complete ===\n";
