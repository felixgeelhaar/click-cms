<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Site;

use Click\Cms\Core\Application;
use PHPUnit\Framework\TestCase;

/**
 * The one property multi-site has to have: two sites cannot see each other.
 *
 * Everything else about the feature is convenience. This is the part that, when
 * it fails, puts one client's unpublished work on another client's domain — so
 * it is tested at the kernel, against the paths the application actually builds,
 * rather than against the registry that decides them.
 *
 * The design under test: nothing below the kernel knows sites exist. Each
 * request resolves a site from its hostname and hands every service a root
 * directory; isolation is therefore a property of where the bytes are, not of a
 * predicate somebody has to remember to add to a query.
 */
final class SiteIsolationTest extends TestCase
{
    private string $root;
    private ?string $previousHost = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-cms-sites-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0o775, true);

        $this->previousHost = $_SERVER['HTTP_HOST'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->previousHost;
        }

        $this->removeTree($this->root);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    private function declareSites(array $sites): void
    {
        file_put_contents(
            $this->root . '/config/sites.json',
            (string) json_encode(['sites' => $sites], JSON_PRETTY_PRINT)
        );
    }

    private function appFor(string $host): Application
    {
        $_SERVER['HTTP_HOST'] = $host;

        return new Application($this->root);
    }

    /* --------------------------------------------------- no configuration -- */

    /**
     * What makes this additive rather than a migration: an installation that
     * never declares a site keeps `content/` and `data/` exactly where they
     * were, so an upgrade moves nothing and breaks nothing.
     */
    public function testAnInstallationWithNoSitesFileIsUnchanged(): void
    {
        $app = $this->appFor('anything.example.com');

        $this->assertSame($this->root, $app->siteRoot());
        $this->assertSame('primary', $app->site()->id);
    }

    /* ------------------------------------------------------------ routing -- */

    public function testEachHostGetsItsOwnRoot(): void
    {
        $this->declareSites([
            ['id' => 'primary', 'hosts' => ['main.example.com']],
            ['id' => 'acme', 'hosts' => ['acme.example.com']],
        ]);

        $this->assertSame($this->root, $this->appFor('main.example.com')->siteRoot());
        $this->assertSame($this->root . '/sites/acme', $this->appFor('acme.example.com')->siteRoot());
    }

    /**
     * The assertion the whole feature stands on. Two sites never share a
     * directory, so there is no query that could return the other's content
     * even if somebody wrote one.
     */
    public function testTwoSitesNeverShareADirectory(): void
    {
        $this->declareSites([
            ['id' => 'acme', 'hosts' => ['acme.example.com']],
            ['id' => 'globex', 'hosts' => ['globex.example.com']],
        ]);

        $acme = $this->appFor('acme.example.com')->siteRoot();
        $globex = $this->appFor('globex.example.com')->siteRoot();

        $this->assertNotSame($acme, $globex);
        $this->assertStringStartsNotWith($acme . '/', $globex);
        $this->assertStringStartsNotWith($globex . '/', $acme);
    }

    /**
     * `Host` is whatever the client sent. A hostname nobody declared must land
     * on the default site rather than being trusted to name one — and must
     * certainly never become a path segment.
     */
    public function testAnUnknownHostCannotInventASite(): void
    {
        $this->declareSites([
            ['id' => 'primary', 'hosts' => ['main.example.com']],
            ['id' => 'acme', 'hosts' => ['acme.example.com']],
        ]);

        $app = $this->appFor('../../etc/passwd');

        $this->assertSame('primary', $app->site()->id);
        $this->assertSame($this->root, $app->siteRoot());
    }

    public function testAHostThatIsAnAttemptedTraversalIsNotUsedAsAPath(): void
    {
        $this->declareSites([['id' => 'acme', 'hosts' => ['acme.example.com']]]);

        $root = $this->appFor('acme.example.com/../../elsewhere')->siteRoot();

        $this->assertStringNotContainsString('..', $root);
    }

    /* --------------------------------------------------------------- cli -- */

    /**
     * A command-line run has no hostname, so it says which site it means.
     */
    public function testACommandLineRunCanNameItsSite(): void
    {
        $this->declareSites([
            ['id' => 'primary', 'hosts' => ['main.example.com']],
            ['id' => 'acme', 'hosts' => ['acme.example.com']],
        ]);

        $app = new Application($this->root, 'acme');

        $this->assertSame('acme', $app->site()->id);
        $this->assertSame($this->root . '/sites/acme', $app->siteRoot());
    }

    /**
     * A typo in a cron entry must stop, not quietly operate on whichever site
     * happens to be first. Publishing one client's content on another client's
     * schedule is not an error anybody would notice quickly.
     */
    public function testACommandLineRunNamingAnUnknownSiteRefuses(): void
    {
        $this->declareSites([['id' => 'acme', 'hosts' => ['acme.example.com']]]);

        $this->expectExceptionMessageMatches('/no site is configured/i');

        (new Application($this->root, 'acmee'))->site();
    }

    /* ------------------------------------------------------------ broken -- */

    /**
     * A `sites.json` that cannot be parsed must not fall back to serving every
     * host from the primary site: for an agency that means one client's content
     * appearing on another client's domain, silently.
     */
    public function testAnUnreadableSitesFileIsAnErrorRatherThanAFallback(): void
    {
        file_put_contents($this->root . '/config/sites.json', '{ this is not json');

        $this->expectExceptionMessageMatches('/could not be read/i');

        $this->appFor('acme.example.com')->site();
    }
}
