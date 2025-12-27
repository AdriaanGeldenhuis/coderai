import { Router, Request, Response, NextFunction } from 'express';
import bcrypt from 'bcryptjs';
import { z } from 'zod';
import { prisma } from '../index.js';
import { requireAuth, requireAdmin } from '../middleware/auth.js';
import { NotFoundError, ValidationError, ConflictError } from '../middleware/errorHandler.js';
import { createAuditLog } from '../middleware/audit.js';
import { AuditAction, UserRole } from '@prisma/client';

export const userRouter = Router();

// Validation schemas
const createUserSchema = z.object({
  username: z.string().min(3).max(50).regex(/^[a-zA-Z0-9_-]+$/),
  email: z.string().email().optional(),
  password: z.string().min(8),
  displayName: z.string().min(1).max(100),
  role: z.enum(['ADMIN', 'MEMBER']).default('MEMBER'),
  workspaceAccess: z.array(z.object({
    workspaceType: z.enum(['NORMAL', 'CHURCH', 'CODER']),
    canRead: z.boolean().default(true),
    canWrite: z.boolean().default(true),
    canAdmin: z.boolean().default(false),
  })).optional(),
});

const updateUserSchema = z.object({
  email: z.string().email().optional(),
  displayName: z.string().min(1).max(100).optional(),
  role: z.enum(['ADMIN', 'MEMBER']).optional(),
  isActive: z.boolean().optional(),
});

// List all users (admin only)
userRouter.get('/', requireAuth, requireAdmin, async (_req: Request, res: Response): Promise<void> => {
  const users = await prisma.user.findMany({
    select: {
      id: true,
      username: true,
      email: true,
      displayName: true,
      role: true,
      isActive: true,
      twoFactorEnabled: true,
      createdAt: true,
      lastLoginAt: true,
      createdBy: {
        select: {
          id: true,
          username: true,
          displayName: true,
        }
      },
      workspaceAccess: {
        include: {
          workspace: {
            select: {
              id: true,
              type: true,
              name: true,
            }
          }
        }
      }
    },
    orderBy: { createdAt: 'desc' }
  });

  res.json({ users });
});

// Get single user (admin only)
userRouter.get('/:userId', requireAuth, requireAdmin, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { userId } = req.params;

    const user = await prisma.user.findUnique({
      where: { id: userId },
      select: {
        id: true,
        username: true,
        email: true,
        displayName: true,
        role: true,
        isActive: true,
        twoFactorEnabled: true,
        createdAt: true,
        updatedAt: true,
        lastLoginAt: true,
        createdBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        },
        workspaceAccess: {
          include: {
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
          select: {
            projects: true,
            threads: true,
            messages: true,
            uploads: true,
          }
        }
      }
    });

    if (!user) {
      throw new NotFoundError('User not found');
    }

    res.json({ user });
  } catch (error) {
    next(error);
  }
});

// Create user (admin only - family auth model)
userRouter.post('/', requireAuth, requireAdmin, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const data = createUserSchema.parse(req.body);

    // Check if username exists
    const existingUser = await prisma.user.findUnique({
      where: { username: data.username }
    });

    if (existingUser) {
      throw new ConflictError('Username already exists');
    }

    // Check if email exists
    if (data.email) {
      const existingEmail = await prisma.user.findUnique({
        where: { email: data.email }
      });

      if (existingEmail) {
        throw new ConflictError('Email already exists');
      }
    }

    const passwordHash = await bcrypt.hash(data.password, 12);

    const user = await prisma.user.create({
      data: {
        username: data.username,
        email: data.email,
        passwordHash,
        displayName: data.displayName,
        role: data.role as UserRole,
        createdById: req.user!.id,
      },
      select: {
        id: true,
        username: true,
        email: true,
        displayName: true,
        role: true,
        createdAt: true,
      }
    });

    // Grant workspace access if specified
    if (data.workspaceAccess && data.workspaceAccess.length > 0) {
      for (const access of data.workspaceAccess) {
        const workspace = await prisma.workspace.findUnique({
          where: { type: access.workspaceType }
        });

        if (workspace) {
          await prisma.workspaceAccess.create({
            data: {
              userId: user.id,
              workspaceId: workspace.id,
              canRead: access.canRead,
              canWrite: access.canWrite,
              canAdmin: access.canAdmin,
            }
          });
        }
      }
    }

    await createAuditLog(
      AuditAction.USER_CREATE,
      req.user!.id,
      null,
      'user',
      user.id,
      { username: user.username, role: user.role },
      req
    );

    res.status(201).json({ user });
  } catch (error) {
    next(error);
  }
});

// Update user (admin only)
userRouter.put('/:userId', requireAuth, requireAdmin, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { userId } = req.params;
    const data = updateUserSchema.parse(req.body);

    const existingUser = await prisma.user.findUnique({
      where: { id: userId }
    });

    if (!existingUser) {
      throw new NotFoundError('User not found');
    }

    // Check email uniqueness if changing
    if (data.email && data.email !== existingUser.email) {
      const emailExists = await prisma.user.findUnique({
        where: { email: data.email }
      });

      if (emailExists) {
        throw new ConflictError('Email already exists');
      }
    }

    const user = await prisma.user.update({
      where: { id: userId },
      data: {
        email: data.email,
        displayName: data.displayName,
        role: data.role as UserRole | undefined,
        isActive: data.isActive,
      },
      select: {
        id: true,
        username: true,
        email: true,
        displayName: true,
        role: true,
        isActive: true,
        updatedAt: true,
      }
    });

    // Log deactivation/reactivation
    if (data.isActive !== undefined && data.isActive !== existingUser.isActive) {
      await createAuditLog(
        data.isActive ? AuditAction.USER_REACTIVATE : AuditAction.USER_DEACTIVATE,
        req.user!.id,
        null,
        'user',
        user.id,
        { username: user.username },
        req
      );
    } else {
      await createAuditLog(
        AuditAction.USER_UPDATE,
        req.user!.id,
        null,
        'user',
        user.id,
        { changes: data },
        req
      );
    }

    res.json({ user });
  } catch (error) {
    next(error);
  }
});

// Reset user password (admin only)
userRouter.post('/:userId/reset-password', requireAuth, requireAdmin, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { userId } = req.params;
    const { newPassword } = z.object({ newPassword: z.string().min(8) }).parse(req.body);

    const existingUser = await prisma.user.findUnique({
      where: { id: userId }
    });

    if (!existingUser) {
      throw new NotFoundError('User not found');
    }

    const passwordHash = await bcrypt.hash(newPassword, 12);

    await prisma.user.update({
      where: { id: userId },
      data: { passwordHash }
    });

    // Invalidate all sessions for this user
    await prisma.session.deleteMany({
      where: { userId }
    });

    await createAuditLog(
      AuditAction.PASSWORD_CHANGE,
      req.user!.id,
      null,
      'user',
      userId,
      { resetBy: req.user!.username },
      req
    );

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});

// Update user workspace access (admin only)
userRouter.put('/:userId/workspace-access', requireAuth, requireAdmin, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { userId } = req.params;
    const { workspaceAccess } = z.object({
      workspaceAccess: z.array(z.object({
        workspaceType: z.enum(['NORMAL', 'CHURCH', 'CODER']),
        canRead: z.boolean(),
        canWrite: z.boolean(),
        canAdmin: z.boolean(),
      }))
    }).parse(req.body);

    const existingUser = await prisma.user.findUnique({
      where: { id: userId }
    });

    if (!existingUser) {
      throw new NotFoundError('User not found');
    }

    // Remove all existing access
    await prisma.workspaceAccess.deleteMany({
      where: { userId }
    });

    // Grant new access
    for (const access of workspaceAccess) {
      const workspace = await prisma.workspace.findUnique({
        where: { type: access.workspaceType }
      });

      if (workspace) {
        await prisma.workspaceAccess.create({
          data: {
            userId,
            workspaceId: workspace.id,
            canRead: access.canRead,
            canWrite: access.canWrite,
            canAdmin: access.canAdmin,
          }
        });

        await createAuditLog(
          access.canRead ? AuditAction.WORKSPACE_ACCESS_GRANT : AuditAction.WORKSPACE_ACCESS_REVOKE,
          req.user!.id,
          workspace.id,
          'user',
          userId,
          { workspaceType: access.workspaceType, ...access },
          req
        );
      }
    }

    const updatedUser = await prisma.user.findUnique({
      where: { id: userId },
      select: {
        id: true,
        username: true,
        workspaceAccess: {
          include: {
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
    });

    res.json({ user: updatedUser });
  } catch (error) {
    next(error);
  }
});

// Disable user's 2FA (admin only)
userRouter.post('/:userId/disable-2fa', requireAuth, requireAdmin, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { userId } = req.params;

    const existingUser = await prisma.user.findUnique({
      where: { id: userId }
    });

    if (!existingUser) {
      throw new NotFoundError('User not found');
    }

    if (!existingUser.twoFactorEnabled) {
      throw new ValidationError('2FA is not enabled for this user');
    }

    await prisma.user.update({
      where: { id: userId },
      data: {
        twoFactorEnabled: false,
        twoFactorSecret: null,
      }
    });

    await createAuditLog(
      AuditAction.TWO_FACTOR_DISABLE,
      req.user!.id,
      null,
      'user',
      userId,
      { disabledBy: req.user!.username },
      req
    );

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});
