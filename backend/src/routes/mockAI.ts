import { Router, Request, Response, NextFunction } from 'express';
import { z } from 'zod';
import { requireAuth } from '../middleware/auth.js';
import { generateMockResponse, generateGreeting } from '../services/mockAI.js';
import { WorkspaceType } from '@prisma/client';

export const mockAIRouter = Router();

// Schema for chat request
const chatSchema = z.object({
  messages: z.array(z.object({
    role: z.string(),
    content: z.string(),
  })),
  workspaceType: z.enum(['NORMAL', 'CHURCH', 'CODER']).default('NORMAL'),
  rules: z.array(z.record(z.unknown())).optional(),
});

// Generate a mock AI response
mockAIRouter.post('/chat', requireAuth, async (req: Request, res: Response, next: NextFunction): Promise<void> => {
  try {
    const data = chatSchema.parse(req.body);

    const response = await generateMockResponse(
      data.messages,
      data.workspaceType as WorkspaceType,
      data.rules || []
    );

    res.json({
      response: {
        role: 'assistant',
        content: response.content,
      },
      metadata: {
        model: response.model,
        tokenCount: response.tokenCount,
        mock: true,
      }
    });
  } catch (error) {
    next(error);
  }
});

// Get a greeting for a workspace
mockAIRouter.get('/greeting/:workspaceType', requireAuth, async (req: Request, res: Response): Promise<void> => {
  const { workspaceType } = req.params;

  const validTypes = ['NORMAL', 'CHURCH', 'CODER'];
  const type = validTypes.includes(workspaceType.toUpperCase())
    ? workspaceType.toUpperCase() as WorkspaceType
    : 'NORMAL';

  const greeting = generateGreeting(type);

  res.json({
    greeting,
    workspaceType: type,
    mock: true,
  });
});

// Health check for AI service
mockAIRouter.get('/health', async (_req: Request, res: Response): Promise<void> => {
  res.json({
    status: 'ok',
    mode: 'mock',
    message: 'Mock AI service is running. Replace with real AI integration for production.',
    capabilities: [
      'chat',
      'greeting',
      'workspace-aware responses',
      'rule application',
    ],
  });
});

// Get available models (mock)
mockAIRouter.get('/models', requireAuth, async (_req: Request, res: Response): Promise<void> => {
  res.json({
    models: [
      {
        id: 'mock-general-v1',
        name: 'Mock General Assistant',
        description: 'General purpose mock AI for NORMAL workspace',
        workspaceType: 'NORMAL',
      },
      {
        id: 'mock-church-v1',
        name: 'Mock Church Assistant',
        description: 'Church-focused mock AI for CHURCH workspace',
        workspaceType: 'CHURCH',
      },
      {
        id: 'mock-coder-v1',
        name: 'Mock Coder Assistant',
        description: 'Code-focused mock AI for CODER workspace',
        workspaceType: 'CODER',
      },
    ],
    note: 'These are mock models for testing. Replace with real AI models in production.',
  });
});
