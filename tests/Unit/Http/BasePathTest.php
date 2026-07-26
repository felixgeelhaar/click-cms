<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\BasePath;
use Click\Cms\Http\TrustedProxies;
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

    /* --------------------------------------------------- forwarded prefix -- */

    /**
     * The case detection alone cannot see: a proxy publishes the site at
     * /blog/ and forwards to an application that is installed at a root, so the
     * script's own path says nothing about the public URL. This is what
     * `core.basePath` was the only answer to, and what the field's convention —
     * `X-Forwarded-Prefix` — answers without configuration per environment.
     */
    public function testATrustedProxyMaySayWhereTheSiteIsPublished(): void
    {
        $base = BasePath::detect(
            [
                'SCRIPT_NAME' => '/index.php',
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_PREFIX' => '/blog',
            ],
            null,
            new TrustedProxies(['10.0.0.0/8'])
        );

        $this->assertSame('/blog', $base->prefix());
    }

    /**
     * The whole point of the gate. Any visitor can send this header, and the
     * prefix ends up in every URL the site emits — so an untrusted sender who
     * was believed could rewrite every link on a page, and a cached render would
     * then serve their version to everybody else.
     */
    public function testAnUntrustedSenderIsIgnored(): void
    {
        $server = [
            'SCRIPT_NAME' => '/2026/cms/index.php',
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PREFIX' => '/evil',
        ];

        $this->assertSame('/2026/cms', BasePath::detect($server, null, new TrustedProxies(['10.0.0.0/8']))->prefix());
        // And with no proxies configured at all — the default for every site.
        $this->assertSame('/2026/cms', BasePath::detect($server)->prefix());
    }

    /** Configuration is still the last word, for an operator who needs it to be. */
    public function testAConfiguredPrefixBeatsAForwardedOne(): void
    {
        $base = BasePath::detect(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PREFIX' => '/blog'],
            '/configured',
            new TrustedProxies(['10.0.0.0/8'])
        );

        $this->assertSame('/configured', $base->prefix());
    }

    /**
     * A trusted proxy is trusted, not infallible. A value that is not a path —
     * one carrying a scheme, a traversal, or a control character — is dropped
     * rather than concatenated into every link on the page.
     */
    public function testAForwardedPrefixThatIsNotAPathIsRefused(): void
    {
        $trusted = new TrustedProxies(['10.0.0.0/8']);

        $refused = [
            'https://evil.example',
            // A scheme with one slash: not a URL the browser would follow, but
            // spliced onto every link it is still not a prefix anyone meant.
            'https:/evil.example',
            '/a/../../etc',
            "/blog\n/x",
            '/a<b>',
            '\\evil',
        ];

        foreach ($refused as $value) {
            $base = BasePath::detect(
                [
                    'SCRIPT_NAME' => '/2026/cms/index.php',
                    'REMOTE_ADDR' => '10.0.0.1',
                    'HTTP_X_FORWARDED_PREFIX' => $value,
                ],
                null,
                $trusted
            );

            $this->assertSame('/2026/cms', $base->prefix(), "refused: {$value}");
        }
    }

    /** Leftmost wins in a proxy chain, as it does for every X-Forwarded header. */
    public function testTheFirstValueOfAChainIsUsed(): void
    {
        $base = BasePath::detect(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PREFIX' => '/blog, /inner'],
            null,
            new TrustedProxies(['10.0.0.0/8'])
        );

        $this->assertSame('/blog', $base->prefix());
    }

    /** A proxy publishing at the root says so, and is believed. */
    public function testATrustedProxyMaySayTheSiteIsAtTheRoot(): void
    {
        $base = BasePath::detect(
            [
                'SCRIPT_NAME' => '/2026/cms/index.php',
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_PREFIX' => '/',
            ],
            null,
            new TrustedProxies(['10.0.0.0/8'])
        );

        $this->assertSame('', $base->prefix());
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
