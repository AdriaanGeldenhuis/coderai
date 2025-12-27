import { Router, Request, Response, NextFunction } from 'express';
import { z } from 'zod';
import { prisma } from '../index.js';
import { requireAuth } from '../middleware/auth.js';
import { NotFoundError, AuthorizationError, ConflictError, ValidationError } from '../middleware/errorHandler.js';
import { createAuditLog } from '../middleware/audit.js';
import { AuditAction } from '@prisma/client';

export const rulesetRouter = Router();

// Validation schemas
const createRulesetSchema = z.object({
  name: z.string().min(1).max(100),
  description: z.string().max(1000).optional(),
  workspaceId: z.string().uuid(),
  rules: z.record(z.unknown()),
});

const updateRulesetSchema = z.object({
  name: z.string().min(1).max(100).optional(),
  description: z.string().max(1000).optional(),
  isActive: z.boolean().optional(),
});

const createVersionSchema = z.object({
  rules: z.record(z.unknown()),
  changelog: z.string().max(1000).optional(),
});

const lockRulesetSchema = z.object({
  reason: z.string().max(500).optional(),
});

// List rulesets in a workspace
rulesetRouter.get('/', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { workspaceId, includeInactive } = req.query;

    if (!workspaceId || typeof workspaceId !== 'string') {
      throw new ValidationError('Workspace ID is required');
    }

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

    const rulesets = await prisma.ruleset.findMany({
      where: {
        workspaceId,
        ...(includeInactive !== 'true' && { isActive: true })
      },
      include: {
        createdBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        },
        versions: {
          orderBy: { version: 'desc' },
          take: 1,
          select: {
            id: true,
            version: true,
            createdAt: true,
          }
        },
        _count: {
          select: {
            versions: true,
            projectBindings: true,
          }
        }
      },
      orderBy: { name: 'asc' }
    });

    res.json({ rulesets });
  } catch (error) {
    next(error);
  }
});

// Get single ruleset with versions
rulesetRouter.get('/:rulesetId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { rulesetId } = req.params;

    const ruleset = await prisma.ruleset.findUnique({
      where: { id: rulesetId },
      include: {
        workspace: {
          select: {
            id: true,
            type: true,
            name: true,
          }
        },
        createdBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        },
        versions: {
          orderBy: { version: 'desc' },
          take: 10,
          include: {
            createdBy: {
              select: {
                id: true,
                username: true,
                displayName: true,
              }
            }
          }
        },
        projectBindings: {
          where: { isActive: true },
          include: {
            project: {
              select: {
                id: true,
                name: true,
              }
            }
          }
        },
        _count: {
          select: {
            versions: true,
            projectBindings: true,
          }
        }
      }
    });

    if (!ruleset) {
      throw new NotFoundError('Ruleset not found');
    }

    // Check access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: ruleset.workspaceId
        }
      }
    });

    if (!access || !access.canRead) {
      throw new AuthorizationError('No access to this ruleset');
    }

    res.json({ ruleset });
  } catch (error) {
    next(error);
  }
});

// Get specific version of a ruleset
rulesetRouter.get('/:rulesetId/versions/:version', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { rulesetId, version } = req.params;

    const ruleset = await prisma.ruleset.findUnique({
      where: { id: rulesetId },
      select: { workspaceId: true }
    });

    if (!ruleset) {
      throw new NotFoundError('Ruleset not found');
    }

    // Check access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: ruleset.workspaceId
        }
      }
    });

    if (!access || !access.canRead) {
      throw new AuthorizationError('No access to this ruleset');
    }

    const rulesetVersion = await prisma.rulesetVersion.findUnique({
      where: {
        rulesetId_version: {
          rulesetId,
          version: parseInt(version, 10)
        }
      },
      include: {
        createdBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        }
      }
    });

    if (!rulesetVersion) {
      throw new NotFoundError('Version not found');
    }

    res.json({ version: rulesetVersion });
  } catch (error) {
    next(error);
  }
});

// Create ruleset
rulesetRouter.post('/', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const data = createRulesetSchema.parse(req.body);

    // Check write access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: data.workspaceId
        }
      }
    });

    if (!access || !access.canWrite) {
      throw new AuthorizationError('No write access to this workspace');
    }

    // Create ruleset with initial version
    const ruleset = await prisma.ruleset.create({
      data: {
        name: data.name,
        description: data.description,
        workspaceId: data.workspaceId,
        createdById: req.user!.id,
        versions: {
          create: {
            version: 1,
            rules: data.rules as any,
            createdById: req.user!.id,
          }
        }
      },
      include: {
        versions: {
          orderBy: { version: 'desc' },
          take: 1,
        }
      }
    });

    // Set current version
    await prisma.ruleset.update({
      where: { id: ruleset.id },
      data: { currentVersionId: ruleset.versions[0].id }
    });

    await createAuditLog(
      AuditAction.RULESET_CREATE,
      req.user!.id,
      data.workspaceId,
      'ruleset',
      ruleset.id,
      { name: ruleset.name },
      req
    );

    res.status(201).json({ ruleset });
  } catch (error) {
    next(error);
  }
});

// Update ruleset metadata
rulesetRouter.put('/:rulesetId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { rulesetId } = req.params;
    const data = updateRulesetSchema.parse(req.body);

    const existingRuleset = await prisma.ruleset.findUnique({
      where: { id: rulesetId }
    });

    if (!existingRuleset) {
      throw new NotFoundError('Ruleset not found');
    }

    // Check if locked
    if (existingRuleset.isLocked && existingRuleset.lockedById !== req.user!.id) {
      throw new ConflictError('Ruleset is locked by another user');
    }

    // Check write access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: existingRuleset.workspaceId
        }
      }
    });

    if (!access || !access.canWrite) {
      throw new AuthorizationError('No write access to this ruleset');
    }

    const ruleset = await prisma.ruleset.update({
      where: { id: rulesetId },
      data: {
        name: data.name,
        description: data.description,
        isActive: data.isActive,
      }
    });

    await createAuditLog(
      AuditAction.RULESET_UPDATE,
      req.user!.id,
      ruleset.workspaceId,
      'ruleset',
      ruleset.id,
      { changes: data },
      req
    );

    res.json({ ruleset });
  } catch (error) {
    next(error);
  }
});

// Create new version
rulesetRouter.post('/:rulesetId/versions', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { rulesetId } = req.params;
    const data = createVersionSchema.parse(req.body);

    const existingRuleset = await prisma.ruleset.findUnique({
      where: { id: rulesetId },
      include: {
        versions: {
          orderBy: { version: 'desc' },
          take: 1,
        }
      }
    });

    if (!existingRuleset) {
      throw new NotFoundError('Ruleset not found');
    }

    // Check if locked by someone else
    if (existingRuleset.isLocked && existingRuleset.lockedById !== req.user!.id) {
      throw new ConflictError('Ruleset is locked by another user');
    }

    // Check write access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: existingRuleset.workspaceId
        }
      }
    });

    if (!access || !access.canWrite) {
      throw new AuthorizationError('No write access to this ruleset');
    }

    const nextVersion = (existingRuleset.versions[0]?.version || 0) + 1;

    const version = await prisma.rulesetVersion.create({
      data: {
        rulesetId,
        version: nextVersion,
        rules: data.rules as any,
        changelog: data.changelog,
        createdById: req.user!.id,
      },
      include: {
        createdBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        }
      }
    });

    // Update current version
    await prisma.ruleset.update({
      where: { id: rulesetId },
      data: { currentVersionId: version.id }
    });

    await createAuditLog(
      AuditAction.RULESET_VERSION_CREATE,
      req.user!.id,
      existingRuleset.workspaceId,
      'ruleset',
      rulesetId,
      { version: nextVersion, changelog: data.changelog },
      req
    );

    res.status(201).json({ version });
  } catch (error) {
    next(error);
  }
});

// Lock ruleset
rulesetRouter.post('/:rulesetId/lock', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { rulesetId } = req.params;
    const { reason } = lockRulesetSchema.parse(req.body);

    const existingRuleset = await prisma.ruleset.findUnique({
      where: { id: rulesetId }
    });

    if (!existingRuleset) {
      throw new NotFoundError('Ruleset not found');
    }

    if (existingRuleset.isLocked) {
      throw new ConflictError('Ruleset is already locked');
    }

    // Check write access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: existingRuleset.workspaceId
        }
      }
    });

    if (!access || !access.canWrite) {
      throw new AuthorizationError('No write access to this ruleset');
    }

    const ruleset = await prisma.ruleset.update({
      where: { id: rulesetId },
      data: {
        isLocked: true,
        lockedById: req.user!.id,
        lockedAt: new Date(),
        lockReason: reason,
      }
    });

    await createAuditLog(
      AuditAction.RULESET_LOCK,
      req.user!.id,
      ruleset.workspaceId,
      'ruleset',
      ruleset.id,
      { reason },
      req
    );

    res.json({ ruleset });
  } catch (error) {
    next(error);
  }
});

// Unlock ruleset
rulesetRouter.post('/:rulesetId/unlock', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { rulesetId } = req.params;

    const existingRuleset = await prisma.ruleset.findUnique({
      where: { id: rulesetId }
    });

    if (!existingRuleset) {
      throw new NotFoundError('Ruleset not found');
    }

    if (!existingRuleset.isLocked) {
      throw new ConflictError('Ruleset is not locked');
    }

    // Check if user is the one who locked it or is admin
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: existingRuleset.workspaceId
        }
      }
    });

    const canUnlock = existingRuleset.lockedById === req.user!.id || access?.canAdmin;

    if (!canUnlock) {
      throw new AuthorizationError('Only the user who locked this ruleset or an admin can unlock it');
    }

    const ruleset = await prisma.ruleset.update({
      where: { id: rulesetId },
      data: {
        isLocked: false,
        lockedById: null,
        lockedAt: null,
        lockReason: null,
      }
    });

    await createAuditLog(
      AuditAction.RULESET_UNLOCK,
      req.user!.id,
      ruleset.workspaceId,
      'ruleset',
      ruleset.id,
      {},
      req
    );

    res.json({ ruleset });
  } catch (error) {
    next(error);
  }
});

// Delete ruleset (admin only)
rulesetRouter.delete('/:rulesetId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { rulesetId } = req.params;

    const existingRuleset = await prisma.ruleset.findUnique({
      where: { id: rulesetId }
    });

    if (!existingRuleset) {
      throw new NotFoundError('Ruleset not found');
    }

    // Check admin access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: existingRuleset.workspaceId
        }
      }
    });

    if (!access || !access.canAdmin) {
      throw new AuthorizationError('Admin access required to delete rulesets');
    }

    await prisma.ruleset.delete({
      where: { id: rulesetId }
    });

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});
