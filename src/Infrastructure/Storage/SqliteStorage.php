<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Storage;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
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
 *
 * Key safety is enforced here exactly as the flat-file backend enforces it, even
 * though no path is ever built from a key. See {@see ContentKeyRules} for why
 * the legal key space must not depend on the backend in use.
 */
final class SqliteStorage implements StorageInterface
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $databasePath) {}

    public function find(ContentKey $key): ?Content
    {
        if (!ContentKeyRules::isSafe($key)) {
            return null;
        }

        $stmt = $this->pdo()->prepare(
            'SELECT payload FROM content WHERE type = :type AND slug = :slug LIMIT 1'
        );
        $stmt->execute(['type' => $key->type, 'slug' => $key->slug]);

        $payload = $stmt->fetchColumn();
        if (!is_string($payload)) {
            return null;
        }

        return $this->decode($payload, $key);
    }

    public function findByType(string $type): array
    {
        if (!ContentKeyRules::isSafeSegment($type)) {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT slug, payload FROM content WHERE type = :type ORDER BY slug ASC'
        );
        $stmt->execute(['type' => $type]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $content = $this->decode(
                (string) $row['payload'],
                ContentKey::fromString($type . ':' . $row['slug'])
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
            'INSERT INTO content (type, slug, payload, updated_at)
             VALUES (:type, :slug, :payload, :updated_at)
             ON CONFLICT(type, slug) DO UPDATE SET
                 payload = excluded.payload,
                 updated_at = excluded.updated_at'
        );

        $stmt->execute([
            'type' => $content->key->type,
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

        $stmt = $this->pdo()->prepare('DELETE FROM content WHERE type = :type AND slug = :slug');
        $stmt->execute(['type' => $key->type, 'slug' => $key->slug]);

        return $stmt->rowCount() > 0;
    }

    public function exists(ContentKey $key): bool
    {
        if (!ContentKeyRules::isSafe($key)) {
            return false;
        }

        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM content WHERE type = :type AND slug = :slug LIMIT 1'
        );
        $stmt->execute(['type' => $key->type, 'slug' => $key->slug]);

        return $stmt->fetchColumn() !== false;
    }

    /** A corrupt row must not break a listing, so it reads as absent. */
    private function decode(string $payload, ContentKey $key): ?Content
    {
        $row = json_decode($payload, true);
        if (!is_array($row)) {
            return null;
        }

        $row['key'] ??= $key->toString();

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
                slug       TEXT NOT NULL,
                payload    TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (type, slug)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS content_type_idx ON content (type)');

        return $this->pdo = $pdo;
    }
}
