<?php

declare(strict_types=1);

namespace Click\Cms\Application\Authentication;

/** Validate new password input before passing it to PHP's default hasher. */
final class PasswordInput
{
    public static function hashingError(string $password): ?string
    {
        // Bcrypt rejects NUL and silently ignores bytes beyond 72. Check bytes,
        // not Unicode characters, so every accepted character affects the hash.
        if (str_contains($password, "\0")) {
            return 'Password must not contain a null character.';
        }
        if (PASSWORD_DEFAULT === PASSWORD_BCRYPT && strlen($password) > 72) {
            return 'Password must be at most 72 bytes (some characters use more than one byte).';
        }

        return null;
    }
}
