import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Plus, FolderOpen, Clock, MessageSquare, Loader2,
  Home, Church, Code2
} from 'lucide-react';
import { useState } from 'react';
import { workspacesApi, projectsApi } from '../lib/api';
import toast from 'react-hot-toast';

const workspaceIcons = {
  NORMAL: Home,
  CHURCH: Church,
  CODER: Code2,
};

const workspaceColors = {
  NORMAL: 'sky',
  CHURCH: 'fuchsia',
  CODER: 'emerald',
};

export function WorkspacePage() {
  const { type } = useParams<{ type: string }>();
  const queryClient = useQueryClient();
  const [showNewProject, setShowNewProject] = useState(false);
  const [newProjectName, setNewProjectName] = useState('');
  const [newProjectDesc, setNewProjectDesc] = useState('');

  const { data: workspace, isLoading } = useQuery({
    queryKey: ['workspace', type],
    queryFn: () => workspacesApi.getByType(type!),
    enabled: !!type,
  });

  const { data: projects } = useQuery({
    queryKey: ['projects', workspace?.data?.workspace?.id],
    queryFn: () => projectsApi.list(workspace?.data?.workspace?.id),
    enabled: !!workspace?.data?.workspace?.id,
  });

  const createProject = useMutation({
    mutationFn: (data: { name: string; description?: string; workspaceId: string }) =>
      projectsApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      setShowNewProject(false);
      setNewProjectName('');
      setNewProjectDesc('');
      toast.success('Project created!');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to create project');
    },
  });

  const handleCreateProject = (e: React.FormEvent) => {
    e.preventDefault();
    if (workspace?.data?.workspace?.id) {
      createProject.mutate({
        name: newProjectName,
        description: newProjectDesc || undefined,
        workspaceId: workspace.data.workspace.id,
      });
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Loader2 className="w-8 h-8 animate-spin text-slate-400" />
      </div>
    );
  }

  const ws = workspace?.data?.workspace;
  const Icon = workspaceIcons[type as keyof typeof workspaceIcons] || Home;
  const color = workspaceColors[type as keyof typeof workspaceColors] || 'sky';

  return (
    <div className={`h-full workspace-${type?.toLowerCase()}`}>
      {/* Header */}
      <header className="bg-slate-800/50 border-b border-slate-700 px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <div className={`w-12 h-12 rounded-xl bg-${color}-500/20 flex items-center justify-center`}>
              <Icon className={`w-6 h-6 text-${color}-400`} />
            </div>
            <div>
              <h1 className="text-2xl font-bold text-white">{ws?.name}</h1>
              <p className="text-slate-400">{ws?.description}</p>
            </div>
          </div>
          <button
            onClick={() => setShowNewProject(true)}
            className={`flex items-center gap-2 px-4 py-2 bg-${color}-500 hover:bg-${color}-400 text-white rounded-lg transition-colors`}
          >
            <Plus size={20} />
            New Project
          </button>
        </div>
      </header>

      {/* Content */}
      <div className="p-6">
        {/* Quick Stats */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
          <div className="bg-slate-800 rounded-xl p-4 border border-slate-700">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-sky-500/20 flex items-center justify-center">
                <FolderOpen className="w-5 h-5 text-sky-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-white">
                  {projects?.data?.projects?.length || 0}
                </div>
                <div className="text-sm text-slate-400">Projects</div>
              </div>
            </div>
          </div>
          <div className="bg-slate-800 rounded-xl p-4 border border-slate-700">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                <MessageSquare className="w-5 h-5 text-emerald-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-white">
                  {projects?.data?.projects?.reduce((acc: number, p: any) => acc + (p._count?.threads || 0), 0) || 0}
                </div>
                <div className="text-sm text-slate-400">Threads</div>
              </div>
            </div>
          </div>
          <div className="bg-slate-800 rounded-xl p-4 border border-slate-700">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-fuchsia-500/20 flex items-center justify-center">
                <Clock className="w-5 h-5 text-fuchsia-400" />
              </div>
              <div>
                <div className="text-2xl font-bold text-white">Active</div>
                <div className="text-sm text-slate-400">Status</div>
              </div>
            </div>
          </div>
        </div>

        {/* Projects List */}
        <div>
          <h2 className="text-lg font-semibold text-white mb-4">Projects</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {projects?.data?.projects?.map((project: any) => (
              <Link
                key={project.id}
                to={`/workspace/${type}/project/${project.id}`}
                className="bg-slate-800 rounded-xl p-5 border border-slate-700 hover:border-slate-600 transition-colors group"
              >
                <div className="flex items-start justify-between mb-3">
                  <div className={`w-10 h-10 rounded-lg bg-${color}-500/20 flex items-center justify-center`}>
                    <FolderOpen className={`w-5 h-5 text-${color}-400`} />
                  </div>
                  <span className="text-xs text-slate-500">
                    {project._count?.threads || 0} threads
                  </span>
                </div>
                <h3 className="text-lg font-medium text-white group-hover:text-sky-400 transition-colors">
                  {project.name}
                </h3>
                {project.description && (
                  <p className="text-sm text-slate-400 mt-1 line-clamp-2">
                    {project.description}
                  </p>
                )}
                <div className="flex items-center gap-2 mt-3 text-xs text-slate-500">
                  <span>by {project.owner?.displayName}</span>
                </div>
              </Link>
            ))}

            {(!projects?.data?.projects || projects.data.projects.length === 0) && (
              <div className="col-span-full text-center py-12 text-slate-400">
                <FolderOpen className="w-12 h-12 mx-auto mb-3 opacity-50" />
                <p>No projects yet. Create your first project to get started!</p>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* New Project Modal */}
      {showNewProject && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-slate-800 rounded-xl p-6 w-full max-w-md border border-slate-700">
            <h2 className="text-xl font-semibold text-white mb-4">Create New Project</h2>
            <form onSubmit={handleCreateProject} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">
                  Project Name
                </label>
                <input
                  type="text"
                  value={newProjectName}
                  onChange={(e) => setNewProjectName(e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-sky-500"
                  placeholder="My Project"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">
                  Description (optional)
                </label>
                <textarea
                  value={newProjectDesc}
                  onChange={(e) => setNewProjectDesc(e.target.value)}
                  className="w-full px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-sky-500 h-24 resize-none"
                  placeholder="Describe your project..."
                />
              </div>
              <div className="flex justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setShowNewProject(false)}
                  className="px-4 py-2 text-slate-400 hover:text-white transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={createProject.isPending}
                  className="px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white rounded-lg transition-colors disabled:opacity-50 flex items-center gap-2"
                >
                  {createProject.isPending && <Loader2 size={16} className="animate-spin" />}
                  Create
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
