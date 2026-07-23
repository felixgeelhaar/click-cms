<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Schema;

/**
 * Validates and normalises editor input against a section type.
 *
 * Two jobs, both deliberate:
 *
 *  - Reject what the schema does not allow, so a section can never hold a shape
 *    the site's templates were not written for.
 *  - Drop anything the schema does not mention, so a crafted request cannot
 *    smuggle extra keys into stored content.
 *
 * Errors are collected rather than thrown one at a time: an editor filling in a
 * form should see everything that is wrong at once, not one problem per save.
 */
final class SectionValidator
{
    /**
     * @param array<string, mixed> $input
     */
    public function validate(SectionType $type, array $input): ValidationResult
    {
        $errors = [];
        $values = [];

        foreach ($type->fields as $field) {
            $present = array_key_exists($field->name, $input);
            $raw = $present ? $input[$field->name] : $field->default;

            if ($this->isEmpty($raw)) {
                if ($field->required) {
                    $errors[$field->name] = "{$field->label} is required.";
                    continue;
                }
                // Absent optional fields are simply omitted rather than stored
                // as null, keeping saved documents free of empty noise.
                continue;
            }

            $result = $this->coerce($field, $raw);

            if ($result['error'] !== null) {
                $errors[$field->name] = $result['error'];
                continue;
            }

            $values[$field->name] = $result['value'];
        }

        return new ValidationResult($values, $errors);
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function coerce(FieldDefinition $field, mixed $raw): array
    {
        return match ($field->type) {
            FieldType::Text,
            FieldType::Textarea,
            FieldType::RichText => $this->coerceString($field, $raw),

            FieldType::Number => $this->coerceNumber($field, $raw),
            FieldType::Boolean => ['value' => (bool) $raw, 'error' => null],
            FieldType::Select => $this->coerceSelect($field, $raw),
            FieldType::Url => $this->coerceUrl($field, $raw),
            FieldType::Email => $this->coerceFiltered($field, $raw, FILTER_VALIDATE_EMAIL, 'a valid email address'),
            FieldType::Date => $this->coerceDate($field, $raw),
            FieldType::Image, FieldType::File => $this->coerceString($field, $raw),
            // A reference is stored as the referenced item's slug — a string.
            // Whether it still resolves is a read-time concern, not a validation
            // one: a target deleted after the reference was set must not make the
            // whole entry invalid to save.
            FieldType::Reference => $this->coerceString($field, $raw),
            FieldType::Repeater => $this->coerceRepeater($field, $raw),
        };
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function coerceString(FieldDefinition $field, mixed $raw): array
    {
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            return ['value' => null, 'error' => "{$field->label} must be text."];
        }

        $value = (string) $raw;

        if ($field->min !== null && mb_strlen($value) < $field->min) {
            return ['value' => null, 'error' => "{$field->label} must be at least {$field->min} characters."];
        }
        if ($field->max !== null && mb_strlen($value) > $field->max) {
            return ['value' => null, 'error' => "{$field->label} must be at most {$field->max} characters."];
        }

        return ['value' => $value, 'error' => null];
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function coerceNumber(FieldDefinition $field, mixed $raw): array
    {
        if (!is_numeric($raw)) {
            return ['value' => null, 'error' => "{$field->label} must be a number."];
        }

        $value = $raw + 0;

        if ($field->min !== null && $value < $field->min) {
            return ['value' => null, 'error' => "{$field->label} must be at least {$field->min}."];
        }
        if ($field->max !== null && $value > $field->max) {
            return ['value' => null, 'error' => "{$field->label} must be at most {$field->max}."];
        }

        return ['value' => $value, 'error' => null];
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function coerceSelect(FieldDefinition $field, mixed $raw): array
    {
        $value = is_scalar($raw) ? (string) $raw : null;

        if ($value === null || !in_array($value, $field->options, true)) {
            $allowed = implode(', ', $field->options);

            return ['value' => null, 'error' => "{$field->label} must be one of: {$allowed}."];
        }

        return ['value' => $value, 'error' => null];
    }

    /**
     * A link the editor points a button at. Two shapes are allowed, and nothing
     * else: a full http(s) URL for an off-site link, or an on-site absolute path
     * beginning with a single slash for one of their own pages — the common case,
     * which a bare FILTER_VALIDATE_URL would reject.
     *
     * The path branch is deliberately narrow because this value becomes an href.
     * A protocol-relative `//host` (an off-site jump wearing a path's clothes), a
     * `javascript:`/`data:` scheme, or a backslash used to fake a path are all
     * refused. It mirrors the same allow-a-path-or-an-http-URL rule the redirect
     * value object already enforces, so a link and a redirect cannot disagree
     * about what is safe.
     *
     * @return array{value: mixed, error: ?string}
     */
    private function coerceUrl(FieldDefinition $field, mixed $raw): array
    {
        if (is_string($raw) && str_starts_with($raw, '/') && !str_starts_with($raw, '//')
            && !str_contains($raw, '\\')) {
            return ['value' => $raw, 'error' => null];
        }

        if (is_string($raw)
            && (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://'))
            && filter_var($raw, FILTER_VALIDATE_URL) !== false) {
            return ['value' => $raw, 'error' => null];
        }

        return ['value' => null, 'error' => "{$field->label} must be a valid URL or an on-site path like /contact."];
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function coerceFiltered(FieldDefinition $field, mixed $raw, int $filter, string $expected): array
    {
        if (!is_string($raw) || filter_var($raw, $filter) === false) {
            return ['value' => null, 'error' => "{$field->label} must be {$expected}."];
        }

        return ['value' => $raw, 'error' => null];
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function coerceDate(FieldDefinition $field, mixed $raw): array
    {
        if (!is_string($raw)) {
            return ['value' => null, 'error' => "{$field->label} must be a date."];
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return ['value' => null, 'error' => "{$field->label} must be a date in YYYY-MM-DD format."];
        }

        return ['value' => $raw, 'error' => null];
    }

    /**
     * @return array{value: mixed, error: ?string}
     */
    private function coerceRepeater(FieldDefinition $field, mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return ['value' => null, 'error' => "{$field->label} must be a list of entries."];
        }

        if ($field->min !== null && count($raw) < $field->min) {
            return ['value' => null, 'error' => "{$field->label} needs at least {$field->min} entries."];
        }
        if ($field->max !== null && count($raw) > $field->max) {
            return ['value' => null, 'error' => "{$field->label} allows at most {$field->max} entries."];
        }

        // A repeater is a section type in miniature, so validate each row with
        // the same machinery rather than a parallel implementation.
        $rowType = SectionType::fromArray([
            'id' => 'row',
            'label' => $field->label,
            'fields' => array_map(
                static fn (FieldDefinition $f): array => $f->toArray(),
                $field->fields
            ),
        ]);

        $rows = [];
        foreach ($raw as $index => $row) {
            if (!is_array($row)) {
                return ['value' => null, 'error' => "{$field->label} entry " . ($index + 1) . ' is malformed.'];
            }

            $result = $this->validate($rowType, $row);

            if (!$result->isValid()) {
                // array_values() rather than reset(): reset() takes its argument
                // by reference, which is not allowed on a readonly property.
                $first = (string) (array_values($result->errors)[0] ?? 'is invalid.');

                return ['value' => null, 'error' => "{$field->label} entry " . ($index + 1) . ": {$first}"];
            }

            $rows[] = $result->values;
        }

        return ['value' => $rows, 'error' => null];
    }

    private function isEmpty(mixed $value): bool
    {
        // False and 0 are meaningful values, so only null, "" and [] count.
        return $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);
    }
}
