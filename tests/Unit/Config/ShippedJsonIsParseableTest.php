<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every JSON file the project ships is, in fact, JSON.
 *
 * Written after `schemas/visual-builder.schema.json` was found to have never
 * parsed — a `"^\d+\.\d+$"` pattern, where JSON reads `\d` as an invalid escape.
 * It had shipped that way from the beginning. Nothing caught it because nothing
 * loads that file at runtime: it exists to be read by other tools, and a file
 * only other people's tools read is a file this project never opens.
 *
 * The section and collection schemas are loaded at boot and so would fail
 * loudly, but they cost nothing to include and the point is the class of bug,
 * not the one instance of it.
 */
final class ShippedJsonIsParseableTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function shippedJsonFiles(): iterable
    {
        $root = dirname(__DIR__, 3);

        foreach (['schemas', 'config', 'config/sections', 'config/collections'] as $dir) {
            foreach (glob($root . '/' . $dir . '/*.json') ?: [] as $path) {
                yield substr($path, strlen($root) + 1) => [$path];
            }
        }
    }

    #[DataProvider('shippedJsonFiles')]
    public function testItParses(string $path): void
    {
        $raw = file_get_contents($path);
        $this->assertIsString($raw);

        json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // Reaching here without an exception is the assertion; PHPUnit wants one
        // stated so the test is not reported as risky.
        $this->assertTrue(true);
    }
}
