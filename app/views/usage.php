<?php
if (!defined('CODERAI')) die('Direct access not allowed');

require_once __DIR__ . '/../core/Auth.php';

// Require login
if (!Auth::check()) {
    header('Location: /login');
    exit;
}

$user = Auth::user();
$isAdmin = Auth::isAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usage Dashboard - CoderAI</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .usage-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .usage-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .usage-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--white);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 24px;
            text-align: center;
        }

        .stat-card.highlight {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(107, 33, 168, 0.2));
            border-color: var(--purple-primary);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 4px;
        }

        .stat-value.cost {
            color: var(--accent-green);
        }

        .stat-value.warning {
            color: var(--accent-gold);
        }

        .stat-value.danger {
            color: var(--accent-red);
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-muted);
        }

        .stat-sublabel {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
        }

        .chart-container {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 32px;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--white);
        }

        .chart-canvas {
            width: 100%;
            height: 300px;
            background: var(--black-tertiary);
            border-radius: 8px;
            display: flex;
            align-items: flex-end;
            padding: 20px;
            gap: 4px;
            overflow-x: auto;
        }

        .chart-bar {
            flex: 1;
            min-width: 20px;
            max-width: 40px;
            background: linear-gradient(to top, var(--purple-dark), var(--purple-primary));
            border-radius: 4px 4px 0 0;
            position: relative;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .chart-bar:hover {
            background: linear-gradient(to top, var(--purple-primary), var(--purple-light));
        }

        .chart-bar:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--black-tertiary);
            border: 1px solid var(--glass-border);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 10;
            color: var(--white);
        }

        .breakdown-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }

        .breakdown-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 24px;
        }

        .breakdown-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 16px;
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--glass-border);
        }

        .breakdown-item:last-child {
            border-bottom: none;
        }

        .breakdown-name {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .breakdown-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .breakdown-icon.openai { background: rgba(16, 163, 127, 0.2); }
        .breakdown-icon.anthropic { background: rgba(204, 153, 102, 0.2); }
        .breakdown-icon.normal { background: rgba(59, 130, 246, 0.2); }
        .breakdown-icon.church { background: rgba(168, 85, 247, 0.2); }
        .breakdown-icon.coder { background: rgba(16, 185, 129, 0.2); }

        .breakdown-details {
            text-align: right;
        }

        .breakdown-cost {
            font-size: 16px;
            font-weight: 600;
            color: var(--white);
        }

        .breakdown-meta {
            font-size: 12px;
            color: var(--text-muted);
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--black-tertiary);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-green), var(--purple-primary));
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .progress-fill.warning {
            background: linear-gradient(90deg, var(--accent-gold), var(--accent-red));
        }

        .range-selector {
            display: flex;
            gap: 8px;
        }

        .range-btn {
            padding: 6px 12px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            color: var(--text-muted);
            font-size: 13px;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .range-btn:hover {
            border-color: var(--purple-primary);
            color: var(--purple-light);
        }

        .range-btn.active {
            background: var(--purple-primary);
            border-color: var(--purple-primary);
            color: var(--white);
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .breakdown-grid {
                grid-template-columns: 1fr;
            }
            .chart-canvas {
                height: 200px;
            }
        }
    </style>
</head>
<body>
    <div class="usage-container">
        <div class="usage-header">
            <div>
                <h1>📊 Usage Dashboard</h1>
                <p style="color: var(--text-muted); margin-top: 8px;">
                    Track your AI usage and costs
                    <?php if ($isAdmin): ?>
                        <span style="color: var(--purple-light);">(Admin: viewing all users)</span>
                    <?php endif; ?>
                </p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="/dashboard" class="btn btn-secondary">← Dashboard</a>
                <div class="range-selector">
                    <button class="range-btn active" data-range="month">This Month</button>
                    <button class="range-btn" data-range="week">Last 7 Days</button>
                    <button class="range-btn" data-range="today">Today</button>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid" id="stats-grid">
            <div class="stat-card">
                <div class="stat-value" id="stat-today-cost">$0.00</div>
                <div class="stat-label">Today's Spend</div>
                <div class="stat-sublabel" id="stat-today-requests">0 requests</div>
            </div>
            <div class="stat-card highlight">
                <div class="stat-value cost" id="stat-month-cost">$0.00</div>
                <div class="stat-label">This Month</div>
                <div class="stat-sublabel" id="stat-month-requests">0 requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="stat-budget-remaining">$0.00</div>
                <div class="stat-label">Budget Remaining</div>
                <div class="progress-bar">
                    <div class="progress-fill" id="budget-progress" style="width: 0%"></div>
                </div>
                <div class="stat-sublabel" id="stat-budget-percent">0% used</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="stat-total-tokens">0</div>
                <div class="stat-label">Tokens Used (Month)</div>
                <div class="stat-sublabel" id="stat-avg-per-request">~0 per request</div>
            </div>
        </div>

        <!-- Daily Chart -->
        <div class="chart-container">
            <div class="chart-header">
                <div class="chart-title">Daily Spending (Last 30 Days)</div>
            </div>
            <div class="chart-canvas" id="daily-chart">
                <div style="color: var(--text-muted); margin: auto;">Loading...</div>
            </div>
        </div>

        <!-- Breakdowns -->
        <div class="breakdown-grid">
            <!-- By Model -->
            <div class="breakdown-card">
                <div class="breakdown-title">💰 Cost by Model</div>
                <div id="model-breakdown">
                    <div style="text-align: center; padding: 20px; color: var(--text-muted);">Loading...</div>
                </div>
            </div>

            <!-- By Workspace -->
            <div class="breakdown-card">
                <div class="breakdown-title">📁 Cost by Workspace</div>
                <div id="workspace-breakdown">
                    <div style="text-align: center; padding: 20px; color: var(--text-muted);">Loading...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toast-container"></div>

    <script src="/assets/js/api.js"></script>
    <script>
        let currentRange = 'month';

        document.addEventListener('DOMContentLoaded', () => {
            loadAllData();

            // Range selector
            document.querySelectorAll('.range-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    currentRange = btn.dataset.range;
                    loadAllData();
                });
            });
        });

        async function loadAllData() {
            await Promise.all([
                loadStats(),
                loadDailyChart(),
                loadModelBreakdown(),
                loadWorkspaceBreakdown()
            ]);
        }

        async function loadStats() {
            try {
                const response = await API.get('/usage/stats?range=' + currentRange);
                const data = response.data;

                // Today
                document.getElementById('stat-today-cost').textContent = '$' + data.today.cost.toFixed(2);
                document.getElementById('stat-today-requests').textContent = data.today.requests + ' requests';

                // Month
                document.getElementById('stat-month-cost').textContent = '$' + data.month.cost.toFixed(2);
                document.getElementById('stat-month-requests').textContent = data.month.requests + ' requests';

                // Budget
                document.getElementById('stat-budget-remaining').textContent = '$' + data.budget.remaining.toFixed(2);
                document.getElementById('stat-budget-percent').textContent = data.budget.percent_used.toFixed(0) + '% used of $' + data.budget.monthly_limit;
                
                const progressFill = document.getElementById('budget-progress');
                progressFill.style.width = Math.min(100, data.budget.percent_used) + '%';
                progressFill.classList.toggle('warning', data.budget.percent_used >= 80);

                // Update budget remaining color
                const remainingEl = document.getElementById('stat-budget-remaining');
                remainingEl.classList.remove('warning', 'danger');
                if (data.budget.percent_used >= 90) {
                    remainingEl.classList.add('danger');
                } else if (data.budget.percent_used >= 80) {
                    remainingEl.classList.add('warning');
                }

                // Tokens
                document.getElementById('stat-total-tokens').textContent = formatNumber(data.month.tokens);
                const avgPerRequest = data.month.requests > 0 ? Math.round(data.month.tokens / data.month.requests) : 0;
                document.getElementById('stat-avg-per-request').textContent = '~' + formatNumber(avgPerRequest) + ' per request';

            } catch (error) {
                console.error('Failed to load stats:', error);
            }
        }

        async function loadDailyChart() {
            try {
                const response = await API.get('/usage/daily?days=30');
                const data = response.data;

                const maxCost = Math.max(...data.map(d => d.cost), 0.01);
                const chartHtml = data.map(d => {
                    const height = Math.max(4, (d.cost / maxCost) * 250);
                    const tooltip = `${d.date}: $${d.cost.toFixed(4)} (${d.requests} req)`;
                    return `<div class="chart-bar" style="height: ${height}px" data-tooltip="${tooltip}"></div>`;
                }).join('');

                document.getElementById('daily-chart').innerHTML = chartHtml || '<div style="color: var(--text-muted); margin: auto;">No data</div>';

            } catch (error) {
                console.error('Failed to load daily chart:', error);
                document.getElementById('daily-chart').innerHTML = '<div style="color: var(--text-muted); margin: auto;">Failed to load</div>';
            }
        }

        async function loadModelBreakdown() {
            try {
                const response = await API.get('/usage/by-model?range=' + currentRange);
                const data = response.data;

                if (data.length === 0) {
                    document.getElementById('model-breakdown').innerHTML = '<div style="text-align: center; padding: 20px; color: var(--text-muted);">No usage data</div>';
                    return;
                }

                const html = data.map(item => `
                    <div class="breakdown-item">
                        <div class="breakdown-name">
                            <div class="breakdown-icon ${item.provider}">${item.provider === 'openai' ? '🟢' : '🟤'}</div>
                            <div>
                                <div style="color: var(--white); font-weight: 500;">${escapeHtml(item.model)}</div>
                                <div style="font-size: 12px; color: var(--text-muted);">${item.provider}</div>
                            </div>
                        </div>
                        <div class="breakdown-details">
                            <div class="breakdown-cost">$${item.cost.toFixed(4)}</div>
                            <div class="breakdown-meta">${formatNumber(item.total_tokens)} tokens · ${item.requests} req</div>
                        </div>
                    </div>
                `).join('');

                document.getElementById('model-breakdown').innerHTML = html;

            } catch (error) {
                console.error('Failed to load model breakdown:', error);
            }
        }

        async function loadWorkspaceBreakdown() {
            try {
                const response = await API.get('/usage/by-workspace?range=' + currentRange);
                const data = response.data;

                if (data.length === 0) {
                    document.getElementById('workspace-breakdown').innerHTML = '<div style="text-align: center; padding: 20px; color: var(--text-muted);">No usage data</div>';
                    return;
                }

                const icons = { normal: '💬', church: '⛪', coder: '💻', unknown: '❓' };
                
                const html = data.map(item => `
                    <div class="breakdown-item">
                        <div class="breakdown-name">
                            <div class="breakdown-icon ${item.workspace}">${icons[item.workspace] || '📁'}</div>
                            <div>
                                <div style="color: var(--white); font-weight: 500; text-transform: capitalize;">${escapeHtml(item.workspace)}</div>
                                <div style="font-size: 12px; color: var(--text-muted);">${item.requests} requests</div>
                            </div>
                        </div>
                        <div class="breakdown-details">
                            <div class="breakdown-cost">$${item.cost.toFixed(4)}</div>
                            <div class="breakdown-meta">${formatNumber(item.tokens)} tokens</div>
                        </div>
                    </div>
                `).join('');

                document.getElementById('workspace-breakdown').innerHTML = html;

            } catch (error) {
                console.error('Failed to load workspace breakdown:', error);
            }
        }

        function formatNumber(num) {
            if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
            if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
            return num.toString();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>