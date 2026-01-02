/**
 * CoderAI API Client
 * ✅ FIXED: Send mode parameter correctly
 */

const API = {
    baseUrl: '/api',

    async request(method, endpoint, data = null) {
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin'
        };

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (csrfToken) {
            options.headers['X-CSRF-Token'] = csrfToken;
        }

        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(this.baseUrl + endpoint, options);
            const json = await response.json();

            if (!response.ok) {
                throw new Error(json.error || json.message || 'Request failed');
            }

            return json;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },

    get(endpoint) { 
        return this.request('GET', endpoint); 
    },
    
    post(endpoint, data) { 
        return this.request('POST', endpoint, data); 
    },
    
    put(endpoint, data) { 
        return this.request('PUT', endpoint, data); 
    },
    
    delete(endpoint) { 
        return this.request('DELETE', endpoint); 
    },

    // AUTH
    auth: {
        login(email, password) {
            return API.post('/auth/login', { email, password });
        },
        logout() { 
            return API.post('/auth/logout'); 
        },
        me() { 
            return API.get('/auth/me'); 
        },
        changePassword(currentPassword, newPassword) {
            return API.post('/auth/change-password', {
                current_password: currentPassword,
                new_password: newPassword
            });
        }
    },

    // WORKSPACES
    workspaces: {
        list() {
            return API.get('/workspaces');
        },
        get(id) {
            return API.get('/workspaces/' + id);
        }
    },

    // PROJECTS
    projects: {
        list(workspaceSlug = null) {
            const query = workspaceSlug ? '?workspace_slug=' + workspaceSlug : '';
            return API.get('/projects' + query);
        },
        get(id) { 
            return API.get('/projects/' + id); 
        },
        create(data) { 
            return API.post('/projects', data); 
        },
        update(id, data) { 
            return API.put('/projects/' + id, data); 
        },
        delete(id) { 
            return API.delete('/projects/' + id); 
        }
    },

    // THREADS
    threads: {
        list(projectId = null) {
            const query = projectId ? '?project_id=' + projectId : '';
            return API.get('/threads' + query);
        },
        get(id) { 
            return API.get('/threads/' + id); 
        },
        create(data) { 
            return API.post('/threads', data); 
        },
        update(id, data) { 
            return API.put('/threads/' + id, data); 
        },
        delete(id) { 
            return API.delete('/threads/' + id); 
        }
    },

    // MESSAGES
    messages: {
        list(threadId) {
            return API.get('/messages?thread_id=' + threadId);
        },

        /**
         * Send message with workspace and model
         * workspace: REQUIRED - ensures correct rules are loaded
         * model: optional - for coder workspace model selection
         */
        send(threadId, content, options = {}) {
            const data = {
                thread_id: threadId,
                content: content
            };
            // ALWAYS pass workspace to ensure correct rules
            if (options.workspace) {
                data.workspace = options.workspace;
            }
            // Pass model if specified (for coder workspace)
            if (options.model) {
                data.model = options.model;
            }
            return API.post('/messages', data);
        }
    },

    // REPOS
    repos: {
        list() { 
            return API.get('/repos'); 
        },
        get(id) { 
            return API.get('/repos/' + id); 
        },
        create(data) { 
            return API.post('/repos', data); 
        },
        update(id, data) { 
            return API.put('/repos/' + id, data); 
        },
        delete(id) { 
            return API.delete('/repos/' + id); 
        },
        files(id, path = '') {
            const query = path ? '?path=' + encodeURIComponent(path) : '';
            return API.get('/repos/' + id + '/files' + query);
        },
        validate(id) {
            return API.get('/repos/' + id + '/validate');
        }
    },

    // RUNS (Coder)
    runs: {
        list(threadId = null) {
            const query = threadId ? '?thread_id=' + threadId : '';
            return API.get('/runs' + query);
        },
        get(id) { 
            return API.get('/runs/' + id); 
        },
        create(threadId, request, options = {}) {
            return API.post('/runs', { 
                thread_id: threadId, 
                request: request,
                ...options
            });
        },
        plan(id) { 
            return API.post('/runs/' + id + '/plan'); 
        },
        code(id) { 
            return API.post('/runs/' + id + '/code'); 
        },
        review(id) { 
            return API.post('/runs/' + id + '/review'); 
        },
        apply(id) { 
            return API.post('/runs/' + id + '/apply'); 
        },
        rollback(id) { 
            return API.post('/runs/' + id + '/rollback'); 
        },
        cancel(id) { 
            return API.post('/runs/' + id + '/cancel'); 
        },
        queue(id, autoApply = false) { 
            return API.post('/runs/' + id + '/queue', { auto_apply: autoApply }); 
        }
    },

    // SETTINGS (Admin only)
    settings: {
        list() {
            return API.get('/settings');
        },
        get(key) {
            return API.get('/settings/' + key);
        },
        update(settings) {
            return API.post('/settings', settings);
        }
    },

    // USERS (Admin only)
    users: {
        list(page = 1, perPage = 20) {
            return API.get('/users?page=' + page + '&per_page=' + perPage);
        },
        get(id) {
            return API.get('/users/' + id);
        },
        create(data) {
            return API.post('/users', data);
        },
        update(id, data) {
            return API.put('/users/' + id, data);
        },
        delete(id) {
            return API.delete('/users/' + id);
        }
    },

    // QUEUE (Admin only)
    queue: {
        stats() {
            return API.get('/queue/stats');
        }
    },

    // USAGE
    usage: {
        stats(range = 'month') {
            return API.get('/usage/stats?range=' + range);
        },
        daily(days = 30) {
            return API.get('/usage/daily?days=' + days);
        },
        byModel(range = 'month') {
            return API.get('/usage/by-model?range=' + range);
        },
        byWorkspace(range = 'month') {
            return API.get('/usage/by-workspace?range=' + range);
        }
    },

    // MODELS
    models: {
        _cache: null,
        _cacheTime: 0,
        _cacheDuration: 5 * 60 * 1000,

        async getAvailable() {
            const now = Date.now();
            if (this._cache && (now - this._cacheTime) < this._cacheDuration) {
                return this._cache;
            }

            try {
                const response = await API.get('/models');
                this._cache = response.data;
                this._cacheTime = now;
                return response.data;
            } catch (error) {
                console.error('Failed to load models:', error);
                return this._getFallback();
            }
        },

        async getAllFlat() {
            const data = await this.getAvailable();
            return data.models || [];
        },

        async getGrouped() {
            const data = await this.getAvailable();
            return data.grouped || {};
        },

        async getCheap() {
            const models = await this.getAllFlat();
            return models.filter(m => m.tier === 'cheap');
        },

        async getPremium() {
            const models = await this.getAllFlat();
            return models.filter(m => m.tier === 'premium');
        },

        async getProviders() {
            const data = await this.getAvailable();
            return data.providers || { openai: false, anthropic: false };
        },

        async getRecommended(task = 'balanced') {
            const data = await this.getAvailable();
            return data.recommended?.[task] || 'qwen2.5-coder:7b';
        },

        clearCache() {
            this._cache = null;
            this._cacheTime = 0;
        },

        _getFallback() {
            return {
                providers: { ollama_gateway: true },
                models: [
                    { id: 'qwen2.5-coder:7b', name: 'Fast (7B)', provider: 'ollama_gateway', tier: 'fast' },
                    { id: 'qwen2.5-coder:14b', name: 'Precise (14B)', provider: 'ollama_gateway', tier: 'precise' }
                ],
                grouped: {
                    ollama_gateway: [
                        { id: 'qwen2.5-coder:7b', name: 'Fast (7B)', provider: 'ollama_gateway', tier: 'fast' },
                        { id: 'qwen2.5-coder:14b', name: 'Precise (14B)', provider: 'ollama_gateway', tier: 'precise' }
                    ]
                },
                defaults: {
                    normal_cheap: 'qwen2.5-coder:7b',
                    normal_rich: 'qwen2.5-coder:14b'
                },
                recommended: {
                    cheap: 'qwen2.5-coder:7b',
                    balanced: 'qwen2.5-coder:7b',
                    premium: 'qwen2.5-coder:14b',
                    coding: 'qwen2.5-coder:14b'
                }
            };
        }
    }
};

// UTILITY FUNCTIONS
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

function formatTime(timestamp) {
    if (!timestamp) return '';
    return new Date(timestamp).toLocaleTimeString([], { 
        hour: '2-digit', 
        minute: '2-digit' 
    });
}

function formatDate(timestamp) {
    if (!timestamp) return '';
    return new Date(timestamp).toLocaleDateString([], {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        showToast('Copied to clipboard', 'success');
        return true;
    } catch (err) {
        console.error('Failed to copy:', err);
        showToast('Failed to copy', 'error');
        return false;
    }
}

function formatNumber(num) {
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
}