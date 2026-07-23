<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\Plugin\PluginState;
use Click\Cms\Domain\ValueObjects\PluginId;
use PHPUnit\Framework\TestCase;

/**
 * A deactivation must last past a restart, and a new plugin must still work
 * without one.
 *
 * The kernel used to activate every discovered plugin unconditionally at boot,
 * which meant deactivating a plugin was written to disk and then ignored on the
 * next start. These pin the rule that fixes it: `isDeactivated` reads the
 * persisted choice, and only an explicit, stored deactivation keeps a plugin off.
 */
final class PluginActivationPersistenceTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-plugstate-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/plugins/demo', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);
        file_put_contents(
            $this->base . '/plugins/demo/plugin.json',
            json_encode(['name' => 'Demo', 'description' => 'x', 'version' => '1.0.0', 'author' => 'x', 'hooks' => []])
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    private function manager(): PluginManager
    {
        // A fresh manager each time re-reads the state file from disk, which is
        // what a restart does.
        return new PluginManager($this->base . '/plugins', $this->base . '/data');
    }

    /**
     * The boot rule, applied by hand: activate every discovered plugin that is
     * not persisted as deactivated.
     */
    private function boot(PluginManager $manager): void
    {
        foreach ($manager->discover() as $plugin) {
            if (!$manager->isDeactivated($plugin->id)) {
                $manager->activate($plugin->id);
            }
        }
    }

    public function testANewPluginActivatesByDefault(): void
    {
        $manager = $this->manager();
        $this->boot($manager);

        $demo = $manager->get(PluginId::generate('Demo'));
        $this->assertSame(PluginState::ACTIVATED, $demo->state);
    }

    public function testADeactivationSurvivesARestart(): void
    {
        // First boot: it comes up active.
        $first = $this->manager();
        $this->boot($first);
        $id = PluginId::generate('Demo');
        $this->assertSame(PluginState::ACTIVATED, $first->get($id)->state);

        // Turn it off — this persists to the state file.
        $first->deactivate($id);

        // Restart: a fresh manager reads the state file and boot runs again.
        $second = $this->manager();
        $this->boot($second);

        $this->assertTrue($second->isDeactivated($id), 'the deactivation should be remembered');
        $this->assertNotSame(
            PluginState::ACTIVATED,
            $second->get($id)->state,
            'a plugin turned off must not come back on after a restart'
        );
    }

    public function testReactivatingAfterADeactivationSticks(): void
    {
        $first = $this->manager();
        $this->boot($first);
        $id = PluginId::generate('Demo');
        $first->deactivate($id);

        // A fresh manager (restart), then reactivate.
        $second = $this->manager();
        $second->discover();
        $second->activate($id);

        // Another restart: it should be active again and stay so.
        $third = $this->manager();
        $this->boot($third);

        $this->assertFalse($third->isDeactivated($id));
        $this->assertSame(PluginState::ACTIVATED, $third->get($id)->state);
    }
}
