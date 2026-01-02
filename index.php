<?php
/**
 * CoderAI - Single Entry Point
 */

define('CODERAI', true);

// Suppress display errors for API (prevents JSON corruption)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($uri, '/api/') === 0) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start(); // Buffer any stray output
}

// Load bootstrap
require_once __DIR__ . '/app/bootstrap.php';

// Initialize
$app = new Bootstrap();
$app->init();

// Get request path
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');
if (empty($uri)) $uri = '/';

// API routes go to router
if (strpos($uri, '/api/') === 0) {
    $router = new Router();
    $router->dispatch();
    exit;
}

// Page routes
switch ($uri) {
    case '/':
    case '/dashboard':
        require_once __DIR__ . '/app/views/dashboard.php';
        break;
    case '/login':
        require_once __DIR__ . '/app/views/login.php';
        break;
    case '/register':
        require_once __DIR__ . '/app/views/register.php';
        break;
    case '/forgot-password':
        require_once __DIR__ . '/app/views/forgot-password.php';
        break;
    case '/reset-password':
        require_once __DIR__ . '/app/views/reset-password.php';
        break;
    case '/settings':
        require_once __DIR__ . '/app/views/settings.php';
        break;
    case '/logout':
        require_once __DIR__ . '/app/core/Auth.php';
        Auth::logout();
        header('Location: /login');
        exit;
    case '/coder':
        require_once __DIR__ . '/app/views/coder.php';
        break;
    case '/repos':
        require_once __DIR__ . '/app/views/repos.php';
        break;
    // ✅ NEW: Usage dashboard
    case '/usage':
        require_once __DIR__ . '/app/views/usage.php';
        break;
    default:
        http_response_code(404);
        echo '404 - Page not found';
        break;
}