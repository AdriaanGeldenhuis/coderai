<?php
/**
 * CoderAI Rate Limiter
 * Uses Redis for rate limiting login attempts and API requests
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class RateLimiter
{
    private static $redis = null;

    /**
     * Initialize Redis connection
     */
    private static function getRedis()
    {
        if (self::$redis === null) {
            self::$redis = Bootstrap::getRedis();
        }
        return self::$redis;
    }

    /**
     * Check if action is rate limited
     */
    public static function tooManyAttempts($key, $maxAttempts, $decayMinutes = 1)
    {
        $redis = self::getRedis();

        if (!$redis) {
            // Fallback: no rate limiting if Redis unavailable
            return false;
        }

        $attempts = (int) $redis->get(self::key($key));
        return $attempts >= $maxAttempts;
    }

    /**
     * Increment attempts counter
     */
    public static function hit($key, $decayMinutes = 1)
    {
        $redis = self::getRedis();

        if (!$redis) {
            return 1;
        }

        $cacheKey = self::key($key);
        $attempts = $redis->incr($cacheKey);

        // Set expiry on first hit
        if ($attempts === 1) {
            $redis->expire($cacheKey, $decayMinutes * 60);
        }

        return $attempts;
    }

    /**
     * Get remaining attempts
     */
    public static function remaining($key, $maxAttempts)
    {
        $redis = self::getRedis();

        if (!$redis) {
            return $maxAttempts;
        }

        $attempts = (int) $redis->get(self::key($key));
        return max(0, $maxAttempts - $attempts);
    }

    /**
     * Get seconds until rate limit resets
     */
    public static function availableIn($key)
    {
        $redis = self::getRedis();

        if (!$redis) {
            return 0;
        }

        $ttl = $redis->ttl(self::key($key));
        return max(0, $ttl);
    }

    /**
     * Clear rate limit for key
     */
    public static function clear($key)
    {
        $redis = self::getRedis();

        if ($redis) {
            $redis->del(self::key($key));
        }
    }

    /**
     * Generate cache key
     */
    private static function key($key)
    {
        return 'rate_limit:' . $key;
    }

    /**
     * Check login rate limit
     */
    public static function checkLogin($identifier)
    {
        $config = Bootstrap::getConfig('security')['rate_limit']['login'] ?? [
            'max_attempts' => 5,
            'decay_minutes' => 15
        ];

        $key = 'login:' . $identifier;

        if (self::tooManyAttempts($key, $config['max_attempts'], $config['decay_minutes'])) {
            return [
                'limited' => true,
                'retry_after' => self::availableIn($key)
            ];
        }

        return ['limited' => false];
    }

    /**
     * Record login attempt
     */
    public static function hitLogin($identifier)
    {
        $config = Bootstrap::getConfig('security')['rate_limit']['login'] ?? [
            'decay_minutes' => 15
        ];

        return self::hit('login:' . $identifier, $config['decay_minutes']);
    }

    /**
     * Clear login attempts on success
     */
    public static function clearLogin($identifier)
    {
        self::clear('login:' . $identifier);
    }

    /**
     * Check API rate limit
     */
    public static function checkApi($identifier)
    {
        $config = Bootstrap::getConfig('security')['rate_limit']['api'] ?? [
            'max_requests' => 60,
            'per_minutes' => 1
        ];

        $key = 'api:' . $identifier;

        if (self::tooManyAttempts($key, $config['max_requests'], $config['per_minutes'])) {
            return [
                'limited' => true,
                'retry_after' => self::availableIn($key)
            ];
        }

        self::hit($key, $config['per_minutes']);
        return ['limited' => false];
    }
}
