<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Config;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Application\Plugin\PluginMarketplace;
use Click\Cms\Application\Update\UpdateInstaller;
use Click\Cms\Domain\Update\Release;
use Click\Cms\Domain\Update\UpdatePolicy;
use PHPUnit\Framework\TestCase;

final class DeploymentModeTest extends TestCase
{
    public function testDeploymentManagedModeBlocksAllCodeInstallationPaths(): void
    {
        $before = getenv('CLICK_DEPLOYMENT_MANAGED');
        $base = sys_get_temp_dir() . '/click-managed-' . bin2hex(random_bytes(6));
        mkdir($base . '/data/marketplace', 0700, true);
        mkdir($base . '/plugins', 0700);
        putenv('CLICK_DEPLOYMENT_MANAGED=1');
        try {
            $config = CoreConfig::fromArray(['core' => ['marketplace' => ['enabled' => true], 'updates' => ['policy' => 'all']]]);
            self::assertFalse($config->marketplaceEnabled());
            self::assertSame(UpdatePolicy::Manual, $config->updatePolicy());
            $market = new PluginMarketplace(new PluginManager($base . '/plugins', $base . '/data'), $base);
            $result = $market->installFromZip($base . '/absent.zip');
            self::assertFalse($result['success']);
            self::assertStringContainsString('deployment', $result['error']);
            $result = $market->installFromRegistry('https://invalid.example', '', 'demo');
            self::assertStringContainsString('deployment', $result['error']);
            $release = Release::fromArray(['version' => '9.0.0', 'packageUrl' => 'https://invalid.example/release.zip', 'sha256' => str_repeat('a', 64)]);
            $result = (new UpdateInstaller($base))->install($release, $base . '/absent.zip');
            self::assertFalse($result['success']);
            self::assertStringContainsString('deployment', $result['error']);
            self::assertFileDoesNotExist($base . '/data/updates/install.lock');
        } finally {
            putenv($before === false ? 'CLICK_DEPLOYMENT_MANAGED' : 'CLICK_DEPLOYMENT_MANAGED=' . $before);
            rmdir($base . '/data/marketplace');
            rmdir($base . '/data');
            rmdir($base . '/plugins');
            rmdir($base);
        }
    }
}
