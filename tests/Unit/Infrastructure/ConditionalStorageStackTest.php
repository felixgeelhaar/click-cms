<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Infrastructure;

use Click\Cms\Application\Cache\RenderCache;
use Click\Cms\Application\Plugin\ContentGate;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Content\ContentRevision;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Audit\AuditingStorage;
use Click\Cms\Infrastructure\Audit\JsonAuditLog;
use Click\Cms\Infrastructure\Cache\CacheInvalidatingStorage;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Plugin\ContentEventStorage;
use Click\Cms\Infrastructure\Storage\AuthorizingStorage;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\StorageAuthorizationException;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

final class ConditionalStorageStackTest extends TestCase
{
    public function testConflictsAndDeniedWritesHaveNoSuccessSideEffectsThroughTheApplicationStack(): void
    {
        $root = sys_get_temp_dir() . '/click-conditional-stack-' . bin2hex(random_bytes(6));
        $allowed = true;
        $events = [];
        $versions = new JsonVersionStore($root . '/versions');
        $audit = new JsonAuditLog($root . '/audit');
        $cache = new RenderCache($root . '/cache', true);
        $storage = new ContentEventStorage(
            new CacheInvalidatingStorage(
                new AuthorizingStorage(
                    new AuditingStorage(new VersioningStorage(new JsonStorage($root . '/content'), $versions), $audit),
                    static function () use (&$allowed): bool { return $allowed; },
                ),
                $cache,
            ),
            new ContentGate(
                static function (string $hook) use (&$events): array { $events[] = $hook; return []; },
                static fn (string $hook): bool => $hook === ContentGate::SAVED,
            ),
            static fn (): array => ['username' => 'editor'],
        );

        try {
            $key = ContentKey::page('home');
            $initial = Content::create($key, ['title' => 'Initial']);
            self::assertTrue($storage->saveIfUnchanged($initial, ''));
            $cacheKey = $cache->keyFor('home', 'en', 'default', '/theme.css');
            $cache->put($cacheKey, 'cached response');

            $update = Content::fromArray($initial->toArray())->update(['title' => 'Changed']);
            self::assertFalse($storage->saveIfUnchanged($update, 'stale'));
            self::assertSame('Initial', $storage->draft($key)?->title());
            self::assertCount(1, $versions->all($key));
            self::assertCount(1, $audit->recent(10));
            self::assertSame([ContentGate::SAVED], $events);
            self::assertNotNull($cache->get($cacheKey));

            $allowed = false;
            try {
                $storage->saveIfUnchanged($update, ContentRevision::of($initial));
                self::fail('Conditional saves must enforce authorization.');
            } catch (StorageAuthorizationException) {
                self::assertCount(1, $versions->all($key));
                self::assertCount(1, $audit->recent(10));
                self::assertCount(1, $events);
                self::assertNotNull($cache->get($cacheKey));
            }

            $allowed = true;
            self::assertTrue($storage->saveIfUnchanged($update, ContentRevision::of($initial)));
            self::assertSame('Changed', $storage->draft($key)?->title());
            self::assertCount(2, $versions->all($key));
            self::assertCount(2, $audit->recent(10));
            self::assertCount(2, $events);
            self::assertNull($cache->get($cacheKey));

            $cache->put($cacheKey, 'still cached');
            self::assertFalse($storage->publishIfUnchanged($key, ContentRevision::of($initial)));
            self::assertNull($storage->find($key));
            self::assertCount(2, $audit->recent(10));
            self::assertNotNull($cache->get($cacheKey));
            $allowed = false;
            try {
                $storage->publishIfUnchanged($key, ContentRevision::of($update));
                self::fail('Conditional publication must enforce authorization.');
            } catch (StorageAuthorizationException) {
                self::assertNull($storage->find($key));
                self::assertCount(2, $audit->recent(10));
            }
            $allowed = true;
            self::assertSame('Changed', $storage->publishIfUnchanged($key, ContentRevision::of($update))?->title());
            self::assertSame('Changed', $storage->find($key)?->title());
            self::assertCount(3, $audit->recent(10));
            self::assertNull($cache->get($cacheKey));
        } finally {
            $this->removeTree($root);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
