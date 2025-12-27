import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  User, Shield, Key, Monitor, Loader2, Check, X, QrCode
} from 'lucide-react';
import { useAuthStore } from '../stores/authStore';
import { authApi } from '../lib/api';
import toast from 'react-hot-toast';

export function SettingsPage() {
  const { user, fetchUser } = useAuthStore();
  const queryClient = useQueryClient();

  const [activeTab, setActiveTab] = useState('profile');
  const [showPasswordChange, setShowPasswordChange] = useState(false);
  const [show2FASetup, setShow2FASetup] = useState(false);
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [twoFactorCode, setTwoFactorCode] = useState('');
  const [qrCode, setQrCode] = useState('');

  const { data: sessions } = useQuery({
    queryKey: ['sessions'],
    queryFn: authApi.getSessions,
  });

  const changePassword = useMutation({
    mutationFn: () => authApi.changePassword(currentPassword, newPassword),
    onSuccess: () => {
      toast.success('Password changed successfully');
      setShowPasswordChange(false);
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to change password');
    },
  });

  const setup2FA = useMutation({
    mutationFn: authApi.setupTwoFactor,
    onSuccess: (response) => {
      setQrCode(response.data.qrCode);
      setShow2FASetup(true);
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to setup 2FA');
    },
  });

  const enable2FA = useMutation({
    mutationFn: (code: string) => authApi.enableTwoFactor(code),
    onSuccess: () => {
      toast.success('Two-factor authentication enabled');
      setShow2FASetup(false);
      setTwoFactorCode('');
      fetchUser();
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Invalid code');
    },
  });

  const disable2FA = useMutation({
    mutationFn: (code: string) => authApi.disableTwoFactor(code),
    onSuccess: () => {
      toast.success('Two-factor authentication disabled');
      setTwoFactorCode('');
      fetchUser();
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Invalid code');
    },
  });

  const revokeSession = useMutation({
    mutationFn: (sessionId: string) => authApi.revokeSession(sessionId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sessions'] });
      toast.success('Session revoked');
    },
  });

  const handlePasswordSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (newPassword !== confirmPassword) {
      toast.error('Passwords do not match');
      return;
    }
    if (newPassword.length < 8) {
      toast.error('Password must be at least 8 characters');
      return;
    }
    changePassword.mutate();
  };

  const tabs = [
    { id: 'profile', label: 'Profile', icon: User },
    { id: 'security', label: 'Security', icon: Shield },
    { id: 'sessions', label: 'Sessions', icon: Monitor },
  ];

  return (
    <div className="h-full overflow-auto">
      <div className="max-w-4xl mx-auto p-6">
        <h1 className="text-2xl font-bold text-white mb-6">Settings</h1>

        {/* Tabs */}
        <div className="flex gap-1 mb-6 bg-slate-800 rounded-lg p-1">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`flex items-center gap-2 px-4 py-2 rounded-md transition-colors ${
                activeTab === tab.id
                  ? 'bg-slate-700 text-white'
                  : 'text-slate-400 hover:text-white'
              }`}
            >
              <tab.icon size={18} />
              {tab.label}
            </button>
          ))}
        </div>

        {/* Profile Tab */}
        {activeTab === 'profile' && (
          <div className="bg-slate-800 rounded-xl p-6 border border-slate-700">
            <h2 className="text-lg font-semibold text-white mb-4">Profile Information</h2>
            <div className="space-y-4">
              <div className="flex items-center gap-4">
                <div className="w-16 h-16 rounded-full bg-gradient-to-br from-sky-400 to-emerald-400 flex items-center justify-center text-white text-2xl font-bold">
                  {user?.displayName?.[0]?.toUpperCase()}
                </div>
                <div>
                  <h3 className="text-xl font-medium text-white">{user?.displayName}</h3>
                  <p className="text-slate-400">@{user?.username}</p>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4 mt-6">
                <div>
                  <label className="block text-sm font-medium text-slate-400 mb-1">Email</label>
                  <div className="text-white">{user?.email || 'Not set'}</div>
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-400 mb-1">Role</label>
                  <div className="text-white">{user?.role}</div>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Security Tab */}
        {activeTab === 'security' && (
          <div className="space-y-6">
            {/* Password */}
            <div className="bg-slate-800 rounded-xl p-6 border border-slate-700">
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-3">
                  <Key size={20} className="text-slate-400" />
                  <div>
                    <h3 className="font-medium text-white">Password</h3>
                    <p className="text-sm text-slate-400">Change your account password</p>
                  </div>
                </div>
                <button
                  onClick={() => setShowPasswordChange(!showPasswordChange)}
                  className="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors"
                >
                  Change Password
                </button>
              </div>

              {showPasswordChange && (
                <form onSubmit={handlePasswordSubmit} className="mt-4 space-y-4 border-t border-slate-700 pt-4">
                  <div>
                    <label className="block text-sm font-medium text-slate-300 mb-1">Current Password</label>
                    <input
                      type="password"
                      value={currentPassword}
                      onChange={(e) => setCurrentPassword(e.target.value)}
                      className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                      required
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-slate-300 mb-1">New Password</label>
                    <input
                      type="password"
                      value={newPassword}
                      onChange={(e) => setNewPassword(e.target.value)}
                      className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                      required
                      minLength={8}
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-slate-300 mb-1">Confirm New Password</label>
                    <input
                      type="password"
                      value={confirmPassword}
                      onChange={(e) => setConfirmPassword(e.target.value)}
                      className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white"
                      required
                    />
                  </div>
                  <div className="flex justify-end gap-3">
                    <button
                      type="button"
                      onClick={() => setShowPasswordChange(false)}
                      className="px-4 py-2 text-slate-400 hover:text-white"
                    >
                      Cancel
                    </button>
                    <button
                      type="submit"
                      disabled={changePassword.isPending}
                      className="px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white rounded-lg disabled:opacity-50"
                    >
                      {changePassword.isPending ? <Loader2 size={18} className="animate-spin" /> : 'Update'}
                    </button>
                  </div>
                </form>
              )}
            </div>

            {/* 2FA */}
            <div className="bg-slate-800 rounded-xl p-6 border border-slate-700">
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-3">
                  <Shield size={20} className="text-slate-400" />
                  <div>
                    <h3 className="font-medium text-white">Two-Factor Authentication</h3>
                    <p className="text-sm text-slate-400">
                      {user?.twoFactorEnabled ? 'Enabled' : 'Add an extra layer of security'}
                    </p>
                  </div>
                </div>
                {user?.twoFactorEnabled ? (
                  <span className="flex items-center gap-1 text-emerald-400">
                    <Check size={18} />
                    Enabled
                  </span>
                ) : (
                  <button
                    onClick={() => setup2FA.mutate()}
                    disabled={setup2FA.isPending}
                    className="px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white rounded-lg transition-colors disabled:opacity-50"
                  >
                    {setup2FA.isPending ? <Loader2 size={18} className="animate-spin" /> : 'Enable 2FA'}
                  </button>
                )}
              </div>

              {show2FASetup && qrCode && (
                <div className="mt-4 space-y-4 border-t border-slate-700 pt-4">
                  <p className="text-slate-300">Scan this QR code with your authenticator app:</p>
                  <div className="flex justify-center">
                    <img src={qrCode} alt="2FA QR Code" className="rounded-lg" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-slate-300 mb-1">Enter verification code</label>
                    <input
                      type="text"
                      value={twoFactorCode}
                      onChange={(e) => setTwoFactorCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                      className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white text-center text-xl tracking-widest"
                      maxLength={6}
                      placeholder="000000"
                    />
                  </div>
                  <div className="flex justify-end gap-3">
                    <button
                      onClick={() => setShow2FASetup(false)}
                      className="px-4 py-2 text-slate-400 hover:text-white"
                    >
                      Cancel
                    </button>
                    <button
                      onClick={() => enable2FA.mutate(twoFactorCode)}
                      disabled={twoFactorCode.length !== 6 || enable2FA.isPending}
                      className="px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white rounded-lg disabled:opacity-50"
                    >
                      {enable2FA.isPending ? <Loader2 size={18} className="animate-spin" /> : 'Verify & Enable'}
                    </button>
                  </div>
                </div>
              )}

              {user?.twoFactorEnabled && !show2FASetup && (
                <div className="mt-4 space-y-4 border-t border-slate-700 pt-4">
                  <p className="text-slate-300">Enter your 2FA code to disable:</p>
                  <div className="flex gap-3">
                    <input
                      type="text"
                      value={twoFactorCode}
                      onChange={(e) => setTwoFactorCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                      className="flex-1 px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white text-center text-xl tracking-widest"
                      maxLength={6}
                      placeholder="000000"
                    />
                    <button
                      onClick={() => disable2FA.mutate(twoFactorCode)}
                      disabled={twoFactorCode.length !== 6 || disable2FA.isPending}
                      className="px-4 py-2 bg-red-500 hover:bg-red-400 text-white rounded-lg disabled:opacity-50"
                    >
                      {disable2FA.isPending ? <Loader2 size={18} className="animate-spin" /> : 'Disable 2FA'}
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* Sessions Tab */}
        {activeTab === 'sessions' && (
          <div className="bg-slate-800 rounded-xl p-6 border border-slate-700">
            <h2 className="text-lg font-semibold text-white mb-4">Active Sessions</h2>
            <div className="space-y-3">
              {sessions?.data?.sessions?.map((session: any) => (
                <div
                  key={session.id}
                  className="flex items-center justify-between p-4 bg-slate-700/50 rounded-lg"
                >
                  <div className="flex items-center gap-3">
                    <Monitor size={20} className="text-slate-400" />
                    <div>
                      <div className="text-white">{session.userAgent || 'Unknown device'}</div>
                      <div className="text-sm text-slate-400">
                        {session.ipAddress} · Last active {new Date(session.lastActiveAt).toLocaleDateString()}
                      </div>
                    </div>
                  </div>
                  <button
                    onClick={() => revokeSession.mutate(session.id)}
                    className="p-2 hover:bg-slate-600 rounded-lg text-red-400 hover:text-red-300 transition-colors"
                    title="Revoke session"
                  >
                    <X size={18} />
                  </button>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
