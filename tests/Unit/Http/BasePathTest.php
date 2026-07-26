<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\BasePath;
use PHPUnit\Framework\TestCase;

/**
 * The URL prefix an installation is served under.
 *
 * Every rule here exists because getting it wrong breaks a site in a way that
 * looks like something else: a prefix detected where there is none turns every
 * route into a 404, and a prefix missed turns every generated link into one.
 * So detection is pinned per hosting shape rather than only through a request.
 */
final class BasePathTest extends TestCase
{
    /* ------------------------------------------------------------ detect -- */

    public function testASiteAtTheDomainRootHasNoPrefix(): void
    {
        $base = BasePath::detect(['SCRIPT_NAME' => '/index.php']);

        $this->assertSame('', $base->prefix());
        $this->assertSame('/api/pages', $base->strip('/api/pages'));
        $this->assertSame('/theme.css', $base->url('/theme.css'));
    }

    public function testASiteInASubdirectoryTakesItsPrefixFromTheScriptName(): void
    {
        $base = BasePath::detect(['SCRIPT_NAME' => '/2026/cms/index.php']);

        $this->assertSame('/2026/cms', $base->prefix());
    }

    public function testAMissingScriptNameMeansNoPrefix(): void
    {
        $this->assertSame('', BasePath::detect([])->prefix());
    }

    /**
     * The null object every URL-emitting class defaults to. Without it each of
     * them would need a nullable dependency and a `?->url() ?? $path` at every
     * call — the shape that quietly leaves one URL unprefixed.
     */
    public function testTheRootIsTheDoNothingPrefix(): void
    {
        $root = BasePath::root();

        $this->assertSame('', $root->prefix());
        $this->assertSame('/api/pages', $root->url('/api/pages'));
        $this->assertSame('/api/pages', $root->strip('/api/pages'));
    }

    /**
     * PHP's built-in server reports the *requested path* as SCRIPT_NAME when it
     * routes through a script, so `/api/pages` would otherwise be read as a site
     * installed under `/api`. Only a script name that actually names a PHP file
     * is trusted to describe where the installation lives.
     */
    public function testAScriptNameThatIsNotAPhpFileIsNotTrusted(): void
    {
        $this->assertSame('', BasePath::detect(['SCRIPT_NAME' => '/api/pages'])->prefix());
    }

    public function testAConfiguredPrefixWinsOverDetection(): void
    {
        $base = BasePath::detect(['SCRIPT_NAME' => '/index.php'], '/2026/cms');

        $this->assertSame('/2026/cms', $base->prefix());
    }

    /**
     * A prefix is written by a person into a config file, so it arrives in every
     * shape a person might write it in.
     */
    public function testAConfiguredPrefixIsNormalised(): void
    {
        $this->assertSame('/2026/cms', BasePath::detect([], '2026/cms/')->prefix());
        $this->assertSame('/2026/cms', BasePath::detect([], '/2026/cms/')->prefix());
        $this->assertSame('', BasePath::detect([], '/')->prefix());
    }

    /**
     * Configuring the root explicitly is an answer, not an absence of one. This
     * is the reverse-proxy case the setting exists for, in reverse: the script
     * lives under a directory but the site is published at the root, and only
     * the operator knows. Falling back to detection here would override them
     * with the very value they configured around.
     */
    public function testAnExplicitlyEmptyPrefixMeansTheDomainRoot(): void
    {
        $this->assertSame('', BasePath::detect(['SCRIPT_NAME' => '/2026/cms/index.php'], '')->prefix());
        $this->assertSame('', BasePath::detect(['SCRIPT_NAME' => '/2026/cms/index.php'], '   ')->prefix());
    }

    /* ------------------------------------------------------------- strip -- */

    public function testStripRemovesThePrefixFromARequestPath(): void
    {
        $base = BasePath::detect([], '/2026/cms');

        $this->assertSame('/api/pages', $base->strip('/2026/cms/api/pages'));
        $this->assertSame('/admin/', $base->strip('/2026/cms/admin/'));
    }

    public function testTheInstallationRootStripsToASlash(): void
    {
        $base = BasePath::detect([], '/2026/cms');

        $this->assertSame('/', $base->strip('/2026/cms'));
        $this->assertSame('/', $base->strip('/2026/cms/'));
    }

    /**
     * Left alone rather than mangled. A request that does not belong to this
     * installation should reach the router unchanged and 404 there — quietly
     * rewriting it would serve one site's page under another's URL.
     */
    public function testAPathOutsideThePrefixIsUntouched(): void
    {
        $base = BasePath::detect([], '/2026/cms');

        $this->assertSame('/elsewhere/api', $base->strip('/elsewhere/api'));
    }

    /**
     * `/cms` must not match `/cmsx`: the prefix is a sequence of path segments,
     * not a string prefix. Without the boundary check, a neighbouring directory
     * whose name merely starts the same way would have its requests rewritten.
     */
    public function testThePrefixMatchesOnSegmentBoundaries(): void
    {
        $base = BasePath::detect([], '/cms');

        $this->assertSame('/cmsx/api/pages', $base->strip('/cmsx/api/pages'));
        $this->assertSame('/api/pages', $base->strip('/cms/api/pages'));
    }

    /**
     * Shared hosting without mod_rewrite reaches the front controller by naming
     * it, and the path arrives as PATH_INFO on the end of the script name. The
     * router should see the same `/api/pages` either way, so a site that cannot
     * rewrite still works.
     */
    public function testTheFrontControllerNameIsStrippedWhenItIsInThePath(): void
    {
        $base = BasePath::detect(['SCRIPT_NAME' => '/2026/cms/index.php']);

        $this->assertSame('/api/pages', $base->strip('/2026/cms/index.php/api/pages'));
        $this->assertSame('/', $base->strip('/2026/cms/index.php'));
    }

    public function testStrippingIsANoOpWithoutAPrefix(): void
    {
        $base = BasePath::detect(['SCRIPT_NAME' => '/index.php']);

        $this->assertSame('/api/pages', $base->strip('/api/pages'));
        $this->assertSame('/', $base->strip('/'));
    }

    /* --------------------------------------------------------------- url -- */

    public function testUrlPrefixesAnApplicationPath(): void
    {
        $base = BasePath::detect([], '/2026/cms');

        $this->assertSame('/2026/cms/api/media/file', $base->url('/api/media/file'));
        $this->assertSame('/2026/cms/', $base->url('/'));
    }

    public function testUrlLeavesAbsoluteUrlsAlone(): void
    {
        $base = BasePath::detect([], '/2026/cms');

        $this->assertSame('https://cdn.example/x.jpg', $base->url('https://cdn.example/x.jpg'));
        $this->assertSame('//cdn.example/x.jpg', $base->url('//cdn.example/x.jpg'));
    }

    public function testUrlAndStripRoundTrip(): void
    {
        $base = BasePath::detect([], '/2026/cms');

        $this->assertSame('/api/pages', $base->strip($base->url('/api/pages')));
    }
}
