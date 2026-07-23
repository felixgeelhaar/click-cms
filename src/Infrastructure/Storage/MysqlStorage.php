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
 * MySQL / MariaDB content storage.
 *
 * The same shape as {@see SqliteStorage}: the payload stays JSON in a single
 * column so a plugin can add a field without a schema migration, while `type`,
 * `locale` and `slug` are real columns because those are the only things queried
 * on. The legal key space is kept identical to the flat-file backend
 * ({@see ContentKeyRules}), so content stays portable between all three.
 *
 * For a site that has outgrown a single machine and wants a shared database —
 * multiple app servers behind one MySQL — where SQLite's single file and flat
 * JSON cannot reach. It needs the pdo_mysql extension and a reachable server.
 */
final class MysqlStorage implements StorageInterface
{
    private ?PDO $pdo = null;

    private readonly Locale $defaultLocale;

    /**
     * @param string  $host     MySQL host.
     * @param int     $port     MySQL port.
     * @param string  $database The database to use; created if it does not exist
     *                          and the account may.
     * @param string  $user     MySQL user.
     * @param string  $password MySQL password.
     * @param ?Locale $defaultLocale Which language a row carrying none is taken
     *                          to hold — the same contract the other backends keep.
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
        // The same strict rule the flat-file backend enforces, so the legal key
        // space is identical and content stays portable between backends.
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
        // Loud on the write path: a key the flat-file backend could never
        // represent is a bug or an attack, not a miss.
        ContentKeyRules::assertSafe($content->key);

        $payload = json_encode(
            $content->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $stmt = $this->pdo()->prepare(
            'INSERT INTO content (type, locale, slug, payload, updated_at)
             VALUES (:type, :locale, :slug, :payload, :updated_at)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)'
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

        // The row's own coordinates decide what it is.
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
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Connect to the server first (no database named), create the database if
        // it is absent and the account may, then select it — so a fresh server
        // needs no manual setup. If the account cannot create it, the database is
        // expected to exist already and selecting it below reports the real error.
        try {
            $server = new PDO(
                "mysql:host={$this->host};port={$this->port};charset=utf8mb4",
                $this->user,
                $this->password,
                $options
            );
            $server->exec(
                'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $this->database)
                . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        } catch (PDOException) {
            // No create privilege, or the database already exists — either way the
            // connection with the database named will settle it.
        }

        try {
            $pdo = new PDO(
                "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset=utf8mb4",
                $this->user,
                $this->password,
                $options
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Unable to connect to MySQL database \"{$this->database}\" at "
                . "{$this->host}:{$this->port}: " . $e->getMessage(),
                previous: $e
            );
        }

        // VARCHAR lengths chosen so the composite primary key stays within
        // InnoDB's index limit under utf8mb4: a slug is already bounded by
        // ContentKeyRules, a locale is a short BCP-47 tag, a type a single word.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS content (
                type       VARCHAR(64)  NOT NULL,
                locale     VARCHAR(35)  NOT NULL DEFAULT ' . $pdo->quote($this->defaultLocale->code) . ',
                slug       VARCHAR(191) NOT NULL,
                payload    LONGTEXT     NOT NULL,
                updated_at VARCHAR(40)  NOT NULL,
                PRIMARY KEY (type, locale, slug),
                KEY content_type_idx (type, locale)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return $this->pdo = $pdo;
    }
}
