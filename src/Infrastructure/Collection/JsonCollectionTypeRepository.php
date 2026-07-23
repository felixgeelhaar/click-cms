<?php

declare(strict_types=1);

namespace Click\Cms\Infrastructure\Collection;

use Click\Cms\Domain\Collection\CollectionType;
use Click\Cms\Domain\Collection\CollectionTypeRepository;
use InvalidArgumentException;
use JsonException;

/**
 * Loads collection types from a directory of JSON files, one type per file.
 *
 *   {dir}/post.json, {dir}/team-member.json, ...
 *
 * A file per type keeps each definition reviewable on its own and lets a site
 * add or drop a collection by adding or removing a file — no registry to edit,
 * no merge conflict when two people add a type at once. Loading is lazy and
 * cached for the request; a malformed file is recorded as an error rather than
 * thrown, so one bad definition cannot take the admin UI down.
 */
final class JsonCollectionTypeRepository implements CollectionTypeRepository
{
    /** @var array<string, CollectionType>|null */
    private ?array $types = null;

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private readonly string $directory) {}

    public function all(): array
    {
        return array_values($this->load());
    }

    public function find(string $id): ?CollectionType
    {
        return $this->load()[$id] ?? null;
    }

    public function errors(): array
    {
        $this->load();

        return $this->errors;
    }

    /**
     * @return array<string, CollectionType>
     */
    private function load(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $this->types = [];
        $this->errors = [];

        if (!is_dir($this->directory)) {
            // A fresh install may declare no collections at all; that is a state,
            // not a fault.
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

            // The id defaults to the filename, so a definition cannot silently
            // disagree with the file it lives in.
            $spec['id'] ??= basename($file, '.json');

            try {
                $type = CollectionType::fromArray($spec);
            } catch (InvalidArgumentException $e) {
                $this->errors[$name] = $e->getMessage();
                continue;
            }

            if ($type->id === '') {
                $this->errors[$name] = 'A collection type needs an id.';
                continue;
            }

            $this->types[$type->id] = $type;
        }

        return $this->types;
    }
}
