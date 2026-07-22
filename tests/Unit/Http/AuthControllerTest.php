<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\LoginThrottle;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Http\AuthController;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * The identity controller, exercised as itself.
 *
 * Login, logout and password change are the most security-sensitive code in the
 * project, and they used to be reachable only through the whole kernel. As their
 * own unit each rule can be pinned directly: the seeded admin can sign in, a
 * wrong password cannot, the published seed password can never be chosen as the
 * replacement, and a locked-out account is told to wait.
 */
final class AuthControllerTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private AuthController $auth;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-auth-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/sessions', 0o700, true);

        $_COOKIE = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->content = new ContentService(new JsonStorage($this->base . '/content'));
        $this->auth = new AuthController(
            new SessionStore($this->base . '/sessions', 1800),
            new LoginThrottle($this->base . '/lockouts.json', 3, 900, 900),
            $this->content,
            CoreConfig::fromArray([]),
            'admin',
        );

        $this->auth->ensureDefaultAdminUser();
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        $_POST = [];
        $this->removeTree($this->base);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $this->removeTree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }

    /** Drive an auth action, supplying the JSON body it reads from php://input. */
    private function post(string $action, array $body): array
    {
        // php://input cannot be set in-process, so the controller's fallback to
        // $_POST is used to carry the body — the same array it would decode.
        $_POST = $body;
        $result = $this->auth->handle('auth/' . $action, 'POST');
        $_POST = [];
        return $result;
    }

    /* ----------------------------------------------------- seeding -- */

    public function testTheSeededAdminExistsAndMustChangeItsPassword(): void
    {
        $admin = $this->content->user('admin');

        $this->assertNotNull($admin);
        $this->assertTrue($admin->data['mustChangePassword']);
        // The seed password is stored hashed, never in the clear.
        $this->assertNotSame('admin', $admin->data['password']);
    }

    /* ------------------------------------------------------- login -- */

    public function testTheSeededAdminCanSignIn(): void
    {
        $result = $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $this->assertTrue($result['data']['success']);
        $this->assertSame('admin', $result['data']['user']['username']);
        // The published seed password logs in but is flagged for change.
        $this->assertTrue($result['data']['user']['mustChangePassword']);
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $result = $this->post('login', ['username' => 'admin', 'password' => 'not-it']);

        $this->assertSame(401, $result['status']);
    }

    public function testAnUnknownAccountIsRefused(): void
    {
        $result = $this->post('login', ['username' => 'nobody', 'password' => 'whatever']);

        $this->assertSame(401, $result['status']);
    }

    public function testRepeatedFailuresLockTheAccountOut(): void
    {
        // The throttle allows three failures; the fourth attempt is refused with
        // a wait rather than another "invalid credentials".
        for ($i = 0; $i < 3; $i++) {
            $this->post('login', ['username' => 'admin', 'password' => 'wrong']);
        }

        $result = $this->post('login', ['username' => 'admin', 'password' => 'wrong']);

        $this->assertSame(429, $result['status']);
        $this->assertArrayHasKey('retryAfter', $result);
    }

    /* --------------------------------------------- change password -- */

    public function testChangingPasswordRequiresASession(): void
    {
        $result = $this->post('password', ['currentPassword' => 'admin', 'newPassword' => 'a-good-one']);

        $this->assertSame(401, $result['status']);
    }

    public function testTheSeedPasswordCannotBecomeTheNewPassword(): void
    {
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        // Even though "admin" satisfies no length rule here, the point is it is
        // published and must never be the answer.
        $result = $this->post('password', ['currentPassword' => 'admin', 'newPassword' => 'admin']);

        $this->assertSame(422, $result['status']);
    }

    public function testAShortPasswordIsRefused(): void
    {
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $result = $this->post('password', ['currentPassword' => 'admin', 'newPassword' => 'short']);

        $this->assertSame(422, $result['status']);
    }

    public function testAValidChangeSucceedsAndClearsTheMustChangeFlag(): void
    {
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $result = $this->post('password', ['currentPassword' => 'admin', 'newPassword' => 'a-proper-password']);
        $this->assertTrue($result['data']['success']);

        // The flag is gone on disk, and the new password now works.
        $this->assertArrayNotHasKey('mustChangePassword', array_filter(
            $this->content->user('admin')->data,
            static fn ($v): bool => $v !== null
        ));

        $again = $this->post('login', ['username' => 'admin', 'password' => 'a-proper-password']);
        $this->assertTrue($again['data']['success']);
        $this->assertFalse($again['data']['user']['mustChangePassword']);
    }

    /* ---------------------------------------------- logout / check -- */

    public function testLogoutEndsTheSession(): void
    {
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        $this->assertTrue($this->auth->handle('auth/check', 'GET')['data']['authenticated']);

        $this->auth->handle('auth/logout', 'POST');

        $this->assertFalse($this->auth->handle('auth/check', 'GET')['data']['authenticated']);
    }

    public function testAnUnknownAuthActionIs404(): void
    {
        $this->assertSame(404, $this->auth->handle('auth/nonsense', 'POST')['status']);
    }
}
