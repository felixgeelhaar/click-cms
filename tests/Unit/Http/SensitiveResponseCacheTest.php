<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Http\ApiGuard;
use Click\Cms\Http\SensitiveResponseCache;
use Click\Cms\Core\Application;
use PHPUnit\Framework\TestCase;

final class SensitiveResponseCacheTest extends TestCase
{
    private string $base;
    private string $applicationBase = '';
    private SensitiveResponseCache $policy;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cache-policy-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/sessions', 0700, true);
        $this->policy = new SensitiveResponseCache(new ApiGuard(new SessionStore($this->base . '/sessions', 1800)));
    }

    protected function tearDown(): void
    {
        @rmdir($this->base . '/sessions');
        @rmdir($this->base);
        $this->removeTree($this->applicationBase);
    }

    public function testAuthenticationManagementAndPreviewResponsesArePrivateAndUncacheable(): void
    {
        foreach ([
            ['/api/auth/login', 'POST'],
            ['/api/users', 'GET'],
            ['/api/pages/home/versions', 'GET'],
            ['/api/section-presets', 'GET'],
            ['/api/section-presets', 'POST'],
            ['/api/section-presets/abc/versions/1', 'GET'],
            ['/api/collections/blog/preview/draft', 'GET'],
            ['/preview/secret-page?token=secret', 'GET'],
            ['/admin/pages/edit/home', 'GET'],
        ] as [$path, $method]) {
            self::assertSame(
                ['Cache-Control' => 'no-store, private'],
                $this->policy->headersFor($path, $method),
                $path . ' must not be stored by a browser or shared cache',
            );
        }
    }

    public function testPublicPublishedDeliveryAndOrdinaryStaticAssetsKeepExistingCaching(): void
    {
        foreach ([
            ['/api/pages', 'GET'],
            ['/api/collections/blog/published', 'GET'],
            ['/api/graphql', 'POST'],
            ['/about', 'GET'],
            ['/admin/assets/app.js', 'GET'],
            ['/theme.css', 'GET'],
        ] as [$path, $method]) {
            self::assertSame([], $this->policy->headersFor($path, $method), $path . ' should retain its existing cache policy');
        }
    }

    public function testAuthenticatedPublicDeliveryCannotReusePublicCacheEntries(): void
    {
        self::assertSame(
            ['Cache-Control' => 'no-store, private'],
            $this->policy->headersFor('/api/pages/home', 'GET', authenticated: true),
        );
        self::assertSame(
            ['Cache-Control' => 'no-store, private'],
            $this->policy->headersFor('/api/graphql', 'POST', authenticated: true),
        );
    }

    public function testApplicationReturnsCacheHeadersForAdminHtmlAndManagementApi(): void
    {
        $this->applicationBase = sys_get_temp_dir() . '/click-cache-app-' . bin2hex(random_bytes(6));
        foreach (['content', 'data', 'config', 'plugins', 'public/admin'] as $directory) {
            mkdir($this->applicationBase . '/' . $directory, 0700, true);
        }
        file_put_contents($this->applicationBase . '/public/admin/index.html', '<html>admin</html>');
        $app = new Application($this->applicationBase);
        $app->boot();

        $admin = $app->route('/admin/pages/edit/home', 'GET');
        self::assertSame(['Cache-Control' => 'no-store, private'], $admin['headers']);
        self::assertSame('<html>admin</html>', $admin['html']);

        $management = $app->route('/api/users', 'GET');
        self::assertSame(401, $management['status']);
        self::assertSame(['Cache-Control' => 'no-store, private'], $management['headers']);

        $auth = $app->route('/api/auth/check', 'GET');
        self::assertSame(['Cache-Control' => 'no-store, private'], $auth['headers']);

        $delivery = $app->route('/api/pages', 'GET');
        self::assertArrayNotHasKey('headers', $delivery);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach (array_diff($items, ['.', '..']) as $item) {
            $child = $path . '/' . $item;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
