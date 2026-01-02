/**
 * CoderAI Main Application
 * ✅ UPDATED: Model dropdown for CODER, fixed model for CHAT/CHURCH
 */

const App = {
    state: {
        user: null,
        currentWorkspace: 'normal',
        projects: [],
        currentProject: null,
        threads: [],
        currentThread: null,
        messages: [],
        isSending: false,
        activeDropdown: null,
        selectedModel: 'qwen2.5-coder:7b',  // Default for coder
        availableModels: [],
        workspaceRules: {}
    },

    elements: {},

    async init() {
        console.log('CoderAI starting...');
        this.cacheElements();
        this.bindEvents();

        // Restore workspace from localStorage (or default to 'normal')
        const savedWorkspace = localStorage.getItem('coderaiWorkspace');
        const workspace = (savedWorkspace && ['normal', 'church', 'coder'].includes(savedWorkspace))
            ? savedWorkspace
            : 'normal';

        this.state.currentWorkspace = workspace;

        // ALWAYS update tab UI (HTML no longer has hardcoded active)
        this.elements.workspaceTabs.forEach(tab => {
            tab.classList.toggle('active', tab.dataset.workspace === workspace);
        });

        // Update header info
        if (this.elements.currentChatInfo) {
            this.elements.currentChatInfo.textContent = workspace;
        }

        this.updateModelSelectionVisibility();
        console.log('Workspace set to:', workspace, '(from localStorage:', !!savedWorkspace, ')');

        await this.loadUser();
        await this.loadModels();
        await this.loadProjects();
        console.log('CoderAI ready!');
    },

    cacheElements() {
        this.elements = {
            sidebar: document.getElementById('sidebar'),
            sidebarOverlay: document.getElementById('sidebar-overlay'),
            menuToggle: document.getElementById('menu-toggle'),
            workspaceTabs: document.querySelectorAll('.workspace-tab'),
            newChatBtn: document.getElementById('new-chat-btn'),
            projectsList: document.getElementById('projects-list'),
            messagesArea: document.getElementById('messages-area'),
            messagesWrapper: document.getElementById('messages-wrapper'),
            welcomeScreen: document.getElementById('welcome-screen'),
            chatInput: document.getElementById('chat-input'),
            sendBtn: document.getElementById('send-btn'),
            currentChatTitle: document.getElementById('current-chat-title'),
            currentChatInfo: document.getElementById('current-chat-info'),
            toastContainer: document.getElementById('toast-container'),
            modelDropdownContainer: document.getElementById('model-dropdown-container'),
            modelSelect: document.getElementById('model-select'),
            modelIndicator: document.getElementById('model-indicator'),
            currentModelName: document.getElementById('current-model-name')
        };
    },

    bindEvents() {
        // Mobile menu
        this.elements.menuToggle?.addEventListener('click', () => this.toggleSidebar());
        this.elements.sidebarOverlay?.addEventListener('click', () => this.closeSidebar());

        // Workspace tabs
        this.elements.workspaceTabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                this.switchWorkspace(e.currentTarget.dataset.workspace);
            });
        });

        // New chat
        this.elements.newChatBtn?.addEventListener('click', () => this.showNewProjectModal());

        // Chat input
        this.elements.chatInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });

        this.elements.chatInput?.addEventListener('input', () => {
            this.autoResizeTextarea();
            this.updateSendButton();
        });

        this.elements.sendBtn?.addEventListener('click', () => this.sendMessage());

        // Model dropdown (only for coder workspace)
        this.elements.modelSelect?.addEventListener('change', (e) => {
            this.setModel(e.target.value);
        });

        // Logout
        document.getElementById('logout-btn')?.addEventListener('click', (e) => {
            e.preventDefault();
            this.logout();
        });

        // Modal
        document.querySelectorAll('.modal-close, [data-modal-close]').forEach(btn => {
            btn.addEventListener('click', () => this.closeAllModals());
        });

        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) this.closeAllModals();
            });
        });

        document.getElementById('project-form')?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.createProject();
        });

        // Quick actions
        document.querySelectorAll('.quick-action').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const prompt = e.currentTarget.dataset.prompt;
                if (prompt && this.elements.chatInput) {
                    this.elements.chatInput.value = prompt;
                    this.elements.chatInput.focus();
                    this.updateSendButton();
                }
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (this.state.activeDropdown && !e.target.closest('.project-menu-btn') && !e.target.closest('.thread-menu-btn')) {
                this.closeDropdown();
            }
        });
    },

    async loadUser() {
        try {
            const response = await API.auth.me();
            this.state.user = response.data;
        } catch (error) {
            console.error('Failed to load user:', error);
        }
    },

    async loadModels() {
        try {
            const modelData = await API.models.getAvailable();
            this.state.availableModels = modelData.models || [];
            this.state.workspaceModels = modelData.workspace_models || {};
            this.state.modelDefaults = modelData.defaults || {};
        } catch (error) {
            console.error('Failed to load models:', error);
            // Use fallback - 3 general for chat/church, 5 for coder
            this.state.workspaceModels = {
                normal: [
                    { id: 'qwen2.5:14b-instruct', name: 'Qwen 2.5 14B Instruct' },
                    { id: 'qwen2.5:32b', name: 'Qwen 2.5 32B' },
                    { id: 'mistral-small:24b', name: 'Mistral Small 24B' }
                ],
                church: [
                    { id: 'qwen2.5:14b-instruct', name: 'Qwen 2.5 14B Instruct' },
                    { id: 'qwen2.5:32b', name: 'Qwen 2.5 32B' },
                    { id: 'mistral-small:24b', name: 'Mistral Small 24B' }
                ],
                coder: [
                    { id: 'qwen2.5:14b-instruct', name: 'Qwen 2.5 14B Instruct' },
                    { id: 'qwen2.5:32b', name: 'Qwen 2.5 32B' },
                    { id: 'mistral-small:24b', name: 'Mistral Small 24B' },
                    { id: 'qwen2.5-coder:7b', name: 'Qwen 2.5 Coder 7B (Fast)' },
                    { id: 'qwen2.5-coder:14b', name: 'Qwen 2.5 Coder 14B (Precise)' }
                ]
            };
            this.state.modelDefaults = {
                normal: 'qwen2.5:14b-instruct',
                church: 'qwen2.5:14b-instruct',
                coder: 'qwen2.5-coder:7b'
            };
        }
        // Populate the dropdown for current workspace
        this.populateModelDropdown();
    },

    populateModelDropdown() {
        const select = this.elements.modelSelect;
        if (!select) return;

        // Get models for current workspace
        const workspace = this.state.currentWorkspace || 'normal';
        const models = this.state.workspaceModels?.[workspace] || [];

        select.innerHTML = '';
        models.forEach(model => {
            const option = document.createElement('option');
            option.value = model.id;
            option.textContent = model.name;
            select.appendChild(option);
        });

        // Set default for this workspace if no selection
        if (!this.state.selectedModel || !models.find(m => m.id === this.state.selectedModel)) {
            const defaultModel = this.state.modelDefaults?.[workspace] || models[0]?.id;
            this.state.selectedModel = defaultModel;
        }

        if (this.state.selectedModel) {
            select.value = this.state.selectedModel;
        }
    },

    setModel(modelId) {
        this.state.selectedModel = modelId;

        // Update dropdown if exists
        if (this.elements.modelSelect) {
            this.elements.modelSelect.value = modelId;
        }

        console.log('Model changed to:', modelId);
    },

    updateModelSelectionVisibility() {
        if (!this.elements.modelDropdownContainer) return;

        // Show dropdown for ALL workspaces - user can always select model
        this.elements.modelDropdownContainer.style.display = 'block';

        // Hide the fixed indicator if it exists
        if (this.elements.modelIndicator) {
            this.elements.modelIndicator.style.display = 'none';
        }
    },

    async switchWorkspace(slug) {
        console.log('🔄 switchWorkspace called with:', slug);
        console.log('🔄 Previous workspace was:', this.state.currentWorkspace);

        this.state.currentWorkspace = slug;

        // Save to localStorage so it persists on refresh
        localStorage.setItem('coderaiWorkspace', slug);
        console.log('🔄 Saved to localStorage:', localStorage.getItem('coderaiWorkspace'));

        this.elements.workspaceTabs.forEach(tab => {
            tab.classList.toggle('active', tab.dataset.workspace === slug);
        });

        this.elements.currentChatInfo.textContent = slug;
        console.log('🔄 State is now:', this.state.currentWorkspace);

        this.state.currentThread = null;

        // Update model dropdown for new workspace
        this.populateModelDropdown();

        this.updateModelSelectionVisibility();
        this.showWelcomeScreen();

        await this.loadProjects();
    },

    async loadProjects() {
        const container = this.elements.projectsList;
        if (!container) return;

        container.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--text-muted);"><div class="loading"><span></span><span></span><span></span></div></div>';

        try {
            const response = await API.projects.list(this.state.currentWorkspace);
            this.state.projects = response.data || [];
            this.renderProjects();
        } catch (error) {
            console.error('Failed to load projects:', error);
            this.state.projects = [];
            this.renderProjects();
        }
    },

    renderProjects() {
        const container = this.elements.projectsList;
        if (!container) return;

        if (this.state.projects.length === 0) {
            container.innerHTML = `
                <div style="padding: 20px; text-align: center; color: var(--text-muted);">
                    <p>No projects yet</p>
                    <button class="btn btn-primary" style="margin-top: 12px;" onclick="App.showNewProjectModal()">
                        Create Project
                    </button>
                </div>
            `;
            return;
        }

        container.innerHTML = this.state.projects.map(project => `
            <div class="project-item ${this.state.currentProject?.id === project.id ? 'active' : ''}" data-project-id="${project.id}">
                <div class="project-name" onclick="App.toggleProject(${project.id})" style="position: relative;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                    </svg>
                    <span style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${this.escapeHtml(project.name)}</span>
                    <button class="project-menu-btn" onclick="event.stopPropagation(); App.toggleProjectMenu(${project.id})">⋮</button>
                </div>
                <div class="project-threads" id="threads-${project.id}" style="display: none;"></div>
            </div>
        `).join('');
    },

    toggleProjectMenu(projectId) {
        const existingDropdown = document.querySelector('.project-dropdown');
        if (existingDropdown) {
            existingDropdown.remove();
        }

        if (this.state.activeDropdown === 'project-' + projectId) {
            this.state.activeDropdown = null;
            return;
        }

        const projectItem = document.querySelector(`[data-project-id="${projectId}"]`);
        const menuBtn = projectItem.querySelector('.project-menu-btn');

        const dropdown = document.createElement('div');
        dropdown.className = 'project-dropdown active';

        // Create buttons separately to ensure they render
        const renameBtn = document.createElement('button');
        renameBtn.textContent = 'Rename';
        renameBtn.onclick = (e) => { e.stopPropagation(); App.renameProject(projectId); };

        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'danger';
        deleteBtn.textContent = 'Delete';
        deleteBtn.onclick = (e) => { e.stopPropagation(); App.deleteProject(projectId); };

        dropdown.appendChild(renameBtn);
        dropdown.appendChild(deleteBtn);

        menuBtn.style.position = 'relative';
        menuBtn.appendChild(dropdown);
        this.state.activeDropdown = 'project-' + projectId;
    },

    async renameProject(projectId) {
        this.closeDropdown();
        const project = this.state.projects.find(p => p.id === projectId);
        if (!project) return;

        const newName = prompt('Enter new project name:', project.name);
        if (!newName || newName === project.name) return;

        try {
            await API.projects.update(projectId, { name: newName });
            await this.loadProjects();
            this.showToast('Project renamed!', 'success');
        } catch (error) {
            this.showToast('Failed to rename: ' + error.message, 'error');
        }
    },

    async deleteProject(projectId) {
        this.closeDropdown();
        if (!confirm('Delete this project and all its conversations?')) return;

        try {
            await API.projects.delete(projectId);
            if (this.state.currentProject?.id === projectId) {
                this.state.currentProject = null;
                this.state.currentThread = null;
                this.showWelcomeScreen();
            }
            await this.loadProjects();
            this.showToast('Project deleted', 'success');
        } catch (error) {
            this.showToast('Failed to delete: ' + error.message, 'error');
        }
    },

    closeDropdown() {
        const dropdown = document.querySelector('.project-dropdown');
        if (dropdown) dropdown.remove();
        this.state.activeDropdown = null;
    },

    async toggleProject(projectId) {
        const threadsContainer = document.getElementById('threads-' + projectId);
        const projectItem = threadsContainer?.parentElement;
        if (!threadsContainer) return;

        const isExpanded = threadsContainer.style.display !== 'none';

        document.querySelectorAll('.project-threads').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.project-item').forEach(el => el.classList.remove('active'));

        if (!isExpanded || this.state.currentProject?.id !== projectId) {
            await this.loadThreads(projectId);
            threadsContainer.style.display = 'block';
            projectItem.classList.add('active');
            this.state.currentProject = this.state.projects.find(p => p.id === projectId);
        } else {
            this.state.currentProject = null;
        }
    },

    async loadThreads(projectId) {
        const container = document.getElementById('threads-' + projectId);
        if (!container) return;

        try {
            const response = await API.threads.list(projectId);
            const threads = response.data || [];

            if (threads.length === 0) {
                container.innerHTML = `<div class="thread-item" onclick="App.createNewThread(${projectId})">+ New conversation</div>`;
            } else {
                container.innerHTML = threads.map(thread => `
                    <div class="thread-item ${this.state.currentThread?.id === thread.id ? 'active' : ''}" onclick="App.selectThread(${thread.id})" style="position: relative; display: flex; align-items: center;">
                        <span style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${this.escapeHtml(thread.title || 'Untitled')}</span>
                        <button class="thread-menu-btn" onclick="event.stopPropagation(); App.toggleThreadMenu(${thread.id})" style="position: absolute; right: 6px; top: 50%; transform: translateY(-50%);">⋮</button>
                    </div>
                `).join('') + `<div class="thread-item" style="color: var(--purple-light);" onclick="App.createNewThread(${projectId})">+ New conversation</div>`;
            }
        } catch (error) {
            console.error('Failed to load threads:', error);
            container.innerHTML = '<div style="padding: 10px; color: var(--text-muted);">Error loading</div>';
        }
    },

    toggleThreadMenu(threadId) {
        const existingDropdown = document.querySelector('.project-dropdown');
        if (existingDropdown) existingDropdown.remove();

        if (this.state.activeDropdown === 'thread-' + threadId) {
            this.state.activeDropdown = null;
            return;
        }

        const threadItem = document.querySelector(`[onclick*="selectThread(${threadId})"]`);
        const menuBtn = threadItem.querySelector('.thread-menu-btn');

        const dropdown = document.createElement('div');
        dropdown.className = 'project-dropdown active';
        dropdown.innerHTML = `
            <button onclick="App.renameThread(${threadId})">✏️ Rename</button>
            <button class="danger" onclick="App.deleteThread(${threadId})">🗑️ Delete</button>
        `;

        menuBtn.style.position = 'relative';
        menuBtn.appendChild(dropdown);
        this.state.activeDropdown = 'thread-' + threadId;
    },

    async renameThread(threadId) {
        this.closeDropdown();
        const newTitle = prompt('Enter new conversation title:');
        if (!newTitle) return;

        try {
            await API.threads.update(threadId, { title: newTitle });
            if (this.state.currentThread?.id === threadId) {
                this.state.currentThread.title = newTitle;
                this.elements.currentChatTitle.textContent = newTitle;
            }
            await this.loadThreads(this.state.currentProject.id);
            this.showToast('Conversation renamed!', 'success');
        } catch (error) {
            this.showToast('Failed to rename: ' + error.message, 'error');
        }
    },

    async deleteThread(threadId) {
        this.closeDropdown();
        if (!confirm('Delete this conversation?')) return;

        try {
            await API.threads.delete(threadId);
            if (this.state.currentThread?.id === threadId) {
                this.state.currentThread = null;
                this.showWelcomeScreen();
            }
            await this.loadThreads(this.state.currentProject.id);
            this.showToast('Conversation deleted', 'success');
        } catch (error) {
            this.showToast('Failed to delete: ' + error.message, 'error');
        }
    },

    async selectThread(threadId) {
        try {
            const response = await API.threads.get(threadId);
            this.state.currentThread = response.data.thread;
            this.state.messages = response.data.messages || [];

            this.elements.currentChatTitle.textContent = this.state.currentThread.title || 'New Chat';
            this.renderMessages();

            document.querySelectorAll('.thread-item').forEach(el => el.classList.remove('active'));
            document.querySelector(`[onclick*="selectThread(${threadId})"]`)?.classList.add('active');

            this.elements.chatInput?.focus();
            this.closeSidebar();

        } catch (error) {
            console.error('Failed to load thread:', error);
            this.showToast('Failed to load conversation', 'error');
        }
    },

    async createNewThread(projectId) {
        try {
            const response = await API.threads.create({ project_id: projectId, title: 'New Chat' });
            await this.loadThreads(projectId);
            await this.selectThread(response.data.id);
        } catch (error) {
            console.error('Failed to create thread:', error);
            this.showToast('Failed to create conversation', 'error');
        }
    },

    renderMessages() {
        if (!this.elements.messagesWrapper) return;

        if (this.elements.welcomeScreen) {
            this.elements.welcomeScreen.style.display = 'none';
        }

        if (this.state.messages.length === 0) {
            this.elements.messagesWrapper.innerHTML = `
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; text-align: center; color: var(--text-muted);">
                    <h2>Start a conversation</h2>
                    <p>Type a message below</p>
                </div>
            `;
            return;
        }

        this.elements.messagesWrapper.innerHTML = this.state.messages.map(msg => {
            const isStreaming = msg.isStreaming === true;
            const streamingClass = isStreaming ? ' streaming' : '';
            const bodyClass = isStreaming ? 'message-body streaming-content' : 'message-body';

            return `
                <div class="message ${msg.role}${streamingClass}">
                    <div class="message-avatar">${msg.role === 'user' ? 'U' : 'AI'}</div>
                    <div class="message-content">
                        <div class="message-header">
                            <span class="message-sender">${msg.role === 'user' ? 'You' : 'CoderAI'}</span>
                            <span class="message-time">${this.formatTime(msg.created_at)}</span>
                            ${msg.model ? `<span class="message-model">${msg.model}</span>` : ''}
                        </div>
                        <div class="${bodyClass}">${this.formatMessage(msg.content)}</div>
                    </div>
                </div>
            `;
        }).join('');

        this.scrollToBottom();
    },

    async sendMessage() {
        const content = this.elements.chatInput?.value?.trim();
        if (!content || this.state.isSending) return;

        // Auto-create project and thread if needed
        if (!this.state.currentThread) {
            if (!this.state.currentProject) {
                try {
                    const projResponse = await API.projects.create({
                        workspace_slug: this.state.currentWorkspace,
                        name: 'Quick Chat'
                    });
                    await this.loadProjects();
                    this.state.currentProject = { id: projResponse.data.id };
                } catch (e) {
                    this.showToast('Create a project first', 'error');
                    return;
                }
            }
            await this.createNewThread(this.state.currentProject.id);
        }

        this.state.isSending = true;
        this.elements.chatInput.value = '';
        this.autoResizeTextarea();
        this.updateSendButton();

        // Add user message optimistically
        this.state.messages.push({ role: 'user', content: content, created_at: new Date().toISOString() });
        this.renderMessages();

        // Add empty assistant message for streaming
        const streamingMessage = {
            role: 'assistant',
            content: '',
            created_at: new Date().toISOString(),
            model: '',
            isStreaming: true
        };
        this.state.messages.push(streamingMessage);
        this.renderMessages();

        const options = {
            workspace: this.state.currentWorkspace,
            model: this.state.selectedModel
        };
        console.log('📤 Sending streaming message with options:', options);

        try {
            await API.messages.sendStream(
                this.state.currentThread.id,
                content,
                options,
                {
                    onStart: (data) => {
                        console.log('🚀 Stream started:', data);
                        streamingMessage.model = data.model;
                    },
                    onChunk: (chunk, fullContent) => {
                        // Update the streaming message content
                        streamingMessage.content = fullContent;
                        this.updateStreamingMessage(streamingMessage);
                    },
                    onDone: async (data, fullContent) => {
                        console.log('✅ Stream done:', data);
                        streamingMessage.content = fullContent;
                        streamingMessage.model = data.model;
                        streamingMessage.tokens = data.tokens;
                        streamingMessage.isStreaming = false;
                        this.renderMessages();

                        // Update thread title if needed
                        if (this.state.messages.length === 2) {
                            const threadResponse = await API.threads.get(this.state.currentThread.id);
                            this.state.currentThread.title = threadResponse.data.thread.title;
                            this.elements.currentChatTitle.textContent = this.state.currentThread.title;
                            await this.loadThreads(this.state.currentProject.id);
                        }
                    },
                    onError: (error) => {
                        console.error('❌ Stream error:', error);
                        // Remove the streaming message
                        this.state.messages.pop();
                        this.showToast('AI error: ' + error, 'error');
                        this.renderMessages();
                    }
                }
            );
        } catch (error) {
            console.error('Stream failed:', error);

            // Remove streaming message on error
            this.state.messages.pop();

            if (error.message?.includes('budget') || error.message?.includes('429')) {
                this.showBudgetBanner('blocked', error.message, 100);
                this.state.messages.pop(); // Also remove user message
            } else if (error.message?.includes('unavailable') || error.message?.includes('offline')) {
                this.showToast('AI is currently unavailable. Please try again later.', 'error');
                this.state.messages.pop();
            } else {
                this.showToast('Failed: ' + error.message, 'error');
                this.state.messages.pop();
            }

            this.renderMessages();
        } finally {
            this.state.isSending = false;
        }
    },

    // Update streaming message in real-time
    updateStreamingMessage(msg) {
        const messagesWrapper = this.elements.messagesWrapper;
        if (!messagesWrapper) return;

        // Find the streaming message element (created by renderMessages with .streaming class)
        let streamEl = messagesWrapper.querySelector('.message.streaming');

        if (streamEl) {
            // Update content
            const contentEl = streamEl.querySelector('.streaming-content');
            if (contentEl) {
                contentEl.innerHTML = this.formatMessage(msg.content);
            }

            // Update model if available
            const modelEl = streamEl.querySelector('.message-model');
            if (modelEl && msg.model) {
                modelEl.textContent = msg.model;
            }

            this.scrollToBottom();
        }
    },

    showBudgetBanner(level, message, percent) {
        // Remove existing banner
        const existing = document.getElementById('budget-banner');
        if (existing) existing.remove();

        const banner = document.createElement('div');
        banner.id = 'budget-banner';
        banner.className = 'budget-banner ' + level;
        
        let icon = '💰';
        if (level === 'critical') icon = '⚠️';
        if (level === 'blocked') icon = '🚫';

        banner.innerHTML = `
            <div class="budget-banner-content">
                <span class="budget-banner-icon">${icon}</span>
                <span class="budget-banner-message">${this.escapeHtml(message)}</span>
                <span class="budget-banner-percent">${Math.round(percent)}%</span>
            </div>
            <button class="budget-banner-close" onclick="this.parentElement.remove()">×</button>
        `;

        // Insert at top of main content
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.insertBefore(banner, mainContent.firstChild);
        }

        // Auto-hide warning banners after 10 seconds (not blocked)
        if (level === 'warning') {
            setTimeout(() => {
                if (banner.parentElement) {
                    banner.style.opacity = '0';
                    setTimeout(() => banner.remove(), 300);
                }
            }, 10000);
        }
    },

    showTypingIndicator() {
        const indicator = document.createElement('div');
        indicator.className = 'message assistant';
        indicator.id = 'typing-indicator';
        indicator.innerHTML = `<div class="message-avatar">AI</div><div class="message-content"><div class="typing-indicator"><span></span><span></span><span></span></div></div>`;
        this.elements.messagesWrapper?.appendChild(indicator);
        this.scrollToBottom();
    },

    hideTypingIndicator() {
        document.getElementById('typing-indicator')?.remove();
    },

    showWelcomeScreen() {
        if (this.elements.welcomeScreen) this.elements.welcomeScreen.style.display = 'flex';
        if (this.elements.messagesWrapper) this.elements.messagesWrapper.innerHTML = '';
        this.elements.currentChatTitle.textContent = 'CoderAI';
    },

    showNewProjectModal() {
        document.getElementById('new-project-modal')?.classList.add('active');
        document.getElementById('project-name')?.focus();
    },

    async createProject() {
        const nameInput = document.getElementById('project-name');
        const name = nameInput?.value?.trim();
        if (!name) return;

        try {
            const response = await API.projects.create({
                workspace_slug: this.state.currentWorkspace,
                name: name
            });

            nameInput.value = '';
            this.closeAllModals();
            await this.loadProjects();

            if (response.data?.id) {
                await this.toggleProject(response.data.id);
            }
            this.showToast('Project created!', 'success');

        } catch (error) {
            console.error('Failed to create project:', error);
            this.showToast('Failed: ' + error.message, 'error');
        }
    },

    closeAllModals() {
        document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
    },

    toggleSidebar() {
        this.elements.sidebar?.classList.toggle('open');
        this.elements.sidebarOverlay?.classList.toggle('active');
    },

    closeSidebar() {
        this.elements.sidebar?.classList.remove('open');
        this.elements.sidebarOverlay?.classList.remove('active');
    },

    async logout() {
        try {
            await API.auth.logout();
        } catch (e) {}
        window.location.href = '/login';
    },

    autoResizeTextarea() {
        const ta = this.elements.chatInput;
        if (ta) {
            ta.style.height = 'auto';
            ta.style.height = Math.min(ta.scrollHeight, 200) + 'px';
        }
    },

    updateSendButton() {
        const hasContent = this.elements.chatInput?.value?.trim().length > 0;
        if (this.elements.sendBtn) {
            this.elements.sendBtn.disabled = !hasContent || this.state.isSending;
        }
    },

    scrollToBottom() {
        if (this.elements.messagesArea) {
            this.elements.messagesArea.scrollTop = this.elements.messagesArea.scrollHeight;
        }
    },

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = 'toast ' + type;
        toast.textContent = message;
        this.elements.toastContainer?.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 4000);
    },

    formatMessage(content) {
        if (!content) return '';
        let html = this.escapeHtml(content);
        html = html.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>');
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\n/g, '<br>');
        return '<p>' + html + '</p>';
    },

    formatTime(ts) {
        if (!ts) return '';
        return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());