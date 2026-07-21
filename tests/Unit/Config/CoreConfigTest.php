<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Config;

use Click\Cms\Application\Config\CoreConfig;
use PHPUnit\Framework\TestCase;

final class CoreConfigTest extends TestCase
{
    public function testEverySettingHasADefaultSoAnEmptyConfigRuns(): void
    {
        $config = CoreConfig::fromArray([]);

        $this->assertTrue($config->restApiEnabled());
        $this->assertTrue($config->authEnabled());
        $this->assertSame(28_800, $config->sessionTtlSeconds());
        $this->assertSame(5, $config->lockoutMaxAttempts());
        $this->assertSame(['admin-ui', 'authentication'], $config->excludedPluginIds());
    }

    public function testAMissingFileIsNotAnError(): void
    {
        $config = CoreConfig::load('/nowhere/at/all/core.json');

        $this->assertTrue($config->restApiEnabled());
    }

    public function testAnUnreadableOrCorruptFileFallsBackToDefaults(): void
    {
        $path = sys_get_temp_dir() . '/click-cms-config-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, '{ not json');

        $this->assertTrue(CoreConfig::load($path)->restApiEnabled());

        @unlink($path);
    }

    public function testValuesAreReadFromTheFile(): void
    {
        $config = CoreConfig::fromArray([
            'core' => [
                'restApi' => ['enabled' => false],
                'auth' => ['sessionTtlSeconds' => 60, 'lockoutMaxAttempts' => 2],
            ],
        ]);

        $this->assertFalse($config->restApiEnabled());
        $this->assertSame(60, $config->sessionTtlSeconds());
        $this->assertSame(2, $config->lockoutMaxAttempts());
    }

    public function testRememberedSessionsUseTheirOwnLifetime(): void
    {
        $config = CoreConfig::fromArray([
            'core' => ['auth' => ['sessionTtlSeconds' => 60, 'rememberTtlSeconds' => 9_999]],
        ]);

        $this->assertSame(60, $config->sessionTtlSeconds(false));
        $this->assertSame(9_999, $config->sessionTtlSeconds(true));
    }

    /**
     * Configuration must not be able to weaken this below what the code
     * considers safe.
     */
    public function testPasswordMinimumCannotBeConfiguredBelowEight(): void
    {
        $config = CoreConfig::fromArray(['core' => ['auth' => ['passwordMinLength' => 1]]]);

        $this->assertSame(8, $config->passwordMinLength());
    }

    public function testPasswordMinimumCanBeRaised(): void
    {
        $config = CoreConfig::fromArray(['core' => ['auth' => ['passwordMinLength' => 16]]]);

        $this->assertSame(16, $config->passwordMinLength());
    }

    public function testWrongTypesFallBackRatherThanCoercingNonsense(): void
    {
        $config = CoreConfig::fromArray([
            'core' => ['auth' => ['sessionTtlSeconds' => 'soon']],
        ]);

        $this->assertSame(28_800, $config->sessionTtlSeconds());
    }

    public function testNonStringEntriesAreDroppedFromLists(): void
    {
        $config = CoreConfig::fromArray([
            'core' => ['plugins' => ['exclude' => ['ids' => ['good', 42, null, 'also-good']]]],
        ]);

        $this->assertSame(['good', 'also-good'], $config->excludedPluginIds());
    }

    public function testHistoryRetentionDefaultsToTwenty(): void
    {
        $this->assertSame(20, CoreConfig::fromArray([])->historyRetainedVersions());
    }

    public function testHistoryRetentionIsConfigurable(): void
    {
        $config = CoreConfig::fromArray([
            'core' => ['history' => ['retainVersions' => 5]],
        ]);

        $this->assertSame(5, $config->historyRetainedVersions());
    }

    /**
     * Zero would leave history looking enabled while keeping nothing, which an
     * editor would only discover on the day they needed it.
     */
    public function testHistoryRetentionIsNeverBelowOne(): void
    {
        foreach ([0, -1] as $configured) {
            $config = CoreConfig::fromArray([
                'core' => ['history' => ['retainVersions' => $configured]],
            ]);

            $this->assertSame(1, $config->historyRetainedVersions());
        }
    }

    public function testBothDeliveryApisCanBeDisabled(): void
    {
        $config = CoreConfig::fromArray([
            'core' => ['restApi' => ['enabled' => false], 'graphql' => ['enabled' => false]],
        ]);

        // A site that renders its own pages runs like this; management is
        // unaffected, so this must simply be allowed.
        $this->assertFalse($config->restApiEnabled());
        $this->assertFalse($config->graphqlEnabled());
    }
}
