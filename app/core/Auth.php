<?php
/**
 * CoderAI Authentication Core
 * Handles user authentication and session management
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class Auth
{
    private static $user = null;

    /**
     * Attempt login with email and password
     */
    public static function attempt($email, $password)
    {
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Create session
        $token = self::createSession($user['id']);

        // Update last login
        $stmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        // Set session data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['session_token'] = $token;

        // Set cookie
        self::setSessionCookie($token);

        self::$user = $user;
        return true;
    }

    /**
     * Create a new session in database
     */
    private static function createSession($userId)
    {
        $db = Bootstrap::getDB();
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        $stmt = $db->prepare("
            INSERT INTO sessions (user_id, token, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $token,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $expiresAt
        ]);

        return $token;
    }

    /**
     * Set session cookie
     */
    private static function setSessionCookie($token)
    {
        $secure = Bootstrap::getConfig('security')['secure_cookies'] ?? true;

        setcookie('coderai_session', $token, [
            'expires' => strtotime('+7 days'),
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    /**
     * Check if user is authenticated
     */
    public static function check()
    {
        if (self::$user !== null) {
            return true;
        }

        // Check session
        if (!empty($_SESSION['user_id'])) {
            return self::validateSession();
        }

        // Check cookie
        if (!empty($_COOKIE['coderai_session'])) {
            return self::validateToken($_COOKIE['coderai_session']);
        }

        return false;
    }

    /**
     * Validate session token
     */
    private static function validateSession()
    {
        if (empty($_SESSION['session_token'])) {
            return false;
        }

        return self::validateToken($_SESSION['session_token']);
    }

    /**
     * Validate token against database
     */
    private static function validateToken($token)
    {
        $db = Bootstrap::getDB();

        $stmt = $db->prepare("
            SELECT s.*, u.*
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.token = ? AND s.expires_at > NOW() AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $result = $stmt->fetch();

        if (!$result) {
            self::logout();
            return false;
        }

        // Set session data
        $_SESSION['user_id'] = $result['user_id'];
        $_SESSION['user_role'] = $result['role'];
        $_SESSION['session_token'] = $token;

        self::$user = $result;
        return true;
    }

    /**
     * Get current authenticated user
     */
    public static function user()
    {
        if (self::$user === null && self::check()) {
            // User is loaded in check()
        }

        if (self::$user) {
            unset(self::$user['password_hash']);
        }

        return self::$user;
    }

    /**
     * Get current user ID
     */
    public static function id()
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Check if current user is admin
     */
    public static function isAdmin()
    {
        return ($_SESSION['user_role'] ?? '') === 'admin';
    }

    /**
     * Logout current user
     */
    public static function logout()
    {
        $db = Bootstrap::getDB();

        // Delete session from database
        if (!empty($_SESSION['session_token'])) {
            $stmt = $db->prepare("DELETE FROM sessions WHERE token = ?");
            $stmt->execute([$_SESSION['session_token']]);
        }

        // Clear session
        $_SESSION = [];

        // Delete cookie
        setcookie('coderai_session', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        self::$user = null;
    }

    /**
     * Change password for a user
     */
    public static function changePassword($userId, $currentPassword, $newPassword)
    {
        $db = Bootstrap::getDB();

        // Get user
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return false;
        }

        // Update password
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$newHash, $userId]);

        // Invalidate all other sessions
        $stmt = $db->prepare("DELETE FROM sessions WHERE user_id = ? AND token != ?");
        $stmt->execute([$userId, $_SESSION['session_token'] ?? '']);

        return true;
    }
}
