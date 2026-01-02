<?php
/**
 * CoderAI Application Configuration
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

return [
    // Application name
    'name' => 'CoderAI',

    // Application URL
    'url' => 'https://coderai.co.za',

    // Environment: production, development
    'env' => 'production',

    // Debug mode (disable in production)
    'debug' => false,

    // Timezone
    'timezone' => 'Africa/Johannesburg',

    // Default language
    'locale' => 'en',

    // Paths
    'paths' => [
        'app' => __DIR__ . '/..',
        'public' => __DIR__ . '/../..',
        'storage' => __DIR__ . '/../../storage',
        'logs' => __DIR__ . '/../../storage/logs'
    ],

    // Pagination defaults
    'pagination' => [
        'per_page' => 20,
        'max_per_page' => 100
    ]
];
