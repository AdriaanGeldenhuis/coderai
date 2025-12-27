import { Router, Request, Response, NextFunction } from 'express';
import { z } from 'zod';
import { prisma } from '../index.js';
import { requireAuth } from '../middleware/auth.js';
import { NotFoundError, AuthorizationError } from '../middleware/errorHandler.js';
import { createAuditLog } from '../middleware/audit.js';
import { AuditAction, MessageRole } from '@prisma/client';
import { generateMockResponse } from '../services/mockAI.js';

export const messageRouter = Router();

// Validation schemas
const createMessageSchema = z.object({
  threadId: z.string().uuid(),
  content: z.string().min(1).max(100000),
  role: z.enum(['USER', 'ASSISTANT', 'SYSTEM']).default('USER'),
  metadata: z.record(z.unknown()).optional(),
});

// Helper to check thread access
async function checkThreadAccess(userId: string, threadId: string): Promise<{ canRead: boolean; canWrite: boolean; workspaceId: string; projectId: string }> {
  const thread = await prisma.thread.findUnique({
    where: { id: threadId },
    include: {
      project: {
        select: { id: true, workspaceId: true }
      }
    }
  });

  if (!thread) {
    throw new NotFoundError('Thread not found');
  }

  const access = await prisma.workspaceAccess.findUnique({
    where: {
      userId_workspaceId: {
        userId,
        workspaceId: thread.project.workspaceId
      }
    }
  });

  return {
    canRead: access?.canRead || false,
    canWrite: access?.canWrite || false,
    workspaceId: thread.project.workspaceId,
    projectId: thread.project.id
  };
}

// List messages in a thread
messageRouter.get('/', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { threadId, limit = '50', offset = '0' } = req.query;

    if (!threadId || typeof threadId !== 'string') {
      throw new AuthorizationError('Thread ID is required');
    }

    const access = await checkThreadAccess(req.user!.id, threadId);

    if (!access.canRead) {
      throw new AuthorizationError('No access to this thread');
    }

    const messages = await prisma.message.findMany({
      where: { threadId },
      include: {
        author: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        }
      },
      orderBy: { createdAt: 'asc' },
      take: parseInt(limit as string, 10),
      skip: parseInt(offset as string, 10),
    });

    const totalCount = await prisma.message.count({
      where: { threadId }
    });

    res.json({ messages, totalCount });
  } catch (error) {
    next(error);
  }
});

// Get single message
messageRouter.get('/:messageId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { messageId } = req.params;

    const message = await prisma.message.findUnique({
      where: { id: messageId },
      include: {
        thread: {
          select: {
            id: true,
            title: true,
            project: {
              select: {
                id: true,
                name: true,
                workspaceId: true
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
      }
    });

    if (!message) {
      throw new NotFoundError('Message not found');
    }

    const access = await checkThreadAccess(req.user!.id, message.threadId);

    if (!access.canRead) {
      throw new AuthorizationError('No access to this message');
    }

    res.json({ message });
  } catch (error) {
    next(error);
  }
});

// Create message (and optionally get AI response)
messageRouter.post('/', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const data = createMessageSchema.parse(req.body);
    const { generateResponse = true } = req.query;

    const access = await checkThreadAccess(req.user!.id, data.threadId);

    if (!access.canWrite) {
      throw new AuthorizationError('No write access to this thread');
    }

    // Create user message
    const userMessage = await prisma.message.create({
      data: {
        threadId: data.threadId,
        content: data.content,
        role: data.role as MessageRole,
        authorId: req.user!.id,
        metadata: data.metadata as any || {},
      },
      include: {
        author: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        }
      }
    });

    // Update thread title if it's the first message and no title set
    const thread = await prisma.thread.findUnique({
      where: { id: data.threadId },
      include: {
        _count: { select: { messages: true } }
      }
    });

    if (thread && !thread.title && thread._count.messages === 1) {
      // Auto-generate title from first message
      const title = data.content.substring(0, 100) + (data.content.length > 100 ? '...' : '');
      await prisma.thread.update({
        where: { id: data.threadId },
        data: { title }
      });
    }

    await createAuditLog(
      AuditAction.MESSAGE_CREATE,
      req.user!.id,
      access.workspaceId,
      'message',
      userMessage.id,
      { threadId: data.threadId, role: data.role },
      req
    );

    let assistantMessage = null;

    // Generate AI response if requested and this is a user message
    if (generateResponse === 'true' && data.role === 'USER') {
      // Get thread context (previous messages)
      const previousMessages = await prisma.message.findMany({
        where: { threadId: data.threadId },
        orderBy: { createdAt: 'asc' },
        take: 20, // Last 20 messages for context
      });

      // Get project rulesets
      const project = await prisma.project.findUnique({
        where: { id: access.projectId },
        include: {
          rulesetBindings: {
            where: { isActive: true },
            include: {
              ruleset: {
                include: {
                  versions: {
                    orderBy: { version: 'desc' },
                    take: 1
                  }
                }
              }
            },
            orderBy: { priority: 'desc' }
          },
          workspace: {
            select: { type: true }
          }
        }
      });

      // Get active rules
      const rules = project?.rulesetBindings
        .map(b => b.ruleset.versions[0]?.rules)
        .filter(Boolean) || [];

      // Generate mock AI response
      const mockResponse = await generateMockResponse(
        previousMessages.map(m => ({ role: m.role, content: m.content })),
        project?.workspace.type || 'NORMAL',
        rules as any[]
      );

      // Create assistant message
      assistantMessage = await prisma.message.create({
        data: {
          threadId: data.threadId,
          content: mockResponse.content,
          role: 'ASSISTANT',
          modelUsed: mockResponse.model,
          tokenCount: mockResponse.tokenCount,
          metadata: { mockResponse: true },
        }
      });

      await createAuditLog(
        AuditAction.MESSAGE_CREATE,
        null,
        access.workspaceId,
        'message',
        assistantMessage.id,
        { threadId: data.threadId, role: 'ASSISTANT', model: mockResponse.model },
        req
      );
    }

    // Update thread's updatedAt
    await prisma.thread.update({
      where: { id: data.threadId },
      data: { updatedAt: new Date() }
    });

    res.status(201).json({
      userMessage,
      assistantMessage
    });
  } catch (error) {
    next(error);
  }
});

// Delete message (admin only)
messageRouter.delete('/:messageId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { messageId } = req.params;

    const message = await prisma.message.findUnique({
      where: { id: messageId },
      include: {
        thread: {
          include: {
            project: {
              select: { workspaceId: true }
            }
          }
        }
      }
    });

    if (!message) {
      throw new NotFoundError('Message not found');
    }

    // Check admin access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: message.thread.project.workspaceId
        }
      }
    });

    if (!access || !access.canAdmin) {
      throw new AuthorizationError('Admin access required to delete messages');
    }

    await prisma.message.delete({
      where: { id: messageId }
    });

    await createAuditLog(
      AuditAction.MESSAGE_DELETE,
      req.user!.id,
      message.thread.project.workspaceId,
      'message',
      messageId,
      { threadId: message.threadId },
      req
    );

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});
