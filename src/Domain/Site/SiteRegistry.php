<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Site;

/**
 * Which site a request belongs to.
 *
 * The whole of multi-site resolution, and it is deliberately small. Every other
 * part of the CMS learns which site it is serving by being handed a different
 * root directory at boot — nothing below the kernel knows sites exist, no
 * service takes a site argument, and no query has to remember to scope itself.
 *
 * That is the design decision worth defending. The alternative — a site column
 * on every document and a predicate on every read — is how multi-site is
 * usually built and how it usually leaks: one query somewhere forgets the
 * predicate, and a client sees another client's drafts. Here the isolation is a
 * property of where the bytes are, so a forgotten scope is not expressible.
 *
 * The cost is that sites cannot share content. For an agency running eight
 * client sites that is the point rather than a limitation.
 */
final class SiteRegistry
{
    /**
     * @param list<Site> $sites
     */
    private function __construct(private readonly array $sites) {}

    /**
     * An installation that has declared no sites.
     *
     * Not an empty registry: it holds the primary site, so every code path is
     * the multi-site path and the single-site case is not a second one that
     * could drift. It simply resolves to the same site every time.
     */
    public static function single(): self
    {
        return new self([Site::primary()]);
    }

    /**
     * @param array<string, mixed> $document The parsed `config/sites.json`.
     */
    public static function fromArray(array $document): self
    {
        $sites = [];

        foreach ($document['sites'] ?? [] as $row) {
            if (is_array($row)) {
                $sites[] = Site::fromArray($row);
            }
        }

        // An empty list is the same as no file at all: one site, at the
        // original layout. A `sites.json` containing `{"sites": []}` is somebody
        // part-way through setting this up, not a request to serve nothing.
        if ($sites === []) {
            return self::single();
        }

        return new self($sites);
    }

    /**
     * The site serving this hostname.
     *
     * Falls back to the default rather than refusing. A request arriving on a
     * hostname nobody declared is ordinary — an IP address, a health check, a
     * staging alias somebody forgot — and answering it with the default site is
     * better than a 500 that takes the installation down when DNS changes.
     */
    public function forHost(?string $host): Site
    {
        if ($host !== null && $host !== '') {
            foreach ($this->sites as $site) {
                if ($site->matches($host)) {
                    return $site;
                }
            }
        }

        return $this->default();
    }

    public function forId(string $id): ?Site
    {
        foreach ($this->sites as $site) {
            if ($site->id === $id) {
                return $site;
            }
        }

        return null;
    }

    /**
     * The site that answers when nothing else does: the one marked primary, or
     * the first declared.
     */
    public function default(): Site
    {
        foreach ($this->sites as $site) {
            if ($site->isPrimary) {
                return $site;
            }
        }

        return $this->sites[0] ?? Site::primary();
    }

    /**
     * @return list<Site>
     */
    public function all(): array
    {
        return $this->sites;
    }

    public function isMultiSite(): bool
    {
        return count($this->sites) > 1;
    }
}
