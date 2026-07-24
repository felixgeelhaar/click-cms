<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Storage\PostgresStorage;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * The shared storage contract, run against a real PostgreSQL server.
 *
 * Like the MySQL contract test it needs a reachable server, so it skips cleanly
 * unless one is provided (defaults to 127.0.0.1:5432; override with
 * CLICK_CMS_POSTGRES_TEST_* environment variables). Run it against the demo
 * database container locally.
 */
#[RequiresPhpExtension('pdo_pgsql')]
final class PostgresStorageTest extends StorageContractTestCase
{
    /** @var array{host: string, port: int, db: string, user: string, pass: string} */
    private array $conn;

    protected function createStorage(): StorageInterface
    {
        $host = getenv('CLICK_CMS_POSTGRES_TEST_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('CLICK_CMS_POSTGRES_TEST_PORT') ?: '5432');
        $user = getenv('CLICK_CMS_POSTGRES_TEST_USER') ?: 'postgres';
        $pass = getenv('CLICK_CMS_POSTGRES_TEST_PASSWORD') ?: 'postgres';
        $db = getenv('CLICK_CMS_POSTGRES_TEST_DATABASE') ?: 'clickcms_test';

        // A fresh schema per test, and a clean skip when no server is present.
        try {
            $server = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $exists = $server->query('SELECT 1 FROM pg_database WHERE datname = ' . $server->quote($db))->fetchColumn();
            if ($exists === false) {
                $server->exec('CREATE DATABASE "' . str_replace('"', '""', $db) . '"');
            }
            $work = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $work->exec('DROP TABLE IF EXISTS content');
        } catch (\Throwable $e) {
            $this->markTestSkipped('No PostgreSQL server reachable for the storage contract: ' . $e->getMessage());
        }

        $this->conn = compact('host', 'port', 'db', 'user', 'pass');

        return new PostgresStorage($host, $port, $db, $user, $pass);
    }

    protected function corruptStoredItem(ContentKey $key): void
    {
        $c = $this->conn;
        $pdo = new PDO(
            "pgsql:host={$c['host']};port={$c['port']};dbname={$c['db']}",
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $pdo->prepare('UPDATE content SET payload = :p WHERE type = :t AND slug = :s');
        $stmt->execute(['p' => '{ this is not json', 't' => $key->type, 's' => $key->slug]);
    }
}
