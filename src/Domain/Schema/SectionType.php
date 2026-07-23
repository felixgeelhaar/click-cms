<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Schema;

use InvalidArgumentException;

/**
 * One kind of section a site offers its editors.
 *
 * The CMS ships no section types of its own: a site declares the ones its
 * design supports, and the editor chooses from exactly that list. That is the
 * whole point — the editor composes a page from designs someone built, and
 * cannot invent a layout that the site has no styling for.
 */
final class SectionType
{
    /**
     * @param list<FieldDefinition> $fields
     */
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $description,
        public readonly ?string $icon,
        public readonly array $fields,
    ) {}

    /**
     * @param array<string, mixed> $spec
     */
    public static function fromArray(array $spec): self
    {
        $id = $spec['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            throw new InvalidArgumentException('Section type is missing an "id".');
        }
        $id = trim($id);

        // The id appears in stored content and in URLs, so keep it slug-shaped.
        if (preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1) {
            throw new InvalidArgumentException(
                "Section type id \"{$id}\" must be lowercase letters, digits and dashes, starting with a letter."
            );
        }

        $rawFields = $spec['fields'] ?? null;
        if (!is_array($rawFields) || $rawFields === []) {
            throw new InvalidArgumentException(
                "Section type \"{$id}\" must declare a non-empty \"fields\" list."
            );
        }

        $fields = [];
        $seen = [];

        foreach ($rawFields as $fieldSpec) {
            if (!is_array($fieldSpec)) {
                throw new InvalidArgumentException("Section type \"{$id}\" has a malformed field.");
            }

            $field = FieldDefinition::fromArray($fieldSpec);

            if (isset($seen[$field->name])) {
                throw new InvalidArgumentException(
                    "Section type \"{$id}\" declares field \"{$field->name}\" twice."
                );
            }

            $seen[$field->name] = true;
            $fields[] = $field;
        }

        return new self(
            id: $id,
            label: is_string($spec['label'] ?? null) && $spec['label'] !== '' ? $spec['label'] : $id,
            description: is_string($spec['description'] ?? null) ? $spec['description'] : null,
            icon: is_string($spec['icon'] ?? null) ? $spec['icon'] : null,
            fields: $fields,
        );
    }

    public function field(string $name): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_map(static fn (FieldDefinition $f): string => $f->name, $this->fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'label' => $this->label,
            'fields' => array_map(
                static fn (FieldDefinition $f): array => $f->toArray(),
                $this->fields
            ),
        ];

        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->icon !== null) {
            $out['icon'] = $this->icon;
        }

        return $out;
    }
}
