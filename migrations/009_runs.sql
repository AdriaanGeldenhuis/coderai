-- =============================================
-- CODERAI SECTION 10: CODER RUNS
-- Database: MariaDB (Xneelo Managed)
-- =============================================

-- Runs table (controlled code modification process)
CREATE TABLE IF NOT EXISTS runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id INT UNSIGNED NOT NULL,
    repo_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'planning', 'coding', 'reviewing', 'ready', 'applying', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'pending',
    request TEXT NOT NULL,
    plan_json TEXT NULL,
    diff_content TEXT NULL,
    review_result TEXT NULL,
    git_checkpoint VARCHAR(50) NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_runs_thread (thread_id),
    INDEX idx_runs_repo (repo_id),
    INDEX idx_runs_user (user_id),
    INDEX idx_runs_status (status),
    CONSTRAINT fk_runs_thread FOREIGN KEY (thread_id) REFERENCES threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_runs_repo FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE,
    CONSTRAINT fk_runs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Run steps table (track each phase)
CREATE TABLE IF NOT EXISTS run_steps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    phase ENUM('plan', 'code', 'review', 'apply', 'rollback') NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    input_json TEXT NULL,
    output_json TEXT NULL,
    model VARCHAR(50) NULL,
    tokens_used INT UNSIGNED NULL DEFAULT 0,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    error_message TEXT NULL,
    INDEX idx_steps_run (run_id),
    INDEX idx_steps_phase (phase),
    CONSTRAINT fk_steps_run FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
