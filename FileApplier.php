<?php
/**
 * CoderAI File Applier
 * Applies diff changes to actual files on the server
 * ✅ Section 6: Enhanced security with read_only and maintenance_lock
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/Coder.php';
require_once __DIR__ . '/../RulesService.php';

class FileApplier
{
    /**
     * ✅ Apply diff to repository files
     * Now accepts repo_id in options for security checks
     * 
     * @param string $diff The diff content to apply
     * @param string $repoPath The base path of the repository
     * @param array $options Options including repo_id for security validation
     */
    public static function apply($diff, $repoPath, $options = [])
    {
        // ✅ SECTION 6.3: Check repo status if repo_id provided
        if (isset($options['repo_id'])) {
            $repoCheck = self::checkRepoStatus($options['repo_id']);
            if (!$repoCheck['allowed']) {
                throw new Exception($repoCheck['error']);
            }
        }

        $rules = RulesService::loadRules('coder');
        $blockedPaths = $rules['restrictions']['blocked_paths'] ?? [];
        $allowedExtensions = $rules['restrictions']['allowed_extensions'] ?? [];
        $maxFileSize = $rules['restrictions']['max_file_size_bytes'] ?? 1048576;

        // Get allowed_paths from repo if available
        $allowedSubPaths = $options['allowed_paths'] ?? [];

        // Parse diff into file changes
        $changes = Coder::parseDiff($diff);

        if (empty($changes)) {
            throw new Exception('No valid changes found in diff');
        }

        $results = [];
        $errors = [];

        foreach ($changes as $change) {
            $filePath = $change['file'];
            $fullPath = $repoPath . '/' . ltrim($filePath, '/');

            try {
                // Security checks
                self::validatePath($fullPath, $repoPath, $blockedPaths);
                self::validateExtension($filePath, $allowedExtensions);
                
                // ✅ Check against allowed_paths if set
                if (!empty($allowedSubPaths)) {
                    self::validateAllowedPath($filePath, $allowedSubPaths);
                }

                // Determine action from diff
                $action = self::determineAction($change['diff']);

                switch ($action) {
                    case 'create':
                        $result = self::createFile($fullPath, $change['diff'], $maxFileSize);
                        break;
                    case 'delete':
                        $result = self::deleteFile($fullPath);
                        break;
                    case 'modify':
                    default:
                        $result = self::modifyFile($fullPath, $change['diff'], $maxFileSize);
                        break;
                }

                $results[] = [
                    'file' => $filePath,
                    'action' => $action,
                    'success' => true,
                    'details' => $result
                ];

            } catch (Exception $e) {
                $errors[] = [
                    'file' => $filePath,
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'success' => empty($errors),
            'applied' => $results,
            'errors' => $errors,
            'total_files' => count($changes),
            'successful' => count($results),
            'failed' => count($errors)
        ];
    }

    /**
     * ✅ SECTION 6.3: Check repo status (read_only, maintenance_lock)
     */
    private static function checkRepoStatus($repoId)
    {
        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT read_only, maintenance_lock, is_active FROM repos WHERE id = ?");
        $stmt->execute([$repoId]);
        $repo = $stmt->fetch();

        if (!$repo) {
            return ['allowed' => false, 'error' => 'Repository not found'];
        }

        if (!$repo['is_active']) {
            return ['allowed' => false, 'error' => 'Repository has been deleted'];
        }

        if ($repo['maintenance_lock']) {
            return ['allowed' => false, 'error' => 'Repository is under maintenance lock. All operations blocked.'];
        }

        if ($repo['read_only']) {
            return ['allowed' => false, 'error' => 'Repository is read-only. Modifications not allowed.'];
        }

        return ['allowed' => true];
    }

    /**
     * Validate file path is safe
     */
    private static function validatePath($fullPath, $repoPath, $blockedPaths)
    {
        // Resolve real path (handles ../ etc)
        $realRepoPath = realpath($repoPath);
        if (!$realRepoPath) {
            throw new Exception('Repository path does not exist');
        }

        // For new files, check parent directory
        $checkPath = file_exists($fullPath) ? realpath($fullPath) : realpath(dirname($fullPath));

        if (!$checkPath) {
            // Parent directory doesn't exist, will need to create
            $checkPath = dirname($fullPath);
            // Make sure it's still under repo
            if (strpos($checkPath, $realRepoPath) !== 0) {
                throw new Exception('Path traversal attempt detected');
            }
        } else {
            // Ensure path is within repo
            if (strpos($checkPath, $realRepoPath) !== 0) {
                throw new Exception('Path traversal attempt detected');
            }
        }

        // Check against blocked paths
        foreach ($blockedPaths as $blocked) {
            $blocked = str_replace('~', getenv('HOME') ?: '/home', $blocked);
            if (strpos($fullPath, $blocked) !== false ||
                fnmatch("*{$blocked}*", $fullPath)) {
                throw new Exception("Access to blocked path: {$blocked}");
            }
        }

        return true;
    }

    /**
     * Validate file extension
     */
    private static function validateExtension($filePath, $allowedExtensions)
    {
        if (empty($allowedExtensions)) {
            return true;
        }

        $extension = '.' . strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Also check for dotfiles like .htaccess
        $basename = basename($filePath);
        if (strpos($basename, '.') === 0) {
            $extension = $basename;
        }

        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception("File extension not allowed: {$extension}");
        }

        return true;
    }

    /**
     * ✅ Validate file is within allowed_paths
     */
    private static function validateAllowedPath($filePath, $allowedPaths)
    {
        // Remove leading slash for comparison
        $filePath = ltrim($filePath, '/');
        
        foreach ($allowedPaths as $allowed) {
            $allowed = ltrim($allowed, '/');
            if (strpos($filePath, $allowed) === 0 || strpos($allowed, dirname($filePath)) === 0) {
                return true;
            }
        }

        throw new Exception("File path not in allowed paths: {$filePath}");
    }

    /**
     * Determine action from diff content
     */
    private static function determineAction($diff)
    {
        // Check for new file (--- /dev/null)
        if (preg_match('/^---\s+\/dev\/null/m', $diff)) {
            return 'create';
        }

        // Check for delete (+++ /dev/null)
        if (preg_match('/^\+\+\+\s+\/dev\/null/m', $diff)) {
            return 'delete';
        }

        return 'modify';
    }

    /**
     * Create a new file
     */
    private static function createFile($fullPath, $diff, $maxFileSize)
    {
        // Extract content from diff (all + lines)
        $content = self::extractNewContent($diff);

        if (strlen($content) > $maxFileSize) {
            throw new Exception('File size exceeds limit');
        }

        // Create directory if needed
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception('Failed to create directory');
            }
        }

        // Check if file already exists
        if (file_exists($fullPath)) {
            throw new Exception('File already exists (expected new file)');
        }

        // Write file
        if (file_put_contents($fullPath, $content) === false) {
            throw new Exception('Failed to write file');
        }

        return [
            'created' => true,
            'size' => strlen($content),
            'lines' => substr_count($content, "\n") + 1
        ];
    }

    /**
     * Delete a file
     */
    private static function deleteFile($fullPath)
    {
        if (!file_exists($fullPath)) {
            return ['deleted' => true, 'note' => 'File did not exist'];
        }

        // Create backup before delete
        $backup = $fullPath . '.coderai-backup-' . time();
        copy($fullPath, $backup);

        if (!unlink($fullPath)) {
            throw new Exception('Failed to delete file');
        }

        return [
            'deleted' => true,
            'backup' => basename($backup)
        ];
    }

    /**
     * Modify an existing file
     */
    private static function modifyFile($fullPath, $diff, $maxFileSize)
    {
        if (!file_exists($fullPath)) {
            throw new Exception('File does not exist for modification');
        }

        if (!is_readable($fullPath) || !is_writable($fullPath)) {
            throw new Exception('File is not readable/writable');
        }

        // Read current content
        $originalContent = file_get_contents($fullPath);
        if ($originalContent === false) {
            throw new Exception('Failed to read file');
        }

        // Apply diff
        $newContent = Coder::applyDiffToContent($originalContent, $diff);

        if (strlen($newContent) > $maxFileSize) {
            throw new Exception('Modified file size exceeds limit');
        }

        // Create backup
        $backup = $fullPath . '.coderai-backup-' . time();
        copy($fullPath, $backup);

        // Write new content
        if (file_put_contents($fullPath, $newContent) === false) {
            // Restore from backup
            copy($backup, $fullPath);
            throw new Exception('Failed to write file');
        }

        return [
            'modified' => true,
            'original_size' => strlen($originalContent),
            'new_size' => strlen($newContent),
            'backup' => basename($backup)
        ];
    }

    /**
     * Extract new content from diff (for new files)
     */
    private static function extractNewContent($diff)
    {
        $lines = explode("\n", $diff);
        $content = [];

        foreach ($lines as $line) {
            // Skip diff headers
            if (strpos($line, '---') === 0 || strpos($line, '+++') === 0) {
                continue;
            }
            if (strpos($line, '@@') === 0) {
                continue;
            }
            // Get added lines (remove the + prefix)
            if (strpos($line, '+') === 0) {
                $content[] = substr($line, 1);
            }
        }

        return implode("\n", $content);
    }

    /**
     * Preview changes without applying
     */
    public static function preview($diff, $repoPath, $options = [])
    {
        // ✅ Still check repo status for preview
        if (isset($options['repo_id'])) {
            $repoCheck = self::checkRepoStatus($options['repo_id']);
            // For preview, we show status but don't block
            $repoStatus = $repoCheck;
        } else {
            $repoStatus = ['allowed' => true];
        }

        $changes = Coder::parseDiff($diff);
        $preview = [];

        foreach ($changes as $change) {
            $filePath = $change['file'];
            $fullPath = $repoPath . '/' . ltrim($filePath, '/');
            $action = self::determineAction($change['diff']);

            $preview[] = [
                'file' => $filePath,
                'action' => $action,
                'exists' => file_exists($fullPath),
                'diff_lines' => substr_count($change['diff'], "\n") + 1
            ];
        }

        return [
            'repo_status' => $repoStatus,
            'changes' => $preview
        ];
    }

    /**
     * Clean up old backup files
     */
    public static function cleanupBackups($repoPath, $maxAgeHours = 24)
    {
        $pattern = $repoPath . '/**/*.coderai-backup-*';
        $files = glob($pattern, GLOB_BRACE);
        $deleted = 0;
        $maxAge = time() - ($maxAgeHours * 3600);

        foreach ($files as $file) {
            if (filemtime($file) < $maxAge) {
                unlink($file);
                $deleted++;
            }
        }

        return ['deleted' => $deleted];
    }
}