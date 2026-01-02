<?php
/**
 * CoderAI Projects Controller
 * Projects group conversations within workspaces
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../middleware/RequireAuth.php';
require_once __DIR__ . '/../core/Auth.php';

class ProjectsController
{
    /**
     * GET /api/projects
     * List all projects for user (optionally filtered by workspace_slug)
     */
    public function index($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        $workspaceSlug = $_GET['workspace_slug'] ?? null;

        if ($workspaceSlug) {
            $stmt = $db->prepare("
                SELECT * FROM projects
                WHERE user_id = ? AND workspace_slug = ? AND is_active = 1
                ORDER BY updated_at DESC
            ");
            $stmt->execute([$userId, $workspaceSlug]);
        } else {
            $stmt = $db->prepare("
                SELECT * FROM projects
                WHERE user_id = ? AND is_active = 1
                ORDER BY updated_at DESC
            ");
            $stmt->execute([$userId]);
        }

        $projects = $stmt->fetchAll();

        foreach ($projects as &$project) {
            $project['rules'] = json_decode($project['rules_json'] ?? '[]', true) ?? [];
            unset($project['rules_json']);
        }

        Response::success($projects);
    }

    /**
     * POST /api/projects
     * Create new project
     */
    public function store($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        if (empty($input['name'])) {
            Response::error('Name is required', 422);
        }

        $db = Bootstrap::getDB();

        $workspaceSlug = $input['workspace_slug'] ?? 'normal';
        $rulesJson = isset($input['rules']) ? json_encode($input['rules']) : null;

        $stmt = $db->prepare("
            INSERT INTO projects (workspace_slug, user_id, name, description, rules_json)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $workspaceSlug,
            $userId,
            $input['name'],
            $input['description'] ?? null,
            $rulesJson
        ]);

        $projectId = $db->lastInsertId();

        Response::success(['id' => $projectId], 'Project created', 201);
    }

    /**
     * GET /api/projects/{id}
     * Get single project
     */
    public function show($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("
            SELECT * FROM projects
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$params['id'], $userId]);
        $project = $stmt->fetch();

        if (!$project) {
            Response::error('Project not found', 404);
        }

        $project['rules'] = json_decode($project['rules_json'] ?? '[]', true) ?? [];
        unset($project['rules_json']);

        Response::success($project);
    }

    /**
     * PUT /api/projects/{id}
     * Update project
     */
    public function update($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        // Check ownership
        $stmt = $db->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
        $stmt->execute([$params['id'], $userId]);
        if (!$stmt->fetch()) {
            Response::error('Project not found', 404);
        }

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

        if (isset($input['rules'])) {
            $updates[] = 'rules_json = ?';
            $values[] = json_encode($input['rules']);
        }

        if (isset($input['is_active'])) {
            $updates[] = 'is_active = ?';
            $values[] = $input['is_active'] ? 1 : 0;
        }

        if (empty($updates)) {
            Response::error('No fields to update', 422);
        }

        $updates[] = 'updated_at = NOW()';
        $values[] = $params['id'];
        $stmt = $db->prepare("UPDATE projects SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($values);

        Response::success(null, 'Project updated');
    }

    /**
     * DELETE /api/projects/{id}
     * Delete project (soft delete)
     */
    public function destroy($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        // Check ownership
        $stmt = $db->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
        $stmt->execute([$params['id'], $userId]);
        if (!$stmt->fetch()) {
            Response::error('Project not found', 404);
        }

        // Soft delete
        $stmt = $db->prepare("UPDATE projects SET is_active = 0 WHERE id = ?");
        $stmt->execute([$params['id']]);

        Response::success(null, 'Project deleted');
    }
}
