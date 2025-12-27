import { Outlet, NavLink, useNavigate } from 'react-router-dom';
import {
  Home, Church, Code2, Search, Settings, LogOut,
  Menu, X, Users, ChevronDown
} from 'lucide-react';
import { useState } from 'react';
import { useAuthStore } from '../stores/authStore';

const workspaces = [
  { type: 'NORMAL', name: 'General', icon: Home, color: 'text-sky-400' },
  { type: 'CHURCH', name: 'Church', icon: Church, color: 'text-fuchsia-400' },
  { type: 'CODER', name: 'Coder', icon: Code2, color: 'text-emerald-400' },
];

export function DashboardLayout() {
  const navigate = useNavigate();
  const { user, logout } = useAuthStore();
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [userMenuOpen, setUserMenuOpen] = useState(false);

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  return (
    <div className="flex h-screen bg-slate-900">
      {/* Sidebar */}
      <aside
        className={`${
          sidebarOpen ? 'w-64' : 'w-16'
        } bg-slate-800 border-r border-slate-700 flex flex-col transition-all duration-200`}
      >
        {/* Logo */}
        <div className="h-16 flex items-center justify-between px-4 border-b border-slate-700">
          {sidebarOpen && (
            <span className="text-xl font-bold bg-gradient-to-r from-sky-400 to-emerald-400 bg-clip-text text-transparent">
              CoderAI
            </span>
          )}
          <button
            onClick={() => setSidebarOpen(!sidebarOpen)}
            className="p-2 rounded-lg hover:bg-slate-700 text-slate-400"
          >
            {sidebarOpen ? <X size={20} /> : <Menu size={20} />}
          </button>
        </div>

        {/* Workspaces */}
        <nav className="flex-1 py-4 px-2 space-y-1">
          {sidebarOpen && (
            <span className="px-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">
              Workspaces
            </span>
          )}
          {workspaces.map((ws) => (
            <NavLink
              key={ws.type}
              to={`/workspace/${ws.type}`}
              className={({ isActive }) =>
                `flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors ${
                  isActive
                    ? 'bg-slate-700/50 text-white'
                    : 'text-slate-400 hover:bg-slate-700/30 hover:text-white'
                }`
              }
            >
              <ws.icon size={20} className={ws.color} />
              {sidebarOpen && <span>{ws.name}</span>}
            </NavLink>
          ))}

          <div className="my-4 border-t border-slate-700" />

          {sidebarOpen && (
            <span className="px-3 text-xs font-semibold text-slate-500 uppercase tracking-wider">
              Tools
            </span>
          )}

          <NavLink
            to="/search"
            className={({ isActive }) =>
              `flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors ${
                isActive
                  ? 'bg-slate-700/50 text-white'
                  : 'text-slate-400 hover:bg-slate-700/30 hover:text-white'
              }`
            }
          >
            <Search size={20} />
            {sidebarOpen && <span>Search</span>}
          </NavLink>

          <NavLink
            to="/settings"
            className={({ isActive }) =>
              `flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors ${
                isActive
                  ? 'bg-slate-700/50 text-white'
                  : 'text-slate-400 hover:bg-slate-700/30 hover:text-white'
              }`
            }
          >
            <Settings size={20} />
            {sidebarOpen && <span>Settings</span>}
          </NavLink>

          {user?.role === 'ADMIN' && (
            <NavLink
              to="/admin"
              className={({ isActive }) =>
                `flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors ${
                  isActive
                    ? 'bg-slate-700/50 text-white'
                    : 'text-slate-400 hover:bg-slate-700/30 hover:text-white'
                }`
              }
            >
              <Users size={20} />
              {sidebarOpen && <span>Admin</span>}
            </NavLink>
          )}
        </nav>

        {/* User section */}
        <div className="p-3 border-t border-slate-700">
          <div className="relative">
            <button
              onClick={() => setUserMenuOpen(!userMenuOpen)}
              className="w-full flex items-center gap-3 p-2 rounded-lg hover:bg-slate-700/50 transition-colors"
            >
              <div className="w-8 h-8 rounded-full bg-gradient-to-br from-sky-400 to-emerald-400 flex items-center justify-center text-white font-semibold text-sm">
                {user?.displayName?.[0]?.toUpperCase() || 'U'}
              </div>
              {sidebarOpen && (
                <>
                  <div className="flex-1 text-left">
                    <div className="text-sm font-medium text-white truncate">
                      {user?.displayName}
                    </div>
                    <div className="text-xs text-slate-400">
                      {user?.role}
                    </div>
                  </div>
                  <ChevronDown size={16} className="text-slate-400" />
                </>
              )}
            </button>

            {userMenuOpen && (
              <div className="absolute bottom-full left-0 right-0 mb-2 bg-slate-700 rounded-lg shadow-lg border border-slate-600 overflow-hidden">
                <button
                  onClick={handleLogout}
                  className="w-full flex items-center gap-2 px-4 py-3 text-left text-red-400 hover:bg-slate-600 transition-colors"
                >
                  <LogOut size={18} />
                  <span>Sign out</span>
                </button>
              </div>
            )}
          </div>
        </div>
      </aside>

      {/* Main content */}
      <main className="flex-1 overflow-auto">
        <Outlet />
      </main>
    </div>
  );
}
