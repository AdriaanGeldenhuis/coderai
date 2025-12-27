import { Router, Request, Response, NextFunction } from 'express';
import multer from 'multer';
import path from 'path';
import fs from 'fs/promises';
import crypto from 'crypto';
import { z } from 'zod';
import { prisma } from '../index.js';
import { requireAuth } from '../middleware/auth.js';
import { NotFoundError, AuthorizationError, ValidationError } from '../middleware/errorHandler.js';
import { createAuditLog } from '../middleware/audit.js';
import { AuditAction, UploadStatus } from '@prisma/client';
import { config } from '../config/index.js';
import { scanFile, indexFile } from '../services/fileProcessor.js';

export const uploadRouter = Router();

// Configure multer for file uploads
const storage = multer.diskStorage({
  destination: async (_req, _file, cb) => {
    const uploadPath = config.uploadDir;
    await fs.mkdir(uploadPath, { recursive: true });
    cb(null, uploadPath);
  },
  filename: (_req, file, cb) => {
    const uniqueSuffix = Date.now() + '-' + crypto.randomBytes(8).toString('hex');
    const ext = path.extname(file.originalname);
    cb(null, uniqueSuffix + ext);
  }
});

const fileFilter = (_req: Request, file: Express.Multer.File, cb: multer.FileFilterCallback) => {
  // Allowed file types
  const allowedTypes = [
    'text/plain',
    'text/markdown',
    'text/csv',
    'application/json',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
    'application/zip',
    'application/x-tar',
    'application/gzip',
  ];

  // Also allow code files
  const codeExtensions = ['.js', '.ts', '.py', '.java', '.c', '.cpp', '.h', '.go', '.rs', '.rb', '.php', '.html', '.css', '.scss', '.json', '.yaml', '.yml', '.xml', '.sql', '.sh', '.bash', '.md', '.txt'];
  const ext = path.extname(file.originalname).toLowerCase();

  if (allowedTypes.includes(file.mimetype) || codeExtensions.includes(ext)) {
    cb(null, true);
  } else {
    cb(new ValidationError(`File type not allowed: ${file.mimetype}`));
  }
};

const upload = multer({
  storage,
  fileFilter,
  limits: {
    fileSize: config.maxFileSize,
  }
});

// List uploads in a workspace
uploadRouter.get('/', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { workspaceId, projectId, status } = req.query;

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

    const uploads = await prisma.upload.findMany({
      where: {
        workspaceId,
        ...(projectId && typeof projectId === 'string' && { projectId }),
        ...(status && typeof status === 'string' && { status: status as UploadStatus }),
      },
      include: {
        uploadedBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        },
        project: {
          select: {
            id: true,
            name: true,
          }
        }
      },
      orderBy: { createdAt: 'desc' }
    });

    res.json({ uploads });
  } catch (error) {
    next(error);
  }
});

// Get single upload
uploadRouter.get('/:uploadId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { uploadId } = req.params;

    const uploadRecord = await prisma.upload.findUnique({
      where: { id: uploadId },
      include: {
        uploadedBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        },
        project: {
          select: {
            id: true,
            name: true,
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

    if (!uploadRecord) {
      throw new NotFoundError('Upload not found');
    }

    // Check access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: uploadRecord.workspaceId
        }
      }
    });

    if (!access || !access.canRead) {
      throw new AuthorizationError('No access to this upload');
    }

    res.json({ upload: uploadRecord });
  } catch (error) {
    next(error);
  }
});

// Upload file
uploadRouter.post('/', requireAuth, upload.single('file'), async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { workspaceId, projectId } = z.object({
      workspaceId: z.string().uuid(),
      projectId: z.string().uuid().optional(),
    }).parse(req.body);

    if (!req.file) {
      throw new ValidationError('No file uploaded');
    }

    // Check write access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId
        }
      }
    });

    if (!access || !access.canWrite) {
      // Clean up uploaded file
      await fs.unlink(req.file.path).catch(() => {});
      throw new AuthorizationError('No write access to this workspace');
    }

    // If projectId provided, verify it's in the same workspace
    if (projectId) {
      const project = await prisma.project.findUnique({
        where: { id: projectId }
      });

      if (!project || project.workspaceId !== workspaceId) {
        await fs.unlink(req.file.path).catch(() => {});
        throw new ValidationError('Project not found or not in this workspace');
      }
    }

    // Calculate checksum
    const fileBuffer = await fs.readFile(req.file.path);
    const checksum = crypto.createHash('sha256').update(fileBuffer).digest('hex');

    // Create upload record
    const uploadRecord = await prisma.upload.create({
      data: {
        filename: req.file.filename,
        originalName: req.file.originalname,
        mimeType: req.file.mimetype,
        size: req.file.size,
        storagePath: req.file.path,
        checksum,
        status: 'PENDING',
        workspaceId,
        projectId,
        uploadedById: req.user!.id,
      },
      include: {
        uploadedBy: {
          select: {
            id: true,
            username: true,
            displayName: true,
          }
        }
      }
    });

    await createAuditLog(
      AuditAction.UPLOAD_CREATE,
      req.user!.id,
      workspaceId,
      'upload',
      uploadRecord.id,
      { filename: uploadRecord.originalName, size: uploadRecord.size, mimeType: uploadRecord.mimeType },
      req
    );

    // Process file asynchronously (scan + index)
    processUpload(uploadRecord.id).catch(err => {
      console.error('Upload processing error:', err);
    });

    res.status(201).json({ upload: uploadRecord });
  } catch (error) {
    next(error);
  }
});

// Download file
uploadRouter.get('/:uploadId/download', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { uploadId } = req.params;

    const uploadRecord = await prisma.upload.findUnique({
      where: { id: uploadId }
    });

    if (!uploadRecord) {
      throw new NotFoundError('Upload not found');
    }

    // Check access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: uploadRecord.workspaceId
        }
      }
    });

    if (!access || !access.canRead) {
      throw new AuthorizationError('No access to this upload');
    }

    // Don't allow downloading quarantined files
    if (uploadRecord.status === 'QUARANTINED') {
      throw new AuthorizationError('This file has been quarantined');
    }

    res.download(uploadRecord.storagePath, uploadRecord.originalName);
  } catch (error) {
    next(error);
  }
});

// Delete upload
uploadRouter.delete('/:uploadId', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { uploadId } = req.params;

    const uploadRecord = await prisma.upload.findUnique({
      where: { id: uploadId }
    });

    if (!uploadRecord) {
      throw new NotFoundError('Upload not found');
    }

    // Check write access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: uploadRecord.workspaceId
        }
      }
    });

    // Allow owner or admin to delete
    const isOwner = uploadRecord.uploadedById === req.user!.id;
    const isAdmin = access?.canAdmin || false;

    if (!access || (!isOwner && !isAdmin)) {
      throw new AuthorizationError('Cannot delete this upload');
    }

    // Delete file from storage
    await fs.unlink(uploadRecord.storagePath).catch(() => {});

    // Delete record
    await prisma.upload.delete({
      where: { id: uploadId }
    });

    await createAuditLog(
      AuditAction.UPLOAD_DELETE,
      req.user!.id,
      uploadRecord.workspaceId,
      'upload',
      uploadId,
      { filename: uploadRecord.originalName },
      req
    );

    res.json({ success: true });
  } catch (error) {
    next(error);
  }
});

// Reprocess upload (re-scan and re-index)
uploadRouter.post('/:uploadId/reprocess', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const { uploadId } = req.params;

    const uploadRecord = await prisma.upload.findUnique({
      where: { id: uploadId }
    });

    if (!uploadRecord) {
      throw new NotFoundError('Upload not found');
    }

    // Check admin access
    const access = await prisma.workspaceAccess.findUnique({
      where: {
        userId_workspaceId: {
          userId: req.user!.id,
          workspaceId: uploadRecord.workspaceId
        }
      }
    });

    if (!access || !access.canAdmin) {
      throw new AuthorizationError('Admin access required');
    }

    // Reset status and reprocess
    await prisma.upload.update({
      where: { id: uploadId },
      data: {
        status: 'PENDING',
        scanResult: null,
        indexedContent: null,
      }
    });

    processUpload(uploadId).catch(err => {
      console.error('Upload reprocessing error:', err);
    });

    res.json({ success: true, message: 'Reprocessing started' });
  } catch (error) {
    next(error);
  }
});

// Process upload (scan + index)
async function processUpload(uploadId: string): Promise<void> {
  const uploadRecord = await prisma.upload.findUnique({
    where: { id: uploadId }
  });

  if (!uploadRecord) return;

  try {
    // Update status to scanning
    await prisma.upload.update({
      where: { id: uploadId },
      data: { status: 'SCANNING' }
    });

    // Scan file for malware/issues
    const scanResult = await scanFile(uploadRecord.storagePath, uploadRecord.mimeType);

    if (!scanResult.safe) {
      // Quarantine the file
      await prisma.upload.update({
        where: { id: uploadId },
        data: {
          status: 'QUARANTINED',
          scanResult: scanResult as any,
        }
      });

      await createAuditLog(
        AuditAction.UPLOAD_QUARANTINE,
        null,
        uploadRecord.workspaceId,
        'upload',
        uploadId,
        { reason: scanResult.reason },
      );

      return;
    }

    // Update status to processing
    await prisma.upload.update({
      where: { id: uploadId },
      data: {
        status: 'PROCESSING',
        scanResult: scanResult as any,
      }
    });

    // Index file content
    const indexedContent = await indexFile(uploadRecord.storagePath, uploadRecord.mimeType);

    // Update to indexed
    await prisma.upload.update({
      where: { id: uploadId },
      data: {
        status: 'INDEXED',
        indexedContent,
      }
    });

    await createAuditLog(
      AuditAction.UPLOAD_SCAN_COMPLETE,
      null,
      uploadRecord.workspaceId,
      'upload',
      uploadId,
      { indexed: !!indexedContent },
    );
  } catch (error) {
    console.error('Upload processing error:', error);

    await prisma.upload.update({
      where: { id: uploadId },
      data: {
        status: 'FAILED',
        scanResult: { error: (error as Error).message } as any,
      }
    });
  }
}
