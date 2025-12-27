import { Router, Request, Response, NextFunction } from 'express';
import bcrypt from 'bcryptjs';
import { authenticator } from 'otplib';
import QRCode from 'qrcode';
import { v4 as uuidv4 } from 'uuid';
import { z } from 'zod';
import { prisma } from '../index.js';
import { requireAuth } from '../middleware/auth.js';
import { AuthenticationError, ValidationError } from '../middleware/errorHandler.js';
import { createAuditLog } from '../middleware/audit.js';
import { AuditAction } from '@prisma/client';

export const authRouter = Router();

// Validation schemas
const loginSchema = z.object({
  username: z.string().min(1),
  password: z.string().min(1),
});

const verifyTwoFactorSchema = z.object({
  code: z.string().length(6),
});

const changePasswordSchema = z.object({
  currentPassword: z.string().min(1),
  newPassword: z.string().min(8),
});

// Login
authRouter.post('/login', async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { username, password } = loginSchema.parse(req.body);

    const user = await prisma.user.findUnique({
      where: { username }
    });

    if (!user || !user.isActive) {
      await createAuditLog(
        AuditAction.LOGIN_FAILED,
        null,
        null,
        'user',
        null,
        { username, reason: 'User not found or inactive' },
        req
      );
      throw new AuthenticationError('Invalid credentials');
    }

    const validPassword = await bcrypt.compare(password, user.passwordHash);

    if (!validPassword) {
      await createAuditLog(
        AuditAction.LOGIN_FAILED,
        user.id,
        null,
        'user',
        user.id,
        { reason: 'Invalid password' },
        req
      );
      throw new AuthenticationError('Invalid credentials');
    }

    // Create session
    const token = uuidv4();
    const expiresAt = new Date(Date.now() + 24 * 60 * 60 * 1000); // 24 hours

    await prisma.session.create({
      data: {
        userId: user.id,
        token,
        userAgent: req.get('user-agent'),
        ipAddress: req.ip || req.socket.remoteAddress,
        expiresAt,
      }
    });

    // Update last login
    await prisma.user.update({
      where: { id: user.id },
      data: { lastLoginAt: new Date() }
    });

    // Set session
    req.session.userId = user.id;
    req.session.twoFactorVerified = !user.twoFactorEnabled;

    await createAuditLog(
      AuditAction.LOGIN,
      user.id,
      null,
      'user',
      user.id,
      { twoFactorRequired: user.twoFactorEnabled },
      req
    );

    res.json({
      user: {
        id: user.id,
        username: user.username,
        displayName: user.displayName,
        role: user.role,
        twoFactorEnabled: user.twoFactorEnabled,
      },
      token,
      requiresTwoFactor: user.twoFactorEnabled,
    });
  } catch (error) {
    next(error);
  }
});

// Verify 2FA code
authRouter.post('/verify-2fa', async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    if (!req.session.userId) {
      throw new AuthenticationError('Not authenticated');
    }

    const { code } = verifyTwoFactorSchema.parse(req.body);

    const user = await prisma.user.findUnique({
      where: { id: req.session.userId }
    });

    if (!user || !user.twoFactorSecret) {
      throw new AuthenticationError('2FA not configured');
    }

    const isValid = authenticator.verify({
      token: code,
      secret: user.twoFactorSecret
    });

    if (!isValid) {
      throw new AuthenticationError('Invalid 2FA code');
    }

    req.session.twoFactorVerified = true;

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});

// Logout
authRouter.post('/logout', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const authHeader = req.headers.authorization;

    if (authHeader?.startsWith('Bearer ')) {
      const token = authHeader.slice(7);
      await prisma.session.delete({
        where: { token }
      }).catch(() => {}); // Ignore if session doesn't exist
    }

    await createAuditLog(
      AuditAction.LOGOUT,
      req.user!.id,
      null,
      'user',
      req.user!.id,
      {},
      req
    );

    req.session.destroy((err) => {
      if (err) {
        console.error('Session destroy error:', err);
      }
      res.json({ success: true });
    });
  } catch (error) {
    next(error);
  }
});

// Get current user
authRouter.get('/me', requireAuth, async (req: Request, res: Response): Promise<void> => {
  const user = await prisma.user.findUnique({
    where: { id: req.user!.id },
    select: {
      id: true,
      username: true,
      email: true,
      displayName: true,
      role: true,
      twoFactorEnabled: true,
      createdAt: true,
      lastLoginAt: true,
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

  res.json({ user });
});

// Setup 2FA
authRouter.post('/2fa/setup', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const user = await prisma.user.findUnique({
      where: { id: req.user!.id }
    });

    if (!user) {
      throw new AuthenticationError('User not found');
    }

    if (user.twoFactorEnabled) {
      throw new ValidationError('2FA is already enabled');
    }

    const secret = authenticator.generateSecret();
    const otpauth = authenticator.keyuri(user.username, 'CoderAI', secret);
    const qrCode = await QRCode.toDataURL(otpauth);

    // Store secret temporarily (will be confirmed on verification)
    await prisma.user.update({
      where: { id: user.id },
      data: { twoFactorSecret: secret }
    });

    res.json({
      secret,
      qrCode,
    });
  } catch (error) {
    next(error);
  }
});

// Enable 2FA
authRouter.post('/2fa/enable', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { code } = verifyTwoFactorSchema.parse(req.body);

    const user = await prisma.user.findUnique({
      where: { id: req.user!.id }
    });

    if (!user || !user.twoFactorSecret) {
      throw new ValidationError('2FA setup not initiated');
    }

    const isValid = authenticator.verify({
      token: code,
      secret: user.twoFactorSecret
    });

    if (!isValid) {
      throw new AuthenticationError('Invalid 2FA code');
    }

    await prisma.user.update({
      where: { id: user.id },
      data: { twoFactorEnabled: true }
    });

    await createAuditLog(
      AuditAction.TWO_FACTOR_ENABLE,
      user.id,
      null,
      'user',
      user.id,
      {},
      req
    );

    req.session.twoFactorVerified = true;

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});

// Disable 2FA
authRouter.post('/2fa/disable', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { code } = verifyTwoFactorSchema.parse(req.body);

    const user = await prisma.user.findUnique({
      where: { id: req.user!.id }
    });

    if (!user || !user.twoFactorEnabled || !user.twoFactorSecret) {
      throw new ValidationError('2FA is not enabled');
    }

    const isValid = authenticator.verify({
      token: code,
      secret: user.twoFactorSecret
    });

    if (!isValid) {
      throw new AuthenticationError('Invalid 2FA code');
    }

    await prisma.user.update({
      where: { id: user.id },
      data: {
        twoFactorEnabled: false,
        twoFactorSecret: null
      }
    });

    await createAuditLog(
      AuditAction.TWO_FACTOR_DISABLE,
      user.id,
      null,
      'user',
      user.id,
      {},
      req
    );

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});

// Change password
authRouter.post('/change-password', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { currentPassword, newPassword } = changePasswordSchema.parse(req.body);

    const user = await prisma.user.findUnique({
      where: { id: req.user!.id }
    });

    if (!user) {
      throw new AuthenticationError('User not found');
    }

    const validPassword = await bcrypt.compare(currentPassword, user.passwordHash);

    if (!validPassword) {
      throw new AuthenticationError('Current password is incorrect');
    }

    const newPasswordHash = await bcrypt.hash(newPassword, 12);

    await prisma.user.update({
      where: { id: user.id },
      data: { passwordHash: newPasswordHash }
    });

    // Invalidate all other sessions
    await prisma.session.deleteMany({
      where: {
        userId: user.id,
        NOT: {
          id: req.session.id
        }
      }
    });

    await createAuditLog(
      AuditAction.PASSWORD_CHANGE,
      user.id,
      null,
      'user',
      user.id,
      {},
      req
    );

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});

// List active sessions
authRouter.get('/sessions', requireAuth, async (req: Request, res: Response): Promise<void> => {
  const sessions = await prisma.session.findMany({
    where: {
      userId: req.user!.id,
      expiresAt: { gt: new Date() }
    },
    select: {
      id: true,
      userAgent: true,
      ipAddress: true,
      createdAt: true,
      lastActiveAt: true,
    },
    orderBy: { lastActiveAt: 'desc' }
  });

  res.json({ sessions });
});

// Revoke a session
authRouter.delete('/sessions/:sessionId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { sessionId } = req.params;

    await prisma.session.deleteMany({
      where: {
        id: sessionId,
        userId: req.user!.id
      }
    });

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});
