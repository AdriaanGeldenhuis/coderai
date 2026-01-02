<?php
/**
 * CoderAI Model Catalog
 * ✅ UPDATED: 5 models total
 * - Chat/Church: 3 general models
 * - Coder: 5 models (3 general + 2 coder)
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class ModelCatalog
{
    /**
     * Get all available models
     * ✅ 5 models: 3 general + 2 coder
     */
    public static function getAll()
    {
        return [
            'ollama_gateway' => [
                // General models (for Chat/Church)
                'qwen2.5:14b-instruct' => [
                    'name' => 'Qwen 2.5 14B Instruct',
                    'context' => 32768,
                    'cost_per_1k' => ['input' => 0, 'output' => 0],
                    'capabilities' => ['chat'],
                    'category' => 'general',
                    'description' => 'General purpose instruct model'
                ],
                'qwen2.5:32b' => [
                    'name' => 'Qwen 2.5 32B',
                    'context' => 32768,
                    'cost_per_1k' => ['input' => 0, 'output' => 0],
                    'capabilities' => ['chat'],
                    'category' => 'general',
                    'description' => 'Larger general purpose model'
                ],
                'mistral-small:24b' => [
                    'name' => 'Mistral Small 24B',
                    'context' => 32768,
                    'cost_per_1k' => ['input' => 0, 'output' => 0],
                    'capabilities' => ['chat'],
                    'category' => 'general',
                    'description' => 'Mistral small model for general tasks'
                ],
                // Coder models (only for Coder workspace)
                'qwen2.5-coder:7b' => [
                    'name' => 'Qwen 2.5 Coder 7B (Fast)',
                    'context' => 32768,
                    'cost_per_1k' => ['input' => 0, 'output' => 0],
                    'capabilities' => ['chat', 'code'],
                    'category' => 'coder',
                    'description' => 'Fast coding model'
                ],
                'qwen2.5-coder:14b' => [
                    'name' => 'Qwen 2.5 Coder 14B (Precise)',
                    'context' => 32768,
                    'cost_per_1k' => ['input' => 0, 'output' => 0],
                    'capabilities' => ['chat', 'code'],
                    'category' => 'coder',
                    'description' => 'Precise coding model'
                ]
            ]
        ];
    }

    /**
     * Get models for a specific workspace
     * Chat/Church = general models only
     * Coder = all models
     */
    public static function getModelsForWorkspace($workspace)
    {
        $all = self::getAll()['ollama_gateway'];
        $models = [];

        foreach ($all as $id => $model) {
            // Coder workspace gets ALL models
            if ($workspace === 'coder') {
                $models[$id] = $model;
            }
            // Chat/Church only get general models
            else if ($model['category'] === 'general') {
                $models[$id] = $model;
            }
        }

        return $models;
    }

    /**
     * Get models for a specific provider
     */
    public static function getByProvider($provider)
    {
        $all = self::getAll();
        return $all[$provider] ?? [];
    }

    /**
     * Get model info
     */
    public static function getModel($modelId)
    {
        $all = self::getAll();
        
        foreach ($all as $provider => $models) {
            if (isset($models[$modelId])) {
                return array_merge($models[$modelId], [
                    'id' => $modelId,
                    'provider' => $provider
                ]);
            }
        }

        return null;
    }

    /**
     * Check if model exists and is allowed
     */
    public static function isAllowed($modelId)
    {
        return in_array($modelId, [
            'qwen2.5:14b-instruct',
            'qwen2.5:32b',
            'mistral-small:24b',
            'qwen2.5-coder:7b',
            'qwen2.5-coder:14b'
        ]);
    }

    /**
     * Get provider for a model
     */
    public static function getProviderForModel($modelId)
    {
        if (self::isAllowed($modelId)) {
            return 'ollama_gateway';
        }
        return null;
    }

    /**
     * Estimate cost for tokens (always $0 for self-hosted)
     */
    public static function estimateCost($modelId, $inputTokens, $outputTokens)
    {
        return 0;
    }

    /**
     * Get cheap models (fast model)
     */
    public static function getCheapModels()
    {
        return ['qwen2.5-coder:7b'];
    }

    /**
     * Get premium models (precise model)
     */
    public static function getPremiumModels()
    {
        return ['qwen2.5-coder:14b'];
    }

    /**
     * Get models with vision capability (none)
     */
    public static function getVisionModels()
    {
        return [];
    }

    /**
     * Get models optimized for coding (both)
     */
    public static function getCodingModels()
    {
        return ['qwen2.5-coder:7b', 'qwen2.5-coder:14b'];
    }

    /**
     * Get models with reasoning capability (precise model)
     */
    public static function getReasoningModels()
    {
        return ['qwen2.5-coder:14b'];
    }

    /**
     * Normalize model name (handle aliases)
     */
    public static function normalizeModelName($modelName)
    {
        $aliases = [
            // Shortcuts
            '7b' => 'qwen2.5-coder:7b',
            '14b' => 'qwen2.5-coder:14b',
            'fast' => 'qwen2.5-coder:7b',
            'precise' => 'qwen2.5-coder:14b',
            'quick' => 'qwen2.5-coder:7b',
            'smart' => 'qwen2.5-coder:14b',
            'instruct' => 'qwen2.5:14b-instruct',
            'chat' => 'qwen2.5:14b-instruct',

            // Full names
            'qwen2.5:14b-instruct' => 'qwen2.5:14b-instruct',
            'qwen2.5-coder:7b' => 'qwen2.5-coder:7b',
            'qwen2.5-coder:14b' => 'qwen2.5-coder:14b'
        ];

        $normalized = $aliases[strtolower($modelName)] ?? $modelName;

        // Verify the model exists
        if (self::isAllowed($normalized)) {
            return $normalized;
        }

        // Return original if not found (will fail validation later)
        return $modelName;
    }

    /**
     * Get default model for a workspace
     */
    public static function getDefaultForWorkspace($workspace)
    {
        // CHAT and CHURCH use instruct model, CODER uses coder model
        if ($workspace === 'normal' || $workspace === 'church') {
            return 'qwen2.5:14b-instruct';
        }
        return 'qwen2.5-coder:7b';
    }

    /**
     * Get recommended model based on task
     */
    public static function getRecommended($task = 'general')
    {
        $recommendations = [
            'general' => 'qwen2.5-coder:7b',
            'complex' => 'qwen2.5-coder:14b',
            'coding' => 'qwen2.5-coder:14b',
            'reasoning' => 'qwen2.5-coder:14b',
            'fast' => 'qwen2.5-coder:7b',
            'cheap' => 'qwen2.5-coder:7b',
            'analysis' => 'qwen2.5-coder:14b'
        ];

        return $recommendations[$task] ?? 'qwen2.5-coder:7b';
    }

    /**
     * Get all model IDs as flat array
     */
    public static function getAllIds()
    {
        return ['qwen2.5:14b-instruct', 'qwen2.5-coder:7b', 'qwen2.5-coder:14b'];
    }

    /**
     * Get model tier (cheap/premium)
     */
    public static function getTier($modelId)
    {
        if ($modelId === 'qwen2.5-coder:7b') {
            return 'cheap';
        }
        if ($modelId === 'qwen2.5-coder:14b' || $modelId === 'qwen2.5:14b-instruct') {
            return 'premium';
        }
        return 'medium';
    }

    /**
     * Compare cost between two models (always $0)
     */
    public static function compareCost($model1, $model2, $inputTokens = 1000, $outputTokens = 1000)
    {
        return [
            'model1' => ['id' => $model1, 'cost' => 0],
            'model2' => ['id' => $model2, 'cost' => 0],
            'cheaper' => $model1,
            'difference' => 0,
            'ratio' => 1
        ];
    }
}