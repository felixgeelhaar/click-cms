<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Docs;

use ClickCms\Tools\Docs\DocumentDefect;
use ClickCms\Tools\Docs\ImageLibrary;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/scripts/docs/bootstrap.php';

/**
 * The library decides the one question the renderer cannot answer for itself:
 * is this image a file in the repository, which the site must copy and show, or
 * something remote, which the site must never fetch?
 *
 * Getting that wrong in either direction is a real failure. A remote badge
 * treated as local puts a third-party host in the critical path of a page that
 * is otherwise entirely self-contained; a local screenshot treated as remote
 * publishes a link where a picture should be.
 */
final class ImageLibraryTest extends TestCase
{
    private string $repository;

    protected function setUp(): void
    {
        $this->repository = sys_get_temp_dir() . '/click-cms-images-' . bin2hex(random_bytes(6));
        mkdir($this->repository . '/docs/images', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->repository);
    }

    // ------------------------------------------------------------------
    // Local and remote
    // ------------------------------------------------------------------

    public function testALocalImageResolvesToACopyBelowTheSiteRoot(): void
    {
        $this->png('docs/images/dashboard.png', 800, 600);
        $library = new ImageLibrary($this->repository);

        $asset = $library->resolve('images/dashboard.png', 'docs', 1);

        $this->assertNotNull($asset);
        $this->assertSame('../assets/docs/images/dashboard.png', $asset->src);
        $this->assertSame(
            ['assets/docs/images/dashboard.png' => $this->repository . '/docs/images/dashboard.png'],
            $library->assets(),
        );
    }

    public function testTheSourceIsRelativeToTheDocumentThatReferencesIt(): void
    {
        $this->png('docs/images/dashboard.png', 10, 10);
        $library = new ImageLibrary($this->repository);

        // The README sits at the site root, so no `../` at all.
        $asset = $library->resolve('docs/images/dashboard.png', '', 0);

        $this->assertNotNull($asset);
        $this->assertSame('assets/docs/images/dashboard.png', $asset->src);
    }

    public function testTheSameImageIsCopiedOnceHoweverOftenItIsUsed(): void
    {
        $this->png('docs/images/dashboard.png', 10, 10);
        $library = new ImageLibrary($this->repository);

        $library->resolve('images/dashboard.png', 'docs', 1);
        $library->resolve('docs/images/dashboard.png', '', 0);

        $this->assertCount(1, $library->assets());
    }

    /** @return array<string, array{0: string}> */
    public static function remoteSources(): array
    {
        return [
            'https' => ['https://img.shields.io/badge/PHP-8.1+-777BB4'],
            'http' => ['http://example.test/a.png'],
            'protocol relative' => ['//example.test/a.png'],
            'data uri' => ['data:image/png;base64,iVBORw0KGgo='],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('remoteSources')]
    public function testARemoteImageIsNeverLocalAndIsNeverCopied(string $destination): void
    {
        $library = new ImageLibrary($this->repository);

        $this->assertNull($library->resolve($destination, 'docs', 1));
        $this->assertSame([], $library->assets());
    }

    public function testAPathThatClimbsOutOfTheRepositoryIsNotLocal(): void
    {
        $library = new ImageLibrary($this->repository);

        $this->assertNull($library->resolve('../../../etc/hosts', 'docs', 1));
        $this->assertSame([], $library->assets());
    }

    /**
     * A reference to a picture that is not there is a hole in the page. The
     * build says so rather than publishing a broken image.
     */
    public function testAMissingLocalImageFailsTheBuild(): void
    {
        $library = new ImageLibrary($this->repository);

        $this->expectException(DocumentDefect::class);
        $this->expectExceptionMessageMatches('#docs/images/absent\.png#');
        $library->resolve('images/absent.png', 'docs', 1);
    }

    public function testAssetsAreSortedSoTheBuildIsDeterministic(): void
    {
        $this->png('docs/images/zebra.png', 10, 10);
        $this->png('docs/images/aardvark.png', 10, 10);
        $library = new ImageLibrary($this->repository);

        $library->resolve('images/zebra.png', 'docs', 1);
        $library->resolve('images/aardvark.png', 'docs', 1);

        $this->assertSame(
            ['assets/docs/images/aardvark.png', 'assets/docs/images/zebra.png'],
            array_keys($library->assets()),
        );
    }

    // ------------------------------------------------------------------
    // Intrinsic dimensions
    // ------------------------------------------------------------------

    public function testPngDimensionsAreReadFromTheHeader(): void
    {
        $this->png('docs/images/wide.png', 1280, 742);

        $asset = (new ImageLibrary($this->repository))->resolve('images/wide.png', 'docs', 1);

        $this->assertNotNull($asset);
        $this->assertSame(1280, $asset->width);
        $this->assertSame(742, $asset->height);
    }

    public function testGifDimensionsAreReadFromTheHeader(): void
    {
        $header = 'GIF89a' . pack('vv', 320, 200) . "\x00\x00\x00";
        file_put_contents($this->repository . '/docs/images/a.gif', $header);

        $asset = (new ImageLibrary($this->repository))->resolve('images/a.gif', 'docs', 1);

        $this->assertNotNull($asset);
        $this->assertSame(320, $asset->width);
        $this->assertSame(200, $asset->height);
    }

    public function testJpegDimensionsAreReadFromTheFrameHeader(): void
    {
        // SOI, a comment segment to be walked past, then SOF0.
        $jpeg = "\xff\xd8"
            . "\xff\xfe" . pack('n', 7) . 'hello'
            . "\xff\xc0" . pack('n', 17) . "\x08" . pack('nn', 480, 640) . str_repeat("\x00", 10);
        file_put_contents($this->repository . '/docs/images/a.jpg', $jpeg);

        $asset = (new ImageLibrary($this->repository))->resolve('images/a.jpg', 'docs', 1);

        $this->assertNotNull($asset);
        $this->assertSame(640, $asset->width);
        $this->assertSame(480, $asset->height);
    }

    /** Guessing at a size is worse than leaving it out: a wrong box reflows too. */
    public function testAFormatWhoseDimensionsCannotBeReadOmitsThemRatherThanGuessing(): void
    {
        file_put_contents(
            $this->repository . '/docs/images/diagram.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        );

        $asset = (new ImageLibrary($this->repository))->resolve('images/diagram.svg', 'docs', 1);

        $this->assertNotNull($asset);
        $this->assertNull($asset->width);
        $this->assertNull($asset->height);
    }

    public function testATruncatedPngIsTreatedAsUnreadableRatherThanCrashing(): void
    {
        file_put_contents($this->repository . '/docs/images/broken.png', "\x89PNG\r\n\x1a\n\x00\x00");

        $asset = (new ImageLibrary($this->repository))->resolve('images/broken.png', 'docs', 1);

        $this->assertNotNull($asset);
        $this->assertNull($asset->width);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** A real, decodable PNG of the given size — greyscale, one flat colour. */
    private function png(string $relativePath, int $width, int $height): void
    {
        file_put_contents($this->repository . '/' . $relativePath, self::pngBytes($width, $height));
    }

    public static function pngBytes(int $width, int $height): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $rows = '';
        for ($y = 0; $y < $height; $y++) {
            $rows .= "\x00" . str_repeat("\xd8", $width);
        }

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NN', $width, $height) . "\x08\x00\x00\x00\x00")
            . $chunk('IDAT', (string) gzcompress($rows, 9))
            . $chunk('IEND', '');
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
