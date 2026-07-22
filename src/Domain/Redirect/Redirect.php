<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Redirect;

use InvalidArgumentException;

/**
 * A single redirect rule: when a visitor asks for one path, send them to
 * another address instead.
 *
 * The destination is validated the same way a menu target is, and for the same
 * reason: a redirect that could point at `javascript:…` would turn a stored rule
 * into stored script, run in the site's own origin. So a destination is either
 * an on-site path (starting with `/`) or an http/https URL — nothing else.
 *
 * Pure: it validates and answers whether it matches a path. Where the rules are
 * stored and when they are consulted is someone else's concern.
 */
final class Redirect
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly bool $permanent,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $from = self::normalisePath((string) ($data['from'] ?? ''));
        if ($from === '') {
            throw new InvalidArgumentException('A redirect needs a "from" path.');
        }

        $to = trim((string) ($data['to'] ?? ''));
        if (!self::isSafeDestination($to)) {
            throw new InvalidArgumentException(
                'A redirect destination must be an on-site path or an http(s) URL.'
            );
        }

        // A rule that sends a path to itself is a loop; refuse it rather than
        // serve a redirect the browser will reject after bouncing.
        if (str_starts_with($to, '/') && self::normalisePath($to) === $from) {
            throw new InvalidArgumentException('A redirect cannot point a path at itself.');
        }

        return new self($from, $to, (bool) ($data['permanent'] ?? true));
    }

    /**
     * Whether this rule applies to a requested path. Matching is exact on the
     * normalised path — leading/trailing slashes do not change the answer, so
     * `/old` and `/old/` are the same rule.
     */
    public function matches(string $requestPath): bool
    {
        return self::normalisePath($requestPath) === $this->from;
    }

    /** 301 for a permanent move (cached by browsers), 302 for a temporary one. */
    public function statusCode(): int
    {
        return $this->permanent ? 301 : 302;
    }

    /**
     * @return array{from: string, to: string, permanent: bool}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'permanent' => $this->permanent];
    }

    private static function normalisePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        // Compared without surrounding slashes so the rule is written the way an
        // editor thinks of it ("/old-page") but matches however the path arrives.
        return '/' . trim($path, '/');
    }

    private static function isSafeDestination(string $to): bool
    {
        if ($to === '') {
            return false;
        }

        if (str_starts_with($to, '/')) {
            return true;
        }

        // Only http/https absolute URLs. Parsed rather than pattern-matched so a
        // `javascript:` or `data:` scheme cannot slip through a loose regex.
        $scheme = strtolower((string) parse_url($to, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }
}
