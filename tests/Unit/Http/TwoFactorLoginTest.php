<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Http;

use Click\Cms\Application\Authentication\LoginThrottle;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Application\Authentication\TwoFactorService;
use Click\Cms\Application\Config\CoreConfig;
use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Identity\Base32;
use Click\Cms\Domain\Identity\Totp;
use Click\Cms\Http\AuthController;
use Click\Cms\Infrastructure\Storage\JsonStorage;
use PHPUnit\Framework\TestCase;

/**
 * Signing in when the account carries a second factor.
 *
 * The property everything here defends: **holding the password alone gets you
 * nothing.** A two-step login is only a second factor if the state between the
 * steps authenticates nothing at all — if the intermediate session can read a
 * page, list an account or change a setting, the second step is decoration and
 * the password is still the only real credential.
 */
final class TwoFactorLoginTest extends TestCase
{
    private string $base;
    private ContentService $content;
    private SessionStore $sessions;
    private TwoFactorService $twoFactor;
    private AuthController $auth;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/click-cms-2fa-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/content', 0o775, true);
        mkdir($this->base . '/sessions', 0o700, true);

        $_COOKIE = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->content = new ContentService(new JsonStorage($this->base . '/content'));
        $this->sessions = new SessionStore($this->base . '/sessions', 1800);
        $this->twoFactor = new TwoFactorService($this->content, 'Test Site');

        $this->auth = new AuthController(
            $this->sessions,
            new LoginThrottle($this->base . '/lockouts.json', 3, 900, 900),
            $this->content,
            CoreConfig::fromArray([]),
            'admin',
            null,
            $this->twoFactor,
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

    private function post(string $action, array $body = []): array
    {
        $_POST = $body;
        $result = $this->auth->handle('auth/' . $action, 'POST');
        $_POST = [];

        return $result;
    }

    /** Enrol the admin account and confirm it, returning the base32 secret. */
    private function enrolAdmin(): string
    {
        $enrolment = $this->twoFactor->beginEnrolment('admin');
        $secret = $enrolment['secret'];

        $raw = (string) Base32::decode($secret);
        $this->twoFactor->confirmEnrolment('admin', Totp::codeAt($raw, time()));

        return $secret;
    }

    private function codeFor(string $secret, ?int $at = null): string
    {
        return Totp::codeAt((string) Base32::decode($secret), $at ?? time());
    }

    /* ------------------------------------------------------ enrolment -- */

    public function testEnrolmentIssuesASecretAUriAndRecoveryCodes(): void
    {
        $enrolment = $this->twoFactor->beginEnrolment('admin');

        $this->assertNotNull($enrolment);
        $this->assertStringStartsWith('otpauth://totp/', $enrolment['uri']);
        $this->assertCount(10, $enrolment['recoveryCodes']);
    }

    /**
     * The rule the enrolment/confirmation split exists for. Between showing the
     * QR code and the person proving they scanned it, the account must sign in
     * exactly as before — otherwise turning two-factor on locks you out at the
     * moment you have not finished setting it up.
     */
    public function testAnUnconfirmedEnrolmentDoesNotChangeSigningIn(): void
    {
        $this->twoFactor->beginEnrolment('admin');

        $result = $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $this->assertTrue($result['data']['success']);
        $this->assertNotNull($this->sessions->user());
    }

    public function testConfirmingRequiresACorrectCode(): void
    {
        $this->twoFactor->beginEnrolment('admin');

        $this->assertFalse($this->twoFactor->confirmEnrolment('admin', '000000'));
        $this->assertFalse($this->twoFactor->isActiveFor('admin'));
    }

    /* --------------------------------------------------------- login -- */

    public function testAPasswordAloneNoLongerSignsIn(): void
    {
        $this->enrolAdmin();

        $result = $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $this->assertTrue($result['data']['twoFactorRequired']);
        $this->assertFalse($result['data']['success']);
    }

    /**
     * The load-bearing assertion of the whole feature. If the pending session
     * authenticates anything, the second factor is decoration.
     */
    public function testThePendingSessionAuthenticatesNothing(): void
    {
        $this->enrolAdmin();

        $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $this->assertNull($this->sessions->user());
        $this->assertSame(401, $this->auth->handle('auth/me', 'GET')['status']);
        $this->assertFalse($this->auth->handle('auth/check', 'GET')['data']['authenticated']);
    }

    public function testACorrectCodeCompletesTheSignIn(): void
    {
        $secret = $this->enrolAdmin();
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $result = $this->post('2fa', ['code' => $this->codeFor($secret)]);

        $this->assertTrue($result['data']['success']);
        $this->assertSame('admin', $result['data']['user']['username']);
        $this->assertNotNull($this->sessions->user());
    }

    public function testAWrongCodeDoesNotCompleteIt(): void
    {
        $this->enrolAdmin();
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $result = $this->post('2fa', ['code' => '000000']);

        $this->assertSame(401, $result['status']);
        $this->assertNull($this->sessions->user());
    }

    /**
     * The second step must not be reachable without the first. Otherwise the
     * code alone is the credential, which is a worse secret than the password
     * it was meant to strengthen.
     */
    public function testTheCodeStepIsUselessWithoutThePasswordStep(): void
    {
        $secret = $this->enrolAdmin();

        $result = $this->post('2fa', ['code' => $this->codeFor($secret)]);

        $this->assertSame(401, $result['status']);
        $this->assertNull($this->sessions->user());
    }

    /**
     * A half-finished login left on a shared machine has to close on its own,
     * rather than sitting there until the ordinary idle timeout — which is
     * measured in half-hours and starts from activity, not from this.
     */
    public function testAPendingSignInExpires(): void
    {
        $secret = $this->enrolAdmin();
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        // Wind the stored deadline back rather than sleeping.
        $session = $this->sessions->read();
        $session['pendingExpiresAt'] = time() - 1;
        $this->sessions->write($session);

        $result = $this->post('2fa', ['code' => $this->codeFor($secret)]);

        $this->assertSame(401, $result['status']);
        $this->assertNull($this->sessions->user());
    }

    /**
     * Six digits with unlimited guesses is not a second factor. The same lockout
     * that governs passwords has to govern this.
     */
    public function testWrongCodesAreThrottled(): void
    {
        $this->enrolAdmin();
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $this->post('2fa', ['code' => '000000']);
        $this->post('2fa', ['code' => '000001']);
        $this->post('2fa', ['code' => '000002']);

        $result = $this->post('2fa', ['code' => '000003']);

        $this->assertSame(429, $result['status']);
    }

    /* ------------------------------------------------------ recovery -- */

    public function testARecoveryCodeCompletesTheSignIn(): void
    {
        $enrolment = $this->twoFactor->beginEnrolment('admin');
        $raw = (string) Base32::decode($enrolment['secret']);
        $this->twoFactor->confirmEnrolment('admin', Totp::codeAt($raw, time()));

        $this->post('login', ['username' => 'admin', 'password' => 'admin']);

        $result = $this->post('2fa', ['code' => $enrolment['recoveryCodes'][0]]);

        $this->assertTrue($result['data']['success']);
    }

    public function testARecoveryCodeWorksOnlyOnce(): void
    {
        $enrolment = $this->twoFactor->beginEnrolment('admin');
        $raw = (string) Base32::decode($enrolment['secret']);
        $this->twoFactor->confirmEnrolment('admin', Totp::codeAt($raw, time()));

        $code = $enrolment['recoveryCodes'][0];

        $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        $this->post('2fa', ['code' => $code]);

        $this->auth->handle('auth/logout', 'POST');
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        $result = $this->post('2fa', ['code' => $code]);

        $this->assertSame(401, $result['status']);
    }

    /* ------------------------------------------------------ disabling -- */

    /**
     * A borrowed session must not be able to strip the protection off the
     * account it borrowed — the same reasoning that makes a password change
     * require the current password.
     */
    public function testTurningItOffRequiresThePassword(): void
    {
        $secret = $this->enrolAdmin();
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        $this->post('2fa', ['code' => $this->codeFor($secret)]);

        $refused = $this->post('2fa/disable', ['password' => 'not-it']);

        $this->assertSame(403, $refused['status']);
        $this->assertTrue($this->twoFactor->isActiveFor('admin'));
    }

    public function testTheRightPasswordTurnsItOff(): void
    {
        $secret = $this->enrolAdmin();
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        $this->post('2fa', ['code' => $this->codeFor($secret)]);

        $result = $this->post('2fa/disable', ['password' => 'admin']);

        $this->assertTrue($result['data']['success']);
        $this->assertFalse($this->twoFactor->isActiveFor('admin'));
    }

    public function testAnAnonymousCallerCannotTurnItOff(): void
    {
        $this->enrolAdmin();

        $result = $this->post('2fa/disable', ['password' => 'admin']);

        $this->assertSame(401, $result['status']);
        $this->assertTrue($this->twoFactor->isActiveFor('admin'));
    }

    /**
     * Enrolling again over a confirmed second factor would replace the secret
     * without proving anything, which is the same hole as turning it off
     * without the password.
     */
    public function testItCannotBeSilentlyReEnrolledWhileActive(): void
    {
        $secret = $this->enrolAdmin();
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        $this->post('2fa', ['code' => $this->codeFor($secret)]);

        $result = $this->post('2fa/enrol');

        $this->assertSame(409, $result['status']);
        $this->assertNull($this->twoFactor->beginEnrolment('admin'));
    }

    /* --------------------------------------------------------- status -- */

    public function testTheStatusEndpointReportsWhatIsOn(): void
    {
        $secret = $this->enrolAdmin();
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        $this->post('2fa', ['code' => $this->codeFor($secret)]);

        $status = $this->auth->handle('auth/2fa', 'GET');

        $this->assertTrue($status['data']['active']);
        $this->assertSame(10, $status['data']['recoveryCodesLeft']);
    }

    /**
     * A secret is a secret. The status endpoint is read by the profile screen on
     * every visit, and handing back the shared key there would put it in every
     * browser cache and every proxy log the admin passes through.
     */
    public function testTheStatusEndpointNeverReturnsTheSecret(): void
    {
        $secret = $this->enrolAdmin();
        $this->post('login', ['username' => 'admin', 'password' => 'admin']);
        $this->post('2fa', ['code' => $this->codeFor($secret)]);

        $status = $this->auth->handle('auth/2fa', 'GET');

        $this->assertStringNotContainsString($secret, json_encode($status['data']) ?: '');
    }
}
