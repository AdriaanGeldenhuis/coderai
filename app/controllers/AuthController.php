<?php
/**
 * CoderAI Authentication Controller
 * Handles login, logout, and authentication endpoints
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/RateLimiter.php';

class AuthController
{
    /**
     * POST /api/auth/login
     */
    public function login($params, $input)
    {
        Response::validate($input, ['email', 'password']);

        $email = trim($input['email']);
        $password = $input['password'];

        // Check rate limit by IP and email
        $identifier = $_SERVER['REMOTE_ADDR'] . ':' . $email;
        $rateCheck = RateLimiter::checkLogin($identifier);

        if ($rateCheck['limited']) {
            Response::error(
                'Too many login attempts. Try again in ' . $rateCheck['retry_after'] . ' seconds.',
                429
            );
        }

        // Attempt login
        if (Auth::attempt($email, $password)) {
            RateLimiter::clearLogin($identifier);

            $user = Auth::user();

            Response::success([
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'name' => $user['name'],
                    'role' => $user['role']
                ],
                'csrf_token' => $_SESSION['csrf_token']
            ], 'Login successful');
        }

        // Record failed attempt
        RateLimiter::hitLogin($identifier);

        Response::error('Invalid email or password', 401);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout($params, $input)
    {
        Auth::logout();
        Response::success(null, 'Logged out successfully');
    }

    /**
     * GET /api/auth/me
     */
    public function me($params, $input)
    {
        require_once __DIR__ . '/../middleware/RequireAuth.php';
        RequireAuth::handle();

        $user = Auth::user();

        Response::success([
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role'],
                'last_login_at' => $user['last_login_at'],
                'created_at' => $user['created_at']
            ],
            'csrf_token' => $_SESSION['csrf_token']
        ]);
    }

    /**
     * POST /api/auth/change-password
     */
    public function changePassword($params, $input)
    {
        require_once __DIR__ . '/../middleware/RequireAuth.php';
        RequireAuth::handle();

        Response::validate($input, ['current_password', 'new_password']);

        $currentPassword = $input['current_password'];
        $newPassword = $input['new_password'];

        // Validate new password
        $securityConfig = Bootstrap::getConfig('security')['password'] ?? [];
        $minLength = $securityConfig['min_length'] ?? 8;

        if (strlen($newPassword) < $minLength) {
            Response::error("Password must be at least {$minLength} characters", 422);
        }

        if (!empty($securityConfig['require_uppercase']) && !preg_match('/[A-Z]/', $newPassword)) {
            Response::error('Password must contain at least one uppercase letter', 422);
        }

        if (!empty($securityConfig['require_lowercase']) && !preg_match('/[a-z]/', $newPassword)) {
            Response::error('Password must contain at least one lowercase letter', 422);
        }

        if (!empty($securityConfig['require_number']) && !preg_match('/[0-9]/', $newPassword)) {
            Response::error('Password must contain at least one number', 422);
        }

        // Change password
        if (Auth::changePassword(Auth::id(), $currentPassword, $newPassword)) {
            Response::success(null, 'Password changed successfully');
        }

        Response::error('Current password is incorrect', 401);
    }
}
