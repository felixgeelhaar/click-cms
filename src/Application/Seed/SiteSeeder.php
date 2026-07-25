<?php

declare(strict_types=1);

namespace Click\Cms\Application\Seed;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Media\MediaService;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Puts {@see ExampleSite} into a site.
 *
 * ## It goes through the services, not the storage
 *
 * Every write here is the same call the admin UI's HTTP handler makes:
 * `PageService::create()`, `CollectionService::create()`, `MediaService::store()`.
 * Writing straight to storage would be shorter and would let the seeder produce
 * documents the application itself would reject — content that renders in the
 * demo and 422s the moment an editor saves it. Going through the services means
 * seeded content is, by construction, content the CMS accepts.
 *
 * ## It never overwrites
 *
 * Anything already present is left exactly as it is and reported as skipped.
 * There is no `--force` and no `--reset`, deliberately: the one thing a seeder
 * must never do is destroy work, and a flag that deletes content is a flag that
 * will eventually be typed on the wrong host. Re-running is therefore always
 * safe, and an interrupted run is finished by running it again.
 *
 * ## Order
 *
 * Media, then team members, then posts, then pages, then the menu — each step
 * depends only on ones before it. Media first because image fields hold ids
 * that do not exist until the pictures do; team members before posts because a
 * post's author is a reference to one.
 */
final class SiteSeeder
{
    public function __construct(
        private readonly ContentService $content,
        private readonly PageService $pages,
        private readonly CollectionService $collections,
        private readonly MediaService $media,
    ) {
    }

    /**
     * @param array<string, mixed> $user the account the seeded content is
     *                                   attributed to; ownership is recorded on
     *                                   every page, so it must be a real one
     */
    public function seed(array $user): SeedReport
    {
        $report = new SeedReport();

        $mediaIds = $this->seedMedia($report);
        $this->seedCollection('team-member', ExampleSite::teamMembers(), $mediaIds, $user, $report);
        $this->seedCollection('post', ExampleSite::posts(), $mediaIds, $user, $report);
        $this->seedPages($mediaIds, $user, $report);
        $this->seedMenu($report);

        return $report;
    }

    /* ------------------------------------------------------------- media -- */

    /**
     * @return array<string, string> token name => stored media id
     */
    private function seedMedia(SeedReport $report): array
    {
        // Ids are generated at upload, so "have I seeded this already?" cannot be
        // asked by id. The original filename is the only stable handle the
        // library keeps, and it is what the second run matches on.
        $existing = [];
        foreach ($this->media->all() as $item) {
            $existing[$item->originalName] ??= $item->id;
        }

        $ids = [];

        foreach (ExampleSite::media() as $token => $picture) {
            if (isset($existing[$picture['name']])) {
                $ids[$token] = $existing[$picture['name']];
                $report->skipped('media ' . $picture['name']);
                continue;
            }

            $id = $this->storePicture($picture, $report);
            if ($id !== null) {
                $ids[$token] = $id;
                $report->created('media ' . $picture['name']);
            }
        }

        return $ids;
    }

    /**
     * @param array{name: string, alt: string, svg: string} $picture
     */
    private function storePicture(array $picture, SeedReport $report): ?string
    {
        // MediaService takes an upload, so the SVG becomes one: written to a
        // temp file and handed over in the shape PHP would have produced. The
        // alternative — a second, seeder-only write path into the library —
        // would be a path the sanitiser and the type gate had never seen.
        $tmp = tempnam(sys_get_temp_dir(), 'click-seed-');
        if ($tmp === false) {
            $report->failed('media ' . $picture['name'], 'no temporary file could be created');
            return null;
        }

        try {
            file_put_contents($tmp, $picture['svg']);

            $result = $this->media->store([
                'name' => $picture['name'],
                'type' => 'image/svg+xml',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp) ?: strlen($picture['svg']),
            ]);
        } finally {
            // store() moves the file on success and leaves it on failure, so
            // both cases have to be cleaned up here.
            @unlink($tmp);
        }

        if ($result['item'] === null) {
            $report->failed('media ' . $picture['name'], (string) ($result['error'] ?? 'unknown error'));
            return null;
        }

        // Alt text is what makes the seeded site a usable accessibility example
        // rather than a demonstration of the commonest mistake.
        $this->media->updateAlt($result['item']->id, $picture['alt']);

        return $result['item']->id;
    }

    /* -------------------------------------------------------- collections -- */

    /**
     * @param array<string, array<string, mixed>> $entries
     * @param array<string, string>               $mediaIds
     * @param array<string, mixed>                $user
     */
    private function seedCollection(
        string $typeId,
        array $entries,
        array $mediaIds,
        array $user,
        SeedReport $report
    ): void {
        // A collection type the site has removed from config/collections is not
        // an error — it is a site that has decided it does not want posts.
        if ($this->collections->collectionType($typeId) === null) {
            $report->skipped($typeId . ' (collection type not configured)');
            return;
        }

        foreach ($entries as $slug => $entry) {
            $label = $typeId . '/' . $slug;

            if ($this->collections->find($typeId, $slug) !== null) {
                $report->skipped($label);
                continue;
            }

            // `values` beside `slug` is the shape CollectionService validates —
            // the same body the admin UI posts.
            $result = $this->collections->create(
                $typeId,
                ['slug' => $slug, 'values' => $this->resolveMedia($entry, $mediaIds)],
                $user
            );

            if (($result['entry'] ?? null) === null) {
                $report->failed($label, $this->describe($result));
                continue;
            }

            // Seeded content is published: an example site whose every page is a
            // draft shows a visitor an empty site and teaches nothing.
            $this->collections->publish($typeId, $slug, $user);
            $report->created($label);
        }
    }

    /* -------------------------------------------------------------- pages -- */

    /**
     * @param array<string, string> $mediaIds
     * @param array<string, mixed>  $user
     */
    private function seedPages(array $mediaIds, array $user, SeedReport $report): void
    {
        foreach (ExampleSite::pages() as $slug => $page) {
            $label = 'page/' . $slug;

            // The working copy, not the live page: a draft occupies the address
            // just as firmly, and overwriting one would be exactly the
            // destruction this seeder promises not to do.
            if ($this->content->draftPage($slug) !== null) {
                $report->skipped($label);
                continue;
            }

            $data = $this->resolveMedia($page, $mediaIds);
            $data['slug'] = $slug;

            $result = $this->pages->create($data, $user);

            if (($result['page'] ?? null) === null) {
                $report->failed($label, $this->describe($result));
                continue;
            }

            $this->pages->publish($slug, $user);
            $report->created($label);
        }
    }

    /* --------------------------------------------------------------- menu -- */

    private function seedMenu(SeedReport $report): void
    {
        // Menus are ordinary content documents in the site's default language;
        // there is no service in front of them, so this writes one directly.
        $key = ContentKey::for('menu', 'main', $this->content->defaultLocale());

        if ($this->content->get($key) !== null) {
            $report->skipped('menu/main');
            return;
        }

        $this->content->save(Content::create($key, ExampleSite::menu()->toArray()));
        $report->created('menu/main');
    }

    /* ------------------------------------------------------------ helpers -- */

    /**
     * Swap every `@media/<name>` token for the id the picture was stored under.
     *
     * Recursive because image fields appear inside repeaters as well as at the
     * top level. A token whose picture failed to store resolves to nothing and
     * the field is dropped, so a missing image never becomes a broken reference
     * baked into content.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, string>   $mediaIds
     * @return array<array-key, mixed>
     */
    private function resolveMedia(array $data, array $mediaIds): array
    {
        foreach ($data as $field => $value) {
            if (is_array($value)) {
                $data[$field] = $this->resolveMedia($value, $mediaIds);
                continue;
            }

            if (!is_string($value) || !str_starts_with($value, ExampleSite::MEDIA_TOKEN_PREFIX)) {
                continue;
            }

            $token = substr($value, strlen(ExampleSite::MEDIA_TOKEN_PREFIX));

            if (isset($mediaIds[$token])) {
                $data[$field] = $mediaIds[$token];
            } else {
                unset($data[$field]);
            }
        }

        return $data;
    }

    /**
     * A service failure as one line, keeping the per-field validation errors —
     * without them "Some sections are invalid" is untraceable to the field that
     * was wrong.
     *
     * @param array<string, mixed> $result
     */
    private function describe(array $result): string
    {
        $message = (string) ($result['error'] ?? 'rejected');
        $fields = $result['errors'] ?? [];

        if (!is_array($fields) || $fields === []) {
            return $message;
        }

        $detail = [];
        foreach ($fields as $field => $why) {
            $detail[] = $field . ' — ' . (is_string($why) ? $why : json_encode($why));
        }

        return $message . ' (' . implode('; ', $detail) . ')';
    }
}
