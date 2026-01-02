-- =============================================
-- CODERAI SECTION 4: WORKSPACES
-- Database: MariaDB (Xneelo Managed)
-- =============================================

-- Workspaces table (fixed: normal, church, coder)
CREATE TABLE IF NOT EXISTS workspaces (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    icon VARCHAR(10) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    settings_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_workspaces_slug (slug),
    INDEX idx_workspaces_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the 3 fixed workspaces
INSERT INTO workspaces (slug, name, description, icon, settings_json) VALUES
('normal', 'Normal Chat', 'General AI assistant for everyday tasks. Ask questions, get help with research, writing, and more.', '💬', '{"model":"gpt-4","temperature":0.7,"max_tokens":4096}'),
('church', 'Church Chat', 'Spiritual guidance and church-related assistance. Respectful, faith-based conversations.', '⛪', '{"model":"gpt-4","temperature":0.5,"max_tokens":4096,"system_prompt":"You are a respectful, faith-based assistant. Provide spiritual guidance and church-related help."}'),
('coder', 'Coder', 'AI-powered code editor. Modify server files directly with intelligent suggestions and safety checks.', '💻', '{"model":"claude-3-opus","temperature":0.2,"max_tokens":8192,"require_approval":true}')
ON DUPLICATE KEY UPDATE name = VALUES(name);
