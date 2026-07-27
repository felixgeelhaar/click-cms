<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Http\ApiGuard;
use PHPUnit\Framework\TestCase;

/**
 * The API security gate, tested one case at a time.
 *
 * These rules used to be reachable only through a full request against the whole
 * kernel. As their own unit they can be pinned directly — which matters, because
 * a mistake here does not throw or 500: it silently makes something reachable
 * that should not be, and the only signal is a test that was watching for it.
 */
final class ApiGuardTest extends TestCase
{
    private string $base;
    private ApiGuard $guard;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-guard-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/sessions', 0o700, true);
        $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->guard = new ApiGuard(new SessionStore($this->base . '/sessions', 1800));
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        foreach (glob($this->base . '/sessions/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->base . '/sessions');
        @rmdir($this->base);
    }

    /** Put a session on disk and hand its id to the request via the cookie. */
    private function signIn(string $role, bool $mustChangePassword = false): string
    {
        $id = bin2hex(random_bytes(32));
        file_put_contents(
            $this->base . '/sessions/' . $id . '.json',
            json_encode([
                'username' => 'u',
                'expiresAt' => time() + 3600,
                'lastActivity' => time(),
                'csrfToken' => 'the-real-token',
                'user' => ['username' => 'u', 'role' => $role, 'mustChangePassword' => $mustChangePassword],
            ])
        );
        $_COOKIE[SessionStore::COOKIE] = $id;

        return $id;
    }

    /* ------------------------------------------------ public paths -- */

    public function testPublishedContentIsPublicToRead(): void
    {
        $this->assertTrue($this->guard->isPublic('pages', 'GET'));
        $this->assertTrue($this->guard->isPublic('pages/home', 'GET'));
        $this->assertTrue($this->guard->isPublic('media/file/x.jpg', 'GET'));
        $this->assertTrue($this->guard->isPublic('graphql', 'POST'));
        $this->assertTrue($this->guard->isPublic('search', 'GET'));
        $this->assertTrue($this->guard->isPublic('forms/submit', 'POST'));
        $this->assertTrue($this->guard->isPublic('auth/login', 'POST'));
        $this->assertTrue($this->guard->isPublic('collections/blog/published', 'GET'));
        $this->assertTrue($this->guard->isPublic('collections/blog/published/hello', 'GET'));
        // The entry preview delivery is reachable anonymously; the handler gates
        // it on a signed token, exactly as the page /preview route does.
        $this->assertTrue($this->guard->isPublic('collections/blog/preview/hello', 'GET'));
    }

    public function testManagementAndWritesAreNotPublic(): void
    {
        $this->assertFalse($this->guard->isPublic('media', 'GET'));
        $this->assertFalse($this->guard->isPublic('users', 'GET'));
        $this->assertFalse($this->guard->isPublic('pages', 'POST'));
        // The one that has bitten before: version history hangs off a public
        // prefix but must never be anonymously readable.
        $this->assertFalse($this->guard->isPublic('pages/home/versions', 'GET'));
        $this->assertFalse($this->guard->isPublic('pages/home/versions/abc', 'GET'));
        // A schedule hangs off the same public prefix as version history and
        // leaks the same class of thing: unpublished editorial intent. Knowing
        // a page goes live at nine tomorrow is knowing about a page that is not
        // public yet, and for an embargoed announcement that is the whole
        // secret.
        $this->assertFalse($this->guard->isPublic('pages/home/schedule', 'GET'));
        $this->assertFalse($this->guard->isPublic('schedule', 'GET'));
        // Webhook management is deny-by-default like everything unnamed. Worth
        // pinning rather than assuming: the endpoint list is a set of URLs this
        // server can be made to fetch, and the delivery log describes changes
        // that may not be public yet.
        $this->assertFalse($this->guard->isPublic('webhooks', 'GET'));
        $this->assertFalse($this->guard->isPublic('webhooks/deliveries', 'GET'));
        // Entry management and draft reads stay private; only /published and the
        // signature-gated /preview are open.
        $this->assertFalse($this->guard->isPublic('collections/blog/entries', 'GET'));
        $this->assertFalse($this->guard->isPublic('collections/blog/entries/hello', 'GET'));
        // "What links here" may surface drafts, so it is management, not delivery.
        $this->assertFalse($this->guard->isPublic('collections/blog/entries/hello/backreferences', 'GET'));
        // Minting a preview link is a POST and must stay authenticated.
        $this->assertFalse($this->guard->isPublic('collections/blog/entries/hello/preview', 'POST'));
    }

    /* --------------------------------------------- authentication -- */

    public function testAnAnonymousRequestToAProtectedPathIsRefused(): void
    {
        $this->assertSame(401, $this->guard->enforceAuth('media', 'GET')['status']);
    }

    public function testAnAnonymousRequestToAPublicPathIsAllowed(): void
    {
        $this->assertNull($this->guard->enforceAuth('pages/home', 'GET'));
    }

    public function testASignedInUserReachesAManagementPath(): void
    {
        $this->signIn('editor');
        $this->assertNull($this->guard->enforceAuth('media', 'GET'));
    }

    public function testAnOutstandingPasswordChangeBlocksEverything(): void
    {
        $this->signIn('admin', mustChangePassword: true);

        // Even a public path, even a read: the seeded password must be changed
        // before anything else can be reached at all.
        $result = $this->guard->enforceAuth('pages/home', 'GET');
        $this->assertSame(403, $result['status']);
        $this->assertTrue($result['mustChangePassword']);
    }

    public function testAnEditorCannotReachUserManagement(): void
    {
        $this->signIn('editor');
        $this->assertSame(403, $this->guard->enforceAuth('users', 'GET')['status']);
    }

    public function testAnAdminCanReachUserManagement(): void
    {
        $this->signIn('admin');
        $this->assertNull($this->guard->enforceAuth('users', 'GET'));
    }

    /* ----------------------------------------------------- csrf -- */

    public function testASafeMethodNeedsNoToken(): void
    {
        $this->signIn('admin');
        $this->assertNull($this->guard->enforceCsrf('pages', 'GET', []));
    }

    public function testAnUnsafeRequestWithNoTokenIsRefused(): void
    {
        $this->signIn('admin');
        $result = $this->guard->enforceCsrf('pages', 'POST', ['HTTP_X_CLICK_CSRF' => 'wrong']);
        $this->assertSame(403, $result['status']);
    }

    public function testAnUnsafeRequestWithTheRightTokenIsAllowed(): void
    {
        $this->signIn('admin');
        $this->assertNull(
            $this->guard->enforceCsrf('pages', 'POST', ['HTTP_X_CLICK_CSRF' => 'the-real-token'])
        );
    }

    public function testLoginAndGraphqlAreExemptFromCsrf(): void
    {
        $this->signIn('admin');
        $this->assertNull($this->guard->enforceCsrf('auth/login', 'POST', []));
        $this->assertNull($this->guard->enforceCsrf('graphql', 'POST', []));
    }

    /**
     * A public contact form is submitted by anyone, with no token — the same
     * anonymous POST graphql accepts. It must keep working even when the caller
     * happens to hold a session (an editor previewing their own site), so it is
     * exempt from CSRF like the other public writes.
     */
    public function testFormSubmitIsExemptFromCsrf(): void
    {
        $this->signIn('admin');
        $this->assertNull($this->guard->enforceCsrf('forms/submit', 'POST', []));
    }

    /**
     * Published collection entries are delivery — a public read for a front end
     * with no account, like /api/pages. Only the /published paths open; the
     * /entries management paths, which hand back drafts and working copies, stay
     * behind authentication so a draft cannot be read anonymously.
     */
    public function testPublishedCollectionEntriesArePublicButManagementIsNot(): void
    {
        $this->assertTrue($this->guard->isPublic('collections/post/published', 'GET'));
        $this->assertTrue($this->guard->isPublic('collections/post/published/hello-world', 'GET'));

        // The management surface is not public.
        $this->assertFalse($this->guard->isPublic('collections/post/entries', 'GET'));
        $this->assertFalse($this->guard->isPublic('collections/post/entries/hello-world', 'GET'));
        $this->assertFalse($this->guard->isPublic('collections', 'GET'));
        // A write to a published path is still not a public read.
        $this->assertFalse($this->guard->isPublic('collections/post/published', 'POST'));
    }

    /**
     * A menu is what every visitor sees in the header. Withholding it from the
     * delivery API meant a headless site could read its content but not its
     * navigation — so the nav had to be hardcoded in the front end, which is
     * the one thing a CMS exists to prevent.
     */
    public function testAnyoneMayReadTheNavigation(): void
    {
        $this->assertTrue($this->guard->isPublic('menus', 'GET'));
        $this->assertTrue($this->guard->isPublic('menus/main', 'GET'));
    }

    /** Reading it is public; changing it is not. */
    public function testNobodyMayChangeTheNavigationAnonymously(): void
    {
        $this->assertFalse($this->guard->isPublic('menus/main', 'PUT'));
        $this->assertFalse($this->guard->isPublic('menus/main', 'DELETE'));
        $this->assertFalse($this->guard->isPublic('menus', 'POST'));
    }
}
