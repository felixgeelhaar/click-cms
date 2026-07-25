<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\ContentGate;
use Click\Cms\Domain\ValueObjects\ContentKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The contract of the content lifecycle hooks, tested at the gate rather than
 * through storage.
 *
 * The rules here are the ones `PublishGate` established and this class inherits:
 * silence permits, a throw is not an opinion, the first refusal wins, and a
 * payload carries identity rather than content. Each is a decision that would be
 * cheap to regress and expensive to notice — a gate that quietly stopped
 * refusing looks exactly like a site nobody objected to.
 */
final class ContentGateTest extends TestCase
{
    private function key(): ContentKey
    {
        return ContentKey::fromString('page:en:about');
    }

    /** @param array<string, mixed> $answers */
    private function gateAnswering(array $answers): ContentGate
    {
        return new ContentGate(static fn (): array => $answers);
    }

    public function testAGateWithNothingBehindItPermitsAndAnnouncesNothing(): void
    {
        $gate = new ContentGate();

        $this->assertNull($gate->refusalForSave($this->key(), []));
        $this->assertNull($gate->refusalForDelete($this->key(), []));
        $this->assertFalse($gate->listensTo(ContentGate::SAVED));

        // Nothing to assert on the announcements beyond that they are harmless:
        // a CMS booted without plugins must be able to write.
        $gate->announceSaved($this->key(), []);
        $gate->announceDeleted($this->key(), []);
        $gate->announceUnpublished($this->key(), []);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function silentAnswers(): iterable
    {
        yield 'no plugins answered' => [[]];
        yield 'null' => [['p' => null]];
        yield 'empty array' => [['p' => []]];
        yield 'no allowed key' => [['p' => ['reason' => 'I only left a reason']]];
        yield 'allowed true' => [['p' => ['allowed' => true]]];
        yield 'allowed truthy but not false' => [['p' => ['allowed' => 0]]];
        yield 'a string' => [['p' => 'no']];
        yield 'a bool' => [['p' => false]];
    }

    /** @param array<string, mixed> $answers */
    #[DataProvider('silentAnswers')]
    public function testEverythingThatIsNotAnExplicitRefusalPermits(array $answers): void
    {
        $gate = $this->gateAnswering($answers);

        $this->assertNull($gate->refusalForSave($this->key(), []));
        $this->assertNull($gate->refusalForDelete($this->key(), []));
    }

    public function testAnExplicitRefusalIsReturnedWithItsReason(): void
    {
        $gate = $this->gateAnswering(['Validator' => [
            'allowed' => false,
            'reason' => '  This page has no headline.  ',
        ]]);

        $this->assertSame('This page has no headline.', $gate->refusalForSave($this->key(), []));
    }

    public function testARefusalWithNoReasonNamesThePluginToGoAndAsk(): void
    {
        $gate = $this->gateAnswering(['Validator' => ['allowed' => false]]);

        $this->assertSame(
            'The "Validator" plugin refused this change, and gave no reason.',
            $gate->refusalForSave($this->key(), [])
        );
    }

    public function testTheFirstRefusalWins(): void
    {
        // Dependency order is the dispatch order, so the editor is told one
        // reason rather than a list they have to read to find the first problem.
        $gate = $this->gateAnswering([
            'Quiet' => null,
            'First' => ['allowed' => false, 'reason' => 'first'],
            'Second' => ['allowed' => false, 'reason' => 'second'],
        ]);

        $this->assertSame('first', $gate->refusalForSave($this->key(), []));
    }

    public function testADispatcherThatThrowsPermitsTheWrite(): void
    {
        // Per-plugin throws are absorbed by the isolated dispatch; reaching here
        // means the dispatch itself broke. Same rule: the write survives.
        $gate = new ContentGate(static function (): array {
            throw new \RuntimeException('half-booted kernel');
        });

        $this->assertNull($gate->refusalForSave($this->key(), []));
        $this->assertNull($gate->refusalForDelete($this->key(), []));
    }

    public function testThePayloadCarriesTheKeyWholeAndInParts(): void
    {
        $seen = [];
        $gate = new ContentGate(static function (string $hook, array $payload) use (&$seen): array {
            $seen = $payload;

            return [];
        });

        $gate->announceSaved($this->key(), ['username' => 'ada', 'role' => 'editor'], [
            'created' => true,
            'reason' => 'save',
        ]);

        $this->assertSame('page:en:about', $seen['key']);
        $this->assertSame('page', $seen['type']);
        $this->assertSame('about', $seen['slug']);
        $this->assertSame('en', $seen['locale']);
        $this->assertTrue($seen['created']);
        $this->assertSame('save', $seen['reason']);
        $this->assertSame(['username' => 'ada', 'role' => 'editor'], $seen['user']);
    }

    /**
     * The payload must not become an exfiltration route.
     *
     * Users are ordinary content documents, so anything that forwarded a whole
     * account would hand every plugin a password hash on every password change.
     * The actor is reduced to an allowlist, so a field added to the session is
     * invisible until someone decides it should be visible.
     */
    public function testTheActorIsReducedToAnAllowlist(): void
    {
        $seen = [];
        $gate = new ContentGate(static function (string $hook, array $payload) use (&$seen): array {
            $seen = $payload;

            return [];
        });

        $gate->announceSaved($this->key(), [
            'username' => 'ada',
            'role' => 'admin',
            'passwordHash' => '$2y$10$secret',
            'email' => 'ada@example.com',
            'csrfToken' => 'abc123',
            'capabilities' => ['publish_content'],
        ]);

        $this->assertSame(['username' => 'ada', 'role' => 'admin'], $seen['user']);
    }

    public function testIdentifyDropsNonScalarsRatherThanForwardingThem(): void
    {
        $this->assertSame(
            ['username' => 'ada'],
            ContentGate::identify(['username' => 'ada', 'role' => ['admin', 'editor']])
        );
    }

    public function testAFactCannotOverwriteTheKeyItDescribes(): void
    {
        // `$facts + [...]` would let a caller's `type` win over the key's. The
        // union order matters, so it is pinned: a listener must be able to trust
        // that `type` came from the document.
        $seen = [];
        $gate = new ContentGate(static function (string $hook, array $payload) use (&$seen): array {
            $seen = $payload;

            return [];
        });

        $gate->announceSaved($this->key(), [], ['created' => false]);

        $this->assertSame('page', $seen['type']);
    }

    public function testListensToDefersToTheInjectedAnswer(): void
    {
        $asked = [];
        $gate = new ContentGate(
            static fn (): array => [],
            static function (string $hook) use (&$asked): bool {
                $asked[] = $hook;

                return $hook === ContentGate::SAVED;
            }
        );

        $this->assertTrue($gate->listensTo(ContentGate::SAVED));
        $this->assertFalse($gate->listensTo(ContentGate::BEFORE_SAVE));
        $this->assertSame([ContentGate::SAVED, ContentGate::BEFORE_SAVE], $asked);
    }

    public function testWithoutAListenerCheckTheGateAssumesSomebodyIsListening(): void
    {
        // Wrong in the cheap direction: a payload built and thrown away, rather
        // than an event silently never fired.
        $this->assertTrue($this->gateAnswering([])->listensTo(ContentGate::SAVED));
    }

    public function testTheHookNamesAreTheDocumentedOnes(): void
    {
        // These strings are the public API — they live in every plugin.json that
        // will ever listen — so renaming one is a breaking change and has to be
        // a deliberate act rather than a refactor.
        $this->assertSame('content.before_save', ContentGate::BEFORE_SAVE);
        $this->assertSame('content.saved', ContentGate::SAVED);
        $this->assertSame('content.before_delete', ContentGate::BEFORE_DELETE);
        $this->assertSame('content.deleted', ContentGate::DELETED);
        $this->assertSame('content.unpublished', ContentGate::UNPUBLISHED);
    }
}
