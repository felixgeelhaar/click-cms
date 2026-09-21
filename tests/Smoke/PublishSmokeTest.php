<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Smoke;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Plugin\PublishGate;
use Click\Cms\Core\Application;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;

/**
 * Login-adjacent publish smoke, at the service and API layer.
 *
 * Playwright (a browser walking login → edit → publish) stays deferred: there
 * is no browser CI. This is the path that walk would hit, without a browser.
 * A draft is saved through {@see PageService} — the same service
 * `POST /api/pages` uses — then an administrator session publishes it through
 * `POST /api/pages/{slug}/publish`, the route the admin UI calls. The page is
 * not public before that, and is afterwards.
 *
 * Logout and a fresh login are not part of this: standing up a password and
 * posting it through `php://input` is heavier than the question being asked,
 * and the session the publish request presents is the same shape a login
 * would have left behind.
 */
final class PublishSmokeTest extends TestCase
{
    private string $base;
    private Application $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-publish-smoke-' . bin2hex(random_bytes(6));
        foreach (['content', 'data', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_X_CLICK_CSRF']);

        $this->app = new Application($this->base);
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        unset($_SERVER['HTTP_X_CLICK_CSRF']);
        // Boot installs a process-wide gate. Forget it so a later test is not
        // publishing through this temporary site's plugin manager.
        PublishGate::useAmbient(null);
        $this->rrmdir($this->base);
    }

    /**
     * The content service the running application publishes through, so the
     * draft and the publish agree about where the working copy lives.
     */
    private function pages(): PageService
    {
        $content = (new ReflectionProperty(Application::class, 'contentService'))->getValue($this->app);
        $this->assertInstanceOf(ContentService::class, $content);

        return new PageService(
            $content,
            new JsonSectionTypeRepository(dirname(__DIR__, 2) . '/config/sections'),
        );
    }

    /** @return array<string, mixed> */
    private function admin(): array
    {
        return ['username' => 'ada', 'role' => 'admin'];
    }

    private function signInAsAdmin(): string
    {
        $sessions = (new ReflectionProperty(Application::class, 'sessions'))->getValue($this->app);
        $ref = new ReflectionObject($sessions);

        $id = str_repeat('a', 64);
        $token = str_repeat('b', 64);
        $ref->getProperty('id')->setValue($sessions, $id);
        $ref->getMethod('writeFile')->invoke($sessions, $id, [
            'user' => $this->admin(),
            'csrfToken' => $token,
            'lastActivity' => time(),
            'expiresAt' => time() + 3600,
        ]);

        // The kernel's guard reads the in-memory id set above. The page
        // handler builds its own store and only sees the cookie, which is how
        // a browser presents the same session.
        $_COOKIE[SessionStore::COOKIE] = $id;

        return $token;
    }

    /** @return array<string, mixed> */
    private function request(string $path, string $method): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;

        return (new ReflectionMethod(Application::class, 'handleApiRequest'))
            ->invoke($this->app, $path, $method);
    }

    public function testAnAdminSessionPublishesADraftThroughTheApi(): void
    {
        $created = $this->pages()->create(
            ['title' => 'Smoke', 'slug' => 'smoke'],
            $this->admin(),
        );

        $this->assertNull($created['error'], (string) ($created['error'] ?? ''));
        $this->assertSame(201, $created['status']);
        $this->assertFalse($this->pages()->publicationOf('smoke')->published);

        // No session: a visitor must not be handed the draft.
        $hidden = $this->request('pages/smoke', 'GET');
        $this->assertSame(404, $hidden['status'] ?? null);

        $token = $this->signInAsAdmin();
        $_SERVER['HTTP_X_CLICK_CSRF'] = $token;

        $published = $this->request('pages/smoke/publish', 'POST');

        $this->assertArrayNotHasKey('error', $published, (string) ($published['error'] ?? ''));
        $this->assertTrue($published['data']['publication']['published'] ?? false);
        $this->assertFalse($published['data']['publication']['hasUnpublishedChanges'] ?? true);
        $this->assertSame('Smoke', $published['data']['page']['data']['title'] ?? null);

        // The same read, still with no session, now serves the live page.
        // The store also remembers the id in memory for this process, so
        // clearing the cookie alone would leave the request signed in.
        $_COOKIE = [];
        unset($_SERVER['HTTP_X_CLICK_CSRF']);
        $sessions = (new ReflectionProperty(Application::class, 'sessions'))->getValue($this->app);
        (new ReflectionProperty($sessions, 'id'))->setValue($sessions, null);
        $public = $this->request('pages/smoke', 'GET');

        $this->assertArrayNotHasKey('error', $public, (string) ($public['error'] ?? json_encode($public)));
        $this->assertSame('smoke', $public['data']['slug'] ?? null);
        $this->assertSame('Smoke', $public['data']['title'] ?? $public['data']['data']['title'] ?? null);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
