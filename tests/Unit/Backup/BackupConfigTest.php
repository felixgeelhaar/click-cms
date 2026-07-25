<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Backup;

use Click\Cms\Application\Config\CoreConfig;
use PHPUnit\Framework\TestCase;

/**
 * The `core.backup` settings, and the defaults a site that has never heard of
 * them gets.
 *
 * Two of these are decisions rather than conveniences and are asserted as such:
 * scheduled backups are off until somebody asks for them, and retention never
 * deletes the last archive however small the number in the file is.
 */
final class BackupConfigTest extends TestCase
{
    private function config(array $backup): CoreConfig
    {
        return CoreConfig::fromArray(['core' => ['backup' => $backup]]);
    }

    /**
     * A backup nobody asked for is a directory that grows every night on a host
     * whose disk quota the CMS knows nothing about, and the first anyone hears of
     * it is the site failing to write a page.
     */
    public function testScheduledBackupsAreOffUntilASiteAsksForThem(): void
    {
        $this->assertFalse(CoreConfig::fromArray([])->backupEnabled());
        $this->assertTrue($this->config(['enabled' => true])->backupEnabled());
    }

    public function testTheDefaultsAreUsableWithoutAnyConfiguration(): void
    {
        $config = CoreConfig::fromArray([]);

        $this->assertSame(24, $config->backupIntervalHours());
        $this->assertSame(7, $config->backupKeep());
        $this->assertTrue($config->backupIncludeMedia());
        $this->assertSame(512 * 1024 * 1024, $config->backupMaxMediaBytes());
    }

    public function testEachSettingIsRead(): void
    {
        $config = $this->config([
            'intervalHours' => 6,
            'keep' => 30,
            'includeMedia' => false,
            'maxMediaBytes' => 1024,
        ]);

        $this->assertSame(6, $config->backupIntervalHours());
        $this->assertSame(30, $config->backupKeep());
        $this->assertFalse($config->backupIncludeMedia());
        $this->assertSame(1024, $config->backupMaxMediaBytes());
    }

    /**
     * Zero would mean retention deleting the archive it has just taken. It is an
     * easy thing to type and there is no site that wants it.
     */
    public function testRetentionNeverDropsBelowOneArchive(): void
    {
        $this->assertSame(1, $this->config(['keep' => 0])->backupKeep());
        $this->assertSame(1, $this->config(['keep' => -5])->backupKeep());
    }

    /** An interval of zero and a cron line would write archives continuously. */
    public function testTheIntervalNeverDropsBelowAnHour(): void
    {
        $this->assertSame(1, $this->config(['intervalHours' => 0])->backupIntervalHours());
        $this->assertSame(1, $this->config(['intervalHours' => -3])->backupIntervalHours());
    }

    /** Zero or less is "no ceiling", which is a legitimate thing to want. */
    public function testACeilingOfZeroIsKeptAsZero(): void
    {
        $this->assertSame(0, $this->config(['maxMediaBytes' => 0])->backupMaxMediaBytes());
    }

    public function testGarbageValuesFallBackToTheDefaults(): void
    {
        $config = $this->config([
            'intervalHours' => 'nightly',
            'keep' => 'lots',
            'maxMediaBytes' => 'big',
        ]);

        $this->assertSame(24, $config->backupIntervalHours());
        $this->assertSame(7, $config->backupKeep());
        $this->assertSame(512 * 1024 * 1024, $config->backupMaxMediaBytes());
    }

    /** The shipped configuration file must agree with the documented defaults. */
    public function testTheShippedConfigurationMatchesTheDefaults(): void
    {
        $config = CoreConfig::load(dirname(__DIR__, 3) . '/config/core.json');

        $this->assertFalse($config->backupEnabled());
        $this->assertSame(24, $config->backupIntervalHours());
        $this->assertSame(7, $config->backupKeep());
        $this->assertTrue($config->backupIncludeMedia());
        $this->assertSame(512 * 1024 * 1024, $config->backupMaxMediaBytes());
    }
}
