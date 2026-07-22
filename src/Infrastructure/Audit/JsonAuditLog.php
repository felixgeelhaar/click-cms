<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Audit;

use Click\Cms\Domain\Audit\AuditEntry;
use Click\Cms\Domain\Audit\AuditLogInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use RuntimeException;

/**
 * A flat-file audit trail: one append-only JSON-lines file under `data/`.
 *
 *   {auditDir}/audit.log
 *
 * One line per entry, each a complete JSON object. The format is chosen for the
 * one property that makes an audit trail one: it is written with a single
 * append, so a new entry cannot touch, reorder or truncate the ones already
 * there. A store that filed each document's history in its own rewritable file
 * would have every write reopen and rewrite something, and an audit log you can
 * silently rewrite is not audit — so this is deliberately the one store in the
 * system that never edits what it has written.
 *
 * That choice also disposes of a hazard the version store has to guard against
 * by hand. The version store derives a directory from the document key and must
 * prove every segment path-safe, because a crafted key would otherwise write
 * outside its tree. Here the path is fixed — `{auditDir}/audit.log`, the same
 * file for every document — and the key appears only as text inside a line,
 * never as a path segment. There is nothing derived from untrusted data to
 * traverse with, so there is nothing to escape.
 *
 * Lives under `data/`, beside sessions and versions, because the trail names
 * who did what and is no more meant to be served over HTTP than the session
 * files are — `data/` is already outside the web root and already expected to
 * be writable.
 */
final class JsonAuditLog implements AuditLogInterface
{
    private readonly string $file;

    public function __construct(private readonly string $auditDir)
    {
        $this->file = $auditDir . '/audit.log';
    }

    public function append(AuditEntry $entry): void
    {
        if (!is_dir($this->auditDir) && !@mkdir($this->auditDir, 0o775, true) && !is_dir($this->auditDir)) {
            throw new RuntimeException("Unable to create audit directory: {$this->auditDir}");
        }

        // One entry, one line. JSON encoding escapes any newline inside a value,
        // so a line break is always a record boundary and never content — which
        // is what lets a reader split on newlines and lets one corrupt line be
        // skipped without taking the rest of the trail with it.
        $line = json_encode(
            $entry->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        // A single locked append. Not a temp-file-and-rename like the version
        // store, because that pattern replaces a whole file and here the whole
        // point is to add to one without rewriting it. LOCK_EX serialises
        // concurrent writers so two requests in the same instant do not
        // interleave their bytes; the failure to write is allowed to propagate,
        // because a trail that has quietly stopped recording is the exact silent
        // degradation core exists to refuse.
        if (@file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Unable to append to the audit trail: {$this->file}");
        }
    }

    public function recent(int $limit): array
    {
        return $this->read($limit, null);
    }

    public function forDocument(ContentKey $key, int $limit): array
    {
        return $this->read($limit, $key->toString());
    }

    /**
     * Read the trail newest-first, optionally filtered to one document.
     *
     * The file is read whole and reversed. For a flat-file CMS the trail is
     * small and this stays honest and simple; a store that outgrew it would be
     * the SQLite backend's job, behind this same port, rather than a cleverer
     * file reader.
     *
     * @return list<AuditEntry>
     */
    private function read(int $limit, ?string $document): array
    {
        if ($limit <= 0 || !is_file($this->file)) {
            return [];
        }

        $lines = file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        // Reversed, because the file is appended oldest-first and an operator
        // reading an audit trail wants the latest events at the top.
        $out = [];
        foreach (array_reverse($lines) as $line) {
            $entry = $this->parse($line);
            if ($entry === null) {
                // One damaged line must not hide the rest, exactly as the
                // version store skips an unreadable snapshot: a trail that shows
                // what it can still read beats one that shows an error because a
                // single record is corrupt.
                continue;
            }

            if ($document !== null && $entry->document !== $document) {
                continue;
            }

            $out[] = $entry;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function parse(string $line): ?AuditEntry
    {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            return null;
        }

        try {
            return AuditEntry::fromArray($row);
        } catch (\Exception) {
            return null;
        }
    }
}
