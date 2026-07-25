<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugins;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Content\PageService;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Application\Plugin\PublishGate;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Schema\JsonSectionTypeRepository;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The review workflow: request, decide, gate, release.
 *
 * The properties worth pinning are the ones that make the feature mean
 * something rather than merely exist. Nobody approves their own request, or the
 * record of consent is a record of nothing. The gate is off until a site asks
 * for it, or shipping this plugin breaks every installation that wanted presence
 * and comments. And a release is refused as a unit, because half of an approved
 * release reaching the public is precisely the half-updated site
 * `collaboration.md` exists to argue against.
 */
final class CollaborationReviewTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private PageService $pages;
    private object $plugin;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-review-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);

        $storage = new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/data/versions'),
        );
        $this->content = new ContentService($storage);
        $this->pages = new PageService(
            $this->content,
            new JsonSectionTypeRepository(dirname(__DIR__, 3) . '/config/sections')
        );

        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $manager->setContentService($this->content);

        require_once dirname(__DIR__, 3) . '/plugins/collaboration/bootstrap.php';
        $this->plugin = new \Plugin_collaboration($manager);

        // The gate is process-wide. Neither inherited nor left behind.
        PublishGate::useAmbient(null);

        $_POST = [];
        $_GET = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        PublishGate::useAmbient(null);
        $_POST = [];
        $_GET = [];
        $_COOKIE = [];
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    /** @return array<string, mixed> */
    private function signIn(string $role = 'editor', string $username = 'ada', string $displayName = 'Ada Lovelace'): array
    {
        $id = bin2hex(random_bytes(32));
        $dir = $this->base . '/data/sessions';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $user = ['username' => $username, 'displayName' => $displayName, 'role' => $role];
        file_put_contents($dir . '/' . $id . '.json', json_encode([
            'lastActivity' => time(),
            'user' => $user,
        ]));
        $_COOKIE[SessionStore::COOKIE] = $id;

        return $user;
    }

    /** @param array<string, mixed> $body */
    private function post(string $handler, array $body): array
    {
        $_POST = $body;
        $response = $this->plugin->$handler();
        $_POST = [];

        return $response;
    }

    /** Turn the gate on the way a site would: through the admin-only endpoint. */
    private function armTheGate(): void
    {
        $this->signIn(role: 'admin', username: 'boss', displayName: 'The Boss');
        $response = $this->post('handleWriteReviewSettings', ['enabled' => true]);
        $this->assertTrue($response['data']['enabled'] ?? false);
    }

    private function makePage(string $slug, string $owner = 'ada'): void
    {
        $this->pages->create(
            ['title' => ucfirst($slug), 'slug' => $slug],
            ['username' => $owner, 'role' => 'editor']
        );
    }

    /** What core would hand the gate when publishing this page. */
    private function askTheGate(string $slug, string $locale = 'en'): ?array
    {
        return $this->plugin->hook_content_before_publish([
            'key' => "page:{$locale}:{$slug}",
            'type' => 'page',
            'slug' => $slug,
            'locale' => $locale,
            'user' => ['username' => 'ada', 'role' => 'editor'],
        ]);
    }

    private function state(string $slug, string $locale = 'en'): string
    {
        return (string) $this->plugin->reviewFor($slug, $locale)['state'];
    }

    /* ---------------------------------------------------------- requesting -- */

    public function testRequestingAReviewStoresItAsAnOrdinaryContentDocument(): void
    {
        $this->signIn();

        $response = $this->post('handleRequestReview', [
            'page' => 'home',
            'locale' => 'en',
            'note' => 'Please check the German quote before this goes out.',
            'reviewer' => 'hanna',
        ]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertSame('in_review', $response['data']['state']);
        $this->assertSame('hanna', $response['data']['reviewer']);
        $this->assertSame('Ada Lovelace', $response['data']['requestedByName']);

        // Stored the same way presence and comments are, so it inherits storage,
        // backups and the version trail rather than inventing a place to live.
        $documents = $this->content->all('collaboration_review');
        $this->assertCount(1, $documents);
        $this->assertSame('collaboration_review', $documents[0]->type());
    }

    public function testAskingTwiceDoesNotSilentlyReplaceTheFirstRequest(): void
    {
        $this->signIn();
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en', 'note' => 'first']);

        $second = $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en', 'note' => 'second']);

        $this->assertSame(409, $second['status'] ?? 200);
        $this->assertSame('first', $this->plugin->reviewFor('home', 'en')['note']);
    }

    public function testAReviewIsPerPageAndLanguage(): void
    {
        $this->signIn();
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'de']);

        $this->assertSame('in_review', $this->state('home', 'de'));
        $this->assertSame('', $this->state('home', 'en'));
        $this->assertSame('', $this->state('about', 'de'));
    }

    /* ----------------------------------------------------------- deciding -- */

    public function testNobodyApprovesTheirOwnRequest(): void
    {
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);

        $response = $this->post('handleReviewDecision', [
            'page' => 'home',
            'locale' => 'en',
            'decision' => 'approve',
        ]);

        $this->assertSame(403, $response['status'] ?? 200);
        $this->assertSame('in_review', $this->state('home'));
    }

    public function testNotEvenAnAdministratorApprovesTheirOwnRequest(): void
    {
        // No override, deliberately: an override that exists is an override that
        // becomes routine, and then every recorded approval means nothing. A
        // single-person site leaves the gate off instead.
        $this->signIn(role: 'admin', username: 'boss');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);

        $response = $this->post('handleReviewDecision', [
            'page' => 'home',
            'locale' => 'en',
            'decision' => 'approve',
        ]);

        $this->assertSame(403, $response['status'] ?? 200);
    }

    public function testSomebodyElseApprovingRecordsWhoAndWhen(): void
    {
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);

        $this->signIn(username: 'hanna', displayName: 'Hanna Berg');
        $response = $this->post('handleReviewDecision', [
            'page' => 'home',
            'locale' => 'en',
            'decision' => 'approve',
            'note' => 'Reads well.',
        ]);

        $this->assertSame('approved', $response['data']['state']);
        $this->assertSame('hanna', $response['data']['decidedBy']);
        $this->assertSame('Reads well.', $response['data']['decisionNote']);
        $this->assertNotSame('', $response['data']['decidedAt']);

        // The trail, which is what a future notifier reads.
        $this->assertCount(2, $response['data']['history']);
    }

    public function testAskingForChangesOnYourOwnRequestIsAllowed(): void
    {
        // Finding more work to do is not consenting to your own change.
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);

        $response = $this->post('handleReviewDecision', [
            'page' => 'home',
            'locale' => 'en',
            'decision' => 'changes',
            'note' => 'The second paragraph is wrong.',
        ]);

        $this->assertSame('changes_requested', $response['data']['state'] ?? null);
    }

    public function testADecisionOnAPageNobodyAskedAboutIsRefused(): void
    {
        $this->signIn();

        $response = $this->post('handleReviewDecision', [
            'page' => 'home',
            'locale' => 'en',
            'decision' => 'approve',
        ]);

        $this->assertSame(409, $response['status'] ?? 200);
        $this->assertSame('', $this->state('home'));
    }

    public function testAnAccountWithoutEditorialReachCannotTouchAReview(): void
    {
        $this->signIn(role: 'author', username: 'bob');

        $this->assertSame(403, $this->post('handleRequestReview', ['page' => 'home'])['status'] ?? 200);
        $this->assertSame(403, $this->post('handleReviewDecision', [
            'page' => 'home',
            'decision' => 'approve',
        ])['status'] ?? 200);
        $this->assertSame([], $this->content->all('collaboration_review'));
    }

    public function testARequestAfterChangesWereAskedForStartsACleanCycle(): void
    {
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);
        $this->signIn(username: 'hanna');
        $this->post('handleReviewDecision', ['page' => 'home', 'locale' => 'en', 'decision' => 'changes']);

        $this->signIn(username: 'ada');
        $response = $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);

        $this->assertSame('in_review', $response['data']['state']);
        // The old decision is gone rather than left standing, so a stale
        // approval can never unlock a page that has changed since.
        $this->assertNull($response['data']['decidedBy']);
        // The trail is not: three events so far.
        $this->assertCount(3, $response['data']['history']);
    }

    /* ------------------------------------------------------------- the gate -- */

    public function testTheGateIsOffUntilASiteAsksForIt(): void
    {
        $this->signIn();
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);

        // In review, and publishing is still permitted: a site that installed
        // this plugin for presence and comments must not discover it cannot
        // publish.
        $this->assertNull($this->askTheGate('home'));
        $this->assertNull($this->plugin->reviewRefusalFor('home', 'en'));
    }

    public function testAnArmedGateRefusesAnUnfinishedReviewAndNamesTheState(): void
    {
        $this->armTheGate();
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);

        $refusal = $this->askTheGate('home');
        $this->assertFalse($refusal['allowed'] ?? true);
        $this->assertStringContainsString('waiting for review', $refusal['reason']);

        $this->signIn(username: 'hanna');
        $this->post('handleReviewDecision', ['page' => 'home', 'locale' => 'en', 'decision' => 'changes']);

        $refusal = $this->askTheGate('home');
        $this->assertFalse($refusal['allowed'] ?? true);
        $this->assertStringContainsString('asked for changes', $refusal['reason']);
    }

    public function testAnArmedGateLetsAnApprovedPageAndAnUnreviewedPageThrough(): void
    {
        $this->armTheGate();
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);
        $this->signIn(username: 'hanna');
        $this->post('handleReviewDecision', ['page' => 'home', 'locale' => 'en', 'decision' => 'approve']);

        $this->assertNull($this->askTheGate('home'));
        // And a page nobody sent for review is not this plugin's business:
        // requiring review of everything is an editorial policy, not something a
        // plugin may impose by existing.
        $this->assertNull($this->askTheGate('about'));
    }

    public function testTheGateHasNoOpinionAboutThingsThatAreNotPages(): void
    {
        $this->armTheGate();
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);

        $this->assertNull($this->plugin->hook_content_before_publish([
            'key' => 'post:en:home',
            'type' => 'post',
            'slug' => 'home',
            'locale' => 'en',
            'user' => [],
        ]));
    }

    public function testOnlyAnAdministratorCanArmTheGate(): void
    {
        // An editor who could switch the gate off could publish their own
        // unapproved page by turning off the thing about to stop them.
        $this->signIn(role: 'editor');
        $this->assertSame(403, $this->post('handleWriteReviewSettings', ['enabled' => true])['status'] ?? 200);

        $this->signIn(role: 'admin', username: 'boss');
        $this->assertTrue($this->post('handleWriteReviewSettings', ['enabled' => true])['data']['enabled']);

        // But any collaborator may read it: an editor about to press Publish is
        // entitled to know whether it can refuse them.
        $this->signIn(role: 'editor');
        $this->assertTrue($this->plugin->handleReadReviewSettings()['data']['enabled']);
    }

    /* --------------------------------------------------- clearing on publish -- */

    public function testPublishingFinishesTheReview(): void
    {
        $this->armTheGate();
        $this->makePage('home');
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);
        $this->signIn(username: 'hanna');
        $this->post('handleReviewDecision', ['page' => 'home', 'locale' => 'en', 'decision' => 'approve']);

        $this->plugin->hook_content_published([
            'key' => 'page:en:home',
            'type' => 'page',
            'slug' => 'home',
            'locale' => 'en',
            'user' => ['username' => 'hanna', 'role' => 'editor'],
        ]);

        // Finished, and no longer blocking a later publish of the next change.
        $this->assertSame('published', $this->state('home'));
        $this->assertNull($this->askTheGate('home'));

        // Moved rather than deleted: this is the only record that what went live
        // had been approved, and by whom.
        $this->assertCount(1, $this->content->all('collaboration_review'));
    }

    public function testPublishingAPageThatWasNeverInReviewChangesNothing(): void
    {
        $this->plugin->hook_content_published([
            'key' => 'page:en:about',
            'type' => 'page',
            'slug' => 'about',
            'locale' => 'en',
            'user' => ['username' => 'ada', 'role' => 'editor'],
        ]);

        $this->assertSame([], $this->content->all('collaboration_review'));
    }

    /* -------------------------------------------------------------- cancel -- */

    public function testTheRequesterMayWithdrawAndAStrangerMayNotCancel(): void
    {
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);

        $this->signIn(username: 'hanna');
        $this->assertSame(403, $this->post('handleCancelReview', ['page' => 'home', 'locale' => 'en'])['status'] ?? 200);

        $this->signIn(username: 'ada');
        $response = $this->post('handleCancelReview', ['page' => 'home', 'locale' => 'en']);
        $this->assertSame('cancelled', $response['data']['state']);
    }

    public function testAnAdministratorCanCancelSomebodyElsesReviewButItIsNotAnApproval(): void
    {
        $this->armTheGate();
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);

        $this->signIn(role: 'admin', username: 'boss');
        $response = $this->post('handleCancelReview', ['page' => 'home', 'locale' => 'en']);

        $this->assertSame('cancelled', $response['data']['state']);
        // Unblocked, but the record says cancelled — nothing here can be
        // mistaken afterwards for consent somebody gave.
        $this->assertNull($this->askTheGate('home'));
        $this->assertSame('', (string) $this->plugin->reviewFor('home', 'en')['decidedBy']);
    }

    /* ---------------------------------------------------------- reading it -- */

    public function testTheOpenReviewListShowsOnlyWhatIsStillWaiting(): void
    {
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);
        $this->post('handleRequestReview', ['page' => 'about', 'locale' => 'en']);
        $this->signIn(username: 'hanna');
        $this->post('handleReviewDecision', ['page' => 'about', 'locale' => 'en', 'decision' => 'approve']);

        $_GET = [];
        $open = $this->plugin->handleReadReview()['data']['open'];
        $_GET = [];

        $this->assertCount(1, $open);
        $this->assertSame('home', $open[0]['page']);
    }

    public function testANoteIsStoredAsInertDataNotMarkup(): void
    {
        $this->signIn();
        $payload = '<script>alert(1)</script>';

        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en', 'note' => $payload]);

        $this->assertSame($payload, $this->plugin->reviewFor('home', 'en')['note']);
        $this->assertStringContainsString('&lt;script&gt;', $this->plugin->escape($payload));
    }

    /* ------------------------------------------------------ publish together -- */

    public function testAReleasePublishesEveryPageAtOnce(): void
    {
        $this->makePage('home');
        $this->makePage('about');
        $this->signIn(username: 'ada');

        $response = $this->post('handlePublishTogether', [
            'pages' => ['home', 'about'],
            'locale' => 'en',
        ]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertCount(2, $response['data']['published']);
        $this->assertNotNull($this->content->page('home'));
        $this->assertNotNull($this->content->page('about'));

        // And the release itself is on the record, so "which changes went out
        // together" is answerable at all.
        $this->assertCount(1, $this->content->all('collaboration_release'));
    }

    public function testAReleaseIsRefusedWholeWhenOnePageIsNotApproved(): void
    {
        $this->armTheGate();
        $this->makePage('home');
        $this->makePage('about');

        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);
        $this->post('handleRequestReview', ['page' => 'about', 'locale' => 'en']);
        $this->signIn(username: 'hanna');
        // Only one of the two is approved.
        $this->post('handleReviewDecision', ['page' => 'home', 'locale' => 'en', 'decision' => 'approve']);

        $response = $this->post('handlePublishTogether', ['pages' => ['home', 'about'], 'locale' => 'en']);

        $this->assertSame(409, $response['status'] ?? 200);
        $this->assertSame([], $response['data']['published']);
        $this->assertSame('about', $response['data']['refused'][0]['page']);

        // Neither page went live — not even the approved one. A half-published
        // release is the failure this endpoint exists to prevent, not a partial
        // success to be tolerated.
        $this->assertNull($this->content->page('home'));
        $this->assertNull($this->content->page('about'));
    }

    public function testAReleaseNamesEveryPageThatIsNotReadyRatherThanTheFirst(): void
    {
        $this->armTheGate();
        $this->makePage('home');
        $this->makePage('about');
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);
        $this->post('handleRequestReview', ['page' => 'about', 'locale' => 'en']);

        $response = $this->post('handlePublishTogether', ['pages' => ['home', 'about'], 'locale' => 'en']);

        $this->assertCount(2, $response['data']['refused']);
    }

    public function testAReleaseRefusesAPageThatDoesNotExist(): void
    {
        $this->makePage('home');
        $this->signIn(username: 'ada');

        $response = $this->post('handlePublishTogether', [
            'pages' => ['home', 'never-written'],
            'locale' => 'en',
        ]);

        $this->assertSame(409, $response['status'] ?? 200);
        $this->assertNull($this->content->page('home'), 'nothing goes out when the set is incomplete');
    }

    public function testAReleaseObeysTheSameOwnershipRuleASinglePublishDoes(): void
    {
        $this->makePage('home', owner: 'someone-else');
        // An author may not publish anything, their own work included; a release
        // must not be a way around the rule the publish endpoint applies.
        $this->signIn(role: 'author', username: 'bob');

        $response = $this->post('handlePublishTogether', ['pages' => ['home'], 'locale' => 'en']);

        $this->assertSame(403, $response['status'] ?? 200);
        $this->assertNull($this->content->page('home'));
    }

    public function testAReleaseFinishesEveryReviewItPublished(): void
    {
        $this->armTheGate();
        $this->makePage('home');
        $this->makePage('about');
        $this->signIn(username: 'ada');
        $this->post('handleRequestReview', ['page' => 'home', 'locale' => 'en']);
        $this->post('handleRequestReview', ['page' => 'about', 'locale' => 'en']);
        $this->signIn(username: 'hanna');
        $this->post('handleReviewDecision', ['page' => 'home', 'locale' => 'en', 'decision' => 'approve']);
        $this->post('handleReviewDecision', ['page' => 'about', 'locale' => 'en', 'decision' => 'approve']);

        // The release publishes through the same service a single publish uses,
        // so the after-publish hook that closes a review runs for each page —
        // but only because this plugin is wired to it, which the ambient gate is
        // what supplies.
        PublishGate::useAmbient(new PublishGate(
            function (string $hook, array $payload): array {
                if ($hook === PublishGate::PUBLISHED_HOOK) {
                    $this->plugin->hook_content_published($payload);
                }

                return [];
            }
        ));

        $response = $this->post('handlePublishTogether', ['pages' => ['home', 'about'], 'locale' => 'en']);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertSame('published', $this->state('home'));
        $this->assertSame('published', $this->state('about'));
    }

    public function testAReleaseNeedsAtLeastOnePage(): void
    {
        $this->signIn(username: 'ada');

        $this->assertSame(400, $this->post('handlePublishTogether', ['pages' => []])['status'] ?? 200);
        $this->assertSame(400, $this->post('handlePublishTogether', [])['status'] ?? 200);
    }

    public function testAReleaseNamingTheSamePageTwicePublishesItOnce(): void
    {
        $this->makePage('home');
        $this->signIn(username: 'ada');

        $response = $this->post('handlePublishTogether', [
            'pages' => ['home', ['page' => 'home', 'locale' => 'en']],
            'locale' => 'en',
        ]);

        $this->assertCount(1, $response['data']['published']);
    }
}
