<?php
/**
 * CoderAI Workspaces Controller
 * Fixed workspaces: normal, church, coder
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../middleware/RequireAuth.php';

class WorkspacesController
{
    /**
     * GET /api/workspaces
     * List all active workspaces
     */
    public function index($params, $input)
    {
        RequireAuth::handle();

        $db = Bootstrap::getDB();
        $stmt = $db->query("
            SELECT id, slug, name, description, icon, settings_json
            FROM workspaces
            WHERE is_active = 1
            ORDER BY id ASC
        ");
        $workspaces = $stmt->fetchAll();

        // Decode settings JSON
        foreach ($workspaces as &$workspace) {
            $workspace['settings'] = json_decode($workspace['settings_json'], true) ?? [];
            unset($workspace['settings_json']);
        }

        Response::success($workspaces);
    }

    /**
     * GET /api/workspaces/{id}
     * Get single workspace by ID or slug
     */
    public function show($params, $input)
    {
        RequireAuth::handle();

        $identifier = $params['id'] ?? '';

        $db = Bootstrap::getDB();

        // Check if numeric (ID) or string (slug)
        if (is_numeric($identifier)) {
            $stmt = $db->prepare("SELECT * FROM workspaces WHERE id = ? AND is_active = 1");
        } else {
            $stmt = $db->prepare("SELECT * FROM workspaces WHERE slug = ? AND is_active = 1");
        }

        $stmt->execute([$identifier]);
        $workspace = $stmt->fetch();

        if (!$workspace) {
            Response::error('Workspace not found', 404);
        }

        $workspace['settings'] = json_decode($workspace['settings_json'], true) ?? [];
        unset($workspace['settings_json']);

        Response::success($workspace);
    }

    /**
     * PUT /api/workspaces/{id}
     * Update workspace settings (admin only)
     */
    public function update($params, $input)
    {
        RequireAuth::admin();

        $id = $params['id'] ?? '';

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT * FROM workspaces WHERE id = ?");
        $stmt->execute([$id]);
        $workspace = $stmt->fetch();

        if (!$workspace) {
            Response::error('Workspace not found', 404);
        }

        // Build update query
        $updates = [];
        $values = [];

        if (isset($input['name'])) {
            $updates[] = 'name = ?';
            $values[] = $input['name'];
        }

        if (isset($input['description'])) {
            $updates[] = 'description = ?';
            $values[] = $input['description'];
        }

        if (isset($input['icon'])) {
            $updates[] = 'icon = ?';
            $values[] = $input['icon'];
        }

        if (isset($input['is_active'])) {
            $updates[] = 'is_active = ?';
            $values[] = $input['is_active'] ? 1 : 0;
        }

        if (isset($input['settings'])) {
            $updates[] = 'settings_json = ?';
            $values[] = json_encode($input['settings']);
        }

        if (empty($updates)) {
            Response::error('No fields to update', 422);
        }

        $values[] = $id;
        $stmt = $db->prepare("UPDATE workspaces SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($values);

        Response::success(null, 'Workspace updated');
    }
}
