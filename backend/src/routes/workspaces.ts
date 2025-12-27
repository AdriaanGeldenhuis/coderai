import { Router, Request, Response, NextFunction } from 'express';
import { z } from 'zod';
import { prisma } from '../index.js';
import { requireAuth, requireAdmin } from '../middleware/auth.js';
import { NotFoundError, AuthorizationError } from '../middleware/errorHandler.js';
import { createAuditLog } from '../middleware/audit.js';
import { AuditAction, WorkspaceType } from '@prisma/client';

export const workspaceRouter = Router();

// Validation schemas
const updateWorkspaceSchema = z.object({
  name: z.string().min(1).max(100).optional(),
  description: z.string().max(500).optional(),
  settings: z.record(z.unknown()).optional(),
});

// List all workspaces the user has access to
workspaceRouter.get('/', requireAuth, async (req: Request, res: Response): Promise<void> => {
  const workspaces = await prisma.workspace.findMany({
    where: {
      access: {
        some: {
          userId: req.user!.id,
          canRead: true
        }
      }
    },
    include: {
      access: {
        where: {
          userId: req.user!.id
        },
        select: {
          canRead: true,
          canWrite: true,
          canAdmin: true,
        }
      },
      _count: {
        select: {
          projects: true,
          uploads: true,
          rulesets: true,
        }
      }
    }
  });

  res.json({ workspaces });
});

// Get single workspace
workspaceRouter.get('/:workspaceId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { workspaceId } = req.params;

    const workspace = await prisma.workspace.findUnique({
      where: { id: workspaceId },
      include: {
        access: {
          where: {
            userId: req.user!.id
          },
          select: {
            canRead: true,
            canWrite: true,
            canAdmin: true,
          }
        },
        _count: {
          select: {
            projects: true,
            uploads: true,
            rulesets: true,
          }
        }
      }
    });

    if (!workspace) {
      throw new NotFoundError('Workspace not found');
    }

    if (workspace.access.length === 0 || !workspace.access[0].canRead) {
      throw new AuthorizationError('No access to this workspace');
    }

    res.json({ workspace });
  } catch (error) {
    next(error);
  }
});

// Get workspace by type
workspaceRouter.get('/type/:type', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { type } = req.params;

    if (!['NORMAL', 'CHURCH', 'CODER'].includes(type)) {
      throw new NotFoundError('Invalid workspace type');
    }

    const workspace = await prisma.workspace.findUnique({
      where: { type: type as WorkspaceType },
      include: {
        access: {
          where: {
            userId: req.user!.id
          },
          select: {
            canRead: true,
            canWrite: true,
            canAdmin: true,
          }
        },
        projects: {
          where: {
            isArchived: false
          },
          select: {
            id: true,
            name: true,
            description: true,
            createdAt: true,
            _count: {
              select: {
                threads: true
              }
            }
          },
          orderBy: { updatedAt: 'desc' },
          take: 10
        },
        _count: {
          select: {
            projects: true,
            uploads: true,
            rulesets: true,
          }
        }
      }
    });

    if (!workspace) {
      throw new NotFoundError('Workspace not found');
    }

    if (workspace.access.length === 0 || !workspace.access[0].canRead) {
      throw new AuthorizationError('No access to this workspace');
    }

    res.json({ workspace });
  } catch (error) {
    next(error);
  }
});

// Update workspace settings (admin only)
workspaceRouter.put('/:workspaceId', requireAuth, requireAdmin, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { workspaceId } = req.params;
    const data = updateWorkspaceSchema.parse(req.body);

    const existingWorkspace = await prisma.workspace.findUnique({
      where: { id: workspaceId }
    });

    if (!existingWorkspace) {
      throw new NotFoundError('Workspace not found');
    }

    const workspace = await prisma.workspace.update({
      where: { id: workspaceId },
      data: {
        name: data.name,
        description: data.description,
        settings: data.settings as any,
      }
    });

    await createAuditLog(
      AuditAction.WORKSPACE_SETTINGS_UPDATE,
      req.user!.id,
      workspaceId,
      'workspace',
      workspaceId,
      { changes: data },
      req
    );

    res.json({ workspace });
  } catch (error) {
    next(error);
  }
});

// Get workspace statistics
workspaceRouter.get('/:workspaceId/stats', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { workspaceId } = req.params;

    // Check access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId
        }
      }
    });

    if (!access || !access.canRead) {
      throw new AuthorizationError('No access to this workspace');
    }

    const [projectCount, threadCount, messageCount, uploadCount, rulesetCount] = await Promise.all([
      prisma.project.count({ where: { workspaceId } }),
      prisma.thread.count({
        where: {
          project: { workspaceId }
        }
      }),
      prisma.message.count({
        where: {
          thread: {
            project: { workspaceId }
          }
        }
      }),
      prisma.upload.count({ where: { workspaceId } }),
      prisma.ruleset.count({ where: { workspaceId } }),
    ]);

    // Recent activity
    const recentMessages = await prisma.message.findMany({
      where: {
        thread: {
          project: { workspaceId }
        }
      },
      orderBy: { createdAt: 'desc' },
      take: 5,
      select: {
        id: true,
        content: true,
        role: true,
        createdAt: true,
        thread: {
          select: {
            id: true,
            title: true,
            project: {
              select: {
                id: true,
                name: true
              }
            }
          }
        }
      }
    });

    res.json({
      stats: {
        projectCount,
        threadCount,
        messageCount,
        uploadCount,
        rulesetCount,
      },
      recentActivity: recentMessages
    });
  } catch (error) {
    next(error);
  }
});

// List users with access to workspace (admin only)
workspaceRouter.get('/:workspaceId/users', requireAuth, requireAdmin, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { workspaceId } = req.params;

    const users = await prisma.workspaceAccess.findMany({
      where: { workspaceId },
      include: {
        user: {
          select: {
            id: true,
            username: true,
            displayName: true,
            role: true,
            isActive: true,
          }
        }
      }
    });

    res.json({ users });
  } catch (error) {
    next(error);
  }
});
