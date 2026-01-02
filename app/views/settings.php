<?php
if (!defined('CODERAI')) die('Direct access not allowed');

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/../config/env.php';

// Require admin
if (!Auth::check() || !Auth::isAdmin()) {
    header('Location: /login');
    exit;
}

$user = Auth::user();
$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ✅ Budget settings
        SettingsService::set('budget_monthly', $_POST['budget_monthly'] ?? '100', false);
        SettingsService::set('budget_warn_percent', $_POST['budget_warn_percent'] ?? '80', false);
        SettingsService::set('budget_hard_stop', isset($_POST['budget_hard_stop']) ? 'true' : 'false', false);
        
        // Budget block workspaces
        $blockWorkspaces = $_POST['budget_block_workspaces'] ?? ['normal'];
        SettingsService::set('budget_block_workspaces', $blockWorkspaces, false);
        
        // ✅ Context control
        SettingsService::set('max_context_messages', $_POST['max_context_messages'] ?? '20', false);
        
        // Safety settings
        SettingsService::set('safety_require_approval', isset($_POST['safety_require_approval']) ? 'true' : 'false', false);
        SettingsService::set('safety_auto_backup', isset($_POST['safety_auto_backup']) ? 'true' : 'false', false);
        SettingsService::set('maintenance_mode', isset($_POST['maintenance_mode']) ? 'true' : 'false', false);
        
        // Clear cache
        SettingsService::clearCache();
        
        $success = 'Settings saved successfully!';
    } catch (Exception $e) {
        $error = 'Failed to save settings: ' . $e->getMessage();
    }
}

// Get current settings
$currentSettings = SettingsService::getAll();

// Get budget block workspaces
$blockWorkspaces = SettingsService::get('budget_block_workspaces', ['normal']);
if (is_string($blockWorkspaces)) {
    $blockWorkspaces = json_decode($blockWorkspaces, true) ?? ['normal'];
}

$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - CoderAI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .settings-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .settings-header {
            margin-bottom: 32px;
        }
        .settings-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 8px;
        }
        .settings-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .settings-card h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .settings-card h4 {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 12px;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--glass-border);
        }
        .settings-card h4:first-of-type {
            margin-top: 0;
            padding-top: 0;
            border-top: none;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
        }
        .form-group select,
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            background: var(--black-secondary);
            border: 2px solid var(--glass-border);
            border-radius: 8px;
            color: var(--white);
            font-size: 14px;
            transition: border-color var(--transition-fast);
        }
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: var(--purple-primary);
        }
        .form-group small {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }
        .form-group small.success {
            color: var(--accent-green);
        }
        .form-group small.warning {
            color: var(--accent-gold);
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: var(--black-tertiary);
            border-radius: 8px;
            cursor: pointer;
            margin-bottom: 12px;
            border: 1px solid transparent;
            transition: all var(--transition-fast);
        }
        .checkbox-group:hover {
            border-color: var(--purple-primary);
        }
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--purple-primary);
        }
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            flex: 1;
            font-size: 14px;
            color: var(--text-secondary);
        }
        .checkbox-group.danger label {
            color: var(--accent-red);
        }
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert.success {
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid var(--accent-green);
            color: var(--accent-green);
        }
        .alert.error {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid var(--accent-red);
            color: var(--accent-red);
        }
        .workspace-checkboxes {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .workspace-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: var(--black-secondary);
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        .workspace-checkbox:hover {
            border-color: var(--purple-primary);
        }
        .workspace-checkbox input:checked + span {
            color: var(--purple-light);
        }
        .workspace-checkbox input {
            accent-color: var(--purple-primary);
        }
        .nav-links {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .nav-links a {
            padding: 8px 16px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            transition: all var(--transition-fast);
        }
        .nav-links a:hover {
            border-color: var(--purple-primary);
            color: var(--purple-light);
        }
        .info-box {
            padding: 12px 16px;
            background: var(--black-tertiary);
            border-radius: 8px;
            border-left: 4px solid var(--accent-blue);
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 16px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-badge.success {
            background: rgba(16, 185, 129, 0.2);
            color: var(--accent-green);
        }
        .status-badge.warning {
            background: rgba(245, 158, 11, 0.2);
            color: var(--accent-gold);
        }
        .status-badge.error {
            background: rgba(239, 68, 68, 0.2);
            color: var(--accent-red);
        }
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .workspace-checkboxes {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="settings-container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
            <div class="settings-header" style="margin-bottom: 0;">
                <h1⚙️ System Settings</h1>
                <p style="color: var(--text-muted);">Configure AI gateway, budget limits, and safety options</p>
            </div>
            <a href="/dashboard" class="btn btn-secondary">← Dashboard</a>
        </div>

        <!-- Quick Nav -->
        <div class="nav-links">
            <a href="/usage">📊 Usage Dashboard</a>
            <a href="/repos">📁 Repositories</a>
            <a href="/coder">💻 Coder</a>
        </div>

        <?php if ($success): ?>
        <div class="alert success">✓ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert error">✗ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- GATEWAY STATUS (Read-only) -->
        <div class="settings-card">
            <h3>🌐 AI Gateway Status</h3>
            
            <div class="form-group">
                <label>Gateway URL</label>
                <input type="text" value="<?= htmlspecialchars(Env::get('AI_GATEWAY_URL') ?: 'Not configured') ?>" disabled style="background: var(--black-tertiary); color: var(--text-muted);">
                <small>Configure in .env file: AI_GATEWAY_URL</small>
            </div>
            
            <div class="form-group">
                <label>Gateway Key Status</label>
                <?php if (!empty(Env::get('AI_GATEWAY_KEY'))): ?>
                    <div class="status-badge success">✓ Key is configured</div>
                <?php else: ?>
                    <div class="status-badge error">✗ Key not set</div>
                <?php endif; ?>
                <small>Configure in .env file: AI_GATEWAY_KEY</small>
            </div>
            
            <div class="form-group">
                <label>Available Models</label>
                <div style="padding: 12px; background: var(--black-tertiary); border-radius: 8px; font-family: monospace; font-size: 13px;">
                    <div style="color: var(--accent-green); margin-bottom: 4px;">⚡ qwen2.5-coder:7b (Fast)</div>
                    <div style="color: var(--accent-blue);">🎯 qwen2.5-coder:14b (Precise)</div>
                </div>
                <small>Self-hosted models via Ollama Gateway - no API costs</small>
            </div>
            
            <div class="form-group">
                <label>Timeout</label>
                <input type="text" value="<?= htmlspecialchars(Env::get('AI_TIMEOUT') ?: '120') ?> seconds" disabled style="background: var(--black-tertiary); color: var(--text-muted);">
                <small>Configure in .env file: AI_TIMEOUT</small>
            </div>
            
            <div class="form-group">
                <label>Temperature Settings</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <input type="text" value="Fast: <?= htmlspecialchars(Env::get('AI_TEMP_FAST') ?: '0.2') ?>" disabled style="background: var(--black-tertiary); color: var(--text-muted); width: 100%;">
                        <small style="display: block; margin-top: 4px;">AI_TEMP_FAST in .env</small>
                    </div>
                    <div>
                        <input type="text" value="Precise: <?= htmlspecialchars(Env::get('AI_TEMP_PRECISE') ?: '0.1') ?>" disabled style="background: var(--black-tertiary); color: var(--text-muted); width: 100%;">
                        <small style="display: block; margin-top: 4px;">AI_TEMP_PRECISE in .env</small>
                    </div>
                </div>
            </div>

            <div class="info-box">
                ℹ️ <strong>Note:</strong> All AI gateway settings are configured in the <code>.env</code> file and cannot be changed from the UI for security reasons.
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <!-- WORKSPACE MODEL RULES (Read-only info) -->
            <div class="settings-card">
                <h3>🎯 Workspace Model Rules</h3>
                
                <div style="padding: 16px; background: var(--black-tertiary); border-radius: 8px; margin-bottom: 16px;">
                    <h4 style="margin: 0 0 12px 0; padding: 0; border: none; color: var(--white);">💬 Normal Workspace</h4>
                    <div style="display: flex; align-items: center; gap: 12px; padding: 10px; background: var(--black-secondary); border-radius: 6px;">
                        <span style="color: var(--accent-green);">⚡ Fast (7B)</span>
                        <span style="color: var(--text-muted); font-size: 12px;">— Always uses fast model, no user toggle</span>
                    </div>
                </div>
                
                <div style="padding: 16px; background: var(--black-tertiary); border-radius: 8px; margin-bottom: 16px;">
                    <h4 style="margin: 0 0 12px 0; padding: 0; border: none; color: var(--white);">⛪ Church Workspace</h4>
                    <div style="display: flex; align-items: center; gap: 12px; padding: 10px; background: var(--black-secondary); border-radius: 6px;">
                        <span style="color: var(--accent-green);">⚡ Fast (7B)</span>
                        <span style="color: var(--text-muted); font-size: 12px;">— Always uses fast model, no user toggle</span>
                    </div>
                </div>
                
                <div style="padding: 16px; background: var(--black-tertiary); border-radius: 8px;">
                    <h4 style="margin: 0 0 12px 0; padding: 0; border: none; color: var(--white);">💻 Coder Workspace</h4>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; align-items: center; gap: 12px; padding: 10px; background: var(--black-secondary); border-radius: 6px;">
                            <span style="color: var(--accent-green);">⚡ Fast (7B)</span>
                            <span style="color: var(--text-muted); font-size: 12px;">— Default mode</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 12px; padding: 10px; background: var(--black-secondary); border-radius: 6px;">
                            <span style="color: var(--accent-blue);">🎯 Precise (14B)</span>
                            <span style="color: var(--text-muted); font-size: 12px;">— User can select via UI toggle</span>
                        </div>
                    </div>
                </div>
                
                <div class="info-box">
                    ℹ️ <strong>Note:</strong> Model routing is hardcoded per workspace. Normal and Church always use Fast (7B). Only Coder workspace allows user selection between Fast and Precise modes.
                </div>
            </div>

            <!-- CONTEXT CONTROL -->
            <div class="settings-card">
                <h3>📝 Context Control</h3>
                <div class="form-group">
                    <label>Maximum Messages in Context</label>
                    <input type="number" name="max_context_messages" 
                           value="<?= htmlspecialchars(SettingsService::get('max_context_messages', '20')) ?>" 
                           min="5" max="100" step="1">
                    <small>Limits how many previous messages are sent to AI. Lower = less memory usage. Recommended: 15-25</small>
                </div>
            </div>

            <!-- BUDGET CONTROL -->
            <div class="settings-card">
                <h3>💰 Budget Control</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Monthly Budget (USD)</label>
                        <input type="number" name="budget_monthly" 
                               value="<?= htmlspecialchars(SettingsService::get('budget_monthly', '100')) ?>" 
                               min="0" step="0.01">
                        <small>Set to 0 for unlimited (not recommended). Note: Self-hosted models have no API cost, but you can still set limits for tracking.</small>
                    </div>
                    <div class="form-group">
                        <label>Warning Threshold (%)</label>
                        <input type="number" name="budget_warn_percent" 
                               value="<?= htmlspecialchars(SettingsService::get('budget_warn_percent', '80')) ?>" 
                               min="50" max="99">
                        <small>Show warning banner when this % is reached</small>
                    </div>
                </div>
                
                <div class="checkbox-group" style="margin-top: 16px;">
                    <input type="checkbox" name="budget_hard_stop" id="budget_hard_stop" 
                           <?= SettingsService::get('budget_hard_stop', 'true') === 'true' ? 'checked' : '' ?>>
                    <label for="budget_hard_stop">Enable Hard Stop (block AI requests when budget exceeded)</label>
                </div>

                <div class="form-group" style="margin-top: 16px;">
                    <label>Workspaces to Block When Over Budget</label>
                    <div class="workspace-checkboxes">
                        <label class="workspace-checkbox">
                            <input type="checkbox" name="budget_block_workspaces[]" value="normal" 
                                   <?= in_array('normal', $blockWorkspaces) ? 'checked' : '' ?>>
                            <span>💬 Normal</span>
                        </label>
                        <label class="workspace-checkbox">
                            <input type="checkbox" name="budget_block_workspaces[]" value="church" 
                                   <?= in_array('church', $blockWorkspaces) ? 'checked' : '' ?>>
                            <span>⛪ Church</span>
                        </label>
                        <label class="workspace-checkbox">
                            <input type="checkbox" name="budget_block_workspaces[]" value="coder" 
                                   <?= in_array('coder', $blockWorkspaces) ? 'checked' : '' ?>>
                            <span>💻 Coder</span>
                        </label>
                    </div>
                    <small>Unchecked workspaces can continue even when over budget. Admins always bypass.</small>
                </div>
            </div>

            <!-- SAFETY -->
            <div class="settings-card">
                <h3>🛡️ Safety & Security</h3>
                <div class="checkbox-group">
                    <input type="checkbox" name="safety_require_approval" id="safety_require_approval" 
                           <?= SettingsService::get('safety_require_approval', 'true') === 'true' ? 'checked' : '' ?>>
                    <label for="safety_require_approval">Require manual approval before applying code changes</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="safety_auto_backup" id="safety_auto_backup" 
                           <?= SettingsService::get('safety_auto_backup', 'true') === 'true' ? 'checked' : '' ?>>
                    <label for="safety_auto_backup">Auto backup files before modifications (recommended)</label>
                </div>
                <div class="checkbox-group danger">
                    <input type="checkbox" name="maintenance_mode" id="maintenance_mode" 
                           <?= SettingsService::get('maintenance_mode', 'false') === 'true' ? 'checked' : '' ?>>
                    <label for="maintenance_mode">🚨 Maintenance Mode (disables ALL AI features for everyone)</label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px;">
                💾 Save All Settings
            </button>
        </form>

        <!-- Current Usage Summary -->
        <div class="settings-card" style="margin-top: 24px;">
            <h3>📊 Quick Stats</h3>
            <div id="quick-stats" style="color: var(--text-muted);">
                Loading usage data...
            </div>
        </div>
    </div>

    <script src="/assets/js/api.js"></script>
    <script>
        // Load quick stats
        document.addEventListener('DOMContentLoaded', async () => {
            try {
                const response = await API.get('/usage/stats');
                const data = response.data;
                
                document.getElementById('quick-stats').innerHTML = `
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-top: 12px;">
                        <div>
                            <div style="font-size: 24px; font-weight: 700; color: var(--white);">$${data.today.cost.toFixed(2)}</div>
                            <div style="font-size: 12px;">Today</div>
                        </div>
                        <div>
                            <div style="font-size: 24px; font-weight: 700; color: var(--accent-green);">$${data.month.cost.toFixed(2)}</div>
                            <div style="font-size: 12px;">This Month</div>
                        </div>
                        <div>
                            <div style="font-size: 24px; font-weight: 700; color: ${data.budget.percent_used > 80 ? 'var(--accent-gold)' : 'var(--white)'};">
                                ${data.budget.percent_used.toFixed(0)}%
                            </div>
                            <div style="font-size: 12px;">Budget Used</div>
                        </div>
                        <div>
                            <div style="font-size: 24px; font-weight: 700; color: var(--purple-light);">${formatNumber(data.month.tokens)}</div>
                            <div style="font-size: 12px;">Tokens (Month)</div>
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <a href="/usage" style="color: var(--purple-light);">View detailed usage →</a>
                    </div>
                `;
            } catch (e) {
                document.getElementById('quick-stats').innerHTML = '<span style="color: var(--text-muted);">Could not load stats</span>';
            }
        });

        function formatNumber(num) {
            if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
            if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
            return num.toString();
        }
    </script>
</body>
</html>