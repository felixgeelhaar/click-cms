<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Storage\MysqlStorage;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * The shared storage contract, run against a real MySQL/MariaDB server.
 *
 * It needs a reachable server, which a plain `phpunit` run does not have, so it
 * skips cleanly unless one is provided (defaults to 127.0.0.1:3306; override
 * with CLICK_CMS_MYSQL_TEST_* environment variables). CI wires a MySQL service
 * and points these at it; locally, run it against the demo database container.
 */
#[RequiresPhpExtension('pdo_mysql')]
final class MysqlStorageTest extends StorageContractTestCase
{
    /** @var array{host: string, port: int, db: string, user: string, pass: string} */
    private array $conn;

    protected function createStorage(): StorageInterface
    {
        $host = getenv('CLICK_CMS_MYSQL_TEST_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('CLICK_CMS_MYSQL_TEST_PORT') ?: '3306');
        $user = getenv('CLICK_CMS_MYSQL_TEST_USER') ?: 'root';
        $pass = getenv('CLICK_CMS_MYSQL_TEST_PASSWORD') ?: '';
        $db = getenv('CLICK_CMS_MYSQL_TEST_DATABASE') ?: 'clickcms_test';

        // A fresh schema per test, and a clean skip when no server is present.
        try {
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $server->exec("CREATE DATABASE IF NOT EXISTS `{$db}`");
            $server->exec("USE `{$db}`");
            $server->exec('DROP TABLE IF EXISTS content');
        } catch (\Throwable $e) {
            $this->markTestSkipped('No MySQL server reachable for the storage contract: ' . $e->getMessage());
        }

        $this->conn = compact('host', 'port', 'db', 'user', 'pass');

        return new MysqlStorage($host, $port, $db, $user, $pass);
    }

    protected function corruptStoredItem(ContentKey $key): void
    {
        $c = $this->conn;
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['db']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $pdo->prepare('UPDATE content SET payload = :p WHERE type = :t AND slug = :s');
        $stmt->execute(['p' => '{ this is not json', 't' => $key->type, 's' => $key->slug]);
    }
}
