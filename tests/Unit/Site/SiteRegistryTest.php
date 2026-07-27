<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Site;

use Click\Cms\Domain\Site\Site;
use Click\Cms\Domain\Site\SiteRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SiteRegistryTest extends TestCase
{
    private function registry(): SiteRegistry
    {
        return SiteRegistry::fromArray(['sites' => [
            ['id' => 'primary', 'hosts' => ['main.example.com'], 'title' => 'Main'],
            ['id' => 'acme', 'hosts' => ['acme.example.com', '*.acme.com'], 'title' => 'Acme'],
        ]]);
    }

    public function testAnInstallationWithNoConfigurationHasOneSite(): void
    {
        $registry = SiteRegistry::single();

        $this->assertFalse($registry->isMultiSite());
        $this->assertSame(Site::PRIMARY, $registry->default()->id);
        $this->assertSame('', $registry->default()->rootSuffix());
    }

    public function testAnEmptyConfigurationIsTheSameAsNone(): void
    {
        $this->assertFalse(SiteRegistry::fromArray(['sites' => []])->isMultiSite());
    }

    public function testItResolvesByHost(): void
    {
        $this->assertSame('acme', $this->registry()->forHost('acme.example.com')->id);
        $this->assertSame('primary', $this->registry()->forHost('main.example.com')->id);
    }

    public function testAWildcardHostResolves(): void
    {
        $this->assertSame('acme', $this->registry()->forHost('shop.acme.com')->id);
    }

    /**
     * A request on a hostname nobody declared is ordinary — an IP address, a
     * health check, a staging alias somebody forgot. Answering it with the
     * default beats a 500 that takes the installation down when DNS changes.
     */
    public function testAnUnknownHostFallsBackToTheDefault(): void
    {
        $this->assertSame('primary', $this->registry()->forHost('who.knows.example')->id);
        $this->assertSame('primary', $this->registry()->forHost(null)->id);
        $this->assertSame('primary', $this->registry()->forHost('')->id);
    }

    /**
     * Command-line tools have no host at all, so something has to answer. With
     * no site marked primary the first declared one takes the role, which keeps
     * `sites.json` from needing a magic entry nobody would think to write.
     */
    public function testTheFirstSiteIsTheDefaultWhenNoneIsMarkedPrimary(): void
    {
        $registry = SiteRegistry::fromArray(['sites' => [
            ['id' => 'first', 'hosts' => ['first.example.com']],
            ['id' => 'second', 'hosts' => ['second.example.com']],
        ]]);

        $this->assertSame('first', $registry->default()->id);
    }

    public function testItFindsASiteById(): void
    {
        $this->assertSame('acme', $this->registry()->forId('acme')?->id);
        $this->assertNull($this->registry()->forId('nobody'));
    }

    public function testMoreThanOneSiteIsMultiSite(): void
    {
        $this->assertTrue($this->registry()->isMultiSite());
    }

    /**
     * The isolation this whole design rests on: two sites never share a
     * directory, so a query that forgot to scope itself is not expressible.
     */
    public function testEachSiteHasItsOwnRoot(): void
    {
        $roots = array_map(
            static fn (Site $s): string => $s->rootSuffix(),
            $this->registry()->all()
        );

        $this->assertSame(count($roots), count(array_unique($roots)));
    }
}
