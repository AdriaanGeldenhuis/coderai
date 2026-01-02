<?php
/**
 * CoderAI Git Service
 * Handles git checkpoints and rollback operations
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class GitService
{
    /**
     * Create a checkpoint (commit) before applying changes
     */
    public static function createCheckpoint($repoPath, $message = 'CoderAI checkpoint')
    {
        // Verify it's a git repository
        if (!is_dir($repoPath . '/.git')) {
            throw new Exception('Not a git repository: ' . $repoPath);
        }

        $originalDir = getcwd();
        chdir($repoPath);

        try {
            // Stage all changes (including untracked)
            self::exec('git add -A');

            // Check if there are changes to commit
            $status = self::exec('git status --porcelain');
            if (empty(trim($status))) {
                // No changes, return current HEAD
                $hash = trim(self::exec('git rev-parse HEAD'));
                chdir($originalDir);
                return [
                    'hash' => $hash,
                    'message' => 'No changes to checkpoint',
                    'created' => false
                ];
            }

            // Create commit
            $timestamp = date('Y-m-d H:i:s');
            $fullMessage = "[CoderAI] {$message} - {$timestamp}";
            self::exec('git commit -m ' . escapeshellarg($fullMessage));

            // Get commit hash
            $hash = trim(self::exec('git rev-parse HEAD'));

            chdir($originalDir);

            return [
                'hash' => $hash,
                'message' => $fullMessage,
                'created' => true
            ];

        } catch (Exception $e) {
            chdir($originalDir);
            throw $e;
        }
    }

    /**
     * Rollback to a specific checkpoint
     */
    public static function rollback($repoPath, $checkpointHash)
    {
        if (!is_dir($repoPath . '/.git')) {
            throw new Exception('Not a git repository: ' . $repoPath);
        }

        if (!preg_match('/^[a-f0-9]{7,40}$/i', $checkpointHash)) {
            throw new Exception('Invalid commit hash format');
        }

        $originalDir = getcwd();
        chdir($repoPath);

        try {
            // Verify the hash exists
            $verify = trim(self::exec('git cat-file -t ' . escapeshellarg($checkpointHash) . ' 2>&1'));
            if ($verify !== 'commit') {
                throw new Exception('Checkpoint not found: ' . $checkpointHash);
            }

            // Get current HEAD for reference
            $currentHead = trim(self::exec('git rev-parse HEAD'));

            // Hard reset to the checkpoint
            self::exec('git reset --hard ' . escapeshellarg($checkpointHash));

            // Get the new HEAD
            $newHead = trim(self::exec('git rev-parse HEAD'));

            chdir($originalDir);

            return [
                'success' => true,
                'previous_head' => $currentHead,
                'current_head' => $newHead,
                'rolled_back_to' => $checkpointHash
            ];

        } catch (Exception $e) {
            chdir($originalDir);
            throw $e;
        }
    }

    /**
     * Get current HEAD hash
     */
    public static function getCurrentHead($repoPath)
    {
        if (!is_dir($repoPath . '/.git')) {
            return null;
        }

        $originalDir = getcwd();
        chdir($repoPath);

        try {
            $hash = trim(self::exec('git rev-parse HEAD 2>/dev/null'));
            chdir($originalDir);
            return $hash ?: null;
        } catch (Exception $e) {
            chdir($originalDir);
            return null;
        }
    }

    /**
     * Check if repo has uncommitted changes
     */
    public static function hasUncommittedChanges($repoPath)
    {
        if (!is_dir($repoPath . '/.git')) {
            return false;
        }

        $originalDir = getcwd();
        chdir($repoPath);

        try {
            $status = self::exec('git status --porcelain');
            chdir($originalDir);
            return !empty(trim($status));
        } catch (Exception $e) {
            chdir($originalDir);
            return false;
        }
    }

    /**
     * Get recent commits
     */
    public static function getRecentCommits($repoPath, $limit = 10)
    {
        if (!is_dir($repoPath . '/.git')) {
            return [];
        }

        $originalDir = getcwd();
        chdir($repoPath);

        try {
            $format = '%H|%s|%ai|%an';
            $output = self::exec('git log --format=' . escapeshellarg($format) . ' -n ' . (int)$limit);

            $commits = [];
            foreach (explode("\n", trim($output)) as $line) {
                if (empty($line)) continue;
                $parts = explode('|', $line, 4);
                if (count($parts) === 4) {
                    $commits[] = [
                        'hash' => $parts[0],
                        'message' => $parts[1],
                        'date' => $parts[2],
                        'author' => $parts[3]
                    ];
                }
            }

            chdir($originalDir);
            return $commits;

        } catch (Exception $e) {
            chdir($originalDir);
            return [];
        }
    }

    /**
     * Initialize git repo if not exists
     */
    public static function initRepo($repoPath)
    {
        if (is_dir($repoPath . '/.git')) {
            return ['initialized' => false, 'message' => 'Already a git repository'];
        }

        $originalDir = getcwd();
        chdir($repoPath);

        try {
            self::exec('git init');
            self::exec('git config user.email "coderai@localhost"');
            self::exec('git config user.name "CoderAI"');

            // Initial commit
            self::exec('git add -A');
            $status = self::exec('git status --porcelain');
            if (!empty(trim($status))) {
                self::exec('git commit -m "Initial CoderAI checkpoint"');
            }

            chdir($originalDir);

            return [
                'initialized' => true,
                'message' => 'Git repository initialized'
            ];

        } catch (Exception $e) {
            chdir($originalDir);
            throw $e;
        }
    }

    /**
     * Execute shell command safely
     */
    private static function exec($command)
    {
        $output = [];
        $returnCode = 0;

        exec($command . ' 2>&1', $output, $returnCode);

        $result = implode("\n", $output);

        if ($returnCode !== 0) {
            throw new Exception("Git command failed: {$result}");
        }

        return $result;
    }
}
