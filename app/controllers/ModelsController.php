<?php
/**
 * CoderAI Models Controller
 * ✅ UPDATED: 5 models, filtered by workspace
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../middleware/RequireAuth.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../services/ai/ModelCatalog.php';
require_once __DIR__ . '/../services/SettingsService.php';

class ModelsController
{
    /**
     * GET /api/models
     * Get available models for the user
     * ✅ Returns workspace-specific models
     */
    public function index($params, $input)
    {
        RequireAuth::handle();

        // Check if gateway is configured
        $hasGateway = !empty(getenv('AI_GATEWAY_URL')) && !empty(getenv('AI_GATEWAY_KEY'));

        // Build model lists for each workspace
        $workspaceModels = [];
        foreach (['normal', 'church', 'coder'] as $workspace) {
            $models = ModelCatalog::getModelsForWorkspace($workspace);
            $workspaceModels[$workspace] = [];
            foreach ($models as $id => $model) {
                $workspaceModels[$workspace][] = [
                    'id' => $id,
                    'name' => $model['name'],
                    'category' => $model['category'],
                    'provider' => 'ollama_gateway'
                ];
            }
        }

        // All models flat list
        $allModels = ModelCatalog::getAll()['ollama_gateway'];
        $flatList = [];
        foreach ($allModels as $id => $model) {
            $flatList[] = [
                'id' => $id,
                'name' => $model['name'],
                'category' => $model['category'],
                'provider' => 'ollama_gateway'
            ];
        }

        Response::success([
            'providers' => [
                'ollama_gateway' => $hasGateway
            ],
            'models' => $flatList,
            'workspace_models' => $workspaceModels,
            'defaults' => [
                'normal' => 'qwen2.5:14b-instruct',
                'church' => 'qwen2.5:14b-instruct',
                'coder' => 'qwen2.5-coder:7b'
            ]
        ]);
    }

    /**
     * GET /api/models/{id}
     * Get specific model info
     */
    public function show($params, $input)
    {
        RequireAuth::handle();

        $modelId = $params['id'] ?? '';
        $model = ModelCatalog::getModel($modelId);

        if (!$model) {
            Response::error('Model not found', 404);
        }

        // Check if gateway is configured
        $hasGateway = !empty(getenv('AI_GATEWAY_URL')) && !empty(getenv('AI_GATEWAY_KEY'));
        
        if (!$hasGateway) {
            Response::error('Ollama Gateway not configured', 400);
        }

        // Add tier info
        $model['tier'] = ModelCatalog::getTier($modelId);

        Response::success($model);
    }
}