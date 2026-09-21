<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Media\MediaService;
use Click\Cms\Application\Seed\SiteSeeder;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;

/**
 * Load the example site from the admin, for a fresh install that has nothing
 * to show yet.
 *
 * The CLI seeder ({@see bin/click-seed.php}) refuses to run under a web SAPI on
 * purpose — an unauthenticated HTTP path that writes content would be a hole.
 * This endpoint is the authenticated counterpart: CSRF and a session are
 * already enforced by the kernel, and seeding itself is gated on
 * ManageSettings so only an administrator can fill the site from the UI.
 *
 * The seeder never overwrites. Re-running is safe; a site that already has
 * content just reports what was skipped.
 */
final class SeedController
{
    /**
     * @param callable(): (?array<string, mixed>) $currentUser
     */
    public function __construct(
        private readonly ContentService $content,
        private readonly CoreConfig $config,
        private readonly string $siteRoot,
        private readonly string $schemaRoot,
        private readonly mixed $currentUser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $method): array
    {
        if ($method !== 'POST') {
            return ApiFault::of(405, 'Method not allowed', 'method_not_allowed');
        }

        $user = ($this->currentUser)();
        if ($user === null) {
            return ApiFault::of(401, 'Not authenticated', 'unauthenticated');
        }

        if (!Role::fromName($user['role'] ?? null)->can(Capability::ManageSettings)) {
            return ApiFault::of(403, 'You do not have permission to load the example site.', 'forbidden');
        }

        $pages = new PageService(
            $this->content,
            new JsonSectionTypeRepository($this->schemaRoot . '/config/sections'),
            new SectionValidator(),
            $this->config->locales(),
        );

        $collections = new CollectionService(
            $this->content,
            new JsonCollectionTypeRepository($this->schemaRoot . '/config/collections'),
            new SectionValidator(),
        );

        $media = new MediaService(
            $this->siteRoot . '/content/media',
            crops: $this->config->mediaCrops(),
        );

        $report = (new SiteSeeder($this->content, $pages, $collections, $media))->seed([
            'username' => (string) ($user['username'] ?? 'admin'),
            'role' => (string) ($user['role'] ?? 'admin'),
        ]);

        // Partial failure is still a usable response: the caller sees what landed
        // and what did not, rather than a blank 500 with half a site on disk.
        $status = $report->hasFailures() ? 207 : ($report->wasNoOp() ? 200 : 201);

        return [
            'status' => $status,
            'data' => [
                'created' => $report->createdItems(),
                'skipped' => $report->skippedItems(),
                'failures' => $report->failureMessages(),
                'noop' => $report->wasNoOp(),
            ],
        ];
    }
}
