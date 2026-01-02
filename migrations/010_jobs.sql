-- =============================================
-- CODERAI SECTION 12: BACKGROUND JOBS
-- Database: MariaDB (Xneelo Managed)
-- =============================================

-- Jobs queue table
CREATE TABLE IF NOT EXISTS jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    payload TEXT NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    run_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    error_message TEXT NULL,
    scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jobs_status (status),
    INDEX idx_jobs_type (type),
    INDEX idx_jobs_priority (priority),
    INDEX idx_jobs_scheduled (scheduled_at),
    INDEX idx_jobs_run (run_id),
    CONSTRAINT fk_jobs_run FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cron log table (track cron executions)
CREATE TABLE IF NOT EXISTS cron_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    jobs_processed INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_failed INT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds DECIMAL(10,2) NULL,
    memory_peak_mb DECIMAL(10,2) NULL,
    notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
