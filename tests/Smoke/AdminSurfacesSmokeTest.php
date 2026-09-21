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
 * Every admin sidebar surface's backing API, as an administrator session.
 *
 * Complements the GUI page tour: a Vue screen that loads while its list
 * endpoint answers 4xx/5xx looks empty or broken. This hits the same routes
 * the admin components fetch on mount.
 */
final class AdminSurfacesSmokeTest extends TestCase
{
    private string $base;
    private Application $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-admin-surfaces-' . bin2hex(random_bytes(6));
        foreach (['content', 'data', 'plugins', 'themes/default', 'config'] as $dir) {
            mkdir($this->base . '/' . $dir, 0o775, true);
        }
        file_put_contents($this->base . '/themes/default/theme.json', json_encode(['name' => 'Default', 'version' => '1.0.0']));
        file_put_contents($this->base . '/themes/default/theme.css', 'body{}');
        file_put_contents($this->base . '/config/core.json', json_encode([
            'core' => [
                'storage' => ['backend' => 'json'],
                'locales' => ['default' => 'en', 'supported' => ['en']],
                'marketplace' => ['enabled' => true],
            ],
        ], JSON_PRETTY_PRINT));

        // Symlink rather than copy: plugin bootstraps require_once relative to
        // the real install (`../../src/...`), so a copied tree cannot load.
        $repoPlugins = dirname(__DIR__, 2) . '/plugins';
        foreach (['collaboration', 'webhooks', 'forms', 'visual-builder'] as $plugin) {
            $src = $repoPlugins . '/' . $plugin;
            if (is_dir($src)) {
                symlink($src, $this->base . '/plugins/' . $plugin);
            }
        }
        file_put_contents($this->base . '/data/plugin-state.json', json_encode([
            'collaboration' => ['state' => 'activated'],
            'webhooks' => ['state' => 'activated'],
            'forms' => ['state' => 'activated'],
            'visual-builder' => ['state' => 'activated'],
        ], JSON_PRETTY_PRINT));

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
        PublishGate::useAmbient(null);
        $this->rrmdir($this->base);
    }

    public function testEveryAdminSurfaceReadEndpointAnswersSuccessfully(): void
    {
        $this->signInAsAdmin();

        $paths = [
            'settings',
            'site',
            'pages',
            'collaboration/review',
            'media',
            'media/capabilities',
            'plugins',
            'marketplace',
            'users',
            'redirects',
            'menus',
            'themes',
            'webhooks',
            'updates',
            'builder/blocks',
            'forms/submissions',
            'audit',
            'section-types',
        ];

        foreach ($paths as $path) {
            $response = $this->request($path, 'GET');
            $status = $response['status'] ?? 200;
            $this->assertLessThan(400, $status, "GET /api/{$path}: " . json_encode($response));
            $this->assertArrayNotHasKey('error', $response, "GET /api/{$path}: " . json_encode($response));
        }
    }

    public function testSettingsPutAndThemesListAreReachableForAnAdmin(): void
    {
        $token = $this->signInAsAdmin();
        $_SERVER['HTTP_X_CLICK_CSRF'] = $token;

        $put = $this->request('settings', 'PUT');
        // Empty body is fine: controller ignores unknown/missing keys and returns current.
        $_POST = ['siteName' => 'Surface Smoke Site'];
        // Re-issue with body via php://input is awkward in-process; use $_POST
        // fallback the same way ThemesController tests do for JSON.
        $put = $this->request('settings', 'PUT');
        // SettingsController reads json from php://input then $_POST — set both paths.
        // Without a body the PUT still succeeds and returns data.
        $this->assertArrayNotHasKey('error', $put, json_encode($put));
        $this->assertArrayHasKey('data', $put);

        $themes = $this->request('themes', 'GET');
        $this->assertArrayNotHasKey('error', $themes, json_encode($themes));
        $this->assertNotEmpty($themes['data']['themes'] ?? []);
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

        $id = str_repeat('c', 64);
        $token = str_repeat('d', 64);
        $ref->getProperty('id')->setValue($sessions, $id);
        $ref->getMethod('writeFile')->invoke($sessions, $id, [
            'user' => $this->admin(),
            'csrfToken' => $token,
            'lastActivity' => time(),
            'expiresAt' => time() + 3600,
        ]);

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

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
