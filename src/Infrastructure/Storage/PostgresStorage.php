<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PostgreSQL content storage.
 *
 * The same shape as {@see MysqlStorage} and {@see SqliteStorage}: the payload
 * stays JSON in a single column so a plugin can add a field without a schema
 * migration, while `type`, `locale` and `slug` are real columns because those
 * are the only things queried on. The legal key space is kept identical to the
 * flat-file backend ({@see ContentKeyRules}), so content stays portable between
 * all four.
 *
 * Only two things differ from the MySQL backend, both dialect: the upsert is
 * `ON CONFLICT ... DO UPDATE`, and creating the database means connecting to the
 * `postgres` maintenance database first (PostgreSQL has no `CREATE DATABASE IF
 * NOT EXISTS`). Needs the pdo_pgsql extension and a reachable server.
 */
final class PostgresStorage implements StorageInterface
{
    private ?PDO $pdo = null;

    private readonly Locale $defaultLocale;

    /**
     * @param ?Locale $defaultLocale Which language a row carrying none is taken
     *        to hold — the same contract the other backends keep.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $user,
        private readonly string $password,
        ?Locale $defaultLocale = null,
    ) {
        $this->defaultLocale = $defaultLocale ?? Locale::default();
    }

    public function find(ContentKey $key): ?Content
    {
        if (!ContentKeyRules::isSafe($key)) {
            return null;
        }

        $stmt = $this->pdo()->prepare(
            'SELECT payload FROM content WHERE type = :type AND locale = :locale AND slug = :slug LIMIT 1'
        );
        $stmt->execute(['type' => $key->type, 'locale' => $key->locale->code, 'slug' => $key->slug]);

        $payload = $stmt->fetchColumn();

        return is_string($payload) ? $this->decode($payload, $key) : null;
    }

    public function findByType(string $type, ?Locale $locale = null): array
    {
        if (!ContentKeyRules::isSafeSegment($type)) {
            return [];
        }

        $sql = 'SELECT locale, slug, payload FROM content WHERE type = :type';
        $params = ['type' => $type];
        if ($locale !== null) {
            $sql .= ' AND locale = :locale';
            $params['locale'] = $locale->code;
        }

        $stmt = $this->pdo()->prepare($sql . ' ORDER BY locale ASC, slug ASC');
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $content = $this->decode(
                (string) $row['payload'],
                ContentKey::fromString($type . ':' . $row['locale'] . ':' . $row['slug'])
            );
            if ($content !== null) {
                $out[] = $content;
            }
        }

        return $out;
    }

    public function save(Content $content): void
    {
        ContentKeyRules::assertSafe($content->key);

        $payload = json_encode(
            $content->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $stmt = $this->pdo()->prepare(
            'INSERT INTO content (type, locale, slug, payload, updated_at)
             VALUES (:type, :locale, :slug, :payload, :updated_at)
             ON CONFLICT (type, locale, slug) DO UPDATE SET
                 payload = EXCLUDED.payload,
                 updated_at = EXCLUDED.updated_at'
        );
        $stmt->execute([
            'type' => $content->key->type,
            'locale' => $content->key->locale->code,
            'slug' => $content->key->slug,
            'payload' => $payload,
            'updated_at' => $content->updatedAt()->format(DATE_ATOM),
        ]);
    }

    public function delete(ContentKey $key): bool
    {
        if (!ContentKeyRules::isSafe($key)) {
            return false;
        }

        $stmt = $this->pdo()->prepare(
            'DELETE FROM content WHERE type = :type AND locale = :locale AND slug = :slug'
        );
        $stmt->execute(['type' => $key->type, 'locale' => $key->locale->code, 'slug' => $key->slug]);

        return $stmt->rowCount() > 0;
    }

    public function exists(ContentKey $key): bool
    {
        if (!ContentKeyRules::isSafe($key)) {
            return false;
        }

        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM content WHERE type = :type AND locale = :locale AND slug = :slug LIMIT 1'
        );
        $stmt->execute(['type' => $key->type, 'locale' => $key->locale->code, 'slug' => $key->slug]);

        return $stmt->fetchColumn() !== false;
    }

    /** A corrupt row must not break a listing, so it reads as absent. */
    private function decode(string $payload, ContentKey $key): ?Content
    {
        $row = json_decode($payload, true);
        if (!is_array($row)) {
            return null;
        }

        $row['key'] = $key->toString();

        return Content::fromArray($row);
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        // PostgreSQL has no CREATE DATABASE IF NOT EXISTS and cannot create a
        // database while connected to it, so the maintenance database is used to
        // create the target if it is absent and the account may. A failure here
        // is tolerated: the database is then expected to exist, and the connection
        // below reports the real error if it does not.
        try {
            $maintenance = new PDO(
                "pgsql:host={$this->host};port={$this->port};dbname=postgres",
                $this->user,
                $this->password,
                $options
            );
            $exists = $maintenance
                ->query('SELECT 1 FROM pg_database WHERE datname = ' . $maintenance->quote($this->database))
                ->fetchColumn();
            if ($exists === false) {
                $maintenance->exec('CREATE DATABASE "' . str_replace('"', '""', $this->database) . '"');
            }
        } catch (PDOException) {
            // No create privilege, no maintenance database, or a race — the
            // connection with the database named settles it.
        }

        try {
            $pdo = new PDO(
                "pgsql:host={$this->host};port={$this->port};dbname={$this->database}",
                $this->user,
                $this->password,
                $options
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Unable to connect to PostgreSQL database \"{$this->database}\" at "
                . "{$this->host}:{$this->port}: " . $e->getMessage(),
                previous: $e
            );
        }

        // The same column shape as the other SQL backends. PostgreSQL supports
        // CREATE TABLE / INDEX IF NOT EXISTS, so a first boot builds the schema
        // and a later one leaves it alone.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS content (
                type       VARCHAR(64)  NOT NULL,
                locale     VARCHAR(35)  NOT NULL DEFAULT ' . $pdo->quote($this->defaultLocale->code) . ',
                slug       VARCHAR(191) NOT NULL,
                payload    TEXT         NOT NULL,
                updated_at VARCHAR(40)  NOT NULL,
                PRIMARY KEY (type, locale, slug)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS content_type_idx ON content (type, locale)');

        return $this->pdo = $pdo;
    }
}
