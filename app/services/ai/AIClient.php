<?php
/**
 * CoderAI AI Client
 * Main interface for AI providers
 * ✅ UPDATED: Only uses Ollama Gateway with qwen2.5-coder models
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/OllamaGatewayProvider.php';
require_once __DIR__ . '/ModelCatalog.php';
require_once __DIR__ . '/../SettingsService.php';

class AIClient
{
    private static $providers = [];

    /**
     * Send message to AI and get response
     */
    public static function chat($messages, $options = [])
    {
        $model = $options['model'] ?? getenv('AI_MODEL_FAST');
        
        // Normalize model name
        $model = ModelCatalog::normalizeModelName($model);
        
        // Validate model (only allow our 2 models)
        if (!ModelCatalog::isAllowed($model)) {
            throw new Exception("Model not allowed: {$model}. Only qwen2.5-coder:7b and qwen2.5-coder:14b are supported.");
        }

        $provider = self::getProvider('ollama_gateway');

        if (!$provider) {
            throw new Exception("Ollama Gateway provider not available. Check AI_GATEWAY_URL and AI_GATEWAY_KEY in .env");
        }

        return $provider->chat($messages, array_merge($options, ['model' => $model]));
    }

    /**
     * Get or create provider instance
     */
    private static function getProvider($name)
    {
        if (!isset(self::$providers[$name])) {
            if ($name === 'ollama_gateway') {
                try {
                    self::$providers[$name] = new OllamaGatewayProvider();
                } catch (Exception $e) {
                    error_log('Failed to initialize Ollama Gateway: ' . $e->getMessage());
                    return null;
                }
            } else {
                return null;
            }
        }

        return self::$providers[$name];
    }

    /**
     * Track AI usage
     */
    public static function trackUsage($userId, $workspaceSlug, $model, $provider, $promptTokens, $completionTokens)
    {
        try {
            $db = Bootstrap::getDB();

            // Cost is $0 for self-hosted
            $cost = 0;

            $stmt = $db->prepare("
                INSERT INTO ai_usage (user_id, workspace_slug, model, provider, prompt_tokens, completion_tokens, total_tokens, cost_usd)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $workspaceSlug,
                $model,
                $provider,
                $promptTokens,
                $completionTokens,
                $promptTokens + $completionTokens,
                $cost
            ]);

            error_log(sprintf(
                "AI Usage tracked: User %d, Workspace %s, Model %s, Tokens %d",
                $userId,
                $workspaceSlug,
                $model,
                $promptTokens + $completionTokens
            ));

        } catch (Exception $e) {
            error_log('Usage tracking failed: ' . $e->getMessage());
        }
    }

    /**
     * Get usage stats for user
     */
    public static function getUsageStats($userId, $period = 'month')
    {
        $db = Bootstrap::getDB();

        $dateFilter = match($period) {
            'day' => 'DATE(created_at) = CURDATE()',
            'week' => 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month' => 'created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            default => '1=1'
        };

        $stmt = $db->prepare("
            SELECT
                COUNT(*) as requests,
                SUM(total_tokens) as total_tokens,
                SUM(cost_usd) as total_cost
            FROM ai_usage
            WHERE user_id = ? AND {$dateFilter}
        ");
        $stmt->execute([$userId]);

        return $stmt->fetch();
    }

    /**
     * Estimate tokens for text (rough approximation)
     */
    public static function estimateTokens($text)
    {
        // Very rough: ~4 chars per token for English
        return (int) ceil(strlen($text) / 4);
    }
}