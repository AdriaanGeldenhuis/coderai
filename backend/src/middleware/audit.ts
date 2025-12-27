import { Request, Response, NextFunction } from 'express';
import { prisma } from '../index.js';
import { AuditAction } from '@prisma/client';

// Extend Express Request to include user
declare global {
  namespace Express {
    interface Request {
      userId?: string;
      workspaceId?: string;
    }
  }
}

// Map HTTP methods and paths to audit actions
function getAuditAction(method: string, path: string): AuditAction | null {
  const patterns: [RegExp, string, AuditAction][] = [
    [/^\/api\/auth\/login$/, 'POST', AuditAction.LOGIN],
    [/^\/api\/auth\/logout$/, 'POST', AuditAction.LOGOUT],
    [/^\/api\/users$/, 'POST', AuditAction.USER_CREATE],
    [/^\/api\/users\/[^/]+$/, 'PUT', AuditAction.USER_UPDATE],
    [/^\/api\/projects$/, 'POST', AuditAction.PROJECT_CREATE],
    [/^\/api\/projects\/[^/]+$/, 'PUT', AuditAction.PROJECT_UPDATE],
    [/^\/api\/projects\/[^/]+$/, 'DELETE', AuditAction.PROJECT_DELETE],
    [/^\/api\/threads$/, 'POST', AuditAction.THREAD_CREATE],
    [/^\/api\/threads\/[^/]+$/, 'PUT', AuditAction.THREAD_UPDATE],
    [/^\/api\/messages$/, 'POST', AuditAction.MESSAGE_CREATE],
    [/^\/api\/messages\/[^/]+$/, 'DELETE', AuditAction.MESSAGE_DELETE],
    [/^\/api\/uploads$/, 'POST', AuditAction.UPLOAD_CREATE],
    [/^\/api\/uploads\/[^/]+$/, 'DELETE', AuditAction.UPLOAD_DELETE],
    [/^\/api\/rulesets$/, 'POST', AuditAction.RULESET_CREATE],
    [/^\/api\/rulesets\/[^/]+$/, 'PUT', AuditAction.RULESET_UPDATE],
    [/^\/api\/rulesets\/[^/]+\/lock$/, 'POST', AuditAction.RULESET_LOCK],
    [/^\/api\/rulesets\/[^/]+\/unlock$/, 'POST', AuditAction.RULESET_UNLOCK],
    [/^\/api\/search$/, 'GET', AuditAction.SEARCH_QUERY],
  ];

  for (const [pattern, httpMethod, action] of patterns) {
    if (method === httpMethod && pattern.test(path)) {
      return action;
    }
  }

  return null;
}

export async function auditMiddleware(
  req: Request,
  res: Response,
  next: NextFunction
): Promise<void> {
  // Store original end function
  const originalEnd = res.end;
  const startTime = Date.now();

  // Override end to capture response
  res.end = function(this: Response, ...args: any[]): Response {
    const action = getAuditAction(req.method, req.path);

    // Only log specific actions, not all requests
    if (action && res.statusCode < 400) {
      // Extract entity info from URL
      const pathParts = req.path.split('/').filter(Boolean);
      let entityType: string | undefined;
      let entityId: string | undefined;

      if (pathParts.length >= 3) {
        entityType = pathParts[1]; // e.g., 'projects', 'threads'
        if (pathParts.length >= 4) {
          entityId = pathParts[2]; // The ID in the path
        }
      }

      // Log asynchronously - don't block response
      prisma.auditLog.create({
        data: {
          action,
          userId: req.userId || null,
          workspaceId: req.workspaceId || null,
          entityType,
          entityId: entityId || (req.body?.id as string) || null,
          details: {
            method: req.method,
            path: req.path,
            statusCode: res.statusCode,
            duration: Date.now() - startTime,
          },
          ipAddress: req.ip || req.socket.remoteAddress || null,
          userAgent: req.get('user-agent') || null,
        }
      }).catch(err => {
        console.error('Failed to create audit log:', err);
      });
    }

    return originalEnd.apply(this, args);
  };

  next();
}

// Helper function to create audit logs manually for specific actions
export async function createAuditLog(
  action: AuditAction,
  userId: string | null,
  workspaceId: string | null,
  entityType: string | null,
  entityId: string | null,
  details: Record<string, unknown>,
  req?: Request
): Promise<void> {
  try {
    await prisma.auditLog.create({
      data: {
        action,
        userId,
        workspaceId,
        entityType,
        entityId,
        details,
        ipAddress: req?.ip || req?.socket.remoteAddress || null,
        userAgent: req?.get('user-agent') || null,
      }
    });
  } catch (err) {
    console.error('Failed to create audit log:', err);
  }
}
