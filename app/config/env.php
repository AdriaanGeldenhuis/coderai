<?php
/**
 * CoderAI Environment Helper
 * Safe environment variable loading
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class Env
{
    private static $loaded = false;
    private static $required = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'APP_KEY'];

    /**
     * Load .env file
     */
    public static function load($file = null)
    {
        if (self::$loaded) {
            return;
        }

        if ($file === null) {
            // Default to /app/.env (one level up from /app/config/)
            $file = __DIR__ . '/../.env';
        }

        if (!file_exists($file)) {
            throw new RuntimeException('.env file not found. Copy .env.example to .env and configure it.');
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes
                if (($value[0] === '"' && $value[strlen($value)-1] === '"') ||
                    ($value[0] === "'" && $value[strlen($value)-1] === "'")) {
                    $value = substr($value, 1, -1);
                }

                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        self::$loaded = true;
        self::validate();
    }

    /**
     * Get environment variable
     */
    public static function get($key, $default = null)
    {
        $value = getenv($key);
        
        if ($value === false) {
            return $default;
        }

        // Convert string booleans
        if (strtolower($value) === 'true') return true;
        if (strtolower($value) === 'false') return false;
        if (strtolower($value) === 'null') return null;

        return $value;
    }

    /**
     * Validate required environment variables
     */
    private static function validate()
    {
        $missing = [];

        foreach (self::$required as $key) {
            if (empty(self::get($key))) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new RuntimeException(
                'Missing required environment variables: ' . implode(', ', $missing) . 
                "\nPlease configure your .env file properly."
            );
        }

        // Validate APP_KEY strength
        $appKey = self::get('APP_KEY');
        if (strlen($appKey) < 32) {
            throw new RuntimeException('APP_KEY must be at least 32 characters long.');
        }

        if (strpos($appKey, 'change-this') !== false || 
            strpos($appKey, 'INSECURE') !== false) {
            throw new RuntimeException('APP_KEY must be changed from default value.');
        }
    }

    /**
     * Check if environment is production
     */
    public static function isProduction()
    {
        return self::get('APP_ENV') === 'production';
    }

    /**
     * Check if debug is enabled
     */
    public static function isDebug()
    {
        return self::get('APP_DEBUG', false) === true;
    }
}