import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Users, UserPlus, Shield, Loader2, Check, X, MoreVertical,
  Home, Church, Code2, Activity
} from 'lucide-react';
import { usersApi, auditApi } from '../lib/api';
import toast from 'react-hot-toast';

const workspaceTypes = [
  { type: 'NORMAL', label: 'General', icon: Home, color: 'sky' },
  { type: 'CHURCH', label: 'Church', icon: Church, color: 'fuchsia' },
  { type: 'CODER', label: 'Coder', icon: Code2, color: 'emerald' },
];

export function AdminPage() {
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('users');
  const [showCreateUser, setShowCreateUser] = useState(false);
  const [selectedUser, setSelectedUser] = useState<any>(null);

  // Form state
  const [newUser, setNewUser] = useState({
    username: '',
    password: '',
    displayName: '',
    email: '',
    role: 'MEMBER' as 'ADMIN' | 'MEMBER',
    workspaceAccess: [] as Array<{
      workspaceType: 'NORMAL' | 'CHURCH' | 'CODER';
      canRead: boolean;
      canWrite: boolean;
      canAdmin: boolean;
    }>,
  });

  const { data: users, isLoading } = useQuery({
    queryKey: ['users'],
    queryFn: usersApi.list,
  });

  const { data: auditStats } = useQuery({
    queryKey: ['audit-stats'],
    queryFn: () => auditApi.getStats(7),
  });

  const createUser = useMutation({
    mutationFn: () => usersApi.create(newUser),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      setShowCreateUser(false);
      setNewUser({
        username: '',
        password: '',
        displayName: '',
        email: '',
        role: 'MEMBER',
        workspaceAccess: [],
      });
      toast.success('User created successfully');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to create user');
    },
  });

  const updateUser = useMutation({
    mutationFn: ({ userId, data }: { userId: string; data: any }) =>
      usersApi.update(userId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      toast.success('User updated');
    },
  });

  const toggleWorkspaceAccess = (workspaceType: 'NORMAL' | 'CHURCH' | 'CODER') => {
    const existing = newUser.workspaceAccess.find((w) => w.workspaceType === workspaceType);
    if (existing) {
      setNewUser({
        ...newUser,
        workspaceAccess: newUser.workspaceAccess.filter((w) => w.workspaceType !== workspaceType),
      });
    } else {
      setNewUser({
        ...newUser,
        workspaceAccess: [
          ...newUser.workspaceAccess,
          { workspaceType, canRead: true, canWrite: true, canAdmin: false },
        ],
      });
    }
  };

  const handleCreateUser = (e: React.FormEvent) => {
    e.preventDefault();
    createUser.mutate();
  };

  return (
    <div className="h-full overflow-auto">
      <div className="max-w-6xl mx-auto p-6">
        <h1 className="text-2xl font-bold text-white mb-6">Admin Panel</h1>

        {/* Tabs */}
        <div className="flex gap-1 mb-6 bg-slate-800 rounded-lg p-1">
          <button
            onClick={() => setActiveTab('users')}
            className={`flex items-center gap-2 px-4 py-2 rounded-md transition-colors ${
              activeTab === 'users'
                ? 'bg-slate-700 text-white'
                : 'text-slate-400 hover:text-white'
            }`}
          >
            <Users size={18} />
            Users
          </button>
          <button
            onClick={() => setActiveTab('activity')}
            className={`flex items-center gap-2 px-4 py-2 rounded-md transition-colors ${
              activeTab === 'activity'
                ? 'bg-slate-700 text-white'
                : 'text-slate-400 hover:text-white'
            }`}
          >
            <Activity size={18} />
            Activity
          </button>
        </div>

        {/* Users Tab */}
        {activeTab === 'users' && (
          <div>
            <div className="flex items-center justify-between mb-6">
              <h2 className="text-lg font-semibold text-white">Family Members</h2>
              <button
                onClick={() => setShowCreateUser(true)}
                className="flex items-center gap-2 px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white rounded-lg transition-colors"
              >
                <UserPlus size={18} />
                Add User
              </button>
            </div>

            {isLoading ? (
              <div className="flex justify-center py-12">
                <Loader2 className="w-8 h-8 animate-spin text-slate-400" />
              </div>
            ) : (
              <div className="bg-slate-800 rounded-xl border border-slate-700 overflow-hidden">
                <table className="w-full">
                  <thead>
                    <tr className="border-b border-slate-700">
                      <th className="text-left px-4 py-3 text-sm font-medium text-slate-400">User</th>
                      <th className="text-left px-4 py-3 text-sm font-medium text-slate-400">Role</th>
                      <th className="text-left px-4 py-3 text-sm font-medium text-slate-400">Workspaces</th>
                      <th className="text-left px-4 py-3 text-sm font-medium text-slate-400">Status</th>
                      <th className="text-left px-4 py-3 text-sm font-medium text-slate-400">2FA</th>
                      <th className="w-12"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {users?.data?.users?.map((user: any) => (
                      <tr key={user.id} className="border-b border-slate-700/50 hover:bg-slate-700/30">
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded-full bg-gradient-to-br from-sky-400 to-emerald-400 flex items-center justify-center text-white font-semibold text-sm">
                              {user.displayName[0].toUpperCase()}
                            </div>
                            <div>
                              <div className="text-white font-medium">{user.displayName}</div>
                              <div className="text-sm text-slate-400">@{user.username}</div>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <span className={`inline-flex items-center gap-1 px-2 py-1 rounded text-xs ${
                            user.role === 'ADMIN'
                              ? 'bg-amber-500/20 text-amber-400'
                              : 'bg-slate-600 text-slate-300'
                          }`}>
                            {user.role === 'ADMIN' && <Shield size={12} />}
                            {user.role}
                          </span>
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex gap-1">
                            {user.workspaceAccess?.map((access: any) => {
                              const ws = workspaceTypes.find((w) => w.type === access.workspace.type);
                              if (!ws) return null;
                              return (
                                <span
                                  key={access.workspace.id}
                                  className={`inline-flex items-center gap-1 px-2 py-1 rounded text-xs bg-${ws.color}-500/20 text-${ws.color}-400`}
                                  title={`${ws.label}: ${access.canAdmin ? 'Admin' : access.canWrite ? 'Write' : 'Read'}`}
                                >
                                  <ws.icon size={12} />
                                </span>
                              );
                            })}
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <span className={`inline-flex items-center gap-1 px-2 py-1 rounded text-xs ${
                            user.isActive
                              ? 'bg-emerald-500/20 text-emerald-400'
                              : 'bg-red-500/20 text-red-400'
                          }`}>
                            {user.isActive ? <Check size={12} /> : <X size={12} />}
                            {user.isActive ? 'Active' : 'Inactive'}
                          </span>
                        </td>
                        <td className="px-4 py-3">
                          {user.twoFactorEnabled ? (
                            <Check size={18} className="text-emerald-400" />
                          ) : (
                            <X size={18} className="text-slate-500" />
                          )}
                        </td>
                        <td className="px-4 py-3">
                          <button
                            onClick={() => setSelectedUser(user)}
                            className="p-1 hover:bg-slate-600 rounded text-slate-400 hover:text-white"
                          >
                            <MoreVertical size={18} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {/* Activity Tab */}
        {activeTab === 'activity' && auditStats?.data && (
          <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div className="bg-slate-800 rounded-xl p-4 border border-slate-700">
                <div className="text-sm text-slate-400">Events (7 days)</div>
                <div className="text-3xl font-bold text-white mt-1">{auditStats.data.totalEvents}</div>
              </div>
              <div className="bg-slate-800 rounded-xl p-4 border border-slate-700">
                <div className="text-sm text-slate-400">Most Active User</div>
                <div className="text-xl font-bold text-white mt-1">
                  {auditStats.data.mostActiveUsers?.[0]?.user?.displayName || 'N/A'}
                </div>
              </div>
              <div className="bg-slate-800 rounded-xl p-4 border border-slate-700">
                <div className="text-sm text-slate-400">Top Action</div>
                <div className="text-xl font-bold text-white mt-1">
                  {auditStats.data.actionCounts?.[0]?.action || 'N/A'}
                </div>
              </div>
            </div>

            <div className="bg-slate-800 rounded-xl p-6 border border-slate-700">
              <h3 className="text-lg font-semibold text-white mb-4">Recent Activity by Type</h3>
              <div className="space-y-2">
                {auditStats.data.actionCounts?.map((action: any) => (
                  <div key={action.action} className="flex items-center justify-between">
                    <span className="text-slate-300">{action.action}</span>
                    <span className="text-white font-medium">{action.count}</span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}

        {/* Create User Modal */}
        {showCreateUser && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div className="bg-slate-800 rounded-xl p-6 w-full max-w-lg border border-slate-700 max-h-[90vh] overflow-auto">
              <h2 className="text-xl font-semibold text-white mb-4">Create New User</h2>
              <form onSubmit={handleCreateUser} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-slate-300 mb-1">Username</label>
                    <input
                      type="text"
                      value={newUser.username}
                      onChange={(e) => setNewUser({ ...newUser, username: e.target.value })}
                      className="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                      required
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-slate-300 mb-1">Password</label>
                    <input
                      type="password"
                      value={newUser.password}
                      onChange={(e) => setNewUser({ ...newUser, password: e.target.value })}
                      className="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                      required
                      minLength={8}
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-300 mb-1">Display Name</label>
                  <input
                    type="text"
                    value={newUser.displayName}
                    onChange={(e) => setNewUser({ ...newUser, displayName: e.target.value })}
                    className="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-300 mb-1">Email (optional)</label>
                  <input
                    type="email"
                    value={newUser.email}
                    onChange={(e) => setNewUser({ ...newUser, email: e.target.value })}
                    className="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-300 mb-1">Role</label>
                  <select
                    value={newUser.role}
                    onChange={(e) => setNewUser({ ...newUser, role: e.target.value as 'ADMIN' | 'MEMBER' })}
                    className="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                  >
                    <option value="MEMBER">Member</option>
                    <option value="ADMIN">Admin</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-300 mb-2">Workspace Access</label>
                  <div className="space-y-2">
                    {workspaceTypes.map((ws) => {
                      const hasAccess = newUser.workspaceAccess.some((w) => w.workspaceType === ws.type);
                      return (
                        <button
                          key={ws.type}
                          type="button"
                          onClick={() => toggleWorkspaceAccess(ws.type as any)}
                          className={`w-full flex items-center gap-3 p-3 rounded-lg border transition-colors ${
                            hasAccess
                              ? `bg-${ws.color}-500/20 border-${ws.color}-500/50 text-${ws.color}-400`
                              : 'bg-slate-700 border-slate-600 text-slate-400 hover:border-slate-500'
                          }`}
                        >
                          <ws.icon size={20} />
                          <span>{ws.label}</span>
                          {hasAccess && <Check size={18} className="ml-auto" />}
                        </button>
                      );
                    })}
                  </div>
                </div>
                <div className="flex justify-end gap-3 pt-4">
                  <button
                    type="button"
                    onClick={() => setShowCreateUser(false)}
                    className="px-4 py-2 text-slate-400 hover:text-white"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={createUser.isPending}
                    className="px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white rounded-lg disabled:opacity-50 flex items-center gap-2"
                  >
                    {createUser.isPending && <Loader2 size={16} className="animate-spin" />}
                    Create User
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
