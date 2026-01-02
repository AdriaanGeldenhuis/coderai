<?php
/**
 * CoderAI Response Handler
 * Standardized JSON responses
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class Response
{
    /**
     * Send success response
     */
    public static function success($data = null, $message = 'Success', $code = 200)
    {
        self::send([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    /**
     * Send error response
     */
    public static function error($message = 'Error', $code = 400, $errors = null)
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        self::send($response, $code);
    }

    /**
     * Send paginated response
     */
    public static function paginated($data, $total, $page, $perPage)
    {
        self::send([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ]
        ], 200);
    }

    /**
     * Send raw JSON response
     */
    public static function json($data, $code = 200)
    {
        self::send($data, $code);
    }

    /**
     * Internal send method
     */
    private static function send($data, $code)
    {
        // Clean any buffered output (PHP warnings/errors)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Validate required fields
     */
    public static function validate($input, $required)
    {
        $missing = [];

        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            self::error('Missing required fields: ' . implode(', ', $missing), 422);
        }

        return true;
    }

    /**
     * Require authentication
     */
    public static function requireAuth()
    {
        if (empty($_SESSION['user_id'])) {
            self::error('Authentication required', 401);
        }

        return $_SESSION['user_id'];
    }

    /**
     * Require admin role
     */
    public static function requireAdmin()
    {
        self::requireAuth();

        if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
            self::error('Admin access required', 403);
        }

        return true;
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCsrf()
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';

        if (empty($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            self::error('Invalid CSRF token', 403);
        }

        return true;
    }
}
