<?php
/**
 * CoderAI Rules Service
 * Loads and merges rules: global → workspace → project
 *
 * FIXED: Removed markdown fluff from buildSystemPrompt
 * FIXED: Plain text, directive format for better model compliance
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class RulesService
{
    private static $rulesPath;
    private static $cache = [];

    /**
     * Valid workspace slugs
     */
    private static $validWorkspaces = ['normal', 'church', 'coder'];

    /**
     * Get rules for a workspace
     *
     * IMPORTANT: Each workspace loads its own rules file:
     * - normal → normal.json (general chat)
     * - church → church.json (spiritual guidance)
     * - coder  → coder.json (coding assistant)
     */
    public static function getWorkspaceRules($workspace)
    {
        // Validate workspace - must be one of the 3 valid options
        if (!in_array($workspace, self::$validWorkspaces)) {
            error_log("[RulesService] Invalid workspace '{$workspace}', defaulting to 'normal'");
            $workspace = 'normal';
        }

        if (isset(self::$cache[$workspace])) {
            return self::$cache[$workspace];
        }

        $rulesFile = self::getRulesPath() . '/' . $workspace . '.json';

        if (!file_exists($rulesFile)) {
            error_log("[RulesService] Rules file not found: {$rulesFile}");
            return self::getDefaultRules();
        }

        $rules = json_decode(file_get_contents($rulesFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[RulesService] JSON parse error for {$workspace}: " . json_last_error_msg());
            return self::getDefaultRules();
        }

        // Ensure workspace name is included in rules
        $rules['_workspace'] = $workspace;

        self::$cache[$workspace] = $rules;
        return $rules;
    }

    /**
     * Build system prompt - SINGLE SOURCE OF TRUTH
     *
     * FIXED: Plain text format, no markdown, directive style
     */
    public static function buildSystemPrompt($workspace, $projectRules = [])
    {
        $rules = self::getWorkspaceRules($workspace);

        // Merge with project rules if provided
        if (!empty($projectRules)) {
            $rules = self::deepMerge($rules, $projectRules);
        }

        // Start with base system prompt from rules file
        $prompt = $rules['system_prompt'] ?? '';

        // Add behavior constraints (plain text, no markdown)
        if (!empty($rules['behavior'])) {
            $behavior = $rules['behavior'];
            $behaviorLines = [];

            if (!empty($behavior['tone'])) {
                $behaviorLines[] = "Tone: {$behavior['tone']}";
            }
            if (!empty($behavior['verbosity'])) {
                $behaviorLines[] = "Verbosity: {$behavior['verbosity']}";
            }

            if (!empty($behaviorLines)) {
                $prompt .= "\n\nBEHAVIOR: " . implode('. ', $behaviorLines) . ".";
            }
        }

        // Add disabled capabilities
        if (!empty($rules['capabilities'])) {
            $disabled = [];
            foreach ($rules['capabilities'] as $capability => $enabled) {
                if (!$enabled) {
                    $disabled[] = str_replace('_', ' ', $capability);
                }
            }
            if (!empty($disabled)) {
                $prompt .= "\n\nDISABLED: " . implode(', ', $disabled) . ".";
            }
        }

        // Add restrictions (plain text)
        if (!empty($rules['restrictions'])) {
            $restrictions = $rules['restrictions'];

            // Blocked topics (for non-coder workspaces)
            if (!empty($restrictions['blocked_topics'])) {
                $prompt .= "\n\nBLOCKED TOPICS: " . implode(', ', $restrictions['blocked_topics']) . ". Decline politely if asked.";
            }

            // Blocked paths
            if (!empty($restrictions['blocked_paths'])) {
                $prompt .= "\n\nBLOCKED PATHS: " . implode(', ', $restrictions['blocked_paths']) . ". Never read or modify these.";
            }

            // Allowed extensions
            if (!empty($restrictions['allowed_extensions'])) {
                $prompt .= "\n\nALLOWED FILE TYPES: " . implode(', ', $restrictions['allowed_extensions']) . " only.";
            }

            // Church-specific rules
            if ($workspace === 'church') {
                if (!empty($restrictions['require_scripture_for_claims'])) {
                    $prompt .= "\n\nSCRIPTURE REQUIREMENT: Every spiritual claim must include direct Scripture quotation with context (1 verse before and after). If unavailable, say 'Onvoldoende bronteks'.";
                }
                if (!empty($restrictions['allowed_sources'])) {
                    $prompt .= "\n\nALLOWED BIBLE VERSIONS: " . implode(', ', $restrictions['allowed_sources']) . " only.";
                }
            }
        }

        // Add project-specific instructions
        if (!empty($projectRules['instructions'])) {
            $prompt .= "\n\nPROJECT INSTRUCTIONS: " . $projectRules['instructions'];
        }

        // Coder safety (brief, not verbose)
        if ($workspace === 'coder') {
            $prompt .= "\n\nSAFETY: Require confirmation for deletions. Create backups. Never modify credentials.";
        }

        return trim($prompt);
    }

    /**
     * Get merged rules for a project
     */
    public static function getMergedRules($workspace, $projectRules = [])
    {
        $workspaceRules = self::getWorkspaceRules($workspace);

        if (empty($projectRules)) {
            return $workspaceRules;
        }

        return self::deepMerge($workspaceRules, $projectRules);
    }

    /**
     * Check if action is allowed
     */
    public static function isActionAllowed($workspace, $action, $context = [])
    {
        $rules = self::getWorkspaceRules($workspace);

        if (isset($rules['capabilities'][$action])) {
            return $rules['capabilities'][$action];
        }

        if ($action === 'file_access' && !empty($context['path'])) {
            return self::isPathAllowed($rules, $context['path']);
        }

        return true;
    }

    /**
     * Check if file path is allowed
     */
    public static function isPathAllowed($rules, $path)
    {
        $blockedPaths = $rules['restrictions']['blocked_paths'] ?? [];

        foreach ($blockedPaths as $blocked) {
            if (strpos($path, $blocked) !== false) {
                return false;
            }
        }

        $allowedExtensions = $rules['restrictions']['allowed_extensions'] ?? [];

        if (!empty($allowedExtensions)) {
            $extension = '.' . pathinfo($path, PATHINFO_EXTENSION);
            if (!in_array($extension, $allowedExtensions)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get model for phase (coder workspace)
     */
    public static function getModelForPhase($workspace, $phase)
    {
        $rules = self::getWorkspaceRules($workspace);

        if (isset($rules['phases'][$phase]['model'])) {
            return $rules['phases'][$phase]['model'];
        }

        return $rules['model_preferences']['default'] ?? 'gpt-4';
    }

    /**
     * Get model preferences for workspace
     */
    public static function getModelPreferences($workspace)
    {
        $rules = self::getWorkspaceRules($workspace);
        return $rules['model_preferences'] ?? [
            'default' => 'gpt-4',
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'context_budget_tokens' => 24000,
            'output_reserve_tokens' => 4000
        ];
    }

    /**
     * Load rules (alias for backward compatibility)
     */
    public static function loadRules($workspace)
    {
        return self::getWorkspaceRules($workspace);
    }

    /**
     * Get rules path
     */
    private static function getRulesPath()
    {
        if (!self::$rulesPath) {
            self::$rulesPath = __DIR__ . '/../rules';
        }
        return self::$rulesPath;
    }

    /**
     * Get default rules
     */
    private static function getDefaultRules()
    {
        return [
            'name' => 'Default',
            'system_prompt' => 'You are a helpful AI assistant.',
            'behavior' => ['tone' => 'friendly'],
            'capabilities' => [],
            'restrictions' => [],
            'model_preferences' => [
                'default' => 'gpt-4',
                'temperature' => 0.7,
                'max_tokens' => 4096,
                'context_budget_tokens' => 24000,
                'output_reserve_tokens' => 4000
            ]
        ];
    }

    /**
     * Deep merge arrays
     */
    private static function deepMerge($base, $override)
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * Clear cache
     */
    public static function clearCache()
    {
        self::$cache = [];
    }
}
