import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { api } from '../lib/api';

interface User {
  id: string;
  username: string;
  displayName: string;
  email?: string;
  role: 'ADMIN' | 'MEMBER';
  twoFactorEnabled: boolean;
}

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  requiresTwoFactor: boolean;
  login: (username: string, password: string) => Promise<void>;
  verifyTwoFactor: (code: string) => Promise<void>;
  logout: () => Promise<void>;
  fetchUser: () => Promise<void>;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      isAuthenticated: false,
      requiresTwoFactor: false,

      login: async (username: string, password: string) => {
        const response = await api.post('/auth/login', { username, password });
        const { user, token, requiresTwoFactor } = response.data;

        set({
          user,
          token,
          isAuthenticated: true,
          requiresTwoFactor: requiresTwoFactor || false,
        });

        if (token) {
          api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
        }
      },

      verifyTwoFactor: async (code: string) => {
        await api.post('/auth/verify-2fa', { code });
        set({ requiresTwoFactor: false });
      },

      logout: async () => {
        try {
          await api.post('/auth/logout');
        } catch {
          // Ignore logout errors
        }

        delete api.defaults.headers.common['Authorization'];
        set({
          user: null,
          token: null,
          isAuthenticated: false,
          requiresTwoFactor: false,
        });
      },

      fetchUser: async () => {
        try {
          const response = await api.get('/auth/me');
          set({ user: response.data.user });
        } catch {
          get().logout();
        }
      },
    }),
    {
      name: 'auth-storage',
      partialize: (state) => ({
        token: state.token,
        isAuthenticated: state.isAuthenticated,
        requiresTwoFactor: state.requiresTwoFactor,
      }),
      onRehydrateStorage: () => (state) => {
        if (state?.token) {
          api.defaults.headers.common['Authorization'] = `Bearer ${state.token}`;
        }
      },
    }
  )
);
