<?php
/**
 * CoderAI Router
 * Routes all /api/* requests to controllers
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class Router
{
    private $routes = [];
    private $middleware = [];

    public function __construct()
    {
        $this->registerRoutes();
    }

    private function registerRoutes()
    {
        // Models routes (Section 10)
        $this->get('/api/models', 'ModelsController@index');
        $this->get('/api/models/{id}', 'ModelsController@show');
        
        // Usage routes (Section 8)
        $this->get('/api/usage/stats', 'UsageController@stats');
        $this->get('/api/usage/daily', 'UsageController@daily');
        $this->get('/api/usage/by-model', 'UsageController@byModel');
        $this->get('/api/usage/by-workspace', 'UsageController@byWorkspace');

        // Auth routes (Section 2)
        $this->post('/api/auth/login', 'AuthController@login');
        $this->post('/api/auth/logout', 'AuthController@logout');
        $this->get('/api/auth/me', 'AuthController@me');
        $this->post('/api/auth/change-password', 'AuthController@changePassword');

        // Users routes (Section 2)
        $this->get('/api/users', 'UsersController@index');
        $this->post('/api/users', 'UsersController@store');
        $this->get('/api/users/{id}', 'UsersController@show');
        $this->put('/api/users/{id}', 'UsersController@update');
        $this->delete('/api/users/{id}', 'UsersController@destroy');

        // Settings routes (Section 3)
        $this->get('/api/settings', 'SettingsController@index');
        $this->post('/api/settings', 'SettingsController@store');

        // Workspaces routes (Section 4)
        $this->get('/api/workspaces', 'WorkspacesController@index');
        $this->get('/api/workspaces/{id}', 'WorkspacesController@show');

        // Projects routes (Section 5)
        $this->get('/api/projects', 'ProjectsController@index');
        $this->post('/api/projects', 'ProjectsController@store');
        $this->get('/api/projects/{id}', 'ProjectsController@show');
        $this->put('/api/projects/{id}', 'ProjectsController@update');
        $this->delete('/api/projects/{id}', 'ProjectsController@destroy');

        // Threads routes (Section 6)
        $this->get('/api/threads', 'ThreadsController@index');
        $this->post('/api/threads', 'ThreadsController@store');
        $this->get('/api/threads/{id}', 'ThreadsController@show');
        $this->delete('/api/threads/{id}', 'ThreadsController@destroy');

        // Messages routes (Section 7)
        $this->get('/api/messages', 'MessagesController@index');
        $this->post('/api/messages', 'MessagesController@store');

        // Repos routes (Section 9)
        $this->get('/api/repos', 'ReposController@index');
        $this->post('/api/repos', 'ReposController@store');
        $this->get('/api/repos/{id}', 'ReposController@show');
        $this->put('/api/repos/{id}', 'ReposController@update');
        $this->delete('/api/repos/{id}', 'ReposController@destroy');
        $this->get('/api/repos/{id}/files', 'ReposController@files');
        // ✅ NEW: Validate endpoint
        $this->get('/api/repos/{id}/validate', 'ReposController@validate');

        // Runs routes (Section 10)
        $this->get('/api/runs', 'RunsController@index');
        $this->post('/api/runs', 'RunsController@store');
        $this->get('/api/runs/{id}', 'RunsController@show');
        $this->post('/api/runs/{id}/plan', 'RunsController@plan');
        $this->post('/api/runs/{id}/code', 'RunsController@code');
        $this->post('/api/runs/{id}/review', 'RunsController@review');
        $this->post('/api/runs/{id}/apply', 'RunsController@apply');
        $this->post('/api/runs/{id}/rollback', 'RunsController@rollback');
        $this->post('/api/runs/{id}/cancel', 'RunsController@cancel');
        $this->post('/api/runs/{id}/queue', 'RunsController@queue');

        // Queue routes (Section 12)
        $this->get('/api/queue/stats', 'QueueController@stats');
    }

    private function get($path, $handler)
    {
        $this->routes['GET'][$path] = $handler;
    }

    private function post($path, $handler)
    {
        $this->routes['POST'][$path] = $handler;
    }

    private function put($path, $handler)
    {
        $this->routes['PUT'][$path] = $handler;
    }

    private function delete($path, $handler)
    {
        $this->routes['DELETE'][$path] = $handler;
    }

    public function dispatch()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Remove trailing slash
        $uri = rtrim($uri, '/');

        // Handle preflight CORS
        if ($method === 'OPTIONS') {
            $this->handleCors();
            exit;
        }

        // Set CORS headers
        $this->handleCors();

        // Find matching route
        $handler = $this->findRoute($method, $uri);

        if ($handler === null) {
            // Not an API route - serve static content or 404
            if (strpos($uri, '/api/') === 0) {
                Response::error('Endpoint not found', 404);
            }
            return;
        }

        // Parse handler
        list($controllerName, $action) = explode('@', $handler['handler']);
        $params = $handler['params'];

        // Load and execute controller
        $controllerFile = __DIR__ . '/controllers/' . $controllerName . '.php';

        if (!file_exists($controllerFile)) {
            Response::error('Controller not found', 500);
        }

        require_once $controllerFile;

        $controller = new $controllerName();

        if (!method_exists($controller, $action)) {
            Response::error('Action not found', 500);
        }

        // Get request body for POST/PUT
        $input = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true) ?? [];
        }

        // Execute controller action
        $controller->$action($params, $input);
    }

    private function findRoute($method, $uri)
    {
        if (!isset($this->routes[$method])) {
            return null;
        }

        foreach ($this->routes[$method] as $route => $handler) {
            $pattern = preg_replace('/\{([a-zA-Z]+)\}/', '(?P<$1>[^/]+)', $route);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return [
                    'handler' => $handler,
                    'params' => $params
                ];
            }
        }

        return null;
    }

    private function handleCors()
    {
        $config = Bootstrap::getConfig('security');
        $allowedOrigins = $config['allowed_origins'] ?? ['*'];

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
        header('Access-Control-Allow-Credentials: true');
    }
}