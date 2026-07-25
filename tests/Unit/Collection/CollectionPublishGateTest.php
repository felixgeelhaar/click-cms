<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Collection;

use Click\Cms\Application\Collection\CollectionService;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Plugin\PublishGate;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\Publishing\Publishable;
use Click\Cms\Domain\Schema\SectionValidator;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Infrastructure\Collection\JsonCollectionTypeRepository;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The publish gate over collection entries.
 *
 * Publishing happens in two places — a page goes through `PageService`, an
 * entry through `CollectionService` — and a gate covering only the first is a
 * gate an editor routes around by putting the thing in a collection instead.
 * Since the whole point of the gate is that approval cannot be skipped, the
 * second path has to be held to the same rule as the first.
 */
final class CollectionPublishGateTest extends TestCase
{
    private string $dir;
    private ContentService $content;

    /** @var array<string, mixed> */
    private array $admin = ['username' => 'ann', 'role' => 'admin'];

    protected function setUp(): void
    {
        Publishable::register(['post']);

        $this->dir = sys_get_temp_dir() . '/click-cms-colgate-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);

        $this->content = new ContentService(new VersioningStorage(
            new JsonStorage($this->dir . '/content'),
            new JsonVersionStore($this->dir . '/versions', RetentionPolicy::keeping(3)),
            static fn (): string => 'ann',
        ));
    }

    protected function tearDown(): void
    {
        Publishable::reset();
        self::removeTree($this->dir);
    }

    private function service(?PublishGate $gate): CollectionService
    {
        return new CollectionService(
            $this->content,
            new JsonCollectionTypeRepository(dirname(__DIR__, 3) . '/config/collections'),
            new SectionValidator(),
            $gate,
        );
    }

    private function draftEntry(string $slug = 'hello'): void
    {
        $this->content->save(Content::create(
            ContentKey::for('post', $slug, $this->content->defaultLocale()),
            ['title' => 'Hello', 'body' => '<p>Hi</p>', 'owner' => 'ann']
        ));
    }

    private function isLive(string $slug = 'hello'): bool
    {
        return $this->content->get(ContentKey::for('post', $slug, $this->content->defaultLocale())) !== null;
    }

    public function testARefusedEntryDoesNotGoLive(): void
    {
        $this->draftEntry();
        $gate = new PublishGate(static fn (): array => [
            ['allowed' => false, 'reason' => 'This entry is still in review.'],
        ]);

        $result = $this->service($gate)->publish('post', 'hello', $this->admin);

        $this->assertSame(409, $result['status']);
        $this->assertStringContainsString('still in review', (string) $result['error']);
        $this->assertFalse($this->isLive(), 'a refused entry must not be live');
    }

    /** The gate is told the type, so it can hold entries and pages to different rules. */
    public function testTheGateIsToldItIsLookingAtAnEntry(): void
    {
        $this->draftEntry();
        $seen = null;

        $gate = new PublishGate(static function (string $hook, array $payload) use (&$seen): array {
            if ($hook === PublishGate::HOOK) {
                $seen = $payload;
            }
            return [];
        });

        $this->service($gate)->publish('post', 'hello', $this->admin);

        $this->assertSame('post', $seen['type'] ?? null);
        $this->assertSame('hello', $seen['slug'] ?? null);
        $this->assertSame('ann', $seen['user']['username'] ?? null);
    }

    public function testSilencePublishesExactlyAsBefore(): void
    {
        $this->draftEntry();

        $result = $this->service(new PublishGate(static fn (): array => []))
            ->publish('post', 'hello', $this->admin);

        $this->assertNull($result['error']);
        $this->assertTrue($this->isLive());
    }

    /** With nothing gating, collections behave as they always did. */
    public function testWithNoGateAtAllNothingChanges(): void
    {
        $this->draftEntry();

        $result = $this->service(null)->publish('post', 'hello', $this->admin);

        $this->assertNull($result['error']);
        $this->assertTrue($this->isLive());
    }

    /** The announcement is what lets a gate close its own record — and only once live. */
    public function testTheAfterHookFiresOnlyOnceTheEntryIsLive(): void
    {
        $this->draftEntry();
        $announced = [];

        $liveWhenAnnounced = null;

        $gate = new PublishGate(function (string $hook) use (&$announced, &$liveWhenAnnounced): array {
            $announced[] = $hook;
            if ($hook === PublishGate::PUBLISHED_HOOK) {
                // The point of the second hook: whoever was gating this can close
                // its own record, and only for a publish that actually landed.
                $liveWhenAnnounced = $this->isLive();
            }
            return [];
        });

        $this->service($gate)->publish('post', 'hello', $this->admin);

        $this->assertSame([PublishGate::HOOK, PublishGate::PUBLISHED_HOOK], $announced);
        $this->assertTrue($liveWhenAnnounced, 'the entry must already be live when the after hook fires');
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
