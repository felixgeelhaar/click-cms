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
     * @param ?string               $labelField For Url fields: the name of the field
     *                                          holding the link's text. A link and its
     *                                          wording are one control to a reader, but
     *                                          two fields to an editor; without this the
     *                                          renderer has no way to know they belong
     *                                          together and prints the raw address on
     *                                          the page next to a separate label.
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
    ) {}

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
            labelField: $type === FieldType::Url && is_string($spec['labelField'] ?? null)
                ? trim($spec['labelField'])
                : null,
            // Ignored on every other type: a display width means nothing for a
            // number or a date, and silently accepting it there would suggest
            // it does something.
            displayWidth: $type === FieldType::Image && isset($spec['displayWidth'])
                && (int) $spec['displayWidth'] > 0
                ? (int) $spec['displayWidth']
                : null,
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
        if ($this->displayWidth !== null) {
            $out['displayWidth'] = $this->displayWidth;
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
