<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Schema;

use RuntimeException;

/** A private, file-locked and atomically replaced per-site section preset store. */
final class JsonSectionPresetRepository
{
    private const MAX_VERSIONS = 50;
    private const MAX_SECTIONS = 100;
    private readonly string $file;
    private readonly string $lockFile;

    public function __construct(string $siteRoot)
    {
        if (is_link($siteRoot)) throw new RuntimeException('The site root path is unsafe for private preset storage.');
        $root = realpath($siteRoot);
        if ($root === false || !is_dir($root)) throw new RuntimeException('The site root is unavailable for private preset storage.');
        $directory = $root . '/data';
        if (is_link($directory)) throw new RuntimeException('The site data directory is unsafe for private preset storage.');
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the section preset storage directory.');
        }
        if (realpath($directory) !== $directory) throw new RuntimeException('The site data directory escapes the site root.');
        $this->file = $directory . '/section-presets.json';
        $this->lockFile = $directory . '/section-presets.lock';
        if (is_link($this->file) || is_link($this->lockFile)) throw new RuntimeException('The preset file path is unsafe.');
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->locked(LOCK_SH, function (): array {
            $state = $this->read();
            $records = array_values(array_map($this->present(...), $state['presets']));
            usort($records, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            return $records;
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->locked(LOCK_SH, function () use ($id): ?array {
            $record = $this->read()['presets'][$id] ?? null;
            return is_array($record) ? $this->present($record) : null;
        });
    }

    /** @return array<string, mixed>|null */
    public function version(string $id, int $version): ?array
    {
        return $this->locked(LOCK_SH, function () use ($id, $version): ?array {
            $record = $this->read()['presets'][$id] ?? null;
            if (!is_array($record)) return null;
            foreach ($record['versions'] as $snapshot) {
                if (($snapshot['version'] ?? null) === $version) {
                    return [
                        'id' => $id,
                        'name' => $record['name'],
                        'version' => $version,
                        'currentVersion' => $record['currentVersion'],
                        'sections' => $snapshot['sections'],
                        'updatedAt' => $snapshot['updatedAt'],
                        'updatedBy' => $snapshot['updatedBy'],
                    ];
                }
            }
            return null;
        });
    }

    /** @param list<array{type:string,values:array<string,mixed>}> $sections
     * @return array<string,mixed>
     */
    public function create(string $name, array $sections, string $username): array
    {
        return $this->locked(LOCK_EX, function () use ($name, $sections, $username): array {
            $state = $this->read();
            foreach ($state['presets'] as $record) {
                if ($this->foldName($record['name']) === $this->foldName($name)) {
                    throw new SectionPresetNameConflict($record['id']);
                }
            }
            $id = bin2hex(random_bytes(16));
            $now = gmdate(DATE_ATOM);
            $record = [
                'id' => $id,
                'name' => $name,
                'currentVersion' => 1,
                'versions' => [[
                    'version' => 1,
                    'sections' => $sections,
                    'updatedAt' => $now,
                    'updatedBy' => $username,
                ]],
            ];
            $state['revision']++;
            $state['presets'][$id] = $record;
            $this->write($state);
            return $this->present($record);
        });
    }

    /** @param list<array{type:string,values:array<string,mixed>}> $sections
     * @return array<string,mixed>
     */
    public function update(string $id, int $expectedVersion, string $name, array $sections, string $username): array
    {
        return $this->locked(LOCK_EX, function () use ($id, $expectedVersion, $name, $sections, $username): array {
            $state = $this->read();
            $record = $state['presets'][$id] ?? null;
            if (!is_array($record)) throw new SectionPresetNotFound();
            if ($record['currentVersion'] !== $expectedVersion) {
                throw new SectionPresetVersionConflict($record['currentVersion']);
            }
            if ($name !== $record['name']) {
                foreach ($state['presets'] as $other) {
                    if ($other['id'] !== $id && $this->foldName($other['name']) === $this->foldName($name)) {
                        throw new SectionPresetNameConflict($other['id']);
                    }
                }
            }
            $next = $expectedVersion + 1;
            $record['name'] = $name;
            $record['currentVersion'] = $next;
            $record['versions'][] = [
                'version' => $next,
                'sections' => $sections,
                'updatedAt' => gmdate(DATE_ATOM),
                'updatedBy' => $username,
            ];
            if (count($record['versions']) > self::MAX_VERSIONS) {
                $record['versions'] = array_slice($record['versions'], -self::MAX_VERSIONS);
            }
            $state['revision']++;
            $state['presets'][$id] = $record;
            $this->write($state);
            return $this->present($record);
        });
    }

    public function delete(string $id, int $expectedVersion): bool
    {
        return $this->locked(LOCK_EX, function () use ($id, $expectedVersion): bool {
            $state = $this->read();
            $record = $state['presets'][$id] ?? null;
            if (!is_array($record)) return false;
            if ($record['currentVersion'] !== $expectedVersion) {
                throw new SectionPresetVersionConflict($record['currentVersion']);
            }
            unset($state['presets'][$id]);
            $state['revision']++;
            $this->write($state);
            return true;
        });
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function present(array $record): array
    {
        $current = $record['versions'][array_key_last($record['versions'])];
        return [
            'id' => $record['id'],
            'name' => $record['name'],
            'currentVersion' => $record['currentVersion'],
            'sections' => $current['sections'],
            'versions' => array_map(static fn (array $snapshot): array => [
                'version' => $snapshot['version'],
                'updatedAt' => $snapshot['updatedAt'],
                'updatedBy' => $snapshot['updatedBy'],
            ], $record['versions']),
        ];
    }

    /** @return array{schema:int,revision:int,presets:array<string,array<string,mixed>>} */
    private function read(): array
    {
        if (!is_file($this->file)) return ['schema' => 1, 'revision' => 0, 'presets' => []];
        $raw = @file_get_contents($this->file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || ($decoded['schema'] ?? null) !== 1 || !is_array($decoded['presets'] ?? null)) {
            throw new RuntimeException('The section preset store is unreadable; no changes were made.');
        }
        foreach ($decoded['presets'] as $id => $record) {
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/', $id) !== 1
                || !is_array($record) || ($record['id'] ?? null) !== $id
                || !is_string($record['name'] ?? null) || trim($record['name']) === ''
                || !is_int($record['currentVersion'] ?? null) || $record['currentVersion'] < 1
                || !is_array($record['versions'] ?? null) || !array_is_list($record['versions'])
                || $record['versions'] === [] || count($record['versions']) > self::MAX_VERSIONS) {
                throw new RuntimeException('The section preset store is unreadable; no changes were made.');
            }
            $previous = 0;
            foreach ($record['versions'] as $snapshot) {
                if (!is_array($snapshot) || !is_int($snapshot['version'] ?? null)
                    || $snapshot['version'] <= $previous || !is_array($snapshot['sections'] ?? null)
                    || !array_is_list($snapshot['sections']) || !is_string($snapshot['updatedAt'] ?? null)
                    || !is_string($snapshot['updatedBy'] ?? null)) {
                    throw new RuntimeException('The section preset store is unreadable; no changes were made.');
                }
                if ($snapshot['sections'] === [] || count($snapshot['sections']) > self::MAX_SECTIONS) {
                    throw new RuntimeException('The section preset store is unreadable; no changes were made.');
                }
                foreach ($snapshot['sections'] as $section) {
                    if (!is_array($section) || !is_string($section['type'] ?? null)
                        || strlen($section['type']) > 100 || preg_match('/^[a-z][a-z0-9-]*$/', $section['type']) !== 1
                        || !is_array($section['values'] ?? null)
                        || ($section['values'] !== [] && array_is_list($section['values']))
                        || count($section['values']) > 200) {
                        throw new RuntimeException('The section preset store is unreadable; no changes were made.');
                    }
                }
                $previous = $snapshot['version'];
            }
            if ($previous !== $record['currentVersion']) {
                throw new RuntimeException('The section preset store is unreadable; no changes were made.');
            }
        }
        return [
            'schema' => 1,
            'revision' => is_int($decoded['revision'] ?? null) ? $decoded['revision'] : 0,
            'presets' => $decoded['presets'],
        ];
    }

    /** @param array<string,mixed> $state */
    private function write(array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        // tempnam creates the file with restrictive permissions. Set the mode
        // before writing content so page copy cannot be read during the window
        // before the atomic rename.
        $temporary = @tempnam(dirname($this->file), '.section-presets-');
        if ($temporary === false || dirname($temporary) !== dirname($this->file) || !@chmod($temporary, 0600)
            || @file_put_contents($temporary, $json, LOCK_EX) === false
            || !@rename($temporary, $this->file)) {
            if (is_string($temporary)) @unlink($temporary);
            throw new RuntimeException('Could not atomically save section presets.');
        }
        @chmod($this->file, 0600);
    }

    private function locked(int $mode, callable $callback): mixed
    {
        $lock = @fopen($this->lockFile, 'c');
        if ($lock === false) throw new RuntimeException('Could not open the section preset lock.');
        @chmod($this->lockFile, 0600);
        try {
            if (!flock($lock, $mode)) throw new RuntimeException('Could not lock the section preset store.');
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function foldName(string $name): string
    {
        // mbstring is optional in supported PHP installations; ASCII case
        // folding still prevents the ordinary duplicate-name mistake.
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }
}

final class SectionPresetNotFound extends RuntimeException {}

final class SectionPresetVersionConflict extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('This preset has a newer version. Reload it before saving.');
    }
}

final class SectionPresetNameConflict extends RuntimeException
{
    public function __construct(public readonly string $existingId)
    {
        parent::__construct('A shared preset with this name already exists.');
    }
}
