<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Update;

use Click\Cms\Application\Update\UpdateRecovery;
use PHPUnit\Framework\TestCase;

final class UpdateRecoveryTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-recovery-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/data/updates', 0o755, true);
        mkdir($this->base . '/src', 0o755, true);
        mkdir($this->base . '/public', 0o755, true);
        file_put_contents($this->base . '/src/version.php', 'new');
        file_put_contents($this->base . '/public/index.php', 'new-front-controller');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    public function testRecoversAProcessKilledAfterMovingOldCodeAndPublishingNewIndex(): void
    {
        $backup = $this->base . '/data/updates/backup-20261025-120000-a1b2c3';
        mkdir($backup . '/src', 0o755, true);
        mkdir($backup . '/public', 0o755, true);
        file_put_contents($backup . '/src/version.php', 'old');
        file_put_contents($backup . '/public/index.php', 'old-front-controller');
        mkdir($this->base . '/.update-staging-a1b2c3', 0o755);
        $this->writeJournal([
            ['target' => 'src', 'previous' => true, 'incoming' => true],
            ['target' => 'public/index.php', 'previous' => true, 'incoming' => true],
        ], 'backup-20261025-120000-a1b2c3', '.update-staging-a1b2c3');

        $result = UpdateRecovery::recover($this->base);

        self::assertSame(['recovered' => true, 'error' => null], $result);
        self::assertSame('old', file_get_contents($this->base . '/src/version.php'));
        self::assertSame('old-front-controller', file_get_contents($this->base . '/public/index.php'));
        self::assertFileDoesNotExist($this->base . '/data/updates/swap-journal.json');
        self::assertDirectoryDoesNotExist($this->base . '/.update-staging-a1b2c3');
    }

    public function testRecoveryIsIdempotentWhenInterruptedAfterRestoringOneTarget(): void
    {
        $backup = $this->base . '/data/updates/backup-20261025-120000-a1b2c3';
        mkdir($backup . '/src', 0o755, true);
        mkdir($backup . '/public', 0o755, true);
        file_put_contents($backup . '/src/version.php', 'old');
        file_put_contents($backup . '/public/index.php', 'old-front-controller');
        // Simulate recovery having already restored src before being killed.
        unlink($this->base . '/src/version.php');
        rmdir($this->base . '/src');
        rename($backup . '/src', $this->base . '/src');
        mkdir($this->base . '/.update-staging-a1b2c3', 0o755);
        $this->writeJournal([
            ['target' => 'src', 'previous' => true, 'incoming' => true],
            ['target' => 'public/index.php', 'previous' => true, 'incoming' => true],
        ], 'backup-20261025-120000-a1b2c3', '.update-staging-a1b2c3');

        $result = UpdateRecovery::recover($this->base);

        self::assertTrue($result['recovered']);
        self::assertSame('old', file_get_contents($this->base . '/src/version.php'));
        self::assertSame('old-front-controller', file_get_contents($this->base . '/public/index.php'));
    }

    public function testRejectsUnsafeTargetAndRetainsJournal(): void
    {
        $this->writeJournal([
            ['target' => '../config', 'previous' => true, 'incoming' => true],
        ], 'backup-20261025-120000-a1b2c3', '.update-staging-a1b2c3');

        $result = UpdateRecovery::recover($this->base);

        self::assertFalse($result['recovered']);
        self::assertFileExists($this->base . '/data/updates/swap-journal.json');
        self::assertSame('new', file_get_contents($this->base . '/src/version.php'));
    }

    public function testValidatesEveryActionBeforeChangingAnyFiles(): void
    {
        $backup = $this->base . '/data/updates/backup-20261025-120000-a1b2c3';
        mkdir($backup . '/src', 0755, true);
        file_put_contents($backup . '/src/version.php', 'old');
        $this->writeJournal([
            ['target'=>'src', 'previous'=>true, 'incoming'=>true],
            ['target'=>'../config', 'previous'=>true, 'incoming'=>true],
        ], 'backup-20261025-120000-a1b2c3', '.update-staging-a1b2c3');
        $result = UpdateRecovery::recover($this->base);
        self::assertNotNull($result['error']);
        self::assertSame('new', file_get_contents($this->base . '/src/version.php'));
        self::assertSame('old', file_get_contents($backup . '/src/version.php'));
    }

    public function testMissingLiveAndBackupCopyRequiresOperatorRecovery(): void
    {
        unlink($this->base . '/src/version.php'); rmdir($this->base . '/src');
        $this->writeJournal([['target'=>'src', 'previous'=>true, 'incoming'=>true]],
            'backup-20261025-120000-a1b2c3', '.update-staging-a1b2c3');
        $result = UpdateRecovery::recover($this->base);
        self::assertNotNull($result['error']);
        self::assertFileExists($this->base . '/data/updates/swap-journal.json');
    }

    /** @param list<array{target:string,previous:bool,incoming:bool}> $actions */
    private function writeJournal(array $actions, string $backup, string $staging): void
    {
        file_put_contents($this->base . '/data/updates/swap-journal.json', json_encode([
            'format' => 1,
            'base' => realpath($this->base),
            'publicRoot' => realpath($this->base . '/public'),
            'backup' => $backup,
            'staging' => $staging,
            'actions' => $actions,
        ], JSON_THROW_ON_ERROR));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $child = $path . '/' . $name;
                is_dir($child) ? $this->removeTree($child) : unlink($child);
            }
        }
        rmdir($path);
    }
}
