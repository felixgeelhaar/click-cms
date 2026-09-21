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
 * an admin is not — authorization now lives in MarketplaceController (same
 * peel as Themes / Seed), not inline in Application.
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
        unset($_SERVER['HTTP_X_CLICK_CSRF']);
        $_FILES = [];
        $this->rrmdir($this->base);
    }

    /**
     * Seed a signed-in user of the given role directly into the session store,
     * without going through login or a cookie.
     *
     * @return string The CSRF token written into the session, so a test that
     *                issues an unsafe method can present the matching header.
     */
    private function signInAs(string $role): string
    {
        $sessions = (new \ReflectionProperty(Application::class, 'sessions'))->getValue($this->app);
        $ref = new ReflectionObject($sessions);

        $id = str_repeat('a', 64);
        $token = str_repeat('b', 64);
        $ref->getProperty('id')->setValue($sessions, $id);
        $ref->getMethod('writeFile')->invoke($sessions, $id, [
            'user' => ['username' => 'u', 'role' => $role],
            'csrfToken' => $token,
            'lastActivity' => time(),
            'expiresAt' => time() + 3600,
        ]);

        return $token;
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
        $this->assertSame('forbidden', $result['code'] ?? null);
    }

    public function testAnAuthorCannotReachTheMarketplace(): void
    {
        // A GET, so this is the capability gate refusing — not CSRF, which only
        // guards the unsafe methods.
        $this->signInAs('author');

        $result = $this->request('marketplace', 'GET');

        $this->assertSame(403, $result['status'] ?? null);
        $this->assertSame('forbidden', $result['code'] ?? null);
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
        $this->assertFalse($result['data']['registryConfigured']);
        // Unconfigured must not look like a failure on a fresh install.
        $this->assertSame([], $result['data']['errors']);
    }

    public function testUploadWithoutAFileIsABadRequestNotMethodNotAllowed(): void
    {
        $token = $this->signInAs('admin');
        $_SERVER['HTTP_X_CLICK_CSRF'] = $token;
        $_FILES = [];

        $result = $this->request('marketplace/upload', 'POST');

        $this->assertSame(400, $result['status'] ?? null);
        $this->assertSame('bad_request', $result['code'] ?? null);
        $this->assertStringContainsString('uploaded', strtolower((string) ($result['error'] ?? '')));
    }

    public function testAnEditorCannotUploadAPlugin(): void
    {
        $token = $this->signInAs('editor');
        $_SERVER['HTTP_X_CLICK_CSRF'] = $token;

        $result = $this->request('marketplace/upload', 'POST');

        $this->assertSame(403, $result['status'] ?? null);
        $this->assertSame('forbidden', $result['code'] ?? null);
    }

    public function testAnAdminCanUploadAPluginZip(): void
    {
        $token = $this->signInAs('admin');
        $_SERVER['HTTP_X_CLICK_CSRF'] = $token;

        $zip = $this->base . '/data/upload-plugin.zip';
        $archive = new \ZipArchive();
        $archive->open($zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $archive->addFromString('demo/plugin.json', json_encode(['name' => 'Demo Plugin', 'version' => '1.0.0']));
        $archive->addFromString('demo/bootstrap.php', "<?php\n");
        $archive->close();

        $_FILES['file'] = [
            'name' => 'demo.zip',
            'type' => 'application/zip',
            'tmp_name' => $zip,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($zip),
        ];

        $result = $this->request('marketplace/upload', 'POST');

        $this->assertSame(201, $result['status'] ?? null, (string) ($result['error'] ?? ''));
        $this->assertSame('demo-plugin', $result['data']['id'] ?? null);
        $this->assertFileExists($this->base . '/plugins/demo-plugin/plugin.json');
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
