<?php
/**
 * CoderAI Redis Configuration
 * Used for sessions, rate limiting, caching
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

return [
    // Enable Redis (set to true only if Redis is installed)
    'enabled' => getenv('REDIS_ENABLED') === 'true',

    // Redis host
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',

    // Redis port
    'port' => getenv('REDIS_PORT') ?: 6379,

    // Redis password (leave empty if none)
    'password' => getenv('REDIS_PASSWORD') ?: '',

    // Redis database number
    'database' => getenv('REDIS_DATABASE') ?: 0,

    // Connection timeout (seconds)
    'timeout' => 2.0,

    // Key prefix for namespacing
    'prefix' => 'coderai:',

    // TTL settings (seconds)
    'ttl' => [
        'session' => 7200,      // 2 hours
        'rate_limit' => 60,     // 1 minute
        'cache' => 3600         // 1 hour
    ]
];
