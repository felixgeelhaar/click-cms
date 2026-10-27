<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Http\PluginsController;
use PHPUnit\Framework\TestCase;

/**
 * Plugin management fault shaping.
 *
 * A missing plugin and a bad id must carry stable {@see \Click\Cms\Http\ApiFault}
 * codes so clients that read `code` get the same answer Themes already do.
 */
final class PluginsControllerTest extends TestCase
{
    private string $base;
    private PluginsController $plugins;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-plugins-ctl-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/plugins', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);

        $manager = new PluginManager($this->base);
        $manager->discover();
        $this->plugins = new PluginsController($manager);
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

    public function testMetadataComesFromTheDiscoveredFolderRatherThanTheGeneratedId(): void
    {
        $dir = $this->base . '/plugins/search';
        mkdir($dir);
        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'Search API', 'version' => '1.0.0', 'description' => 'Search',
            'author' => 'Click', 'dependencies' => [], 'hooks' => [],
            'settings' => ['title' => 'Search preferences', 'endpoint' => '/api/search-api/settings', 'fields' => []],
        ]));
        file_put_contents($dir . '/CHANGELOG.md', '# Search changes');
        file_put_contents($dir . '/signature.txt', 'signature');
        file_put_contents($dir . '/manifest.json', '{"release":"1.0.0"}');
        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $manager->discover();
        $controller = new PluginsController($manager);
        $result = $controller->get('search-api');
        $this->assertSame('Search preferences', $result['data']['settings']['title']);
        $this->assertSame($result['data']['settings'], array_values($controller->list()['data'])[0]['settings']);

        $this->assertSame('# Search changes', $result['data']['changelog']);
        $this->assertSame('signature', $result['data']['signature']);
        $this->assertSame(['release' => '1.0.0'], $result['data']['manifest']);
    }

    public function testAMissingPluginIsNotFound(): void
    {
        $result = $this->plugins->get('does-not-exist');

        $this->assertSame(404, $result['status']);
        $this->assertSame('not_found', $result['code']);
        $this->assertSame('Plugin not found', $result['error']);
    }

    public function testAnInvalidPluginIdIsBadRequest(): void
    {
        $result = $this->plugins->activate('Not A Valid Id!!!');

        $this->assertSame(400, $result['status']);
        $this->assertSame('bad_request', $result['code']);
        $this->assertSame('Invalid plugin ID', $result['error']);
    }
}
