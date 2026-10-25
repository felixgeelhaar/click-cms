<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Plugin\PluginExecutionPolicy;
use Click\Cms\Http\SecurityHealthController;
use PHPUnit\Framework\TestCase;

final class SecurityHealthControllerTest extends TestCase
{
    public function testReportsConfiguredFactsAndSpecificActionsWithoutAnOverallScore(): void
    {
        $controller = new SecurityHealthController(
            CoreConfig::fromArray([
                'core' => [
                    'auth' => ['enabled' => true],
                    'backup' => ['enabled' => true, 'intervalHours' => 12, 'keep' => 10, 'includeMedia' => true],
                    'updates' => ['policy' => 'security'],
                ],
            ]),
            PluginExecutionPolicy::fromValue('admin-ui,authentication'),
        );

        $response = $controller->show();
        $checks = $response['data']['checks'];
        $byId = array_column($checks, null, 'id');

        self::assertSame('good', $byId['authentication']['status']);
        self::assertSame('review', $byId['scheduled-backups']['status']);
        self::assertStringContainsString('does not verify', $byId['scheduled-backups']['detail']);
        self::assertSame('good', $byId['update-policy']['status']);
        self::assertSame('application-managed', $byId['deployment-management']['value']);
        self::assertSame('allowlist', $byId['plugin-admission']['value']);
        self::assertArrayHasKey('php-runtime', $byId);
        self::assertArrayNotHasKey('score', $response['data']);
        self::assertArrayNotHasKey('overall', $response['data']);
        self::assertStringNotContainsString('admin-ui,authentication', json_encode($response));
    }

    public function testCallsOutDisabledControlsAndWeakRuntimeConfiguration(): void
    {
        $controller = new SecurityHealthController(
            CoreConfig::fromArray([
                'core' => [
                    'auth' => ['enabled' => false],
                    'backup' => ['enabled' => false],
                    'updates' => ['policy' => 'all'],
                ],
            ]),
            PluginExecutionPolicy::fromValue(''),
            [
                'phpVersionId' => 80000,
                'phpVersion' => '8.0.0',
                'displayErrors' => '1',
                'exposePhp' => '1',
            ],
        );

        $checks = array_column($controller->show()['data']['checks'], null, 'id');

        self::assertSame('attention', $checks['authentication']['status']);
        self::assertSame('attention', $checks['scheduled-backups']['status']);
        self::assertSame('attention', $checks['update-policy']['status']);
        self::assertSame('attention', $checks['php-runtime']['status']);
        self::assertSame('disabled', $checks['plugin-admission']['value']);
        self::assertStringContainsString('display_errors', $checks['php-runtime']['detail']);
        self::assertStringContainsString('expose_php', $checks['php-runtime']['detail']);
    }

    public function testReportsDeploymentManagedUpdatesAsOwnedByTheDeploymentPipeline(): void
    {
        $previous = getenv('CLICK_DEPLOYMENT_MANAGED');
        putenv('CLICK_DEPLOYMENT_MANAGED=1');
        try {
            $controller = new SecurityHealthController(
                CoreConfig::fromArray(['core' => ['updates' => ['policy' => 'all']]]),
                PluginExecutionPolicy::fromValue(false),
            );
            $checks = array_column($controller->show()['data']['checks'], null, 'id');

            self::assertSame('deployment-managed', $checks['deployment-management']['value']);
            self::assertStringContainsString('deployment environment manages code updates', $checks['deployment-management']['detail']);
            self::assertSame('manual', $checks['update-policy']['value']);
            self::assertStringContainsString('separate deployment process', $checks['update-policy']['detail']);
        } finally {
            $previous === false ? putenv('CLICK_DEPLOYMENT_MANAGED') : putenv('CLICK_DEPLOYMENT_MANAGED=' . $previous);
        }
    }
}
