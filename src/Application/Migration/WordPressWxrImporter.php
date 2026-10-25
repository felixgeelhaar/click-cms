<?php

declare(strict_types=1);

namespace Click\Cms\Application\Migration;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Content\PageService;

/** Imports supported WXR text records as unpublished CMS working copies. */
final class WordPressWxrImporter
{
    public function __construct(
        private readonly PageService $pages,
        private readonly CollectionService $collections,
    ) {}

    /** @param list<array<string, mixed>> $items @param array<string, mixed> $user
     * @return array{created:list<array{type:string,slug:string,title:string}>, skipped:list<array{type:string,slug:string,reason:string}>}
     */
    public function import(array $items, array $user, string $locale): array
    {
        $created = [];
        $skipped = [];
        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            $slug = (string) ($item['slug'] ?? '');
            $title = trim((string) ($item['title'] ?? '')) ?: $slug;
            $body = (string) ($item['content'] ?? '');
            if (!in_array($type, ['page', 'post'], true)) {
                $skipped[] = ['type' => $type, 'slug' => $slug, 'reason' => 'Unsupported WordPress content type.'];
                continue;
            }
            if ($slug === '') {
                $skipped[] = ['type' => $type, 'slug' => '(missing)', 'reason' => 'WordPress item has no slug.'];
                continue;
            }

            if ($type === 'page') {
                $sections = $body === '' ? [] : [[
                    'type' => 'rich-text',
                    'values' => ['heading' => '', 'body' => $body, 'width' => 'wide'],
                ]];
                $result = $this->pages->create([
                    'slug' => $slug,
                    'title' => $title,
                    'sections' => $sections,
                    // Keep source identity and source status for an operator's
                    // audit; the actual text is stored in the section body.
                    'wordpressSource' => ['id' => $item['id'] ?? null, 'status' => $item['status'] ?? null],
                ], $user, $locale);
                if ($result['error'] !== null) {
                    $skipped[] = ['type' => $type, 'slug' => $slug, 'reason' => $result['error']];
                    continue;
                }
            } else {
                $result = $this->collections->create('post', [
                    'slug' => $slug,
                    'values' => [
                        'title' => $title,
                        'body' => $body,
                        'excerpt' => (string) ($item['excerpt'] ?? ''),
                    ],
                ], $user, $locale);
                if ($result['error'] !== null) {
                    $skipped[] = ['type' => $type, 'slug' => $slug, 'reason' => $result['error']];
                    continue;
                }
            }

            $created[] = ['type' => $type, 'slug' => $slug, 'title' => $title];
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
