<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity;

/**
 * An account's second factor: the shared secret, and the recovery codes that
 * stand in for a lost phone.
 *
 * Held as a value object rather than read out of the user document field by
 * field, because three of the rules here are easy to get wrong in a handler and
 * impossible to get wrong in one place:
 *
 * - **Enrolled is not the same as confirmed.** A secret exists from the moment
 *   the QR code is shown, and must not protect anything until the person has
 *   proved they can produce a code from it. Treating "has a secret" as "has
 *   two-factor" locks people out of their own accounts at the moment they turn
 *   it on, which is precisely when they have not yet finished setting it up.
 * - **Recovery codes are stored hashed**, never in clear: the user document is
 *   readable by anything that can read the content store, and a recovery code
 *   in clear is a password in clear.
 * - **A used recovery code is gone.** Single use is the whole property.
 *
 * ## Why SHA-256 and not `password_hash`
 *
 * A recovery code here is 100 bits of `random_bytes`, not something a person
 * chose. Slow hashing exists to make guessing a *low-entropy* secret expensive;
 * against 100 bits, an attacker with the stored hash and infinite time is not
 * going to arrive, and making each verification take a quarter of a second buys
 * nothing while making a login noticeably slower and the test suite absurdly so.
 * This is the same reasoning that applies to API tokens, and the opposite of the
 * reasoning for the account password next door — which is user-chosen, and does
 * use `password_hash`.
 */
final class TwoFactorEnrolment
{
    /**
     * @param list<string> $recoveryCodeHashes
     */
    private function __construct(
        public readonly ?string $secret,
        public readonly ?string $confirmedAt,
        public readonly array $recoveryCodeHashes,
    ) {}

    public static function none(): self
    {
        return new self(null, null, []);
    }

    /**
     * A secret has been issued but not yet proved. Nothing is protected yet.
     *
     * @param list<string> $recoveryCodeHashes
     */
    public static function pending(string $secret, array $recoveryCodeHashes): self
    {
        return new self($secret, null, $recoveryCodeHashes);
    }

    /** The person produced a valid code, so the second factor is now in force. */
    public function confirmed(string $at): self
    {
        return new self($this->secret, $at, $this->recoveryCodeHashes);
    }

    /**
     * Whether this account must present a second factor to sign in.
     *
     * The one question every caller actually has, and the reason this class
     * exists: `secret !== null` is the tempting way to ask it and is wrong for
     * the whole of enrolment.
     */
    public function isActive(): bool
    {
        return $this->secret !== null && $this->confirmedAt !== null;
    }

    public function isPending(): bool
    {
        return $this->secret !== null && $this->confirmedAt === null;
    }

    /**
     * Spend a recovery code, returning the enrolment without it — or null when
     * the code is not one of the unused ones.
     *
     * Every stored hash is checked even after a match is found. Stopping early
     * would make the response time depend on which code was used, and more
     * importantly on *whether* one was, which is the thing being kept quiet.
     */
    public function withoutRecoveryCode(string $candidate): ?self
    {
        $wanted = self::hashRecoveryCode($candidate);
        $matchedIndex = null;

        foreach ($this->recoveryCodeHashes as $index => $hash) {
            if (hash_equals($hash, $wanted)) {
                $matchedIndex = $index;
            }
        }

        if ($matchedIndex === null) {
            return null;
        }

        $remaining = $this->recoveryCodeHashes;
        unset($remaining[$matchedIndex]);

        return new self($this->secret, $this->confirmedAt, array_values($remaining));
    }

    public function unusedRecoveryCodeCount(): int
    {
        return count($this->recoveryCodeHashes);
    }

    /**
     * How a recovery code is stored. Normalised first, so a person who types
     * the code back in upper case, or without the dash it was shown with, is
     * not told it is wrong.
     */
    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower(preg_replace('/[\s-]+/', '', $code) ?? ''));
    }

    /**
     * @param array<string, mixed> $row The `twoFactor` block of a user document.
     */
    public static function fromArray(?array $row): self
    {
        if ($row === null) {
            return self::none();
        }

        $secret = $row['secret'] ?? null;
        $confirmedAt = $row['confirmedAt'] ?? null;
        $codes = $row['recoveryCodes'] ?? [];

        return new self(
            is_string($secret) && $secret !== '' ? $secret : null,
            is_string($confirmedAt) && $confirmedAt !== '' ? $confirmedAt : null,
            is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'secret' => $this->secret,
            'confirmedAt' => $this->confirmedAt,
            'recoveryCodes' => $this->recoveryCodeHashes,
        ];
    }
}
