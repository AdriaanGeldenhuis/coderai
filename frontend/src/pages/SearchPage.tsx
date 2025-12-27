import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
  Search, MessageSquare, FileText, BookOpen, FolderOpen,
  Loader2, Filter
} from 'lucide-react';
import { searchApi } from '../lib/api';

const typeFilters = [
  { id: 'messages', label: 'Messages', icon: MessageSquare },
  { id: 'uploads', label: 'Files', icon: FileText },
  { id: 'rulesets', label: 'Rulesets', icon: BookOpen },
  { id: 'projects', label: 'Projects', icon: FolderOpen },
];

export function SearchPage() {
  const [query, setQuery] = useState('');
  const [selectedTypes, setSelectedTypes] = useState<string[]>(['messages', 'uploads', 'rulesets', 'projects']);
  const [debouncedQuery, setDebouncedQuery] = useState('');

  // Debounce search
  const handleSearch = (value: string) => {
    setQuery(value);
    const timeoutId = setTimeout(() => {
      setDebouncedQuery(value);
    }, 300);
    return () => clearTimeout(timeoutId);
  };

  const { data: results, isLoading } = useQuery({
    queryKey: ['search', debouncedQuery, selectedTypes],
    queryFn: () => searchApi.search(debouncedQuery, { types: selectedTypes, limit: 50 }),
    enabled: debouncedQuery.length >= 2,
  });

  const toggleType = (type: string) => {
    setSelectedTypes((prev) =>
      prev.includes(type)
        ? prev.filter((t) => t !== type)
        : [...prev, type]
    );
  };

  const searchResults = results?.data;

  return (
    <div className="h-full flex flex-col">
      {/* Header */}
      <header className="bg-slate-800/50 border-b border-slate-700 px-6 py-4">
        <div className="max-w-3xl mx-auto">
          <h1 className="text-2xl font-bold text-white mb-4">Search</h1>

          {/* Search Input */}
          <div className="relative">
            <Search className="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
            <input
              type="text"
              value={query}
              onChange={(e) => handleSearch(e.target.value)}
              placeholder="Search messages, files, rulesets, and projects..."
              className="w-full pl-12 pr-4 py-3 bg-slate-700 border border-slate-600 rounded-xl text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500"
              autoFocus
            />
          </div>

          {/* Type Filters */}
          <div className="flex items-center gap-2 mt-4">
            <Filter size={16} className="text-slate-400" />
            {typeFilters.map((type) => (
              <button
                key={type.id}
                onClick={() => toggleType(type.id)}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm transition-colors ${
                  selectedTypes.includes(type.id)
                    ? 'bg-sky-500/20 text-sky-400 border border-sky-500/30'
                    : 'bg-slate-700 text-slate-400 border border-slate-600 hover:border-slate-500'
                }`}
              >
                <type.icon size={14} />
                {type.label}
              </button>
            ))}
          </div>
        </div>
      </header>

      {/* Results */}
      <div className="flex-1 overflow-auto p-6">
        <div className="max-w-3xl mx-auto">
          {!debouncedQuery && (
            <div className="text-center py-16 text-slate-400">
              <Search className="w-16 h-16 mx-auto mb-4 opacity-50" />
              <p>Enter a search term to find messages, files, and more</p>
            </div>
          )}

          {debouncedQuery && debouncedQuery.length < 2 && (
            <div className="text-center py-16 text-slate-400">
              <p>Enter at least 2 characters to search</p>
            </div>
          )}

          {isLoading && (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="w-8 h-8 animate-spin text-slate-400" />
            </div>
          )}

          {searchResults && (
            <div className="space-y-6">
              {/* Summary */}
              <div className="text-sm text-slate-400">
                Found {searchResults.total} results for "{searchResults.query}"
              </div>

              {/* Messages */}
              {searchResults.results.messages?.length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
                    <MessageSquare size={16} />
                    Messages ({searchResults.counts.messages})
                  </h3>
                  <div className="space-y-2">
                    {searchResults.results.messages.map((msg: any) => (
                      <Link
                        key={msg.id}
                        to={`/workspace/${msg.thread.project.workspace.type}/project/${msg.thread.project.id}/thread/${msg.thread.id}`}
                        className="block p-4 bg-slate-800 rounded-lg border border-slate-700 hover:border-slate-600 transition-colors"
                      >
                        <div className="text-sm text-slate-400 mb-1">
                          {msg.thread.project.workspace.name} / {msg.thread.project.name} / {msg.thread.title || 'Untitled'}
                        </div>
                        <p className="text-white">{msg.snippet}</p>
                        <div className="text-xs text-slate-500 mt-2">
                          {msg.author?.displayName || 'AI'} · {new Date(msg.createdAt).toLocaleDateString()}
                        </div>
                      </Link>
                    ))}
                  </div>
                </div>
              )}

              {/* Uploads */}
              {searchResults.results.uploads?.length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
                    <FileText size={16} />
                    Files ({searchResults.counts.uploads})
                  </h3>
                  <div className="space-y-2">
                    {searchResults.results.uploads.map((upload: any) => (
                      <div
                        key={upload.id}
                        className="p-4 bg-slate-800 rounded-lg border border-slate-700"
                      >
                        <div className="flex items-start gap-3">
                          <div className="w-10 h-10 rounded-lg bg-sky-500/20 flex items-center justify-center">
                            <FileText className="w-5 h-5 text-sky-400" />
                          </div>
                          <div className="flex-1 min-w-0">
                            <h4 className="font-medium text-white truncate">{upload.originalName}</h4>
                            <div className="text-sm text-slate-400">
                              {upload.workspace.name} · {(upload.size / 1024).toFixed(1)} KB
                            </div>
                            {upload.snippet && (
                              <p className="text-sm text-slate-300 mt-2">{upload.snippet}</p>
                            )}
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Rulesets */}
              {searchResults.results.rulesets?.length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
                    <BookOpen size={16} />
                    Rulesets ({searchResults.counts.rulesets})
                  </h3>
                  <div className="space-y-2">
                    {searchResults.results.rulesets.map((ruleset: any) => (
                      <div
                        key={ruleset.id}
                        className="p-4 bg-slate-800 rounded-lg border border-slate-700"
                      >
                        <h4 className="font-medium text-white">{ruleset.name}</h4>
                        {ruleset.description && (
                          <p className="text-sm text-slate-400 mt-1">{ruleset.description}</p>
                        )}
                        <div className="text-xs text-slate-500 mt-2">
                          {ruleset.workspace.name} · {ruleset._count.versions} versions
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Projects */}
              {searchResults.results.projects?.length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
                    <FolderOpen size={16} />
                    Projects ({searchResults.counts.projects})
                  </h3>
                  <div className="space-y-2">
                    {searchResults.results.projects.map((project: any) => (
                      <Link
                        key={project.id}
                        to={`/workspace/${project.workspace.type}/project/${project.id}`}
                        className="block p-4 bg-slate-800 rounded-lg border border-slate-700 hover:border-slate-600 transition-colors"
                      >
                        <h4 className="font-medium text-white">{project.name}</h4>
                        {project.description && (
                          <p className="text-sm text-slate-400 mt-1">{project.description}</p>
                        )}
                        <div className="text-xs text-slate-500 mt-2">
                          {project.workspace.name} · {project._count.threads} threads
                        </div>
                      </Link>
                    ))}
                  </div>
                </div>
              )}

              {/* No Results */}
              {searchResults.total === 0 && (
                <div className="text-center py-12 text-slate-400">
                  <p>No results found for "{searchResults.query}"</p>
                  <p className="text-sm mt-2">Try different keywords or adjust filters</p>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
