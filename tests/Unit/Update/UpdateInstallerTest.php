<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Application\Update\UpdateInstaller;
use Click\Cms\Domain\Update\Release;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Applying a release replaces the running application's own code, so what is
 * pinned here is not "does it work" but "what happens when it goes wrong":
 * a wrong checksum changes nothing, a package that reaches for the site's
 * content is refused, and a failure part-way leaves the install as it was.
 */
final class UpdateInstallerTest extends TestCase
{
    private string $base;
    private string $packages;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-update-' . bin2hex(random_bytes(6));
        $this->packages = $this->base . '-pkg';
        mkdir($this->base, 0o755, true);
        mkdir($this->packages, 0o755, true);

        // A minimal live installation: code that an update replaces, and site
        // data that it must not.
        mkdir($this->base . '/src', 0o755, true);
        file_put_contents($this->base . '/src/App.php', '<?php // version 1');
        mkdir($this->base . '/content/page/en', 0o755, true);
        file_put_contents($this->base . '/content/page/en/home.json', '{"title":"My page"}');
        mkdir($this->base . '/data', 0o755, true);
        file_put_contents($this->base . '/data/settings.json', '{"siteName":"Mine"}');
        mkdir($this->base . '/config', 0o755, true);
        file_put_contents($this->base . '/config/core.json', '{"core":{}}');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
        $this->rrmdir($this->packages);
    }

    /** @param array<string,string> $entries */
    private function makePackage(string $name, array $entries): string
    {
        $path = "$this->packages/$name";
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }
        $zip->close();

        return $path;
    }

    private function releaseFor(string $package, ?string $sha = null): Release
    {
        $release = Release::fromArray([
            'version' => '1.5.0',
            'packageUrl' => 'https://example.com/click-cms-1.5.0.zip',
            'sha256' => $sha ?? hash_file('sha256', $package),
        ]);
        $this->assertNotNull($release);

        return $release;
    }

    private function installer(): UpdateInstaller
    {
        return new UpdateInstaller($this->base, $this->base . '/data/updates');
    }

    /* ------------------------------------------------------------ happy path -- */

    public function testInstallsAReleaseAndKeepsABackup(): void
    {
        $pkg = $this->makePackage('ok.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php // version 2',
            'click-cms-1.5.0/src/New.php' => '<?php // added',
        ]);

        $result = $this->installer()->install($this->releaseFor($pkg), $pkg);

        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertSame('<?php // version 2', file_get_contents($this->base . '/src/App.php'));
        $this->assertFileExists($this->base . '/src/New.php');
        // The replaced code is kept, so there is a way back.
        $this->assertFileExists($result['backup'] . '/src/App.php');
        $this->assertSame('<?php // version 1', file_get_contents($result['backup'] . '/src/App.php'));
    }

    public function testAnInstalledUpdateCanBeRestored(): void
    {
        $pkg = $this->makePackage('ok.zip', ['click-cms-1.5.0/src/App.php' => '<?php // version 2']);
        $installer = $this->installer();

        $result = $installer->install($this->releaseFor($pkg), $pkg);
        $this->assertTrue($result['success']);

        $restored = $installer->restore((string) $result['backup']);

        $this->assertTrue($restored['success'], (string) $restored['error']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
    }

    /* ------------------------------------------------------------- refusals -- */

    /** The most important test here: a bad checksum must change nothing at all. */
    public function testAChecksumMismatchInstallsNothing(): void
    {
        $pkg = $this->makePackage('bad.zip', ['click-cms-1.5.0/src/App.php' => '<?php // tampered']);
        $wrong = str_repeat('0', 64);

        $result = $this->installer()->install($this->releaseFor($pkg, $wrong), $pkg);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('checksum', (string) $result['error']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'), 'nothing may change');
    }

    /**
     * A release package has no business writing the site's pages, uploads or
     * settings. Those entries are dropped rather than the update being refused,
     * so a package that merely ships an example file still installs.
     */
    public function testThePackageCannotOverwriteContentDataOrConfig(): void
    {
        $pkg = $this->makePackage('greedy.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php // version 2',
            'click-cms-1.5.0/content/page/en/home.json' => '{"title":"REPLACED"}',
            'click-cms-1.5.0/data/settings.json' => '{"siteName":"REPLACED"}',
            'click-cms-1.5.0/config/core.json' => '{"core":"REPLACED"}',
        ]);

        $result = $this->installer()->install($this->releaseFor($pkg), $pkg);

        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertSame('{"title":"My page"}', file_get_contents($this->base . '/content/page/en/home.json'));
        $this->assertSame('{"siteName":"Mine"}', file_get_contents($this->base . '/data/settings.json'));
        $this->assertSame('{"core":{}}', file_get_contents($this->base . '/config/core.json'));
    }

    public function testAPackageThatEscapesItsDirectoryIsRefused(): void
    {
        $sentinel = sys_get_temp_dir() . '/click-cms-UPDATE-ESCAPE';
        @unlink($sentinel);

        $pkg = $this->makePackage('evil.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php // version 2',
            '../../../../../../../../tmp/click-cms-UPDATE-ESCAPE' => 'pwned',
        ]);

        $result = $this->installer()->install($this->releaseFor($pkg), $pkg);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unsafe path', (string) $result['error']);
        $this->assertFileDoesNotExist($sentinel);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
    }

    public function testAnAbsolutePathEntryIsRefused(): void
    {
        $pkg = $this->makePackage('abs.zip', [
            'click-cms-1.5.0/src/App.php' => '<?php',
            '/etc/click-cms-should-never-exist' => 'x',
        ]);

        $result = $this->installer()->install($this->releaseFor($pkg), $pkg);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unsafe path', (string) $result['error']);
    }

    public function testAPackageThatShipsNoKnownDirectoryChangesNothing(): void
    {
        $pkg = $this->makePackage('empty.zip', ['click-cms-1.5.0/README.md' => '# notes']);

        $result = $this->installer()->install($this->releaseFor($pkg), $pkg);

        $this->assertTrue($result['success']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
    }

    public function testAnUnopenablePackageIsReportedNotIgnored(): void
    {
        $notAZip = "$this->packages/broken.zip";
        file_put_contents($notAZip, 'this is not a zip file');

        $result = $this->installer()->install($this->releaseFor($notAZip), $notAZip);

        $this->assertFalse($result['success']);
        $this->assertSame('<?php // version 1', file_get_contents($this->base . '/src/App.php'));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
