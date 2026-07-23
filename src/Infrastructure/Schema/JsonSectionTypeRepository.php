<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Schema;

use Click\Cms\Domain\Schema\SectionType;
use Click\Cms\Domain\Schema\SectionTypeRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Loads section types from a directory of JSON files, one type per file.
 *
 *   {dir}/hero.json, {dir}/feature-grid.json, ...
 *
 * A file per type keeps definitions reviewable in isolation and lets a site add
 * or remove one by adding or removing a file — no central registry to edit and
 * no merge conflicts when two people add a type at once.
 *
 * Loading is lazy and cached for the request: the admin UI asks for the list on
 * nearly every screen, and re-reading the directory each time would be wasteful.
 */
final class JsonSectionTypeRepository implements SectionTypeRepository
{
    /** @var array<string, SectionType>|null */
    private ?array $types = null;

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private readonly string $directory) {}

    public function all(): array
    {
        return array_values($this->load());
    }

    public function find(string $id): ?SectionType
    {
        return $this->load()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->load()[$id]);
    }

    public function errors(): array
    {
        // Loading is what populates the errors, so make sure it has happened.
        $this->load();

        return $this->errors;
    }

    /**
     * @return array<string, SectionType>
     */
    private function load(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $this->types = [];
        $this->errors = [];

        if (!is_dir($this->directory)) {
            // No directory simply means the site has declared nothing yet. That
            // is a legitimate state for a fresh install, not an error.
            return $this->types;
        }

        $files = glob($this->directory . '/*.json') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $name = basename($file);

            $raw = @file_get_contents($file);
            if ($raw === false) {
                $this->errors[$name] = 'Could not be read.';
                continue;
            }

            try {
                $spec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->errors[$name] = 'Invalid JSON: ' . $e->getMessage();
                continue;
            }

            if (!is_array($spec)) {
                $this->errors[$name] = 'Expected a JSON object.';
                continue;
            }

            // Default the id to the filename so a definition cannot silently
            // disagree with the file it lives in.
            $spec['id'] ??= basename($file, '.json');

            try {
                $type = SectionType::fromArray($spec);
            } catch (InvalidArgumentException $e) {
                $this->errors[$name] = $e->getMessage();
                continue;
            }

            if (isset($this->types[$type->id])) {
                $this->errors[$name] = "Duplicate section type id \"{$type->id}\".";
                continue;
            }

            $this->types[$type->id] = $type;
        }

        return $this->types;
    }
}
