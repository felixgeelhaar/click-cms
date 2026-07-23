<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Schema;

/**
 * The outcome of validating editor input against a section type.
 *
 * Carries every error at once, keyed by field name, so a form can highlight all
 * of them in one pass instead of revealing them one save at a time.
 */
final class ValidationResult
{
    /**
     * @param array<string, mixed>  $values Normalised values, schema fields only.
     * @param array<string, string> $errors Field name => message.
     */
    public function __construct(
        public readonly array $values,
        public readonly array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function errorFor(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
