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
}
