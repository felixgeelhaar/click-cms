<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugins;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Application\Plugin\PluginManager;
use Click\Cms\Infrastructure\History\JsonVersionStore;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use Click\Cms\Infrastructure\Storage\VersioningStorage;
use PHPUnit\Framework\TestCase;

/**
 * The collaboration plugin, exercised as itself.
 *
 * Each test pins one property the feature rests on. Comments are content
 * documents that round-trip through the same store as everything else and come
 * back in a page's thread; resolving updates rather than deletes; a comment body
 * is untrusted editor text kept as inert data, never markup. Presence is a set
 * of timestamps that self-expires — a fresh beat is listed, a stale one is
 * pruned without any explicit "leave". And, the mirror image of the forms
 * plugin, nothing here is anonymous: no session, or a session without editorial
 * reach, is refused.
 */
final class CollaborationTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private object $plugin;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-collab-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/data', 0o775, true);

        $storage = new VersioningStorage(
            new JsonStorage($this->base . '/content'),
            new JsonVersionStore($this->base . '/data/versions'),
        );
        $this->content = new ContentService($storage);

        $manager = new PluginManager($this->base . '/plugins', $this->base . '/data');
        $manager->setContentService($this->content);

        require_once dirname(__DIR__, 3) . '/plugins/collaboration/bootstrap.php';
        $this->plugin = new \Plugin_collaboration($manager);

        $_POST = [];
        $_GET = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
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

    /**
     * Plant a real session file and the cookie that points at it, so the
     * plugin's own SessionStore lookup resolves a signed-in editor exactly as it
     * would in the HTTP path — the capability gate is then tested for real, not
     * mocked away.
     */
    private function signIn(string $role = 'editor', string $username = 'ada', string $displayName = 'Ada Lovelace'): void
    {
        $id = bin2hex(random_bytes(32));
        $dir = $this->base . '/data/sessions';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        file_put_contents($dir . '/' . $id . '.json', json_encode([
            'lastActivity' => time(),
            'user' => [
                'username' => $username,
                'displayName' => $displayName,
                'role' => $role,
            ],
        ]));
        $_COOKIE[SessionStore::COOKIE] = $id;
    }

    /**
     * @param array<string, string> $body
     * @return array<string, mixed>
     */
    private function postComment(array $body): array
    {
        $_POST = $body;
        $response = $this->plugin->handlePostComment();
        $_POST = [];

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listComments(string $page, string $locale = ''): array
    {
        $_GET = ['page' => $page, 'locale' => $locale];
        $response = $this->plugin->handleListComments();
        $_GET = [];

        return $response['data'] ?? [];
    }

    /* -------------------------------------------------------- comments CRUD -- */

    public function testPostingACommentStoresADocumentAndItComesBackInTheList(): void
    {
        $this->signIn();

        $response = $this->postComment([
            'page' => 'home',
            'locale' => 'en',
            'body' => 'The hero image is the wrong crop.',
        ]);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertArrayHasKey('data', $response);

        // Stored through the content service as a collaboration_comment document,
        // so it inherits storage, backups and the version trail.
        $documents = $this->content->all('collaboration_comment');
        $this->assertCount(1, $documents);
        $this->assertSame('collaboration_comment', $documents[0]->type());

        // And it comes back in that page's thread.
        $thread = $this->listComments('home', 'en');
        $this->assertCount(1, $thread);
        $this->assertSame('The hero image is the wrong crop.', $thread[0]['body']);
        $this->assertSame('Ada Lovelace', $thread[0]['authorName']);
        $this->assertFalse($thread[0]['resolved']);
    }

    public function testACommentIsListedOnlyForItsOwnPage(): void
    {
        $this->signIn();

        $this->postComment(['page' => 'home', 'locale' => 'en', 'body' => 'about home']);
        $this->postComment(['page' => 'about', 'locale' => 'en', 'body' => 'about the about page']);

        $home = $this->listComments('home', 'en');
        $this->assertCount(1, $home);
        $this->assertSame('about home', $home[0]['body']);
    }

    public function testResolvingFlipsTheCommentState(): void
    {
        $this->signIn();

        $created = $this->postComment(['page' => 'home', 'locale' => 'en', 'body' => 'typo in the footer']);
        $id = $created['data']['id'];

        $this->assertFalse($this->listComments('home', 'en')[0]['resolved']);

        $_POST = ['id' => $id];
        $resolved = $this->plugin->handleResolveComment();
        $_POST = [];

        $this->assertArrayNotHasKey('error', $resolved);
        $this->assertTrue($resolved['data']['resolved']);
        $this->assertSame('ada', $resolved['data']['resolvedBy']);

        // The flip is persisted, not just returned.
        $this->assertTrue($this->listComments('home', 'en')[0]['resolved']);
    }

    public function testResolvingAMissingCommentIs404(): void
    {
        $this->signIn();

        $_POST = ['id' => 'does-not-exist'];
        $response = $this->plugin->handleResolveComment();
        $_POST = [];

        $this->assertSame(404, $response['status'] ?? 200);
    }

    public function testAnEmptyCommentIsRefused(): void
    {
        $this->signIn();

        $response = $this->postComment(['page' => 'home', 'locale' => 'en', 'body' => '   ']);

        $this->assertSame(400, $response['status'] ?? 200);
        $this->assertSame([], $this->content->all('collaboration_comment'));
    }

    /* ------------------------------------------------------------- auth gate -- */

    public function testAnUnauthenticatedRequestIsRefused(): void
    {
        // No session at all.
        $this->assertSame(403, $this->postComment(['page' => 'home', 'body' => 'hi'])['status'] ?? 200);

        $_GET = ['page' => 'home'];
        $this->assertSame(403, $this->plugin->handleListComments()['status'] ?? 200);
        $this->assertSame(403, $this->plugin->handlePresence()['status'] ?? 200);
        $_GET = [];

        $_POST = ['page' => 'home'];
        $this->assertSame(403, $this->plugin->handleHeartbeat()['status'] ?? 200);
        $_POST = [];

        // Nothing was written by any refused call.
        $this->assertSame([], $this->content->all('collaboration_comment'));
    }

    public function testAnAuthenticatedButUnderPrivilegedAccountIsRefused(): void
    {
        // An author may write their own drafts but cannot edit any content, so
        // they have no business in another page's review thread.
        $this->signIn(role: 'author', username: 'bob', displayName: 'Bob');

        $response = $this->postComment(['page' => 'home', 'locale' => 'en', 'body' => 'let me in']);

        $this->assertSame(403, $response['status'] ?? 200);
        $this->assertSame([], $this->content->all('collaboration_comment'));
    }

    /* -------------------------------------------------------------- presence -- */

    public function testPresenceListsAFreshHeartbeatAndPrunesAStaleOne(): void
    {
        $now = 1_000_000;

        // Ada beat just now; Hanna beat two minutes ago — well past the 30s TTL.
        $this->plugin->recordPresence('home', 'en', 'ada', 'Ada Lovelace', $now);
        $this->plugin->recordPresence('home', 'en', 'hanna', 'Hanna', $now - 120);

        $roster = $this->plugin->presenceFor('home', 'en', $now);

        $names = array_map(static fn (array $e): string => $e['name'], $roster);
        $this->assertContains('Ada Lovelace', $names, 'a fresh heartbeat is present');
        $this->assertNotContains('Hanna', $names, 'a stale heartbeat is pruned');
    }

    public function testHeartbeatingAgainRefreshesRatherThanDuplicates(): void
    {
        $now = 2_000_000;

        $this->plugin->recordPresence('home', 'en', 'ada', 'Ada Lovelace', $now - 20);
        // Same person, later beat: keeps them present past when the first would
        // have expired, and does not list them twice.
        $this->plugin->recordPresence('home', 'en', 'ada', 'Ada Lovelace', $now);

        $roster = $this->plugin->presenceFor('home', 'en', $now);
        $this->assertCount(1, $roster);
        $this->assertSame('Ada Lovelace', $roster[0]['name']);
    }

    public function testPresenceIsPerPageAndLanguage(): void
    {
        $now = 3_000_000;

        $this->plugin->recordPresence('home', 'en', 'ada', 'Ada', $now);
        $this->plugin->recordPresence('home', 'de', 'hanna', 'Hanna', $now);

        $en = $this->plugin->presenceFor('home', 'en', $now);
        $this->assertCount(1, $en);
        $this->assertSame('Ada', $en[0]['name']);
    }

    /* ------------------------------------------ untrusted input stays as data -- */

    public function testACommentBodyIsStoredAsInertDataNotExecutableMarkup(): void
    {
        $this->signIn();

        $payload = '<script>alert(document.cookie)</script>';
        $this->postComment(['page' => 'home', 'locale' => 'en', 'body' => $payload]);

        // Returned verbatim, the exact bytes typed — a string, not parsed, not
        // run. Whoever displays it escapes it.
        $thread = $this->listComments('home', 'en');
        $this->assertSame($payload, $thread[0]['body']);

        // And on disk it is a JSON string value, treated as nothing but text.
        $onDisk = $this->content->all('collaboration_comment')[0]->data;
        $this->assertIsString($onDisk['body']);
        $this->assertSame($payload, $onDisk['body']);

        // When a server-side HTML context is ever built from it, the plugin's
        // escaper renders it inert — the script tag survives as escaped text, not
        // as markup a browser would execute.
        $escaped = $this->plugin->escape($thread[0]['body']);
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }
}
