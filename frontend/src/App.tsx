import { Routes, Route, Navigate } from 'react-router-dom';
import { useAuthStore } from './stores/authStore';
import { LoginPage } from './pages/LoginPage';
import { DashboardLayout } from './layouts/DashboardLayout';
import { WorkspacePage } from './pages/WorkspacePage';
import { ProjectPage } from './pages/ProjectPage';
import { ThreadPage } from './pages/ThreadPage';
import { SearchPage } from './pages/SearchPage';
import { SettingsPage } from './pages/SettingsPage';
import { AdminPage } from './pages/AdminPage';
import { TwoFactorPage } from './pages/TwoFactorPage';

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, requiresTwoFactor } = useAuthStore();

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (requiresTwoFactor) {
    return <Navigate to="/2fa" replace />;
  }

  return <>{children}</>;
}

function App() {
  const { isAuthenticated, requiresTwoFactor } = useAuthStore();

  return (
    <Routes>
      <Route
        path="/login"
        element={
          isAuthenticated && !requiresTwoFactor ? (
            <Navigate to="/" replace />
          ) : (
            <LoginPage />
          )
        }
      />
      <Route
        path="/2fa"
        element={
          !isAuthenticated ? (
            <Navigate to="/login" replace />
          ) : !requiresTwoFactor ? (
            <Navigate to="/" replace />
          ) : (
            <TwoFactorPage />
          )
        }
      />

      <Route
        path="/"
        element={
          <ProtectedRoute>
            <DashboardLayout />
          </ProtectedRoute>
        }
      >
        <Route index element={<Navigate to="/workspace/NORMAL" replace />} />
        <Route path="workspace/:type" element={<WorkspacePage />} />
        <Route path="workspace/:type/project/:projectId" element={<ProjectPage />} />
        <Route path="workspace/:type/project/:projectId/thread/:threadId" element={<ThreadPage />} />
        <Route path="search" element={<SearchPage />} />
        <Route path="settings" element={<SettingsPage />} />
        <Route path="admin/*" element={<AdminPage />} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

export default App;
