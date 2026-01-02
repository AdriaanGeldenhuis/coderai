<?php
/**
 * CoderAI Planner
 * Phase 1: Analyze request and create execution plan
 *
 * FIXED: Now uses RulesService for centralized prompts
 * FIXED: Includes repo snapshot for better planning
 * FIXED: Path safety with proper realpath normalization
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../ai/AIClient.php';
require_once __DIR__ . '/../RulesService.php';

class Planner
{
    /**
     * Create execution plan for code change request
     */
    public static function plan($request, $repo, $context = [])
    {
        $model = RulesService::getModelForPhase('coder', 'plan');
        $rules = RulesService::loadRules('coder');

        // Get base system prompt from rules (centralized)
        $basePrompt = RulesService::buildSystemPrompt('coder');

        // Get model preferences from rules
        $temperature = $rules['model_preferences']['temperature'] ?? 0.3;
        $maxTokens = $rules['model_preferences']['max_tokens'] ?? 8192;

        // Build repo snapshot for context
        $repoSnapshot = self::getRepoSnapshot($repo['base_path'], 2);

        // Phase-specific instructions (kept minimal)
        $phasePrompt = "
CURRENT PHASE: PLANNING

You are analyzing a code change request. Your job is to create a precise execution plan.

REPOSITORY INFO:
- Base path: {$repo['base_path']}
- Label: {$repo['label']}

REPOSITORY STRUCTURE:
{$repoSnapshot}

OUTPUT FORMAT (JSON only, no markdown):
{
    \"summary\": \"Brief description of what needs to be done\",
    \"files_to_modify\": [
        {
            \"path\": \"relative/path/to/file.php\",
            \"action\": \"modify|create|delete\",
            \"description\": \"What changes to make\"
        }
    ],
    \"files_to_read\": [\"paths to read for context before coding\"],
    \"risks\": [\"potential risks or concerns\"],
    \"estimated_complexity\": \"low|medium|high\",
    \"requires_backup\": true,
    \"steps\": [
        \"Step 1: ...\",
        \"Step 2: ...\"
    ]
}

RULES:
- Only reference files that exist in the repository structure above
- If you need files that don't exist, mark action as \"create\"
- If you cannot find required files, say so in risks
- Never invent database tables or columns - ask for schema if needed
- Keep changes minimal and surgical";

        $systemPrompt = $basePrompt . "\n\n" . $phasePrompt;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $request]
        ];

        // Add file context if provided
        if (!empty($context['files'])) {
            $fileContext = "EXISTING FILE CONTENTS:\n";
            foreach ($context['files'] as $path => $content) {
                $fileContext .= "\n--- {$path} ---\n{$content}\n";
            }
            $messages[] = ['role' => 'user', 'content' => $fileContext];
        }

        $response = AIClient::chat($messages, [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens
        ]);

        // Parse JSON from response
        $plan = self::parseJsonResponse($response['content']);

        return [
            'plan' => $plan,
            'model' => $response['model'],
            'usage' => $response['usage']
        ];
    }

    /**
     * Get repository file tree snapshot
     *
     * @param string $basePath Repository base path
     * @param int $maxDepth Maximum directory depth to traverse
     * @return string Formatted file tree
     */
    public static function getRepoSnapshot($basePath, $maxDepth = 2)
    {
        $basePath = rtrim(realpath($basePath) ?: $basePath, '/');

        if (!is_dir($basePath)) {
            return "(Unable to read repository)";
        }

        $tree = [];
        $ignoreDirs = ['.git', 'node_modules', 'vendor', '.idea', '__pycache__', 'cache', 'logs'];
        $ignoreFiles = ['.DS_Store', 'Thumbs.db', '.gitkeep'];

        self::buildTree($basePath, $basePath, $tree, 0, $maxDepth, $ignoreDirs, $ignoreFiles);

        return implode("\n", $tree);
    }

    /**
     * Recursively build file tree
     */
    private static function buildTree($basePath, $currentPath, &$tree, $depth, $maxDepth, $ignoreDirs, $ignoreFiles)
    {
        if ($depth > $maxDepth) {
            return;
        }

        $items = @scandir($currentPath);
        if ($items === false) {
            return;
        }

        $indent = str_repeat('  ', $depth);
        $dirs = [];
        $files = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $currentPath . '/' . $item;
            $relativePath = str_replace($basePath . '/', '', $fullPath);

            if (is_dir($fullPath)) {
                if (!in_array($item, $ignoreDirs)) {
                    $dirs[] = $item;
                }
            } else {
                if (!in_array($item, $ignoreFiles)) {
                    $files[] = $item;
                }
            }
        }

        // Sort alphabetically
        sort($dirs);
        sort($files);

        // Add directories first
        foreach ($dirs as $dir) {
            $tree[] = $indent . "📁 " . $dir . "/";
            self::buildTree($basePath, $currentPath . '/' . $dir, $tree, $depth + 1, $maxDepth, $ignoreDirs, $ignoreFiles);
        }

        // Then files
        foreach ($files as $file) {
            $tree[] = $indent . "📄 " . $file;
        }
    }

    /**
     * Parse JSON from AI response
     */
    private static function parseJsonResponse($content)
    {
        // Try to extract JSON from response
        $content = trim($content);

        // Remove markdown code blocks if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $content = $matches[1];
        }

        $plan = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse plan: ' . json_last_error_msg());
        }

        // Validate required fields
        $required = ['summary', 'files_to_modify', 'steps'];
        foreach ($required as $field) {
            if (!isset($plan[$field])) {
                throw new Exception("Plan missing required field: {$field}");
            }
        }

        return $plan;
    }

    /**
     * Read files for context
     *
     * FIXED: Proper path safety with normalized basePath comparison
     */
    public static function readFilesForContext($repo, $paths)
    {
        $files = [];
        $basePath = realpath($repo['base_path']);

        // Security: basePath must be valid
        if (!$basePath || !is_dir($basePath)) {
            error_log("[Planner] Invalid base path: " . $repo['base_path']);
            return $files;
        }

        // Ensure trailing slash for proper prefix matching
        $basePath = rtrim($basePath, '/') . '/';

        foreach ($paths as $path) {
            // Normalize the requested path
            $cleanPath = ltrim($path, '/');
            $fullPath = $basePath . $cleanPath;
            $realPath = realpath($fullPath);

            // Security check: realPath must exist and be within basePath
            if (!$realPath) {
                error_log("[Planner] Path does not exist: {$fullPath}");
                continue;
            }

            // Ensure file is within repository (with trailing slash check)
            if (strpos($realPath . '/', $basePath) !== 0 && strpos($realPath, $basePath) !== 0) {
                error_log("[Planner] Path traversal blocked: {$realPath}");
                continue;
            }

            if (is_file($realPath) && is_readable($realPath)) {
                $size = filesize($realPath);
                // Limit file size to 100KB
                if ($size <= 102400) {
                    $files[$path] = file_get_contents($realPath);
                } else {
                    error_log("[Planner] File too large: {$realPath} ({$size} bytes)");
                }
            }
        }

        return $files;
    }
}
