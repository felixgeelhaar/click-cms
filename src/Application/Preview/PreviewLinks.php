<?php

declare(strict_types=1);

namespace Click\Cms\Application\Preview;

use Click\Cms\Domain\Preview\PreviewToken;

/**
 * Issues and checks preview links.
 *
 * The signing secret lives here because reading it is I/O and the token itself
 * must stay free of any. It is generated on first use rather than configured:
 * a secret an administrator has to set is a secret that ships as "changeme" on
 * the sites that most need it to be random.
 *
 * Rotating the secret — deleting the file — invalidates every link that has
 * been handed out. That is the only revocation there is, since nothing about an
 * issued token is recorded anywhere.
 */
final class PreviewLinks
{
    private ?string $secret = null;

    public function __construct(
        private readonly string $secretPath,
        private readonly int $ttlSeconds = PreviewToken::DEFAULT_TTL_SECONDS,
    ) {}

    /**
     * A shareable link for one page, and when it stops working.
     *
     * @return array{path: string, token: string, expiresAt: int}|null
     *         Null when no secret could be established, because a link that
     *         cannot be verified later must not be handed out now.
     */
    public function issue(string $slug): ?array
    {
        $secret = $this->secret(create: true);
        if ($secret === '') {
            return null;
        }

        $token = PreviewToken::issue($secret, $slug, $this->ttlSeconds);

        return [
            'path' => '/preview/' . rawurlencode($slug) . '?token=' . rawurlencode($token),
            'token' => $token,
            'expiresAt' => (int) explode('.', $token, 2)[0],
        ];
    }

    /**
     * Whether a presented token permits this page.
     *
     * Never creates a secret. Verification that generated one on demand would
     * mint a fresh key for every unsigned request, which is wasted work at
     * best and, if the file were ever written between two requests, an
     * inconsistent answer.
     */
    public function accepts(string $slug, ?string $token): bool
    {
        return PreviewToken::accepts($this->secret(create: false), $slug, $token);
    }

    /**
     * The signing key, generated once and then reused.
     *
     * Written with restrictive permissions and outside the document root: it is
     * as sensitive as a session, because holding it means being able to mint a
     * link to any unpublished page.
     */
    private function secret(bool $create): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }

        if (is_file($this->secretPath)) {
            $stored = trim((string) @file_get_contents($this->secretPath));

            // A truncated or empty file is not a usable key. Treating it as one
            // would mean every request signed with the empty string.
            if (strlen($stored) >= 32) {
                return $this->secret = $stored;
            }
        }

        if (!$create) {
            return '';
        }

        $directory = dirname($this->secretPath);
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            return '';
        }

        $generated = bin2hex(random_bytes(32));

        if (@file_put_contents($this->secretPath, $generated, LOCK_EX) === false) {
            return '';
        }

        @chmod($this->secretPath, 0o600);

        return $this->secret = $generated;
    }
}
