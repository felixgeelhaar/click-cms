<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Domain\Site\Site;
use Click\Cms\Domain\Site\SiteRegistry;
use Click\Cms\Http\SiteController;
use PHPUnit\Framework\TestCase;

/**
 * Naming the current site for the admin UI.
 *
 * Thin: the controller only shapes what {@see Site} and {@see SiteRegistry}
 * already know, and refuses anything that is not a GET.
 */
final class SiteControllerTest extends TestCase
{
    public function testGetReturnsTheSiteAndWhetherTheInstallIsMultiSite(): void
    {
        $site = Site::primary('Acme');
        $registry = SiteRegistry::single();
        $controller = new SiteController(
            static fn (): Site => $site,
            static fn (): SiteRegistry => $registry,
        );

        $result = $controller->handle('GET');

        $this->assertSame('primary', $result['data']['id']);
        $this->assertSame('Acme', $result['data']['title']);
        $this->assertFalse($result['data']['multiSite']);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function testNonGetIsMethodNotAllowed(): void
    {
        $controller = new SiteController(
            static fn (): Site => Site::primary(),
            static fn (): SiteRegistry => SiteRegistry::single(),
        );

        $result = $controller->handle('POST');

        $this->assertSame(405, $result['status']);
        $this->assertSame('method_not_allowed', $result['code']);
        $this->assertSame('Method not allowed', $result['error']);
    }
}
