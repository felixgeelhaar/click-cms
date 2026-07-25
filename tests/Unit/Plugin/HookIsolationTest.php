<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * One broken plugin must not decide an answer on every other plugin's behalf.
 *
 * This is the difference between the two dispatches, and it only matters for
 * hooks that collect an opinion. If a plugin that throws could abort the loop,
 * a bug in an unrelated extension would silently disarm a publish gate — the
 * gate would report "nobody objected" when in fact nobody was asked, which is
 * exactly the quiet failure this codebase keeps having to remove.
 */
final class HookIsolationTest extends TestCase
{
    /**
     * Built once for the whole class, not once per test. A plugin bootstrap is
     * `require_once`d into the one PHP process, so rebuilding the fixtures per
     * test would try to declare the same class twice from a new path.
     */
    private static string $root = '';

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/click-cms-hooks-' . bin2hex(random_bytes(6));

        // Deliberately named so the throwing plugin sorts first and is therefore
        // reached before the one with an opinion. A fixture where the survivor
        // ran first would pass whatever the implementation did.
        self::plugin('Boomer', 'boomer', 'throw new \RuntimeException("plugin is broken");');
        self::plugin('Steady', 'steady', 'return ["allowed" => false, "reason" => "still here"];');
    }

    public static function tearDownAfterClass(): void
    {
        self::removeTree(self::$root);
    }

    private static function plugin(string $name, string $id, string $body): void
    {
        $dir = self::$root . '/plugins/' . $id;
        mkdir($dir, 0o775, true);

        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => $name,
            'description' => 'fixture',
            'version' => '1.0.0',
            'author' => 'test',
            'dependencies' => [],
            'hooks' => ['content.before_publish'],
        ]));

        file_put_contents($dir . '/bootstrap.php', <<<PHP
        <?php
        class Plugin_{$id} {
            public function __construct(\$manager) {}
            public function activate(): bool { return true; }
            public function hook_content_before_publish(array \$params) { {$body} }
        }
        PHP);
    }

    private function manager(): PluginManager
    {
        // A fresh state file per manager, so activating in one test does not
        // leave the next one looking at a plugin it never activated.
        $manager = new PluginManager(
            self::$root . '/plugins',
            self::$root . '/data-' . bin2hex(random_bytes(4))
        );

        foreach ($manager->discover() as $plugin) {
            $manager->activate($plugin->id);
        }

        return $manager;
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

    public function testAThrowingPluginIsSkippedAndTheRestStillAnswer(): void
    {
        $results = $this->manager()->executeHookIsolated('content.before_publish', []);

        $this->assertArrayNotHasKey('Boomer', $results, 'a plugin that threw has no answer');
        $this->assertSame(
            ['allowed' => false, 'reason' => 'still here'],
            $results['Steady'] ?? null,
            'the plugin after it was still asked'
        );
    }

    public function testTheOrdinaryDispatchStillFailsLoudly(): void
    {
        // Not a regression, a boundary. `api.routes` and `web.render` build the
        // application rather than collect an opinion, and a site quietly missing
        // a plugin's endpoints is worse than one that refuses to boot.
        $this->expectException(\RuntimeException::class);

        $this->manager()->executeHook('content.before_publish', []);
    }
}
