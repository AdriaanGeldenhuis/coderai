import { Request, Response, NextFunction } from 'express';
import { prisma } from '../index.js';
import { AuthenticationError, AuthorizationError } from './errorHandler.js';
import { UserRole, WorkspaceType } from '@prisma/client';

// Extend session to include userId
declare module 'express-session' {
  interface SessionData {
    userId: string;
    twoFactorVerified?: boolean;
  }
}

// Extend Request to include user data
declare global {
  namespace Express {
    interface Request {
      user?: {
        id: string;
        username: string;
        displayName: string;
        role: UserRole;
        twoFactorEnabled: boolean;
      };
    }
  }
}

// Require authentication
export async function requireAuth(
  req: Request,
  _res: Response,
  next: NextFunction
): Promise<void> {
  try {
    const sessionUserId = req.session.userId;

    if (!sessionUserId) {
      throw new AuthenticationError('Not authenticated');
    }

    // Check for session token in header for API clients
    const authHeader = req.headers.authorization;
    let userId = sessionUserId;

    if (authHeader?.startsWith('Bearer ')) {
      const token = authHeader.slice(7);
      const session = await prisma.session.findUnique({
        where: { token },
        include: { user: true }
      });

      if (!session || session.expiresAt < new Date()) {
        throw new AuthenticationError('Invalid or expired session');
      }

      userId = session.userId;

      // Update last active
      await prisma.session.update({
        where: { id: session.id },
        data: { lastActiveAt: new Date() }
      });
    }

    const user = await prisma.user.findUnique({
      where: { id: userId },
      select: {
        id: true,
        username: true,
        displayName: true,
        role: true,
        isActive: true,
        twoFactorEnabled: true,
      }
    });

    if (!user || !user.isActive) {
      throw new AuthenticationError('User not found or inactive');
    }

    // Check 2FA if enabled
    if (user.twoFactorEnabled && !req.session.twoFactorVerified) {
      throw new AuthenticationError('Two-factor authentication required');
    }

    req.user = user;
    req.userId = user.id;

    next();
  } catch (error) {
    next(error);
  }
}

// Require admin role
export function requireAdmin(
  req: Request,
  _res: Response,
  next: NextFunction
): void {
  if (!req.user) {
    next(new AuthenticationError('Not authenticated'));
    return;
  }

  if (req.user.role !== UserRole.ADMIN) {
    next(new AuthorizationError('Admin access required'));
    return;
  }

  next();
}

// Require workspace access
export function requireWorkspaceAccess(workspaceType: WorkspaceType) {
  return async (req: Request, _res: Response, next: NextFunction): Promise<void> => {
    try {
      if (!req.user) {
        throw new AuthenticationError('Not authenticated');
      }

      const workspace = await prisma.workspace.findUnique({
        where: { type: workspaceType }
      });

      if (!workspace) {
        throw new AuthorizationError(`Workspace ${workspaceType} not found`);
      }

      const access = await prisma.workspaceAccess.findUnique({
        where: {
          userId_workspaceId: {
            userId: req.user.id,
            workspaceId: workspace.id
          }
        }
      });

      if (!access || !access.canRead) {
        throw new AuthorizationError(`No access to ${workspaceType} workspace`);
      }

      req.workspaceId = workspace.id;
      next();
    } catch (error) {
      next(error);
    }
  };
}

// Require write access to workspace
export function requireWorkspaceWrite(workspaceType: WorkspaceType) {
  return async (req: Request, _res: Response, next: NextFunction): Promise<void> => {
    try {
      if (!req.user) {
        throw new AuthenticationError('Not authenticated');
      }

      const workspace = await prisma.workspace.findUnique({
        where: { type: workspaceType }
      });

      if (!workspace) {
        throw new AuthorizationError(`Workspace ${workspaceType} not found`);
      }

      const access = await prisma.workspaceAccess.findUnique({
        where: {
          userId_workspaceId: {
            userId: req.user.id,
            workspaceId: workspace.id
          }
        }
      });

      if (!access || !access.canWrite) {
        throw new AuthorizationError(`No write access to ${workspaceType} workspace`);
      }

      req.workspaceId = workspace.id;
      next();
    } catch (error) {
      next(error);
    }
  };
}

// Middleware to check access to a specific workspace by ID
export async function checkWorkspaceAccess(
  req: Request,
  _res: Response,
  next: NextFunction
): Promise<void> {
  try {
    const workspaceId = req.params.workspaceId || req.body.workspaceId;

    if (!workspaceId) {
      next();
      return;
    }

    if (!req.user) {
      throw new AuthenticationError('Not authenticated');
    }

    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user.id,
          workspaceId
        }
      }
    });

    if (!access || !access.canRead) {
      throw new AuthorizationError('No access to this workspace');
    }

    req.workspaceId = workspaceId;
    next();
  } catch (error) {
    next(error);
  }
}
