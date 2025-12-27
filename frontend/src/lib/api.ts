import axios from 'axios';

export const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true,
});

// Request interceptor for error handling
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Clear auth state and redirect to login
      localStorage.removeItem('auth-storage');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

// API helper functions
export const authApi = {
  login: (username: string, password: string) =>
    api.post('/auth/login', { username, password }),
  logout: () => api.post('/auth/logout'),
  me: () => api.get('/auth/me'),
  verifyTwoFactor: (code: string) => api.post('/auth/verify-2fa', { code }),
  setupTwoFactor: () => api.post('/auth/2fa/setup'),
  enableTwoFactor: (code: string) => api.post('/auth/2fa/enable', { code }),
  disableTwoFactor: (code: string) => api.post('/auth/2fa/disable', { code }),
  changePassword: (currentPassword: string, newPassword: string) =>
    api.post('/auth/change-password', { currentPassword, newPassword }),
  getSessions: () => api.get('/auth/sessions'),
  revokeSession: (sessionId: string) => api.delete(`/auth/sessions/${sessionId}`),
};

export const workspacesApi = {
  list: () => api.get('/workspaces'),
  get: (workspaceId: string) => api.get(`/workspaces/${workspaceId}`),
  getByType: (type: string) => api.get(`/workspaces/type/${type}`),
  getStats: (workspaceId: string) => api.get(`/workspaces/${workspaceId}/stats`),
};

export const projectsApi = {
  list: (workspaceId: string) => api.get(`/projects?workspaceId=${workspaceId}`),
  get: (projectId: string) => api.get(`/projects/${projectId}`),
  create: (data: { name: string; description?: string; workspaceId: string }) =>
    api.post('/projects', data),
  update: (projectId: string, data: { name?: string; description?: string; isArchived?: boolean }) =>
    api.put(`/projects/${projectId}`, data),
  delete: (projectId: string) => api.delete(`/projects/${projectId}`),
};

export const threadsApi = {
  list: (projectId: string) => api.get(`/threads?projectId=${projectId}`),
  get: (threadId: string) => api.get(`/threads/${threadId}`),
  create: (data: { projectId: string; title?: string }) => api.post('/threads', data),
  update: (threadId: string, data: { title?: string; isArchived?: boolean }) =>
    api.put(`/threads/${threadId}`, data),
};

export const messagesApi = {
  list: (threadId: string, limit = 50, offset = 0) =>
    api.get(`/messages?threadId=${threadId}&limit=${limit}&offset=${offset}`),
  create: (data: { threadId: string; content: string; role?: string }, generateResponse = true) =>
    api.post(`/messages?generateResponse=${generateResponse}`, data),
  delete: (messageId: string) => api.delete(`/messages/${messageId}`),
};

export const uploadsApi = {
  list: (workspaceId: string, projectId?: string) =>
    api.get(`/uploads?workspaceId=${workspaceId}${projectId ? `&projectId=${projectId}` : ''}`),
  get: (uploadId: string) => api.get(`/uploads/${uploadId}`),
  upload: (formData: FormData) =>
    api.post('/uploads', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
  delete: (uploadId: string) => api.delete(`/uploads/${uploadId}`),
  download: (uploadId: string) => api.get(`/uploads/${uploadId}/download`, { responseType: 'blob' }),
};

export const rulesetsApi = {
  list: (workspaceId: string) => api.get(`/rulesets?workspaceId=${workspaceId}`),
  get: (rulesetId: string) => api.get(`/rulesets/${rulesetId}`),
  create: (data: { name: string; description?: string; workspaceId: string; rules: Record<string, unknown> }) =>
    api.post('/rulesets', data),
  update: (rulesetId: string, data: { name?: string; description?: string; isActive?: boolean }) =>
    api.put(`/rulesets/${rulesetId}`, data),
  createVersion: (rulesetId: string, data: { rules: Record<string, unknown>; changelog?: string }) =>
    api.post(`/rulesets/${rulesetId}/versions`, data),
  lock: (rulesetId: string, reason?: string) =>
    api.post(`/rulesets/${rulesetId}/lock`, { reason }),
  unlock: (rulesetId: string) => api.post(`/rulesets/${rulesetId}/unlock`),
};

export const searchApi = {
  search: (query: string, options?: { workspaceId?: string; types?: string[]; limit?: number }) =>
    api.get('/search', {
      params: {
        query,
        workspaceId: options?.workspaceId,
        types: options?.types?.join(','),
        limit: options?.limit,
      },
    }),
};

export const auditApi = {
  list: (options?: { workspaceId?: string; limit?: number; offset?: number }) =>
    api.get('/audit', { params: options }),
  getStats: (days = 7) => api.get(`/audit/stats/summary?days=${days}`),
  getEntityHistory: (entityType: string, entityId: string) =>
    api.get(`/audit/entity/${entityType}/${entityId}`),
};

export const usersApi = {
  list: () => api.get('/users'),
  get: (userId: string) => api.get(`/users/${userId}`),
  create: (data: {
    username: string;
    password: string;
    displayName: string;
    email?: string;
    role?: 'ADMIN' | 'MEMBER';
    workspaceAccess?: Array<{
      workspaceType: 'NORMAL' | 'CHURCH' | 'CODER';
      canRead: boolean;
      canWrite: boolean;
      canAdmin: boolean;
    }>;
  }) => api.post('/users', data),
  update: (userId: string, data: { displayName?: string; email?: string; role?: string; isActive?: boolean }) =>
    api.put(`/users/${userId}`, data),
  resetPassword: (userId: string, newPassword: string) =>
    api.post(`/users/${userId}/reset-password`, { newPassword }),
  updateWorkspaceAccess: (userId: string, workspaceAccess: Array<{
    workspaceType: 'NORMAL' | 'CHURCH' | 'CODER';
    canRead: boolean;
    canWrite: boolean;
    canAdmin: boolean;
  }>) => api.put(`/users/${userId}/workspace-access`, { workspaceAccess }),
};

export const mockAIApi = {
  health: () => api.get('/mock-ai/health'),
  getModels: () => api.get('/mock-ai/models'),
  getGreeting: (workspaceType: string) => api.get(`/mock-ai/greeting/${workspaceType}`),
};
