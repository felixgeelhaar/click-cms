<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Application\Config\ConfigurationException;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\SqliteStorage;
use Click\Cms\Infrastructure\Storage\StorageFactory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StorageFactoryTest extends TestCase
{
    private const BASE = '/srv/click';

    /**
     * A fresh install has no configuration at all and must still boot, on flat
     * files. "No database required" is a property of core, so this is the most
     * important assertion in the file.
     */
    public function testAnEmptyConfigurationGivesFlatFiles(): void
    {
        $storage = StorageFactory::create(CoreConfig::fromArray([]), self::BASE);

        $this->assertInstanceOf(JsonStorage::class, $storage);
    }

    public function testJsonCanBeAskedForExplicitly(): void
    {
        $this->assertInstanceOf(JsonStorage::class, $this->build('json'));
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testSqliteIsBuiltWhenConfigured(): void
    {
        $this->assertInstanceOf(SqliteStorage::class, $this->build('sqlite'));
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testBackendNameIsMatchedRegardlessOfCaseOrSurroundingSpace(): void
    {
        $this->assertInstanceOf(SqliteStorage::class, $this->build('  SQLite '));
    }

    /**
     * The whole point of the exercise: asking for SQLite and quietly receiving
     * JSON would look exactly like every document having vanished.
     */
    public function testAnUnknownBackendRefusesToBuildAnythingAtAll(): void
    {
        $this->expectException(RuntimeException::class);

        $this->build('mongodb');
    }

    /**
     * The entry point shows this one to the visitor and swallows every other
     * exception, so the type is load-bearing rather than incidental.
     */
    public function testMisconfigurationThrowsTheTypeTheEntryPointRenders(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->build('mongodb');
    }

    public function testTheErrorSaysWhatWasAskedForWhatExistsAndWhatToDo(): void
    {
        try {
            $this->build('mongodb');
            $this->fail('Expected an unknown backend to throw.');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('mongodb', $message, 'names what was asked for');
            $this->assertStringContainsString('"json"', $message, 'names what is available');
            $this->assertStringContainsString('"sqlite"', $message, 'names what is available');
            $this->assertStringContainsString('core.storage.backend', $message, 'names the setting');
            $this->assertStringContainsString('config/core.json', $message, 'names the file');
        }
    }

    public function testAnEmptyBackendNameIsRejectedRatherThanTreatedAsUnset(): void
    {
        $this->expectException(RuntimeException::class);

        $this->build('');
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAnEmptySqlitePathIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(RuntimeException::class);

        StorageFactory::create(
            CoreConfig::fromArray([
                'core' => ['storage' => ['backend' => 'sqlite', 'sqlite' => ['path' => '  ']]],
            ]),
            self::BASE
        );
    }

    /**
     * The working directory differs between the web server and the CLI, so a
     * relative path has to be anchored to the installation or one install ends
     * up with two databases.
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testARelativeSqlitePathIsResolvedAgainstTheInstallationRoot(): void
    {
        $storage = $this->buildSqliteAt('data/content.sqlite');

        $this->assertSame(self::BASE . '/data/content.sqlite', $this->databasePathOf($storage));
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAnAbsoluteSqlitePathIsUsedAsGiven(): void
    {
        $storage = $this->buildSqliteAt('/var/lib/click/content.sqlite');

        $this->assertSame('/var/lib/click/content.sqlite', $this->databasePathOf($storage));
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testTheDefaultSqlitePathSitsOutsideTheWebRoot(): void
    {
        $storage = StorageFactory::create(
            CoreConfig::fromArray(['core' => ['storage' => ['backend' => 'sqlite']]]),
            self::BASE
        );

        $this->assertSame(self::BASE . '/data/content.sqlite', $this->databasePathOf($storage));
    }

    private function build(string $backend): object
    {
        return StorageFactory::create(
            CoreConfig::fromArray(['core' => ['storage' => ['backend' => $backend]]]),
            self::BASE
        );
    }

    private function buildSqliteAt(string $path): SqliteStorage
    {
        $storage = StorageFactory::create(
            CoreConfig::fromArray([
                'core' => ['storage' => ['backend' => 'sqlite', 'sqlite' => ['path' => $path]]],
            ]),
            self::BASE
        );

        $this->assertInstanceOf(SqliteStorage::class, $storage);

        return $storage;
    }

    /** The path is private and never opened here; these tests must touch no disk. */
    private function databasePathOf(SqliteStorage $storage): string
    {
        $property = new \ReflectionProperty(SqliteStorage::class, 'databasePath');

        return (string) $property->getValue($storage);
    }
}
