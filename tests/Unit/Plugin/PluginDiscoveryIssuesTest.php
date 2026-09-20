<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Discovery used to skip broken plugin folders in silence. An invalid
 * plugin.json or a bootstrap with no manifest left the Plugins page looking
 * healthy while the folder did nothing. These pin that discover() records why.
 */
final class PluginDiscoveryIssuesTest extends TestCase
{
    private string $base;
    private PluginManager $manager;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-pdisc-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/plugins', 0o755, true);
        mkdir($this->base . '/data', 0o755, true);
        $this->manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    public function testAValidPluginProducesNoIssues(): void
    {
        $this->writePlugin('ok', ['name' => 'Ok Plugin', 'version' => '1.0.0']);

        $found = $this->manager->discover();

        $this->assertCount(1, $found);
        $this->assertSame([], $this->manager->discoveryIssues());
    }

    public function testInvalidJsonIsReported(): void
    {
        mkdir($this->base . '/plugins/broken', 0o755, true);
        file_put_contents($this->base . '/plugins/broken/plugin.json', '{ not json');

        $this->manager->discover();

        $issues = $this->manager->discoveryIssues();
        $this->assertCount(1, $issues);
        $this->assertSame('broken', $issues[0]['directory']);
        $this->assertStringContainsString('not valid JSON', $issues[0]['reason']);
    }

    public function testBootstrapWithoutManifestIsReported(): void
    {
        mkdir($this->base . '/plugins/orphan', 0o755, true);
        file_put_contents($this->base . '/plugins/orphan/bootstrap.php', "<?php\n");

        $this->manager->discover();

        $issues = $this->manager->discoveryIssues();
        $this->assertCount(1, $issues);
        $this->assertSame('orphan', $issues[0]['directory']);
        $this->assertStringContainsString('no plugin.json', $issues[0]['reason']);
    }

    public function testMissingNameIsReported(): void
    {
        $this->writePlugin('noname', ['version' => '1.0.0', 'description' => 'oops']);

        $this->manager->discover();

        $issues = $this->manager->discoveryIssues();
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('missing a name', $issues[0]['reason']);
    }

    public function testAnEmptyFolderIsIgnoredNotReported(): void
    {
        mkdir($this->base . '/plugins/empty', 0o755, true);

        $this->manager->discover();

        $this->assertSame([], $this->manager->discoveryIssues());
    }

    /** @param array<string, mixed> $manifest */
    private function writePlugin(string $folder, array $manifest): void
    {
        $dir = $this->base . '/plugins/' . $folder;
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/plugin.json', json_encode($manifest));
        file_put_contents($dir . '/bootstrap.php', "<?php\n");
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
