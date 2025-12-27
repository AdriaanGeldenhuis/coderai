import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Send, Loader2, User, Bot } from 'lucide-react';
import { useState, useRef, useEffect } from 'react';
import ReactMarkdown from 'react-markdown';
import { threadsApi, messagesApi } from '../lib/api';
import toast from 'react-hot-toast';

export function ThreadPage() {
  const { type, projectId, threadId } = useParams<{ type: string; projectId: string; threadId: string }>();
  const queryClient = useQueryClient();
  const [input, setInput] = useState('');
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  const { data: thread, isLoading } = useQuery({
    queryKey: ['thread', threadId],
    queryFn: () => threadsApi.get(threadId!),
    enabled: !!threadId,
  });

  const { data: messages, refetch: refetchMessages } = useQuery({
    queryKey: ['messages', threadId],
    queryFn: () => messagesApi.list(threadId!),
    enabled: !!threadId,
  });

  const sendMessage = useMutation({
    mutationFn: (content: string) =>
      messagesApi.create({ threadId: threadId!, content, role: 'USER' }, true),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['messages', threadId] });
      queryClient.invalidateQueries({ queryKey: ['thread', threadId] });
      setInput('');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to send message');
    },
  });

  // Auto-scroll to bottom
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages?.data?.messages]);

  // Focus input on load
  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (input.trim() && !sendMessage.isPending) {
      sendMessage.mutate(input.trim());
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSubmit(e);
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Loader2 className="w-8 h-8 animate-spin text-slate-400" />
      </div>
    );
  }

  const threadData = thread?.data?.thread;
  const messageList = messages?.data?.messages || [];

  return (
    <div className="h-full flex flex-col">
      {/* Header */}
      <header className="bg-slate-800/50 border-b border-slate-700 px-4 py-3 shrink-0">
        <div className="flex items-center gap-3">
          <Link
            to={`/workspace/${type}/project/${projectId}`}
            className="p-2 hover:bg-slate-700 rounded-lg text-slate-400 hover:text-white transition-colors"
          >
            <ArrowLeft size={20} />
          </Link>
          <div className="min-w-0">
            <h1 className="font-semibold text-white truncate">
              {threadData?.title || 'New Conversation'}
            </h1>
            <p className="text-xs text-slate-400">
              {threadData?.project?.name} · {messageList.length} messages
            </p>
          </div>
        </div>
      </header>

      {/* Messages */}
      <div className="flex-1 overflow-auto p-4 space-y-4">
        {messageList.length === 0 && (
          <div className="flex flex-col items-center justify-center h-full text-center text-slate-400">
            <Bot className="w-16 h-16 mb-4 opacity-50" />
            <h3 className="text-lg font-medium text-white mb-2">Start a conversation</h3>
            <p className="max-w-md">
              Type a message below to start chatting. The AI will respond with helpful information
              based on the {type?.toLowerCase()} workspace context.
            </p>
          </div>
        )}

        {messageList.map((message: any) => (
          <div
            key={message.id}
            className={`flex gap-3 animate-fadeIn ${
              message.role === 'USER' ? 'flex-row-reverse' : ''
            }`}
          >
            <div
              className={`shrink-0 w-8 h-8 rounded-full flex items-center justify-center ${
                message.role === 'USER'
                  ? 'bg-sky-500'
                  : message.role === 'SYSTEM'
                  ? 'bg-slate-600'
                  : 'bg-gradient-to-br from-emerald-500 to-sky-500'
              }`}
            >
              {message.role === 'USER' ? (
                <User size={16} className="text-white" />
              ) : (
                <Bot size={16} className="text-white" />
              )}
            </div>
            <div
              className={`max-w-[80%] rounded-2xl px-4 py-3 ${
                message.role === 'USER'
                  ? 'bg-sky-500 text-white'
                  : message.role === 'SYSTEM'
                  ? 'bg-slate-700 text-slate-300'
                  : 'bg-slate-800 text-white border border-slate-700'
              }`}
            >
              {message.role === 'USER' ? (
                <p className="whitespace-pre-wrap">{message.content}</p>
              ) : (
                <div className="message-content">
                  <ReactMarkdown>{message.content}</ReactMarkdown>
                </div>
              )}
              {message.role === 'ASSISTANT' && message.modelUsed && (
                <div className="mt-2 pt-2 border-t border-slate-700 text-xs text-slate-500">
                  Model: {message.modelUsed}
                </div>
              )}
            </div>
          </div>
        ))}

        {sendMessage.isPending && (
          <div className="flex gap-3">
            <div className="shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-emerald-500 to-sky-500 flex items-center justify-center">
              <Bot size={16} className="text-white" />
            </div>
            <div className="bg-slate-800 rounded-2xl px-4 py-3 border border-slate-700">
              <div className="flex items-center gap-2 text-slate-400">
                <Loader2 size={16} className="animate-spin" />
                <span>Thinking...</span>
              </div>
            </div>
          </div>
        )}

        <div ref={messagesEndRef} />
      </div>

      {/* Input */}
      <div className="shrink-0 border-t border-slate-700 p-4 bg-slate-800/50">
        <form onSubmit={handleSubmit} className="flex gap-3">
          <textarea
            ref={inputRef}
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="Type a message... (Enter to send, Shift+Enter for new line)"
            className="flex-1 px-4 py-3 bg-slate-700 border border-slate-600 rounded-xl text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent resize-none"
            rows={1}
            disabled={sendMessage.isPending}
          />
          <button
            type="submit"
            disabled={!input.trim() || sendMessage.isPending}
            className="px-4 py-3 bg-sky-500 hover:bg-sky-400 text-white rounded-xl transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {sendMessage.isPending ? (
              <Loader2 size={20} className="animate-spin" />
            ) : (
              <Send size={20} />
            )}
          </button>
        </form>
      </div>
    </div>
  );
}
