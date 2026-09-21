<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Theme;

use Click\Cms\Application\Theme\ThemeInstaller;
use Click\Cms\Application\Theme\ThemeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Installing a theme from a ZIP is less dangerous than a plugin (CSS only, no
 * PHP), but the archive is still treated as hostile until proven otherwise.
 * These mirror the marketplace Zip-Slip suite: a valid package lands under
 * themes/, and every escape, missing piece, or collision is refused.
 */
final class ThemeInstallerTest extends TestCase
{
    private string $base;
    private ThemeInstaller $installer;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-theme-install-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/themes', 0o755, true);
        mkdir($this->base . '/data/theme-uploads', 0o755, true);

        $this->installer = new ThemeInstaller(
            $this->base . '/themes',
            $this->base . '/data/theme-uploads',
            ThemeRepository::forInstallation($this->base),
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
        @unlink(sys_get_temp_dir() . '/click-cms-THEME-ZIPSLIP-SENTINEL.css');
    }

    /* ----------------------------------------------------------- helpers -- */

    /**
     * @param array<string, string> $entries entry name => contents
     */
    private function makeZip(string $path, array $entries): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
    }

    private function validThemeZip(string $path, string $name = 'Coastal', string $folder = 'coastal'): void
    {
        $this->makeZip($path, [
            "{$folder}/theme.json" => json_encode([
                'name' => $name,
                'version' => '1.0.0',
                'description' => 'A sample theme',
                'author' => 'Click CMS',
            ]),
            "{$folder}/theme.css" => "body { color: #111; }\n",
        ]);
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function uploaded(string $path, string $name = 'theme.zip'): array
    {
        return [
            'name' => $name,
            'type' => 'application/zip',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
        ];
    }

    /* ------------------------------------------------------- local install -- */

    public function testInstallsAValidThemeFromAZip(): void
    {
        $zip = $this->base . '/data/pkg.zip';
        $this->validThemeZip($zip);

        $result = $this->installer->installFromZip($zip);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('coastal', $result['theme']['id']);
        $this->assertFileExists($this->base . '/themes/coastal/theme.json');
        $this->assertFileExists($this->base . '/themes/coastal/theme.css');
    }

    public function testRefusesZipSlipWithDotDot(): void
    {
        $sentinel = sys_get_temp_dir() . '/click-cms-THEME-ZIPSLIP-SENTINEL.css';
        @unlink($sentinel);

        $zip = $this->base . '/data/evil.zip';
        $this->makeZip($zip, [
            'evil/theme.json' => json_encode(['name' => 'Evil', 'version' => '1.0.0']),
            'evil/theme.css' => 'body{}',
            '../../../../../../../../../../../../tmp/click-cms-THEME-ZIPSLIP-SENTINEL.css' => 'pwned',
        ]);

        $result = $this->installer->installFromZip($zip);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unsafe path', $result['error']);
        $this->assertFileDoesNotExist($sentinel);
        $this->assertDirectoryDoesNotExist($this->base . '/themes/evil');
    }

    public function testRefusesAnArchiveWithAnAbsolutePathEntry(): void
    {
        $zip = $this->base . '/data/abs.zip';
        $this->makeZip($zip, [
            'abs/theme.json' => json_encode(['name' => 'Abs', 'version' => '1.0.0']),
            'abs/theme.css' => 'body{}',
            '/etc/click-cms-should-never-write' => 'x',
        ]);

        $result = $this->installer->installFromZip($zip);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unsafe path', $result['error']);
    }

    public function testRefusesAZipWithoutThemeJson(): void
    {
        $zip = $this->base . '/data/nomanifest.zip';
        $this->makeZip($zip, [
            'lonely/theme.css' => 'body{}',
            'lonely/readme.txt' => 'hello',
        ]);

        $result = $this->installer->installFromZip($zip);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('theme.json', $result['error']);
    }

    public function testRefusesAZipWhoseStylesheetIsMissing(): void
    {
        $zip = $this->base . '/data/nocss.zip';
        $this->makeZip($zip, [
            'bare/theme.json' => json_encode(['name' => 'Bare', 'version' => '1.0.0']),
        ]);

        $result = $this->installer->installFromZip($zip);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('stylesheet', $result['error']);
        $this->assertDirectoryDoesNotExist($this->base . '/themes/bare');
    }

    public function testRefusesADuplicateThemeId(): void
    {
        $zip = $this->base . '/data/pkg.zip';
        $this->validThemeZip($zip);
        $this->installer->installFromZip($zip);

        $again = $this->installer->installFromZip($zip);

        $this->assertFalse($again['success']);
        $this->assertStringContainsString('already exists', $again['error']);
    }

    /* -------------------------------------------------------- upload path -- */

    public function testUploadRefusesANonZip(): void
    {
        $path = $this->base . '/data/not-a-zip.txt';
        file_put_contents($path, 'hello');

        $result = $this->installer->upload($this->uploaded($path, 'theme.zip'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not a ZIP', $result['error']);
        $this->assertDirectoryDoesNotExist($this->base . '/themes/coastal');
    }

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
