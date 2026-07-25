<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\ContentGate;
use Click\Cms\Application\Plugin\ContentRefusedException;
use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Publishing\PublicationState;
use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Infrastructure\Plugin\ContentEventStorage;
use PHPUnit\Framework\TestCase;

/**
 * Where the events fire from, and what firing costs.
 *
 * The decorator's job is narrow and its guarantees are all about ordering: a
 * veto strictly before the inner call, an announcement strictly after it and
 * only when something happened, and nothing at all — not even a payload — when
 * no plugin is listening. Each is the kind of property that decays silently, so
 * each is pinned here.
 */
final class ContentEventStorageTest extends TestCase
{
    private EventTestStorage $inner;

    protected function setUp(): void
    {
        $this->inner = new EventTestStorage();
    }

    /**
     * A storage wired to a gate that records every hook it was asked to fire.
     *
     * @param list<string> $listening Which hooks have a listener.
     * @param array<string, mixed> $answers What the "plugins" answer.
     * @return array{ContentEventStorage, \ArrayObject<int, array{string, array<string, mixed>}>}
     */
    private function storage(array $listening, array $answers = [], array $user = []): array
    {
        $fired = new \ArrayObject();

        $gate = new ContentGate(
            static function (string $hook, array $payload) use ($fired, $answers): array {
                $fired[] = [$hook, $payload];

                return $answers;
            },
            static fn (string $hook): bool => in_array($hook, $listening, true),
        );

        return [new ContentEventStorage($this->inner, $gate, static fn (): array => $user), $fired];
    }

    /** @param \ArrayObject<int, array{string, array<string, mixed>}> $fired */
    private function hooks(\ArrayObject $fired): array
    {
        return array_map(static fn (array $call): string => $call[0], $fired->getArrayCopy());
    }

    private function page(string $slug = 'home'): Content
    {
        return Content::create(ContentKey::page($slug), ['title' => 'Home']);
    }

    /* ------------------------------------------------------- nothing listening -- */

    /**
     * The cost of *having* these events on a site that uses none of them.
     *
     * They fire on every write a CMS ever performs, so "no listeners is free"
     * is not a nicety — it is the condition on which adding them at all is
     * defensible. No payload, no actor lookup, and in particular none of the
     * reads that establish whether a save is a creation.
     */
    public function testWithNoListenersNothingIsBuiltAndNoExtraReadHappens(): void
    {
        $actorCalls = 0;
        $dispatches = 0;

        $gate = new ContentGate(
            static function () use (&$dispatches): array {
                $dispatches++;

                return [];
            },
            static fn (): bool => false,
        );

        $storage = new ContentEventStorage($this->inner, $gate, static function () use (&$actorCalls): array {
            $actorCalls++;

            return [];
        });

        $storage->save($this->page());
        $storage->saveWithReason($this->page('about'), 'restore');
        $storage->delete(ContentKey::page('gone'));
        $storage->publish(ContentKey::page('home'));
        $storage->unpublish(ContentKey::page('home'));

        $this->assertSame(0, $dispatches, 'nothing may be dispatched');
        $this->assertSame(0, $actorCalls, 'the acting user must not even be looked up');
        $this->assertSame([], $this->inner->reads, 'no read may be added to the write path');

        // And every operation still reached the backend untouched.
        $this->assertSame(['page:en:home', 'page:en:about'], $this->inner->saved);
        $this->assertSame(['page:en:gone'], $this->inner->deleted);
        $this->assertSame(['page:en:home'], $this->inner->published);
        $this->assertSame(['page:en:home'], $this->inner->unpublished);
    }

    public function testOnlyTheHooksWithListenersAreFired(): void
    {
        [$storage, $fired] = $this->storage([ContentGate::SAVED]);

        $storage->save($this->page());
        $storage->delete(ContentKey::page('gone'));
        $storage->unpublish(ContentKey::page('home'));

        $this->assertSame([ContentGate::SAVED], $this->hooks($fired));
    }

    /* --------------------------------------------------------------- vetoes -- */

    public function testARefusedSaveThrowsAndNothingReachesTheBackend(): void
    {
        [$storage] = $this->storage(
            [ContentGate::BEFORE_SAVE],
            ['Validator' => ['allowed' => false, 'reason' => 'This page has no headline.']]
        );

        try {
            $storage->save($this->page());
            $this->fail('a refused save must not return normally');
        } catch (ContentRefusedException $e) {
            // The reason is the message, because the reason is what an editor
            // needs to read.
            $this->assertSame('This page has no headline.', $e->getMessage());
            $this->assertSame(ContentGate::BEFORE_SAVE, $e->hook);
            $this->assertSame('page:en:home', $e->key->toString());
        }

        $this->assertSame([], $this->inner->saved, 'the veto stands before the inner call');
    }

    public function testARefusedDeleteThrowsAndNothingIsDeleted(): void
    {
        [$storage] = $this->storage(
            [ContentGate::BEFORE_DELETE],
            ['Links' => ['allowed' => false, 'reason' => 'Three pages still link here.']]
        );

        try {
            $storage->delete(ContentKey::page('about'));
            $this->fail('a refused delete must not return normally');
        } catch (ContentRefusedException $e) {
            $this->assertSame('Three pages still link here.', $e->getMessage());
            $this->assertSame(ContentGate::BEFORE_DELETE, $e->hook);
        }

        $this->assertSame([], $this->inner->deleted);
    }

    public function testARefusedSaveIsNeverAnnouncedAsHavingHappened(): void
    {
        [$storage, $fired] = $this->storage(
            [ContentGate::BEFORE_SAVE, ContentGate::SAVED],
            ['Validator' => ['allowed' => false, 'reason' => 'no']]
        );

        try {
            $storage->save($this->page());
        } catch (ContentRefusedException) {
            // expected
        }

        $this->assertSame([ContentGate::BEFORE_SAVE], $this->hooks($fired));
    }

    public function testSilenceFromEveryPluginPermitsTheWrite(): void
    {
        [$storage] = $this->storage([ContentGate::BEFORE_SAVE, ContentGate::BEFORE_DELETE], ['Quiet' => null]);

        $storage->save($this->page());
        $storage->delete(ContentKey::page('gone'));

        $this->assertSame(['page:en:home'], $this->inner->saved);
        $this->assertSame(['page:en:gone'], $this->inner->deleted);
    }

    /* --------------------------------------------------------- announcements -- */

    public function testTheVetoIsAskedBeforeTheWriteAndTheAnnouncementAfterIt(): void
    {
        $order = [];
        $gate = new ContentGate(
            static function (string $hook) use (&$order): array {
                $order[] = $hook;

                return [];
            },
            static fn (): bool => true,
        );
        $inner = new OrderRecordingStorage($order);

        (new ContentEventStorage($inner, $gate))->save($this->page());

        $this->assertSame([ContentGate::BEFORE_SAVE, 'inner-save', ContentGate::SAVED], $order);
    }

    public function testAWriteThatThrewIsNotAnnounced(): void
    {
        [$storage, $fired] = $this->storage([ContentGate::SAVED]);
        $this->inner->failWrites = true;

        try {
            $storage->save($this->page());
            $this->fail('the inner failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk is full', $e->getMessage());
        }

        // Announcing a save that then failed is worse than not announcing it: a
        // listener would index content that is not there.
        $this->assertSame([], $this->hooks($fired));
    }

    public function testADeleteThatRemovedNothingIsNotAnnounced(): void
    {
        [$storage, $fired] = $this->storage([ContentGate::DELETED]);
        $this->inner->deleteResult = false;

        $this->assertFalse($storage->delete(ContentKey::page('never-existed')));
        $this->assertSame([], $this->hooks($fired));
    }

    public function testAnUnpublishThatTookNothingDownIsNotAnnounced(): void
    {
        [$storage, $fired] = $this->storage([ContentGate::UNPUBLISHED]);
        $this->inner->unpublishResult = false;

        $this->assertFalse($storage->unpublish(ContentKey::page('not-live')));
        $this->assertSame([], $this->hooks($fired));
    }

    public function testADeleteAndAnUnpublishThatHappenedAreAnnounced(): void
    {
        [$storage, $fired] = $this->storage([ContentGate::DELETED, ContentGate::UNPUBLISHED]);

        $storage->delete(ContentKey::page('gone'));
        $storage->unpublish(ContentKey::page('home'));

        $this->assertSame([ContentGate::DELETED, ContentGate::UNPUBLISHED], $this->hooks($fired));
    }

    /**
     * `content.published` is fired by `PublishGate` from the service layer, where
     * the veto that precedes it also lives. Firing it again from here would
     * deliver every publish to every listener twice, which for a webhook or an
     * index is a bug and not a redundancy.
     */
    public function testPublishFiresNothingHere(): void
    {
        $fired = new \ArrayObject();
        $gate = new ContentGate(
            static function (string $hook) use ($fired): array {
                $fired[] = $hook;

                return [];
            },
            static fn (): bool => true,
        );

        (new ContentEventStorage($this->inner, $gate))->publish(ContentKey::page('home'));

        $this->assertSame([], $fired->getArrayCopy());
        $this->assertSame(['page:en:home'], $this->inner->published);
    }

    /* ---------------------------------------------------------------- facts -- */

    public function testANewDocumentIsAnnouncedAsCreated(): void
    {
        [$storage, $fired] = $this->storage([ContentGate::SAVED]);

        $storage->save($this->page());

        $this->assertTrue($fired[0][1]['created']);
    }

    public function testAnExistingDocumentIsNotAnnouncedAsCreated(): void
    {
        [$storage, $fired] = $this->storage([ContentGate::SAVED]);
        $this->inner->draft = $this->page();

        $storage->save($this->page());

        $this->assertFalse($fired[0][1]['created']);
    }

    public function testTheReasonForTheWriteReachesTheListener(): void
    {
        // A listener that should not react to a history restore has no other way
        // to tell it from an ordinary edit.
        [$storage, $fired] = $this->storage([ContentGate::SAVED]);

        $storage->saveWithReason($this->page(), 'restore');

        $this->assertSame('restore', $fired[0][1]['reason']);
    }

    public function testAPlainSaveIsReportedAsSuchRatherThanAsNoReasonAtAll(): void
    {
        [$storage, $fired] = $this->storage([ContentGate::SAVED]);

        $storage->save($this->page());

        $this->assertSame('save', $fired[0][1]['reason']);
    }

    public function testTheActingUserIsResolvedLazilyAndRedacted(): void
    {
        [$storage, $fired] = $this->storage(
            [ContentGate::SAVED],
            [],
            ['username' => 'ada', 'role' => 'editor', 'passwordHash' => 'nope']
        );

        $storage->save($this->page());

        $this->assertSame(['username' => 'ada', 'role' => 'editor'], $fired[0][1]['user']);
    }

    public function testAnUnattributedWriteIsAnnouncedWithAnEmptyActorRatherThanAFabricatedOne(): void
    {
        $fired = new \ArrayObject();
        $gate = new ContentGate(
            static function (string $hook, array $payload) use ($fired): array {
                $fired[] = $payload;

                return [];
            },
            static fn (): bool => true,
        );

        // No actor closure at all: a CLI task, a seeder.
        (new ContentEventStorage($this->inner, $gate))->save($this->page());

        $this->assertSame([], $fired[0]['user']);
    }

    /* ---------------------------------------------------------------- reads -- */

    public function testEveryReadPassesStraightThroughAndFiresNothing(): void
    {
        $fired = new \ArrayObject();
        $gate = new ContentGate(
            static function (string $hook) use ($fired): array {
                $fired[] = $hook;

                return [];
            },
            static fn (): bool => true,
        );
        $storage = new ContentEventStorage($this->inner, $gate);

        $storage->find(ContentKey::page('home'));
        $storage->findByType('page');
        $storage->types();
        $storage->exists(ContentKey::page('home'));
        $storage->draft(ContentKey::page('home'));
        $storage->workingCopies('page');
        $storage->publicationOf(ContentKey::page('home'));

        $this->assertSame([], $fired->getArrayCopy(), 'a read is not a change');
        $this->assertSame(
            ['find', 'findByType', 'types', 'exists', 'draft', 'workingCopies', 'publicationOf'],
            $this->inner->reads,
        );
    }
}

/**
 * An in-memory {@see PublishingStorage} that records what it was asked to do and
 * can be told to fail, so the decorator's ordering guarantees are observable.
 */
final class EventTestStorage implements PublishingStorage
{
    /** @var list<string> */
    public array $saved = [];
    /** @var list<string> */
    public array $deleted = [];
    /** @var list<string> */
    public array $published = [];
    /** @var list<string> */
    public array $unpublished = [];
    /** @var list<string> */
    public array $reads = [];

    public bool $failWrites = false;
    public bool $deleteResult = true;
    public bool $unpublishResult = true;
    public ?Content $draft = null;

    public function find(ContentKey $key): ?Content
    {
        $this->reads[] = 'find';

        return null;
    }

    public function findByType(string $type, ?Locale $locale = null): array
    {
        $this->reads[] = 'findByType';

        return [];
    }

    public function types(): array
    {
        $this->reads[] = 'types';

        return [];
    }

    public function save(Content $content): void
    {
        $this->saveWithReason($content, 'save');
    }

    public function saveWithReason(Content $content, string $reason): void
    {
        if ($this->failWrites) {
            throw new \RuntimeException('disk is full');
        }

        $this->saved[] = $content->key->toString();
    }

    public function delete(ContentKey $key): bool
    {
        if (!$this->deleteResult) {
            return false;
        }

        $this->deleted[] = $key->toString();

        return true;
    }

    public function exists(ContentKey $key): bool
    {
        $this->reads[] = 'exists';

        return false;
    }

    public function draft(ContentKey $key): ?Content
    {
        $this->reads[] = 'draft';

        return $this->draft;
    }

    public function workingCopies(string $type, ?Locale $locale = null): array
    {
        $this->reads[] = 'workingCopies';

        return [];
    }

    public function publish(ContentKey $key): ?Content
    {
        $this->published[] = $key->toString();

        return null;
    }

    public function unpublish(ContentKey $key): bool
    {
        if (!$this->unpublishResult) {
            return false;
        }

        $this->unpublished[] = $key->toString();

        return true;
    }

    public function publicationOf(ContentKey $key): PublicationState
    {
        $this->reads[] = 'publicationOf';

        return PublicationState::of(null, null, null);
    }
}

/**
 * Records the inner write into the same log the hooks are recorded in, which is
 * the only way to assert that the veto came before it and the announcement after.
 */
final class OrderRecordingStorage implements PublishingStorage
{
    /** @param list<string> $log */
    public function __construct(private array &$log)
    {
    }

    public function find(ContentKey $key): ?Content
    {
        return null;
    }

    public function findByType(string $type, ?Locale $locale = null): array
    {
        return [];
    }

    public function types(): array
    {
        return [];
    }

    public function save(Content $content): void
    {
        $this->log[] = 'inner-save';
    }

    public function saveWithReason(Content $content, string $reason): void
    {
        $this->log[] = 'inner-save';
    }

    public function delete(ContentKey $key): bool
    {
        $this->log[] = 'inner-delete';

        return true;
    }

    public function exists(ContentKey $key): bool
    {
        return false;
    }

    public function draft(ContentKey $key): ?Content
    {
        return null;
    }

    public function workingCopies(string $type, ?Locale $locale = null): array
    {
        return [];
    }

    public function publish(ContentKey $key): ?Content
    {
        $this->log[] = 'inner-publish';

        return null;
    }

    public function unpublish(ContentKey $key): bool
    {
        $this->log[] = 'inner-unpublish';

        return true;
    }

    public function publicationOf(ContentKey $key): PublicationState
    {
        return PublicationState::of(null, null, null);
    }
}
