<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Core\Application;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;

/**
 * Installing a plugin runs code on the server, so the marketplace is gated on a
 * capability rather than merely on being signed in. These drive the kernel the
 * way a request does, with a seeded session, to prove a non-admin is refused and
 * an admin is not — the authorization the controller's docstring assumed but
 * nothing applied until now.
 */
final class MarketplaceGuardTest extends TestCase
{
    private string $base;
    private Application $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-mpguard-' . bin2hex(random_bytes(6));
        foreach (['content', 'data', 'config', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->app = new Application($this->base);
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
        $this->rrmdir($this->base);
    }

    /**
     * Seed a signed-in user of the given role directly into the session store,
     * without going through login or a cookie.
     */
    private function signInAs(string $role): void
    {
        $sessions = (new \ReflectionProperty(Application::class, 'sessions'))->getValue($this->app);
        $ref = new ReflectionObject($sessions);

        $id = str_repeat('a', 64);
        $ref->getProperty('id')->setValue($sessions, $id);
        $ref->getMethod('writeFile')->invoke($sessions, $id, ['user' => ['username' => 'u', 'role' => $role]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $path, string $method): array
    {
        return (new ReflectionMethod(Application::class, 'handleApiRequest'))
            ->invoke($this->app, $path, $method);
    }

    public function testAViewerCannotBrowseTheMarketplace(): void
    {
        $this->signInAs('viewer');

        $result = $this->request('marketplace', 'GET');

        $this->assertSame(403, $result['status'] ?? null);
    }

    public function testAnAuthorCannotReachTheMarketplace(): void
    {
        // A GET, so this is the capability gate refusing — not CSRF, which only
        // guards the unsafe methods.
        $this->signInAs('author');

        $result = $this->request('marketplace', 'GET');

        $this->assertSame(403, $result['status'] ?? null);
    }

    public function testAnAdminReachesTheMarketplace(): void
    {
        $this->signInAs('admin');

        $result = $this->request('marketplace', 'GET');

        // Not forbidden — it reaches the controller. The catalogue may be empty
        // (no registry configured in the test), but that is a 200 with data, not
        // a 403.
        $this->assertNotSame(403, $result['status'] ?? null);
        $this->assertArrayHasKey('data', $result);
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
