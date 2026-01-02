<?php
/**
 * CoderAI Coder
 * Phase 2: Generate code changes based on plan
 *
 * FIXED: Now uses RulesService for centralized prompts
 * FIXED: Uses model preferences from rules
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../ai/AIClient.php';
require_once __DIR__ . '/../RulesService.php';

class Coder
{
    /**
     * Generate code changes based on plan
     */
    public static function generateCode($plan, $repo, $fileContents = [])
    {
        $model = RulesService::getModelForPhase('coder', 'code');
        $rules = RulesService::loadRules('coder');

        // Get base system prompt from rules (centralized)
        $basePrompt = RulesService::buildSystemPrompt('coder');

        // Get model preferences from rules
        $temperature = $rules['model_preferences']['temperature'] ?? 0.2;
        $maxTokens = $rules['model_preferences']['max_tokens'] ?? 8192;

        // Phase-specific instructions (kept minimal and directive)
        $phasePrompt = "
CURRENT PHASE: CODING

You are generating code changes based on an approved plan. Output ONLY unified diff format.

DIFF FORMAT RULES:
--- a/path/to/file
+++ b/path/to/file
@@ -start,count +start,count @@
 context line
-removed line
+added line

For new files:
--- /dev/null
+++ b/path/to/new/file
@@ -0,0 +1,N @@
+new content

For deleted files:
--- a/path/to/file
+++ /dev/null

STRICT RULES:
- Output diff ONLY, no explanations before or after
- Follow existing code style exactly
- Include 3 lines of context around changes
- Never add commentary outside the diff
- One diff block per file";

        $systemPrompt = $basePrompt . "\n\n" . $phasePrompt;

        $planJson = json_encode($plan, JSON_PRETTY_PRINT);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "PLAN:\n{$planJson}"]
        ];

        // Add current file contents
        if (!empty($fileContents)) {
            $filesContext = "CURRENT FILE CONTENTS:\n";
            foreach ($fileContents as $path => $content) {
                $filesContext .= "\n=== {$path} ===\n{$content}\n";
            }
            $messages[] = ['role' => 'user', 'content' => $filesContext];
        }

        $messages[] = ['role' => 'user', 'content' => "Generate the unified diff now."];

        $response = AIClient::chat($messages, [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens
        ]);

        $diff = self::extractDiff($response['content']);

        return [
            'diff' => $diff,
            'raw_response' => $response['content'],
            'model' => $response['model'],
            'usage' => $response['usage']
        ];
    }

    /**
     * Extract diff content from response
     */
    private static function extractDiff($content)
    {
        // Remove markdown code blocks if present
        if (preg_match('/```(?:diff)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            return trim($matches[1]);
        }

        // Look for diff pattern
        if (preg_match('/^---\s+/m', $content)) {
            return trim($content);
        }

        return $content;
    }

    /**
     * Parse diff into file changes
     */
    public static function parseDiff($diff)
    {
        $changes = [];
        $currentFile = null;
        $currentContent = '';

        $lines = explode("\n", $diff);

        foreach ($lines as $line) {
            // New file marker
            if (preg_match('/^---\s+(?:a\/)?(.+)$/', $line, $matches)) {
                if ($currentFile !== null) {
                    $changes[] = [
                        'file' => $currentFile,
                        'diff' => trim($currentContent)
                    ];
                }
                $currentFile = $matches[1] === '/dev/null' ? null : $matches[1];
                $currentContent = $line . "\n";
            }
            // Target file marker
            elseif (preg_match('/^\+\+\+\s+(?:b\/)?(.+)$/', $line, $matches)) {
                if ($currentFile === null && $matches[1] !== '/dev/null') {
                    $currentFile = $matches[1];
                }
                $currentContent .= $line . "\n";
            }
            // Regular diff content
            elseif ($currentFile !== null || strpos($line, '@@') === 0) {
                $currentContent .= $line . "\n";
            }
        }

        // Add last file
        if ($currentFile !== null) {
            $changes[] = [
                'file' => $currentFile,
                'diff' => trim($currentContent)
            ];
        }

        return $changes;
    }

    /**
     * Apply diff to file content
     */
    public static function applyDiffToContent($originalContent, $diff)
    {
        // This is a simplified diff application
        // For production, use a proper diff library

        $lines = explode("\n", $originalContent);
        $diffLines = explode("\n", $diff);

        $result = [];
        $lineIndex = 0;

        foreach ($diffLines as $diffLine) {
            if (strpos($diffLine, '@@') === 0) {
                // Parse hunk header @@ -start,count +start,count @@
                if (preg_match('/@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $diffLine, $matches)) {
                    $oldStart = (int)$matches[1] - 1;

                    // Add unchanged lines before this hunk
                    while ($lineIndex < $oldStart && $lineIndex < count($lines)) {
                        $result[] = $lines[$lineIndex];
                        $lineIndex++;
                    }
                }
            } elseif (strpos($diffLine, '-') === 0 && strpos($diffLine, '---') !== 0) {
                // Removed line - skip it in original
                $lineIndex++;
            } elseif (strpos($diffLine, '+') === 0 && strpos($diffLine, '+++') !== 0) {
                // Added line
                $result[] = substr($diffLine, 1);
            } elseif (strpos($diffLine, ' ') === 0) {
                // Context line
                $result[] = $lines[$lineIndex] ?? substr($diffLine, 1);
                $lineIndex++;
            }
        }

        // Add remaining lines
        while ($lineIndex < count($lines)) {
            $result[] = $lines[$lineIndex];
            $lineIndex++;
        }

        return implode("\n", $result);
    }
}
