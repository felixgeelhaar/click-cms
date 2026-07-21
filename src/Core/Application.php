<?php

declare(strict_types=1);

namespace Click\Cms\Core;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Authentication\CsrfGuard;
use Click\Cms\Application\Authentication\LoginThrottle;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Event\EventBus;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Event\EventDispatcher;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\CoreApiRoutes;
use Click\Cms\Http\SectionRenderer;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;

class Application
{
    /**
     * The password the installer seeds. Published in the documentation and
     * therefore not a secret — the account is unusable until it is changed.
     */
    private const INITIAL_PASSWORD = 'admin';

    private bool $booted = false;
    private ?PluginManager $pluginManager = null;
    private ?ContentService $contentService = null;
    private ?EventDispatcher $eventDispatcher = null;
    private ?EventBus $eventBus = null;
    private array $apiRoutes = [];
    private ?CoreApiRoutes $coreApiRoutes = null;
    private ?SessionStore $sessions = null;
    private ?LoginThrottle $throttle = null;
    private array $coreConfig = [];
    private ?CoreConfig $config = null;

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
            // Raw HTML response.
            //
            // Only set the code when the handler supplied one. Defaulting to 200
            // here would overwrite a status the handler already set on its own —
            // which is how the 404 page came to be served with "200 OK".
            if (isset($response['status'])) {
                http_response_code($response['status']);
            }
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
        $this->config = CoreConfig::fromArray($this->coreConfig);
        $this->validateCoreConfig();

        $this->eventDispatcher = new EventDispatcher();
        $this->eventBus = new EventBus($this->eventDispatcher);
        
        // Storage is constructed directly rather than resolved from a plugin:
        // the application cannot boot without one, so it is not optional.
        $storage = new JsonStorage($this->basePath . '/content');
        $this->contentService = new ContentService($storage);

        $this->coreApiRoutes = new CoreApiRoutes($this->basePath, $this->contentService);

        // Sessions and login throttling are collaborators rather than methods on
        // this class, so each can be understood and tested on its own.
        $this->sessions = new SessionStore(
            $this->basePath . '/data/sessions',
            $this->getIdleTimeoutSeconds()
        );
        $this->throttle = new LoginThrottle(
            $this->basePath . '/data/lockouts.json',
            $this->config->lockoutMaxAttempts(),
            $this->config->lockoutWindowSeconds(),
            $this->config->lockoutDurationSeconds()
        );
        
        $excludedIds = $this->config->excludedPluginIds();
        $excludedDirs = $this->config->excludedPluginDirs();

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

    private function renderPageHtml(Content $page): string
    {
        // A plugin may take over rendering entirely — a theme, or the free-form
        // builder for a page built that way.
        foreach ($this->pluginManager->executeHook('web.render', ['page' => $page]) as $result) {
            if (is_string($result) && $result !== '') {
                return $result;
            }
        }

        $title = htmlspecialchars($page->title(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Sections are the CMS's own content model, so it renders them itself.
        // Without this a site could store a page but not show it.
        $renderer = new SectionRenderer(
            new JsonSectionTypeRepository($this->basePath . '/config/sections'),
            new \Click\Cms\Application\Media\MediaService($this->basePath . '/content/media')
        );
        $body = $renderer->render($page);

        // Pages predating sections keep their plain content field.
        if ($body === '' && $page->content() !== '') {
            $body = '<div class="cms-content">'
                . nl2br(htmlspecialchars($page->content(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                . '</div>';
        }

        return '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $title . '</title>
    <link rel="stylesheet" href="/theme.css">
</head>
<body>
    <main>' . $body . '</main>
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

        // Before anything else acts on the request. A forged POST that reaches
        // a handler has already done its damage, and on plugin installation
        // that damage is arbitrary code execution.
        // Cross-origin reads for a separate front end. Applied before anything
        // else so that a preflight is answered even for paths that would
        // otherwise require a session.
        $preflight = $this->applyDeliveryCors($path, $method);
        if ($preflight !== null) {
            return $preflight;
        }

        $csrf = $this->enforceCsrf($path, $method);
        if ($csrf !== null) {
            return $csrf;
        }

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

        // Core routes first. These are the management endpoints the admin UI
        // cannot work without, so they answer whether or not any delivery API
        // plugin is enabled — a site rendering its own pages still needs to be
        // editable.
        $coreMatch = $this->matchRouteTable($this->coreApiRoutes->routes(), $path, $method);
        if ($coreMatch !== null) {
            return $this->executeHandler($coreMatch['handler'], $coreMatch['params']);
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

    /**
     * Require authentication for API requests.
     *
     * Deny by default, with a short list of deliberate exceptions. The previous
     * rule was the other way round — a list of protected prefixes — which fails
     * open: every endpoint added to core was public until somebody remembered
     * to list it, and the media endpoints were reachable with no session at all
     * because of exactly that.
     *
     * Public by design:
     *   auth/*            logging in must work before there is a session
     *   GET pages*        a headless front end reads published content
     *                     anonymously; that is the whole point of a delivery API
     *   GET media/file/*  images referenced by a public page must load for
     *                     visitors
     *
     * Everything else — including listing the media library and reading section
     * definitions — is management and requires a session.
     */
    /**
     * Whether a path may be reached without a session.
     *
     * @see enforceAuthForApi for why this is an allowlist of public paths
     *      rather than a list of protected ones.
     */
    private function isPublicApiPath(string $path, string $method): bool
    {
        if (str_starts_with($path, 'auth/')) {
            return true;
        }

        if (!CsrfGuard::isSafeMethod($method)) {
            return false;
        }

        // Published content, read by a front end that has no account.
        if ($path === 'pages' || str_starts_with($path, 'pages/')) {
            return true;
        }

        // The bytes of an image a public page references.
        if (str_starts_with($path, 'media/file/')) {
            return true;
        }

        return false;
    }

    private function enforceAuthForApi(string $path, string $method): ?array
    {
        // An account with an outstanding password change may do nothing else.
        // Checked before the per-path rules so that no endpoint, present or
        // future, can be reached while the seeded password is still in place.
        $session = $this->getSessionUser();
        if ($session !== null && ($session['mustChangePassword'] ?? false)) {
            return [
                'status' => 403,
                'error' => 'Set a new password before continuing.',
                'mustChangePassword' => true,
            ];
        }

        if ($this->isPublicApiPath($path, $method)) {
            return null;
        }

        $user = $this->getSessionUser();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        // Asked as capability questions rather than role comparisons, so the
        // rules live in one place and can be changed without hunting for every
        // `=== 'admin'` in the codebase.
        $role = Role::fromName($user['role'] ?? null);

        if (str_starts_with($path, 'users') && !$role->can(Capability::ManageUsers)) {
            return ['status' => 403, 'error' => 'You do not have permission to manage users.'];
        }

        if (str_starts_with($path, 'marketplace') && !$role->can(Capability::InstallPlugins)) {
            return ['status' => 403, 'error' => 'You do not have permission to install plugins.'];
        }

        if (str_starts_with($path, 'plugins') && $method !== 'GET' && !$role->can(Capability::ManagePlugins)) {
            return ['status' => 403, 'error' => 'You do not have permission to manage plugins.'];
        }

        return null;
    }

    /**
     * Serve the admin UI.
     *
     * In a built image the UI is static files under the document root, so
     * Apache answers before PHP is reached and this never runs. It exists for
     * development, where the UI is a Vite dev server on another port.
     *
     * The proxy only runs when CLICK_ADMIN_DEV_URL is set. Previously it always
     * ran against a hardcoded localhost:4321, which meant a production
     * deployment without the built assets silently tried to reach a developer's
     * machine and served a blank page.
     */
    private function serveAdminUi(string $uri): array
    {
        $devUrl = getenv('CLICK_ADMIN_DEV_URL') ?: '';

        if ($devUrl === '') {
            return [
                'status' => 404,
                'error' => 'The admin UI is not available. Build it into public/admin, '
                    . 'or set CLICK_ADMIN_DEV_URL to proxy a development server.',
            ];
        }

        $ch = curl_init(rtrim($devUrl, '/') . $uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $content = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content === false || $httpCode === 0) {
            return ['status' => 502, 'error' => 'The admin development server is not reachable.'];
        }

        if ($httpCode >= 400) {
            return ['status' => $httpCode, 'error' => 'Admin UI error'];
        }

        header('Content-Type: ' . ($contentType ?: 'text/html'));

        return ['raw' => true, 'html' => (string) $content, 'status' => $httpCode];
    }

    private function handleAuthRequest(string $path, string $method): array
    {
        $action = ltrim(preg_replace('#^auth/#', '', $path), '/');

        if ($method === 'POST' && $action === 'login') {
            return $this->handleLogin();
        }

        if ($method === 'POST' && $action === 'password') {
            return $this->handleChangePassword();
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

        // Read through the content service rather than guessing at a path. This
        // previously looked in content/users/ (plural) while users are stored
        // under content/user/, so no account was ever found and every login
        // failed — including the default admin the installer creates.
        $account = $this->contentService?->user($username);
        if ($account === null) {
            $this->recordFailedLogin($username);
            return ['status' => 401, 'error' => 'Invalid credentials'];
        }

        // Content nests its payload under `data`; the password lives there, not
        // at the top level of the stored document.
        $userData = $account->data;

        $hash = $userData['password'] ?? null;
        if (!is_string($hash) || $hash === '') {
            // No usable hash means the account cannot be authenticated. There is
            // deliberately no fallback: a hardcoded admin/admin escape hatch here
            // would let anyone in whenever a user document lost its password.
            $this->recordFailedLogin($username);
            return ['status' => 401, 'error' => 'Invalid credentials'];
        }

        $validPassword = password_verify($password, $hash);

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
            'csrfToken' => CsrfGuard::generateToken(),
            'user' => [
                'username' => $userData['username'] ?? $username,
                'displayName' => $userData['displayName'] ?? $username,
                'email' => $userData['email'] ?? '',
                'role' => $userData['role'] ?? 'editor',
                'capabilities' => Role::fromName($userData['role'] ?? null)->capabilityNames(),
                'mustChangePassword' => (bool) ($userData['mustChangePassword'] ?? false)
            ]
        ];

        $this->sessions->start($session, $remember);
        $this->clearFailedLogin($username);

        return ['data' => ['success' => true, 'user' => $session['user']]];
    }

    /**
     * Change the signed-in account's password.
     *
     * Requires the current password even though the session is already
     * authenticated: it is what stops a borrowed or hijacked session from
     * locking the real owner out of their own account.
     *
     * @return array<string, mixed>
     */
    private function handleChangePassword(): array
    {
        $session = $this->getSessionUser();
        if ($session === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        $data = $this->getJsonBody();
        $current = (string) ($data['currentPassword'] ?? '');
        $new = (string) ($data['newPassword'] ?? '');

        if ($current === '' || $new === '') {
            return ['status' => 400, 'error' => 'Both the current and the new password are required.'];
        }

        $username = (string) ($session['username'] ?? '');
        $account = $this->contentService?->user($username);
        if ($account === null) {
            return ['status' => 404, 'error' => 'Account not found'];
        }

        $hash = $account->data['password'] ?? null;
        if (!is_string($hash) || !password_verify($current, $hash)) {
            $this->recordFailedLogin($username);
            return ['status' => 403, 'error' => 'The current password is not correct.'];
        }

        $minimum = $this->getPasswordMinLength();
        if (mb_strlen($new) < $minimum) {
            return ['status' => 422, 'error' => "The new password must be at least {$minimum} characters."];
        }

        if ($new === $current) {
            return ['status' => 422, 'error' => 'The new password must differ from the current one.'];
        }

        // The seeded password is published, so it can never be the answer even
        // if it satisfies the length rule.
        if ($new === self::INITIAL_PASSWORD) {
            return ['status' => 422, 'error' => 'That password cannot be used.'];
        }

        $account->update([
            'password' => password_hash($new, PASSWORD_DEFAULT),
            'mustChangePassword' => null,
            'passwordChangedAt' => gmdate('c'),
        ]);
        $this->contentService->save($account);

        $this->clearFailedLogin($username);
        $this->refreshSessionUser($username);

        return ['data' => ['success' => true]];
    }

    /**
     * Re-read the account into the session after it changes, so a stale flag
     * cannot keep an account locked out of the rest of the API.
     */
    private function refreshSessionUser(string $username): void
    {
        $account = $this->contentService?->user($username);
        if ($account === null) {
            return;
        }

        $this->sessions?->merge([
            'user' => ['mustChangePassword' => (bool) ($account->data['mustChangePassword'] ?? false)],
        ]);
    }

    private function getPasswordMinLength(): int
    {
        return $this->config?->passwordMinLength() ?? 8;
    }

    private function handleLogout(): array
    {
        $this->sessions?->clear();

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

    /**
     * Reject state-changing requests that do not carry the session's CSRF token.
     *
     * Only applies once there is a session to forge: an unauthenticated request
     * can do nothing that needs protecting, and login itself must be reachable
     * before any token exists.
     *
     * @return array<string, mixed>|null Null when the request may proceed.
     */
    /**
     * Allow a named front end to read the delivery API from the browser.
     *
     * Only public paths are opened, and only to origins the site has listed.
     * Credentials are never allowed: delivery is anonymous, so a cross-origin
     * request must not be able to carry a session, which keeps this from
     * becoming a way around the CSRF protection.
     *
     * @return array<string, mixed>|null A response for a preflight, else null.
     */
    private function applyDeliveryCors(string $path, string $method): ?array
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') {
            return null;
        }

        $allowed = $this->config?->deliveryAllowedOrigins() ?? [];
        if (!in_array($origin, $allowed, true)) {
            return null;
        }

        // A preflight asks about the request that follows, so the answer has to
        // consider that method rather than OPTIONS itself.
        $intended = $method === 'OPTIONS'
            ? strtoupper((string) ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? 'GET'))
            : $method;

        if (!$this->isPublicApiPath($path, $intended)) {
            return null;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        header('Access-Control-Max-Age: 600');

        if ($method === 'OPTIONS') {
            return ['status' => 204, 'raw' => true, 'html' => ''];
        }

        return null;
    }

    private function enforceCsrf(string $path, string $method): ?array
    {
        if (CsrfGuard::isSafeMethod($method)) {
            return null;
        }

        // Logging in and out must work without a token; there is no session to
        // protect yet, and being unable to log out would be worse than the risk.
        if (in_array($path, ['auth/login', 'auth/logout'], true)) {
            return null;
        }

        $session = $this->getSessionData();
        $expected = $session['csrfToken'] ?? null;

        // No session means nothing to forge.
        if (!is_string($expected) || $expected === '') {
            return null;
        }

        if (!CsrfGuard::matches($expected, CsrfGuard::tokenFromRequest($_SERVER))) {
            return [
                'status' => 403,
                'error' => 'Missing or invalid CSRF token.',
            ];
        }

        return null;
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
            'sessionId' => $session['sessionId'] ?? null,
            'csrfToken' => $session['csrfToken'] ?? null
        ]];
    }

    private function getSessionUser(): ?array
    {
        return $this->sessions?->user();
    }

    private function getSessionData(): array
    {
        return $this->sessions?->read() ?? [];
    }

    private function touchSession(): void
    {
        $this->sessions?->touch();
    }

    private function getSessionTtlSeconds(bool $remember = false): int
    {
        return $this->config?->sessionTtlSeconds($remember) ?? ($remember ? 2592000 : 28800);
    }

    private function getIdleTimeoutSeconds(): int
    {
        return $this->config?->idleTimeoutSeconds() ?? 1800;
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
        $remaining = $this->throttle?->secondsRemaining($username);

        if ($remaining === null) {
            return null;
        }

        $minutes = (int) ceil($remaining / 60);

        return [
            'status' => 429,
            'error' => "Too many failed attempts. Try again in {$minutes} minute(s).",
            'retryAfter' => $remaining,
        ];
    }

    private function recordFailedLogin(string $username): void
    {
        $this->throttle?->recordFailure($username);
    }

    private function clearFailedLogin(string $username): void
    {
        $this->throttle?->clear($username);
    }





    /**
     * The delivery APIs are deliberately not required.
     *
     * REST and GraphQL exist to serve an external front end. A site that uses
     * the CMS's own page rendering needs neither, and refusing to boot without
     * one would make that perfectly reasonable setup impossible. Content
     * management does not depend on them: those endpoints are core.
     */
    private function validateCoreConfig(): void
    {
        // Nothing to enforce today. Kept as the place where genuinely fatal
        // misconfiguration should be caught.
    }

    private function isCoreRestApiEnabled(): bool
    {
        return $this->config?->restApiEnabled() ?? true;
    }

    private function isCoreAuthEnabled(): bool
    {
        return $this->config?->authEnabled() ?? true;
    }

    private function isMarketplaceEnabled(): bool
    {
        return $this->config?->marketplaceEnabled() ?? true;
    }

    private function isGraphqlEnabled(): bool
    {
        return $this->config?->graphqlEnabled() ?? true;
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

        $adminUser = [
            'username' => 'admin',
            'displayName' => 'Administrator',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'status' => 'active',
            'password' => password_hash(self::INITIAL_PASSWORD, PASSWORD_DEFAULT),
            // The seeded account has a published, guessable password. It can log
            // in and do exactly one thing: choose a real one.
            'mustChangePassword' => true,
            'createdAt' => gmdate('c'),
        ];

        $content = Content::create(ContentKey::user('admin'), $adminUser);
        $this->contentService->save($content);
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

    /**
     * Match a request against a whole route table.
     *
     * Distinct from matchRoute(), which tests a single route: this walks a table
     * and returns the handler as well as the parameters.
     *
     * @param array<string, callable> $routes
     * @return array{handler: callable, params: array<string, string>}|null
     */
    private function matchRouteTable(array $routes, string $path, string $method): ?array
    {
        foreach ($routes as $route => $handler) {
            $parts = explode(' ', $route, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$routeMethod, $routePath] = $parts;
            if ($routeMethod !== $method) {
                continue;
            }

            $routeParts = explode('/', ltrim(preg_replace('#^/api/#', '', $routePath), '/'));
            $pathParts = explode('/', $path);

            if (count($routeParts) !== count($pathParts)) {
                continue;
            }

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
                return ['handler' => $handler, 'params' => $params];
            }
        }

        return null;
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
