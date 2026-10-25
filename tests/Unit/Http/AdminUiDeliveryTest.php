<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Core\Application;
use PHPUnit\Framework\TestCase;

final class AdminUiDeliveryTest extends TestCase
{
    public function testSplitRootDeepLinkServesUpdatedExternalShellInsteadOfStaleAppCopy(): void
    {
        $base = sys_get_temp_dir() . '/click-admin-split-' . bin2hex(random_bytes(6));
        $external = $base . '-public';
        mkdir($base . '/public/admin', 0700, true);
        mkdir($external . '/admin', 0700, true);
        file_put_contents($base . '/public/admin/index.html', 'Stale app shell');
        file_put_contents($external . '/admin/index.html', 'Updated public shell');
        $previous = getenv('CLICK_ADMIN_DEV_URL');
        putenv('CLICK_ADMIN_DEV_URL');
        try {
            $app = new Application($base);
            (new \ReflectionProperty($app, 'config'))->setValue($app,
                \Click\Cms\Application\Config\CoreConfig::fromArray([
                    'core' => ['updates' => ['publicRoot' => $external]],
                ]));
            $response = (new \ReflectionMethod($app, 'serveAdminUi'))->invoke($app, '/admin/pages/edit/home');
            self::assertSame(200, $response['status']);
            self::assertSame('Updated public shell', $response['html']);
        } finally {
            putenv($previous === false ? 'CLICK_ADMIN_DEV_URL' : 'CLICK_ADMIN_DEV_URL=' . $previous);
            unlink($base . '/public/admin/index.html');
            rmdir($base . '/public/admin');
            rmdir($base . '/public');
            rmdir($base);
            unlink($external . '/admin/index.html');
            rmdir($external . '/admin');
            rmdir($external);
        }
    }

    public function testDeepLinksServeTheBuiltShellButMissingAssetsDoNot(): void
    {
        $base = sys_get_temp_dir() . '/click-admin-ui-' . bin2hex(random_bytes(6));
        mkdir($base . '/public/admin', 0700, true);
        file_put_contents($base . '/public/admin/index.html', '<html>Admin shell</html>');
        $previous = getenv('CLICK_ADMIN_DEV_URL');
        putenv('CLICK_ADMIN_DEV_URL');
        try {
            $app = new Application($base);
            $serve = new \ReflectionMethod($app, 'serveAdminUi');
            $response = $serve->invoke($app, '/admin/pages/edit/home');
            $this->assertSame(200, $response['status']);
            $this->assertTrue($response['raw']);
            $this->assertSame('<html>Admin shell</html>', $response['html']);
            $missing = $serve->invoke($app, '/admin/_astro/missing.js');
            $this->assertSame(404, $missing['status']);
            $this->assertArrayNotHasKey('html', $missing);
        } finally {
            putenv($previous === false ? 'CLICK_ADMIN_DEV_URL' : 'CLICK_ADMIN_DEV_URL=' . $previous);
            unlink($base . '/public/admin/index.html');
            rmdir($base . '/public/admin');
            rmdir($base . '/public');
            rmdir($base);
        }
    }
}
