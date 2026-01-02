<?php
/**
 * CoderAI Queue Service
 * Manages background job queue
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class QueueService
{
    // Job types
    const JOB_RUN_PLAN = 'run_plan';
    const JOB_RUN_CODE = 'run_code';
    const JOB_RUN_REVIEW = 'run_review';
    const JOB_RUN_APPLY = 'run_apply';
    const JOB_CLEANUP = 'cleanup';
    const JOB_BACKUP_CLEANUP = 'backup_cleanup';

    /**
     * Add a job to the queue
     */
    public static function push($type, $payload, $options = [])
    {
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("
            INSERT INTO jobs (type, payload, priority, max_attempts, run_id, user_id, scheduled_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $type,
            json_encode($payload),
            $options['priority'] ?? 5,
            $options['max_attempts'] ?? 3,
            $options['run_id'] ?? null,
            $options['user_id'] ?? null,
            $options['scheduled_at'] ?? date('Y-m-d H:i:s')
        ]);

        return $db->lastInsertId();
    }

    /**
     * Queue a complete run workflow
     */
    public static function queueRun($runId, $userId)
    {
        // Queue plan -> code -> review in sequence
        // Each job will queue the next one upon completion
        return self::push(self::JOB_RUN_PLAN, [
            'run_id' => $runId
        ], [
            'run_id' => $runId,
            'user_id' => $userId,
            'priority' => 3
        ]);
    }

    /**
     * Get next pending job
     */
    public static function pop()
    {
        $db = Bootstrap::getDB();

        // Start transaction to lock the job
        $db->beginTransaction();

        try {
            // Get highest priority pending job that's due
            $stmt = $db->prepare("
                SELECT * FROM jobs
                WHERE status = 'pending'
                  AND scheduled_at <= NOW()
                  AND attempts < max_attempts
                ORDER BY priority ASC, scheduled_at ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute();
            $job = $stmt->fetch();

            if (!$job) {
                $db->rollBack();
                return null;
            }

            // Mark as running
            $stmt = $db->prepare("
                UPDATE jobs SET
                    status = 'running',
                    attempts = attempts + 1,
                    started_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$job['id']]);

            $db->commit();

            $job['payload'] = json_decode($job['payload'], true);
            return $job;

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark job as completed
     */
    public static function complete($jobId, $result = null)
    {
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("
            UPDATE jobs SET
                status = 'completed',
                completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);
    }

    /**
     * Mark job as failed
     */
    public static function fail($jobId, $error)
    {
        $db = Bootstrap::getDB();

        // Check if we should retry
        $stmt = $db->prepare("SELECT attempts, max_attempts FROM jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if ($job && $job['attempts'] < $job['max_attempts']) {
            // Schedule retry with exponential backoff
            $delay = pow(2, $job['attempts']) * 60; // 2, 4, 8 minutes
            $stmt = $db->prepare("
                UPDATE jobs SET
                    status = 'pending',
                    error_message = ?,
                    scheduled_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                WHERE id = ?
            ");
            $stmt->execute([$error, $delay, $jobId]);
        } else {
            // Max attempts reached
            $stmt = $db->prepare("
                UPDATE jobs SET
                    status = 'failed',
                    error_message = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$error, $jobId]);
        }
    }

    /**
     * Get pending job count
     */
    public static function pendingCount()
    {
        $db = Bootstrap::getDB();
        $stmt = $db->query("SELECT COUNT(*) FROM jobs WHERE status = 'pending'");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get running job count
     */
    public static function runningCount()
    {
        $db = Bootstrap::getDB();
        $stmt = $db->query("SELECT COUNT(*) FROM jobs WHERE status = 'running'");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Clean up old completed jobs
     */
    public static function cleanup($daysOld = 7)
    {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("
            DELETE FROM jobs
            WHERE status IN ('completed', 'failed')
              AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysOld]);
        return $stmt->rowCount();
    }

    /**
     * Reset stuck jobs (running for too long)
     */
    public static function resetStuck($minutesOld = 30)
    {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("
            UPDATE jobs SET
                status = 'pending',
                error_message = 'Job timed out and was reset'
            WHERE status = 'running'
              AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$minutesOld]);
        return $stmt->rowCount();
    }

    /**
     * Get queue stats
     */
    public static function stats()
    {
        $db = Bootstrap::getDB();

        $stmt = $db->query("
            SELECT
                status,
                COUNT(*) as count
            FROM jobs
            GROUP BY status
        ");
        $byStatus = [];
        while ($row = $stmt->fetch()) {
            $byStatus[$row['status']] = (int)$row['count'];
        }

        $stmt = $db->query("
            SELECT
                type,
                COUNT(*) as count
            FROM jobs
            WHERE status = 'pending'
            GROUP BY type
        ");
        $pendingByType = [];
        while ($row = $stmt->fetch()) {
            $pendingByType[$row['type']] = (int)$row['count'];
        }

        return [
            'by_status' => $byStatus,
            'pending_by_type' => $pendingByType,
            'total_pending' => $byStatus['pending'] ?? 0,
            'total_running' => $byStatus['running'] ?? 0
        ];
    }
}
