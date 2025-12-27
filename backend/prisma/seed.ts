import { PrismaClient, UserRole, WorkspaceType } from '@prisma/client';
import bcrypt from 'bcryptjs';

const prisma = new PrismaClient();

async function main() {
  console.log('🌱 Starting database seed...');

  // Create workspaces
  console.log('Creating workspaces...');

  const normalWorkspace = await prisma.workspace.upsert({
    where: { type: WorkspaceType.NORMAL },
    update: {},
    create: {
      type: WorkspaceType.NORMAL,
      name: 'General',
      description: 'General purpose workspace for everyday tasks and conversations',
      settings: {
        theme: 'default',
        features: ['chat', 'files', 'search'],
      },
    },
  });

  const churchWorkspace = await prisma.workspace.upsert({
    where: { type: WorkspaceType.CHURCH },
    update: {},
    create: {
      type: WorkspaceType.CHURCH,
      name: 'Church',
      description: 'Church workspace for ministry, sermons, and community',
      settings: {
        theme: 'warm',
        features: ['chat', 'files', 'search', 'sermons', 'events'],
      },
    },
  });

  const coderWorkspace = await prisma.workspace.upsert({
    where: { type: WorkspaceType.CODER },
    update: {},
    create: {
      type: WorkspaceType.CODER,
      name: 'Coder',
      description: 'Development workspace for coding, debugging, and technical projects',
      settings: {
        theme: 'dark',
        features: ['chat', 'files', 'search', 'code', 'terminal'],
        codeHighlighting: true,
      },
    },
  });

  console.log('✓ Workspaces created');

  // Create admin user
  console.log('Creating admin user...');

  const adminPasswordHash = await bcrypt.hash('admin123', 12);

  const adminUser = await prisma.user.upsert({
    where: { username: 'admin' },
    update: {},
    create: {
      username: 'admin',
      email: 'admin@family.local',
      passwordHash: adminPasswordHash,
      displayName: 'Administrator',
      role: UserRole.ADMIN,
      isActive: true,
    },
  });

  console.log('✓ Admin user created (username: admin, password: admin123)');

  // Grant admin access to all workspaces
  console.log('Granting workspace access...');

  for (const workspace of [normalWorkspace, churchWorkspace, coderWorkspace]) {
    await prisma.workspaceAccess.upsert({
      where: {
        userId_workspaceId: {
          userId: adminUser.id,
          workspaceId: workspace.id,
        },
      },
      update: {},
      create: {
        userId: adminUser.id,
        workspaceId: workspace.id,
        canRead: true,
        canWrite: true,
        canAdmin: true,
      },
    });
  }

  console.log('✓ Workspace access granted');

  // Create sample projects
  console.log('Creating sample projects...');

  const generalProject = await prisma.project.upsert({
    where: { id: 'sample-general-project' },
    update: {},
    create: {
      id: 'sample-general-project',
      name: 'Getting Started',
      description: 'Welcome project with introductory threads and examples',
      workspaceId: normalWorkspace.id,
      ownerId: adminUser.id,
      settings: {},
    },
  });

  const churchProject = await prisma.project.upsert({
    where: { id: 'sample-church-project' },
    update: {},
    create: {
      id: 'sample-church-project',
      name: 'Sermon Planning',
      description: 'Plan and organize sermons and church activities',
      workspaceId: churchWorkspace.id,
      ownerId: adminUser.id,
      settings: {},
    },
  });

  const coderProject = await prisma.project.upsert({
    where: { id: 'sample-coder-project' },
    update: {},
    create: {
      id: 'sample-coder-project',
      name: 'Development Workspace',
      description: 'Code review, debugging, and development assistance',
      workspaceId: coderWorkspace.id,
      ownerId: adminUser.id,
      settings: {
        language: 'typescript',
        framework: 'react',
      },
    },
  });

  console.log('✓ Sample projects created');

  // Create sample rulesets
  console.log('Creating sample rulesets...');

  const generalRuleset = await prisma.ruleset.upsert({
    where: { id: 'sample-general-ruleset' },
    update: {},
    create: {
      id: 'sample-general-ruleset',
      name: 'General Guidelines',
      description: 'Basic response guidelines for general conversations',
      workspaceId: normalWorkspace.id,
      createdById: adminUser.id,
      isActive: true,
    },
  });

  await prisma.rulesetVersion.upsert({
    where: {
      rulesetId_version: {
        rulesetId: generalRuleset.id,
        version: 1,
      },
    },
    update: {},
    create: {
      rulesetId: generalRuleset.id,
      version: 1,
      rules: {
        tone: 'friendly',
        responseLength: 'moderate',
        includeEmoji: false,
        guidelines: [
          'Be helpful and concise',
          'Ask clarifying questions when needed',
          'Provide actionable suggestions',
        ],
      },
      changelog: 'Initial version',
      createdById: adminUser.id,
    },
  });

  const coderRuleset = await prisma.ruleset.upsert({
    where: { id: 'sample-coder-ruleset' },
    update: {},
    create: {
      id: 'sample-coder-ruleset',
      name: 'Code Review Standards',
      description: 'Standards for code review and development assistance',
      workspaceId: coderWorkspace.id,
      createdById: adminUser.id,
      isActive: true,
    },
  });

  await prisma.rulesetVersion.upsert({
    where: {
      rulesetId_version: {
        rulesetId: coderRuleset.id,
        version: 1,
      },
    },
    update: {},
    create: {
      rulesetId: coderRuleset.id,
      version: 1,
      rules: {
        codeStyle: 'clean-code',
        preferredLanguages: ['typescript', 'javascript', 'python'],
        includeComments: true,
        guidelines: [
          'Follow SOLID principles',
          'Write self-documenting code',
          'Include error handling',
          'Suggest tests when appropriate',
        ],
      },
      changelog: 'Initial coding standards',
      createdById: adminUser.id,
    },
  });

  console.log('✓ Sample rulesets created');

  // Create sample threads with messages
  console.log('Creating sample threads and messages...');

  const welcomeThread = await prisma.thread.upsert({
    where: { id: 'sample-welcome-thread' },
    update: {},
    create: {
      id: 'sample-welcome-thread',
      title: 'Welcome to CoderAI',
      projectId: generalProject.id,
      createdById: adminUser.id,
    },
  });

  await prisma.message.upsert({
    where: { id: 'sample-welcome-message-1' },
    update: {},
    create: {
      id: 'sample-welcome-message-1',
      threadId: welcomeThread.id,
      role: 'SYSTEM',
      content: 'Welcome to CoderAI! This is a family AI platform with three separate workspaces: General, Church, and Coder.',
    },
  });

  await prisma.message.upsert({
    where: { id: 'sample-welcome-message-2' },
    update: {},
    create: {
      id: 'sample-welcome-message-2',
      threadId: welcomeThread.id,
      role: 'ASSISTANT',
      content: `Hello! I'm your AI assistant. Here's what you can do in this platform:

**General Workspace**
- Everyday conversations and tasks
- File management and search
- Personal productivity

**Church Workspace**
- Sermon planning and preparation
- Community event coordination
- Spiritual resources

**Coder Workspace**
- Code review and debugging
- Development assistance
- Technical documentation

Feel free to explore each workspace. The admin can create accounts for family members with customized access to each workspace.

Is there anything specific you'd like to know about?`,
      modelUsed: 'mock-general-v1',
      tokenCount: 150,
    },
  });

  console.log('✓ Sample threads and messages created');

  // Create audit log entry for seed
  await prisma.auditLog.create({
    data: {
      action: 'USER_CREATE',
      userId: adminUser.id,
      entityType: 'system',
      entityId: 'seed',
      details: {
        event: 'Database seeded',
        workspacesCreated: 3,
        projectsCreated: 3,
        rulesetsCreated: 2,
      },
    },
  });

  console.log('✓ Audit log entry created');

  console.log('\n✅ Database seed completed successfully!');
  console.log('\n📝 Default admin credentials:');
  console.log('   Username: admin');
  console.log('   Password: admin123');
  console.log('\n⚠️  Please change the admin password after first login!');
}

main()
  .catch((e) => {
    console.error('❌ Seed failed:', e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
