<?php
/**
 * CoderAI Auto Router
 * ✅ UPDATED: Workspace-based routing with proper model separation
 *
 * Rules:
 * - normal: ALWAYS qwen2.5:14b-instruct (no user selection)
 * - church: ALWAYS qwen2.5:14b-instruct (no user selection)
 * - coder: dropdown with qwen2.5-coder:7b, qwen2.5-coder:14b, qwen2.5:14b-instruct
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/ModelCatalog.php';

class AutoRouter
{
    /**
     * Route to best model for the request
     *
     * @param string $workspace - normal|church|coder
     * @param string $userMessage - The user's message text
     * @param array $options - Additional context (mode, requested_model)
     * @return array - ['model' => string, 'provider' => string, 'reason' => string]
     */
    public static function route($workspace, $userMessage, $options = [])
    {
        // Models for each workspace type
        $instructModel = 'qwen2.5:14b-instruct';
        $coderFast = 'qwen2.5-coder:7b';
        $coderPrecise = 'qwen2.5-coder:14b';

        // Route based on workspace
        switch ($workspace) {
            case 'normal':
                return self::routeNormal($instructModel);

            case 'church':
                return self::routeChurch($instructModel);

            case 'coder':
                return self::routeCoder($coderFast, $coderPrecise, $instructModel, $options);

            default:
                return self::routeNormal($instructModel);
        }
    }

    /**
     * Route for Normal workspace
     * ALWAYS use qwen2.5:14b-instruct
     */
    private static function routeNormal($instructModel)
    {
        return self::buildResult(
            $instructModel,
            'forced_by_rules',
            'rules',
            'normal'
        );
    }

    /**
     * Route for Church workspace
     * ALWAYS use qwen2.5:14b-instruct
     */
    private static function routeChurch($instructModel)
    {
        return self::buildResult(
            $instructModel,
            'forced_by_rules',
            'rules',
            'church'
        );
    }

    /**
     * Route for Coder workspace
     * User can choose from: qwen2.5-coder:7b, qwen2.5-coder:14b, qwen2.5:14b-instruct
     */
    private static function routeCoder($coderFast, $coderPrecise, $instructModel, $options)
    {
        // Check for explicit model request from dropdown
        $requestedModel = $options['requested_model'] ?? null;

        // If user explicitly selected a model from dropdown
        if ($requestedModel) {
            // Validate and use the requested model
            if ($requestedModel === 'qwen2.5-coder:7b' || $requestedModel === '7b' || $requestedModel === 'fast') {
                return self::buildResult($coderFast, 'user_selected', 'fast', 'coder');
            }
            if ($requestedModel === 'qwen2.5-coder:14b' || $requestedModel === '14b' || $requestedModel === 'precise') {
                return self::buildResult($coderPrecise, 'user_selected', 'precise', 'coder');
            }
            if ($requestedModel === 'qwen2.5:14b-instruct' || $requestedModel === 'instruct') {
                return self::buildResult($instructModel, 'user_selected', 'instruct', 'coder');
            }
        }

        // Check mode parameter (for backwards compatibility with mode toggle)
        $mode = $options['mode'] ?? 'fast';

        if ($mode === 'precise') {
            return self::buildResult($coderPrecise, 'mode_selected', 'precise', 'coder');
        }

        // Default to fast coder model
        return self::buildResult($coderFast, 'default', 'fast', 'coder');
    }

    /**
     * Build result array
     */
    private static function buildResult($model, $reason, $mode, $workspace = 'normal')
    {
        return [
            'model' => $model,
            'provider' => 'ollama_gateway',
            'reason' => $reason,
            'mode' => $mode,
            'workspace' => $workspace
        ];
    }

    /**
     * Estimate tokens for message (rough)
     */
    public static function estimateTokens($text)
    {
        // Rough estimate: ~4 chars per token for English
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Get routing explanation for debugging
     */
    public static function explain($workspace, $userMessage, $options = [])
    {
        $result = self::route($workspace, $userMessage, $options);
        
        return [
            'input' => [
                'workspace' => $workspace,
                'message_preview' => substr($userMessage, 0, 100) . (strlen($userMessage) > 100 ? '...' : ''),
                'options' => $options
            ],
            'result' => $result
        ];
    }
}