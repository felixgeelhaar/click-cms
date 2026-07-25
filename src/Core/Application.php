<?php

declare(strict_types=1);

namespace Click\Cms\Core;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Preview\PreviewLinks;
use Click\Cms\Application\Authentication\CsrfGuard;
use Click\Cms\Application\Authentication\LoginThrottle;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Config\Settings;
use Click\Cms\Application\Event\EventBus;
use Click\Cms\Application\Audit\AuditService;
use Click\Cms\Application\History\HistoryService;
use Click\Cms\Application\Plugin\AuthGate;
use Click\Cms\Application\Plugin\ContentGate;
use Click\Cms\Application\Plugin\ContentRefusedException;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Application\Plugin\PublishGate;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Event\EventDispatcher;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Http\CoreApiRoutes;
use Click\Cms\Application\Theme\ThemeRepository;
use Click\Cms\Application\Update\ReleaseFeed;
use Click\Cms\Application\Update\UpdateInstaller;
use Click\Cms\Application\Update\UpdateService;
use Click\Cms\Http\MarketplaceController;
use Click\Cms\Http\ThemesController;
use Click\Cms\Http\UpdatesController;
use Click\Cms\Application\Collection\BackReferenceService;
use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Collection\EntryListings;
use Click\Cms\Application\Collection\EntryRouter;
use Click\Cms\Application\Collection\ReferenceResolver;
use Click\Cms\Domain\Collection\CollectionType;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Http\CollectionsController;
use Click\Cms\Http\MenusController;
use Click\Cms\Http\NavigationRenderer;
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
use Click\Cms\Infrastructure\Storage\AuthorizingStorage;
use Click\Cms\Application\Cache\RenderCache;
use Click\Cms\Infrastructure\Cache\CacheInvalidatingStorage;
use Click\Cms\Infrastructure\Plugin\ContentEventStorage;
use Click\Cms\Infrastructure\Storage\StorageAuthorizationException;
use Click\Cms\Infrastructure\Storage\StorageFactory;
use Click\Cms\Infrastructure\Storage\VersioningStorage;

class Application
{
    /**
     * The release this code is. The updater compares it against the versions a
     * signed feed offers, so it is the single answer to "what is running here?"
     * — bumping it is part of cutting a release, not an afterthought.
     */
    public const VERSION = '1.1.0';

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
    private ?MenusController $menusController = null;
    private ?ThemesController $themesController = null;
    private ?UpdatesController $updatesController = null;
    private ?ThemeRepository $themes = null;
    private ?RenderCache $renderCache = null;
    private ?CollectionsController $collectionsController = null;
    private ?EntryRouter $entryRouter = null;
    private ?EntryListings $entryListings = null;
    private ?NavigationRenderer $navigationRenderer = null;
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

            // A handler may ask for extra response headers — a preview delivery
            // marking itself no-store, for instance — by returning a `headers`
            // map. It is applied here and removed from the body, so it directs
            // the response rather than being serialised into it.
            if (isset($response['headers']) && is_array($response['headers'])) {
                foreach ($response['headers'] as $name => $value) {
                    header($name . ': ' . $value);
                }
                unset($response['headers']);
            }

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

            // Defense in depth at the storage boundary, complementing — not
            // duplicating — the request guard. Anonymous access to a state-
            // changing path is already denied by default before a handler runs,
            // and the writes that legitimately carry no session (a public form
            // submission, the first-boot admin seed, a CLI task) must not be
            // blocked here. The gap the request guard cannot close is an
            // *authenticated* caller whose role should not be writing reaching a
            // handler that forgot its capability check: a signed-in viewer, or
            // any future read-only role. That is what this refuses.
            //
            // The specific capability a write needs — create vs edit, own vs any,
            // a user account vs a page — stays with the handler that alone knows
            // the ownership and intent; the storage layer only asks the weaker,
            // type-blind question of whether this account may mutate content at
            // all, throwing rather than silently dropping when the answer is no.
            $authorizer = function (string $op, ContentKey $key): bool {
                $user = $this->getSessionUser();
                if ($user === null || $user === []) {
                    return true;
                }

                $role = Role::fromName($user['role'] ?? null);
                return $role->can(Capability::CreateContent)
                    || $role->can(Capability::EditOwnContent)
                    || $role->can(Capability::EditAnyContent);
            };

            $storage = new AuthorizingStorage($storage, $authorizer);

            // The render cache and the decorator that clears it. Outermost of
            // the write decorators on purpose: it fires only after a write has
            // been authorised, versioned, audited and actually landed.
            //
            // Wiring invalidation into storage rather than into each handler is
            // what makes "the cache went stale" structurally impossible for
            // content. There is no path that changes a document without going
            // through here, so there is no handler that can forget.
            $this->renderCache = new RenderCache(
                $this->basePath . '/data/cache/pages',
                $this->config->cacheEnabled(),
            );
            if ($this->renderCache->isEnabled()) {
                // Wrapped only when the cache is on, so a site with it off pays
                // nothing — not even a delegating call per read.
                $storage = new CacheInvalidatingStorage($storage, $this->renderCache);
            }

            // The content lifecycle, offered to plugins. Outermost of the write
            // decorators, so an announcement means the write was authorised,
            // versioned, audited, on disk *and* the stale render cache dropped —
            // a listener that re-renders what it was just told about cannot warm
            // from content it has been told is old.
            //
            // Here rather than in each handler for the reason the cache
            // decorator is: "also fire the event" is the instruction that gets
            // forgotten, and events that fire on some write paths and not others
            // are worse than none. Both closures are late-bound because the
            // plugin manager is built further down and nothing writes during
            // boot. Isolated dispatch, so one broken plugin cannot swallow
            // another's refusal — see `Application\Plugin\ContentGate`.
            $storage = new ContentEventStorage(
                $storage,
                new ContentGate(
                    fn (string $hook, array $payload): array
                        => $this->pluginManager?->executeHookIsolated($hook, $payload) ?? [],
                    // Asked before any payload is built, so a site listening to
                    // none of these events pays one array lookup per write.
                    fn (string $hook): bool => $this->pluginManager?->hasHookListeners($hook) ?? false,
                ),
                fn (): array => $this->getSessionUser() ?? [],
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

        // Navigation menus: managed through the admin and rendered into the site's
        // header, both reading the same stored menu.
        $this->menusController = new MenusController($this->contentService);
        $this->navigationRenderer = new NavigationRenderer();

        // Collections — repeatable content types (posts, team members, …) defined
        // in config/collections. Their entries are ordinary content documents, so
        // registering the ids here is what gives each the draft-and-publish
        // lifecycle a page has; without it saving an entry would put it live at
        // once. Done before the routes are gathered so the storage stack already
        // treats these types as publishable on the first write.
        $collectionTypes = new JsonCollectionTypeRepository($this->basePath . '/config/collections');
        Publishable::register(array_map(
            static fn ($type): string => $type->id,
            $collectionTypes->all()
        ));
        $collections = new CollectionService($this->contentService, $collectionTypes, new SectionValidator());

        // Where a collection's entries live on the public site, and the listing
        // that shows them there. Both read the same definitions the admin does, so
        // a route an editor sees in the admin is the route a visitor gets, and a
        // listing's links cannot point somewhere the router does not answer.
        $this->entryRouter = new EntryRouter($collectionTypes);
        $this->entryListings = new EntryListings(
            $collections,
            $this->entryRouter,
            $this->config->defaultLocale(),
        );

        $this->collectionsController = new CollectionsController(
            $collections,
            new ReferenceResolver($this->contentService, $collectionTypes),
            fn (): array => $this->getSessionUser() ?? [],
            // The same history service the page endpoints use; an entry's key is
            // all it needs, so entries get version history for free.
            $this->history,
            // Preview links share the one signing secret with the page previews,
            // so a token minted anywhere verifies everywhere.
            new PreviewLinks($this->basePath . '/data/preview-secret'),
            // "What links here" scans reference fields on demand rather than
            // keeping a stored index a flat-file write would have to maintain.
            new BackReferenceService($collectionTypes, $this->contentService),
        );

        // Themes live outside the application so a site's design survives a
        // deploy; the repository discovers them and remembers which is active.
        $this->themes = ThemeRepository::forInstallation($this->basePath);
        $this->themesController = new ThemesController(
            $this->themes,
            fn (): array => $this->getSessionUser() ?? [],
        );

        // Self-update. The installer replaces the application's own code, so the
        // service is handed the running version to compare a signed feed against.
        $this->updatesController = new UpdatesController(
            new UpdateService(
                $this->basePath,
                new ReleaseFeed($this->basePath . '/data/updates'),
                new UpdateInstaller($this->basePath),
                self::VERSION,
            ),
            $this->config,
            fn (): array => $this->getSessionUser() ?? [],
        );

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

        // The one extension point that can say no. Publishing is the first act
        // core lets a plugin refuse, and the refusal has to reach `PageService`,
        // which several handlers build for themselves and none of them have a
        // plugin manager to hand it — so the gate is installed process-wide
        // here, once the plugins that might veto are loaded. The closure is
        // deliberately late-bound: nothing publishes during boot, and reading
        // the manager on demand keeps this line independent of the order the
        // rest of this method happens to be in.
        //
        // Isolated dispatch, because one plugin throwing must not decide
        // publication for every other plugin. See `PublishGate` for the contract.
        PublishGate::useAmbient(new PublishGate(
            fn (string $hook, array $payload): array
                => $this->pluginManager?->executeHookIsolated($hook, $payload) ?? []
        ));

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
            // Built here rather than reached for ambiently, so the one place that
            // constructs this controller is the one place that decides what its
            // hooks dispatch through. The listens-closure is what keeps a site
            // that subscribes to no auth event from building a payload — or
            // reading the lockout file twice to detect a transition nobody is
            // listening for.
            new AuthGate(
                fn (string $hook, array $payload): array
                    => $this->pluginManager?->executeHookIsolated($hook, $payload) ?? [],
                fn (string $hook): bool => $this->pluginManager?->hasHookListeners($hook) ?? false,
            ),
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

    /**
     * The public site: a page, a collection entry, a redirect, or a 404 — decided
     * in that order.
     *
     * ## The precedence rule, stated once so it cannot drift
     *
     * 1. `/health/…`, `/api/…`, `/admin…` and `/preview/…` never reach here at all;
     *    {@see handleRequest()} claims them first, and a collection is refused a
     *    route under any of them ({@see CollectionType}) rather than being given
     *    addresses that silently never answer.
     * 2. **A page at exactly this path wins.** Adding a route to a collection must
     *    not be able to take a URL away from a page that already exists — an
     *    editor who publishes a `blog` page and a developer who routes posts at
     *    `blog` have not made the page disappear, and `/blog` is still the page.
     * 3. **Then a published collection entry**, when a declared route is a prefix of
     *    the path and exactly one slug segment follows it. Entry addresses are
     *    therefore always two segments or more and page slugs are always one — the
     *    two cannot collide today, and the ordering is what keeps that true if page
     *    slugs ever gain a slash.
     * 4. **Then a redirect rule**, so a bookmark for content that has moved still
     *    lands. Deliberately after the entry: content that is actually here beats a
     *    rule saying where it used to be, exactly as it does for a page.
     * 5. Otherwise the 404, identical for "never existed" and "not published".
     */
    private function handlePublicPage(string $uri): array
    {
        $path = trim($uri, '/');
        [$locale, $withinLocale] = $this->splitLocaleFromPath($path);

        $slug = $withinLocale === '' ? 'home' : $withinLocale;

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
            // No page here. Before anything else, the collection entries: step 3 of
            // the rule above.
            $entry = $this->handleEntryAddress($withinLocale, $locale);
            if ($entry !== null) {
                return $entry;
            }

            // Before giving up on a path, see if it was moved. A redirect rule
            // sends an old address to a new one, so a bookmark or an inbound
            // link that predates a slug change still lands somewhere.
            $redirect = $this->redirectsController->rules()->match($path);
            if ($redirect !== null) {
                return ['redirect' => $redirect->to, 'status' => $redirect->statusCode()];
            }

            return $this->notFoundPage($locale);
        }

        $rendered = $this->renderCachedPageHtml($resolved->content, $resolved->served);

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
     * Serve a published collection entry at its own address, or decline.
     *
     * Null means "not an entry here", and the caller carries on to redirects and
     * the 404. It is the answer for three different situations on purpose:
     *
     * - the path is under no declared route, so no collection claims it;
     * - the entry does not exist;
     * - the entry exists **only as a working copy**, i.e. it is a draft, or it has
     *   been taken down.
     *
     * The third is the one that matters, and it is true by construction rather than
     * by a check that could be forgotten in a later edit: the read is
     * {@see ContentService::resolve()}, which reads `content/`, and `content/` holds
     * published documents only. There is no draft here to filter out — exactly as
     * {@see handlePublicPage()} has no unpublished page to filter out — so a draft
     * entry and a slug nobody ever used produce the identical 404, and neither
     * discloses that the other kind of nothing is there. Seeing an entry early is
     * what preview is for, and that needs a signed link or a session.
     *
     * @param string $withinLocale The request path with any language prefix already
     *        removed, so `/de/blog/x` and `/blog/x` arrive here identically and the
     *        language rides along in $locale — the same scheme pages use, not a
     *        second one.
     * @return array<string, mixed>|null
     */
    private function handleEntryAddress(string $withinLocale, Locale $locale): ?array
    {
        $address = $this->entryRouter?->match($withinLocale);
        if ($address === null) {
            return null;
        }

        // The same fallback a page gets: a missing German entry is served in the
        // site's default language rather than 404ing, and the response says which
        // language the visitor actually got.
        $resolved = $this->contentService?->resolve(
            ContentKey::for($address->type->id, $address->slug, $locale)
        );
        if ($resolved === null) {
            return null;
        }

        $html = $this->renderCachedHtml(
            $resolved->content,
            $resolved->served,
            fn (): string => $this->renderEntryHtml($address->type, $resolved->content, $resolved->served),
        );

        header('Content-Type: text/html');
        header('Content-Language: ' . $resolved->served->code);
        if ($resolved->isFallback()) {
            header('Vary: Accept-Language');
        }

        echo $html;

        return ['raw' => true];
    }

    /**
     * One collection entry as a public HTML document.
     *
     * The entry's fields are rendered by the *same* {@see SectionRenderer} a page's
     * sections go through — a collection type's field set is a section type, so an
     * entry inherits that renderer's escaping, its rich-text sanitising and its
     * responsive images rather than getting a second implementation of all three to
     * keep in step. Around them goes the same {@see PageShell} a page gets, so an
     * entry has the site's header, navigation and active theme and does not read as
     * a different website.
     *
     * The `web.render` hook is deliberately not fired here. Its payload is a
     * `page`, and handing a plugin an entry under that name would have every
     * existing listener treat a post as a page — a lie about the request that a
     * theme cannot detect. Entries are rendered by the shell until that hook is
     * given a shape that can say what it is being handed.
     */
    private function renderEntryHtml(CollectionType $type, Content $entry, ?Locale $locale): string
    {
        $title = $type->titleOf($entry->data);
        $served = $locale ?? $entry->locale();

        $media = new \Click\Cms\Application\Media\MediaService($this->basePath . '/content/media');

        $head = \Click\Cms\Http\SeoMeta::forPage(
            $entry->data,
            $title,
            static function (string $ref) use ($media): string {
                $item = $media->find($ref);
                return $item?->urls('/api/media/file')['original'] ?? '';
            }
        );

        $theme = $this->themes?->active();
        $stylesheet = $theme !== null && $this->themes !== null
            ? $this->themes->stylesheetUrl($theme)
            : '/theme.css';

        // The entry's own address, so a menu item pointing at it is marked current
        // — built by the router rather than assembled here, so there is one answer
        // to "where does this entry live".
        $href = $this->entryRouter?->hrefFor(
            $type,
            $entry->slug(),
            $served,
            $this->contentService?->defaultLocale() ?? $served,
        ) ?? '/';

        $shell = new \Click\Cms\Http\PageShell(
            htmlspecialchars($served->code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $head,
            $this->renderSiteHeader($href, $served),
            $stylesheet,
            // Escaped by the shell, so the raw title goes in.
            $title,
        );

        $renderer = new SectionRenderer(
            new JsonSectionTypeRepository($this->basePath . '/config/sections'),
            $media,
        );

        return $shell->render($renderer->renderFields($type->schema, $entry->data));
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

        // A collection entry is previewable at the address it will have once it
        // is published — `/preview/blog/a-post` for `/blog/a-post`. Resolved by
        // the same router the public route uses, so the two cannot disagree
        // about where an entry lives.
        //
        // Without this, only a single-segment page slug matched, so an entry
        // could not be previewed at all: an author could draft a post and
        // nobody — including whoever had to approve it — could see it rendered.
        // For a CMS whose author role exists precisely to draft for review, that
        // was the review step missing its subject.
        $entryAddress = $this->entryRouter?->match($slug);
        if ($entryAddress !== null) {
            return $this->previewEntry($entryAddress, $locale);
        }

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
     * Preview one collection entry's working copy.
     *
     * Every guarantee the page preview makes is repeated here rather than
     * assumed, because the consequence of getting one wrong is unpublished work
     * reaching a stranger: a signed token for *this* document or a session
     * entitled to see drafts, the working copy rather than the live entry, no
     * language fallback, and headers that stop anything in between keeping it.
     */
    private function previewEntry(
        \Click\Cms\Application\Collection\EntryAddress $address,
        Locale $locale
    ): array {
        $key = ContentKey::for($address->type->id, $address->slug, $locale);

        $token = $_GET['token'] ?? null;
        $bySignature = $this->previewLinks()->accepts($key, is_string($token) ? $token : null);

        if (!$bySignature && !$this->mayPreviewFromSession()) {
            return $this->notFoundPage($locale);
        }

        // The working copy. Reading the live entry would show whatever is
        // already public and silently omit the edit the link was minted for.
        // Not the fallback-resolving read either: a preview of a translation
        // that does not exist yet must be absent rather than quietly showing
        // another language and letting it be approved as this one.
        $entry = $this->contentService?->draft($key);
        if ($entry === null) {
            return $this->notFoundPage($locale);
        }

        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Content-Type: text/html');
        header('Content-Language: ' . $locale->code);

        echo $this->renderEntryHtml($address->type, $entry, $locale);

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
     * A published page, from the render cache when it is there and cacheable.
     *
     * Only the public path calls this. Previews go straight to
     * {@see renderPageHtml()} — a preview must never be read from the cache
     * either, because a preview showing the last published render would tell an
     * editor their unsaved work looks fine.
     *
     * A signed-in visitor is served a fresh render and writes nothing back. The
     * public shell happens to be identical today, but a `web.render` plugin is
     * handed the request and may key something to whoever is looking; a cache a
     * signed-in request can write into is a cache the public reads out of.
     */
    private function renderCachedPageHtml(Content $page, ?Locale $locale): string
    {
        return $this->renderCachedHtml(
            $page,
            $locale,
            fn (): string => $this->renderPageHtml($page, $locale),
        );
    }

    /**
     * Any public document — a page or a collection entry — from the render cache
     * when it is there and cacheable, otherwise rendered and stored.
     *
     * There is exactly one place in this application that builds a render-cache key,
     * and this is it. That is the point: a second call site is a second chance to
     * omit a component of the key, and the failure mode of an incomplete key is one
     * document's HTML served at another document's address — visible only to
     * visitors, and only for the people who are not looking. The type in the key is
     * taken from the document's own {@see Content::type()} rather than passed in, so
     * a caller cannot label a post as a page even by accident.
     *
     * @param callable(): string $render Produces the document when the cache cannot.
     */
    private function renderCachedHtml(Content $document, ?Locale $locale, callable $render): string
    {
        $cache = $this->renderCache;
        $cacheable = $cache !== null
            && $cache->isCacheable(preview: false, authenticated: $this->getSessionUser() !== null);

        if (!$cacheable) {
            return $render();
        }

        $theme = $this->themes?->active();

        $key = $cache->keyFor(
            $document->slug(),
            ($locale ?? $document->locale())->code,
            $theme?->id ?? '',
            // The stylesheet URL rather than a version number of its own: it
            // already carries the cache-busting token, which is the stylesheet's
            // mtime. A designer editing theme CSS in place activates nothing and
            // bumps nothing, so no invalidation fires — but every cached document
            // would go on linking the stale `?v=`. Folding the URL into the key
            // makes that edit heal the cache by itself.
            $theme !== null && $this->themes !== null ? $this->themes->stylesheetUrl($theme) : '',
            // Which kind of document this is. A page `notes` and a post `notes` are
            // two documents at two addresses; without this they are one cache entry,
            // and whoever rendered first decides what everybody sees at both.
            $document->type(),
        );

        $cached = $cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $rendered = $render();
        $cache->put($key, $rendered);

        return $rendered;
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
        $title = htmlspecialchars($page->title(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // One media service, shared by the renderer (which resolves in-page
        // images) and the SEO head (which resolves the Open Graph image), so all
        // media I/O stays here and SeoMeta stays pure.
        $media = new \Click\Cms\Application\Media\MediaService($this->basePath . '/content/media');

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

        // The site's header: the brand, and the main navigation from the "main"
        // menu if the site has built one. It reads the same stored menu the admin
        // edits, resolves each item to a safe href, marks the current page, and is
        // empty markup when there is neither menu nor site name — so a site that
        // built neither simply has no header, not a broken one.
        $served = $locale ?? $page->locale();
        $defaultLocale = $this->contentService?->defaultLocale()->code ?? $served->code;
        $nav = $this->renderSiteHeader(
            $served->code === $defaultLocale
                ? '/' . $page->slug()
                : '/' . $served->code . '/' . $page->slug(),
            $served,
        );

        // The shared document chrome — head, header, theme link — that every page
        // gets regardless of how its body is produced. Handed to the render hook
        // so a plugin (the visual builder) can wrap its own body in the same
        // navigable, indexable shell instead of emitting a bare document.
        // The active theme's stylesheet, cache-busted. Falls back to the historic
        // /theme.css when a site has no themes directory at all, so an install
        // that predates theming renders unchanged.
        $theme = $this->themes?->active();
        $stylesheet = $theme !== null && $this->themes !== null
            ? $this->themes->stylesheetUrl($theme)
            : '/theme.css';

        $shell = new \Click\Cms\Http\PageShell($lang, $head, $nav, $stylesheet, $page->title());

        // A plugin may take over rendering. The builder wraps its node tree in the
        // shell above, so the page keeps nav, SEO and theme; a full theme is free
        // to ignore the shell and return its own complete document.
        foreach ($this->pluginManager?->executeHook('web.render', ['page' => $page, 'preview' => $preview, 'shell' => $shell]) ?? [] as $result) {
            if (is_string($result) && $result !== '') {
                // The mark is added here rather than left to the plugin. A theme
                // that had never heard of preview would otherwise render an
                // unpublished page indistinguishable from the live site, which
                // is the exact mistake this is meant to prevent.
                return $preview ? $this->markAsPreview($result, $page) : $result;
            }
        }

        // Sections are the CMS's own content model, so it renders them itself.
        // Without this a site could store a page but not show it.
        $renderer = new SectionRenderer(
            new JsonSectionTypeRepository($this->basePath . '/config/sections'),
            $media,
            // Defaults repeated because the listing service is the fifth argument;
            // a page carrying a listing section is how a collection becomes visible
            // at all, so this is not an optional extra on the public render path.
            '/api/media/file',
            null,
            $this->entryListings,
        );
        $body = $renderer->render($page);

        // Pages predating sections keep their plain content field.
        if ($body === '' && $page->content() !== '') {
            $body = '<div class="cms-content">'
                . nl2br(htmlspecialchars($page->content(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                . '</div>';
        }

        $html = $shell->render($body);

        return $preview ? $this->markAsPreview($html, $page) : $html;
    }

    /**
     * The site header — brand plus the "main" menu — for the document being
     * rendered.
     *
     * The menu items and the site name are gathered here (a kernel concern:
     * reading storage and settings), and the actual markup is left to
     * {@see NavigationRenderer}.
     *
     * @param string $currentHref Where the document being rendered lives, spelt the
     *        same way the menu spells its own hrefs — no locale prefix for the
     *        default locale, `/locale/…` otherwise — so the item pointing at it
     *        matches and is marked current. Taken as a parameter because a page's
     *        address is its slug while an entry's comes from its collection's route,
     *        and the caller is the one that knows which it is holding.
     */
    private function renderSiteHeader(string $currentHref, Locale $locale): string
    {
        $items = $this->menusController?->resolvedItems('main', $locale->code) ?? [];

        $brand = ($this->settings ?? Settings::load($this->basePath . '/data/settings.json'))->siteName();

        // Stateless, so a render path that never ran boot() (a direct-render test)
        // gets one on demand rather than a half-built kernel.
        $renderer = $this->navigationRenderer ??= new NavigationRenderer();

        return $renderer->render($items, $currentHref, $brand);
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

            // Installing a plugin is running code on the server, so it is gated on
            // a capability, not merely on being signed in. Authentication and CSRF
            // are already enforced above; this is the authorization the
            // marketplace controller's own docstring assumed but nothing applied.
            // Browsing the catalogue needs the weaker ManagePlugins; the install
            // POST needs InstallPlugins. Both are administrator-only by default.
            $role = Role::fromName(($this->getSessionUser() ?? [])['role'] ?? null);
            $needed = ($method === 'POST') ? Capability::InstallPlugins : Capability::ManagePlugins;
            if (!$role->can($needed)) {
                return ['status' => 403, 'error' => 'You do not have permission to manage plugins.'];
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
            $this->menusController->routes(),
            $this->collectionsController->routes(),
            $this->themesController->routes(),
            $this->updatesController->routes(),
        ];
        foreach ($coreTables as $table) {
            $match = $this->matchRouteTable($table, $path, $method);
            if ($match !== null) {
                $response = $this->executeHandler($match['handler'], $match['params']);

                // Anything an administrator changes that is not a content
                // document — the plugin set, a theme, a redirect rule — is
                // invisible to the storage decorator that normally invalidates.
                // Rather than enumerate which of these endpoints can reach a
                // rendered page and be wrong about one of them, every admin write
                // clears the cache. Admin writes are rare and public reads are
                // not, so the cost is a refill nobody times; the cost of missing
                // one is a visitor served a page that is no longer true.
                if ($method !== 'GET' && $method !== 'HEAD') {
                    $this->renderCache?->flush();
                }

                return $response;
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
        if (array_key_exists('siteName', $data) && is_string($data['siteName'])) {
            $settings->setSiteName($data['siteName']);
        }

        // Settings are not content documents, so the storage decorator that
        // invalidates the render cache never sees this write. The site name is
        // the brand in every page's header, and headless mode changes whether
        // there is a public page at all, so both reach every cached document.
        $this->renderCache?->flush();

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
        
        try {
            return $object->$method(...$args);
        } catch (ContentRefusedException $refused) {
            // A plugin said no. That is an answer, not a fault — and until this
            // caught it, a plugin refusing a save produced a 500 and a stack
            // trace, which reads as "the CMS is broken" rather than "your
            // content was not accepted".
            //
            // 409, matching how PageService reports a publish the gate refused:
            // the request is well formed and the caller is entitled to make it;
            // what is wrong is the state of the thing being written.
            return [
                'status' => 409,
                'error' => $refused->reason,
                'refusedBy' => $refused->hook,
            ];
        } catch (StorageAuthorizationException $denied) {
            // The storage layer's own last line of defence, which had the same
            // gap: an authenticated account whose role may not write reached a
            // handler that forgot to check, and got a 500 instead of being told.
            return ['status' => 403, 'error' => $denied->getMessage()];
        }
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
