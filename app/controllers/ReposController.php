<?php
/**
 * CoderAI Repos Controller
 * Manages server folder targets for code modifications
 * ✅ Section 6: Enhanced path validation and security
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../middleware/RequireAuth.php';
require_once __DIR__ . '/../core/Auth.php';

class ReposController
{
    /**
     * Dangerous system paths that should never be accessible
     */
    private const BLOCKED_PATHS = [
        '/etc',
        '/root',
        '/var/log',
        '/usr/bin',
        '/usr/sbin',
        '/bin',
        '/sbin',
        '/boot',
        '/proc',
        '/sys',
        '/dev'
    ];

    /**
     * GET /api/repos
     * List all repos for current user
     */
    public function index($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("
            SELECT id, label, base_path, allowed_paths_json, read_only, maintenance_lock, is_active, created_at
            FROM repos
            WHERE user_id = ? AND is_active = 1
            ORDER BY label ASC
        ");
        $stmt->execute([$userId]);
        $repos = $stmt->fetchAll();

        foreach ($repos as &$repo) {
            $repo['allowed_paths'] = json_decode($repo['allowed_paths_json'], true) ?? [];
            unset($repo['allowed_paths_json']);
        }

        Response::success($repos);
    }

    /**
     * POST /api/repos
     * Create new repo with enhanced validation
     */
    public function store($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        Response::validate($input, ['label', 'base_path']);

        $basePath = rtrim($input['base_path'], '/');

        // ✅ SECTION 6.1: Enhanced path validation using realpath
        $validationResult = $this->validateBasePath($basePath);
        if (!$validationResult['valid']) {
            Response::error($validationResult['error'], $validationResult['code']);
        }

        $db = Bootstrap::getDB();

        // Check for duplicate
        $stmt = $db->prepare("SELECT id FROM repos WHERE user_id = ? AND base_path = ? AND is_active = 1");
        $stmt->execute([$userId, $basePath]);
        if ($stmt->fetch()) {
            Response::error('Repo with this path already exists', 422);
        }

        // ✅ Validate allowed_paths if provided
        $allowedPaths = null;
        if (isset($input['allowed_paths']) && is_array($input['allowed_paths'])) {
            $validatedPaths = $this->validateAllowedPaths($input['allowed_paths'], $basePath);
            $allowedPaths = json_encode($validatedPaths);
        }

        $stmt = $db->prepare("
            INSERT INTO repos (user_id, label, base_path, allowed_paths_json, read_only)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $input['label'],
            $basePath,
            $allowedPaths,
            $input['read_only'] ?? 0
        ]);

        $repoId = $db->lastInsertId();

        Response::success(['id' => $repoId], 'Repo created', 201);
    }

    /**
     * GET /api/repos/{id}
     * Get single repo
     */
    public function show($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("
            SELECT * FROM repos WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$params['id'], $userId]);
        $repo = $stmt->fetch();

        if (!$repo) {
            Response::error('Repo not found', 404);
        }

        $repo['allowed_paths'] = json_decode($repo['allowed_paths_json'], true) ?? [];
        unset($repo['allowed_paths_json']);

        // Get directory listing
        if (is_dir($repo['base_path'])) {
            $repo['files'] = $this->getDirectoryListing($repo['base_path'], 2);
        }

        Response::success($repo);
    }

    /**
     * PUT /api/repos/{id}
     * Update repo
     */
    public function update($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        // Check ownership and get current data
        $stmt = $db->prepare("SELECT * FROM repos WHERE id = ? AND user_id = ?");
        $stmt->execute([$params['id'], $userId]);
        $repo = $stmt->fetch();
        
        if (!$repo) {
            Response::error('Repo not found', 404);
        }

        $updates = [];
        $values = [];

        if (isset($input['label'])) {
            $updates[] = 'label = ?';
            $values[] = $input['label'];
        }

        if (isset($input['allowed_paths']) && is_array($input['allowed_paths'])) {
            // ✅ Validate allowed_paths against base_path
            $validatedPaths = $this->validateAllowedPaths($input['allowed_paths'], $repo['base_path']);
            $updates[] = 'allowed_paths_json = ?';
            $values[] = json_encode($validatedPaths);
        }

        if (isset($input['read_only'])) {
            $updates[] = 'read_only = ?';
            $values[] = $input['read_only'] ? 1 : 0;
        }

        if (isset($input['maintenance_lock'])) {
            $updates[] = 'maintenance_lock = ?';
            $values[] = $input['maintenance_lock'] ? 1 : 0;
        }

        if (empty($updates)) {
            Response::error('No fields to update', 422);
        }

        $values[] = $params['id'];
        $stmt = $db->prepare("UPDATE repos SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($values);

        Response::success(null, 'Repo updated');
    }

    /**
     * DELETE /api/repos/{id}
     * Delete repo (soft delete)
     */
    public function destroy($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();

        // Check ownership
        $stmt = $db->prepare("SELECT id FROM repos WHERE id = ? AND user_id = ?");
        $stmt->execute([$params['id'], $userId]);
        if (!$stmt->fetch()) {
            Response::error('Repo not found', 404);
        }

        $stmt = $db->prepare("UPDATE repos SET is_active = 0 WHERE id = ?");
        $stmt->execute([$params['id']]);

        Response::success(null, 'Repo deleted');
    }

    /**
     * GET /api/repos/{id}/files
     * Browse files in repo
     */
    public function files($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT * FROM repos WHERE id = ? AND user_id = ?");
        $stmt->execute([$params['id'], $userId]);
        $repo = $stmt->fetch();

        if (!$repo) {
            Response::error('Repo not found', 404);
        }

        $subPath = $_GET['path'] ?? '';
        
        // ✅ SECTION 6.2: Validate subpath
        $pathValidation = $this->validateSubPath($subPath, $repo['base_path'], $repo['allowed_paths_json']);
        if (!$pathValidation['valid']) {
            Response::error($pathValidation['error'], 403);
        }

        $fullPath = $pathValidation['resolved_path'];

        if (!is_dir($fullPath)) {
            Response::error('Path is not a directory', 422);
        }

        $files = $this->getDirectoryListing($fullPath, 1);

        Response::success([
            'path' => $subPath,
            'files' => $files
        ]);
    }

    /**
     * ✅ NEW: GET /api/repos/{id}/validate
     * Validate repo accessibility
     */
    public function validate($params, $input)
    {
        RequireAuth::handle();
        $userId = Auth::id();

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT * FROM repos WHERE id = ? AND user_id = ?");
        $stmt->execute([$params['id'], $userId]);
        $repo = $stmt->fetch();

        if (!$repo) {
            Response::error('Repo not found', 404);
        }

        $validation = [
            'repo_id' => $repo['id'],
            'label' => $repo['label'],
            'base_path' => $repo['base_path'],
            'checks' => []
        ];

        // Check 1: Path exists
        $pathExists = is_dir($repo['base_path']);
        $validation['checks']['path_exists'] = [
            'passed' => $pathExists,
            'message' => $pathExists ? 'Path exists' : 'Path does not exist on server'
        ];

        if (!$pathExists) {
            $validation['overall'] = 'failed';
            Response::success($validation);
            return;
        }

        // Check 2: Can read
        $canRead = is_readable($repo['base_path']);
        $validation['checks']['readable'] = [
            'passed' => $canRead,
            'message' => $canRead ? 'Directory is readable' : 'Cannot read directory (permission denied)'
        ];

        // Check 3: Can write (only if not read_only)
        if (!$repo['read_only']) {
            $testFile = $repo['base_path'] . '/.coderai-write-test-' . time();
            $canWrite = @file_put_contents($testFile, 'test') !== false;
            if ($canWrite) {
                @unlink($testFile);
            }
            $validation['checks']['writable'] = [
                'passed' => $canWrite,
                'message' => $canWrite ? 'Directory is writable' : 'Cannot write to directory (permission denied)'
            ];
        } else {
            $validation['checks']['writable'] = [
                'passed' => true,
                'message' => 'Skipped (repo is read-only)',
                'skipped' => true
            ];
        }

        // Check 4: Git status
        $isGitRepo = is_dir($repo['base_path'] . '/.git');
        $gitStatus = 'not_initialized';
        
        if ($isGitRepo) {
            $currentDir = getcwd();
            chdir($repo['base_path']);
            exec('git status --porcelain 2>&1', $output, $returnCode);
            chdir($currentDir);
            
            if ($returnCode === 0) {
                $gitStatus = empty(trim(implode('', $output))) ? 'clean' : 'has_changes';
            } else {
                $gitStatus = 'error';
            }
        }

        $validation['checks']['git'] = [
            'passed' => $isGitRepo,
            'status' => $gitStatus,
            'message' => $isGitRepo 
                ? "Git repository ($gitStatus)" 
                : 'Not a git repository (rollback unavailable)'
        ];

        // Check 5: Maintenance lock
        $validation['checks']['maintenance_lock'] = [
            'passed' => !$repo['maintenance_lock'],
            'message' => $repo['maintenance_lock'] 
                ? 'Maintenance lock is ACTIVE - all operations blocked' 
                : 'No maintenance lock'
        ];

        // Check 6: Read-only status
        $validation['checks']['read_only'] = [
            'passed' => true, // This is informational, not a failure
            'is_read_only' => (bool) $repo['read_only'],
            'message' => $repo['read_only'] 
                ? 'Repo is READ-ONLY - modifications blocked' 
                : 'Repo allows modifications'
        ];

        // Overall status
        $failedChecks = array_filter($validation['checks'], function($check) {
            return isset($check['passed']) && !$check['passed'] && !isset($check['skipped']);
        });

        $validation['overall'] = empty($failedChecks) ? 'passed' : 'failed';
        $validation['failed_count'] = count($failedChecks);

        Response::success($validation);
    }

    /**
     * ✅ SECTION 6.1: Validate base path with realpath
     */
    private function validateBasePath($basePath)
    {
        // Must be absolute path
        if (strpos($basePath, '/') !== 0) {
            return ['valid' => false, 'error' => 'Path must be absolute (start with /)', 'code' => 422];
        }

        // Check for path traversal attempts
        if (strpos($basePath, '..') !== false) {
            return ['valid' => false, 'error' => 'Path traversal not allowed', 'code' => 403];
        }

        // Resolve real path
        $realPath = realpath($basePath);
        if ($realPath === false) {
            return ['valid' => false, 'error' => 'Path does not exist on server', 'code' => 422];
        }

        // Must be a directory
        if (!is_dir($realPath)) {
            return ['valid' => false, 'error' => 'Path is not a directory', 'code' => 422];
        }

        // Check against blocked paths
        foreach (self::BLOCKED_PATHS as $blocked) {
            if (strpos($realPath, $blocked) === 0) {
                return ['valid' => false, 'error' => 'Access to system paths is not allowed', 'code' => 403];
            }
        }

        return ['valid' => true, 'resolved' => $realPath];
    }

    /**
     * ✅ SECTION 6.2: Validate allowed_paths entries
     */
    private function validateAllowedPaths($paths, $basePath)
    {
        $validated = [];
        
        foreach ($paths as $path) {
            // Clean the path
            $path = trim($path);
            if (empty($path)) continue;

            // Must not start with /
            if (strpos($path, '/') === 0) {
                $path = ltrim($path, '/');
            }

            // Must not contain ..
            if (strpos($path, '..') !== false) {
                continue; // Skip invalid paths
            }

            // Must not contain dangerous patterns
            if (preg_match('/[<>:|"\'\\\\]/', $path)) {
                continue;
            }

            $validated[] = $path;
        }

        return array_unique($validated);
    }

    /**
     * ✅ SECTION 6.2: Validate subpath against base_path and allowed_paths
     */
    private function validateSubPath($subPath, $basePath, $allowedPathsJson)
    {
        // Clean subpath
        $subPath = trim($subPath);
        
        // Must not start with /
        if (strpos($subPath, '/') === 0) {
            return ['valid' => false, 'error' => 'Subpath must be relative (not start with /)'];
        }

        // Must not contain ..
        if (strpos($subPath, '..') !== false) {
            return ['valid' => false, 'error' => 'Path traversal not allowed'];
        }

        // Build full path
        $fullPath = $basePath . '/' . ltrim($subPath, '/');
        
        // Resolve with realpath
        $resolvedPath = realpath($fullPath);
        
        // For new paths that don't exist yet, check parent
        if ($resolvedPath === false) {
            $parentPath = realpath(dirname($fullPath));
            if ($parentPath === false) {
                return ['valid' => false, 'error' => 'Path does not exist'];
            }
            // Verify parent is under base
            $resolvedBasePath = realpath($basePath);
            if (strpos($parentPath, $resolvedBasePath) !== 0) {
                return ['valid' => false, 'error' => 'Path traversal attempt detected'];
            }
            $resolvedPath = $parentPath . '/' . basename($fullPath);
        } else {
            // Verify resolved path is under base
            $resolvedBasePath = realpath($basePath);
            if (strpos($resolvedPath, $resolvedBasePath) !== 0) {
                return ['valid' => false, 'error' => 'Path traversal attempt detected'];
            }
        }

        // Check against allowed_paths if set
        $allowedPaths = json_decode($allowedPathsJson, true);
        if (!empty($allowedPaths) && !empty($subPath)) {
            $isAllowed = false;
            foreach ($allowedPaths as $allowed) {
                // Check if subPath starts with allowed path
                if (strpos($subPath, $allowed) === 0 || strpos($allowed, $subPath) === 0) {
                    $isAllowed = true;
                    break;
                }
            }
            if (!$isAllowed) {
                return ['valid' => false, 'error' => 'Path not in allowed paths list'];
            }
        }

        return ['valid' => true, 'resolved_path' => $resolvedPath];
    }

    /**
     * Get directory listing
     */
    private function getDirectoryListing($path, $depth = 1)
    {
        $items = [];

        if (!is_dir($path) || $depth < 1) {
            return $items;
        }

        $files = @scandir($path);
        if ($files === false) {
            return $items;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            // Skip hidden files and backup files
            if (strpos($file, '.coderai-backup') !== false) continue;

            $fullPath = $path . '/' . $file;
            $isDir = is_dir($fullPath);

            $item = [
                'name' => $file,
                'type' => $isDir ? 'directory' : 'file',
                'size' => $isDir ? null : @filesize($fullPath),
                'modified' => date('Y-m-d H:i:s', @filemtime($fullPath))
            ];

            if ($isDir && $depth > 1) {
                $item['children'] = $this->getDirectoryListing($fullPath, $depth - 1);
            }

            $items[] = $item;
        }

        return $items;
    }
}