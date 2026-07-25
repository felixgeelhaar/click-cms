<?php

declare(strict_types=1);

namespace ClickCms\Tools\Docs;

/**
 * Reads an image's intrinsic size out of its header.
 *
 * A screenshot without `width` and `height` on its `<img>` reflows the page the
 * moment it decodes: the reader loses their place mid-paragraph, which on a
 * documentation page is the difference between reading and re-finding. The
 * browser only needs the numbers, and every format below states them in its
 * first few bytes.
 *
 * Hand-rolled rather than `getimagesize()`, and not because the function is
 * unavailable: it reads the whole file through a much larger surface, and this
 * is a build step over a handful of screenshots that needs eight bytes out of a
 * header it can name. The formats are the ones a screenshot actually arrives in.
 * Anything else — SVG, WebP, AVIF, a truncated file — returns null, and the
 * `<img>` goes out without dimensions.
 */
final class ImageDimensions
{
    /** @return array{width: int, height: int}|null */
    public static function read(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $head = (string) fread($handle, 24);

            if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
                return self::png($head);
            }
            if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
                return self::gif($head);
            }
            if (str_starts_with($head, "\xff\xd8")) {
                return self::jpeg($handle);
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The IHDR chunk is mandatory and must come first: an 8-byte signature, a
     * 4-byte length, the type, then width and height as big-endian 32-bit ints.
     *
     * @return array{width: int, height: int}|null
     */
    private static function png(string $head): ?array
    {
        if (strlen($head) < 24 || substr($head, 12, 4) !== 'IHDR') {
            return null;
        }

        /** @var array{width: int, height: int} $fields */
        $fields = unpack('Nwidth/Nheight', substr($head, 16, 8));

        return self::sane($fields);
    }

    /** @return array{width: int, height: int}|null */
    private static function gif(string $head): ?array
    {
        if (strlen($head) < 10) {
            return null;
        }

        /** @var array{width: int, height: int} $fields */
        $fields = unpack('vwidth/vheight', substr($head, 6, 4));

        return self::sane($fields);
    }

    /**
     * JPEG states its size in a frame header, which sits behind an arbitrary
     * number of other segments (JFIF, EXIF, quantisation tables), so the only
     * way to it is to walk the segment chain.
     *
     * @param resource $handle
     * @return array{width: int, height: int}|null
     */
    private static function jpeg($handle): ?array
    {
        fseek($handle, 2);

        while (true) {
            $byte = fread($handle, 1);
            if ($byte === false || $byte === '') {
                return null;
            }
            // Segments are introduced by 0xFF; anything else is padding to skip.
            if ($byte !== "\xff") {
                continue;
            }

            do {
                $marker = fread($handle, 1);
            } while ($marker === "\xff");
            if ($marker === false || $marker === '') {
                return null;
            }

            $code = ord($marker);
            // Standalone markers carry no length field: restart markers, SOI, TEM.
            if ($code === 0x01 || $code === 0xd8 || ($code >= 0xd0 && $code <= 0xd7)) {
                continue;
            }

            $lengthBytes = (string) fread($handle, 2);
            if (strlen($lengthBytes) < 2) {
                return null;
            }
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('n', $lengthBytes);
            $length = $unpacked[1];
            if ($length < 2) {
                return null;
            }

            if (self::isFrameHeader($code)) {
                $data = (string) fread($handle, 5);
                if (strlen($data) < 5) {
                    return null;
                }
                /** @var array{width: int, height: int} $fields */
                $fields = unpack('Cprecision/nheight/nwidth', $data);

                return self::sane($fields);
            }

            if (fseek($handle, $length - 2, SEEK_CUR) !== 0) {
                return null;
            }
        }
    }

    /**
     * SOF0–SOF15, which is every frame header there is, minus the three markers
     * that share the range but describe tables rather than a frame.
     */
    private static function isFrameHeader(int $code): bool
    {
        return $code >= 0xc0
            && $code <= 0xcf
            && $code !== 0xc4   // Huffman table
            && $code !== 0xc8   // JPEG extensions
            && $code !== 0xcc;  // arithmetic coding conditioning
    }

    /**
     * @param array{width: int, height: int} $fields
     * @return array{width: int, height: int}|null
     */
    private static function sane(array $fields): ?array
    {
        if ($fields['width'] < 1 || $fields['height'] < 1) {
            return null;
        }

        return ['width' => $fields['width'], 'height' => $fields['height']];
    }
}
