<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Http\SectionRenderer;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use PHPUnit\Framework\TestCase;

/**
 * An uploaded file in a section.
 *
 * `File` had no case in the renderer at all, so it fell through to the scalar
 * path and printed its own stored reference as a sentence: a page showed
 * `clip-4f2a91c0` where a video belonged. Meanwhile the media library has
 * accepted MP4 and WebM since video uploads were added, which meant an editor
 * could put a film in the library and had no way to get it onto a page.
 */
final class FileFieldTest extends TestCase
{
    private string $dir;
    private MediaService $media;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/click-cms-file-field-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->media = new MediaService($this->dir);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->dir);
    }

    /** A real upload through the real path, so the type is detected not asserted. */
    private function storeVideo(): string
    {
        $tmp = $this->dir . '/clip.mp4';
        file_put_contents(
            $tmp,
            "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41" . str_repeat("\x00", 512)
        );
        $result = $this->media->store([
            'name' => 'clip.mp4', 'type' => 'video/mp4', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp),
        ]);
        $this->assertNotNull($result['item'], (string) ($result['error'] ?? ''));

        return $result['item']->id;
    }

    private function storePoster(): string
    {
        $tmp = $this->dir . '/still.png';
        $im = imagecreatetruecolor(960, 540);
        imagepng($im, $tmp);
        $result = $this->media->store([
            'name' => 'still.png', 'type' => 'image/png', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp),
        ]);
        $this->assertNotNull($result['item']);

        return $result['item']->id;
    }

    /** @param array<string, mixed> $values */
    private function render(array $values): string
    {
        $renderer = new SectionRenderer(
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections'),
            $this->media,
        );

        return $renderer->render(Content::create(
            ContentKey::page('p'),
            ['title' => 'P', 'sections' => [['type' => 'video', 'values' => $values]]]
        ));
    }

    public function testAVideoBecomesAPlayerAndNotItsOwnReference(): void
    {
        $id = $this->storeVideo();

        $html = $this->render(['video' => $id, 'caption' => 'Filmed on a Tuesday.']);

        $this->assertStringContainsString('<video', $html);
        $this->assertStringContainsString('<source src="/api/media/file/' . $id . '.mp4" type="video/mp4">', $html);
        // The defect: the reference printed as prose.
        $this->assertStringNotContainsString('<p class="cms-field cms-field--video">', $html);
    }

    /**
     * Defaults chosen for the reader. Nothing is fetched until they ask, and
     * nothing starts by itself — the only autoplay browsers permit is a muted
     * one, and a muted film starting under someone's cursor is why people mute
     * the tab.
     */
    public function testThePlayerAsksBeforeItDoesAnything(): void
    {
        $html = $this->render(['video' => $this->storeVideo()]);

        $this->assertStringContainsString('controls', $html);
        $this->assertStringContainsString('preload="none"', $html);
        $this->assertStringContainsString('playsinline', $html);
        $this->assertStringNotContainsString('autoplay', $html);
    }

    public function testAStillFrameBecomesThePosterAndIsNotAlsoPrinted(): void
    {
        $video = $this->storeVideo();
        $poster = $this->storePoster();

        $html = $this->render(['video' => $video, 'poster' => $poster]);

        $this->assertStringContainsString('poster="/api/media/file/' . $poster . '.png"', $html);
        // Consumed, so the still does not appear a second time as its own image.
        $this->assertStringNotContainsString('<img', $html);
    }

    /** A browser that will not play it at all gets the one useful thing. */
    public function testThereIsADownloadForABrowserThatCannotPlayIt(): void
    {
        $html = $this->render(['video' => $this->storeVideo()]);

        $this->assertStringContainsString('Download the video</a>', $html);
    }

    /**
     * A reference to a file that has been deleted from the library. A dead
     * `<video>` would show a broken player; a link at least names what was meant
     * to be there.
     */
    public function testADeletedFileDoesNotRenderABrokenPlayer(): void
    {
        $html = $this->render(['video' => 'gone-00000000']);

        $this->assertStringNotContainsString('<video', $html);
        $this->assertStringContainsString('href="/api/media/file/gone-00000000"', $html);
    }

    public function testANonVideoFileIsADownloadNamedByWhatWasUploaded(): void
    {
        $tmp = $this->dir . '/prices.png';
        $im = imagecreatetruecolor(10, 10);
        imagepng($im, $tmp);
        $stored = $this->media->store([
            'name' => 'price-list.png', 'type' => 'image/png', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp),
        ]);

        $html = $this->render(['video' => $stored['item']->id]);

        $this->assertStringNotContainsString('<video', $html);
        // The name the editor recognises, not the stored id.
        $this->assertStringContainsString('price-list.png</a>', $html);
        $this->assertStringContainsString('download', $html);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
