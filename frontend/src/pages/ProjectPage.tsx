import { useParams, Link, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Plus, MessageSquare, ArrowLeft, Loader2, Clock,
  Settings, MoreVertical
} from 'lucide-react';
import { useState } from 'react';
import { projectsApi, threadsApi } from '../lib/api';
import toast from 'react-hot-toast';

export function ProjectPage() {
  const { type, projectId } = useParams<{ type: string; projectId: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [showMenu, setShowMenu] = useState(false);

  const { data: project, isLoading } = useQuery({
    queryKey: ['project', projectId],
    queryFn: () => projectsApi.get(projectId!),
    enabled: !!projectId,
  });

  const { data: threads } = useQuery({
    queryKey: ['threads', projectId],
    queryFn: () => threadsApi.list(projectId!),
    enabled: !!projectId,
  });

  const createThread = useMutation({
    mutationFn: () => threadsApi.create({ projectId: projectId! }),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['threads'] });
      navigate(`/workspace/${type}/project/${projectId}/thread/${response.data.thread.id}`);
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to create thread');
    },
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Loader2 className="w-8 h-8 animate-spin text-slate-400" />
      </div>
    );
  }

  const proj = project?.data?.project;

  return (
    <div className="h-full flex flex-col">
      {/* Header */}
      <header className="bg-slate-800/50 border-b border-slate-700 px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Link
              to={`/workspace/${type}`}
              className="p-2 hover:bg-slate-700 rounded-lg text-slate-400 hover:text-white transition-colors"
            >
              <ArrowLeft size={20} />
            </Link>
            <div>
              <h1 className="text-xl font-bold text-white">{proj?.name}</h1>
              {proj?.description && (
                <p className="text-sm text-slate-400">{proj?.description}</p>
              )}
            </div>
          </div>
          <div className="flex items-center gap-2">
            <button
              onClick={() => createThread.mutate()}
              disabled={createThread.isPending}
              className="flex items-center gap-2 px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white rounded-lg transition-colors disabled:opacity-50"
            >
              {createThread.isPending ? (
                <Loader2 size={18} className="animate-spin" />
              ) : (
                <Plus size={18} />
              )}
              New Chat
            </button>
            <div className="relative">
              <button
                onClick={() => setShowMenu(!showMenu)}
                className="p-2 hover:bg-slate-700 rounded-lg text-slate-400 hover:text-white transition-colors"
              >
                <MoreVertical size={20} />
              </button>
              {showMenu && (
                <div className="absolute right-0 mt-2 w-48 bg-slate-700 rounded-lg shadow-lg border border-slate-600 py-1 z-10">
                  <button
                    onClick={() => {
                      setShowMenu(false);
                      // Open settings
                    }}
                    className="w-full flex items-center gap-2 px-4 py-2 text-slate-300 hover:bg-slate-600 text-left"
                  >
                    <Settings size={16} />
                    Project Settings
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      </header>

      {/* Content */}
      <div className="flex-1 overflow-auto p-6">
        <div className="space-y-2">
          {threads?.data?.threads?.map((thread: any) => (
            <Link
              key={thread.id}
              to={`/workspace/${type}/project/${projectId}/thread/${thread.id}`}
              className="flex items-center gap-4 p-4 bg-slate-800 hover:bg-slate-750 rounded-xl border border-slate-700 hover:border-slate-600 transition-colors group"
            >
              <div className="w-10 h-10 rounded-lg bg-sky-500/20 flex items-center justify-center">
                <MessageSquare className="w-5 h-5 text-sky-400" />
              </div>
              <div className="flex-1 min-w-0">
                <h3 className="font-medium text-white group-hover:text-sky-400 transition-colors truncate">
                  {thread.title || 'Untitled Thread'}
                </h3>
                <div className="flex items-center gap-3 text-sm text-slate-400 mt-1">
                  <span className="flex items-center gap-1">
                    <MessageSquare size={14} />
                    {thread._count?.messages || 0} messages
                  </span>
                  <span className="flex items-center gap-1">
                    <Clock size={14} />
                    {new Date(thread.updatedAt).toLocaleDateString()}
                  </span>
                </div>
              </div>
            </Link>
          ))}

          {(!threads?.data?.threads || threads.data.threads.length === 0) && (
            <div className="text-center py-16 text-slate-400">
              <MessageSquare className="w-16 h-16 mx-auto mb-4 opacity-50" />
              <h3 className="text-lg font-medium text-white mb-2">No conversations yet</h3>
              <p className="mb-6">Start a new chat to begin working with AI</p>
              <button
                onClick={() => createThread.mutate()}
                disabled={createThread.isPending}
                className="inline-flex items-center gap-2 px-6 py-3 bg-sky-500 hover:bg-sky-400 text-white rounded-lg transition-colors"
              >
                <Plus size={20} />
                Start New Chat
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
