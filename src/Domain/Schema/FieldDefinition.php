<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Schema;

use InvalidArgumentException;

/**
 * One field within a section type.
 *
 * A developer declares these; an editor fills them in. The editor can never add,
 * remove or retype a field, which is what keeps a site's design intact no matter
 * what is typed into it.
 */
final class FieldDefinition
{
    /**
     * @param list<string>          $options    Allowed values, for Select fields.
     * @param list<FieldDefinition> $fields     Sub-fields, for Repeater fields.
     * @param ?string               $labelField The field that supplies this one's human
     *                                          wording. On a Url field that is the link's
     *                                          text; on an Image field it is the picture's
     *                                          description. Either way the pair is one
     *                                          thing to a reader and two fields to an
     *                                          editor, and without the declaration the
     *                                          renderer prints both — a raw address beside
     *                                          a stray label, or an image description
     *                                          repeated as a paragraph under the picture
     *                                          it was written to replace.
     * @param ?int                  $displayWidth For Image fields: the width in CSS
     *                                          pixels the section shows the image at.
     *                                          A card in a four-column grid and a
     *                                          full-bleed header need very different
     *                                          sources, and only the section type knows
     *                                          which this is. Declared, it turns a vague
     *                                          "this might be small" into arithmetic:
     *                                          the same 1022-pixel file is fine in the
     *                                          card and wrong in the header.
     */
    private function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly string $label,
        public readonly bool $required,
        public readonly ?string $help,
        public readonly mixed $default,
        public readonly array $options,
        public readonly array $fields,
        public readonly ?int $min,
        public readonly ?int $max,
        public readonly ?string $labelField,
        public readonly ?int $displayWidth,
        /**
         * For a Reference field: what it points at — a collection type id, or
         * 'page'. Null on every other type.
         */
        public readonly ?string $references = null,
        /**
         * For a Reference field: whether it links to many items (stored as a list
         * of slugs) rather than one. False on every other type.
         */
        public readonly bool $multiple = false,

        /**
         * What this field *is*, when the default reading of its name and type is
         * wrong.
         *
         * A deliberately small vocabulary of roles rather than an HTML element
         * name. A design says `"as": "quote"` and the renderer decides that means
         * a `<blockquote>`; it does not get to ask for a `<div onclick=…>`. That
         * keeps every guarantee about escaping and structure inside the renderer,
         * where it can be tested once, instead of distributing it across every
         * section design anyone ever writes.
         *
         * It exists because element choice was previously derived entirely from a
         * field's name and type: a field called `heading` or `title` produced an
         * `<h2>`, every other scalar a `<p>`, every repeater a `<ul>`. That is a
         * reasonable default and a poor ceiling — it made a testimonial
         * impossible to mark up as a quotation, opening hours impossible to mark
         * up as a description list, and left long pages with a flat heading
         * outline because nothing could ever be an `<h3>`.
         *
         * Null means the default reading, so every design written before this
         * renders byte-identically.
         */
        public readonly ?string $as = null,
    ) {}

    /**
     * The roles each field type may take, and nothing else.
     *
     * @var array<string, list<string>>
     */
    private const ROLES = [
        // Prose and scalars: how prominently, or as a quotation.
        'text' => ['heading', 'subheading', 'quote', 'note'],
        'textarea' => ['quote', 'note'],
        'richtext' => ['quote', 'note'],
        // A repeater's shape: a plain list, a numbered one, or term-and-definition
        // pairs — which is the correct markup for opening hours or a spec table
        // and was previously unreachable.
        'repeater' => ['ordered', 'definitions'],
    ];

    /** The declared role, or null when it is absent, unknown, or wrong for the type. */
    private static function roleFor(FieldType $type, mixed $declared): ?string
    {
        if (!is_string($declared) || $declared === '') {
            return null;
        }

        $allowed = self::ROLES[$type->value] ?? [];

        return in_array($declared, $allowed, true) ? $declared : null;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function fromArray(array $spec): self
    {
        $name = $spec['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('Field is missing a "name".');
        }
        $name = trim($name);

        // Names become object keys in stored content and in the API payload.
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                "Field name \"{$name}\" must start with a letter and contain only letters, digits and underscores."
            );
        }

        $rawType = $spec['type'] ?? null;
        if (!is_string($rawType)) {
            throw new InvalidArgumentException("Field \"{$name}\" is missing a \"type\".");
        }

        $type = FieldType::tryFromName($rawType);
        if ($type === null) {
            $known = implode(', ', array_column(FieldType::cases(), 'value'));
            throw new InvalidArgumentException(
                "Field \"{$name}\" has unknown type \"{$rawType}\". Known types: {$known}."
            );
        }

        $options = [];
        if ($type === FieldType::Select) {
            $options = self::parseOptions($spec['options'] ?? null, $name);
        }

        $fields = [];
        if ($type === FieldType::Repeater) {
            $fields = self::parseSubFields($spec['fields'] ?? null, $name);
        }

        $references = null;
        if ($type === FieldType::Reference) {
            $references = $spec['references'] ?? null;
            if (!is_string($references) || trim($references) === '') {
                throw new InvalidArgumentException(
                    "Reference field \"{$name}\" must declare what it \"references\" (a collection id or \"page\")."
                );
            }
            $references = trim($references);
        }

        return new self(
            name: $name,
            type: $type,
            label: is_string($spec['label'] ?? null) && $spec['label'] !== ''
                ? $spec['label']
                : self::humanise($name),
            required: (bool) ($spec['required'] ?? false),
            help: is_string($spec['help'] ?? null) ? $spec['help'] : null,
            default: $spec['default'] ?? null,
            options: $options,
            fields: $fields,
            min: isset($spec['min']) ? (int) $spec['min'] : null,
            max: isset($spec['max']) ? (int) $spec['max'] : null,
            // On a File field it names the image to use as a video's poster
            // frame — the same idea as an image's description or a link's
            // wording: a sibling field supplying what this one needs, and
            // consumed so it is not also printed on its own.
            labelField: in_array($type, [FieldType::Url, FieldType::Image, FieldType::File], true)
                && is_string($spec['labelField'] ?? null)
                    ? trim($spec['labelField'])
                    : null,
            // Ignored on every other type: a display width means nothing for a
            // number or a date, and silently accepting it there would suggest
            // it does something.
            displayWidth: $type === FieldType::Image && isset($spec['displayWidth'])
                && (int) $spec['displayWidth'] > 0
                ? (int) $spec['displayWidth']
                : null,
            references: $references,
            multiple: $type === FieldType::Reference && (bool) ($spec['multiple'] ?? false),
            // Validated against what the renderer can actually draw, and against
            // the field's own type: `definitions` means nothing on a text field
            // and `quote` means nothing on a repeater. An unrecognised or
            // misapplied role is dropped rather than honoured, because rendering
            // a guess would be worse than rendering the default.
            as: self::roleFor($type, $spec['as'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'type' => $this->type->value,
            'label' => $this->label,
            'required' => $this->required,
        ];

        if ($this->help !== null) {
            $out['help'] = $this->help;
        }
        if ($this->default !== null) {
            $out['default'] = $this->default;
        }
        if ($this->options !== []) {
            $out['options'] = $this->options;
        }
        if ($this->fields !== []) {
            $out['fields'] = array_map(
                static fn (FieldDefinition $f): array => $f->toArray(),
                $this->fields
            );
        }
        if ($this->min !== null) {
            $out['min'] = $this->min;
        }
        if ($this->max !== null) {
            $out['max'] = $this->max;
        }
        if ($this->labelField !== null) {
            $out['labelField'] = $this->labelField;
        }
        if ($this->as !== null) {
            $out['as'] = $this->as;
        }
        if ($this->displayWidth !== null) {
            $out['displayWidth'] = $this->displayWidth;
        }
        if ($this->references !== null) {
            $out['references'] = $this->references;
        }
        if ($this->multiple) {
            $out['multiple'] = true;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function parseOptions(mixed $raw, string $name): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException(
                "Select field \"{$name}\" must declare a non-empty \"options\" list."
            );
        }

        $options = [];
        foreach ($raw as $option) {
            if (!is_string($option) && !is_int($option)) {
                throw new InvalidArgumentException(
                    "Select field \"{$name}\" has a non-scalar option."
                );
            }
            $options[] = (string) $option;
        }

        return $options;
    }

    /**
     * @return list<FieldDefinition>
     */
    private static function parseSubFields(mixed $raw, string $name): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException(
                "Repeater field \"{$name}\" must declare a non-empty \"fields\" list."
            );
        }

        $fields = [];
        $seen = [];

        foreach ($raw as $spec) {
            if (!is_array($spec)) {
                throw new InvalidArgumentException(
                    "Repeater field \"{$name}\" has a malformed sub-field."
                );
            }

            $field = self::fromArray($spec);

            // Nesting repeaters inside repeaters produces an editing experience
            // no non-technical person can follow, so one level is the limit.
            if ($field->type === FieldType::Repeater) {
                throw new InvalidArgumentException(
                    "Repeater field \"{$name}\" may not contain another repeater (\"{$field->name}\")."
                );
            }

            if (isset($seen[$field->name])) {
                throw new InvalidArgumentException(
                    "Repeater field \"{$name}\" declares \"{$field->name}\" twice."
                );
            }

            $seen[$field->name] = true;
            $fields[] = $field;
        }

        return $fields;
    }

    private static function humanise(string $name): string
    {
        return ucfirst(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $name)) ?? $name));
    }
}
