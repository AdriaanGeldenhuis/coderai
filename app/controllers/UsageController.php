<?php
/**
 * CoderAI Usage Controller
 * API endpoints for usage statistics and cost tracking
 * ✅ Section 8: Usage Dashboard
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../middleware/RequireAuth.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../services/SettingsService.php';

class UsageController
{
    /**
     * GET /api/usage/stats
     * Get overall usage statistics
     */
    public function stats($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();
        $isAdmin = Auth::isAdmin();

        $db = Bootstrap::getDB();

        // Get date range from query params
        $range = $_GET['range'] ?? 'month';
        
        // Build date filter
        $dateFilter = $this->getDateFilter($range);

        // For admins, show all usage. For users, show only their usage.
        $userFilter = $isAdmin ? '' : 'AND user_id = ?';
        $userParams = $isAdmin ? [] : [$userId];

        // Today's stats
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as requests,
                COALESCE(SUM(total_tokens), 0) as tokens,
                COALESCE(SUM(cost_usd), 0) as cost
            FROM ai_usage 
            WHERE DATE(created_at) = CURDATE() {$userFilter}
        ");
        $stmt->execute($userParams);
        $today = $stmt->fetch();

        // This month's stats
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as requests,
                COALESCE(SUM(total_tokens), 0) as tokens,
                COALESCE(SUM(cost_usd), 0) as cost
            FROM ai_usage 
            WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') {$userFilter}
        ");
        $stmt->execute($userParams);
        $month = $stmt->fetch();

        // All time stats
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as requests,
                COALESCE(SUM(total_tokens), 0) as tokens,
                COALESCE(SUM(cost_usd), 0) as cost
            FROM ai_usage 
            WHERE 1=1 {$userFilter}
        ");
        $stmt->execute($userParams);
        $allTime = $stmt->fetch();

        // Budget info
        $monthlyBudget = (float) SettingsService::get('budget_monthly', 100);
        $budgetUsedPercent = $monthlyBudget > 0 ? ($month['cost'] / $monthlyBudget) * 100 : 0;

        Response::success([
            'today' => [
                'requests' => (int) $today['requests'],
                'tokens' => (int) $today['tokens'],
                'cost' => round((float) $today['cost'], 4)
            ],
            'month' => [
                'requests' => (int) $month['requests'],
                'tokens' => (int) $month['tokens'],
                'cost' => round((float) $month['cost'], 4)
            ],
            'all_time' => [
                'requests' => (int) $allTime['requests'],
                'tokens' => (int) $allTime['tokens'],
                'cost' => round((float) $allTime['cost'], 4)
            ],
            'budget' => [
                'monthly_limit' => $monthlyBudget,
                'used' => round((float) $month['cost'], 4),
                'remaining' => round(max(0, $monthlyBudget - $month['cost']), 4),
                'percent_used' => round($budgetUsedPercent, 1)
            ]
        ]);
    }

    /**
     * GET /api/usage/daily
     * Get daily usage for chart
     */
    public function daily($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();
        $isAdmin = Auth::isAdmin();

        $db = Bootstrap::getDB();

        $days = (int) ($_GET['days'] ?? 30);
        $days = min(90, max(7, $days)); // Between 7 and 90

        $userFilter = $isAdmin ? '' : 'AND user_id = ?';
        $userParams = $isAdmin ? [$days] : [$userId, $days];

        $stmt = $db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as requests,
                COALESCE(SUM(total_tokens), 0) as tokens,
                COALESCE(SUM(cost_usd), 0) as cost
            FROM ai_usage 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) {$userFilter}
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute($userParams);
        $data = $stmt->fetchAll();

        // Fill in missing days with zeros
        $filled = [];
        $startDate = new DateTime("-{$days} days");
        $endDate = new DateTime();
        
        $dataByDate = [];
        foreach ($data as $row) {
            $dataByDate[$row['date']] = $row;
        }

        while ($startDate <= $endDate) {
            $dateStr = $startDate->format('Y-m-d');
            if (isset($dataByDate[$dateStr])) {
                $filled[] = [
                    'date' => $dateStr,
                    'requests' => (int) $dataByDate[$dateStr]['requests'],
                    'tokens' => (int) $dataByDate[$dateStr]['tokens'],
                    'cost' => round((float) $dataByDate[$dateStr]['cost'], 4)
                ];
            } else {
                $filled[] = [
                    'date' => $dateStr,
                    'requests' => 0,
                    'tokens' => 0,
                    'cost' => 0
                ];
            }
            $startDate->modify('+1 day');
        }

        Response::success($filled);
    }

    /**
     * GET /api/usage/by-model
     * Get usage breakdown by model
     */
    public function byModel($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();
        $isAdmin = Auth::isAdmin();

        $db = Bootstrap::getDB();

        $range = $_GET['range'] ?? 'month';
        $dateFilter = $this->getDateFilter($range);
        
        $userFilter = $isAdmin ? '' : 'AND user_id = ?';
        $userParams = $isAdmin ? [] : [$userId];

        $stmt = $db->prepare("
            SELECT 
                model,
                provider,
                COUNT(*) as requests,
                COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                COALESCE(SUM(total_tokens), 0) as total_tokens,
                COALESCE(SUM(cost_usd), 0) as cost
            FROM ai_usage 
            WHERE {$dateFilter} {$userFilter}
            GROUP BY model, provider
            ORDER BY cost DESC
        ");
        $stmt->execute($userParams);
        $data = $stmt->fetchAll();

        $formatted = array_map(function($row) {
            return [
                'model' => $row['model'],
                'provider' => $row['provider'],
                'requests' => (int) $row['requests'],
                'prompt_tokens' => (int) $row['prompt_tokens'],
                'completion_tokens' => (int) $row['completion_tokens'],
                'total_tokens' => (int) $row['total_tokens'],
                'cost' => round((float) $row['cost'], 4)
            ];
        }, $data);

        Response::success($formatted);
    }

    /**
     * GET /api/usage/by-workspace
     * Get usage breakdown by workspace
     */
    public function byWorkspace($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();
        $isAdmin = Auth::isAdmin();

        $db = Bootstrap::getDB();

        $range = $_GET['range'] ?? 'month';
        $dateFilter = $this->getDateFilter($range);
        
        $userFilter = $isAdmin ? '' : 'AND user_id = ?';
        $userParams = $isAdmin ? [] : [$userId];

        $stmt = $db->prepare("
            SELECT 
                COALESCE(workspace_slug, 'unknown') as workspace,
                COUNT(*) as requests,
                COALESCE(SUM(total_tokens), 0) as tokens,
                COALESCE(SUM(cost_usd), 0) as cost
            FROM ai_usage 
            WHERE {$dateFilter} {$userFilter}
            GROUP BY workspace_slug
            ORDER BY cost DESC
        ");
        $stmt->execute($userParams);
        $data = $stmt->fetchAll();

        $formatted = array_map(function($row) {
            return [
                'workspace' => $row['workspace'],
                'requests' => (int) $row['requests'],
                'tokens' => (int) $row['tokens'],
                'cost' => round((float) $row['cost'], 4)
            ];
        }, $data);

        Response::success($formatted);
    }

    /**
     * Helper: Get date filter SQL
     */
    private function getDateFilter($range)
    {
        return match($range) {
            'today' => 'DATE(created_at) = CURDATE()',
            'week' => 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month' => 'created_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\')',
            'year' => 'created_at >= DATE_FORMAT(NOW(), \'%Y-01-01\')',
            default => 'created_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\')'
        };
    }
}