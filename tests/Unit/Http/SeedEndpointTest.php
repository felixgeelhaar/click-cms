<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Core\Application;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;

/**
 * Loading the example site from the admin.
 *
 * The CLI seeder refuses a web SAPI so it cannot become an unauthenticated
 * write. This is the authenticated path: only an administrator may call it,
 * CSRF still applies, and a missing body still reaches the seeder (which is
 * safe to re-run).
 */
final class SeedEndpointTest extends TestCase
{
    private string $base;
    private Application $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-seed-api-' . bin2hex(random_bytes(6));
        foreach (['content', 'data', 'config/sections', 'config/collections', 'plugins'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }

        // The seeder reads the shipped schemas. Point at the real ones so the
        // example site validates the same way a real install does.
        $root = dirname(__DIR__, 3);
        foreach (glob($root . '/config/sections/*.json') ?: [] as $file) {
            copy($file, $this->base . '/config/sections/' . basename($file));
        }
        foreach (glob($root . '/config/collections/*.json') ?: [] as $file) {
            copy($file, $this->base . '/config/collections/' . basename($file));
        }
        if (is_file($root . '/config/core.json')) {
            copy($root . '/config/core.json', $this->base . '/config/core.json');
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
        $this->rrmdir($this->base);
    }

    private function signInAs(string $role): string
    {
        $sessions = (new \ReflectionProperty(Application::class, 'sessions'))->getValue($this->app);
        $ref = new ReflectionObject($sessions);

        $id = str_repeat('c', 64);
        $token = str_repeat('d', 64);
        $ref->getProperty('id')->setValue($sessions, $id);
        $ref->getMethod('writeFile')->invoke($sessions, $id, [
            'user' => ['username' => 'u', 'role' => $role],
            'csrfToken' => $token,
            'lastActivity' => time(),
            'expiresAt' => time() + 3600,
        ]);

        return $token;
    }

    /** @return array<string, mixed> */
    private function request(string $path, string $method): array
    {
        return (new ReflectionMethod(Application::class, 'handleApiRequest'))
            ->invoke($this->app, $path, $method);
    }

    public function testAnEditorCannotSeed(): void
    {
        $token = $this->signInAs('editor');
        $_SERVER['HTTP_X_CLICK_CSRF'] = $token;

        $result = $this->request('seed', 'POST');

        $this->assertSame(403, $result['status'] ?? null);
    }

    public function testGetIsNotAllowed(): void
    {
        $this->signInAs('admin');

        $result = $this->request('seed', 'GET');

        $this->assertSame(405, $result['status'] ?? null);
    }

    public function testAnAdminCanSeedTheExampleSite(): void
    {
        $token = $this->signInAs('admin');
        $_SERVER['HTTP_X_CLICK_CSRF'] = $token;

        $result = $this->request('seed', 'POST');

        $this->assertContains($result['status'] ?? null, [200, 201, 207], (string) ($result['error'] ?? ''));
        $this->assertArrayHasKey('data', $result);
        $this->assertNotEmpty($result['data']['created'] ?? [], 'a fresh site should gain example content');
        $this->assertFileExists($this->base . '/content/page/en/home.json');
    }

    public function testSeedingTwiceIsANoOpNotAClobber(): void
    {
        $token = $this->signInAs('admin');
        $_SERVER['HTTP_X_CLICK_CSRF'] = $token;

        $this->request('seed', 'POST');
        $again = $this->request('seed', 'POST');

        $this->assertTrue($again['data']['noop'] ?? false);
        $this->assertSame([], $again['data']['created'] ?? null);
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
