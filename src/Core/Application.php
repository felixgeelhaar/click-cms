<?php

declare(strict_types=1);

namespace Click\Cms\Core;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Authentication\CsrfGuard;
use Click\Cms\Application\Authentication\LoginThrottle;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Config\Settings;
use Click\Cms\Application\Event\EventBus;
use Click\Cms\Application\Audit\AuditService;
use Click\Cms\Application\History\HistoryService;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Event\EventDispatcher;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Http\CoreApiRoutes;
use Click\Cms\Http\MarketplaceController;
use Click\Cms\Http\RedirectsController;
use Click\Cms\Http\PluginsController;
use Click\Cms\Http\UsersController;
use Click\Cms\Http\ApiGuard;
use Click\Cms\Http\DeliveryCors;
use Click\Cms\Http\AuthController;
use Click\Cms\Http\HealthCheck;
use Click\Cms\Http\SectionRenderer;
use Click\Cms\Infrastructure\Audit\AuditingStorage;
use Click\Cms\Infrastructure\Audit\JsonAuditLog;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\StorageFactory;
use Click\Cms\Infrastructure\Storage\VersioningStorage;

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
    private ?UsersController $usersController = null;
    private ?PluginsController $pluginsController = null;
    private ?MarketplaceController $marketplaceController = null;
    private ?RedirectsController $redirectsController = null;
    private ?HistoryService $history = null;
    private ?\Click\Cms\Application\Audit\AuditService $auditService = null;
    private ?SessionStore $sessions = null;
    private ?ApiGuard $apiGuard = null;
    private ?DeliveryCors $deliveryCors = null;
    private ?AuthController $authController = null;
    private ?LoginThrottle $throttle = null;
    private array $coreConfig = [];
    private ?CoreConfig $config = null;
    private ?Settings $settings = null;

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

        // Route on the path alone. The query string was previously matched as
        // part of it, so `/api/pages?locale=de` looked to the router like a path
        // named "pages?locale=de" and answered "Endpoint not found" — every
        // query parameter core has ever wanted to read was unreachable.
        $queryStart = strpos($uri, '?');
        if ($queryStart !== false) {
            $uri = substr($uri, 0, $queryStart);
        }

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
            // A redirect names the destination and the code — 301 for a
            // permanent move so browsers cache it, 302 otherwise. Without the
            // code a Location header rides on a 200, which no browser follows.
            http_response_code($response['status'] ?? 302);
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

        // Runtime settings live under data/, not in the image, so an operator can
        // flip them from the admin and have them survive a redeploy.
        $this->settings = Settings::load($this->basePath . '/data/settings.json');

        $this->eventDispatcher = new EventDispatcher();
        $this->eventBus = new EventBus($this->eventDispatcher);
        
        // Storage is constructed directly rather than resolved from a plugin:
            // the application cannot boot without one, so it is not optional. Which
            // backend, however, is configuration — and a backend that cannot be
            // built throws rather than falling back, so a site never silently runs
            // on a different store than it asked for.
            //
            // Versioning wraps whichever backend was built, at the one place the
            // whole application gets its storage from, so nothing can write
            // content without leaving a way back to what it replaced. Being a
            // decorator, it gains history for SQLite as well as flat files. The
            // author is read from the session lazily because the backend outlives
            // any one request.
            //
            // The default locale reaches the service because the pre-languages
            // layout has to be read as *something*, and the only honest answer is
            // the language the site says it is written in.
            $versions = new JsonVersionStore(
                $this->basePath . '/data/versions',
                RetentionPolicy::keeping($this->config->historyRetainedVersions())
            );
            // Who is acting, read lazily on each write because the storage stack
            // outlives any one request. Shared by versioning and the audit trail.
            $author = fn (): ?string => $this->getSessionUser()['username'] ?? null;

            // Audit wraps versioning as the outermost decorator, so every write —
            // save, delete, publish, unpublish and a history restore — leaves a
            // record of who did it, on top of the record of what changed. Both
            // still satisfy the storage port, so nothing downstream changes.
            $auditLog = new JsonAuditLog($this->basePath . '/data/audit');
            $storage = new AuditingStorage(
                new VersioningStorage(
                    StorageFactory::create($this->config, $this->basePath),
                    $versions,
                    $author,
                ),
                $auditLog,
                $author,
            );
            $this->contentService = new ContentService($storage, $this->config->defaultLocale());

        $this->history = new HistoryService($storage, $versions);
        $this->auditService = new AuditService($auditLog);

        $this->coreApiRoutes = new CoreApiRoutes(
            $this->basePath,
            $this->contentService,
            $this->history,
            $this->config
        );

        // User management is core (the admin UI depends on it); it fires the same
        // account hooks the plugin it moved out of did, so any listener still runs.
        $this->usersController = new UsersController(
            $this->contentService,
            $this->config,
            fn (string $event, array $payload): mixed => $this->pluginManager?->executeHook($event, $payload),
        );

        // Redirect rules are read on the way to a 404 and managed through the
        // admin, both from one place so the two never disagree.
        $this->redirectsController = new RedirectsController($this->contentService);

        // Sessions and login throttling are collaborators rather than methods on
        // this class, so each can be understood and tested on its own.
        $this->sessions = new SessionStore(
            $this->basePath . '/data/sessions',
            $this->getIdleTimeoutSeconds()
        );
        $this->apiGuard = new ApiGuard($this->sessions);
        $this->deliveryCors = new DeliveryCors($this->config->deliveryAllowedOrigins(), $this->apiGuard);
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

        // Activate each plugin that has not been explicitly turned off. A
        // deactivation is persisted, so it survives here — the previous blanket
        // loop activated everything unconditionally, which is why turning a
        // plugin off never lasted past a restart.
        foreach ($plugins as $plugin) {
            if (!$this->pluginManager->isDeactivated($plugin->id)) {
                $this->pluginManager->activate($plugin->id);
            }
        }

        // Plugin management is core — the admin UI's Plugins page depends on it —
        // so it is wired here rather than in a plugin that could be disabled.
        $this->pluginsController = new PluginsController($this->pluginManager);
        $this->marketplaceController = new MarketplaceController($this->pluginManager, $this->config, $this->basePath);

        // Identity — login, logout, password changes, the default admin — is its
        // own controller, given the same session store the rest of a request
        // reads so a login is visible immediately.
        $this->authController = new AuthController(
            $this->sessions,
            $this->throttle,
            $this->contentService,
            $this->config,
            self::INITIAL_PASSWORD,
        );
        $this->authController->ensureDefaultAdminUser();
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

        // Preview sits outside /api/ deliberately. Serving unpublished content
        // from its own path means the API's public allowlist — which is
        // deny-by-default and hard to reason about once it grows — does not
        // have to be widened at all, and nothing that is already public gains
        // a new way in.
        if (str_starts_with($uri, '/preview/')) {
            return $this->handlePreviewRequest($uri);
        }

        // In headless mode this instance serves no rendered site of its own —
        // the public front end is someone else's, reading the delivery API. The
        // admin UI and the API above are untouched; only the public pages go
        // away, which is the whole point of the switch.
        if ($this->settings?->headless()) {
            return $this->headlessPlaceholder($uri);
        }

        return $this->handlePublicPage($uri);
    }

    /**
     * What a visitor gets at a content URL when the site is headless.
     *
     * A 404, because there genuinely is no page here to serve — the content
     * lives behind the API. The root path gets a one-line pointer to the API
     * rather than a bare error, so someone who lands on the origin by hand is
     * told what this is rather than left guessing.
     *
     * @return array<string, mixed>
     */
    private function headlessPlaceholder(string $uri): array
    {
        $path = trim((string) parse_url($uri, PHP_URL_PATH), '/');

        http_response_code($path === '' ? 200 : 404);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');

        $body = $path === ''
            ? '<p>This is a headless Click CMS instance. Content is served from '
                . '<code>/api/pages</code>, not rendered here.</p>'
            : '<h1>Not found</h1>';

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Headless</title></head><body>' . $body . '</body></html>';

        return ['raw' => true];
    }

    private function handlePublicPage(string $uri): array
    {
        $path = trim($uri, '/');
        [$locale, $slug] = $this->splitLocaleFromPath($path);

        if ($slug === '') {
            $slug = 'home';
        }

        $resolved = $this->contentService?->resolve(ContentKey::page($slug, $locale));

        // An unpublished page is not found, as far as the public site is
        // concerned — and that is now true by construction rather than by a
        // check. `resolve()` reads `content/`, `content/` holds published
        // documents only, so there is no unpublished document here to filter
        // out and no way for a later edit to this method to forget to.
        //
        // The check it replaces tested a `status` field on the document, which
        // could disagree with whether the file was there at all. Seeing a page
        // early is what preview is for, and that requires a signed link or a
        // session.
        if ($resolved === null) {
            // Before giving up on a path, see if it was moved. A redirect rule
            // sends an old address to a new one, so a bookmark or an inbound
            // link that predates a slug change still lands somewhere.
            $redirect = $this->redirectsController->rules()->match($path);
            if ($redirect !== null) {
                return ['redirect' => $redirect->to, 'status' => $redirect->statusCode()];
            }

            return $this->notFoundPage($locale);
        }

        $rendered = $this->renderPageHtml($resolved->content, $resolved->served);

        header('Content-Type: text/html');
        // So a cache, and anyone debugging why they are reading English, can see
        // that this URL was answered in a language other than the one asked for.
        header('Content-Language: ' . $resolved->served->code);
        if ($resolved->isFallback()) {
            header('Vary: Accept-Language');
        }
        echo $rendered;
        return ['raw' => true];
    }

    /**
     * Split a leading language segment off a public URL.
     *
     * `/de/kontakt` is the German contact page; `/kontakt` is the contact page
     * in the site's default language. Only a segment that names a *configured*
     * language is treated as one, so a page whose slug happens to look like a
     * language tag — `/de` for a page about Germany — is still reachable on a
     * monolingual site.
     *
     * @return array{0: Locale, 1: string}
     */
    private function splitLocaleFromPath(string $path): array
    {
        $default = $this->config?->defaultLocale() ?? Locale::default();

        $slash = strpos($path, '/');
        $first = $slash === false ? $path : substr($path, 0, $slash);

        $candidate = Locale::tryFromString($first);
        if ($candidate === null || $this->config === null || !$this->config->supportsLocale($candidate)) {
            return [$default, $path];
        }

        return [$candidate, $slash === false ? '' : substr($path, $slash + 1)];
    }

    /**
     * Render a page as it stands, published or not, to somebody entitled to see
     * it early.
     *
     * Two ways to be entitled, and both are checked here rather than trusted
     * from elsewhere:
     *
     * - a signed link, which is what lets a preview be sent to a client or a
     *   proofreader who has no account at all; or
     * - a session, so an editor clicking through from the admin UI is not made
     *   to mint a link to look at their own work.
     *
     * A visitor with neither gets the same 404 the public site gives, so the
     * existence of an unpublished page is not disclosed either.
     *
     * @return array<string, mixed>
     */
    private function handlePreviewRequest(string $uri): array
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $rest = rawurldecode(substr($path, strlen('/preview/')));

        // The same language prefix the public site uses, so `/preview/de/kontakt`
        // previews the German page and `/preview/kontakt` the default one.
        [$locale, $slug] = $this->splitLocaleFromPath($rest);

        // The slug reaches storage, so only the shape this application itself
        // generates is accepted. Anything else is refused before it is used to
        // build a key, rather than relying on a layer further down to notice.
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            return $this->notFoundPage($locale);
        }

        $key = ContentKey::page($slug, $locale);
        $token = $_GET['token'] ?? null;
        $bySignature = $this->previewLinks()->accepts($key, is_string($token) ? $token : null);

        if (!$bySignature && !$this->mayPreviewFromSession()) {
            return $this->notFoundPage($locale);
        }

        // The working copy, which is the thing preview exists to show. Reading
        // the stored document instead would render whatever is already live and
        // silently omit the edit the link was minted for — the gap the core
        // docs described as "preview shows the stored document, not an unsaved
        // edit", and the reason draft-and-publish had to be decided before this
        // capability was finished.
        //
        // Deliberately not the fallback-resolving read the public site uses. A
        // preview of a translation that does not exist yet must say so by being
        // absent, not quietly show the English one and let it be approved as
        // though it were the German page.
        $page = $this->contentService?->draft($key);
        if ($page === null) {
            return $this->notFoundPage($locale);
        }

        // Unpublished content must not be kept by anything between here and the
        // browser, or a shared cache turns one signed link into a public page
        // that outlives the token.
        header('Cache-Control: no-store, private');
        // Belt and braces against a preview link finding its way into a crawler:
        // the token is in the query string, and query strings end up in
        // referrers, bookmarks and pasted chat messages.
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Content-Type: text/html');

        header('Content-Language: ' . $locale->code);

        echo $this->renderPageHtml($page, $locale, preview: true);

        return ['raw' => true];
    }

    /**
     * Whether the signed-in account may see unpublished content.
     *
     * Asked as ViewContent rather than PreviewContent on purpose. PreviewContent
     * is permission to *hand out* a link to somebody with no account, which is a
     * decision to let unpublished work out of the building. Reading a draft
     * while signed in is what the management API already allows every role, and
     * the two should not be conflated.
     */
    private function mayPreviewFromSession(): bool
    {
        $user = $this->getSessionUser();

        if ($user === null) {
            return false;
        }

        // An account still on the seeded password may do nothing else anywhere,
        // and preview is not an exception to that.
        if ($user['mustChangePassword'] ?? false) {
            return false;
        }

        return Role::fromName($user['role'] ?? null)->can(Capability::ViewContent);
    }

    private function previewLinks(): \Click\Cms\Application\Preview\PreviewLinks
    {
        return new \Click\Cms\Application\Preview\PreviewLinks(
            $this->basePath . '/data/preview-secret'
        );
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The same 404 whether the page does not exist or merely is not published.
     *
     * Deliberately indistinguishable: a different response for "exists but is a
     * draft" would let anyone enumerate unpublished work by watching which
     * addresses answer differently.
     */
    private function notFoundPage(?Locale $locale = null): array
    {
        $lang = ($locale ?? $this->config?->defaultLocale() ?? Locale::default())->code;

        header('Content-Type: text/html');
        http_response_code(404);
        echo '<!doctype html><html lang="' . htmlspecialchars($lang, ENT_QUOTES) . '">'
            . '<head><meta charset="utf-8"><title>Not Found</title></head>'
            . '<body><h1>Page not found</h1></body></html>';

        return ['raw' => true];
    }

    /**
     * @param ?Locale $locale  Which language this page is being served in, so the
     *                         document can declare it.
     * @param bool    $preview Whether to mark the page as an unpublished preview.
     */
    private function renderPageHtml(
        Content $page,
        ?Locale $locale = null,
        bool $preview = false,
    ): string {
        // A plugin may take over rendering entirely — a theme, or the free-form
        // builder for a page built that way.
        foreach ($this->pluginManager?->executeHook('web.render', ['page' => $page, 'preview' => $preview]) ?? [] as $result) {
            if (is_string($result) && $result !== '') {
                // The mark is added here rather than left to the plugin. A theme
                // that had never heard of preview would otherwise render an
                // unpublished page indistinguishable from the live site, which
                // is the exact mistake this is meant to prevent.
                return $preview ? $this->markAsPreview($result, $page) : $result;
            }
        }

        $title = htmlspecialchars($page->title(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // One media service, shared by the renderer (which resolves in-page
        // images) and the SEO head (which resolves the Open Graph image), so all
        // media I/O stays here and SeoMeta stays pure.
        $media = new \Click\Cms\Application\Media\MediaService($this->basePath . '/content/media');

        // Sections are the CMS's own content model, so it renders them itself.
        // Without this a site could store a page but not show it.
        $renderer = new SectionRenderer(
            new JsonSectionTypeRepository($this->basePath . '/config/sections'),
            $media
        );
        $body = $renderer->render($page);

        // Pages predating sections keep their plain content field.
        if ($body === '' && $page->content() !== '') {
            $body = '<div class="cms-content">'
                . nl2br(htmlspecialchars($page->content(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                . '</div>';
        }

        // The language actually served, not the one requested. A German URL
        // showing English prose because the translation is missing must still
        // say `lang="en"` — a screen reader that pronounces English with German
        // phonemes is unintelligible, and that is precisely the case a fallback
        // creates.
        $lang = htmlspecialchars(
            ($locale ?? $page->locale())->code,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        // The head differs by audience. A preview is for an editor, so it keeps a
        // plain, unmistakably-marked title and is told never to be indexed — SEO
        // metadata is a public-page concern and would only mislead here. The
        // public page gets the full SEO head: title with its own fallback,
        // description, Open Graph tags, canonical and the editor's own noindex.
        if ($preview) {
            $head = '<title>Preview: ' . $title . '</title>
    <meta name="robots" content="noindex, nofollow, noarchive">';
        } else {
            $head = \Click\Cms\Http\SeoMeta::forPage(
                $page->toArray()['data'] ?? [],
                $page->title(),
                static function (string $ref) use ($media): string {
                    $item = $media->find($ref);
                    return $item?->urls('/api/media/file')['original'] ?? '';
                }
            );
        }

        $html = '<!doctype html>
<html lang="' . $lang . '">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    ' . $head . '
    <link rel="stylesheet" href="/theme.css">
</head>
<body>
    <main>' . $body . '</main>
</body>
</html>';

        return $preview ? $this->markAsPreview($html, $page) : $html;
    }

    /**
     * Make it obvious, on the page itself, that this is not the live site.
     *
     * A status header or a differently-coloured URL is not enough: previews get
     * screenshotted, forwarded and shown in meetings, and by then the address
     * bar is gone. The banner has to survive into the picture.
     *
     * Styles are inline because a preview may be served by a theme whose
     * stylesheet this code has never seen, and the one thing that must not fail
     * is the warning.
     */
    private function markAsPreview(string $html, Content $page): string
    {
        // Which of the three things a preview can be, said plainly. Without it
        // an editor cannot tell a draft nobody has seen from a live page they
        // are looking at through the preview route by accident — and now that
        // saving no longer publishes, there is a third case that matters more
        // than either: a published page whose live version is not this one. An
        // editor who cannot see that difference will send the link, hear "looks
        // good", and never press Publish.
        $state = $this->contentService?->publicationOf($page->key);

        $status = match (true) {
            $state === null => '',
            $state->hasUnpublishedChanges && $state->published
                => ' This page is published, but these changes are not — the public still sees the previous version.',
            $state->published => ' This page is already published.',
            default => ' This page is not published.',
        };

        $banner = '<div role="status" style="position:sticky;top:0;z-index:2147483647;'
            . 'background:#b45309;color:#fff;font:600 14px/1.4 system-ui,sans-serif;'
            . 'padding:10px 16px;text-align:center;">'
            . 'Preview — this is not the published site.' . $status
            . '</div>';

        // Placed immediately inside <body> so it is the first thing rendered,
        // whatever the document around it looks like. A theme with no <body>
        // tag still gets the banner, just at the very top.
        $injected = preg_replace('/<body\b[^>]*>/i', '$0' . $banner, $html, 1, $count);

        return ($count ?? 0) > 0 && is_string($injected) ? $injected : $banner . $html;
    }

    private function handleHealthLive(): array
    {
        return (new HealthCheck($this->basePath, $this->pluginManager !== null))->live();
    }

    private function handleHealthReady(): array
    {
        return (new HealthCheck($this->basePath, $this->pluginManager !== null))->ready();
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
        // The query string is not part of the route. Without this, any endpoint
        // taking a parameter — `/api/media?displayWidth=360`, which asks for the
        // library judged against a field's slot — matched no route at all and
        // came back 404.
        // The query string is already gone by here — run() strips it before any
        // routing, so public pages get the same treatment. Stripping it a second
        // time would only hide it if that ever stopped being true.
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

        $csrf = $this->apiGuard->enforceCsrf($path, $method, $_SERVER);
        if ($csrf !== null) {
            return $csrf;
        }

        if ($this->isCoreAuthEnabled() && str_starts_with($path, 'auth/')) {
            return $this->authController->handle($path, $method);
        }

        if ($this->isCoreAuthEnabled()) {
            $authResult = $this->apiGuard->enforceAuth($path, $method);
            if ($authResult !== null) {
                return $authResult;
            }
        }

        if (str_starts_with($path, 'marketplace')) {
            if (!$this->isMarketplaceEnabled()) {
                return ['status' => 404, 'error' => 'Marketplace disabled'];
            }

            return $this->marketplaceController->handle($path, $method);
        }

        // Runtime settings. Reading is available to any signed-in user so the
        // admin UI can show the current mode; changing one is an administrator
        // action. CSRF and authentication have already been enforced above.
        if ($path === 'settings') {
            return $this->handleSettingsRequest($method);
        }

        // The audit trail — who did what, across the whole site. An operator
        // accountability tool, so the service gates it to administrators; the
        // handler only needs to have a session (enforced above) and hand the
        // user to the service, which decides.
        if ($path === 'audit') {
            $user = $this->getSessionUser() ?? [];
            $result = $this->auditService?->recent($user, 100)
                ?? ['entries' => null, 'error' => 'Audit is unavailable.', 'status' => 500];

            return $result['error'] !== null
                ? ['status' => $result['status'], 'error' => $result['error']]
                : ['data' => $result['entries']];
        }

        // Core routes first. These are the management endpoints the admin UI
        // cannot work without, so they answer whether or not any delivery API
        // plugin is enabled — a site rendering its own pages still needs to be
        // editable. Users and plugins management were once in the rest-api
        // plugin; they are core now, for exactly this reason.
        $coreTables = [
            $this->coreApiRoutes->routes(),
            $this->usersController->routes(),
            $this->pluginsController->routes(),
            $this->redirectsController->routes(),
        ];
        foreach ($coreTables as $table) {
            $match = $this->matchRouteTable($table, $path, $method);
            if ($match !== null) {
                return $this->executeHandler($match['handler'], $match['params']);
            }
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

    /**
     * Read or change the runtime settings.
     *
     * Reading is allowed to any signed-in user, so the admin UI can show the
     * current mode to everyone who can see the admin. Changing one needs the
     * settings capability, which only an administrator has — turning a site
     * headless takes its public pages away, and that is not an editor's call.
     *
     * @return array<string, mixed>
     */
    private function handleSettingsRequest(string $method): array
    {
        $user = $this->getSessionUser();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        if ($method === 'GET') {
            return ['data' => ($this->settings ?? Settings::load($this->basePath . '/data/settings.json'))->toArray()];
        }

        if ($method !== 'PUT') {
            return ['status' => 405, 'error' => 'Method not allowed'];
        }

        if (!Role::fromName($user['role'] ?? null)->can(Capability::ManageSettings)) {
            return ['status' => 403, 'error' => 'You do not have permission to change settings.'];
        }

        $data = $this->getJsonBody();
        $settings = $this->settings ?? Settings::load($this->basePath . '/data/settings.json');

        // Only the keys we understand are acted on; an unknown key is ignored
        // rather than stored, so the settings file cannot accrete arbitrary
        // content a client decides to post.
        if (array_key_exists('headless', $data)) {
            $settings->setHeadless((bool) $data['headless']);
        }

        return ['data' => $settings->toArray()];
    }

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
        $decision = $this->deliveryCors->evaluate($path, $method, $_SERVER);
        if ($decision === null) {
            return null;
        }

        foreach ($decision['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }

        return $decision['preflight'];
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
        // The REST API plugin was deleted — its user and plugin management moved
        // into core, and its page/media routes had long been dead duplicates of
        // core's. Nothing to skip for it any more.
        if ($pluginName === 'GraphQL API' && !$this->isGraphqlEnabled()) {
            return true;
        }

        if ($pluginName === 'Authentication' && $this->isCoreAuthEnabled()) {
            return true;
        }

        return false;
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
