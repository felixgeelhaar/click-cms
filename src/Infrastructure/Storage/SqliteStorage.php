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
 * SQLite content storage.
 *
 * The payload stays JSON in a single column rather than being spread across
 * typed columns: content shape is defined by plugins, so a rigid schema would
 * force a migration every time a plugin added a field. Type and slug are kept
 * as real columns because those are the only things ever queried on.
 *
 * Suitable when a site outgrows flat files but still has no database server —
 * SQLite needs only the pdo_sqlite extension.
 */
final class SqliteStorage implements StorageInterface
{
    private ?PDO $pdo = null;

    private readonly Locale $defaultLocale;

    /**
     * `SELECT DISTINCT` over the type column — the whole reason a relational
     * backend can answer "what is in this site?" that a directory walk cannot
     * answer for it.
     */
    public function types(): array
    {
        $stmt = $this->pdo()->query('SELECT DISTINCT type FROM content ORDER BY type ASC');

        return $stmt === false ? [] : array_values(array_map(
            static fn ($row): string => (string) $row,
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        ));
    }

    /**
     * @param ?Locale $defaultLocale Which language rows written before the
     *        locale column existed are taken to hold.
     */
    public function __construct(
        private readonly string $databasePath,
        ?Locale $defaultLocale = null,
    ) {
        $this->defaultLocale = $defaultLocale ?? Locale::default();
    }

    public function find(ContentKey $key): ?Content
    {
        // SQLite would happily store `page:../../etc/passwd`; the flat-file
        // backend would not. Applying the stricter rule here too keeps the legal
        // key space the same on both, so content stays portable between them.
        if (!ContentKeyRules::isSafe($key)) {
            return null;
        }

        $stmt = $this->pdo()->prepare(
            'SELECT payload FROM content WHERE type = :type AND locale = :locale AND slug = :slug LIMIT 1'
        );
        $stmt->execute([
            'type' => $key->type,
            'locale' => $key->locale->code,
            'slug' => $key->slug,
        ]);

        $payload = $stmt->fetchColumn();
        if (!is_string($payload)) {
            return null;
        }

        return $this->decode($payload, $key);
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
        // Loud on the write path: storing under a key the other backend could
        // never represent is a bug or an attack, not a miss.
        ContentKeyRules::assertSafe($content->key);

        $payload = json_encode(
            $content->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $stmt = $this->pdo()->prepare(
            'INSERT INTO content (type, locale, slug, payload, updated_at)
             VALUES (:type, :locale, :slug, :payload, :updated_at)
             ON CONFLICT(type, locale, slug) DO UPDATE SET
                 payload = excluded.payload,
                 updated_at = excluded.updated_at'
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
        $stmt->execute([
            'type' => $key->type,
            'locale' => $key->locale->code,
            'slug' => $key->slug,
        ]);

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
        $stmt->execute([
            'type' => $key->type,
            'locale' => $key->locale->code,
            'slug' => $key->slug,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /** A corrupt row must not break a listing, so it reads as absent. */
    private function decode(string $payload, ContentKey $key): ?Content
    {
        $row = json_decode($payload, true);
        if (!is_array($row)) {
            return null;
        }

        // The row's own coordinates decide what it is: a payload written before
        // languages carries a key with no locale in it.
        $row['key'] = $key->toString();

        return Content::fromArray($row);
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dir = dirname($this->databasePath);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create database directory: {$dir}");
        }

        try {
            $pdo = new PDO('sqlite:' . $this->databasePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Unable to open SQLite database at {$this->databasePath}: " . $e->getMessage(),
                previous: $e
            );
        }

        // WAL lets reads proceed during a write, which matters as soon as more
        // than one request is in flight.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS content (
                type       TEXT NOT NULL,
                locale     TEXT NOT NULL DEFAULT \'' . $this->defaultLocale->code . '\',
                slug       TEXT NOT NULL,
                payload    TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (type, locale, slug)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS content_type_idx ON content (type, locale)');

        $this->addLocaleColumnIfMissing($pdo);

        return $this->pdo = $pdo;
    }

    /**
     * Bring a pre-languages database up to the current shape.
     *
     * A table created before this change has no locale column and a primary key
     * of (type, slug), so `CREATE TABLE IF NOT EXISTS` above leaves it alone and
     * every query would fail on a column that is not there. Adding the column
     * with a default puts the existing rows in the site's default language,
     * which is what they are. The old primary key stays — SQLite cannot change
     * one in place, and (type, slug) merely constrains more than it needs to
     * until the table is rebuilt.
     */
    private function addLocaleColumnIfMissing(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(content)')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as $column) {
            if (($column['name'] ?? null) === 'locale') {
                return;
            }
        }

        $pdo->exec(
            'ALTER TABLE content ADD COLUMN locale TEXT NOT NULL DEFAULT \''
            . $this->defaultLocale->code . '\''
        );
    }
}
