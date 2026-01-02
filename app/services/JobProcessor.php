<?php
/**
 * CoderAI Job Processor
 * Processes background jobs from the queue
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/QueueService.php';
require_once __DIR__ . '/coder/Planner.php';
require_once __DIR__ . '/coder/Coder.php';
require_once __DIR__ . '/coder/Reviewer.php';
require_once __DIR__ . '/coder/GitService.php';
require_once __DIR__ . '/coder/FileApplier.php';

class JobProcessor
{
    private $maxExecutionTime;
    private $startTime;
    private $jobsProcessed = 0;
    private $jobsFailed = 0;

    public function __construct($maxExecutionTime = 55)
    {
        $this->maxExecutionTime = $maxExecutionTime;
        $this->startTime = time();
    }

    /**
     * Process jobs until time limit
     */
    public function run()
    {
        // Reset any stuck jobs first
        QueueService::resetStuck(30);

        while ($this->hasTimeRemaining()) {
            $job = QueueService::pop();

            if (!$job) {
                // No more jobs, exit
                break;
            }

            try {
                $this->processJob($job);
                QueueService::complete($job['id']);
                $this->jobsProcessed++;
            } catch (Exception $e) {
                QueueService::fail($job['id'], $e->getMessage());
                $this->jobsFailed++;
                error_log("Job {$job['id']} failed: " . $e->getMessage());
            }
        }

        return [
            'processed' => $this->jobsProcessed,
            'failed' => $this->jobsFailed,
            'duration' => time() - $this->startTime
        ];
    }

    /**
     * Check if we have time remaining
     */
    private function hasTimeRemaining()
    {
        return (time() - $this->startTime) < $this->maxExecutionTime;
    }

    /**
     * Process a single job
     */
    private function processJob($job)
    {
        switch ($job['type']) {
            case QueueService::JOB_RUN_PLAN:
                $this->processRunPlan($job);
                break;

            case QueueService::JOB_RUN_CODE:
                $this->processRunCode($job);
                break;

            case QueueService::JOB_RUN_REVIEW:
                $this->processRunReview($job);
                break;

            case QueueService::JOB_RUN_APPLY:
                $this->processRunApply($job);
                break;

            case QueueService::JOB_CLEANUP:
                $this->processCleanup($job);
                break;

            case QueueService::JOB_BACKUP_CLEANUP:
                $this->processBackupCleanup($job);
                break;

            default:
                throw new Exception("Unknown job type: {$job['type']}");
        }
    }

    /**
     * Process run planning phase
     */
    private function processRunPlan($job)
    {
        $runId = $job['payload']['run_id'];
        $db = Bootstrap::getDB();

        // Get run and repo
        $stmt = $db->prepare("
            SELECT r.*, repo.base_path, repo.label
            FROM runs r
            JOIN repos repo ON r.repo_id = repo.id
            WHERE r.id = ?
        ");
        $stmt->execute([$runId]);
        $run = $stmt->fetch();

        if (!$run || $run['status'] !== 'pending') {
            return; // Already processed or cancelled
        }

        // Update status
        $db->prepare("UPDATE runs SET status = 'planning' WHERE id = ?")->execute([$runId]);

        // Create step
        $db->prepare("
            INSERT INTO run_steps (run_id, phase, status, started_at)
            VALUES (?, 'plan', 'running', NOW())
        ")->execute([$runId]);
        $stepId = $db->lastInsertId();

        // Execute planning
        $result = Planner::plan($run['request'], [
            'base_path' => $run['base_path'],
            'label' => $run['label']
        ]);

        $tokensUsed = ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0);

        // Update step
        $db->prepare("
            UPDATE run_steps SET
                status = 'completed',
                output_json = ?,
                model = ?,
                tokens_used = ?,
                completed_at = NOW()
            WHERE id = ?
        ")->execute([json_encode($result['plan']), $result['model'], $tokensUsed, $stepId]);

        // Update run
        $db->prepare("UPDATE runs SET plan_json = ? WHERE id = ?")->execute([
            json_encode($result['plan']),
            $runId
        ]);

        // Queue next phase
        QueueService::push(QueueService::JOB_RUN_CODE, [
            'run_id' => $runId
        ], [
            'run_id' => $runId,
            'user_id' => $run['user_id'],
            'priority' => 3
        ]);
    }

    /**
     * Process run coding phase
     */
    private function processRunCode($job)
    {
        $runId = $job['payload']['run_id'];
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("
            SELECT r.*, repo.base_path, repo.label
            FROM runs r
            JOIN repos repo ON r.repo_id = repo.id
            WHERE r.id = ?
        ");
        $stmt->execute([$runId]);
        $run = $stmt->fetch();

        if (!$run || $run['status'] !== 'planning' || !$run['plan_json']) {
            return;
        }

        $plan = json_decode($run['plan_json'], true);

        // Update status
        $db->prepare("UPDATE runs SET status = 'coding' WHERE id = ?")->execute([$runId]);

        // Create step
        $db->prepare("
            INSERT INTO run_steps (run_id, phase, status, started_at)
            VALUES (?, 'code', 'running', NOW())
        ")->execute([$runId]);
        $stepId = $db->lastInsertId();

        // Read files for context
        $filesToRead = array_column($plan['files_to_modify'] ?? [], 'path');
        $fileContents = Planner::readFilesForContext([
            'base_path' => $run['base_path']
        ], $filesToRead);

        // Generate code
        $result = Coder::generateCode($plan, [
            'base_path' => $run['base_path'],
            'label' => $run['label']
        ], $fileContents);

        $tokensUsed = ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0);

        // Update step
        $db->prepare("
            UPDATE run_steps SET
                status = 'completed',
                output_json = ?,
                model = ?,
                tokens_used = ?,
                completed_at = NOW()
            WHERE id = ?
        ")->execute([json_encode(['diff' => $result['diff']]), $result['model'], $tokensUsed, $stepId]);

        // Update run
        $db->prepare("UPDATE runs SET diff_content = ? WHERE id = ?")->execute([
            $result['diff'],
            $runId
        ]);

        // Queue next phase
        QueueService::push(QueueService::JOB_RUN_REVIEW, [
            'run_id' => $runId
        ], [
            'run_id' => $runId,
            'user_id' => $run['user_id'],
            'priority' => 3
        ]);
    }

    /**
     * Process run review phase
     */
    private function processRunReview($job)
    {
        $runId = $job['payload']['run_id'];
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("
            SELECT r.*, repo.base_path, repo.label
            FROM runs r
            JOIN repos repo ON r.repo_id = repo.id
            WHERE r.id = ?
        ");
        $stmt->execute([$runId]);
        $run = $stmt->fetch();

        if (!$run || $run['status'] !== 'coding' || !$run['diff_content']) {
            return;
        }

        $plan = json_decode($run['plan_json'], true);

        // Update status
        $db->prepare("UPDATE runs SET status = 'reviewing' WHERE id = ?")->execute([$runId]);

        // Create step
        $db->prepare("
            INSERT INTO run_steps (run_id, phase, status, started_at)
            VALUES (?, 'review', 'running', NOW())
        ")->execute([$runId]);
        $stepId = $db->lastInsertId();

        // Quick security scan
        $quickScan = Reviewer::quickSecurityScan($run['diff_content']);

        // Check blocked paths
        require_once __DIR__ . '/RulesService.php';
        $rules = RulesService::loadRules('coder');
        $blockedPaths = $rules['restrictions']['blocked_paths'] ?? [];
        $pathCheck = Reviewer::checkBlockedPaths($run['diff_content'], $blockedPaths);

        $aiModel = 'quick_scan';
        $tokensUsed = 0;

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
                'summary' => 'Automatic security scan failed.'
            ];
        } else {
            $result = Reviewer::review($run['diff_content'], $plan, [
                'base_path' => $run['base_path'],
                'label' => $run['label']
            ]);
            $review = $result['review'];
            $aiModel = $result['model'];
            $tokensUsed = ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0);
        }

        // Update step
        $db->prepare("
            UPDATE run_steps SET
                status = 'completed',
                output_json = ?,
                model = ?,
                tokens_used = ?,
                completed_at = NOW()
            WHERE id = ?
        ")->execute([json_encode($review), $aiModel, $tokensUsed, $stepId]);

        // Update run
        $newStatus = $review['safe_to_apply'] ? 'ready' : 'failed';
        $db->prepare("
            UPDATE runs SET
                status = ?,
                review_result = ?,
                error_message = ?
            WHERE id = ?
        ")->execute([
            $newStatus,
            json_encode($review),
            $review['safe_to_apply'] ? null : $review['summary'],
            $runId
        ]);

        // If auto-apply is enabled and review passed, queue apply
        $autoApply = $job['payload']['auto_apply'] ?? false;
        if ($autoApply && $review['safe_to_apply']) {
            QueueService::push(QueueService::JOB_RUN_APPLY, [
                'run_id' => $runId
            ], [
                'run_id' => $runId,
                'user_id' => $run['user_id'],
                'priority' => 3
            ]);
        }
    }

    /**
     * Process run apply phase
     */
    private function processRunApply($job)
    {
        $runId = $job['payload']['run_id'];
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("
            SELECT r.*, repo.base_path, repo.label, repo.read_only, repo.maintenance_lock
            FROM runs r
            JOIN repos repo ON r.repo_id = repo.id
            WHERE r.id = ?
        ");
        $stmt->execute([$runId]);
        $run = $stmt->fetch();

        if (!$run || $run['status'] !== 'ready' || !$run['diff_content']) {
            return;
        }

        if ($run['read_only'] || $run['maintenance_lock']) {
            $db->prepare("UPDATE runs SET status = 'failed', error_message = ? WHERE id = ?")
                ->execute(['Repository is read-only or locked', $runId]);
            return;
        }

        // Update status
        $db->prepare("UPDATE runs SET status = 'applying' WHERE id = ?")->execute([$runId]);

        // Create step
        $db->prepare("
            INSERT INTO run_steps (run_id, phase, status, started_at)
            VALUES (?, 'apply', 'running', NOW())
        ")->execute([$runId]);
        $stepId = $db->lastInsertId();

        try {
            // Create checkpoint
            $checkpoint = GitService::createCheckpoint(
                $run['base_path'],
                'Before run #' . $runId
            );

            $db->prepare("UPDATE runs SET git_checkpoint = ? WHERE id = ?")
                ->execute([$checkpoint['hash'], $runId]);

            // Apply diff
            $result = FileApplier::apply($run['diff_content'], $run['base_path']);

            if (!$result['success']) {
                if ($checkpoint['created']) {
                    GitService::rollback($run['base_path'], $checkpoint['hash']);
                }
                throw new Exception('Apply failed: ' . json_encode($result['errors']));
            }

            // Post-apply checkpoint
            GitService::createCheckpoint($run['base_path'], 'After run #' . $runId);

            // Update step
            $db->prepare("
                UPDATE run_steps SET
                    status = 'completed',
                    output_json = ?,
                    completed_at = NOW()
                WHERE id = ?
            ")->execute([json_encode($result), $stepId]);

            // Update run
            $db->prepare("
                UPDATE runs SET status = 'completed', completed_at = NOW() WHERE id = ?
            ")->execute([$runId]);

        } catch (Exception $e) {
            $db->prepare("
                UPDATE run_steps SET status = 'failed', error_message = ?, completed_at = NOW() WHERE id = ?
            ")->execute([$e->getMessage(), $stepId]);

            $db->prepare("
                UPDATE runs SET status = 'failed', error_message = ? WHERE id = ?
            ")->execute([$e->getMessage(), $runId]);

            throw $e;
        }
    }

    /**
     * Process cleanup job
     */
    private function processCleanup($job)
    {
        $daysOld = $job['payload']['days_old'] ?? 7;
        QueueService::cleanup($daysOld);
    }

    /**
     * Process backup file cleanup
     */
    private function processBackupCleanup($job)
    {
        $db = Bootstrap::getDB();

        // Get all repos
        $stmt = $db->query("SELECT base_path FROM repos WHERE is_active = 1");
        $repos = $stmt->fetchAll();

        $hoursOld = $job['payload']['hours_old'] ?? 24;

        foreach ($repos as $repo) {
            FileApplier::cleanupBackups($repo['base_path'], $hoursOld);
        }
    }
}
