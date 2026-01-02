<?php
/**
 * CoderAI Bootstrap
 * Core initialization - DB, Redis, Sessions, Security
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

// Load required files first
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/router.php';

// Load environment variables BEFORE configs
require_once __DIR__ . '/config/env.php';
try {
    // Load .env from /app/.env (same folder as bootstrap)
    Env::load(__DIR__ . '/.env');
} catch (RuntimeException $e) {
    // In production, .env is required. In development, show helpful message
    if (file_exists(__DIR__ . '/.env.example')) {
        error_log('ENV Error: ' . $e->getMessage());
    }
}

class Bootstrap
{
    private static $db = null;
    private static $redis = null;
    private static $config = [];

    public function init()
    {
        // Set error reporting
        error_reporting(E_ALL);
        
        // Show errors in development, hide in production
        $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);
        ini_set('display_errors', $isLocal ? '1' : '0');
        ini_set('log_errors', '1');

        // Set timezone
        date_default_timezone_set('Africa/Johannesburg');

        // Load configurations
        $this->loadConfigs();

        // Initialize database
        $this->initDatabase();

        // Initialize Redis (optional)
        $this->initRedis();

        // Initialize session
        $this->initSession();

        // Initialize security
        $this->initSecurity();
    }

    private function loadConfigs()
    {
        self::$config['app'] = require __DIR__ . '/config/app.php';
        self::$config['database'] = require __DIR__ . '/config/database.php';
        self::$config['redis'] = require __DIR__ . '/config/redis.php';
        self::$config['security'] = require __DIR__ . '/config/security.php';
    }

    private function initDatabase()
    {
        $dbConfig = self::$config['database'];

        try {
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";

            self::$db = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5
            ]);
        } catch (PDOException $e) {
            error_log('DB Connection Error: ' . $e->getMessage());
            error_log('DB Config: host=' . $dbConfig['host'] . ', db=' . $dbConfig['database'] . ', user=' . $dbConfig['username']);
            
            // Show detailed error if local
            $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);
            if ($isLocal) {
                die('<h1>Database Connection Failed</h1><pre>' . 
                    'Host: ' . $dbConfig['host'] . "\n" .
                    'Database: ' . $dbConfig['database'] . "\n" .
                    'User: ' . $dbConfig['username'] . "\n" .
                    'Error: ' . $e->getMessage() . 
                    '</pre>');
            }
            
            die('Database connection failed. Please check configuration.');
        }
    }

    private function initRedis()
    {
        $redisConfig = self::$config['redis'];

        // Only try Redis if enabled AND class exists
        if ($redisConfig['enabled'] && class_exists('Redis')) {
            try {
                self::$redis = new Redis();
                self::$redis->connect($redisConfig['host'], $redisConfig['port'], $redisConfig['timeout']);

                if (!empty($redisConfig['password'])) {
                    self::$redis->auth($redisConfig['password']);
                }

                self::$redis->select($redisConfig['database']);
            } catch (Exception $e) {
                error_log('Redis connection failed: ' . $e->getMessage());
                self::$redis = null;
            }
        }
    }

    private function initSession()
    {
        // Only set params if session not already started
        if (session_status() === PHP_SESSION_NONE) {
            $securityConfig = self::$config['security'];

            session_set_cookie_params([
                'lifetime' => $securityConfig['session_lifetime'],
                'path' => '/',
                'domain' => '',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            session_name('CODERAI_SESSION');
            session_start();
        }

        // Regenerate session ID periodically
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        } elseif (time() - $_SESSION['_created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    private function initSecurity()
    {
        // Set security headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');

        // Initialize CSRF token
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public static function getDB()
    {
        return self::$db;
    }

    public static function getRedis()
    {
        return self::$redis;
    }

    public static function getConfig($key = null)
    {
        if ($key === null) {
            return self::$config;
        }
        return self::$config[$key] ?? null;
    }
}