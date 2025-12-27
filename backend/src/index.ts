import express from 'express';
import cors from 'cors';
import helmet from 'helmet';
import session from 'express-session';
import rateLimit from 'express-rate-limit';
import { PrismaClient } from '@prisma/client';
import { config } from './config/index.js';
import { errorHandler } from './middleware/errorHandler.js';
import { auditMiddleware } from './middleware/audit.js';
import { authRouter } from './routes/auth.js';
import { userRouter } from './routes/users.js';
import { workspaceRouter } from './routes/workspaces.js';
import { projectRouter } from './routes/projects.js';
import { threadRouter } from './routes/threads.js';
import { messageRouter } from './routes/messages.js';
import { uploadRouter } from './routes/uploads.js';
import { rulesetRouter } from './routes/rulesets.js';
import { searchRouter } from './routes/search.js';
import { auditRouter } from './routes/audit.js';
import { mockAIRouter } from './routes/mockAI.js';

export const prisma = new PrismaClient();

const app = express();

// Security middleware
app.use(helmet());
app.use(cors({
  origin: config.corsOrigin,
  credentials: true
}));

// Rate limiting
const limiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutes
  max: 100, // Limit each IP to 100 requests per windowMs
  message: { error: 'Too many requests, please try again later.' }
});
app.use('/api', limiter);

// Body parsing
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));

// Session configuration
app.use(session({
  secret: config.sessionSecret,
  resave: false,
  saveUninitialized: false,
  cookie: {
    secure: config.isProduction,
    httpOnly: true,
    maxAge: 24 * 60 * 60 * 1000 // 24 hours
  }
}));

// Audit middleware - logs all requests
app.use(auditMiddleware);

// Health check
app.get('/health', (_req, res) => {
  res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

// API Routes
app.use('/api/auth', authRouter);
app.use('/api/users', userRouter);
app.use('/api/workspaces', workspaceRouter);
app.use('/api/projects', projectRouter);
app.use('/api/threads', threadRouter);
app.use('/api/messages', messageRouter);
app.use('/api/uploads', uploadRouter);
app.use('/api/rulesets', rulesetRouter);
app.use('/api/search', searchRouter);
app.use('/api/audit', auditRouter);
app.use('/api/mock-ai', mockAIRouter);

// Error handling
app.use(errorHandler);

// Start server
const PORT = config.port;

async function main() {
  try {
    await prisma.$connect();
    console.log('✅ Database connected');

    app.listen(PORT, () => {
      console.log(`🚀 Server running on http://localhost:${PORT}`);
      console.log(`📊 Environment: ${config.nodeEnv}`);
    });
  } catch (error) {
    console.error('❌ Failed to start server:', error);
    process.exit(1);
  }
}

main();

// Graceful shutdown
process.on('SIGINT', async () => {
  await prisma.$disconnect();
  process.exit(0);
});

process.on('SIGTERM', async () => {
  await prisma.$disconnect();
  process.exit(0);
});
