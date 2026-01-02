<?php
/**
 * CoderAI Users Controller
 * Admin management of users
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crypto.php';
require_once __DIR__ . '/../middleware/RequireAuth.php';

class UsersController
{
    /**
     * GET /api/users
     */
    public function index($params, $input)
    {
        RequireAuth::admin();

        $db = Bootstrap::getDB();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $stmt = $db->query("SELECT COUNT(*) as total FROM users");
        $total = $stmt->fetch()['total'];

        // Get users
        $stmt = $db->prepare("
            SELECT id, email, name, role, is_active, last_login_at, created_at, updated_at
            FROM users
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$perPage, $offset]);
        $users = $stmt->fetchAll();

        Response::paginated($users, $total, $page, $perPage);
    }

    /**
     * POST /api/users
     */
    public function store($params, $input)
    {
        RequireAuth::admin();

        Response::validate($input, ['email', 'password', 'name']);

        $email = trim($input['email']);
        $password = $input['password'];
        $name = trim($input['name']);
        $role = in_array($input['role'] ?? '', ['admin', 'user']) ? $input['role'] : 'user';

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email format', 422);
        }

        // Validate password
        $securityConfig = Bootstrap::getConfig('security')['password'] ?? [];
        $minLength = $securityConfig['min_length'] ?? 8;

        if (strlen($password) < $minLength) {
            Response::error("Password must be at least {$minLength} characters", 422);
        }

        $db = Bootstrap::getDB();

        // Check if email exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            Response::error('Email already exists', 422);
        }

        // Create user
        $passwordHash = Crypto::hashPassword($password);

        $stmt = $db->prepare("
            INSERT INTO users (email, password_hash, name, role)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$email, $passwordHash, $name, $role]);

        $userId = $db->lastInsertId();

        Response::success([
            'id' => $userId,
            'email' => $email,
            'name' => $name,
            'role' => $role
        ], 'User created successfully', 201);
    }

    /**
     * GET /api/users/{id}
     */
    public function show($params, $input)
    {
        RequireAuth::admin();

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("
            SELECT id, email, name, role, is_active, last_login_at, created_at, updated_at
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$params['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found', 404);
        }

        Response::success($user);
    }

    /**
     * PUT /api/users/{id}
     */
    public function update($params, $input)
    {
        RequireAuth::admin();

        $db = Bootstrap::getDB();
        $userId = $params['id'];

        // Check user exists
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found', 404);
        }

        // Prepare update fields
        $updates = [];
        $values = [];

        if (isset($input['name'])) {
            $updates[] = 'name = ?';
            $values[] = trim($input['name']);
        }

        if (isset($input['email'])) {
            $email = trim($input['email']);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Invalid email format', 422);
            }

            // Check email uniqueness
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $userId]);

            if ($stmt->fetch()) {
                Response::error('Email already exists', 422);
            }

            $updates[] = 'email = ?';
            $values[] = $email;
        }

        if (isset($input['role']) && in_array($input['role'], ['admin', 'user'])) {
            $updates[] = 'role = ?';
            $values[] = $input['role'];
        }

        if (isset($input['is_active'])) {
            $updates[] = 'is_active = ?';
            $values[] = $input['is_active'] ? 1 : 0;
        }

        if (isset($input['password']) && !empty($input['password'])) {
            $updates[] = 'password_hash = ?';
            $values[] = Crypto::hashPassword($input['password']);
        }

        if (empty($updates)) {
            Response::error('No fields to update', 422);
        }

        $values[] = $userId;

        $stmt = $db->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($values);

        // Get updated user
        $stmt = $db->prepare("
            SELECT id, email, name, role, is_active, last_login_at, created_at, updated_at
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        Response::success($user, 'User updated successfully');
    }

    /**
     * DELETE /api/users/{id}
     */
    public function destroy($params, $input)
    {
        RequireAuth::admin();

        $userId = $params['id'];

        // Prevent self-deletion
        if ($userId == Auth::id()) {
            Response::error('Cannot delete your own account', 422);
        }

        $db = Bootstrap::getDB();

        // Check user exists
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$userId]);

        if (!$stmt->fetch()) {
            Response::error('User not found', 404);
        }

        // Delete user (sessions cascade)
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);

        Response::success(null, 'User deleted successfully');
    }
}
