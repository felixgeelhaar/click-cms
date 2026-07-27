<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Site;

use InvalidArgumentException;

/**
 * One site served by this installation.
 *
 * A site owns its content, its media, its accounts and its settings, and shares
 * the code, the plugins and the themes. That split is the whole design: an
 * agency running eight client sites wants one thing to update and eight things
 * that cannot see each other.
 */
final class Site
{
    /**
     * The id an installation with no `config/sites.json` uses.
     *
     * Its content stays at `content/` and `data/` rather than moving under
     * `sites/`, which is what makes multi-site additive: an existing
     * installation that never declares a site is unchanged, byte for byte, on
     * disk and in every path.
     */
    public const PRIMARY = 'primary';

    /**
     * @param list<string> $hosts Hostnames that resolve to this site.
     */
    private function __construct(
        public readonly string $id,
        public readonly array $hosts,
        public readonly string $title,
        public readonly bool $isPrimary,
    ) {}

    public static function primary(string $title = ''): self
    {
        return new self(self::PRIMARY, [], $title, true);
    }

    /**
     * @param array<string, mixed> $row
     * @throws InvalidArgumentException when the id could not be a directory name.
     */
    public static function fromArray(array $row): self
    {
        $id = trim((string) ($row['id'] ?? ''));

        // The id becomes a path segment, so it is held to the same rule content
        // keys are — and for the same reason. A site called `../..` would put
        // one client's content somewhere quite different from where the
        // configuration says.
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id) !== 1) {
            throw new InvalidArgumentException(
                "\"{$id}\" is not a usable site id. Use lower-case letters, digits, dots, dashes "
                . 'and underscores, starting with a letter or digit.'
            );
        }

        $hosts = [];
        foreach ($row['hosts'] ?? [] as $host) {
            if (is_string($host) && trim($host) !== '') {
                // Stored lower-case and without a port, because that is how
                // `matches()` will compare, and normalising once here beats
                // normalising at every comparison.
                $hosts[] = self::normaliseHost($host);
            }
        }

        return new self(
            $id,
            array_values(array_unique($hosts)),
            trim((string) ($row['title'] ?? $id)),
            $id === self::PRIMARY,
        );
    }

    /**
     * Whether this site answers for a hostname.
     *
     * A leading `*.` matches one or more labels beneath the domain, which is
     * what a site serving `www.example.com` and `example.com` needs without
     * listing both. It deliberately does not match the bare domain: `*.
     * example.com` and `example.com` are different addresses and a site that
     * wants both should say both, rather than discovering the rule by accident.
     */
    public function matches(string $host): bool
    {
        $host = self::normaliseHost($host);

        foreach ($this->hosts as $candidate) {
            if ($candidate === $host) {
                return true;
            }

            if (str_starts_with($candidate, '*.') && str_ends_with($host, substr($candidate, 1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where this site's content and data live, relative to the installation.
     *
     * Empty for the primary site, so its paths are `content/` and `data/` — the
     * layout every existing installation already has.
     */
    public function rootSuffix(): string
    {
        return $this->isPrimary ? '' : '/sites/' . $this->id;
    }

    private static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));

        // A port is not part of the identity of a site: the same site is the
        // same site on :80, :443 and :8080 in development.
        return (string) preg_replace('/:\d+$/', '', $host);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hosts' => $this->hosts,
            'title' => $this->title,
            'isPrimary' => $this->isPrimary,
        ];
    }
}
