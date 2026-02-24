<?php

declare(strict_types=1);

namespace Click\Cms\Core;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Event\EventBus;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Event\EventDispatcher;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Storage\JsonStorage;

class Application
{
    private bool $booted = false;
    private ?PluginManager $pluginManager = null;
    private ?ContentService $contentService = null;
    private ?EventDispatcher $eventDispatcher = null;
    private ?EventBus $eventBus = null;
    private array $apiRoutes = [];
    private array $coreConfig = [];

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__, 2);
    }

    public function run(): void
    {
        $this->boot();
        
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $this->applySecurityHeaders();
        $this->touchSession();
        
        $response = $this->handleRequest($uri, $method);
        
        if (isset($response['raw']) && $response['raw']) {
            // Raw HTML response
            http_response_code($response['status'] ?? 200);
            echo $response['html'] ?? '';
        } elseif (isset($response['redirect'])) {
            // Redirect response
            header('Location: ' . $response['redirect']);
        } else {
            // JSON response
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($response['status'] ?? 200);
            echo json_encode($response);
        }
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->loadCoreConfig();
        $this->validateCoreConfig();

        $this->eventDispatcher = new EventDispatcher();
        $this->eventBus = new EventBus($this->eventDispatcher);
        
        $storage = new JsonStorage($this->basePath . '/content');
        $this->contentService = new ContentService($storage);
        
        $pluginConfig = $this->coreConfig['core']['plugins'] ?? [];
        $excludeConfig = $pluginConfig['exclude'] ?? [];
        $excludedIds = $excludeConfig['ids'] ?? ['admin-ui', 'authentication'];
        $excludedDirs = $excludeConfig['dirs'] ?? ['admin-ui', 'auth'];

        $this->pluginManager = new PluginManager(
            $this->basePath . '/plugins',
            $this->basePath . '/data',
            $excludedIds,
            $excludedDirs
        );
        $this->pluginManager->setEventDispatcher($this->eventDispatcher);
        $this->pluginManager->setContentService($this->contentService);
        
        $plugins = $this->pluginManager->discover();
        
        foreach ($plugins as $plugin) {
            $this->pluginManager->activate($plugin->id);
        }

        $this->ensureDefaultAdminUser();
        $this->registerApiRoutes();
        
        $this->booted = true;
    }

    private function registerApiRoutes(): void
    {
        $routes = $this->pluginManager->executeHook('api.routes', []);
        
        foreach ($routes as $pluginRoutes) {
            if (!is_array($pluginRoutes)) {
                continue;
            }
            
            foreach ($pluginRoutes as $route => $handler) {
                $this->apiRoutes[$route] = $handler;
            }
        }
    }

    public function getPluginManager(): PluginManager
    {
        return $this->pluginManager;
    }

    public function getContentService(): ContentService
    {
        return $this->contentService;
    }

    public function getEventDispatcher(): EventDispatcher
    {
        return $this->eventDispatcher;
    }

    public function getEventBus(): EventBus
    {
        return $this->eventBus;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    private function handleRequest(string $uri, string $method): array
    {
        if (str_starts_with($uri, '/health/live')) {
            return $this->handleHealthLive();
        }

        if (str_starts_with($uri, '/health/ready')) {
            return $this->handleHealthReady();
        }

        if (str_starts_with($uri, '/api/')) {
            return $this->handleApiRequest($uri, $method);
        }

        if (str_starts_with($uri, '/admin')) {
            return $this->handleAdminRequest($uri, $method);
        }

        return $this->handlePublicPage($uri);
    }

    private function handlePublicPage(string $uri): array
    {
        $slug = trim($uri, '/');
        if ($slug === '') {
            $slug = 'home';
        }

        $page = $this->contentService?->page($slug);
        if ($page === null) {
            header('Content-Type: text/html');
            http_response_code(404);
            echo '<!doctype html><html><head><meta charset="utf-8"><title>Not Found</title></head><body><h1>Page not found</h1></body></html>';
            return ['raw' => true];
        }

        $rendered = $this->renderPageHtml($page);

        header('Content-Type: text/html');
        echo $rendered;
        return ['raw' => true];
    }

    private function renderPageHtml(\Click\Cms\Domain\Content\Content $page): string
    {
        $hookResults = $this->pluginManager->executeHook('web.render', ['page' => $page]);

        foreach ($hookResults as $result) {
            if (is_string($result) && $result !== '') {
                return $result;
            }
        }
        
        // Fallback: render basic HTML from page content
        $title = htmlspecialchars($page->title() ?? 'Untitled', ENT_QUOTES, 'UTF-8');
        $content = $page->content() ?? '';
        
        return '<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $title . '</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 800px; margin: 0 auto; padding: 2rem; line-height: 1.6; }
        h1 { color: #333; }
    </style>
</head>
<body>
    <h1>' . $title . '</h1>
    <div>' . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . '</div>
</body>
</html>';
    }

    private function handleHealthLive(): array
    {
        return [
            'status' => 200,
            'data' => [
                'status' => 'alive',
                'timestamp' => time()
            ]
        ];
    }

    private function handleHealthReady(): array
    {
        $checks = [];
        $contentPath = $this->basePath . '/content';
        $dataPath = $this->basePath . '/data';

        $checks['content_dir'] = is_dir($contentPath) && is_writable($contentPath);
        $checks['data_dir'] = is_dir($dataPath) && is_writable($dataPath);
        $checks['plugins_loaded'] = $this->pluginManager !== null;

        $ready = !in_array(false, $checks, true);

        return [
            'status' => $ready ? 200 : 503,
            'data' => [
                'status' => $ready ? 'ready' : 'not_ready',
                'timestamp' => time(),
                'checks' => $checks
            ]
        ];
    }

    private function applySecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }

    private function handleAdminRequest(string $uri, string $method): array
    {
        return $this->serveAdminUi($uri);
    }

    private function handleApiRequest(string $uri, string $method): array
    {
        $path = ltrim(preg_replace('#^/api/#', '', $uri), '/');

        if ($this->isCoreAuthEnabled() && str_starts_with($path, 'auth/')) {
            return $this->handleAuthRequest($path, $method);
        }

        if ($this->isCoreAuthEnabled()) {
            $authResult = $this->enforceAuthForApi($path, $method);
            if ($authResult !== null) {
                return $authResult;
            }
        }

        if (str_starts_with($path, 'marketplace')) {
            if (!$this->isMarketplaceEnabled()) {
                return ['status' => 404, 'error' => 'Marketplace disabled'];
            }

            return $this->handleMarketplaceRequest($path, $method);
        }

        if (!$this->isCoreRestApiEnabled() && $this->isGraphqlEnabled() === false) {
            return ['status' => 500, 'error' => 'No content API enabled'];
        }
        
        $routes = $this->pluginManager->executeHook('api.routes', []);
        
        foreach ($routes as $pluginName => $pluginRoutes) {
            if (!is_array($pluginRoutes)) continue;

            if ($this->shouldSkipPluginRoutes($pluginName)) {
                continue;
            }
            
            foreach ($pluginRoutes as $route => $handler) {
                $routeParts = explode(' ', $route, 2);
                if (count($routeParts) !== 2) continue;
                
                [$routeMethod, $routePath] = $routeParts;
                if ($routeMethod !== $method) continue;
                
                $routePath = ltrim(preg_replace('#^/api/#', '', $routePath), '/');
                
                $routeParts = explode('/', $routePath);
                $pathParts = explode('/', $path);
                
                if (count($routeParts) !== count($pathParts)) continue;
                
                $params = [];
                $matched = true;
                
                for ($i = 0; $i < count($routeParts); $i++) {
                    if (str_starts_with($routeParts[$i], ':')) {
                        $params[substr($routeParts[$i], 1)] = $pathParts[$i];
                    } elseif ($routeParts[$i] !== $pathParts[$i]) {
                        $matched = false;
                        break;
                    }
                }
                
                if ($matched) {
                    return $this->executeHandler($handler, $params);
                }
            }
        }

        return ['status' => 404, 'error' => 'Endpoint not found'];
    }

    private function enforceAuthForApi(string $path, string $method): ?array
    {
        $protectedPrefixes = [
            'users',
            'plugins',
            'marketplace',
        ];

        $requiresAuth = false;

        foreach ($protectedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $requiresAuth = true;
                break;
            }
        }

        if (str_starts_with($path, 'pages') && $method !== 'GET') {
            $requiresAuth = true;
        }

        if (str_starts_with($path, 'graphql')) {
            $requiresAuth = true;
        }

        if (!$requiresAuth) {
            return null;
        }

        $user = $this->getSessionUser();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        if (str_starts_with($path, 'users') && ($user['role'] ?? '') !== 'admin') {
            return ['status' => 403, 'error' => 'Admin access required'];
        }

        if (str_starts_with($path, 'marketplace') && ($user['role'] ?? '') !== 'admin') {
            return ['status' => 403, 'error' => 'Admin access required'];
        }

        return null;
    }

    private function serveAdminUi(string $uri): array
    {
        $adminDevUrl = 'http://localhost:4321';
        
        error_reporting(E_ERROR);
        
        $ch = curl_init($adminDevUrl . $uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Host: localhost:4321'
        ]);
        
        $content = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (is_resource($ch)) {
            curl_close($ch);
        }
        
        error_reporting(E_ALL);
        
        if ($httpCode >= 400) {
            return ['status' => $httpCode, 'error' => 'Admin UI error'];
        }
        
        if ($contentType && strpos($contentType, 'text/html') !== false && $content) {
            $content = str_replace('http://localhost:4321', 'http://localhost:8080', $content);
            $content = str_replace('localhost:4321', 'localhost:8080', $content);
            $contentType = 'text/html';
        }
        
        header('Content-Type: ' . ($contentType ?: 'text/html'));
        echo $content;
        return ['raw' => true];
    }

    private function handleAuthRequest(string $path, string $method): array
    {
        $action = ltrim(preg_replace('#^auth/#', '', $path), '/');

        if ($method === 'POST' && $action === 'login') {
            return $this->handleLogin();
        }

        if ($method === 'POST' && $action === 'logout') {
            return $this->handleLogout();
        }

        if ($method === 'GET' && $action === 'me') {
            return $this->handleMe();
        }

        if ($method === 'GET' && $action === 'check') {
            return $this->handleAuthCheck();
        }

        return ['status' => 404, 'error' => 'Auth endpoint not found'];
    }

    private function handleLogin(): array
    {
        $data = $this->getJsonBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $remember = (bool) ($data['remember'] ?? false);

        if ($username === '' || $password === '') {
            return ['status' => 400, 'error' => 'Username and password required'];
        }

        $lockout = $this->checkLockout($username);
        if ($lockout !== null) {
            return $lockout;
        }

        $userFile = $this->basePath . '/content/users/' . $username . '.json';
        if (!file_exists($userFile)) {
            $this->recordFailedLogin($username);
            return ['status' => 401, 'error' => 'Invalid credentials'];
        }

        $userData = json_decode(file_get_contents($userFile), true);
        if (!is_array($userData)) {
            return ['status' => 500, 'error' => 'Invalid user data'];
        }

        $validPassword = false;
        if (isset($userData['password'])) {
            $validPassword = password_verify($password, $userData['password']);
        } elseif ($username === 'admin' && $password === 'admin') {
            $validPassword = true;
        }

        if (!$validPassword) {
            $this->recordFailedLogin($username);
            return ['status' => 401, 'error' => 'Invalid credentials'];
        }

        if (($userData['status'] ?? 'active') !== 'active') {
            return ['status' => 403, 'error' => 'Account is not active'];
        }

        $session = [
            'username' => $username,
            'loginTime' => time(),
            'expiresAt' => time() + $this->getSessionTtlSeconds($remember),
            'remember' => $remember,
            'lastActivity' => time(),
            'sessionId' => bin2hex(random_bytes(16)),
            'user' => [
                'username' => $userData['username'] ?? $username,
                'displayName' => $userData['displayName'] ?? $username,
                'email' => $userData['email'] ?? '',
                'role' => $userData['role'] ?? 'editor'
            ]
        ];

        file_put_contents($this->getSessionFile(), json_encode($session, JSON_PRETTY_PRINT));
        $this->clearFailedLogin($username);

        return ['data' => ['success' => true, 'user' => $session['user']]];
    }

    private function handleLogout(): array
    {
        if (file_exists($this->getSessionFile())) {
            unlink($this->getSessionFile());
        }

        return ['data' => ['success' => true]];
    }

    private function handleMe(): array
    {
        $user = $this->getSessionUser();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        return ['data' => $user];
    }

    private function handleAuthCheck(): array
    {
        $session = $this->getSessionData();
        $user = $session['user'] ?? null;

        return ['data' => [
            'authenticated' => $user !== null,
            'user' => $user,
            'expiresAt' => $session['expiresAt'] ?? null,
            'remember' => $session['remember'] ?? false,
            'lastActivity' => $session['lastActivity'] ?? null,
            'sessionId' => $session['sessionId'] ?? null
        ]];
    }

    private function getSessionUser(): ?array
    {
        $session = $this->getSessionData();

        return $session['user'] ?? null;
    }

    private function getSessionData(): array
    {
        $sessionFile = $this->getSessionFile();
        if (!file_exists($sessionFile)) {
            return [];
        }

        $session = json_decode(file_get_contents($sessionFile), true);
        if (!is_array($session)) {
            return [];
        }

        $expiresAt = $session['expiresAt'] ?? null;
        if ($expiresAt !== null && time() > (int) $expiresAt) {
            unlink($sessionFile);
            return [];
        }

        $idleTimeout = $this->getIdleTimeoutSeconds();
        $lastActivity = $session['lastActivity'] ?? null;

        if ($idleTimeout > 0 && $lastActivity !== null) {
            if (time() - (int) $lastActivity > $idleTimeout) {
                unlink($sessionFile);
                return [];
            }
        }

        return $session;
    }

    private function touchSession(): void
    {
        if (!$this->isCoreAuthEnabled()) {
            return;
        }

        $sessionFile = $this->getSessionFile();
        if (!file_exists($sessionFile)) {
            return;
        }

        $session = $this->getSessionData();
        if (empty($session)) {
            return;
        }

        $session['lastActivity'] = time();
        file_put_contents($sessionFile, json_encode($session, JSON_PRETTY_PRINT));
    }

    private function getSessionTtlSeconds(bool $remember): int
    {
        $authConfig = $this->coreConfig['core']['auth'] ?? [];
        $defaultTtl = 8 * 60 * 60;
        $rememberTtl = 30 * 24 * 60 * 60;

        if ($remember) {
            return (int) ($authConfig['rememberTtlSeconds'] ?? $rememberTtl);
        }

        return (int) ($authConfig['sessionTtlSeconds'] ?? $defaultTtl);
    }

    private function getIdleTimeoutSeconds(): int
    {
        $authConfig = $this->coreConfig['core']['auth'] ?? [];

        return (int) ($authConfig['idleTimeoutSeconds'] ?? 0);
    }

    private function getSessionFile(): string
    {
        return $this->basePath . '/data/session.json';
    }

    private function loadCoreConfig(): void
    {
        $path = $this->basePath . '/config/core.json';
        if (file_exists($path)) {
            $config = json_decode(file_get_contents($path), true);
            if (is_array($config)) {
                $this->coreConfig = $config;
                return;
            }
        }

        $this->coreConfig = [
            'core' => [
                'restApi' => ['enabled' => true],
                'auth' => [
                    'enabled' => true,
                    'sessionTtlSeconds' => 8 * 60 * 60,
                    'rememberTtlSeconds' => 30 * 24 * 60 * 60,
                    'idleTimeoutSeconds' => 30 * 60,
                    'lockoutMaxAttempts' => 5,
                    'lockoutWindowSeconds' => 15 * 60,
                    'lockoutDurationSeconds' => 15 * 60,
                    'passwordMinLength' => 8,
                ],
                'marketplace' => ['enabled' => true],
            ],
            'plugins' => [
                'graphql' => ['enabled' => false],
            ],
        ];
    }

    private function checkLockout(string $username): ?array
    {
        $lockout = $this->getLockoutData();
        $entry = $lockout[$username] ?? null;

        if (!$entry) {
            return null;
        }

        $lockedUntil = $entry['lockedUntil'] ?? null;
        if ($lockedUntil !== null && time() < (int) $lockedUntil) {
            return ['status' => 429, 'error' => 'Account locked. Try again later.'];
        }

        return null;
    }

    private function recordFailedLogin(string $username): void
    {
        $authConfig = $this->coreConfig['core']['auth'] ?? [];
        $maxAttempts = (int) ($authConfig['lockoutMaxAttempts'] ?? 5);
        $windowSeconds = (int) ($authConfig['lockoutWindowSeconds'] ?? 900);
        $lockoutSeconds = (int) ($authConfig['lockoutDurationSeconds'] ?? 900);

        $lockout = $this->getLockoutData();
        $entry = $lockout[$username] ?? [
            'attempts' => [],
            'lockedUntil' => null,
        ];

        $now = time();
        $attempts = array_filter(
            $entry['attempts'] ?? [],
            fn($ts) => $now - (int) $ts <= $windowSeconds
        );

        $attempts[] = $now;
        $entry['attempts'] = array_values($attempts);

        if (count($attempts) >= $maxAttempts) {
            $entry['lockedUntil'] = $now + $lockoutSeconds;
        }

        $lockout[$username] = $entry;
        $this->saveLockoutData($lockout);
    }

    private function clearFailedLogin(string $username): void
    {
        $lockout = $this->getLockoutData();
        if (!isset($lockout[$username])) {
            return;
        }

        unset($lockout[$username]);
        $this->saveLockoutData($lockout);
    }

    private function getLockoutData(): array
    {
        $path = $this->basePath . '/data/auth-lockout.json';
        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    private function saveLockoutData(array $data): void
    {
        $path = $this->basePath . '/data/auth-lockout.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function validateCoreConfig(): void
    {
        $restEnabled = $this->isCoreRestApiEnabled();
        $graphqlEnabled = $this->isGraphqlEnabled();

        if (!$restEnabled && !$graphqlEnabled) {
            throw new \RuntimeException('At least one content API must be enabled (REST or GraphQL).');
        }
    }

    private function isCoreRestApiEnabled(): bool
    {
        return (bool) ($this->coreConfig['core']['restApi']['enabled'] ?? true);
    }

    private function isCoreAuthEnabled(): bool
    {
        return (bool) ($this->coreConfig['core']['auth']['enabled'] ?? true);
    }

    private function isMarketplaceEnabled(): bool
    {
        return (bool) ($this->coreConfig['core']['marketplace']['enabled'] ?? true);
    }

    private function isGraphqlEnabled(): bool
    {
        return (bool) ($this->coreConfig['core']['graphql']['enabled'] ?? false);
    }

    private function shouldSkipPluginRoutes(string $pluginName): bool
    {
        if ($pluginName === 'REST API' && !$this->isCoreRestApiEnabled()) {
            return true;
        }

        if ($pluginName === 'GraphQL API' && !$this->isGraphqlEnabled()) {
            return true;
        }

        if ($pluginName === 'Authentication' && $this->isCoreAuthEnabled()) {
            return true;
        }

        return false;
    }

    private function ensureDefaultAdminUser(): void
    {
        if ($this->contentService === null) {
            return;
        }

        if ($this->contentService->user('admin')) {
            return;
        }

        $legacyUser = $this->loadLegacyAdminUser();
        $adminUser = $legacyUser ?? [
            'username' => 'admin',
            'displayName' => 'Administrator',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'status' => 'active',
            'password' => password_hash('admin', PASSWORD_DEFAULT),
            'createdAt' => gmdate('c'),
        ];

        $content = Content::create(ContentKey::user('admin'), $adminUser);
        $this->contentService->save($content);
    }

    private function loadLegacyAdminUser(): ?array
    {
        $legacyPath = $this->basePath . '/content/users/admin.json';
        if (!file_exists($legacyPath)) {
            return null;
        }

        $data = json_decode(file_get_contents($legacyPath), true);
        if (!is_array($data)) {
            return null;
        }

        $data['password'] = $data['password'] ?? password_hash('admin', PASSWORD_DEFAULT);

        return $data;
    }

    private function handleMarketplaceRequest(string $path, string $method): array
    {
        $action = ltrim(preg_replace('#^marketplace#', '', $path), '/');

        $marketplace = new \Click\Cms\Application\Plugin\PluginMarketplace(
            $this->pluginManager,
            $this->basePath
        );

        $marketplaceConfig = $this->coreConfig['core']['marketplace'] ?? [];
        $registryUrl = $marketplaceConfig['registryUrl'] ?? '';
        $publicKey = $marketplaceConfig['publicKey'] ?? '';

        if ($method === 'POST' && $action === 'install') {
            $data = $this->getJsonBody();
            $pluginId = $data['id'] ?? null;
            $version = $data['version'] ?? null;

            if ($pluginId === null) {
                return ['status' => 400, 'error' => 'Plugin id is required'];
            }

            $result = $marketplace->installFromRegistry($registryUrl, $publicKey, $pluginId, $version);

            if (!($result['success'] ?? false)) {
                return ['status' => 400, 'error' => $result['error'] ?? 'Install failed'];
            }

            return ['data' => $result['plugin'] ?? $result];
        }

        if ($method !== 'GET') {
            return ['status' => 405, 'error' => 'Method not allowed'];
        }

        $plugins = array_map(
            fn($p) => [
                'id' => $p->id->value,
                'name' => $p->name,
                'description' => $p->description,
                'version' => $p->version->value,
                'state' => $p->state->value,
            ],
            $this->pluginManager->all()
        );

        $catalog = $marketplace->getRegistryCatalog($registryUrl, $publicKey);

        return [
            'data' => [
                'available' => $catalog['available'] ?? [],
                'errors' => $catalog['errors'] ?? [],
                'installed' => $plugins,
                'message' => $catalog['available'] ? 'Registry loaded' : 'Marketplace catalog not configured'
            ]
        ];
    }

    private function getJsonBody(): array
    {
        $input = file_get_contents('php://input');

        if (empty($input)) {
            return $_POST;
        }

        $data = json_decode($input, true);

        return $data ?? [];
    }

    private function matchRouteRaw(string $routePath, string $path): array|false
    {
        $routeParts = explode('/', $routePath);
        $pathParts = explode('/', $path);
        
        if (count($routeParts) !== count($pathParts)) {
            return false;
        }

        $params = [];
        
        for ($i = 0; $i < count($routeParts); $i++) {
            if (str_starts_with($routeParts[$i], ':')) {
                $params[substr($routeParts[$i], 1)] = $pathParts[$i];
            } elseif ($routeParts[$i] !== $pathParts[$i]) {
                return false;
            }
        }

        return ['params' => $params];
    }

    private function matchRoute(string $route, string $path, string $method): array|false
    {
        $routeParts = explode(' ', $route, 2);
        
        if (count($routeParts) !== 2) {
            return false;
        }
        
        [$routeMethod, $routePath] = $routeParts;
        
        if ($routeMethod !== $method) {
            return false;
        }

        $routeParts = explode('/', $routePath);
        $pathParts = explode('/', $path);
        
        if (count($routeParts) !== count($pathParts)) {
            return false;
        }

        $params = [];
        
        for ($i = 0; $i < count($routeParts); $i++) {
            if (str_starts_with($routeParts[$i], ':')) {
                $params[substr($routeParts[$i], 1)] = $pathParts[$i];
            } elseif ($routeParts[$i] !== $pathParts[$i]) {
                return false;
            }
        }

        return ['params' => $params];
    }

    private function executeHandler(array $handler, array $params): array
    {
        [$object, $method] = $handler;
        
        $reflection = new \ReflectionMethod($object, $method);
        $args = [];
        
        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            
            if (isset($params[$name])) {
                $args[] = $params[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }
        
        return $object->$method(...$args);
    }

    public function log(string $level, string $message): void
    {
        $logFile = $this->basePath . '/data/logs/app.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '/',
        ];

        $logLine = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

        if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
            error_log("[{$level}] {$message}");
        }
    }
}
