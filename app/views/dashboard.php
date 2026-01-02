<?php
if (!defined('CODERAI')) die('Direct access not allowed');

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../services/ai/ModelCatalog.php';

// Redirect if not logged in
if (!Auth::check()) {
    header('Location: /login');
    exit;
}

$user = Auth::user();
if (!$user) {
    header('Location: /login');
    exit;
}
$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>CoderAI - AI Chat Platform</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css?v=10">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Overlay (Mobile) -->
        <div class="sidebar-overlay" id="sidebar-overlay"></div>

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">C</div>
                <span class="sidebar-title">CoderAI</span>
            </div>

            <!-- Workspace Tabs (active class set by JavaScript based on localStorage) -->
            <div class="workspace-tabs">
                <button class="workspace-tab" data-workspace="normal" title="Normal Chat">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                    </svg>
                    Chat
                </button>
                <button class="workspace-tab" data-workspace="church" title="Church Chat">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                        <path d="M2 17l10 5 10-5"/>
                        <path d="M2 12l10 5 10-5"/>
                    </svg>
                    Church
                </button>
                <button class="workspace-tab" data-workspace="coder" title="Coder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="16 18 22 12 16 6"/>
                        <polyline points="8 6 2 12 8 18"/>
                    </svg>
                    Coder
                </button>
            </div>

            <!-- New Chat Button -->
            <button class="new-chat-btn" id="new-chat-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                New Project
            </button>

            <!-- Projects List -->
            <div class="projects-section">
                <div class="section-label">
                    Projects
                    <button title="Refresh" onclick="App.loadProjects()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M23 4v6h-6"/><path d="M1 20v-6h6"/>
                            <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                        </svg>
                    </button>
                </div>
                <div id="projects-list">
                    <div style="padding: 20px; text-align: center; color: var(--text-muted);">
                        <div class="loading"><span></span><span></span><span></span></div>
                    </div>
                </div>
            </div>

            <!-- User Section -->
            <div class="sidebar-footer">
                <div class="user-info" id="user-info">
                    <div class="user-avatar" id="user-avatar"><?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?></div>
                    <div class="user-details">
                        <div class="user-name" id="user-name"><?= htmlspecialchars($user['name'] ?? 'User') ?></div>
                        <div class="user-role" id="user-role"><?= htmlspecialchars($user['role'] ?? 'user') ?></div>
                    </div>
                </div>
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                <a href="/settings" style="display: block; padding: 10px 12px; margin-top: 8px; border-radius: 8px; color: var(--text-secondary); font-size: 13px; text-decoration: none;">
                    ⚙️ Settings
                </a>
                <a href="/repos" style="display: block; padding: 10px 12px; margin-top: 4px; border-radius: 8px; color: var(--text-secondary); font-size: 13px; text-decoration: none;">
                    📁 Repos
                </a>
                <a href="/coder" style="display: block; padding: 10px 12px; margin-top: 4px; border-radius: 8px; color: var(--text-secondary); font-size: 13px; text-decoration: none;">
                    💻 Coder
                </a>
                <?php endif; ?>
                <a href="#" id="logout-btn" style="display: block; padding: 10px 12px; margin-top: 4px; border-radius: 8px; color: var(--text-secondary); font-size: 13px; text-decoration: none;">
                    🚪 Logout
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="main-header">
                <div class="header-left">
                    <button class="menu-toggle" id="menu-toggle">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="3" y1="12" x2="21" y2="12"/>
                            <line x1="3" y1="6" x2="21" y2="6"/>
                            <line x1="3" y1="18" x2="21" y2="18"/>
                        </svg>
                    </button>
                    <div class="current-chat-info">
                        <h2 id="current-chat-title">CoderAI</h2>
                        <span id="current-chat-info">Select a workspace to begin</span>
                    </div>
                </div>
                <div class="header-actions">
                    <!-- Model Dropdown (visible in all workspaces) -->
                    <div id="model-dropdown-container">
                        <select id="model-select" style="padding: 8px 12px; border-radius: 6px; border: 1px solid var(--glass-border); background: var(--glass-bg); color: var(--text-primary); font-size: 12px; cursor: pointer; min-width: 180px;">
                            <!-- Options populated by JavaScript based on workspace -->
                        </select>
                    </div>
                </div>
            </header>

            <!-- Chat Container -->
            <div class="chat-container">
                <!-- Messages Area -->
                <div class="messages-area" id="messages-area">
                    <!-- Welcome Screen -->
                    <div class="welcome-screen" id="welcome-screen">
                        <div class="welcome-icon">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                            </svg>
                        </div>
                        <h1>Welcome to CoderAI</h1>
                        <p>Your intelligent AI assistant for chat, church matters, and code development. Select a workspace and create a project to get started.</p>
                        <div class="quick-actions">
                            <button class="quick-action" data-prompt="Help me write a professional email">
                                Write an email
                            </button>
                            <button class="quick-action" data-prompt="Explain this concept to me">
                                Learn something
                            </button>
                            <button class="quick-action" data-prompt="Help me brainstorm ideas for">
                                Brainstorm ideas
                            </button>
                        </div>
                    </div>

                    <!-- Messages Wrapper -->
                    <div class="messages-wrapper" id="messages-wrapper">
                        <!-- Messages loaded dynamically -->
                    </div>
                </div>

                <!-- Input Area -->
                <div class="input-area">
                    <div class="input-wrapper">
                        <div class="input-container">
                            <textarea
                                id="chat-input"
                                placeholder="Type your message..."
                                rows="1"
                            ></textarea>
                            <div class="input-actions">
                                <button class="input-btn send" id="send-btn" disabled title="Send message">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="22" y1="2" x2="11" y2="13"/>
                                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="input-hint">
                            Press Enter to send, Shift+Enter for new line
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- New Project Modal -->
    <div class="modal-overlay" id="new-project-modal">
        <div class="modal">
            <div class="modal-header">
                <h3>Create New Project</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="project-form">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="project-name">Project Name</label>
                        <input type="text" id="project-name" placeholder="My Project" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Project</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toast-container"></div>

    <!-- Scripts (v12 = always send selected model) -->
    <script src="/assets/js/api.js?v=6"></script>
    <script src="/assets/js/app.js?v=12"></script>
</body>
</html>