<?php
/**
 * CoderAI Messages Controller
 *
 * FIXED: Token-based context budgeting instead of message count
 * FIXED: Uses model preferences from rules
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../middleware/RequireAuth.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../services/ai/AIClient.php';
require_once __DIR__ . '/../services/ai/AutoRouter.php';
require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/../services/RulesService.php';

class MessagesController
{
    /**
     * Approximate tokens per character (conservative estimate)
     * English text averages ~4 chars per token, but code/mixed is ~3
     */
    const CHARS_PER_TOKEN = 3;

    /**
     * GET /api/messages
     * Get messages for a thread
     */
    public function index($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $threadId = $_GET['thread_id'] ?? null;

        if (!$threadId) {
            Response::error('thread_id required', 422);
        }

        $db = Bootstrap::getDB();

        // Verify thread ownership
        $stmt = $db->prepare("SELECT id FROM threads WHERE id = ? AND user_id = ?");
        $stmt->execute([$threadId, $userId]);
        if (!$stmt->fetch()) {
            Response::error('Thread not found', 404);
        }

        // Get messages
        $stmt = $db->prepare("
            SELECT id, role, content, tokens_used, model, created_at
            FROM messages
            WHERE thread_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$threadId]);
        $messages = $stmt->fetchAll();

        Response::success($messages);
    }

    /**
     * POST /api/messages
     * Send message and get AI response
     */
    public function store($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();
        $isAdmin = Auth::isAdmin();

        Response::validate($input, ['thread_id', 'content']);

        // Check maintenance mode
        if (SettingsService::isMaintenanceMode()) {
            Response::error('System is in maintenance mode', 503);
        }

        $db = Bootstrap::getDB();

        // Get thread with project info
        $stmt = $db->prepare("
            SELECT t.*, p.workspace_slug, p.rules_json as project_rules
            FROM threads t
            JOIN projects p ON t.project_id = p.id
            WHERE t.id = ? AND t.user_id = ?
        ");
        $stmt->execute([$input['thread_id'], $userId]);
        $thread = $stmt->fetch();

        if (!$thread) {
            Response::error('Thread not found', 404);
        }

        $workspaceSlug = $thread['workspace_slug'] ?? 'normal';
        $projectRules = json_decode($thread['project_rules'], true) ?? [];

        // IMPORTANT: Allow frontend to override workspace (for switching tabs)
        // This also fixes old projects with wrong workspace_slug
        if (!empty($input['workspace']) && in_array($input['workspace'], ['normal', 'church', 'coder'])) {
            $newWorkspace = $input['workspace'];

            // If workspace changed, update the project in database
            if ($newWorkspace !== $workspaceSlug) {
                $updateStmt = $db->prepare("
                    UPDATE projects SET workspace_slug = ?
                    WHERE id = (SELECT project_id FROM threads WHERE id = ?)
                ");
                $updateStmt->execute([$newWorkspace, $input['thread_id']]);
                error_log("[MessagesController] Updated project workspace: {$workspaceSlug} -> {$newWorkspace}");
            }

            $workspaceSlug = $newWorkspace;
        }

        // Clear rules cache to ensure fresh rules are loaded
        RulesService::clearCache();

        // Budget check
        $budgetCheck = $this->checkBudget($userId, $workspaceSlug, $isAdmin);

        if ($budgetCheck['blocked']) {
            Response::error($budgetCheck['message'], 429, [
                'budget_exceeded' => true,
                'percent_used' => $budgetCheck['percent_used'],
                'workspace_blocked' => $workspaceSlug
            ]);
        }

        // Save user message
        $stmt = $db->prepare("
            INSERT INTO messages (thread_id, role, content)
            VALUES (?, 'user', ?)
        ");
        $stmt->execute([$input['thread_id'], $input['content']]);
        $userMessageId = $db->lastInsertId();

        // Update thread timestamp
        $stmt = $db->prepare("UPDATE threads SET updated_at = NOW() WHERE id = ?");
        $stmt->execute([$input['thread_id']]);

        // Get model preferences from rules
        $modelPrefs = RulesService::getModelPreferences($workspaceSlug);
        $contextBudget = $modelPrefs['context_budget_tokens'] ?? 24000;
        $outputReserve = $modelPrefs['output_reserve_tokens'] ?? 4000;
        $maxTokens = $modelPrefs['max_tokens'] ?? 8192;

        // Build system prompt
        $systemPrompt = RulesService::buildSystemPrompt($workspaceSlug, $projectRules);
        $systemTokens = $this->estimateTokens($systemPrompt);

        // Calculate available budget for history
        $historyBudget = $contextBudget - $systemTokens - $outputReserve;

        // Get context messages with token budgeting
        $history = $this->getContextWithTokenBudget($db, $input['thread_id'], $historyBudget);

        // Prepare messages for AI
        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($history as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        // Model selection
        // Normal and Church workspaces: always use instruct model (forced by rules)
        // Coder workspace: user can select from dropdown
        $routerOptions = [];

        if ($workspaceSlug === 'coder' && !empty($input['model'])) {
            // User selected a specific model from dropdown
            $routerOptions['requested_model'] = $input['model'];
        }

        // Route to appropriate model based on workspace
        $routingResult = AutoRouter::route($workspaceSlug, $input['content'], $routerOptions);
        $model = $routingResult['model'];

        // Temperature from rules or routing result mode
        $routingMode = $routingResult['mode'] ?? 'fast';
        $temperature = ($routingMode === 'precise')
            ? ($modelPrefs['temperature'] ?? 0.1)
            : (($modelPrefs['temperature'] ?? 0.2) + 0.1);

        try {
            $response = AIClient::chat($messages, [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => min($maxTokens, $outputReserve)
            ]);

            // Save assistant message
            $stmt = $db->prepare("
                INSERT INTO messages (thread_id, role, content, tokens_used, model)
                VALUES (?, 'assistant', ?, ?, ?)
            ");
            $stmt->execute([
                $input['thread_id'],
                $response['content'],
                $response['usage']['total_tokens'],
                $response['model']
            ]);
            $assistantMessageId = $db->lastInsertId();

            // Track usage
            AIClient::trackUsage(
                $userId,
                $workspaceSlug,
                $response['model'],
                $response['provider'],
                $response['usage']['input_tokens'],
                $response['usage']['output_tokens']
            );

            // Auto-title for first message
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM messages WHERE thread_id = ?");
            $stmt->execute([$input['thread_id']]);
            $count = $stmt->fetch()['count'];

            if ($count <= 2 && ($thread['title'] === 'New Conversation' || empty($thread['title']) || $thread['title'] === 'New Chat')) {
                $newTitle = $this->generateTitle($input['content'], $model);
                $stmt = $db->prepare("UPDATE threads SET title = ? WHERE id = ?");
                $stmt->execute([$newTitle, $input['thread_id']]);
            }

            // Build response
            $responseData = [
                'user_message' => [
                    'id' => $userMessageId,
                    'role' => 'user',
                    'content' => $input['content']
                ],
                'assistant_message' => [
                    'id' => $assistantMessageId,
                    'role' => 'assistant',
                    'content' => $response['content'],
                    'model' => $response['model'],
                    'tokens' => $response['usage']['total_tokens']
                ],
                'routing' => [
                    'model_used' => $routingResult['model'],
                    'reason' => $routingResult['reason'],
                    'mode' => $routingResult['mode'],
                    'workspace' => $workspaceSlug
                ],
                'context' => [
                    'messages_in_context' => count($history),
                    'context_budget_tokens' => $contextBudget,
                    'history_budget_tokens' => $historyBudget,
                    'input_tokens' => $response['usage']['input_tokens'],
                    'output_tokens' => $response['usage']['output_tokens']
                ]
            ];

            // Budget status
            $updatedBudget = $this->checkBudget($userId, $workspaceSlug, $isAdmin);

            if ($updatedBudget['warning']) {
                $responseData['budget_warning'] = [
                    'message' => $updatedBudget['message'],
                    'percent_used' => $updatedBudget['percent_used'],
                    'remaining_usd' => $updatedBudget['remaining']
                ];
            }

            Response::success($responseData);

        } catch (Exception $e) {
            error_log('AI Error: ' . $e->getMessage());

            $errorMessage = 'AI service unavailable';

            if (strpos($e->getMessage(), 'offline') !== false ||
                strpos($e->getMessage(), 'unreachable') !== false) {
                $errorMessage = 'AI gateway is currently offline. Please try again later.';
            } elseif (strpos($e->getMessage(), 'timeout') !== false) {
                $errorMessage = 'AI request timed out. Please try again.';
            } elseif (strpos($e->getMessage(), 'authentication') !== false) {
                $errorMessage = 'AI gateway authentication failed. Please contact administrator.';
            }

            Response::error($errorMessage, 503, [
                'ai_error' => true,
                'can_retry' => true
            ]);
        }
    }

    /**
     * Get context messages within token budget
     *
     * Fetches messages from most recent, keeps adding until budget exhausted
     */
    private function getContextWithTokenBudget($db, $threadId, $tokenBudget)
    {
        // Get all messages (newest first)
        $stmt = $db->prepare("
            SELECT role, content
            FROM messages
            WHERE thread_id = ?
            ORDER BY id DESC
        ");
        $stmt->execute([$threadId]);
        $allMessages = $stmt->fetchAll();

        $selected = [];
        $usedTokens = 0;

        foreach ($allMessages as $msg) {
            $msgTokens = $this->estimateTokens($msg['content']);

            // Check if we can fit this message
            if ($usedTokens + $msgTokens > $tokenBudget) {
                break;
            }

            $selected[] = $msg;
            $usedTokens += $msgTokens;
        }

        // Reverse to get chronological order
        return array_reverse($selected);
    }

    /**
     * Estimate token count for text
     */
    private function estimateTokens($text)
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Generate a title for the conversation
     */
    private function generateTitle($content, $model)
    {
        try {
            $titlePrompt = [
                ['role' => 'system', 'content' => 'Generate a short, descriptive title (max 8 words) for this conversation. Return ONLY the title, no quotes or extra text.'],
                ['role' => 'user', 'content' => $content]
            ];

            $titleResponse = AIClient::chat($titlePrompt, [
                'model' => 'qwen2.5-coder:7b',
                'temperature' => 0.3,
                'max_tokens' => 50
            ]);

            $newTitle = trim($titleResponse['content']);
            $newTitle = str_replace(['"', "'"], '', $newTitle);
            $newTitle = substr($newTitle, 0, 50);

            return $newTitle;
        } catch (Exception $e) {
            // Fallback: First 8 words
            $words = explode(' ', $content);
            $newTitle = implode(' ', array_slice($words, 0, 8));
            if (count($words) > 8) $newTitle .= '...';
            return $newTitle;
        }
    }

    /**
     * Budget checking
     */
    private function checkBudget($userId, $workspaceSlug, $isAdmin)
    {
        if ($isAdmin) {
            return [
                'blocked' => false,
                'warning' => false,
                'message' => 'Admin bypass active',
                'percent_used' => 0,
                'remaining' => 999999
            ];
        }

        $monthlyBudget = (float) SettingsService::get('budget_monthly', 100);
        $warnPercent = (float) SettingsService::get('budget_warn_percent', 80);
        $hardStop = SettingsService::get('budget_hard_stop', 'true');
        $hardStop = ($hardStop === true || $hardStop === 'true' || $hardStop === '1');

        $blockWorkspaces = SettingsService::get('budget_block_workspaces', ['normal']);
        if (is_string($blockWorkspaces)) {
            $blockWorkspaces = json_decode($blockWorkspaces, true) ?? ['normal'];
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(cost_usd), 0) as total
            FROM ai_usage
            WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        $totalUsed = (float) ($result['total'] ?? 0);

        $percentUsed = $monthlyBudget > 0 ? ($totalUsed / $monthlyBudget) * 100 : 0;
        $remaining = max(0, $monthlyBudget - $totalUsed);
        $isOverBudget = $totalUsed >= $monthlyBudget;

        $shouldBlockThisWorkspace = in_array($workspaceSlug, $blockWorkspaces);

        $blocked = false;
        $message = '';

        if ($hardStop && $isOverBudget && $shouldBlockThisWorkspace) {
            $blocked = true;
            $message = "Monthly budget of \${$monthlyBudget} exceeded. {$workspaceSlug} workspace is blocked until next month.";
        } elseif ($hardStop && $isOverBudget && !$shouldBlockThisWorkspace) {
            $message = "Budget exceeded but {$workspaceSlug} workspace has bypass permission.";
        }

        $warning = false;
        if (!$blocked && $percentUsed >= $warnPercent) {
            $warning = true;
            if ($percentUsed >= 95) {
                $message = "Critical: {$percentUsed}% of monthly budget used. Only \${$remaining} remaining!";
            } elseif ($percentUsed >= 90) {
                $message = "Warning: {$percentUsed}% of budget used. \${$remaining} remaining.";
            } else {
                $message = "Budget notice: {$percentUsed}% used this month.";
            }
        }

        return [
            'blocked' => $blocked,
            'warning' => $warning,
            'message' => $message,
            'percent_used' => round($percentUsed, 1),
            'total_used' => round($totalUsed, 4),
            'remaining' => round($remaining, 4),
            'budget' => $monthlyBudget,
            'workspace_blocked' => $shouldBlockThisWorkspace,
            'hard_stop_enabled' => $hardStop
        ];
    }
}
