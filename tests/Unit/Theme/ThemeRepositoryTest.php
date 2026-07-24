<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Theme;

use Click\Cms\Application\Theme\ThemeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Themes are discovered by scanning a directory a site owns and can put anything
 * into, and the chosen one is read on every public page render. So what is
 * pinned here is not "does it find a theme" but the things that decide whether a
 * site stays up: a broken theme is skipped rather than thrown over, an id that
 * could escape the themes directory never reaches the filesystem, a choice
 * survives the request that made it, and a choice that could not be honoured is
 * refused instead of stored and silently ignored later.
 */
final class ThemeRepositoryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-theme-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/themes', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    private function repository(): ThemeRepository
    {
        return ThemeRepository::forInstallation($this->base);
    }

    /** Write a theme directory: a manifest, and the stylesheet it names. */
    private function installTheme(string $id, array $manifest = [], ?string $css = 'body{}'): void
    {
        $dir = $this->base . '/themes/' . $id;
        mkdir($dir, 0o775, true);
        file_put_contents($dir . '/theme.json', json_encode($manifest + [
            'name' => ucfirst($id),
            'version' => '1.0.0',
        ]));

        if ($css !== null) {
            file_put_contents($dir . '/' . ($manifest['stylesheet'] ?? 'theme.css'), $css);
        }
    }

    /** @return list<string> */
    private function ids(ThemeRepository $repository): array
    {
        return array_map(static fn ($t): string => $t->id, $repository->all());
    }

    /* ------------------------------------------------------------ discovery -- */

    public function testItDiscoversEveryThemeThatHasAValidManifest(): void
    {
        $this->installTheme('default');
        $this->installTheme('dark');

        // Sorted by id, so an admin list does not reshuffle on filesystem whim.
        $this->assertSame(['dark', 'default'], $this->ids($this->repository()));
    }

    public function testItIgnoresADirectoryWithNoManifest(): void
    {
        $this->installTheme('default');
        mkdir($this->base . '/themes/leftovers', 0o775, true);
        file_put_contents($this->base . '/themes/leftovers/theme.css', 'body{}');

        $this->assertSame(['default'], $this->ids($this->repository()));
    }

    public function testItIgnoresAThemeWhoseManifestIsNotReadableJson(): void
    {
        $this->installTheme('default');
        mkdir($this->base . '/themes/broken', 0o775, true);
        file_put_contents($this->base . '/themes/broken/theme.json', '{ this is not json');
        file_put_contents($this->base . '/themes/broken/theme.css', 'body{}');

        $this->assertSame(['default'], $this->ids($this->repository()));
    }

    public function testItIgnoresAThemeWithNoNameToShowAPersonChoosing(): void
    {
        $dir = $this->base . '/themes/nameless';
        mkdir($dir, 0o775, true);
        file_put_contents($dir . '/theme.json', json_encode(['version' => '1.0.0']));
        file_put_contents($dir . '/theme.css', 'body{}');

        $this->assertSame([], $this->ids($this->repository()));
    }

    public function testItIgnoresAThemeWhoseStylesheetIsMissing(): void
    {
        // Listing it would let it be activated, which would leave the site
        // unstyled and the linked file a 404 — a worse outcome than never
        // offering it.
        $this->installTheme('halfcopied', css: null);

        $this->assertSame([], $this->ids($this->repository()));
    }

    public function testAThemeIdThatIsNotASafeSlugIsRefused(): void
    {
        // The id becomes both a path segment and a URL segment, so anything that
        // could climb out of the themes directory must not resolve at all.
        $this->installTheme('default');

        $this->assertNull($this->repository()->find('../default'));
        $this->assertNull($this->repository()->find('..'));
        $this->assertNull($this->repository()->find('Default'));
        $this->assertNull($this->repository()->find('the theme'));
    }

    public function testAMissingThemesDirectoryIsAnEmptyListNotAFailure(): void
    {
        // The state of a fresh install, and of an install whose themes directory
        // a deploy has not created yet. Every public page render asks this
        // question, so it has to be an empty answer rather than an exception.
        $repository = new ThemeRepository($this->base . '/nowhere', $this->base . '/data/theme.json');

        $this->assertSame([], $repository->all());
        $this->assertNull($repository->active());
    }

    /* -------------------------------------------------------------- active -- */

    public function testTheDefaultThemeIsLiveBeforeAnybodyHasChosen(): void
    {
        $this->installTheme('default');
        $this->installTheme('dark');

        $this->assertSame('default', $this->repository()->active()?->id);
    }

    public function testActivatingPersistsAndSurvivesAFreshRepository(): void
    {
        $this->installTheme('default');
        $this->installTheme('dark');

        $this->assertTrue($this->repository()->activate('dark'));

        // A new instance, reading only what was written to disk: the point of
        // persisting is that the next request renders with the chosen theme.
        $this->assertSame('dark', $this->repository()->active()?->id);
        $this->assertFileExists($this->base . '/data/theme.json');
    }

    public function testActivatingCreatesTheDataDirectoryWhenItIsNotThereYet(): void
    {
        $this->installTheme('dark');
        $this->assertDirectoryDoesNotExist($this->base . '/data');

        $this->assertTrue($this->repository()->activate('dark'));
        $this->assertSame('dark', $this->repository()->active()?->id);
    }

    public function testActivatingAnUnknownThemeFailsAndLeavesTheLiveThemeAlone(): void
    {
        $this->installTheme('default');
        $this->installTheme('dark');
        $this->repository()->activate('dark');

        $this->assertFalse($this->repository()->activate('sunset'));
        $this->assertSame('dark', $this->repository()->active()?->id);
    }

    public function testActivatingNeverWritesADanglingReference(): void
    {
        $this->installTheme('default');

        $this->assertFalse($this->repository()->activate('../../etc'));
        $this->assertFileDoesNotExist($this->base . '/data/theme.json');
    }

    public function testAStoredThemeThatHasSinceBeenDeletedFallsBackRatherThanRenderingNothing(): void
    {
        $this->installTheme('default');
        $this->installTheme('dark');
        $this->repository()->activate('dark');

        $this->rrmdir($this->base . '/themes/dark');

        $this->assertSame('default', $this->repository()->active()?->id);
    }

    /* ------------------------------------------------------------- the URL -- */

    public function testTheStylesheetUrlCarriesACacheBustingVersion(): void
    {
        $this->installTheme('default');
        $theme = $this->repository()->find('default');
        $this->assertNotNull($theme);

        $url = $this->repository()->stylesheetUrl($theme);

        $this->assertStringStartsWith('/themes/default/theme.css?v=', $url);
    }

    public function testEditingTheStylesheetChangesTheUrlSoBrowsersFetchItAgain(): void
    {
        $this->installTheme('default');
        $theme = $this->repository()->find('default');
        $this->assertNotNull($theme);

        $before = $this->repository()->stylesheetUrl($theme);

        // The case the backlog names: a designer edits the CSS in place and
        // never touches the version number. mtime is what has to move the URL.
        $path = $this->base . '/themes/default/theme.css';
        file_put_contents($path, 'body{color:red}');
        touch($path, time() + 60);
        clearstatcache(true, $path);

        $this->assertNotSame($before, $this->repository()->stylesheetUrl($theme));
    }

    public function testTheUrlHonoursAManifestThatNamesAnotherStylesheet(): void
    {
        $this->installTheme('bespoke', ['stylesheet' => 'site.css']);
        $theme = $this->repository()->find('bespoke');
        $this->assertNotNull($theme);

        $this->assertStringStartsWith('/themes/bespoke/site.css?v=', $this->repository()->stylesheetUrl($theme));
    }

    public function testTheUrlPrefixFollowsHowTheSiteServesTheThemesDirectory(): void
    {
        // How the files get in front of a browser — an alias, a symlink, a PHP
        // passthrough — is a deployment decision, so the prefix is not baked in.
        $this->installTheme('default');
        $repository = new ThemeRepository(
            $this->base . '/themes',
            $this->base . '/data/theme.json',
            '/assets/themes'
        );
        $theme = $repository->find('default');
        $this->assertNotNull($theme);

        $this->assertStringStartsWith('/assets/themes/default/theme.css?v=', $repository->stylesheetUrl($theme));
    }

    /* ------------------------------------------------------------- helpers -- */

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
