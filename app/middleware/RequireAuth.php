<?php
/**
 * CoderAI Authentication Middleware
 * Ensures user is authenticated before accessing protected routes
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class RequireAuth
{
    /**
     * Check authentication
     */
    public static function handle()
    {
        require_once __DIR__ . '/../core/Auth.php';

        if (!Auth::check()) {
            Response::error('Authentication required', 401);
        }

        return true;
    }

    /**
     * Check admin role
     */
    public static function admin()
    {
        self::handle();

        if (!Auth::isAdmin()) {
            Response::error('Admin access required', 403);
        }

        return true;
    }

    /**
     * Optional authentication (sets user if logged in, doesn't fail if not)
     */
    public static function optional()
    {
        require_once __DIR__ . '/../core/Auth.php';
        Auth::check();
        return true;
    }
}
