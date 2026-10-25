<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Http\MediaController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaRangeTest extends TestCase
{
    public static function ranges(): array
    {
        return [
            'suffix' => ['bytes=-3', '789', 206],
            'oversized suffix' => ['bytes=-999999999999999999999999', '0123456789', 206],
            'zero suffix' => ['bytes=-0', '', 416],
            'open ended' => ['bytes=7-', '789', 206],
            'bounded' => ['bytes=2-4', '234', 206],
            'past end' => ['bytes=10-', '', 416],
            'huge offset' => ['bytes=999999999999999999999999-', '', 416],
            'malformed' => ['bytes=-', '0123456789', 200],
        ];
    }

    #[DataProvider('ranges')]
    public function testVideoRangeReturnsTheRequestedBytes(string $range, string $expected, int $status): void
    {
        $root = sys_get_temp_dir() . '/click-range-' . bin2hex(random_bytes(6));
        mkdir($root . '/content/media', 0o775, true);
        $path = $root . '/content/media/clip-00000000.mp4';
        file_put_contents($path, '0123456789');
        $previous = $_SERVER['HTTP_RANGE'] ?? null;
        $_SERVER['HTTP_RANGE'] = $range;
        http_response_code(200);
        ob_start();
        try {
            (new MediaController($root, static fn (): array => []))->serveMediaFile('clip-00000000.mp4');
            $body = ob_get_contents();
            self::assertSame($expected, $body);
            self::assertSame($status, http_response_code());
        } finally {
            ob_end_clean();
            http_response_code(200);
            if ($previous === null) {
                unset($_SERVER['HTTP_RANGE']);
            } else {
                $_SERVER['HTTP_RANGE'] = $previous;
            }
            unlink($path);
            rmdir($root . '/content/media');
            rmdir($root . '/content');
            rmdir($root);
        }
    }
}
