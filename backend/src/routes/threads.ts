import { Router, Request, Response, NextFunction } from 'express';
import { z } from 'zod';
import { prisma } from '../index.js';
import { requireAuth } from '../middleware/auth.js';
import { NotFoundError, AuthorizationError } from '../middleware/errorHandler.js';
import { createAuditLog } from '../middleware/audit.js';
import { AuditAction } from '@prisma/client';

export const threadRouter = Router();

// Validation schemas
const createThreadSchema = z.object({
  projectId: z.string().uuid(),
  title: z.string().max(200).optional(),
  metadata: z.record(z.unknown()).optional(),
});

const updateThreadSchema = z.object({
  title: z.string().max(200).optional(),
  metadata: z.record(z.unknown()).optional(),
  isArchived: z.boolean().optional(),
});

// Helper to check project access
async function checkProjectAccess(userId: string, projectId: string): Promise<{ canRead: boolean; canWrite: boolean; workspaceId: string }> {
  const project = await prisma.project.findUnique({
    where: { id: projectId },
    select: { workspaceId: true }
  });

  if (!project) {
    throw new NotFoundError('Project not found');
  }

  const access = await prisma.workspaceAccess.findUnique({
    where: {
      userId_workspaceId: {
        userId,
        workspaceId: project.workspaceId
      }
    }
  });

  return {
    canRead: access?.canRead || false,
    canWrite: access?.canWrite || false,
    workspaceId: project.workspaceId
  };
}

// List threads in a project
threadRouter.get('/', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { projectId, includeArchived } = req.query;

    if (!projectId || typeof projectId !== 'string') {
      throw new AuthorizationError('Project ID is required');
    }

    const access = await checkProjectAccess(req.user!.id, projectId);

    if (!access.canRead) {
      throw new AuthorizationError('No access to this project');
    }

    const threads = await prisma.thread.findMany({
      where: {
        projectId,
        ...(includeArchived !== 'true' && { isArchived: false })
      },
      include: {
        createdBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        },
        _count: {
          select: { messages: true }
        }
      },
      orderBy: { updatedAt: 'desc' }
    });

    res.json({ threads });
  } catch (error) {
    next(error);
  }
});

// Get single thread with messages
threadRouter.get('/:threadId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { threadId } = req.params;
    const { limit = '50', offset = '0' } = req.query;

    const thread = await prisma.thread.findUnique({
      where: { id: threadId },
      include: {
        project: {
          select: {
            id: true,
            name: true,
            workspaceId: true,
            workspace: {
              select: {
                id: true,
                type: true,
                name: true,
              }
            }
          }
        },
        createdBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        },
        messages: {
          orderBy: { createdAt: 'asc' },
          take: parseInt(limit as string, 10),
          skip: parseInt(offset as string, 10),
          include: {
            author: {
              select: {
                id: true,
                username: true,
                displayName: true,
              }
            }
          }
        },
        _count: {
          select: { messages: true }
        }
      }
    });

    if (!thread) {
      throw new NotFoundError('Thread not found');
    }

    const access = await checkProjectAccess(req.user!.id, thread.projectId);

    if (!access.canRead) {
      throw new AuthorizationError('No access to this thread');
    }

    res.json({ thread });
  } catch (error) {
    next(error);
  }
});

// Create thread
threadRouter.post('/', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const data = createThreadSchema.parse(req.body);

    const access = await checkProjectAccess(req.user!.id, data.projectId);

    if (!access.canWrite) {
      throw new AuthorizationError('No write access to this project');
    }

    const thread = await prisma.thread.create({
      data: {
        projectId: data.projectId,
        title: data.title,
        createdById: req.user!.id,
        metadata: data.metadata as any || {},
      },
      include: {
        project: {
          select: {
            id: true,
            name: true,
          }
        },
        createdBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        }
      }
    });

    await createAuditLog(
      AuditAction.THREAD_CREATE,
      req.user!.id,
      access.workspaceId,
      'thread',
      thread.id,
      { title: thread.title, projectId: data.projectId },
      req
    );

    res.status(201).json({ thread });
  } catch (error) {
    next(error);
  }
});

// Update thread
threadRouter.put('/:threadId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { threadId } = req.params;
    const data = updateThreadSchema.parse(req.body);

    const existingThread = await prisma.thread.findUnique({
      where: { id: threadId },
      include: {
        project: {
          select: { workspaceId: true }
        }
      }
    });

    if (!existingThread) {
      throw new NotFoundError('Thread not found');
    }

    const access = await checkProjectAccess(req.user!.id, existingThread.projectId);

    if (!access.canWrite) {
      throw new AuthorizationError('No write access to this thread');
    }

    const thread = await prisma.thread.update({
      where: { id: threadId },
      data: {
        title: data.title,
        metadata: data.metadata as any,
        isArchived: data.isArchived,
      },
      include: {
        project: {
          select: {
            id: true,
            name: true,
          }
        },
        createdBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        }
      }
    });

    if (data.isArchived !== undefined) {
      await createAuditLog(
        data.isArchived ? AuditAction.THREAD_ARCHIVE : AuditAction.THREAD_UPDATE,
        req.user!.id,
        access.workspaceId,
        'thread',
        thread.id,
        { title: thread.title, archived: data.isArchived },
        req
      );
    } else {
      await createAuditLog(
        AuditAction.THREAD_UPDATE,
        req.user!.id,
        access.workspaceId,
        'thread',
        thread.id,
        { changes: data },
        req
      );
    }

    res.json({ thread });
  } catch (error) {
    next(error);
  }
});

// Delete thread (admin only)
threadRouter.delete('/:threadId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { threadId } = req.params;

    const existingThread = await prisma.thread.findUnique({
      where: { id: threadId },
      include: {
        project: {
          select: { workspaceId: true }
        }
      }
    });

    if (!existingThread) {
      throw new NotFoundError('Thread not found');
    }

    // Check admin access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: existingThread.project.workspaceId
        }
      }
    });

    if (!access || !access.canAdmin) {
      throw new AuthorizationError('Admin access required to delete threads');
    }

    await prisma.thread.delete({
      where: { id: threadId }
    });

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});
