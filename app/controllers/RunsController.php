<?php
/**
 * CoderAI Runs Controller
 * API endpoints for coder runs (Section 10)
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../middleware/RequireAuth.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../services/coder/Planner.php';
require_once __DIR__ . '/../services/coder/Coder.php';
require_once __DIR__ . '/../services/coder/Reviewer.php';
require_once __DIR__ . '/../services/coder/GitService.php';
require_once __DIR__ . '/../services/coder/FileApplier.php';
require_once __DIR__ . '/../services/RulesService.php';

class RunsController
{
    /**
     * GET /api/runs
     * List runs for current user
     */
    public function index($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $threadId = $_GET['thread_id'] ?? null;

        $db = Bootstrap::getDB();

        if ($threadId) {
            // Verify thread access
            $stmt = $db->prepare("
                SELECT t.id FROM threads t
                JOIN projects p ON t.project_id = p.id
                WHERE t.id = ? AND p.user_id = ?
            ");
            $stmt->execute([$threadId, $userId]);
            if (!$stmt->fetch()) {
                Response::error('Thread not found', 404);
            }

            $stmt = $db->prepare("
                SELECT id, status, request, created_at, completed_at
                FROM runs
                WHERE thread_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$threadId]);
        } else {
            $stmt = $db->prepare("
                SELECT id, thread_id, status, request, created_at, completed_at
                FROM runs
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$userId]);
        }

        Response::success($stmt->fetchAll());
    }

    /**
     * POST /api/runs
     * Create a new run
     */
    public function store($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        Response::validate($input, ['thread_id', 'request']);

        $db = Bootstrap::getDB();

        // Verify thread exists and user has access
        $stmt = $db->prepare("
            SELECT t.*, p.repo_id, p.user_id as project_owner
            FROM threads t
            JOIN projects p ON t.project_id = p.id
            WHERE t.id = ?
        ");
        $stmt->execute([$input['thread_id']]);
        $thread = $stmt->fetch();

        if (!$thread) {
            Response::error('Thread not found', 404);
        }

        if ($thread['project_owner'] != $userId) {
            Response::error('Access denied', 403);
        }

        if (!$thread['repo_id']) {
            Response::error('Thread has no repository assigned', 400);
        }

        // Create run record
        $stmt = $db->prepare("
            INSERT INTO runs (thread_id, repo_id, user_id, status, request)
            VALUES (?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([
            $input['thread_id'],
            $thread['repo_id'],
            $userId,
            $input['request']
        ]);

        $runId = $db->lastInsertId();

        Response::success([
            'id' => $runId,
            'status' => 'pending',
            'next_step' => '/api/runs/' . $runId . '/plan'
        ], 'Run created', 201);
    }

    /**
     * GET /api/runs/{id}
     * Get run details
     */
    public function show($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("
            SELECT r.*, repo.label as repo_label, repo.base_path
            FROM runs r
            JOIN repos repo ON r.repo_id = repo.id
            WHERE r.id = ? AND r.user_id = ?
        ");
        $stmt->execute([$params['id'], $userId]);
        $run = $stmt->fetch();

        if (!$run) {
            Response::error('Run not found', 404);
        }

        // Get steps
        $stmt = $db->prepare("
            SELECT * FROM run_steps WHERE run_id = ? ORDER BY id ASC
        ");
        $stmt->execute([$params['id']]);
        $steps = $stmt->fetchAll();

        // Parse JSON fields
        $run['plan'] = $run['plan_json'] ? json_decode($run['plan_json'], true) : null;
        $run['review'] = $run['review_result'] ? json_decode($run['review_result'], true) : null;
        unset($run['plan_json'], $run['review_result']);

        Response::success([
            'run' => $run,
            'steps' => $steps
        ]);
    }

    /**
     * POST /api/runs/{id}/plan
     * Execute planning phase
     */
    public function plan($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        $run = $this->getRunWithAccess($params['id'], $userId, $db);

        if ($run['status'] !== 'pending') {
            Response::error('Run already started', 400);
        }

        // Get repo
        $stmt = $db->prepare("SELECT * FROM repos WHERE id = ?");
        $stmt->execute([$run['repo_id']]);
        $repo = $stmt->fetch();

        // Update status
        $stmt = $db->prepare("UPDATE runs SET status = 'planning' WHERE id = ?");
        $stmt->execute([$params['id']]);

        // Create step record
        $stmt = $db->prepare("
            INSERT INTO run_steps (run_id, phase, status, started_at)
            VALUES (?, 'plan', 'running', NOW())
        ");
        $stmt->execute([$params['id']]);
        $stepId = $db->lastInsertId();

        try {
            // Execute planning
            $result = Planner::plan($run['request'], [
                'base_path' => $repo['base_path'],
                'label' => $repo['label']
            ]);

            // Read files for context if needed
            $filesToRead = $result['plan']['files_to_read'] ?? [];
            $fileContents = [];
            if (!empty($filesToRead)) {
                $fileContents = Planner::readFilesForContext([
                    'base_path' => $repo['base_path']
                ], $filesToRead);
            }

            $tokensUsed = ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0);

            // Update step
            $stmt = $db->prepare("
                UPDATE run_steps SET
                    status = 'completed',
                    output_json = ?,
                    model = ?,
                    tokens_used = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                json_encode($result['plan']),
                $result['model'],
                $tokensUsed,
                $stepId
            ]);

            // Update run
            $stmt = $db->prepare("UPDATE runs SET plan_json = ? WHERE id = ?");
            $stmt->execute([json_encode($result['plan']), $params['id']]);

            Response::success([
                'plan' => $result['plan'],
                'files_read' => array_keys($fileContents),
                'model' => $result['model'],
                'usage' => $result['usage'],
                'next_step' => '/api/runs/' . $params['id'] . '/code'
            ]);

        } catch (Exception $e) {
            $stmt = $db->prepare("
                UPDATE run_steps SET
                    status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $stepId]);

            $stmt = $db->prepare("
                UPDATE runs SET status = 'failed', error_message = ? WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $params['id']]);

            Response::error('Planning failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/runs/{id}/code
     * Execute coding phase
     */
    public function code($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        $run = $this->getRunWithAccess($params['id'], $userId, $db);

        if ($run['status'] !== 'planning' || !$run['plan_json']) {
            Response::error('Planning phase must be completed first', 400);
        }

        $plan = json_decode($run['plan_json'], true);

        $stmt = $db->prepare("SELECT * FROM repos WHERE id = ?");
        $stmt->execute([$run['repo_id']]);
        $repo = $stmt->fetch();

        // Update status
        $stmt = $db->prepare("UPDATE runs SET status = 'coding' WHERE id = ?");
        $stmt->execute([$params['id']]);

        // Create step record
        $stmt = $db->prepare("
            INSERT INTO run_steps (run_id, phase, status, started_at)
            VALUES (?, 'code', 'running', NOW())
        ");
        $stmt->execute([$params['id']]);
        $stepId = $db->lastInsertId();

        try {
            // Read files to modify
            $filesToRead = array_column($plan['files_to_modify'] ?? [], 'path');
            $fileContents = Planner::readFilesForContext([
                'base_path' => $repo['base_path']
            ], $filesToRead);

            // Generate code
            $result = Coder::generateCode($plan, [
                'base_path' => $repo['base_path'],
                'label' => $repo['label']
            ], $fileContents);

            $tokensUsed = ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0);

            // Update step
            $stmt = $db->prepare("
                UPDATE run_steps SET
                    status = 'completed',
                    output_json = ?,
                    model = ?,
                    tokens_used = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                json_encode(['diff' => $result['diff']]),
                $result['model'],
                $tokensUsed,
                $stepId
            ]);

            // Update run
            $stmt = $db->prepare("UPDATE runs SET diff_content = ? WHERE id = ?");
            $stmt->execute([$result['diff'], $params['id']]);

            Response::success([
                'diff' => $result['diff'],
                'model' => $result['model'],
                'usage' => $result['usage'],
                'next_step' => '/api/runs/' . $params['id'] . '/review'
            ]);

        } catch (Exception $e) {
            $stmt = $db->prepare("
                UPDATE run_steps SET
                    status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $stepId]);

            $stmt = $db->prepare("
                UPDATE runs SET status = 'failed', error_message = ? WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $params['id']]);

            Response::error('Code generation failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/runs/{id}/review
     * Execute review phase
     */
    public function review($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        $run = $this->getRunWithAccess($params['id'], $userId, $db);

        if ($run['status'] !== 'coding' || !$run['diff_content']) {
            Response::error('Coding phase must be completed first', 400);
        }

        $plan = json_decode($run['plan_json'], true);

        $stmt = $db->prepare("SELECT * FROM repos WHERE id = ?");
        $stmt->execute([$run['repo_id']]);
        $repo = $stmt->fetch();

        // Update status
        $stmt = $db->prepare("UPDATE runs SET status = 'reviewing' WHERE id = ?");
        $stmt->execute([$params['id']]);

        // Create step record
        $stmt = $db->prepare("
            INSERT INTO run_steps (run_id, phase, status, started_at)
            VALUES (?, 'review', 'running', NOW())
        ");
        $stmt->execute([$params['id']]);
        $stepId = $db->lastInsertId();

        try {
            // Quick security scan first
            $quickScan = Reviewer::quickSecurityScan($run['diff_content']);

            // Check blocked paths
            $rules = RulesService::loadRules('coder');
            $blockedPaths = $rules['restrictions']['blocked_paths'] ?? [];
            $pathCheck = Reviewer::checkBlockedPaths($run['diff_content'], $blockedPaths);

            $aiModel = 'quick_scan';
            $tokensUsed = 0;

            // If quick scan or path check fails, don't bother with AI review
            if (!$quickScan['passed'] || !$pathCheck['passed']) {
                $violations = array_map(function($v) {
                    return [
                        'severity' => 'critical',
                        'file' => $v['file'],
                        'message' => 'Blocked path violation: ' . $v['blocked_pattern']
                    ];
                }, $pathCheck['violations']);

                $review = [
                    'approved' => false,
                    'safe_to_apply' => false,
                    'risk_level' => 'critical',
                    'issues' => array_merge($quickScan['issues'], $violations),
                    'summary' => 'Automatic security scan failed. Changes not safe to apply.'
                ];
            } else {
                // Full AI review
                $result = Reviewer::review($run['diff_content'], $plan, [
                    'base_path' => $repo['base_path'],
                    'label' => $repo['label']
                ]);
                $review = $result['review'];
                $aiModel = $result['model'];
                $tokensUsed = ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0);
            }

            // Update step
            $stmt = $db->prepare("
                UPDATE run_steps SET
                    status = 'completed',
                    output_json = ?,
                    model = ?,
                    tokens_used = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([json_encode($review), $aiModel, $tokensUsed, $stepId]);

            // Update run status based on review result
            $newStatus = $review['safe_to_apply'] ? 'ready' : 'failed';
            $stmt = $db->prepare("
                UPDATE runs SET
                    status = ?,
                    review_result = ?,
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $newStatus,
                json_encode($review),
                $review['safe_to_apply'] ? null : $review['summary'],
                $params['id']
            ]);

            $response = [
                'review' => $review,
                'quick_scan' => $quickScan,
                'path_check' => $pathCheck
            ];

            if ($review['safe_to_apply']) {
                $response['next_step'] = '/api/runs/' . $params['id'] . '/apply';
                $response['message'] = 'Changes approved. Ready to apply.';
            } else {
                $response['message'] = 'Changes rejected. See issues for details.';
            }

            Response::success($response);

        } catch (Exception $e) {
            $stmt = $db->prepare("
                UPDATE run_steps SET
                    status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $stepId]);

            $stmt = $db->prepare("
                UPDATE runs SET status = 'failed', error_message = ? WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $params['id']]);

            Response::error('Review failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/runs/{id}/apply
     * Apply approved changes to files
     */
    public function apply($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        $run = $this->getRunWithAccess($params['id'], $userId, $db);

        if ($run['status'] !== 'ready') {
            Response::error('Run must be in ready status to apply. Current: ' . $run['status'], 400);
        }

        if (!$run['diff_content']) {
            Response::error('No diff content to apply', 400);
        }

        $stmt = $db->prepare("SELECT * FROM repos WHERE id = ?");
        $stmt->execute([$run['repo_id']]);
        $repo = $stmt->fetch();

        if (!$repo) {
            Response::error('Repository not found', 404);
        }

        // ✅ These checks stay here as first line of defense
        if ($repo['read_only']) {
            Response::error('Repository is read-only', 403);
        }

        if ($repo['maintenance_lock']) {
            Response::error('Repository is locked for maintenance', 423);
        }

        // Update status to applying
        $stmt = $db->prepare("UPDATE runs SET status = 'applying' WHERE id = ?");
        $stmt->execute([$params['id']]);

        // Create apply step
        $stmt = $db->prepare("
            INSERT INTO run_steps (run_id, phase, status, started_at)
            VALUES (?, 'apply', 'running', NOW())
        ");
        $stmt->execute([$params['id']]);
        $stepId = $db->lastInsertId();

        try {
            // Create git checkpoint before applying
            $checkpoint = GitService::createCheckpoint(
                $repo['base_path'],
                'Before run #' . $params['id']
            );

            // Store checkpoint hash
            $stmt = $db->prepare("UPDATE runs SET git_checkpoint = ? WHERE id = ?");
            $stmt->execute([$checkpoint['hash'], $params['id']]);

            // ✅ SECTION 6: Pass repo_id and allowed_paths for defense in depth
            $result = FileApplier::apply($run['diff_content'], $repo['base_path'], [
                'repo_id' => $repo['id'],
                'allowed_paths' => json_decode($repo['allowed_paths_json'], true) ?? []
            ]);

            if (!$result['success']) {
                // Rollback on failure
                if ($checkpoint['created']) {
                    GitService::rollback($repo['base_path'], $checkpoint['hash']);
                }
                throw new Exception('Apply failed: ' . json_encode($result['errors']));
            }

            // Create post-apply checkpoint
            $postCheckpoint = GitService::createCheckpoint(
                $repo['base_path'],
                'After run #' . $params['id']
            );

            // Update step
            $stmt = $db->prepare("
                UPDATE run_steps SET
                    status = 'completed',
                    output_json = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([json_encode($result), $stepId]);

            // Update run to completed
            $stmt = $db->prepare("
                UPDATE runs SET
                    status = 'completed',
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$params['id']]);

            Response::success([
                'applied' => $result['applied'],
                'files_modified' => $result['successful'],
                'checkpoint_before' => $checkpoint['hash'],
                'checkpoint_after' => $postCheckpoint['hash'],
                'rollback_available' => true,
                'rollback_to' => $checkpoint['hash']
            ], 'Changes applied successfully');

        } catch (Exception $e) {
            $stmt = $db->prepare("
                UPDATE run_steps SET
                    status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $stepId]);

            $stmt = $db->prepare("
                UPDATE runs SET status = 'failed', error_message = ? WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $params['id']]);

            Response::error('Apply failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/runs/{id}/rollback
     * Rollback applied changes to checkpoint
     */
    public function rollback($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        $run = $this->getRunWithAccess($params['id'], $userId, $db);

        // Can rollback completed or failed (during apply) runs
        if (!in_array($run['status'], ['completed', 'failed', 'applying'])) {
            Response::error('Run cannot be rolled back in status: ' . $run['status'], 400);
        }

        if (!$run['git_checkpoint']) {
            Response::error('No checkpoint available for rollback', 400);
        }

        $stmt = $db->prepare("SELECT * FROM repos WHERE id = ?");
        $stmt->execute([$run['repo_id']]);
        $repo = $stmt->fetch();

        if (!$repo) {
            Response::error('Repository not found', 404);
        }

        if ($repo['maintenance_lock']) {
            Response::error('Repository is locked for maintenance', 423);
        }

        // Create rollback step
        $stmt = $db->prepare("
            INSERT INTO run_steps (run_id, phase, status, started_at)
            VALUES (?, 'rollback', 'running', NOW())
        ");
        $stmt->execute([$params['id']]);
        $stepId = $db->lastInsertId();

        try {
            // Get current HEAD before rollback
            $currentHead = GitService::getCurrentHead($repo['base_path']);

            // Perform rollback
            $result = GitService::rollback($repo['base_path'], $run['git_checkpoint']);

            // Update step
            $stmt = $db->prepare("
                UPDATE run_steps SET
                    status = 'completed',
                    output_json = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([json_encode($result), $stepId]);

            // Update run status
            $stmt = $db->prepare("
                UPDATE runs SET
                    status = 'rolled_back',
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$params['id']]);

            Response::success([
                'rolled_back' => true,
                'previous_head' => $currentHead,
                'restored_to' => $run['git_checkpoint'],
                'result' => $result
            ], 'Rollback successful');

        } catch (Exception $e) {
            $stmt = $db->prepare("
                UPDATE run_steps SET
                    status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $stepId]);

            Response::error('Rollback failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/runs/{id}/queue
     * Queue run for background processing
     */
    public function queue($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        require_once __DIR__ . '/../services/QueueService.php';

        $db = Bootstrap::getDB();

        $run = $this->getRunWithAccess($params['id'], $userId, $db);

        if ($run['status'] !== 'pending') {
            Response::error('Only pending runs can be queued', 400);
        }

        $autoApply = $input['auto_apply'] ?? false;

        // Queue the run
        $jobId = QueueService::queueRun($params['id'], $userId);

        Response::success([
            'queued' => true,
            'job_id' => $jobId,
            'message' => 'Run queued for background processing'
        ]);
    }

    /**
     * POST /api/runs/{id}/cancel
     * Cancel a run
     */
    public function cancel($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        $run = $this->getRunWithAccess($params['id'], $userId, $db);

        $cancelableStatuses = ['pending', 'planning', 'coding', 'reviewing', 'ready'];
        if (!in_array($run['status'], $cancelableStatuses)) {
            Response::error('Cannot cancel run in status: ' . $run['status'], 400);
        }

        $stmt = $db->prepare("
            UPDATE runs SET
                status = 'failed',
                error_message = 'Cancelled by user',
                completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$params['id']]);

        Response::success(null, 'Run cancelled');
    }

    /**
     * Get run with access check
     */
    private function getRunWithAccess($id, $userId, $db)
    {
        $stmt = $db->prepare("SELECT * FROM runs WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $run = $stmt->fetch();

        if (!$run) {
            Response::error('Run not found', 404);
        }

        return $run;
    }
}
