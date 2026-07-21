<?php

declare(strict_types=1);

namespace Click\Cms\Application\Content;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;

/**
 * Application service for reading and writing content.
 *
 * The single entry point plugins are handed, so they never touch a storage
 * backend directly and the backend stays swappable.
 */
final class ContentService
{
    public function __construct(private readonly StorageInterface $storage) {}

    public function get(ContentKey $key): ?Content
    {
        return $this->storage->find($key);
    }

    public function page(string $slug): ?Content
    {
        return $this->storage->find(ContentKey::page($slug));
    }

    public function user(string $username): ?Content
    {
        return $this->storage->find(ContentKey::user($username));
    }

    public function media(string $filename): ?Content
    {
        return $this->storage->find(ContentKey::media($filename));
    }

    /**
     * All content of a type.
     *
     * @return list<Content>
     */
    public function all(string $type): array
    {
        return $this->storage->findByType($type);
    }

    /**
     * All pages, newest first — the order a listing screen wants.
     *
     * @return list<Content>
     */
    public function pages(): array
    {
        $pages = $this->storage->findByType('page');

        usort(
            $pages,
            static fn (Content $a, Content $b): int => $b->updatedAt() <=> $a->updatedAt()
        );

        return $pages;
    }

    /**
     * Published pages only — what a public site should ever render.
     *
     * @return list<Content>
     */
    public function publishedPages(): array
    {
        return array_values(array_filter(
            $this->pages(),
            static fn (Content $page): bool => $page->isPublished()
        ));
    }

    public function save(Content $content): void
    {
        $this->storage->save($content);
    }

    public function delete(ContentKey $key): bool
    {
        return $this->storage->delete($key);
    }

    public function exists(ContentKey $key): bool
    {
        return $this->storage->exists($key);
    }
}
