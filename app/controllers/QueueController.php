<?php
/**
 * CoderAI Queue Controller
 * API endpoints for queue management
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../middleware/RequireAuth.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../services/QueueService.php';

class QueueController
{
    /**
     * GET /api/queue/stats
     * Get queue statistics (admin only)
     */
    public function stats($params, $input)
    {
        RequireAuth::handle();
        $user = Auth::user();

        if ($user['role'] !== 'admin') {
            Response::error('Admin access required', 403);
        }

        $stats = QueueService::stats();

        // Get recent cron logs
        $db = Bootstrap::getDB();
        $stmt = $db->query("
            SELECT * FROM cron_logs
            ORDER BY started_at DESC
            LIMIT 10
        ");
        $cronLogs = $stmt->fetchAll();

        Response::success([
            'queue' => $stats,
            'cron_logs' => $cronLogs
        ]);
    }
}
