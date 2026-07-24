<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Theme;

use Click\Cms\Domain\Theme\Theme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A manifest is a file a site writes by hand or copies in, so every malformed
 * shape here is an ordinary condition rather than an exceptional one. These pin
 * that parsing answers "no" instead of throwing — a theme that cannot be
 * installed must drop out of the list, never take the screen listing it down —
 * and that the two strings which end up in a path and a URL are held to a form
 * that cannot escape either.
 */
final class ThemeTest extends TestCase
{
    public function testItReadsWhatAThemeDeclares(): void
    {
        $theme = Theme::fromArray([
            'name' => 'Dark',
            'version' => '2.1.0',
            'description' => 'A dark ground.',
            'author' => 'Click CMS',
        ], 'dark');

        $this->assertNotNull($theme);
        $this->assertSame('dark', $theme->id);
        $this->assertSame('Dark', $theme->name);
        $this->assertSame('2.1.0', $theme->version);
        $this->assertSame('A dark ground.', $theme->description);
        $this->assertSame('Click CMS', $theme->author);
    }

    public function testAThemeWithNoNameIsRefused(): void
    {
        // Without a name there is nothing to show the person choosing between
        // themes, which makes the theme unusable rather than merely incomplete.
        $this->assertNull(Theme::fromArray(['version' => '1.0.0'], 'dark'));
        $this->assertNull(Theme::fromArray(['name' => '   '], 'dark'));
    }

    #[DataProvider('unsafeIds')]
    public function testAnIdThatIsNotASafeSlugIsRefused(string $id): void
    {
        $this->assertNull(Theme::fromArray(['name' => 'Dark'], $id));
    }

    /** @return array<string, array{string}> */
    public static function unsafeIds(): array
    {
        return [
            'parent directory' => ['..'],
            'traversal' => ['../secrets'],
            'a path' => ['themes/dark'],
            'a leading hyphen' => ['-dark'],
            'a dot' => ['dark.theme'],
            'a space' => ['dark theme'],
            'empty' => [''],
            'an embedded newline' => ["dark\ncrash"],
        ];
    }

    public function testTheStylesheetDefaultsToThemeCssSoTheCommonCaseNeedsNoEntry(): void
    {
        $theme = Theme::fromArray(['name' => 'Dark'], 'dark');

        $this->assertNotNull($theme);
        $this->assertSame('theme.css', $theme->stylesheet());
    }

    public function testAManifestMayNameItsOwnStylesheet(): void
    {
        $theme = Theme::fromArray(['name' => 'Bespoke', 'stylesheet' => 'site.css'], 'bespoke');

        $this->assertNotNull($theme);
        $this->assertSame('site.css', $theme->stylesheet());
    }

    #[DataProvider('unsafeStylesheets')]
    public function testAStylesheetThatIsNotAPlainCssFilenameIsRefused(string $stylesheet): void
    {
        // Refused outright rather than falling back to theme.css: the manifest
        // asked for something the CMS will not serve, and quietly serving
        // something else would hide the mistake behind a design that half works.
        $this->assertNull(Theme::fromArray(['name' => 'Dark', 'stylesheet' => $stylesheet], 'dark'));
    }

    /** @return array<string, array{string}> */
    public static function unsafeStylesheets(): array
    {
        return [
            'traversal' => ['../../public/index.php'],
            'a subdirectory' => ['css/theme.css'],
            'not css' => ['theme.php'],
            'an absolute path' => ['/etc/passwd'],
        ];
    }

    public function testExtraAssetsAreKeptOnlyWhenTheyArePlainFilenames(): void
    {
        // An unusable extra asset costs a background image; an unusable
        // stylesheet costs the design. Only the second is worth refusing over,
        // so the bad entries are dropped and the theme still installs.
        $theme = Theme::fromArray([
            'name' => 'Dark',
            'assets' => ['logo.svg', '../../.env', 'fonts/inter.woff2', 'grain.png', 42],
        ], 'dark');

        $this->assertNotNull($theme);
        $this->assertSame(['logo.svg', 'grain.png'], $theme->assets);
    }

    public function testAMissingVersionReadsAsAbsentRatherThanAsOneSomebodyChose(): void
    {
        $theme = Theme::fromArray(['name' => 'Dark'], 'dark');

        $this->assertNotNull($theme);
        $this->assertSame('', $theme->version);
    }

    public function testItSerialisesForTheApi(): void
    {
        $theme = Theme::fromArray([
            'name' => 'Dark',
            'version' => '1.0.0',
            'description' => 'A dark ground.',
            'author' => 'Click CMS',
            'assets' => ['grain.png'],
        ], 'dark');

        $this->assertNotNull($theme);
        $this->assertSame([
            'id' => 'dark',
            'name' => 'Dark',
            'version' => '1.0.0',
            'description' => 'A dark ground.',
            'author' => 'Click CMS',
            'stylesheet' => 'theme.css',
            'assets' => ['grain.png'],
        ], $theme->toArray());
    }
}
