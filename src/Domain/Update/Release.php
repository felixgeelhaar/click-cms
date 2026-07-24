<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Update;

/**
 * One published release, as described by a signed release feed.
 *
 * The feed is signed as a whole and each package carries a SHA-256, so nothing
 * here is trusted on its own — this object only models what a release *claims*.
 * Verification happens where the bytes are fetched.
 */
final class Release
{
    private function __construct(
        public readonly SemanticVersion $version,
        public readonly string $packageUrl,
        public readonly string $sha256,
        /** Whether this release fixes a vulnerability, which the policy weighs. */
        public readonly bool $security,
        public readonly string $notes,
        /** Minimum PHP this release runs on, so a site is never told to install something it cannot run. */
        public readonly string $requiresPhp,
    ) {
    }

    /**
     * Parse one entry of a release feed. Null when it is malformed or could not
     * be installed anyway — a release with no package, no checksum or an
     * unreadable version is not an offer, and silently skipping it is better
     * than surfacing an update that cannot be applied.
     *
     * @param array<string, mixed> $entry
     */
    public static function fromArray(array $entry): ?self
    {
        $version = SemanticVersion::tryFromString((string) ($entry['version'] ?? ''));
        $packageUrl = trim((string) ($entry['packageUrl'] ?? ''));
        $sha256 = strtolower(trim((string) ($entry['sha256'] ?? '')));

        if ($version === null || $packageUrl === '' || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return null;
        }

        // Only http(s). A feed naming a file:// or php:// package would otherwise
        // make the updater read whatever the URL pointed at.
        $scheme = strtolower((string) parse_url($packageUrl, PHP_URL_SCHEME));
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }

        return new self(
            version: $version,
            packageUrl: $packageUrl,
            sha256: $sha256,
            security: (bool) ($entry['security'] ?? false),
            notes: trim((string) ($entry['notes'] ?? '')),
            requiresPhp: trim((string) ($entry['requiresPhp'] ?? '')),
        );
    }

    /** Whether the running PHP satisfies this release's floor. */
    public function runsOn(string $phpVersion): bool
    {
        if ($this->requiresPhp === '') {
            return true;
        }

        return version_compare($phpVersion, $this->requiresPhp, '>=');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version->toString(),
            'security' => $this->security,
            'notes' => $this->notes,
            'requiresPhp' => $this->requiresPhp,
        ];
    }
}
