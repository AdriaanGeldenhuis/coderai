<?php
if (!defined('CODERAI')) die('Direct access not allowed');

require_once __DIR__ . '/../core/Auth.php';

// Redirect if not logged in
if (!Auth::check()) {
    header('Location: /login');
    exit;
}

$user = Auth::user();
$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>CoderAI - Code Runner</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .coder-layout {
            display: flex;
            height: 100vh;
            background: var(--black-primary);
        }
        
        /* Left Panel - Run Builder */
        .builder-panel {
            width: 380px;
            background: var(--glass-bg);
            border-right: 1px solid var(--glass-border);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .builder-header {
            padding: 20px;
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .builder-header h1 {
            font-size: 20px;
            font-weight: 700;
            color: var(--white);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .builder-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }
        
        .builder-section {
            margin-bottom: 24px;
        }
        
        .builder-section h3 {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .builder-section h3 .step-num {
            width: 20px;
            height: 20px;
            background: var(--purple-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            color: var(--white);
        }
        
        /* Form Controls */
        .form-control {
            width: 100%;
            padding: 10px 12px;
            background: var(--black-secondary);
            border: 2px solid var(--glass-border);
            border-radius: 8px;
            color: var(--white);
            font-size: 14px;
            transition: all var(--transition-fast);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--purple-primary);
            box-shadow: 0 0 0 3px var(--purple-glow);
        }
        
        .form-control::placeholder {
            color: var(--text-muted);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
            font-family: inherit;
        }
        
        /* Mode Toggle Buttons */
        .mode-toggle-btn {
            flex: 1;
            padding: 12px;
            background: var(--black-tertiary);
            border: 2px solid var(--glass-border);
            border-radius: 8px;
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        
        .mode-toggle-btn:hover {
            background: var(--glass-bg);
            border-color: var(--purple-primary);
        }
        
        .mode-toggle-btn.active {
            background: var(--purple-primary);
            border-color: var(--purple-primary);
            color: var(--white);
        }
        
        /* Phase Indicators */
        .phases-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .phase-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--black-tertiary);
            border-radius: 8px;
            border-left: 4px solid var(--glass-border);
            transition: all var(--transition-fast);
        }
        
        .phase-item.pending { border-left-color: var(--text-muted); }
        .phase-item.running { border-left-color: var(--accent-blue); background: rgba(59, 130, 246, 0.1); }
        .phase-item.completed { border-left-color: var(--accent-green); }
        .phase-item.failed { border-left-color: var(--accent-red); }
        .phase-item.ready { border-left-color: var(--accent-gold); }
        
        .phase-icon {
            width: 32px;
            height: 32px;
            background: var(--glass-bg);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        .phase-info {
            flex: 1;
        }
        
        .phase-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--white);
        }
        
        .phase-status {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .phase-action {
            padding: 6px 12px;
            background: var(--purple-primary);
            border: none;
            border-radius: 6px;
            color: var(--white);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        
        .phase-action:hover:not(:disabled) {
            background: var(--purple-light);
            transform: translateY(-1px);
        }
        
        .phase-action:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Right Panel - Output */
        .output-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .output-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            gap: 8px;
        }
        
        .output-tab {
            padding: 8px 16px;
            background: transparent;
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        
        .output-tab:hover {
            background: var(--glass-bg);
            color: var(--text-secondary);
        }
        
        .output-tab.active {
            background: var(--purple-primary);
            border-color: var(--purple-primary);
            color: var(--white);
        }
        
        .output-content {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }
        
        .output-pane {
            display: none;
        }
        
        .output-pane.active {
            display: block;
        }
        
        /* Console Output */
        .console-output {
            font-family: 'Fira Code', monospace;
            font-size: 13px;
            line-height: 1.6;
            color: var(--text-secondary);
        }
        
        .console-line {
            padding: 4px 0;
            display: flex;
            gap: 12px;
        }
        
        .console-time {
            color: var(--text-muted);
            flex-shrink: 0;
        }
        
        .console-line.info .console-msg { color: var(--text-secondary); }
        .console-line.success .console-msg { color: var(--accent-green); }
        .console-line.error .console-msg { color: var(--accent-red); }
        .console-line.warning .console-msg { color: var(--accent-gold); }
        
        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-top: 16px;
        }
        
        .quick-action-btn {
            padding: 10px;
            background: var(--black-tertiary);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            color: var(--text-secondary);
            font-size: 12px;
            cursor: pointer;
            transition: all var(--transition-fast);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .quick-action-btn:hover {
            background: var(--glass-bg);
            border-color: var(--purple-primary);
            color: var(--white);
        }
        
        /* Templates */
        .template-btn {
            width: 100%;
            padding: 12px;
            margin-bottom: 8px;
            background: var(--black-tertiary);
            border: 1px dashed var(--glass-border);
            border-radius: 8px;
            color: var(--text-muted);
            font-size: 13px;
            cursor: pointer;
            text-align: left;
            transition: all var(--transition-fast);
        }
        
        .template-btn:hover {
            border-color: var(--purple-primary);
            border-style: solid;
            color: var(--purple-light);
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .coder-layout {
                flex-direction: column;
            }
            
            .builder-panel {
                width: 100%;
                max-height: 50vh;
            }
        }
    </style>
</head>
<body>
    <div class="coder-layout">
        <!-- Left Panel - Run Builder -->
        <div class="builder-panel">
            <div class="builder-header">
                <h1>💻 CoderAI</h1>
                <a href="/dashboard" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;">← Back</a>
            </div>
            
            <div class="builder-content">
                <!-- Step 1: Select Repo -->
                <div class="builder-section">
                    <h3><span class="step-num">1</span> Select Repository</h3>
                    <select id="repo-select" class="form-control">
                        <option value="">-- Choose a repository --</option>
                    </select>
                    <div class="quick-actions-grid" id="repo-actions" style="display: none;">
                        <button class="quick-action-btn" onclick="CoderApp.scanRepo()">
                            📂 Scan Files
                        </button>
                        <button class="quick-action-btn" onclick="CoderApp.searchRepo()">
                            🔍 Search
                        </button>
                    </div>
                </div>
                
                <!-- Step 2: Describe Changes -->
                <div class="builder-section">
                    <h3><span class="step-num">2</span> Describe Changes</h3>
                    <textarea id="request-input" class="form-control" placeholder="Example: Add a contact form with name, email, and message fields. Include validation and a success message."></textarea>
                    
                    <div style="margin-top: 12px;">
                        <div style="font-size: 12px; font-weight: 500; color: var(--text-secondary); margin-bottom: 6px;">Quick Templates:</div>
                        <button class="template-btn" onclick="CoderApp.useTemplate('crud')">
                            📝 Add CRUD Module
                        </button>
                        <button class="template-btn" onclick="CoderApp.useTemplate('api')">
                            🔌 Add API Endpoint
                        </button>
                        <button class="template-btn" onclick="CoderApp.useTemplate('setting')">
                            ⚙️ Add Settings Field
                        </button>
                    </div>
                </div>
                
                <!-- Step 3: Model Selection -->
                <div class="builder-section">
                    <h3><span class="step-num">3</span> AI Model</h3>
                    <select id="model-select" class="form-control" onchange="CoderApp.setModel(this.value)">
                        <option value="qwen2.5-coder:7b">⚡ Coder 7B (Fast)</option>
                        <option value="qwen2.5-coder:14b">🎯 Coder 14B (Precise)</option>
                        <option value="qwen2.5:14b-instruct">💬 Instruct 14B (General)</option>
                    </select>
                    <div id="model-info" style="font-size: 12px; color: var(--text-muted); padding: 12px; background: var(--black-tertiary); border-radius: 6px; margin-top: 12px;">
                        <strong>Coder 7B:</strong> Fast responses, ideal for quick tasks and simple code changes.
                    </div>
                </div>
                
                <!-- Step 4: Run Phases -->
                <div class="builder-section">
                    <h3><span class="step-num">4</span> Execute</h3>
                    
                    <button id="start-run-btn" class="btn btn-primary" style="width: 100%; margin-bottom: 16px;" onclick="CoderApp.startRun()">
                        🚀 Start New Run
                    </button>
                    
                    <div class="phases-list" id="phases-list">
                        <div class="phase-item pending" id="phase-plan">
                            <div class="phase-icon">📋</div>
                            <div class="phase-info">
                                <div class="phase-name">Plan</div>
                                <div class="phase-status">Waiting...</div>
                            </div>
                            <button class="phase-action" onclick="CoderApp.runPhase('plan')" disabled>Run</button>
                        </div>
                        
                        <div class="phase-item pending" id="phase-code">
                            <div class="phase-icon">💻</div>
                            <div class="phase-info">
                                <div class="phase-name">Code</div>
                                <div class="phase-status">Waiting...</div>
                            </div>
                            <button class="phase-action" onclick="CoderApp.runPhase('code')" disabled>Run</button>
                        </div>
                        
                        <div class="phase-item pending" id="phase-review">
                            <div class="phase-icon">🔍</div>
                            <div class="phase-info">
                                <div class="phase-name">Review</div>
                                <div class="phase-status">Waiting...</div>
                            </div>
                            <button class="phase-action" onclick="CoderApp.runPhase('review')" disabled>Run</button>
                        </div>
                        
                        <div class="phase-item pending" id="phase-apply">
                            <div class="phase-icon">✅</div>
                            <div class="phase-info">
                                <div class="phase-name">Apply</div>
                                <div class="phase-status">Waiting...</div>
                            </div>
                            <button class="phase-action" onclick="CoderApp.runPhase('apply')" disabled>Apply</button>
                        </div>
                    </div>
                    
                    <button id="rollback-btn" class="btn btn-danger" style="width: 100%; margin-top: 16px; display: none;" onclick="CoderApp.rollback()">
                        ⏪ Rollback Changes
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Right Panel - Output -->
        <div class="output-panel">
            <div class="output-header">
                <button class="output-tab active" data-tab="console">Console</button>
                <button class="output-tab" data-tab="plan">Plan</button>
                <button class="output-tab" data-tab="diff">Diff</button>
                <button class="output-tab" data-tab="review">Review</button>
                <button class="output-tab" data-tab="files">Files</button>
            </div>
            
            <div class="output-content">
                <!-- Console Tab -->
                <div class="output-pane active" id="pane-console">
                    <div class="console-output" id="console-output">
                        <div class="console-line info">
                            <span class="console-time">[<?= date('H:i:s') ?>]</span>
                            <span class="console-msg">CoderAI ready. Select a repository and describe your changes.</span>
                        </div>
                    </div>
                </div>
                
                <!-- Plan Tab -->
                <div class="output-pane" id="pane-plan">
                    <div id="plan-content">
                        <p style="color: var(--text-muted);">No plan yet. Start a run to generate a plan.</p>
                    </div>
                </div>
                
                <!-- Diff Tab -->
                <div class="output-pane" id="pane-diff">
                    <div id="diff-content">
                        <p style="color: var(--text-muted);">No diff yet. Complete the code phase to see changes.</p>
                    </div>
                </div>
                
                <!-- Review Tab -->
                <div class="output-pane" id="pane-review">
                    <div id="review-content">
                        <p style="color: var(--text-muted);">No review yet. Complete the review phase to see results.</p>
                    </div>
                </div>
                
                <!-- Files Tab -->
                <div class="output-pane" id="pane-files">
                    <div id="files-content">
                        <p style="color: var(--text-muted);">Select a repository and click "Scan Files" to browse.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toast-container"></div>

    <script src="/assets/js/api.js"></script>
    <script>
        const CoderApp = {
            state: {
                repos: [],
                currentRepo: null,
                currentRun: null,
                model: 'qwen2.5-coder:7b', // Selected AI model
                phases: {
                    plan: 'pending',
                    code: 'pending',
                    review: 'pending',
                    apply: 'pending'
                }
            },

            templates: {
                crud: `Add a complete CRUD module for [entity name]:
- Create database migration with appropriate fields
- Create model/class with validation
- Create list view with pagination
- Create add/edit form
- Create delete confirmation
- Add proper error handling`,
                api: `Add a new API endpoint:
- Endpoint: /api/[resource]
- Methods: GET (list), POST (create), GET/:id (show), PUT/:id (update), DELETE/:id (delete)
- Include authentication check
- Include input validation
- Return proper JSON responses`,
                setting: `Add a new settings field:
- Setting key: [setting_name]
- Type: [text/number/boolean/select]
- Add to settings table
- Add to settings form UI
- Add getter in SettingsService`
            },

            async init() {
                await this.loadRepos();
                this.bindEvents();
                this.log('CoderAI initialized', 'info');
            },

            bindEvents() {
                // Tab switching
                document.querySelectorAll('.output-tab').forEach(tab => {
                    tab.addEventListener('click', (e) => {
                        this.switchTab(e.target.dataset.tab);
                    });
                });

                // Repo selection
                document.getElementById('repo-select').addEventListener('change', (e) => {
                    const repoId = e.target.value;
                    if (repoId) {
                        this.state.currentRepo = this.state.repos.find(r => r.id == repoId);
                        document.getElementById('repo-actions').style.display = 'grid';
                        this.log(`Selected repository: ${this.state.currentRepo.label}`, 'info');
                    } else {
                        this.state.currentRepo = null;
                        document.getElementById('repo-actions').style.display = 'none';
                    }
                });
            },

            setModel(modelId) {
                this.state.model = modelId;

                // Update dropdown selection
                const select = document.getElementById('model-select');
                if (select) {
                    select.value = modelId;
                }

                // Update info text
                const infoDiv = document.getElementById('model-info');
                const modelDescriptions = {
                    'qwen2.5-coder:7b': '<strong>Coder 7B:</strong> Fast responses, ideal for quick tasks and simple code changes.',
                    'qwen2.5-coder:14b': '<strong>Coder 14B:</strong> More capable model for complex coding and detailed analysis.',
                    'qwen2.5:14b-instruct': '<strong>Instruct 14B:</strong> General purpose model, good for explanations and documentation.'
                };
                infoDiv.innerHTML = modelDescriptions[modelId] || modelDescriptions['qwen2.5-coder:7b'];

                this.log(`Model changed to: ${modelId}`, 'info');
            },

            async loadRepos() {
                try {
                    const response = await API.repos.list();
                    this.state.repos = response.data || [];
                    
                    const select = document.getElementById('repo-select');
                    select.innerHTML = '<option value="">-- Choose a repository --</option>' + 
                        this.state.repos.map(r => 
                            `<option value="${r.id}">${this.escapeHtml(r.label)} (${this.escapeHtml(r.base_path)})</option>`
                        ).join('');
                    
                    if (this.state.repos.length === 0) {
                        this.log('No repositories found. Add one in Settings > Repos.', 'warning');
                    }
                } catch (error) {
                    this.log('Failed to load repos: ' + error.message, 'error');
                }
            },

            useTemplate(name) {
                const template = this.templates[name];
                if (template) {
                    document.getElementById('request-input').value = template;
                    this.log(`Loaded template: ${name}`, 'info');
                }
            },

            async scanRepo() {
                if (!this.state.currentRepo) {
                    this.showToast('Select a repository first', 'error');
                    return;
                }

                this.log('Scanning repository...', 'info');
                this.switchTab('files');

                try {
                    const response = await API.get(`/repos/${this.state.currentRepo.id}`);
                    const files = response.data.files || [];
                    this.renderFileTree(files);
                    this.log(`Found ${this.countFiles(files)} files`, 'success');
                } catch (error) {
                    this.log('Scan failed: ' + error.message, 'error');
                }
            },

            countFiles(items) {
                let count = 0;
                for (const item of items) {
                    if (item.type === 'file') count++;
                    if (item.children) count += this.countFiles(item.children);
                }
                return count;
            },

            renderFileTree(items, indent = 0) {
                const container = document.getElementById('files-content');
                let html = '<div class="file-tree" style="font-family: monospace; font-size: 12px;">';
                
                const renderItems = (items, level) => {
                    let result = '';
                    for (const item of items) {
                        const icon = item.type === 'directory' ? '📁' : '📄';
                        result += `<div style="padding: 6px 12px; padding-left: ${level * 16 + 12}px; color: var(--text-secondary);">
                            ${icon} ${this.escapeHtml(item.name)}
                        </div>`;
                        if (item.children) {
                            result += renderItems(item.children, level + 1);
                        }
                    }
                    return result;
                };
                
                html += renderItems(items, 0);
                html += '</div>';
                container.innerHTML = html;
            },

            async searchRepo() {
                if (!this.state.currentRepo) {
                    this.showToast('Select a repository first', 'error');
                    return;
                }

                const query = prompt('Search for:');
                if (!query) return;

                this.log(`Searching for: "${query}"...`, 'info');
                this.log('Search feature coming soon', 'warning');
            },

            async startRun() {
                const request = document.getElementById('request-input').value.trim();
                
                if (!this.state.currentRepo) {
                    this.showToast('Select a repository first', 'error');
                    return;
                }
                
                if (!request) {
                    this.showToast('Describe the changes you want', 'error');
                    return;
                }

                this.log('Creating new run...', 'info');

                try {
                    // Create a coder project if needed
                    let projectResponse = await API.projects.list('coder');
                    let project = projectResponse.data?.[0];

                    if (!project) {
                        this.log('Creating coder project...', 'info');
                        const createProj = await API.projects.create({
                            workspace_slug: 'coder',
                            name: 'Coder Workspace'
                        });
                        project = { id: createProj.data.id };
                    }

                    // Create thread
                    const threadResponse = await API.threads.create({
                        project_id: project.id,
                        title: 'Run: ' + request.substring(0, 40) + '...'
                    });

                    // Create run with selected model
                    const runResponse = await API.runs.create(threadResponse.data.id, request, {
                        model: this.state.model
                    });
                    this.state.currentRun = {
                        id: runResponse.data.id,
                        thread_id: threadResponse.data.id,
                        status: 'pending'
                    };

                    this.log(`✓ Run created (ID: ${this.state.currentRun.id})`, 'success');
                    
                    // Enable plan phase
                    this.updatePhase('plan', 'ready', 'Ready to plan');
                    document.querySelector('#phase-plan .phase-action').disabled = false;

                } catch (error) {
                    this.log('✗ Failed to create run: ' + error.message, 'error');
                }
            },

            async runPhase(phase) {
                if (!this.state.currentRun) {
                    this.showToast('Start a run first', 'error');
                    return;
                }

                this.log(`Running ${phase} phase with model: ${this.state.model}...`, 'info');
                this.updatePhase(phase, 'running', 'Processing...');

                try {
                    let response;
                    switch (phase) {
                        case 'plan':
                            response = await API.runs.plan(this.state.currentRun.id, { model: this.state.model });
                            this.displayPlan(response.data.plan);
                            this.updatePhase('plan', 'completed', 'Completed');
                            this.updatePhase('code', 'ready', 'Ready');
                            document.querySelector('#phase-code .phase-action').disabled = false;
                            this.switchTab('plan');
                            break;

                        case 'code':
                            response = await API.runs.code(this.state.currentRun.id, { model: this.state.model });
                            this.displayDiff(response.data.diff);
                            this.updatePhase('code', 'completed', 'Completed');
                            this.updatePhase('review', 'ready', 'Ready');
                            document.querySelector('#phase-review .phase-action').disabled = false;
                            this.switchTab('diff');
                            break;

                        case 'review':
                            response = await API.runs.review(this.state.currentRun.id, { model: this.state.model });
                            this.displayReview(response.data.review);
                            this.updatePhase('review', 'completed', 'Completed');
                            
                            if (response.data.review?.safe_to_apply) {
                                this.updatePhase('apply', 'ready', 'Safe to apply');
                                document.querySelector('#phase-apply .phase-action').disabled = false;
                            } else {
                                this.updatePhase('apply', 'failed', 'Not safe');
                                this.log('⚠️ Review found issues. Check the Review tab.', 'warning');
                            }
                            this.switchTab('review');
                            break;

                        case 'apply':
                            response = await API.runs.apply(this.state.currentRun.id);
                            this.updatePhase('apply', 'completed', 'Applied!');
                            this.log('✓ Changes applied successfully!', 'success');
                            document.getElementById('rollback-btn').style.display = 'block';
                            break;
                    }

                    this.log(`✓ ${phase} phase completed`, 'success');

                } catch (error) {
                    this.log(`✗ ${phase} failed: ` + error.message, 'error');
                    this.updatePhase(phase, 'failed', 'Failed');
                }
            },

            updatePhase(phase, status, message) {
                const el = document.getElementById(`phase-${phase}`);
                if (!el) return;

                el.className = `phase-item ${status}`;
                el.querySelector('.phase-status').textContent = message;
            },

            displayPlan(plan) {
                const container = document.getElementById('plan-content');
                
                let html = `
                    <div style="padding: 20px; background: var(--black-tertiary); border-radius: 12px; margin-bottom: 16px;">
                        <h4 style="margin: 0 0 12px 0; color: var(--white);">📋 Summary</h4>
                        <p style="color: var(--text-secondary); margin: 0;">${this.escapeHtml(plan.summary || 'No summary')}</p>
                    </div>
                `;
                
                if (plan.files_to_modify && plan.files_to_modify.length > 0) {
                    html += `
                        <div style="padding: 20px; background: var(--black-tertiary); border-radius: 12px; margin-bottom: 16px;">
                            <h4 style="margin: 0 0 12px 0; color: var(--white);">📁 Files to Modify</h4>
                            <ul style="list-style: none; margin: 0; padding: 0;">
                                ${plan.files_to_modify.map(f => `
                                    <li style="padding: 8px 0; border-bottom: 1px solid var(--glass-border); display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--text-secondary);">
                                        <span style="padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; background: ${f.action === 'create' ? 'rgba(16, 185, 129, 0.2)' : f.action === 'modify' ? 'rgba(59, 130, 246, 0.2)' : 'rgba(239, 68, 68, 0.2)'}; color: ${f.action === 'create' ? 'var(--accent-green)' : f.action === 'modify' ? 'var(--accent-blue)' : 'var(--accent-red)'};">${f.action}</span>
                                        <code style="font-family: monospace; font-size: 12px;">${this.escapeHtml(f.path)}</code>
                                    </li>
                                `).join('')}
                            </ul>
                        </div>
                    `;
                }
                
                if (plan.steps && plan.steps.length > 0) {
                    html += `
                        <div style="padding: 20px; background: var(--black-tertiary); border-radius: 12px;">
                            <h4 style="margin: 0 0 12px 0; color: var(--white);">📝 Steps</h4>
                            <ol style="margin: 0 0 0 20px; padding: 0; color: var(--text-secondary);">
                                ${plan.steps.map(s => `<li style="margin-bottom: 8px;">${this.escapeHtml(s)}</li>`).join('')}
                            </ol>
                        </div>
                    `;
                }
                
                container.innerHTML = html;
            },

            displayDiff(diff) {
                const container = document.getElementById('diff-content');
                container.innerHTML = `<pre style="font-family: 'Fira Code', monospace; font-size: 13px; line-height: 1.6; color: var(--text-secondary); overflow-x: auto;"><code>${this.escapeHtml(diff)}</code></pre>`;
            },

            displayReview(review) {
                const container = document.getElementById('review-content');
                
                let statusColor = review.safe_to_apply ? 'var(--accent-green)' : 'var(--accent-red)';
                let statusText = review.safe_to_apply ? '✓ Safe to Apply' : '✗ Not Safe';
                
                let html = `
                    <div style="padding: 20px; background: var(--black-tertiary); border-radius: 12px; margin-bottom: 16px;">
                        <h4 style="margin: 0 0 8px 0; color: var(--white);">Review Result</h4>
                        <p style="font-size: 18px; color: ${statusColor}; font-weight: 600; margin: 0;">${statusText}</p>
                        <p style="color: var(--text-secondary); margin: 8px 0 0 0;">
                            Risk Level: <strong>${review.risk_level || 'Unknown'}</strong>
                        </p>
                    </div>
                `;
                
                if (review.issues && review.issues.length > 0) {
                    html += '<h4 style="margin: 20px 0 12px 0; color: var(--white);">Issues Found</h4>';
                    for (const issue of review.issues) {
                        const severityColor = issue.severity === 'critical' ? 'var(--accent-red)' : issue.severity === 'warning' ? 'var(--accent-gold)' : 'var(--accent-blue)';
                        html += `
                            <div style="padding: 16px; border-radius: 8px; margin-bottom: 12px; border-left: 4px solid ${severityColor}; background: rgba(0,0,0,0.3);">
                                <h5 style="margin: 0 0 4px 0; color: ${severityColor}; font-size: 14px;">${issue.severity.toUpperCase()}: ${this.escapeHtml(issue.file || 'General')}</h5>
                                <p style="margin: 0; color: var(--text-secondary); font-size: 13px;">${this.escapeHtml(issue.message)}</p>
                                ${issue.suggestion ? `<p style="color: var(--accent-green); margin: 8px 0 0 0; font-size: 13px;"><em>💡 ${this.escapeHtml(issue.suggestion)}</em></p>` : ''}
                            </div>
                        `;
                    }
                } else {
                    html += '<p style="color: var(--accent-green);">✓ No issues found</p>';
                }
                
                container.innerHTML = html;
            },

            async rollback() {
                if (!this.state.currentRun) return;
                
                if (!confirm('Rollback all changes made by this run?')) return;

                this.log('Rolling back changes...', 'info');

                try {
                    await API.runs.rollback(this.state.currentRun.id);
                    this.log('✓ Rollback successful!', 'success');
                    this.updatePhase('apply', 'pending', 'Rolled back');
                    document.getElementById('rollback-btn').style.display = 'none';
                } catch (error) {
                    this.log('✗ Rollback failed: ' + error.message, 'error');
                }
            },

            switchTab(tabName) {
                document.querySelectorAll('.output-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.output-pane').forEach(p => p.classList.remove('active'));
                
                document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
                document.getElementById(`pane-${tabName}`).classList.add('active');
            },

            log(message, type = 'info') {
                const output = document.getElementById('console-output');
                const time = new Date().toLocaleTimeString();
                output.innerHTML += `
                    <div class="console-line ${type}">
                        <span class="console-time">[${time}]</span>
                        <span class="console-msg">${this.escapeHtml(message)}</span>
                    </div>
                `;
                output.scrollTop = output.scrollHeight;
            },

            showToast(message, type = 'info') {
                const toast = document.createElement('div');
                toast.className = 'toast ' + type;
                toast.textContent = message;
                document.getElementById('toast-container').appendChild(toast);
                setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 4000);
            },

            escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        };

        document.addEventListener('DOMContentLoaded', () => CoderApp.init());
    </script>
</body>
</html>