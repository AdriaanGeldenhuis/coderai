import { z } from 'zod';

const envSchema = z.object({
  NODE_ENV: z.enum(['development', 'production', 'test']).default('development'),
  PORT: z.string().default('3001'),
  DATABASE_URL: z.string().default('postgresql://postgres:postgres@localhost:5432/coderai'),
  SESSION_SECRET: z.string().default('dev-secret-change-in-production'),
  CORS_ORIGIN: z.string().default('http://localhost:5173'),
  UPLOAD_DIR: z.string().default('./uploads'),
  MAX_FILE_SIZE: z.string().default('52428800'), // 50MB
});

const env = envSchema.parse(process.env);

export const config = {
  nodeEnv: env.NODE_ENV,
  isProduction: env.NODE_ENV === 'production',
  isDevelopment: env.NODE_ENV === 'development',
  port: parseInt(env.PORT, 10),
  databaseUrl: env.DATABASE_URL,
  sessionSecret: env.SESSION_SECRET,
  corsOrigin: env.CORS_ORIGIN,
  uploadDir: env.UPLOAD_DIR,
  maxFileSize: parseInt(env.MAX_FILE_SIZE, 10),
};
