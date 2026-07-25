<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Content;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Plugin\PublishGate;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The first extension point that can say no.
 *
 * Everything here pins one half of the same contract: a plugin with something to
 * say stops the publish and its words reach the editor, and a plugin with
 * nothing to say — including one that is simply broken — is not allowed to stop
 * anything. The second half matters more than the first. A review workflow that
 * fails to gate is a missing feature; a CMS that cannot publish because an
 * unrelated plugin threw is a site nobody can update, and the person who could
 * fix it is locked out by the same fault.
 */
final class PublishGateTest extends TestCase
{
    private string $root;
    private ContentService $content;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/click-cms-gate-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/content', 0o775, true);

        $this->content = new ContentService(new VersioningStorage(
            new JsonStorage($this->root . '/content'),
            new JsonVersionStore($this->root . '/versions'),
        ));

        // No test may inherit a gate another test installed, and none may leave
        // one behind: the ambient gate is process-wide by design.
        PublishGate::useAmbient(null);
    }

    protected function tearDown(): void
    {
        PublishGate::useAmbient(null);
        $this->removeTree($this->root);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function admin(): array
    {
        return ['username' => 'boss', 'role' => 'admin'];
    }

    private function pages(?PublishGate $gate): PageService
    {
        return new PageService(
            $this->content,
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections'),
            new \Click\Cms\Domain\Schema\SectionValidator(),
            [],
            $gate,
        );
    }

    /** A gate whose plugins answer with whatever the callable returns. */
    private function gateReturning(\Closure $dispatch): PublishGate
    {
        return new PublishGate($dispatch);
    }

    private function draft(string $slug): void
    {
        $this->pages(null)->create(['title' => 'Draft', 'slug' => $slug], $this->admin());
    }

    private function isLive(string $slug): bool
    {
        return $this->content->page($slug) !== null;
    }

    /* -------------------------------------------------------------- refusal -- */

    public function testAPluginCanRefuseAPublishAndItsReasonReachesTheCaller(): void
    {
        $this->draft('news');

        $result = $this->pages($this->gateReturning(
            static fn (): array => ['Collaboration' => [
                'allowed' => false,
                'reason' => 'This page is waiting for review and has not been approved yet.',
            ]]
        ))->publish('news', $this->admin());

        $this->assertSame(
            'This page is waiting for review and has not been approved yet.',
            $result['error']
        );
        // A conflict, not a forbidden: the account may publish, the page may not
        // be published.
        $this->assertSame(409, $result['status']);
        $this->assertFalse($this->isLive('news'), 'a refused publish must not reach the public');
    }

    public function testARefusalWithNoReasonStillRefusesAndSaysWhoRefused(): void
    {
        $this->draft('news');

        $result = $this->pages($this->gateReturning(
            static fn (): array => ['Embargo' => ['allowed' => false]]
        ))->publish('news', $this->admin());

        $this->assertSame(409, $result['status']);
        $this->assertStringContainsString('Embargo', (string) $result['error']);
        $this->assertFalse($this->isLive('news'));
    }

    public function testTheFirstRefusalWins(): void
    {
        $this->draft('news');

        $result = $this->pages($this->gateReturning(static fn (): array => [
            'First' => ['allowed' => false, 'reason' => 'the first reason'],
            'Second' => ['allowed' => false, 'reason' => 'the second reason'],
        ]))->publish('news', $this->admin());

        $this->assertSame('the first reason', $result['error']);
    }

    /* -------------------------------------------------------------- silence -- */

    public function testEveryShapeThatIsNotARefusalPermits(): void
    {
        $silences = [
            'no plugin answered' => [],
            'a plugin returned null' => ['Quiet' => null],
            'a plugin returned an empty array' => ['Quiet' => []],
            'a plugin said nothing about allowing' => ['Quiet' => ['note' => 'just looking']],
            'a plugin approved explicitly' => ['Quiet' => ['allowed' => true]],
            'a plugin returned a scalar' => ['Quiet' => 'ok'],
            // A truthy-but-not-false value is not a refusal. Only an exact
            // `false` is, so a plugin cannot block by returning a stray zero.
            'a plugin returned a falsy non-false' => ['Quiet' => ['allowed' => 0]],
        ];

        foreach ($silences as $label => $opinions) {
            $slug = 'page-' . substr(md5($label), 0, 8);
            $this->draft($slug);

            $result = $this->pages($this->gateReturning(
                static fn (): array => $opinions
            ))->publish($slug, $this->admin());

            $this->assertNull($result['error'], $label . ' must not block a publish');
            $this->assertTrue($this->isLive($slug), $label . ' must not block a publish');
        }
    }

    public function testAGateWithNoPluginsBehindItPermits(): void
    {
        $this->draft('news');

        $this->assertNull($this->pages(new PublishGate())->publish('news', $this->admin())['error']);
        $this->assertTrue($this->isLive('news'));
    }

    /* ------------------------------------------------------- broken plugins -- */

    public function testAThrowingDispatchDoesNotTakePublishingDown(): void
    {
        $this->draft('news');

        $result = $this->pages($this->gateReturning(
            static function (): array {
                throw new \RuntimeException('the plugin is broken');
            }
        ))->publish('news', $this->admin());

        // Treated as no opinion. A broken gate that lets a page out is
        // recoverable; a CMS that cannot publish anything is not.
        $this->assertNull($result['error']);
        $this->assertTrue($this->isLive('news'));
    }

    public function testAThrowingAfterPublishListenerDoesNotUndoTheResponse(): void
    {
        $this->draft('news');

        $result = $this->pages($this->gateReturning(
            static function (string $hook): array {
                if ($hook === PublishGate::PUBLISHED_HOOK) {
                    throw new \RuntimeException('the notifier is broken');
                }

                return [];
            }
        ))->publish('news', $this->admin());

        // The page is live, so the editor must be told it is live. Failing here
        // would report a failure about work that had already succeeded.
        $this->assertNull($result['error']);
        $this->assertSame(200, $result['status']);
        $this->assertTrue($this->isLive('news'));
    }

    /* ---------------------------------------------------- what is asked, when -- */

    public function testThePluginIsToldWhichDocumentAndWhoIsPublishingIt(): void
    {
        $this->draft('news');

        $seen = [];
        $this->pages($this->gateReturning(
            static function (string $hook, array $payload) use (&$seen): array {
                $seen[$hook] = $payload;

                return [];
            }
        ))->publish('news', ['username' => 'ada', 'role' => 'admin']);

        $before = $seen[PublishGate::HOOK] ?? [];
        $this->assertSame('page:en:news', $before['key'] ?? null);
        // The parts as well as the whole, so a plugin never has to parse a key
        // format core owns.
        $this->assertSame('page', $before['type'] ?? null);
        $this->assertSame('news', $before['slug'] ?? null);
        $this->assertSame('en', $before['locale'] ?? null);
        // Who, because "you cannot approve your own request" is unanswerable
        // without it.
        $this->assertSame('ada', $before['user']['username'] ?? null);
    }

    public function testTheAfterHookFiresOnlyOnceThePageIsActuallyLive(): void
    {
        $this->draft('kept');
        $this->draft('refused');

        $fired = [];
        $record = static function (string $hook, array $payload) use (&$fired): array {
            $fired[] = $hook . ':' . $payload['slug'];

            return $payload['slug'] === 'refused'
                ? ['Gate' => ['allowed' => false, 'reason' => 'not yet']]
                : [];
        };

        $pages = $this->pages($this->gateReturning($record));
        $pages->publish('refused', $this->admin());
        $pages->publish('kept', $this->admin());

        $this->assertContains(PublishGate::PUBLISHED_HOOK . ':kept', $fired);
        $this->assertNotContains(
            PublishGate::PUBLISHED_HOOK . ':refused',
            $fired,
            'nothing may be told a publish happened when it did not'
        );
    }

    public function testAnAccountThatMayNotPublishIsRefusedBeforeThePluginIsAsked(): void
    {
        $this->draft('news');

        $asked = false;
        $result = $this->pages($this->gateReturning(
            static function () use (&$asked): array {
                $asked = true;

                return [];
            }
        ))->publish('news', ['username' => 'ada', 'role' => 'author']);

        $this->assertSame(403, $result['status']);
        // An editorial state is information about unpublished work. Somebody who
        // may not publish at all has no business being told why they could not.
        $this->assertFalse($asked, 'permission is settled before an editorial gate is consulted');
    }

    /* -------------------------------------------------------------- ambient -- */

    public function testACallerHandedNoGateStillGoesThroughTheInstalledOne(): void
    {
        $this->draft('news');

        // This is the case that matters in production: the HTTP layer builds its
        // own PageService and knows nothing about plugins, so a gate it is not
        // handed has to be one it cannot avoid.
        PublishGate::useAmbient($this->gateReturning(
            static fn (): array => ['Collaboration' => ['allowed' => false, 'reason' => 'not approved']]
        ));

        $result = $this->pages(null)->publish('news', $this->admin());

        $this->assertSame('not approved', $result['error']);
        $this->assertFalse($this->isLive('news'));
    }

    public function testWithNoGateInstalledEverythingPublishesAsBefore(): void
    {
        $this->draft('news');

        $this->assertNull($this->pages(null)->publish('news', $this->admin())['error']);
        $this->assertTrue($this->isLive('news'));
    }
}
