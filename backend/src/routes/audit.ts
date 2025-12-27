import { Router, Request, Response, NextFunction } from 'express';
import { z } from 'zod';
import { prisma } from '../index.js';
import { requireAuth, requireAdmin } from '../middleware/auth.js';
import { AuthorizationError } from '../middleware/errorHandler.js';
import { AuditAction } from '@prisma/client';

export const auditRouter = Router();

// Query schema
const auditQuerySchema = z.object({
  workspaceId: z.string().uuid().optional(),
  userId: z.string().uuid().optional(),
  action: z.nativeEnum(AuditAction).optional(),
  entityType: z.string().optional(),
  entityId: z.string().uuid().optional(),
  startDate: z.string().datetime().optional(),
  endDate: z.string().datetime().optional(),
  limit: z.number().int().min(1).max(500).default(50),
  offset: z.number().int().min(0).default(0),
});

// Get audit logs (admin only for all, users for their own workspace)
auditRouter.get('/', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const params = auditQuerySchema.parse({
      workspaceId: req.query.workspaceId,
      userId: req.query.userId,
      action: req.query.action,
      entityType: req.query.entityType,
      entityId: req.query.entityId,
      startDate: req.query.startDate,
      endDate: req.query.endDate,
      limit: req.query.limit ? parseInt(req.query.limit as string, 10) : 50,
      offset: req.query.offset ? parseInt(req.query.offset as string, 10) : 0,
    });

    // Non-admins can only see their own workspace audit logs
    let accessibleWorkspaceIds: string[] | undefined;

    if (req.user!.role !== 'ADMIN') {
      const userAccess = await prisma.workspaceAccess.findMany({
        where: {
          userId: req.user!.id,
          canAdmin: true, // Only workspace admins can view audit logs
        },
        select: { workspaceId: true }
      });

      if (userAccess.length === 0 && !params.workspaceId) {
        throw new AuthorizationError('Admin access required to view audit logs');
      }

      accessibleWorkspaceIds = userAccess.map(a => a.workspaceId);

      // If a specific workspace is requested, verify access
      if (params.workspaceId && !accessibleWorkspaceIds.includes(params.workspaceId)) {
        throw new AuthorizationError('No admin access to this workspace');
      }
    }

    const where = {
      ...(params.workspaceId && { workspaceId: params.workspaceId }),
      ...(accessibleWorkspaceIds && !params.workspaceId && { workspaceId: { in: accessibleWorkspaceIds } }),
      ...(params.userId && { userId: params.userId }),
      ...(params.action && { action: params.action }),
      ...(params.entityType && { entityType: params.entityType }),
      ...(params.entityId && { entityId: params.entityId }),
      ...(params.startDate && { createdAt: { gte: new Date(params.startDate) } }),
      ...(params.endDate && { createdAt: { lte: new Date(params.endDate) } }),
    };

    const [logs, totalCount] = await Promise.all([
      prisma.auditLog.findMany({
        where,
        include: {
          user: {
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
          }
        },
        orderBy: { createdAt: 'desc' },
        take: params.limit,
        skip: params.offset,
      }),
      prisma.auditLog.count({ where })
    ]);

    res.json({
      logs,
      totalCount,
      limit: params.limit,
      offset: params.offset,
    });
  } catch (error) {
    next(error);
  }
});

// Get audit log by ID
auditRouter.get('/:logId', requireAuth, requireAdmin, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { logId } = req.params;

    const log = await prisma.auditLog.findUnique({
      where: { id: logId },
      include: {
        user: {
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
        }
      }
    });

    if (!log) {
      res.status(404).json({ error: 'Audit log not found' });
      return;
    }

    res.json({ log });
  } catch (error) {
    next(error);
  }
});

// Get audit statistics
auditRouter.get('/stats/summary', requireAuth, requireAdmin, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { workspaceId, days = '7' } = req.query;
    const daysNum = parseInt(days as string, 10);
    const since = new Date(Date.now() - daysNum * 24 * 60 * 60 * 1000);

    const where = {
      createdAt: { gte: since },
      ...(workspaceId && typeof workspaceId === 'string' && { workspaceId }),
    };

    // Get counts by action type
    const actionCounts = await prisma.auditLog.groupBy({
      by: ['action'],
      where,
      _count: true,
    });

    // Get daily counts
    const logs = await prisma.auditLog.findMany({
      where,
      select: {
        createdAt: true,
        action: true,
      },
      orderBy: { createdAt: 'asc' }
    });

    // Group by day
    const dailyCounts: Record<string, number> = {};
    for (const log of logs) {
      const day = log.createdAt.toISOString().split('T')[0];
      dailyCounts[day] = (dailyCounts[day] || 0) + 1;
    }

    // Get most active users
    const userCounts = await prisma.auditLog.groupBy({
      by: ['userId'],
      where: {
        ...where,
        userId: { not: null }
      },
      _count: true,
      orderBy: { _count: { userId: 'desc' } },
      take: 10,
    });

    // Get user details for active users
    const userIds = userCounts.map(u => u.userId).filter(Boolean) as string[];
    const users = await prisma.user.findMany({
      where: { id: { in: userIds } },
      select: {
        id: true,
        username: true,
        displayName: true,
      }
    });

    const userMap = new Map(users.map(u => [u.id, u]));

    res.json({
      period: { days: daysNum, since: since.toISOString() },
      totalEvents: logs.length,
      actionCounts: actionCounts.map(a => ({
        action: a.action,
        count: a._count
      })),
      dailyCounts: Object.entries(dailyCounts).map(([date, count]) => ({
        date,
        count
      })),
      mostActiveUsers: userCounts.map(u => ({
        user: userMap.get(u.userId!),
        count: u._count
      })),
    });
  } catch (error) {
    next(error);
  }
});

// Get entity history
auditRouter.get('/entity/:entityType/:entityId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { entityType, entityId } = req.params;
    const { limit = '20' } = req.query;

    // Get the entity to check workspace access
    let workspaceId: string | null = null;

    switch (entityType) {
      case 'project': {
        const project = await prisma.project.findUnique({
          where: { id: entityId },
          select: { workspaceId: true }
        });
        workspaceId = project?.workspaceId || null;
        break;
      }
      case 'thread': {
        const thread = await prisma.thread.findUnique({
          where: { id: entityId },
          include: { project: { select: { workspaceId: true } } }
        });
        workspaceId = thread?.project.workspaceId || null;
        break;
      }
      case 'upload': {
        const upload = await prisma.upload.findUnique({
          where: { id: entityId },
          select: { workspaceId: true }
        });
        workspaceId = upload?.workspaceId || null;
        break;
      }
      case 'ruleset': {
        const ruleset = await prisma.ruleset.findUnique({
          where: { id: entityId },
          select: { workspaceId: true }
        });
        workspaceId = ruleset?.workspaceId || null;
        break;
      }
    }

    // Check access
    if (workspaceId) {
      const access = await prisma.workspaceAccess.findUnique({
        where: {
          userId_workspaceId: {
            userId: req.user!.id,
            workspaceId
          }
        }
      });

      if (!access || !access.canRead) {
        throw new AuthorizationError('No access to this entity');
      }
    }

    const logs = await prisma.auditLog.findMany({
      where: {
        entityType,
        entityId,
      },
      include: {
        user: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        }
      },
      orderBy: { createdAt: 'desc' },
      take: parseInt(limit as string, 10),
    });

    res.json({ logs });
  } catch (error) {
    next(error);
  }
});
