<?php
/**
 * CoderAI Threads Controller
 * Threads are conversations within projects
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../middleware/RequireAuth.php';
require_once __DIR__ . '/../core/Auth.php';

class ThreadsController
{
    /**
     * GET /api/threads
     * List threads (filtered by project if provided)
     */
    public function index($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        $projectId = $_GET['project_id'] ?? null;

        if ($projectId) {
            // Verify project ownership
            $stmt = $db->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
            $stmt->execute([$projectId, $userId]);
            if (!$stmt->fetch()) {
                Response::error('Project not found', 404);
            }

            $stmt = $db->prepare("
                SELECT t.*,
                       (SELECT COUNT(*) FROM messages m WHERE m.thread_id = t.id) as message_count
                FROM threads t
                WHERE t.project_id = ? AND t.user_id = ? AND t.is_active = 1
                ORDER BY t.updated_at DESC
            ");
            $stmt->execute([$projectId, $userId]);
        } else {
            $stmt = $db->prepare("
                SELECT t.*, p.name as project_name,
                       (SELECT COUNT(*) FROM messages m WHERE m.thread_id = t.id) as message_count
                FROM threads t
                JOIN projects p ON t.project_id = p.id
                WHERE t.user_id = ? AND t.is_active = 1
                ORDER BY t.updated_at DESC
                LIMIT 50
            ");
            $stmt->execute([$userId]);
        }

        $threads = $stmt->fetchAll();

        Response::success($threads);
    }

    /**
     * POST /api/threads
     * Create new thread
     */
    public function store($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        Response::validate($input, ['project_id']);

        $db = Bootstrap::getDB();

        // Verify project ownership
        $stmt = $db->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
        $stmt->execute([$input['project_id'], $userId]);
        if (!$stmt->fetch()) {
            Response::error('Project not found', 404);
        }

        $title = $input['title'] ?? 'New Conversation';

        $stmt = $db->prepare("
            INSERT INTO threads (project_id, user_id, title)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$input['project_id'], $userId, $title]);

        $threadId = $db->lastInsertId();

        Response::success(['id' => $threadId], 'Thread created', 201);
    }

    /**
     * GET /api/threads/{id}
     * Get single thread with messages
     */
    public function show($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("
            SELECT t.*, p.name as project_name, p.workspace_id
            FROM threads t
            JOIN projects p ON t.project_id = p.id
            WHERE t.id = ? AND t.user_id = ?
        ");
        $stmt->execute([$params['id'], $userId]);
        $thread = $stmt->fetch();

        if (!$thread) {
            Response::error('Thread not found', 404);
        }

        // Get messages
        $stmt = $db->prepare("
            SELECT id, role, content, tokens_used, created_at
            FROM messages
            WHERE thread_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$params['id']]);
        $messages = $stmt->fetchAll();

        Response::success([
            'thread' => $thread,
            'messages' => $messages
        ]);
    }

    /**
     * PUT /api/threads/{id}
     * Update thread (title)
     */
    public function update($params, $input)
{
    RequireAuth::handle();
    $userId = Auth::id();

    $db = Bootstrap::getDB();

    // Check ownership
    $stmt = $db->prepare("SELECT id FROM threads WHERE id = ? AND user_id = ?");
    $stmt->execute([$params['id'], $userId]);
    if (!$stmt->fetch()) {
        Response::error('Thread not found', 404);
    }

    if (!isset($input['title'])) {
        Response::error('Title required', 422);
    }

    $stmt = $db->prepare("UPDATE threads SET title = ? WHERE id = ?");
    $stmt->execute([$input['title'], $params['id']]);

    Response::success(null, 'Thread updated');
}

    /**
     * DELETE /api/threads/{id}
     * Delete thread (soft delete)
     */
    public function destroy($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        // Check ownership
        $stmt = $db->prepare("SELECT id FROM threads WHERE id = ? AND user_id = ?");
        $stmt->execute([$params['id'], $userId]);
        if (!$stmt->fetch()) {
            Response::error('Thread not found', 404);
        }

        // Soft delete
        $stmt = $db->prepare("UPDATE threads SET is_active = 0 WHERE id = ?");
        $stmt->execute([$params['id']]);

        Response::success(null, 'Thread deleted');
    }
}
