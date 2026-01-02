-- =============================================
-- CODERAI: Update ai_usage to use workspace_slug
-- =============================================

-- Add workspace_slug column
ALTER TABLE ai_usage
ADD COLUMN workspace_slug VARCHAR(50) NULL DEFAULT 'normal' AFTER workspace_id;

-- Copy existing data (if any)
UPDATE ai_usage au
LEFT JOIN workspaces w ON au.workspace_id = w.id
SET au.workspace_slug = COALESCE(w.slug, 'normal');

-- Make workspace_id nullable
ALTER TABLE ai_usage MODIFY COLUMN workspace_id INT UNSIGNED NULL;
