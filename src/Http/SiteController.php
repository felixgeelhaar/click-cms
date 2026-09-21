<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Domain\Site\Site;
use Click\Cms\Domain\Site\SiteRegistry;

/**
 * Which site this admin session is editing.
 *
 * Read by the admin UI so it can say so on screen when an installation serves
 * more than one. Somebody who looks after eight client sites and has three tabs
 * open needs the answer visible, not inferable from the address bar — editing
 * the wrong client's homepage is a mistake with no warning and an audience.
 *
 * Pulled out of the kernel because naming the current site is not the job of
 * the thing that turns requests into responses. Application only routes the path.
 */
final class SiteController
{
    /**
     * @param callable(): Site $site Resolves the site for the current request.
     * @param callable(): SiteRegistry $siteRegistry The installation's site map.
     */
    public function __construct(
        private readonly mixed $site,
        private readonly mixed $siteRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $method): array
    {
        if ($method !== 'GET') {
            return ApiFault::of(405, 'Method not allowed', 'method_not_allowed');
        }

        return ['data' => ($this->site)()->toArray() + [
            'multiSite' => ($this->siteRegistry)()->isMultiSite(),
        ]];
    }
}
