<?php

declare(strict_types=1);

namespace Click\Cms\Application\Content;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Content\ResolvedContent;
use Click\Cms\Domain\Publishing\PublicationState;
use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Domain\Storage\StorageInterface;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;

/**
 * Application service for reading and writing content.
 *
 * The single entry point plugins are handed, so they never touch a storage
 * backend directly and the backend stays swappable.
 *
 * This is also where the site's default language is known. The domain cannot
 * read configuration and storage should not decide policy, so the rule "a
 * missing translation falls back to the default language" is applied here, once,
 * rather than in every caller that reads a page.
 *
 * Two families of read live here and they are not interchangeable. {@see get()},
 * {@see page()} and {@see all()} answer the reader's question — the live
 * document — and are what a public render or a delivery API wants. {@see draft()}
 * and {@see workingCopies()} answer the editor's, and include work that has not
 * been published. Handing a reader a working copy publishes it by accident;
 * handing an editor the live document loses their afternoon's work when they
 * save over it.
 */
final class ContentService
{
    private readonly Locale $defaultLocale;

    public function __construct(
        private readonly StorageInterface $storage,
        ?Locale $defaultLocale = null,
    ) {
        $this->defaultLocale = $defaultLocale ?? Locale::default();
    }

    public function defaultLocale(): Locale
    {
        return $this->defaultLocale;
    }

    /** Exactly this document in exactly this language, or nothing. */
    public function get(ContentKey $key): ?Content
    {
        return $this->storage->find($key);
    }

    public function page(string $slug, string|Locale|null $locale = null): ?Content
    {
        return $this->storage->find(ContentKey::page($slug, $this->locale($locale)));
    }

    public function user(string $username): ?Content
    {
        // Accounts are not translated: there is one admin, not an English one
        // and a German one. They are stored under the default locale so they
        // have somewhere to live, and asked for the same way every time.
        return $this->storage->find(ContentKey::user($username, $this->defaultLocale));
    }

    public function media(string $filename): ?Content
    {
        return $this->storage->find(ContentKey::media($filename, $this->defaultLocale));
    }

    /**
     * Read with fallback, and say what was actually found.
     *
     * Returns null only when the document exists in no language at all. When it
     * exists but not in the language asked for, the default language is served
     * and {@see ResolvedContent::isFallback()} says so — the caller is then able
     * to set `lang` honestly and tell the reader the translation is missing.
     */
    public function resolve(ContentKey $key): ?ResolvedContent
    {
        $requested = $key->locale;

        $content = $this->storage->find($key);
        if ($content !== null) {
            return new ResolvedContent($content, $requested, $requested);
        }

        if ($requested->equals($this->defaultLocale)) {
            return null;
        }

        $content = $this->storage->find($key->withLocale($this->defaultLocale));

        return $content === null
            ? null
            : new ResolvedContent($content, $requested, $this->defaultLocale);
    }

    public function resolvePage(string $slug, string|Locale|null $locale = null): ?ResolvedContent
    {
        return $this->resolve(ContentKey::page($slug, $this->locale($locale)));
    }

    /**
     * Which languages this document has actually been written in.
     *
     * @return list<Locale>
     */
    public function translationsOf(string $type, string $slug): array
    {
        $out = [];

        foreach ($this->storage->findByType($type) as $content) {
            if ($content->slug() === $slug) {
                $out[] = $content->locale();
            }
        }

        return $out;
    }

    /**
     * All content of a type in one language.
     *
     * @return list<Content>
     */
    public function all(string $type, string|Locale|null $locale = null): array
    {
        return $this->storage->findByType($type, $this->locale($locale));
    }

    /**
     * All content of a type, every language.
     *
     * @return list<Content>
     */
    public function allInEveryLocale(string $type): array
    {
        return $this->storage->findByType($type);
    }

    /**
     * Pages in one language, newest first — the order a listing screen wants.
     *
     * One language rather than all of them, because a listing that mixed
     * languages would show the same page repeatedly with no way to tell the
     * entries apart.
     *
     * @return list<Content>
     */
    public function pages(string|Locale|null $locale = null): array
    {
        return $this->sortByRecency($this->storage->findByType('page', $this->locale($locale)));
    }

    /**
     * @return list<Content>
     */
    public function pagesInEveryLocale(): array
    {
        return $this->sortByRecency($this->storage->findByType('page'));
    }

    /**
     * Published pages only — what a public site should ever render.
     *
     * Now the same list as {@see pages()}, and kept as its own name because
     * plugins call it and because the name is the clearer statement of intent
     * at a call site that is rendering to the public. It used to filter on a
     * stored `status` field; that field is gone, and everything in `content/`
     * is by definition published.
     *
     * @return list<Content>
     */
    public function publishedPages(string|Locale|null $locale = null): array
    {
        return $this->pages($locale);
    }

    /* ------------------------------------------------------- publication -- */

    /**
     * The copy being worked on, which for a published page is not what the
     * public is reading.
     *
     * A backend with no version chain behind it has no drafts to offer, so the
     * stored document is the honest answer. That is not a fallback papering
     * over a failure: a bare {@see StorageInterface} genuinely has one document
     * per key, and saving to it genuinely is publishing.
     */
    public function draft(ContentKey $key): ?Content
    {
        return $this->storage instanceof PublishingStorage
            ? $this->storage->draft($key)
            : $this->storage->find($key);
    }

    public function draftPage(string $slug, string|Locale|null $locale = null): ?Content
    {
        return $this->draft(ContentKey::page($slug, $this->locale($locale)));
    }

    /**
     * Every document of a type as it is being worked on — what an editor's
     * listing needs, as against what a reader's does.
     *
     * @return list<Content>
     */
    public function workingCopies(string $type, string|Locale|null $locale = null): array
    {
        return $this->storage instanceof PublishingStorage
            ? $this->storage->workingCopies($type, $this->locale($locale))
            : $this->all($type, $locale);
    }

    /**
     * Pages as they are being worked on, newest first.
     *
     * @return list<Content>
     */
    public function draftPages(string|Locale|null $locale = null): array
    {
        return $this->sortByRecency($this->workingCopies('page', $locale));
    }

    /**
     * Promote the working copy to the live site.
     *
     * @return ?Content What went live, or null when there was nothing to promote.
     */
    public function publish(ContentKey $key): ?Content
    {
        if ($this->storage instanceof PublishingStorage) {
            return $this->storage->publish($key);
        }

        // Without a version chain the document is already as public as it can
        // be. Saying so beats pretending to do something.
        return $this->storage->find($key);
    }

    public function unpublish(ContentKey $key): bool
    {
        return $this->storage instanceof PublishingStorage
            ? $this->storage->unpublish($key)
            : $this->storage->delete($key);
    }

    public function publicationOf(ContentKey $key): PublicationState
    {
        return $this->storage instanceof PublishingStorage
            ? $this->storage->publicationOf($key)
            : PublicationState::of($this->storage->find($key), null, null);
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

    /**
     * @param list<Content> $pages
     * @return list<Content>
     */
    private function sortByRecency(array $pages): array
    {
        usort(
            $pages,
            static fn (Content $a, Content $b): int => $b->updatedAt() <=> $a->updatedAt()
        );

        return $pages;
    }

    private function locale(string|Locale|null $locale): Locale
    {
        if ($locale instanceof Locale) {
            return $locale;
        }

        return $locale === null ? $this->defaultLocale : Locale::fromString($locale);
    }
}
