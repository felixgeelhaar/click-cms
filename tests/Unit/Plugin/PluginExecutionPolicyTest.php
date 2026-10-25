<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\PluginExecutionPolicy;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Domain\ValueObjects\PluginId;
use PHPUnit\Framework\TestCase;

final class PluginExecutionPolicyTest extends TestCase
{
    public function testUnsetPreservesCompatibilityButEmptyDeniesAll(): void
    {
        self::assertTrue(PluginExecutionPolicy::fromValue(false)->allows('demo'));
        self::assertFalse(PluginExecutionPolicy::fromValue('')->allows('demo'));
    }

    public function testExplicitAllowlistUsesExactIdsAndRejectsMalformedConfiguration(): void
    {
        $policy = PluginExecutionPolicy::fromValue('rest-api, visual-builder');
        self::assertTrue($policy->allows('rest-api'));
        self::assertFalse($policy->allows('rest-api-extra'));
        self::assertFalse(PluginExecutionPolicy::fromValue('rest-api,../evil')->allows('rest-api'));
    }

    public function testBlockedPluginBootstrapNeverExecutesEvenIfActivationRequested(): void
    {
        $base = sys_get_temp_dir() . '/click-policy-' . bin2hex(random_bytes(6));
        mkdir($base . '/plugins/demo', 0700, true);
        file_put_contents($base . '/plugins/demo/plugin.json', json_encode([
            'name' => 'Demo', 'version' => '1.0.0', 'description' => '', 'author' => '', 'hooks' => [],
        ]));
        $marker = $base . '/executed';
        file_put_contents($base . '/plugins/demo/bootstrap.php', '<?php file_put_contents(' . var_export($marker, true) . ', "executed");');
        try {
            $manager = new PluginManager($base . '/plugins', $base, [], [], PluginExecutionPolicy::fromValue(''));
            $manager->discover();
            $result = $manager->activate(PluginId::generate('Demo'));
            self::assertFalse($result['success']);
            self::assertStringContainsString('deployment policy', $result['error']);
            self::assertFileDoesNotExist($marker);
            self::assertSame([], $manager->getActive());
        } finally {
            @unlink($marker);
            @unlink($base . '/plugin-state.json');
            unlink($base . '/plugins/demo/bootstrap.php');
            unlink($base . '/plugins/demo/plugin.json');
            rmdir($base . '/plugins/demo');
            rmdir($base . '/plugins');
            rmdir($base);
        }
    }
}
