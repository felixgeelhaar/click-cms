<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Http\UsersController;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * User management, now core rather than a plugin's job.
 *
 * The coarse "may this caller manage users at all" gate is the kernel's
 * ApiGuard; what this pins is everything the controller itself must guarantee —
 * above all that a password hash never leaves in a response, and that the
 * password floor and hashing are applied.
 */
final class UsersControllerTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private UsersController $users;
    /** @var list<array{string, array<string, mixed>}> */
    private array $hooks = [];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-users-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        $_POST = [];

        $this->content = new ContentService(new JsonStorage($this->base . '/content'));
        $this->users = new UsersController(
            $this->content,
            CoreConfig::fromArray([]),
            function (string $event, array $payload): void {
                $this->hooks[] = [$event, $payload];
            },
        );
    }

    protected function tearDown(): void
    {
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

    private function create(array $body): array
    {
        $_POST = $body;
        $r = $this->users->create();
        $_POST = [];
        return $r;
    }

    /* --------------------------------------------- the password hash -- */

    public function testAPasswordHashNeverAppearsInAResponse(): void
    {
        $created = $this->create(['email' => 'ada@example.com', 'password' => 'a-good-password', 'role' => 'editor']);
        $this->assertSame(201, $created['status']);
        $this->assertStringNotContainsString('a-good-password', json_encode($created));
        $this->assertArrayNotHasKey('password', $created['data']['data'] ?? []);

        $listed = $this->users->list();
        $this->assertStringNotContainsString('a-good-password', json_encode($listed));
        // And the raw hash, whatever it is, is not present either.
        $this->assertStringNotContainsString('$2y$', json_encode($listed));
    }

    public function testUserResponsesWithholdTwoFactorCredentials(): void
    {
        $this->create(['username' => 'ada', 'email' => 'ada@example.com', 'password' => 'a-good-password']);
        $account = $this->content->user('ada');
        $account->update(['twoFactor' => ['secret' => 'SYNTHETIC-SECRET', 'recoveryCodeHashes' => ['SYNTHETIC-HASH']]]);
        $this->content->save($account);
        foreach ([$this->users->get('ada'), $this->users->list()] as $response) {
            $this->assertStringNotContainsString('SYNTHETIC-SECRET', json_encode($response));
            $this->assertStringNotContainsString('SYNTHETIC-HASH', json_encode($response));
        }
    }

    public function testUnsafeBcryptInputsAreRejectedWithoutChangingAccounts(): void
    {
        $this->create(['username' => 'ada', 'email' => 'ada@example.com', 'password' => 'first-password']);
        $before = $this->content->user('ada')->data['password'];
        foreach ([str_repeat('a', 73), str_repeat('é', 37), "valid-pass\0word"] as $password) {
            $response = $this->create(['username' => 'new-user', 'email' => 'new@example.com', 'password' => $password]);
            self::assertSame(400, $response['status']);
            self::assertNull($this->content->user('new-user'));
            $_POST = ['password' => $password];
            $response = $this->users->update('ada');
            self::assertSame(400, $response['status']);
            self::assertSame($before, $this->content->user('ada')->data['password']);
        }
    }

    public function testTheFull72BytePasswordIsAcceptedAndVerified(): void
    {
        $password = str_repeat('é', 36);
        $response = $this->create(['username' => 'boundary', 'email' => 'boundary@example.com', 'password' => $password]);
        self::assertSame(201, $response['status']);
        $hash = $this->content->user('boundary')->data['password'];
        self::assertTrue(password_verify($password, $hash));
        self::assertFalse(password_verify(str_repeat('é', 35) . 'xx', $hash));
    }

    public function testTheStoredPasswordIsHashedNotPlaintext(): void
    {
        $this->create(['username' => 'ada', 'email' => 'ada@example.com', 'password' => 'a-good-password']);

        $stored = $this->content->user('ada')->data['password'];
        $this->assertNotSame('a-good-password', $stored);
        $this->assertTrue(password_verify('a-good-password', $stored));
    }

    /* ------------------------------------------------- length rule -- */

    public function testAShortPasswordIsRefused(): void
    {
        $r = $this->create(['email' => 'x@example.com', 'password' => 'short']);
        $this->assertSame(400, $r['status']);
    }

    public function testEmailIsRequired(): void
    {
        $r = $this->create(['password' => 'a-good-password']);
        $this->assertSame(400, $r['status']);
    }

    public function testADuplicateUsernameIsRejected(): void
    {
        $this->create(['username' => 'ada', 'email' => 'ada@example.com', 'password' => 'a-good-password']);
        $r = $this->create(['username' => 'ada', 'email' => 'ada2@example.com', 'password' => 'another-good-one']);
        $this->assertSame(409, $r['status']);
    }

    /* -------------------------------------------------- update path -- */

    public function testUpdatingWithABlankPasswordLeavesTheHashUntouched(): void
    {
        $this->create(['username' => 'ada', 'email' => 'ada@example.com', 'password' => 'first-password']);
        $before = $this->content->user('ada')->data['password'];

        $_POST = ['displayName' => 'Ada L', 'password' => ''];
        $this->users->update('ada');
        $_POST = [];

        $after = $this->content->user('ada')->data['password'];
        $this->assertSame($before, $after, 'a blank password field must not change the password');
        $this->assertSame('Ada L', $this->content->user('ada')->data['displayName']);
    }

    public function testARoleChangeFiresTheHook(): void
    {
        $this->create(['username' => 'ada', 'email' => 'ada@example.com', 'password' => 'first-password', 'role' => 'author']);
        $this->hooks = [];

        $_POST = ['role' => 'editor'];
        $this->users->update('ada');
        $_POST = [];

        $events = array_column($this->hooks, 0);
        $this->assertContains('user_role_change', $events);
    }

    public function testDeletingAMissingUserIs404(): void
    {
        $this->assertSame(404, $this->users->delete('nobody')['status']);
    }
}
