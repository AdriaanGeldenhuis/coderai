# CoderAI - Family AI Platform

A private, family-oriented AI platform with separate workspaces for different use cases: General, Church, and Coder.

## Features

### Phase 1 - Foundation (Current)

- **Family-Only Authentication**: Admin creates user accounts with optional 2FA
- **Three Workspaces**: Hard-separated workspaces for different contexts
  - **General**: Everyday conversations and tasks
  - **Church**: Ministry, sermons, and community activities
  - **Coder**: Development, debugging, and technical projects
- **Projects & Threads**: Organize conversations into projects with multiple chat threads
- **File Uploads**: Upload, scan, and index files for each workspace
- **Rulesets**: Version-controlled rules with locking mechanism
- **Global Search**: Search across messages, files, and rulesets
- **Audit Logging**: Complete audit trail of all actions
- **Mock AI**: Test the platform without real AI integration

## Tech Stack

- **Backend**: Node.js, Express, TypeScript
- **Database**: PostgreSQL with Prisma ORM
- **Frontend**: React, TypeScript, Vite, TailwindCSS
- **State Management**: Zustand, TanStack Query
- **Authentication**: Session-based with optional TOTP 2FA

## Getting Started

### Prerequisites

- Node.js 18+
- PostgreSQL 14+
- npm or yarn

### Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd coderai
```

2. Install dependencies:
```bash
npm install
cd backend && npm install
cd ../frontend && npm install
```

3. Set up the database:
```bash
cd backend
cp .env.example .env
# Edit .env with your database credentials
npx prisma migrate dev
npx prisma db seed
```

4. Start the development servers:
```bash
# From the root directory
npm run dev
```

5. Open http://localhost:5173 in your browser

### Default Credentials

- **Username**: admin
- **Password**: admin123

**Important**: Change the admin password after first login!

## Project Structure

```
coderai/
├── backend/           # Express API server
│   ├── prisma/        # Database schema and migrations
│   ├── src/
│   │   ├── config/    # Configuration
│   │   ├── middleware/# Express middleware
│   │   ├── routes/    # API routes
│   │   └── services/  # Business logic
│   └── uploads/       # Uploaded files (gitignored)
├── frontend/          # React application
│   ├── src/
│   │   ├── layouts/   # Page layouts
│   │   ├── lib/       # API client and utilities
│   │   ├── pages/     # Page components
│   │   └── stores/    # Zustand stores
└── package.json       # Root package with scripts
```

## API Endpoints

### Authentication
- `POST /api/auth/login` - Login
- `POST /api/auth/logout` - Logout
- `GET /api/auth/me` - Current user
- `POST /api/auth/verify-2fa` - Verify 2FA code
- `POST /api/auth/2fa/setup` - Setup 2FA
- `POST /api/auth/2fa/enable` - Enable 2FA
- `POST /api/auth/2fa/disable` - Disable 2FA

### Users (Admin only)
- `GET /api/users` - List users
- `POST /api/users` - Create user
- `PUT /api/users/:id` - Update user
- `PUT /api/users/:id/workspace-access` - Update workspace access

### Workspaces
- `GET /api/workspaces` - List accessible workspaces
- `GET /api/workspaces/type/:type` - Get workspace by type

### Projects
- `GET /api/projects` - List projects
- `POST /api/projects` - Create project
- `PUT /api/projects/:id` - Update project

### Threads & Messages
- `GET /api/threads` - List threads
- `POST /api/threads` - Create thread
- `GET /api/messages` - List messages
- `POST /api/messages` - Send message (triggers mock AI response)

### Uploads
- `GET /api/uploads` - List uploads
- `POST /api/uploads` - Upload file
- `GET /api/uploads/:id/download` - Download file

### Rulesets
- `GET /api/rulesets` - List rulesets
- `POST /api/rulesets` - Create ruleset
- `POST /api/rulesets/:id/versions` - Create new version
- `POST /api/rulesets/:id/lock` - Lock ruleset
- `POST /api/rulesets/:id/unlock` - Unlock ruleset

### Search
- `GET /api/search` - Global search

### Audit
- `GET /api/audit` - List audit logs
- `GET /api/audit/stats/summary` - Audit statistics

## Next Steps (Phase 2+)

- Real AI model integration (OpenAI, Anthropic, local models)
- Advanced file processing (PDF parsing, document analysis)
- Real-time collaboration features
- Mobile app

## License

Private - Family use only
