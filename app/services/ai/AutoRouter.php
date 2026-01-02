<?php
/**
 * CoderAI Auto Router
 * ✅ UPDATED: User can select ANY model from dropdown in ALL workspaces
 *
 * All workspaces now have model selection:
 * - normal: User selects from general models
 * - church: User selects from general models
 * - coder: User selects from all models (general + coder)
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
        // Check for explicit model request from dropdown (works for ALL workspaces)
        $requestedModel = $options['requested_model'] ?? null;

        if ($requestedModel && ModelCatalog::isAllowed($requestedModel)) {
            // User selected a valid model - USE IT regardless of workspace
            return self::buildResult(
                $requestedModel,
                'user_selected',
                self::getModeForModel($requestedModel),
                $workspace
            );
        }

        // No model selected or invalid - use workspace defaults
        $defaultModel = ModelCatalog::getDefaultForWorkspace($workspace);
        return self::buildResult(
            $defaultModel,
            'default',
            self::getModeForModel($defaultModel),
            $workspace
        );
    }

    /**
     * Get mode label for a model
     */
    private static function getModeForModel($model)
    {
        $modes = [
            'qwen2.5:14b-instruct' => 'instruct',
            'qwen2.5:32b' => 'large',
            'mistral-small:24b' => 'mistral',
            'qwen2.5-coder:7b' => 'fast',
            'qwen2.5-coder:14b' => 'precise'
        ];
        return $modes[$model] ?? 'default';
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