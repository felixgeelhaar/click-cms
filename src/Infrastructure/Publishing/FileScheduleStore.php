<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Publishing;

use Click\Cms\Domain\Publishing\PublicationSchedule;
use Click\Cms\Domain\Publishing\ScheduledDocument;
use Click\Cms\Domain\Publishing\ScheduleStore;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Infrastructure\Storage\ContentKeyRules;
use DateTimeImmutable;
use RuntimeException;

/**
 * Schedules as one small JSON file per document, under `data/schedule/`.
 *
 * `data/` rather than `content/` because a schedule is operational state, not
 * content: it is not versioned, not translated as a unit, not exported by a
 * backup of the site's writing, and not something a storage migration should
 * carry between backends. It sits beside sessions, history and the audit trail,
 * which are the other things in that category.
 *
 * The layout mirrors content — `<type>/<locale>/<slug>.json` — so a person
 * looking for the schedule of `page:de:about` finds it where they would guess,
 * and so `due()` can recover a full {@see ContentKey} from a path without
 * keeping a second index that could drift from the files.
 *
 * There is no index and no sort. A site with a schedule on every one of a
 * thousand pages costs a thousand small reads per sweep, which is a rounding
 * error next to the page renders that site is already doing, and it removes the
 * failure mode where an index and the files disagree about what is pending.
 */
final class FileScheduleStore implements ScheduleStore
{
    public function __construct(private readonly string $root) {}

    public function find(ContentKey $key): PublicationSchedule
    {
        if (!ContentKeyRules::isSafe($key)) {
            // A read of an impossible key is an ordinary miss, never an error:
            // these keys arrive from URLs, and throwing would turn a stray
            // request into a 500. The same asymmetry `ContentKeyRules` draws.
            return PublicationSchedule::none();
        }

        return $this->read($this->pathFor($key))?->schedule ?? PublicationSchedule::none();
    }

    public function scheduledBy(ContentKey $key): ?string
    {
        if (!ContentKeyRules::isSafe($key)) {
            return null;
        }

        return $this->read($this->pathFor($key))?->scheduledBy;
    }

    public function save(ContentKey $key, PublicationSchedule $schedule, ?string $scheduledBy = null): void
    {
        ContentKeyRules::assertSafe($key);

        // An empty schedule is the absence of one. Writing `{"publishAt":null}`
        // would leave a file that `due()` has to open and reject on every sweep
        // for ever, and an admin listing has to learn to filter out.
        if ($schedule->isEmpty()) {
            $this->clear($key);

            return;
        }

        $path = $this->pathFor($key);
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create schedule directory: {$dir}");
        }

        // A caller that only moves a time keeps the attribution already on
        // disk: adjusting a date is not the same as claiming authorship of the
        // schedule, and erasing the original would lose the one name the audit
        // trail is going to want when the sweep finally fires.
        $scheduledBy ??= $this->read($path)?->scheduledBy;

        $json = json_encode(
            $schedule->toArray() + ['scheduledBy' => $scheduledBy],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        // Write-then-rename, as content storage does: a sweep running
        // concurrently with an editor saving a new schedule must see one or the
        // other, never half a file.
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write schedule file: {$path}");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to commit schedule file: {$path}");
        }
    }

    public function clear(ContentKey $key): void
    {
        if (!ContentKeyRules::isSafe($key)) {
            return;
        }

        @unlink($this->pathFor($key));
    }

    public function due(DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ScheduledDocument $item): bool => $item->schedule->actionDueAt($now) !== null
        ));
    }

    public function all(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $found = [];

        foreach ($this->files() as $path) {
            $key = $this->keyFor($path);
            if ($key === null) {
                continue;
            }

            $stored = $this->read($path);

            // A file that holds nothing usable is skipped, not thrown over. The
            // sweeper runs unattended across the whole site; one bad write must
            // not be able to stop every other page from being published.
            if ($stored === null || $stored->schedule->isEmpty()) {
                continue;
            }

            $found[] = new ScheduledDocument($key, $stored->schedule, $stored->scheduledBy);
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $paths = glob($this->root . '/*/*/*.json');

        return $paths === false ? [] : array_values($paths);
    }

    /**
     * One stored file: the schedule and who set it.
     *
     * Returned as a pair rather than two reads of the same file, because both
     * callers that want the author want the schedule as well and a second
     * `file_get_contents` for one string is a wasted syscall per document per
     * sweep.
     */
    private function read(string $path): ?StoredSchedule
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $by = $decoded['scheduledBy'] ?? null;

        return new StoredSchedule(
            PublicationSchedule::fromArray($decoded),
            is_string($by) && $by !== '' ? $by : null,
        );
    }

    /**
     * Recover the document's key from where its schedule sits.
     *
     * Returns null for anything whose path does not decode to a legal key — a
     * stray file, a leftover `.tmp` from an interrupted write, a directory a
     * person created by hand.
     */
    private function keyFor(string $path): ?ContentKey
    {
        $relative = substr($path, strlen($this->root) + 1);
        $parts = explode('/', $relative);

        if (count($parts) !== 3) {
            return null;
        }

        [$type, $localeCode, $file] = $parts;
        $slug = substr($file, 0, -strlen('.json'));

        if (!ContentKeyRules::isSafeSegment($type) || !ContentKeyRules::isSafeSegment($slug)) {
            return null;
        }

        $locale = Locale::tryFromString($localeCode);
        if ($locale === null) {
            return null;
        }

        return ContentKey::for($type, $slug, $locale);
    }

    private function pathFor(ContentKey $key): string
    {
        return "{$this->root}/{$key->type}/{$key->locale->code}/{$key->slug}.json";
    }
}

/**
 * What one schedule file holds, once read.
 *
 * File-private to this backend: the attribution is stored alongside the
 * schedule here, but that is this store's layout decision, not part of the
 * domain's idea of a schedule.
 *
 * @internal
 */
final class StoredSchedule
{
    public function __construct(
        public readonly PublicationSchedule $schedule,
        public readonly ?string $scheduledBy,
    ) {}
}
