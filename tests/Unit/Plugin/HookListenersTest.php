<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * The cheap question that makes the content events affordable.
 *
 * `content.saved` and friends fire on every write a site ever performs, and most
 * sites will listen to none of them. Asking "would this reach anybody?" from
 * metadata already in memory is what keeps the cost of *having* those events at
 * an array lookup rather than a payload built and discarded. If this ever
 * answered by loading bootstraps it would be doing exactly the work it exists to
 * avoid, so the test asserts on the absence of that work too.
 */
final class HookListenersTest extends TestCase
{
    private static string $root = '';

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/click-cms-listeners-' . bin2hex(random_bytes(6));

        // A plugin whose bootstrap explodes on load. Nothing in this test may
        // ever load it, which is how "no file is read" is asserted rather than
        // asserted-about.
        self::plugin('Indexer', 'indexer', ['content.saved', 'content.deleted']);
        self::plugin('Nosy', 'nosy', ['api.routes']);
    }

    public static function tearDownAfterClass(): void
    {
        self::removeTree(self::$root);
    }

    /** @param list<string> $hooks */
    private static function plugin(string $name, string $id, array $hooks): void
    {
        $dir = self::$root . '/plugins/' . $id;
        mkdir($dir, 0o775, true);

        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => $name,
            'description' => 'fixture',
            'version' => '1.0.0',
            'author' => 'test',
            'dependencies' => [],
            'hooks' => $hooks,
        ]));

        file_put_contents(
            $dir . '/bootstrap.php',
            "<?php\nthrow new \\RuntimeException('this bootstrap must never be loaded');\n"
        );
    }

    private function manager(bool $activate = true): PluginManager
    {
        $manager = new PluginManager(
            self::$root . '/plugins',
            self::$root . '/data-' . bin2hex(random_bytes(4))
        );

        foreach ($manager->discover() as $plugin) {
            if ($activate) {
                // `activate()` loads the bootstrap, which throws by design here;
                // the plugin is still marked active, which is all this test needs.
                try {
                    $manager->activate($plugin->id);
                } catch (\Throwable) {
                    $manager->activate($plugin->id);
                }
            }
        }

        return $manager;
    }

    public function testADeclaredHookHasAListener(): void
    {
        $manager = $this->manager();

        $this->assertTrue($manager->hasHookListeners('content.saved'));
        $this->assertTrue($manager->hasHookListeners('content.deleted'));
        $this->assertTrue($manager->hasHookListeners('api.routes'));
    }

    public function testAnUndeclaredHookHasNone(): void
    {
        $this->assertFalse($this->manager()->hasHookListeners('content.before_save'));
        $this->assertFalse($this->manager()->hasHookListeners('content.unpublished'));
    }

    public function testTheUnderscoreFormMatchesTheDottedDeclaration(): void
    {
        // `dispatch()` normalises `_` to `.` when matching a declaration, so this
        // must agree with it — otherwise a hook would be dispatched to a plugin
        // the listener check said was not there, or the reverse.
        $this->assertTrue($this->manager()->hasHookListeners('content_saved'));
    }

    public function testAnInactivePluginIsNotAListener(): void
    {
        // Nothing is activated, so nothing listens. A deactivated indexer must
        // stop costing the write path anything at all.
        $this->assertFalse($this->manager(activate: false)->hasHookListeners('content.saved'));
    }

    public function testDeactivatingAPluginWithdrawsItsListeners(): void
    {
        $manager = $this->manager();
        $this->assertTrue($manager->hasHookListeners('content.saved'));

        foreach ($manager->all() as $plugin) {
            if ($plugin->name === 'Indexer') {
                $manager->deactivate($plugin->id);
            }
        }

        // The answer is memoised, so this is really a test that the memo is
        // cleared when the active set changes. A stale "true" would only waste
        // work; a stale "false" would silently stop firing a plugin's events.
        $this->assertFalse($manager->hasHookListeners('content.saved'));
    }

    public function testTheAnswerCostsNoBootstrapLoad(): void
    {
        // Every fixture bootstrap throws on load. Asking the question a thousand
        // times must therefore stay silent — which is the performance claim
        // stated as a behaviour rather than a benchmark.
        $manager = $this->manager();

        for ($i = 0; $i < 1000; $i++) {
            $manager->hasHookListeners('content.saved');
            $manager->hasHookListeners('content.before_save');
        }

        $this->assertTrue($manager->hasHookListeners('content.saved'));
        $this->assertFalse($manager->hasHookListeners('content.before_save'));
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
