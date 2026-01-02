<?php
/**
 * CoderAI Settings Controller
 * Admin-only settings management
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../middleware/RequireAuth.php';
require_once __DIR__ . '/../services/SettingsService.php';

class SettingsController
{
    /**
     * GET /api/settings
     * Get all settings (admin only)
     */
    public function index($params, $input)
    {
        RequireAuth::admin();

        $settings = SettingsService::getAll();

        Response::success($settings);
    }

    /**
     * POST /api/settings
     * Update settings (admin only)
     */
    public function store($params, $input)
    {
        RequireAuth::admin();

        if (empty($input)) {
            Response::error('No settings provided', 422);
        }

        // Validate known settings
        $allowedSettings = [
            'openai_api_key',
            'anthropic_api_key',
            'default_model',
            'model_routing',
            'budget_monthly',
            'budget_warning_threshold',
            'safety_require_approval',
            'safety_auto_backup',
            'safety_max_file_size',
            'maintenance_mode'
        ];

        $toUpdate = [];
        foreach ($input as $key => $value) {
            if (in_array($key, $allowedSettings)) {
                // Special handling for model_routing (nested array from form)
                if ($key === 'model_routing') {
                    if (is_array($value)) {
                        // Coming from form as array, encode it
                        $toUpdate[$key] = json_encode($value);
                    } elseif (is_string($value)) {
                        // Already JSON string, use as-is
                        $toUpdate[$key] = $value;
                    }
                } else {
                    $toUpdate[$key] = $value;
                }
            }
        }

        if (empty($toUpdate)) {
            Response::error('No valid settings to update', 422);
        }

        SettingsService::updateBulk($toUpdate);

        Response::success(null, 'Settings updated successfully');
    }

    /**
     * GET /api/settings/{key}
     * Get single setting (admin only)
     */
    public function show($params, $input)
    {
        RequireAuth::admin();

        $key = $params['key'] ?? '';

        if (empty($key)) {
            Response::error('Setting key required', 422);
        }

        $value = SettingsService::get($key);

        if ($value === null) {
            Response::error('Setting not found', 404);
        }

        Response::success(['key' => $key, 'value' => $value]);
    }
}