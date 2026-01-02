-- =============================================
-- CODERAI: Add workspace_slug to projects
-- This allows projects to work without workspaces table
-- =============================================

ALTER TABLE projects
ADD COLUMN workspace_slug VARCHAR(50) NULL DEFAULT 'normal' AFTER workspace_id;

-- Update existing projects
UPDATE projects p
LEFT JOIN workspaces w ON p.workspace_id = w.id
SET p.workspace_slug = COALESCE(w.slug, 'normal');

-- Make workspace_id nullable if not already
ALTER TABLE projects MODIFY COLUMN workspace_id INT UNSIGNED NULL;
