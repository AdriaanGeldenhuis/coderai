import { Router, Request, Response, NextFunction } from 'express';
import { z } from 'zod';
import { prisma } from '../index.js';
import { requireAuth, checkWorkspaceAccess } from '../middleware/auth.js';
import { NotFoundError, AuthorizationError } from '../middleware/errorHandler.js';
import { createAuditLog } from '../middleware/audit.js';
import { AuditAction } from '@prisma/client';

export const projectRouter = Router();

// Validation schemas
const createProjectSchema = z.object({
  name: z.string().min(1).max(100),
  description: z.string().max(1000).optional(),
  workspaceId: z.string().uuid(),
  settings: z.record(z.unknown()).optional(),
});

const updateProjectSchema = z.object({
  name: z.string().min(1).max(100).optional(),
  description: z.string().max(1000).optional(),
  settings: z.record(z.unknown()).optional(),
  isArchived: z.boolean().optional(),
});

// List all projects in a workspace
projectRouter.get('/', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { workspaceId, includeArchived } = req.query;

    if (!workspaceId || typeof workspaceId !== 'string') {
      throw new AuthorizationError('Workspace ID is required');
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

    const projects = await prisma.project.findMany({
      where: {
        workspaceId,
        ...(includeArchived !== 'true' && { isArchived: false })
      },
      include: {
        owner: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        },
        _count: {
          select: {
            threads: true,
            uploads: true,
          }
        }
      },
      orderBy: { updatedAt: 'desc' }
    });

    res.json({ projects });
  } catch (error) {
    next(error);
  }
});

// Get single project
projectRouter.get('/:projectId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { projectId } = req.params;

    const project = await prisma.project.findUnique({
      where: { id: projectId },
      include: {
        workspace: {
          select: {
            id: true,
            type: true,
            name: true,
          }
        },
        owner: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        },
        threads: {
          where: { isArchived: false },
          orderBy: { updatedAt: 'desc' },
          take: 10,
          select: {
            id: true,
            title: true,
            createdAt: true,
            updatedAt: true,
            _count: {
              select: { messages: true }
            }
          }
        },
        rulesetBindings: {
          where: { isActive: true },
          include: {
            ruleset: {
              select: {
                id: true,
                name: true,
                description: true,
              }
            }
          }
        },
        _count: {
          select: {
            threads: true,
            uploads: true,
          }
        }
      }
    });

    if (!project) {
      throw new NotFoundError('Project not found');
    }

    // Check workspace access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: project.workspaceId
        }
      }
    });

    if (!access || !access.canRead) {
      throw new AuthorizationError('No access to this project');
    }

    res.json({ project });
  } catch (error) {
    next(error);
  }
});

// Create project
projectRouter.post('/', requireAuth, checkWorkspaceAccess, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const data = createProjectSchema.parse(req.body);

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

    const project = await prisma.project.create({
      data: {
        name: data.name,
        description: data.description,
        workspaceId: data.workspaceId,
        ownerId: req.user!.id,
        settings: data.settings as any || {},
      },
      include: {
        workspace: {
          select: {
            id: true,
            type: true,
            name: true,
          }
        },
        owner: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        }
      }
    });

    await createAuditLog(
      AuditAction.PROJECT_CREATE,
      req.user!.id,
      data.workspaceId,
      'project',
      project.id,
      { name: project.name },
      req
    );

    res.status(201).json({ project });
  } catch (error) {
    next(error);
  }
});

// Update project
projectRouter.put('/:projectId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { projectId } = req.params;
    const data = updateProjectSchema.parse(req.body);

    const existingProject = await prisma.project.findUnique({
      where: { id: projectId }
    });

    if (!existingProject) {
      throw new NotFoundError('Project not found');
    }

    // Check write access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: existingProject.workspaceId
        }
      }
    });

    if (!access || !access.canWrite) {
      throw new AuthorizationError('No write access to this project');
    }

    const project = await prisma.project.update({
      where: { id: projectId },
      data: {
        name: data.name,
        description: data.description,
        settings: data.settings as any,
        isArchived: data.isArchived,
      },
      include: {
        workspace: {
          select: {
            id: true,
            type: true,
            name: true,
          }
        },
        owner: {
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
        data.isArchived ? AuditAction.PROJECT_ARCHIVE : AuditAction.PROJECT_UPDATE,
        req.user!.id,
        project.workspaceId,
        'project',
        project.id,
        { name: project.name, archived: data.isArchived },
        req
      );
    } else {
      await createAuditLog(
        AuditAction.PROJECT_UPDATE,
        req.user!.id,
        project.workspaceId,
        'project',
        project.id,
        { changes: data },
        req
      );
    }

    res.json({ project });
  } catch (error) {
    next(error);
  }
});

// Delete project
projectRouter.delete('/:projectId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { projectId } = req.params;

    const existingProject = await prisma.project.findUnique({
      where: { id: projectId }
    });

    if (!existingProject) {
      throw new NotFoundError('Project not found');
    }

    // Check admin access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: existingProject.workspaceId
        }
      }
    });

    if (!access || !access.canAdmin) {
      throw new AuthorizationError('Admin access required to delete projects');
    }

    await prisma.project.delete({
      where: { id: projectId }
    });

    await createAuditLog(
      AuditAction.PROJECT_DELETE,
      req.user!.id,
      existingProject.workspaceId,
      'project',
      projectId,
      { name: existingProject.name },
      req
    );

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});

// Bind ruleset to project
projectRouter.post('/:projectId/rulesets', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { projectId } = req.params;
    const { rulesetId, priority } = z.object({
      rulesetId: z.string().uuid(),
      priority: z.number().int().default(0),
    }).parse(req.body);

    const project = await prisma.project.findUnique({
      where: { id: projectId }
    });

    if (!project) {
      throw new NotFoundError('Project not found');
    }

    // Check write access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: project.workspaceId
        }
      }
    });

    if (!access || !access.canWrite) {
      throw new AuthorizationError('No write access to this project');
    }

    // Verify ruleset is in same workspace
    const ruleset = await prisma.ruleset.findUnique({
      where: { id: rulesetId }
    });

    if (!ruleset || ruleset.workspaceId !== project.workspaceId) {
      throw new NotFoundError('Ruleset not found or not in same workspace');
    }

    const binding = await prisma.projectRulesetBinding.upsert({
      where: {
        projectId_rulesetId: {
          projectId,
          rulesetId
        }
      },
      update: {
        priority,
        isActive: true,
      },
      create: {
        projectId,
        rulesetId,
        priority,
      },
      include: {
        ruleset: {
          select: {
            id: true,
            name: true,
            description: true,
          }
        }
      }
    });

    await createAuditLog(
      AuditAction.RULESET_BIND,
      req.user!.id,
      project.workspaceId,
      'project',
      projectId,
      { rulesetId, rulesetName: ruleset.name },
      req
    );

    res.json({ binding });
  } catch (error) {
    next(error);
  }
});

// Unbind ruleset from project
projectRouter.delete('/:projectId/rulesets/:rulesetId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { projectId, rulesetId } = req.params;

    const project = await prisma.project.findUnique({
      where: { id: projectId }
    });

    if (!project) {
      throw new NotFoundError('Project not found');
    }

    // Check write access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: project.workspaceId
        }
      }
    });

    if (!access || !access.canWrite) {
      throw new AuthorizationError('No write access to this project');
    }

    await prisma.projectRulesetBinding.delete({
      where: {
        projectId_rulesetId: {
          projectId,
          rulesetId
        }
      }
    });

    await createAuditLog(
      AuditAction.RULESET_UNBIND,
      req.user!.id,
      project.workspaceId,
      'project',
      projectId,
      { rulesetId },
      req
    );

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});
