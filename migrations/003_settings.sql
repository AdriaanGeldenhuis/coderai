-- =============================================
-- CODERAI SECTION 3: SETTINGS
-- Database: MariaDB (Xneelo Managed)
-- =============================================

-- Settings table (key-value store with JSON values)
CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    value_json TEXT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO settings (setting_key, value_json, is_encrypted, description) VALUES
('openai_api_key', NULL, 1, 'OpenAI API Key (encrypted)'),
('anthropic_api_key', NULL, 1, 'Anthropic API Key (encrypted)'),
('default_model', '"gpt-4"', 0, 'Default AI model'),
('model_routing', '{"normal":"gpt-4","church":"gpt-4","coder_plan":"gpt-4","coder_code":"claude-3-opus","coder_review":"gpt-4"}', 0, 'Model routing per workspace'),
('budget_monthly', '100', 0, 'Monthly budget in USD'),
('budget_warning_threshold', '80', 0, 'Warning at percentage of budget'),
('safety_require_approval', 'true', 0, 'Require approval for code changes'),
('safety_auto_backup', 'true', 0, 'Auto backup before changes'),
('safety_max_file_size', '1048576', 0, 'Max file size in bytes (1MB)'),
('maintenance_mode', 'false', 0, 'Global maintenance mode')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
