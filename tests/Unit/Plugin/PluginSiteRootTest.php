<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * What a plugin is told about where things are.
 *
 * Two roots, and conflating them is not a tidiness problem. Multi-site scopes
 * the kernel by handing each request a different root directory, on the
 * reasoning that a forgotten scope is then not expressible. It was expressible
 * through here: plugins asked for the base path and appended `/data/…`, which
 * on any site but the primary is another site's directory. Two of them read the
 * session store that way to decide whether the caller was allowed to do
 * something, found no session where they had looked, and permitted the request.
 *
 * On a single-site installation the two roots are the same directory, which is
 * exactly why this was invisible until sites existed — and why it is pinned
 * here rather than left to be noticed again.
 */
final class PluginSiteRootTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-plugin-roots-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/plugins', 0o775, true);
        mkdir($this->root . '/sites/acme/data', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (['/sites/acme/data', '/sites/acme', '/sites', '/plugins'] as $dir) {
            @array_map('unlink', glob($this->root . $dir . '/*') ?: []);
            @rmdir($this->root . $dir);
        }
        @array_map('unlink', glob($this->root . '/*') ?: []);
        @rmdir($this->root);
    }

    /* -------------------------------------------------------- multi-site -- */

    public function testTheSiteRootIsTheSitesOwnDirectory(): void
    {
        $manager = new PluginManager($this->root . '/plugins', $this->root . '/sites/acme/data');

        $this->assertSame($this->root . '/sites/acme', $manager->getSiteRoot());
        $this->assertSame($this->root . '/sites/acme/data', $manager->getDataPath());
    }

    /**
     * The code stays where the code is. Plugins, the built admin UI and themes
     * are deployed once and shared by every site, so the base path must not
     * follow the site.
     */
    public function testTheBasePathStaysAtTheInstallation(): void
    {
        $manager = new PluginManager($this->root . '/plugins', $this->root . '/sites/acme/data');

        $this->assertSame($this->root, $manager->getBasePath());
    }

    /**
     * The assertion this whole class exists for: on a site that is not the
     * primary, the two roots must differ. A plugin appending `/data/sessions`
     * to the wrong one reads another site's sessions, and the two plugins that
     * do that treat "no session found" as permission granted.
     */
    public function testTheTwoRootsDifferOnANonPrimarySite(): void
    {
        $manager = new PluginManager($this->root . '/plugins', $this->root . '/sites/acme/data');

        $this->assertNotSame($manager->getBasePath(), $manager->getSiteRoot());
    }

    /* ------------------------------------------------------- single-site -- */

    /**
     * The primary site's data is at the installation root, so the two coincide.
     * That is not a special case in the code, and it is the reason the fault
     * above went unseen.
     */
    public function testTheTwoRootsCoincideOnASingleSiteInstallation(): void
    {
        $manager = new PluginManager($this->root . '/plugins', $this->root . '/data');

        $this->assertSame($this->root, $manager->getBasePath());
        $this->assertSame($this->root, $manager->getSiteRoot());
    }

    /**
     * A caller predating sites passes no data path at all and must keep working,
     * resolving both roots to the installation.
     *
     * Where its `plugin-state.json` lands is guarded by the existing
     * `PluginManager` tests rather than repeated here — they construct without a
     * data path and would fail if this change had moved it. That matters: a
     * moved state file reads as "every plugin was just activated", which on a
     * site that had deliberately switched one off is a silent reactivation of
     * code somebody removed on purpose.
     */
    public function testACallerWithNoDataPathIsUnchanged(): void
    {
        $manager = new PluginManager($this->root . '/plugins');

        $this->assertSame($this->root, $manager->getBasePath());
        $this->assertSame($this->root, $manager->getSiteRoot());
    }

    public function testATrailingSlashOnTheDataPathIsNotSignificant(): void
    {
        $manager = new PluginManager($this->root . '/plugins/', $this->root . '/sites/acme/data/');

        $this->assertSame($this->root . '/sites/acme', $manager->getSiteRoot());
        $this->assertSame($this->root, $manager->getBasePath());
    }
}
