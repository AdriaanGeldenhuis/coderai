<?php
/**
 * CoderAI Reviewer
 * Phase 3: Review code changes for safety and correctness
 *
 * FIXED: Now uses RulesService for centralized prompts
 * FIXED: Uses model preferences from rules
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../ai/AIClient.php';
require_once __DIR__ . '/../RulesService.php';

class Reviewer
{
    /**
     * Review code changes for safety and correctness
     */
    public static function review($diff, $plan, $repo)
    {
        $model = RulesService::getModelForPhase('coder', 'review');
        $rules = RulesService::loadRules('coder');

        // Get base system prompt from rules (centralized)
        $basePrompt = RulesService::buildSystemPrompt('coder');

        // Get blocked paths from rules
        $blockedPaths = $rules['restrictions']['blocked_paths'] ?? [];
        $blockedPathsStr = implode(', ', $blockedPaths);

        // Get model preferences from rules
        $temperature = 0.1; // Low for consistent review
        $maxTokens = $rules['model_preferences']['max_tokens'] ?? 8192;

        // Phase-specific instructions (kept minimal and directive)
        $phasePrompt = "
CURRENT PHASE: REVIEW

You are reviewing code changes for safety and correctness. Output JSON ONLY.

CHECK FOR:
1. Security: SQL injection, XSS, command injection, path traversal, hardcoded secrets
2. Logic: errors, missing error handling, resource leaks
3. Blocked paths: {$blockedPathsStr}
4. Code quality: follows existing patterns, proper validation

OUTPUT FORMAT (JSON only, no markdown):
{
    \"approved\": true|false,
    \"risk_level\": \"low|medium|high|critical\",
    \"issues\": [
        {
            \"severity\": \"info|warning|error|critical\",
            \"file\": \"path/to/file\",
            \"line\": 123,
            \"message\": \"Issue description\",
            \"suggestion\": \"How to fix\"
        }
    ],
    \"summary\": \"One sentence assessment\",
    \"safe_to_apply\": true|false
}

RULES:
- approved=false if ANY critical or security issues
- safe_to_apply=false if blocked paths touched or critical issues
- Be concise, no verbose explanations";

        $systemPrompt = $basePrompt . "\n\n" . $phasePrompt;

        $planJson = json_encode($plan, JSON_PRETTY_PRINT);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "PLAN:\n{$planJson}"],
            ['role' => 'user', 'content' => "DIFF:\n{$diff}"],
            ['role' => 'user', 'content' => "Review now. Output JSON only."]
        ];

        $response = AIClient::chat($messages, [
            'model' => $model,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens
        ]);

        $review = self::parseJsonResponse($response['content']);

        return [
            'review' => $review,
            'model' => $response['model'],
            'usage' => $response['usage']
        ];
    }

    /**
     * Parse JSON from AI response
     */
    private static function parseJsonResponse($content)
    {
        $content = trim($content);

        // Remove markdown code blocks if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $content = $matches[1];
        }

        $review = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Return a safe default if parsing fails
            return [
                'approved' => false,
                'risk_level' => 'high',
                'issues' => [[
                    'severity' => 'error',
                    'message' => 'Failed to parse review response: ' . json_last_error_msg()
                ]],
                'summary' => 'Review could not be completed due to parsing error',
                'safe_to_apply' => false
            ];
        }

        // Ensure required fields
        $review['approved'] = $review['approved'] ?? false;
        $review['safe_to_apply'] = $review['safe_to_apply'] ?? false;
        $review['risk_level'] = $review['risk_level'] ?? 'unknown';
        $review['issues'] = $review['issues'] ?? [];
        $review['summary'] = $review['summary'] ?? '';

        return $review;
    }

    /**
     * Quick security scan without AI (for fast preliminary checks)
     */
    public static function quickSecurityScan($diff)
    {
        $issues = [];
        $lines = explode("\n", $diff);
        $currentFile = null;
        $lineNum = 0;

        $dangerousPatterns = [
            '/\beval\s*\(/' => 'Dangerous eval() usage detected',
            '/\bexec\s*\(/' => 'Shell execution detected',
            '/\bsystem\s*\(/' => 'System command execution detected',
            '/\bpassthru\s*\(/' => 'Passthru command detected',
            '/\bshell_exec\s*\(/' => 'Shell execution detected',
            '/\bproc_open\s*\(/' => 'Process execution detected',
            '/\bpopen\s*\(/' => 'Process execution detected',
            '/\$_(GET|POST|REQUEST|COOKIE)\s*\[.*\]\s*\)/' => 'Unsanitized user input in function call',
            '/password\s*=\s*["\'][^"\']+["\']/' => 'Hardcoded password detected',
            '/api[_-]?key\s*=\s*["\'][^"\']+["\']/' => 'Hardcoded API key detected',
            '/\bmd5\s*\(/' => 'Weak MD5 hashing (use password_hash instead)',
            '/\bsha1\s*\(/' => 'Weak SHA1 hashing (use password_hash instead)',
            '/\bmysql_query\s*\(/' => 'Deprecated mysql_* functions',
            '/\.\s*\$_(GET|POST|REQUEST)/' => 'Direct string concatenation with user input',
            '/file_get_contents\s*\(\s*\$/' => 'Potential SSRF vulnerability',
            '/include\s*\(\s*\$/' => 'Potential LFI vulnerability',
            '/require\s*\(\s*\$/' => 'Potential LFI vulnerability',
        ];

        foreach ($lines as $line) {
            // Track current file
            if (preg_match('/^\+\+\+\s+(?:b\/)?(.+)$/', $line, $matches)) {
                $currentFile = $matches[1];
                $lineNum = 0;
                continue;
            }

            // Only check added lines
            if (strpos($line, '+') === 0 && strpos($line, '+++') !== 0) {
                $lineNum++;
                $codeLine = substr($line, 1);

                foreach ($dangerousPatterns as $pattern => $message) {
                    if (preg_match($pattern, $codeLine)) {
                        $issues[] = [
                            'severity' => 'warning',
                            'file' => $currentFile,
                            'line' => $lineNum,
                            'message' => $message,
                            'code' => trim($codeLine)
                        ];
                    }
                }
            }
        }

        return [
            'passed' => empty($issues),
            'issues' => $issues,
            'scan_type' => 'quick'
        ];
    }

    /**
     * Check if diff touches blocked paths
     */
    public static function checkBlockedPaths($diff, $blockedPaths)
    {
        $violations = [];
        $lines = explode("\n", $diff);

        foreach ($lines as $line) {
            if (preg_match('/^(?:---|\+\+\+)\s+(?:[ab]\/)?(.+)$/', $line, $matches)) {
                $filePath = $matches[1];
                if ($filePath === '/dev/null') continue;

                foreach ($blockedPaths as $blocked) {
                    // Handle glob patterns
                    $blocked = str_replace('~', getenv('HOME') ?: '/home', $blocked);

                    if (strpos($filePath, $blocked) !== false ||
                        fnmatch($blocked, $filePath) ||
                        fnmatch("*{$blocked}*", $filePath)) {
                        $violations[] = [
                            'file' => $filePath,
                            'blocked_pattern' => $blocked
                        ];
                    }
                }
            }
        }

        return [
            'passed' => empty($violations),
            'violations' => $violations
        ];
    }
}
