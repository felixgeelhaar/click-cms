<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Storage;

use Click\Cms\Domain\Content\Content;
use Click\Cms\Domain\Publishing\PublicationState;
use Click\Cms\Domain\Publishing\PublishingStorage;
use Click\Cms\Domain\ValueObjects\ContentKey;
use Click\Cms\Domain\ValueObjects\Locale;
use Click\Cms\Infrastructure\Storage\AuthorizingStorage;
use Click\Cms\Infrastructure\Storage\StorageAuthorizationException;
use PHPUnit\Framework\TestCase;

/**
 * The decorator's contract: it gates every mutation on the injected policy and
 * nothing else, lets every read through untouched, and hands the authorizer the
 * exact operation and key it was asked about.
 */
final class AuthorizingStorageTest extends TestCase
{
    private RecordingStorage $inner;

    protected function setUp(): void
    {
        $this->inner = new RecordingStorage();
    }

    /** An authorizer that always answers the same, recording what it was asked. */
    private function fixed(bool $answer): array
    {
        $calls = new \ArrayObject();
        $authorizer = static function (string $op, ContentKey $key) use ($answer, $calls): bool {
            $calls[] = [$op, $key->toString()];
            return $answer;
        };

        return [$authorizer, $calls];
    }

    public function testWriteIsRefusedWhenTheAuthorizerReturnsFalse(): void
    {
        [$authorizer] = $this->fixed(false);
        $storage = new AuthorizingStorage($this->inner, $authorizer);

        $this->expectException(StorageAuthorizationException::class);

        try {
            $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));
        } finally {
            // Nothing reached the backend: the gate stands before the inner call.
            $this->assertSame([], $this->inner->saved);
        }
    }

    public function testDeleteIsRefusedWhenTheAuthorizerReturnsFalse(): void
    {
        [$authorizer] = $this->fixed(false);
        $storage = new AuthorizingStorage($this->inner, $authorizer);

        $this->expectException(StorageAuthorizationException::class);

        try {
            $storage->delete(ContentKey::page('home'));
        } finally {
            $this->assertSame([], $this->inner->deleted);
        }
    }

    public function testPublishIsRefusedWhenTheAuthorizerReturnsFalse(): void
    {
        [$authorizer] = $this->fixed(false);
        $storage = new AuthorizingStorage($this->inner, $authorizer);

        $this->expectException(StorageAuthorizationException::class);

        try {
            $storage->publish(ContentKey::page('home'));
        } finally {
            $this->assertSame([], $this->inner->published);
        }
    }

    public function testUnpublishIsRefusedWhenTheAuthorizerReturnsFalse(): void
    {
        [$authorizer] = $this->fixed(false);
        $storage = new AuthorizingStorage($this->inner, $authorizer);

        $this->expectException(StorageAuthorizationException::class);

        try {
            $storage->unpublish(ContentKey::page('home'));
        } finally {
            $this->assertSame([], $this->inner->unpublished);
        }
    }

    public function testWritesPassThroughWhenTheAuthorizerReturnsTrue(): void
    {
        [$authorizer] = $this->fixed(true);
        $storage = new AuthorizingStorage($this->inner, $authorizer);

        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));
        $storage->saveWithReason(Content::create(ContentKey::page('about'), ['title' => 'About']), 'restore');
        $storage->delete(ContentKey::page('gone'));
        $storage->publish(ContentKey::page('home'));
        $storage->unpublish(ContentKey::page('home'));

        $this->assertSame(['page:en:home', 'page:en:about'], $this->inner->saved);
        $this->assertSame(['page:en:gone'], $this->inner->deleted);
        $this->assertSame(['page:en:home'], $this->inner->published);
        $this->assertSame(['page:en:home'], $this->inner->unpublished);
    }

    public function testReadsAreNeverGated(): void
    {
        // An authorizer that would deny everything must not be consulted for a
        // read: reads pass straight through even when policy is "no".
        $calls = 0;
        $authorizer = static function () use (&$calls): bool {
            $calls++;
            return false;
        };
        $storage = new AuthorizingStorage($this->inner, $authorizer);

        $storage->find(ContentKey::page('home'));
        $storage->findByType('page');
        $storage->exists(ContentKey::page('home'));
        $storage->draft(ContentKey::page('home'));
        $storage->workingCopies('page');
        $storage->publicationOf(ContentKey::page('home'));

        $this->assertSame(0, $calls, 'The authorizer must not be consulted for reads.');
        $this->assertSame(
            ['find', 'findByType', 'exists', 'draft', 'workingCopies', 'publicationOf'],
            $this->inner->reads,
        );
    }

    public function testTheExactOperationAndKeyReachTheAuthorizer(): void
    {
        [$authorizer, $calls] = $this->fixed(true);
        $storage = new AuthorizingStorage($this->inner, $authorizer);

        $storage->save(Content::create(ContentKey::page('home', 'de'), ['title' => 'Startseite']));
        $storage->delete(ContentKey::user('alice'));
        $storage->publish(ContentKey::page('home', 'de'));
        $storage->unpublish(ContentKey::page('home', 'de'));

        $this->assertSame([
            ['write', 'page:de:home'],
            ['delete', 'user:en:alice'],
            ['publish', 'page:de:home'],
            ['unpublish', 'page:de:home'],
        ], $calls->getArrayCopy());
    }

    public function testAnonymousFormSubmissionStaysAllowedWhenThePolicyPermitsIt(): void
    {
        // The rationale the decorator exists for: policy lives in the callable,
        // so an integrator can allow a specific anonymous write (a form
        // submission) while refusing every other write. The decorator hardcodes
        // no such rule — it only asks and obeys.
        $authorizer = static fn (string $op, ContentKey $key): bool
            => $op === 'write' && $key->type === 'form_submission';

        $storage = new AuthorizingStorage($this->inner, $authorizer);

        $submission = ContentKey::fromString('form_submission:en:contact-123');
        $storage->save(Content::create($submission, ['message' => 'hello']));
        $this->assertSame(['form_submission:en:contact-123'], $this->inner->saved);

        $this->expectException(StorageAuthorizationException::class);
        $storage->save(Content::create(ContentKey::page('home'), ['title' => 'Home']));
    }
}

/**
 * An in-memory {@see PublishingStorage} that records what it was asked to do,
 * so the decorator's behaviour can be observed without touching a real backend.
 */
final class RecordingStorage implements PublishingStorage
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

    public function save(Content $content): void
    {
        $this->saved[] = $content->key->toString();
    }

    public function saveWithReason(Content $content, string $reason): void
    {
        $this->saved[] = $content->key->toString();
    }

    public function delete(ContentKey $key): bool
    {
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
        return null;
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
        $this->unpublished[] = $key->toString();
        return true;
    }

    public function publicationOf(ContentKey $key): PublicationState
    {
        $this->reads[] = 'publicationOf';
        return PublicationState::of(null, null, null);
    }
}
