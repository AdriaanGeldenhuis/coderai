<?php
if (!defined('CODERAI')) die('Direct access not allowed');

require_once __DIR__ . '/../core/Auth.php';

// Require admin
if (!Auth::check() || !Auth::isAdmin()) {
    header('Location: /login');
    exit;
}

$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repos - CoderAI Admin</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .admin-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .admin-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--white);
        }
        .repos-grid {
            display: grid;
            gap: 20px;
        }
        .repo-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 24px;
            transition: all var(--transition-base);
        }
        .repo-card:hover {
            border-color: var(--purple-primary);
            box-shadow: var(--shadow-md);
        }
        .repo-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
        }
        .repo-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 4px;
        }
        .repo-path {
            font-family: 'Fira Code', monospace;
            font-size: 13px;
            color: var(--purple-light);
        }
        .repo-meta {
            display: flex;
            gap: 16px;
            margin-top: 12px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .repo-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }
        .repo-badge.readonly {
            background: rgba(239, 68, 68, 0.2);
            color: var(--accent-red);
        }
        .repo-badge.locked {
            background: rgba(245, 158, 11, 0.2);
            color: var(--accent-gold);
        }
        .repo-badge.active {
            background: rgba(16, 185, 129, 0.2);
            color: var(--accent-green);
        }
        .repo-actions {
            display: flex;
            gap: 8px;
        }
        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        /* Validation result styles */
        .validation-result {
            margin-top: 12px;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
        }
        .validation-result.passed {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--accent-green);
        }
        .validation-result.failed {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--accent-red);
        }
        .validation-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 4px 0;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <div>
                <h1>📁 Repository Management</h1>
                <p style="color: var(--text-muted); margin-top: 8px;">
                    Manage server paths that CoderAI can access
                </p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="/dashboard" class="btn btn-secondary">← Dashboard</a>
                <button class="btn btn-primary" onclick="showCreateModal()">+ New Repo</button>
            </div>
        </div>

        <div id="repos-container">
            <div style="text-align: center; padding: 40px;">
                <div class="loading"><span></span><span></span><span></span></div>
            </div>
        </div>
    </div>

    <!-- Create/Edit Modal -->
    <div class="modal-overlay" id="repo-modal">
        <div class="modal" style="max-width: 600px;">
            <div class="modal-header">
                <h3 id="modal-title">Create Repository</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="repo-form">
                <div class="modal-body">
                    <input type="hidden" id="repo-id">
                    
                    <div class="form-group">
                        <label for="repo-label">Label *</label>
                        <input type="text" id="repo-label" required placeholder="My Website">
                    </div>

                    <div class="form-group">
                        <label for="repo-path">Base Path * (absolute server path)</label>
                        <input type="text" id="repo-path" required placeholder="/home/username/public_html">
                        <small style="color: var(--text-muted); display: block; margin-top: 4px;">
                            Must be an existing directory on the server
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="repo-allowed-paths">Allowed Subpaths (optional, JSON array)</label>
                        <textarea id="repo-allowed-paths" rows="3" placeholder='["app", "public", "resources"]' style="font-family: monospace; font-size: 13px;"></textarea>
                        <small style="color: var(--text-muted); display: block; margin-top: 4px;">
                            Leave empty to allow all subdirectories. Paths are relative to base path.
                        </small>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="repo-readonly" style="width: auto;">
                            <span>Read-only (no modifications allowed)</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="repo-maintenance" style="width: auto;">
                            <span>Maintenance lock (temporarily disable all access)</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Repo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Validation Modal -->
    <div class="modal-overlay" id="validation-modal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Repository Validation</h3>
                <button class="modal-close" onclick="closeValidationModal()">&times;</button>
            </div>
            <div class="modal-body" id="validation-content">
                <div class="loading"><span></span><span></span><span></span></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeValidationModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toast-container"></div>

    <script src="/assets/js/api.js"></script>
    <script>
        let currentRepos = [];

        // Load repos on page load
        document.addEventListener('DOMContentLoaded', () => {
            loadRepos();
        });

        async function loadRepos() {
            try {
                const response = await API.repos.list();
                currentRepos = response.data || [];
                renderRepos();
            } catch (error) {
                showToast('Failed to load repos: ' + error.message, 'error');
                document.getElementById('repos-container').innerHTML = '<div class="empty-state">Failed to load</div>';
            }
        }

        function renderRepos() {
            const container = document.getElementById('repos-container');

            if (currentRepos.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                        <h3>No repositories yet</h3>
                        <p>Create your first repository to start managing server files</p>
                        <button class="btn btn-primary" style="margin-top: 20px;" onclick="showCreateModal()">Create Repository</button>
                    </div>
                `;
                return;
            }

            container.innerHTML = '<div class="repos-grid">' + currentRepos.map(repo => `
                <div class="repo-card" id="repo-card-${repo.id}">
                    <div class="repo-header">
                        <div style="flex: 1;">
                            <div class="repo-title">${escapeHtml(repo.label)}</div>
                            <div class="repo-path">${escapeHtml(repo.base_path)}</div>
                            <div class="repo-meta">
                                ${repo.read_only ? '<span class="repo-badge readonly">🔒 Read-only</span>' : ''}
                                ${repo.maintenance_lock ? '<span class="repo-badge locked">🔧 Maintenance</span>' : ''}
                                ${!repo.read_only && !repo.maintenance_lock ? '<span class="repo-badge active">✓ Active</span>' : ''}
                            </div>
                        </div>
                        <div class="repo-actions">
                            <button class="btn btn-secondary btn-icon" onclick="validateRepo(${repo.id})" title="Validate">
                                ✓
                            </button>
                            <button class="btn btn-secondary btn-icon" onclick="editRepo(${repo.id})" title="Edit">
                               ✏️
                            </button>
                            <button class="btn btn-danger btn-icon" onclick="deleteRepo(${repo.id})" title="Delete">
                                🗑️
                            </button>
                        </div>
                    </div>
                    ${repo.allowed_paths && repo.allowed_paths.length > 0 ? `
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--glass-border);">
                            <small style="color: var(--text-muted);">Allowed paths:</small>
                            <div style="font-size: 12px; color: var(--purple-light); margin-top: 4px;">
                                ${repo.allowed_paths.map(p => `<code>${escapeHtml(p)}</code>`).join(', ')}
                            </div>
                        </div>
                    ` : ''}
                </div>
            `).join('') + '</div>';
        }

        // ✅ NEW: Validate repo
        async function validateRepo(id) {
            const modal = document.getElementById('validation-modal');
            const content = document.getElementById('validation-content');
            
            modal.classList.add('active');
            content.innerHTML = '<div style="text-align: center; padding: 20px;"><div class="loading"><span></span><span></span><span></span></div><p style="margin-top: 12px; color: var(--text-muted);">Validating repository...</p></div>';

            try {
                const response = await API.repos.validate(id);
                const v = response.data;
                
                const statusClass = v.overall === 'passed' ? 'passed' : 'failed';
                const statusIcon = v.overall === 'passed' ? '✅' : '❌';
                
                let checksHtml = '';
                for (const [name, check] of Object.entries(v.checks)) {
                    const icon = check.passed ? '✅' : (check.skipped ? '⏭️' : '❌');
                    checksHtml += `
                        <div class="validation-check">
                            <span>${icon}</span>
                            <span>${escapeHtml(check.message)}</span>
                        </div>
                    `;
                }

                content.innerHTML = `
                    <div style="margin-bottom: 16px;">
                        <strong style="color: var(--white);">${escapeHtml(v.label)}</strong>
                        <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">${escapeHtml(v.base_path)}</div>
                    </div>
                    <div class="validation-result ${statusClass}">
                        <div style="font-weight: 600; margin-bottom: 8px;">${statusIcon} ${v.overall === 'passed' ? 'All Checks Passed' : 'Some Checks Failed'}</div>
                        ${checksHtml}
                    </div>
                `;

                if (v.overall === 'passed') {
                    showToast('Validation passed!', 'success');
                } else {
                    showToast('Validation failed - see details', 'error');
                }

            } catch (error) {
                content.innerHTML = `<div style="color: var(--accent-red);">❌ Validation failed: ${escapeHtml(error.message)}</div>`;
                showToast('Validation error: ' + error.message, 'error');
            }
        }

        function closeValidationModal() {
            document.getElementById('validation-modal').classList.remove('active');
        }

        function showCreateModal() {
            document.getElementById('modal-title').textContent = 'Create Repository';
            document.getElementById('repo-form').reset();
            document.getElementById('repo-id').value = '';
            document.getElementById('repo-path').removeAttribute('disabled');
            document.getElementById('repo-modal').classList.add('active');
        }

        function editRepo(id) {
            const repo = currentRepos.find(r => r.id === id);
            if (!repo) return;

            document.getElementById('modal-title').textContent = 'Edit Repository';
            document.getElementById('repo-id').value = repo.id;
            document.getElementById('repo-label').value = repo.label;
            document.getElementById('repo-path').value = repo.base_path;
            document.getElementById('repo-path').setAttribute('disabled', 'disabled'); // Can't change path
            document.getElementById('repo-allowed-paths').value = repo.allowed_paths && repo.allowed_paths.length > 0 
                ? JSON.stringify(repo.allowed_paths, null, 2) 
                : '';
            document.getElementById('repo-readonly').checked = repo.read_only;
            document.getElementById('repo-maintenance').checked = repo.maintenance_lock;
            document.getElementById('repo-modal').classList.add('active');
        }

        async function deleteRepo(id) {
            if (!confirm('Delete this repository? This will not delete server files, only the CoderAI reference.')) return;

            try {
                await API.repos.delete(id);
                showToast('Repository deleted', 'success');
                await loadRepos();
            } catch (error) {
                showToast('Failed to delete: ' + error.message, 'error');
            }
        }

        function closeModal() {
            document.getElementById('repo-modal').classList.remove('active');
        }

        // Form submit
        document.getElementById('repo-form').addEventListener('submit', async (e) => {
            e.preventDefault();

            const id = document.getElementById('repo-id').value;
            const data = {
                label: document.getElementById('repo-label').value,
                read_only: document.getElementById('repo-readonly').checked ? 1 : 0,
                maintenance_lock: document.getElementById('repo-maintenance').checked ? 1 : 0
            };

            // Only include base_path for new repos
            if (!id) {
                data.base_path = document.getElementById('repo-path').value;
            }

            const allowedPathsText = document.getElementById('repo-allowed-paths').value.trim();
            if (allowedPathsText) {
                try {
                    data.allowed_paths = JSON.parse(allowedPathsText);
                } catch (e) {
                    showToast('Invalid JSON for allowed paths', 'error');
                    return;
                }
            }

            try {
                if (id) {
                    await API.repos.update(id, data);
                    showToast('Repository updated', 'success');
                } else {
                    await API.repos.create(data);
                    showToast('Repository created', 'success');
                }
                closeModal();
                await loadRepos();
            } catch (error) {
                showToast('Error: ' + error.message, 'error');
            }
        });

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.textContent = message;
            document.getElementById('toast-container').appendChild(toast);
            setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 4000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal on overlay click
        document.getElementById('repo-modal').addEventListener('click', (e) => {
            if (e.target.id === 'repo-modal') closeModal();
        });
        document.getElementById('validation-modal').addEventListener('click', (e) => {
            if (e.target.id === 'validation-modal') closeValidationModal();
        });
    </script>
</body>
</html>