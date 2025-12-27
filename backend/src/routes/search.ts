import { Router, Request, Response, NextFunction } from 'express';
import { z } from 'zod';
import { prisma } from '../index.js';
import { requireAuth } from '../middleware/auth.js';
import { ValidationError, AuthorizationError } from '../middleware/errorHandler.js';
import { createAuditLog } from '../middleware/audit.js';
import { AuditAction } from '@prisma/client';

export const searchRouter = Router();

// Search schema
const searchSchema = z.object({
  query: z.string().min(1).max(500),
  workspaceId: z.string().uuid().optional(),
  types: z.array(z.enum(['messages', 'uploads', 'rulesets', 'projects', 'threads'])).optional(),
  limit: z.number().int().min(1).max(100).default(20),
  offset: z.number().int().min(0).default(0),
});

// Global search across messages, uploads, and rules
searchRouter.get('/', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const {
      query,
      workspaceId,
      types = ['messages', 'uploads', 'rulesets', 'projects', 'threads'],
      limit,
      offset
    } = searchSchema.parse({
      query: req.query.query,
      workspaceId: req.query.workspaceId,
      types: req.query.types ? (req.query.types as string).split(',') : undefined,
      limit: req.query.limit ? parseInt(req.query.limit as string, 10) : 20,
      offset: req.query.offset ? parseInt(req.query.offset as string, 10) : 0,
    });

    // Get workspaces the user has access to
    const userAccess = await prisma.workspaceAccess.findMany({
      where: {
        userId: req.user!.id,
        canRead: true,
        ...(workspaceId && { workspaceId })
      },
      select: { workspaceId: true }
    });

    if (userAccess.length === 0) {
      throw new AuthorizationError('No workspace access');
    }

    const accessibleWorkspaceIds = userAccess.map(a => a.workspaceId);

    const searchTerm = `%${query}%`;
    const results: {
      messages: any[];
      uploads: any[];
      rulesets: any[];
      projects: any[];
      threads: any[];
    } = {
      messages: [],
      uploads: [],
      rulesets: [],
      projects: [],
      threads: [],
    };

    const counts: {
      messages: number;
      uploads: number;
      rulesets: number;
      projects: number;
      threads: number;
    } = {
      messages: 0,
      uploads: 0,
      rulesets: 0,
      projects: 0,
      threads: 0,
    };

    // Search messages
    if (types.includes('messages')) {
      const [messages, messageCount] = await Promise.all([
        prisma.message.findMany({
          where: {
            content: { contains: query, mode: 'insensitive' },
            thread: {
              project: {
                workspaceId: { in: accessibleWorkspaceIds }
              }
            }
          },
          include: {
            thread: {
              select: {
                id: true,
                title: true,
                project: {
                  select: {
                    id: true,
                    name: true,
                    workspace: {
                      select: {
                        id: true,
                        type: true,
                        name: true,
                      }
                    }
                  }
                }
              }
            },
            author: {
              select: {
                id: true,
                username: true,
                displayName: true,
              }
            }
          },
          orderBy: { createdAt: 'desc' },
          take: limit,
          skip: offset,
        }),
        prisma.message.count({
          where: {
            content: { contains: query, mode: 'insensitive' },
            thread: {
              project: {
                workspaceId: { in: accessibleWorkspaceIds }
              }
            }
          }
        })
      ]);

      results.messages = messages.map(m => ({
        ...m,
        // Add snippet with highlighted match
        snippet: createSnippet(m.content, query),
      }));
      counts.messages = messageCount;
    }

    // Search uploads
    if (types.includes('uploads')) {
      const [uploads, uploadCount] = await Promise.all([
        prisma.upload.findMany({
          where: {
            workspaceId: { in: accessibleWorkspaceIds },
            OR: [
              { originalName: { contains: query, mode: 'insensitive' } },
              { indexedContent: { contains: query, mode: 'insensitive' } },
            ]
          },
          include: {
            uploadedBy: {
              select: {
                id: true,
                username: true,
                displayName: true,
              }
            },
            project: {
              select: {
                id: true,
                name: true,
              }
            },
            workspace: {
              select: {
                id: true,
                type: true,
                name: true,
              }
            }
          },
          orderBy: { createdAt: 'desc' },
          take: limit,
          skip: offset,
        }),
        prisma.upload.count({
          where: {
            workspaceId: { in: accessibleWorkspaceIds },
            OR: [
              { originalName: { contains: query, mode: 'insensitive' } },
              { indexedContent: { contains: query, mode: 'insensitive' } },
            ]
          }
        })
      ]);

      results.uploads = uploads.map(u => ({
        ...u,
        snippet: u.indexedContent ? createSnippet(u.indexedContent, query) : null,
      }));
      counts.uploads = uploadCount;
    }

    // Search rulesets
    if (types.includes('rulesets')) {
      const [rulesets, rulesetCount] = await Promise.all([
        prisma.ruleset.findMany({
          where: {
            workspaceId: { in: accessibleWorkspaceIds },
            OR: [
              { name: { contains: query, mode: 'insensitive' } },
              { description: { contains: query, mode: 'insensitive' } },
            ]
          },
          include: {
            createdBy: {
              select: {
                id: true,
                username: true,
                displayName: true,
              }
            },
            workspace: {
              select: {
                id: true,
                type: true,
                name: true,
              }
            },
            _count: {
              select: { versions: true }
            }
          },
          orderBy: { updatedAt: 'desc' },
          take: limit,
          skip: offset,
        }),
        prisma.ruleset.count({
          where: {
            workspaceId: { in: accessibleWorkspaceIds },
            OR: [
              { name: { contains: query, mode: 'insensitive' } },
              { description: { contains: query, mode: 'insensitive' } },
            ]
          }
        })
      ]);

      results.rulesets = rulesets;
      counts.rulesets = rulesetCount;
    }

    // Search projects
    if (types.includes('projects')) {
      const [projects, projectCount] = await Promise.all([
        prisma.project.findMany({
          where: {
            workspaceId: { in: accessibleWorkspaceIds },
            OR: [
              { name: { contains: query, mode: 'insensitive' } },
              { description: { contains: query, mode: 'insensitive' } },
            ]
          },
          include: {
            owner: {
              select: {
                id: true,
                username: true,
                displayName: true,
              }
            },
            workspace: {
              select: {
                id: true,
                type: true,
                name: true,
              }
            },
            _count: {
              select: { threads: true }
            }
          },
          orderBy: { updatedAt: 'desc' },
          take: limit,
          skip: offset,
        }),
        prisma.project.count({
          where: {
            workspaceId: { in: accessibleWorkspaceIds },
            OR: [
              { name: { contains: query, mode: 'insensitive' } },
              { description: { contains: query, mode: 'insensitive' } },
            ]
          }
        })
      ]);

      results.projects = projects;
      counts.projects = projectCount;
    }

    // Search threads
    if (types.includes('threads')) {
      const [threads, threadCount] = await Promise.all([
        prisma.thread.findMany({
          where: {
            project: {
              workspaceId: { in: accessibleWorkspaceIds }
            },
            title: { contains: query, mode: 'insensitive' }
          },
          include: {
            createdBy: {
              select: {
                id: true,
                username: true,
                displayName: true,
              }
            },
            project: {
              select: {
                id: true,
                name: true,
                workspace: {
                  select: {
                    id: true,
                    type: true,
                    name: true,
                  }
                }
              }
            },
            _count: {
              select: { messages: true }
            }
          },
          orderBy: { updatedAt: 'desc' },
          take: limit,
          skip: offset,
        }),
        prisma.thread.count({
          where: {
            project: {
              workspaceId: { in: accessibleWorkspaceIds }
            },
            title: { contains: query, mode: 'insensitive' }
          }
        })
      ]);

      results.threads = threads;
      counts.threads = threadCount;
    }

    // Log search
    await createAuditLog(
      AuditAction.SEARCH_QUERY,
      req.user!.id,
      workspaceId || null,
      null,
      null,
      { query, types, resultCounts: counts },
      req
    );

    res.json({
      query,
      results,
      counts,
      total: Object.values(counts).reduce((a, b) => a + b, 0),
    });
  } catch (error) {
    next(error);
  }
});

// Create a snippet around the search term
function createSnippet(text: string, query: string, contextLength: number = 100): string {
  const lowerText = text.toLowerCase();
  const lowerQuery = query.toLowerCase();
  const index = lowerText.indexOf(lowerQuery);

  if (index === -1) {
    return text.substring(0, contextLength * 2) + (text.length > contextLength * 2 ? '...' : '');
  }

  const start = Math.max(0, index - contextLength);
  const end = Math.min(text.length, index + query.length + contextLength);

  let snippet = '';
  if (start > 0) snippet += '...';
  snippet += text.substring(start, end);
  if (end < text.length) snippet += '...';

  return snippet;
}
