<?php
/**
 * CoderAI Security Configuration
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

return [
    // Use secure cookies (HTTPS only)
    'secure_cookies' => true,

    // Session lifetime (seconds)
    'session_lifetime' => 7200, // 2 hours

    // CSRF protection enabled
    'csrf_enabled' => true,

    // Rate limiting settings
    'rate_limit' => [
        'enabled' => true,
        'login' => [
            'max_attempts' => 5,
            'decay_minutes' => 15
        ],
        'api' => [
            'max_requests' => 60,
            'per_minutes' => 1
        ]
    ],

    // Password requirements
    'password' => [
        'min_length' => 8,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_number' => true,
        'require_special' => false
    ],

    // Allowed origins for CORS
    'allowed_origins' => [
        'https://coderai.co.za',
        'https://www.coderai.co.za'
    ],

    // Encryption key (loaded from .env)
    'encryption_key' => getenv('APP_KEY') ?: 'a7f3c8e9b2d4f1a6c5e8b9d2f4a1c6e5b8d9f2a4c1e6b5d8f9a2c4e1b6d5f8a9',

    // Trusted proxies (for load balancers)
    'trusted_proxies' => [],

    // IP whitelist for admin (empty = all allowed)
    'admin_ip_whitelist' => []
];