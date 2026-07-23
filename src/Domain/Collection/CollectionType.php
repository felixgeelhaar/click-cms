<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Collection;

use Click\Cms\Domain\Schema\SectionType;

/**
 * A repeatable content type — a blog's posts, a team's members, a catalogue's
 * products — defined by the set of fields each of its entries carries.
 *
 * A collection type is, in schema terms, exactly a named set of fields, which is
 * what {@see SectionType} already models and {@see \Click\Cms\Domain\Schema\SectionValidator}
 * already validates. So rather than grow a parallel field system, a collection
 * type composes a SectionType for its fields and adds only what is collection-
 * specific: which field is the entry's display title (and the source of its
 * slug). Everything else — draft/publish, history, audit, per-owner permissions
 * — an entry inherits by being an ordinary content document whose type is this
 * collection's id.
 */
final class CollectionType
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $description,
        public readonly SectionType $schema,
        /** The field whose value titles an entry and seeds its slug. */
        public readonly string $titleField,
    ) {}

    /**
     * @param array<string, mixed> $spec
     */
    public static function fromArray(array $spec): self
    {
        $id = (string) ($spec['id'] ?? '');
        $label = (string) ($spec['label'] ?? $id);
        $description = isset($spec['description']) && is_string($spec['description'])
            ? $spec['description']
            : null;
        $titleField = isset($spec['titleField']) && is_string($spec['titleField']) && $spec['titleField'] !== ''
            ? $spec['titleField']
            : 'title';

        // The field set is parsed by the same machinery a section uses, so field
        // types, defaults and validation behave identically wherever they appear.
        $schema = SectionType::fromArray([
            'id' => $id,
            'label' => $label,
            'fields' => is_array($spec['fields'] ?? null) ? $spec['fields'] : [],
        ]);

        return new self($id, $label, $description, $schema, $titleField);
    }

    /**
     * @return list<\Click\Cms\Domain\Schema\FieldDefinition>
     */
    public function fields(): array
    {
        return $this->schema->fields;
    }

    /**
     * The display title for an entry's stored data — the value of the title
     * field, falling back to the slug so a listing is never blank.
     *
     * @param array<string, mixed> $data
     */
    public function titleOf(array $data): string
    {
        $value = $data[$this->titleField] ?? null;
        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return (string) ($data['slug'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'titleField' => $this->titleField,
            'fields' => array_map(
                static fn ($field): array => $field->toArray(),
                $this->schema->fields
            ),
        ];
    }
}
