-- =============================================
-- CODERAI SECTION 9: REPOS (SERVER FILE TARGETS)
-- Database: MariaDB (Xneelo Managed)
-- =============================================

-- Repos table (defines which server folders can be modified)
CREATE TABLE IF NOT EXISTS repos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    base_path VARCHAR(500) NOT NULL,
    allowed_paths_json TEXT NULL,
    read_only TINYINT(1) NOT NULL DEFAULT 0,
    maintenance_lock TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_repos_user (user_id),
    INDEX idx_repos_active (is_active),
    CONSTRAINT fk_repos_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
