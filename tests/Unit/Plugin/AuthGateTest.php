<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Plugin;

use Click\Cms\Application\Plugin\AuthGate;
use Click\Cms\Application\Plugin\PublishGate;
use PHPUnit\Framework\TestCase;

/**
 * The authentication gate's contract, without a plugin system in the way.
 *
 * The wiring — that a plugin dropped into `plugins/` really is reached from a
 * real sign-in — is proven in `Tests\Unit\Plugins\AuthEventsWiringTest`. What is
 * pinned here is the contract that makes the hook safe to have at all: silence
 * permits, a throw is not an opinion, and the payloads are reduced through an
 * allowlist so no account secret can reach a listener even if a caller hands the
 * whole stored document over.
 */
final class AuthGateTest extends TestCase
{
    /** @var list<array{hook: string, payload: array<string, mixed>}> */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->seen = [];
    }


    /**
     * A gate whose plugins answer whatever the map says, recording every payload.
     *
     * @param array<string, mixed> $answers Hook name to the array of per-plugin
     *        answers the dispatcher should return.
     */
    private function gate(array $answers = [], ?\Closure $listens = null): AuthGate
    {
        return new AuthGate(
            function (string $hook, array $payload) use ($answers): array {
                $this->seen[] = ['hook' => $hook, 'payload' => $payload];

                return $answers[$hook] ?? [];
            },
            $listens
        );
    }

    /** @return list<string> */
    private function hooksSeen(): array
    {
        return array_map(static fn (array $e): string => $e['hook'], $this->seen);
    }

    /** @return array<string, mixed> */
    private function payloadFor(string $hook): array
    {
        foreach ($this->seen as $event) {
            if ($event['hook'] === $hook) {
                return $event['payload'];
            }
        }

        $this->fail("no {$hook} was dispatched");
    }

    /** A stored user document, secrets and all, exactly as the login path holds it. */
    private function account(): array
    {
        return [
            'username' => 'ada',
            'displayName' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'role' => 'admin',
            'status' => 'active',
            'password' => '$2y$10$THISMUSTNEVERREACHAPLUGIN',
            'mustChangePassword' => true,
            'totpSecret' => 'JBSWY3DPEHPK3PXP',
        ];
    }

    /* ----------------------------------------------------------- no secrets -- */

    /**
     * The trap this class exists to avoid. The caller passes the whole account —
     * it is the only thing it has — and the gate is what decides how little of
     * it a plugin sees.
     */
    public function testAPayloadCarriesNoPasswordHashOrOtherAccountSecret(): void
    {
        $gate = $this->gate();

        $gate->refusalForLogin('ada', $this->account(), false);
        $gate->announceLoggedIn('ada', $this->account(), true);
        $gate->announceLoggedOut('ada', $this->account());

        $serialised = json_encode($this->seen);

        $this->assertStringNotContainsString('THISMUSTNEVERREACHAPLUGIN', $serialised);
        $this->assertStringNotContainsString('password', $serialised);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $serialised);
        $this->assertStringNotContainsString('ada@example.com', $serialised);
    }

    public function testTheAllowlistIsTheOnlyWayAFieldGetsThrough(): void
    {
        $this->assertSame(
            ['role' => 'admin', 'mustChangePassword' => true],
            AuthGate::describe($this->account())
        );

        // A field nobody has decided about is absent rather than forwarded.
        $this->assertSame([], AuthGate::describe(['recoveryCodes' => ['1234'], 'apiToken' => 'abc']));
    }

    public function testAPreLoginPayloadIsIdentityAndIntentOnly(): void
    {
        $this->gate()->refusalForLogin('ada', $this->account(), true);

        $this->assertSame([
            'username' => 'ada',
            'remember' => true,
            'role' => 'admin',
            'mustChangePassword' => true,
        ], $this->payloadFor(AuthGate::BEFORE_LOGIN));
    }

    /**
     * The enumeration decision, pinned. Both an unknown account and a wrong
     * password arrive as one reason, because both arrive at the caller as one
     * `401`.
     */
    public function testAFailedSignInSaysNoMoreThanTheHttpResponseDoes(): void
    {
        $gate = $this->gate();

        $gate->announceLoginFailed('nobody-at-all', AuthGate::FAILED_CREDENTIALS);
        $gate->announceLoginFailed('ada', AuthGate::FAILED_CREDENTIALS);

        $this->assertSame(
            [
                ['username' => 'nobody-at-all', 'reason' => 'invalid_credentials'],
                ['username' => 'ada', 'reason' => 'invalid_credentials'],
            ],
            array_map(static fn (array $e): array => $e['payload'], $this->seen)
        );
    }

    /* ---------------------------------------------------------- the contract -- */

    public function testSilencePermits(): void
    {
        $gate = $this->gate([AuthGate::BEFORE_LOGIN => [
            'Quiet' => null,
            'Empty' => [],
            'NoOpinion' => ['allowed' => null],
            'Chatty' => ['something' => 'else'],
            'Wrong shape' => 'no',
        ]]);

        $this->assertNull($gate->refusalForLogin('ada', $this->account(), false));
    }

    public function testAnExplicitRefusalIsReturnedWithItsReason(): void
    {
        $gate = $this->gate([AuthGate::BEFORE_LOGIN => [
            'Two Factor' => ['allowed' => false, 'reason' => 'Enter the code from your authenticator app.'],
        ]]);

        $this->assertSame(
            'Enter the code from your authenticator app.',
            $gate->refusalForLogin('ada', $this->account(), false)
        );
    }

    public function testTheFirstRefusalWins(): void
    {
        $gate = $this->gate([AuthGate::BEFORE_LOGIN => [
            'First' => ['allowed' => false, 'reason' => 'First said no.'],
            'Second' => ['allowed' => false, 'reason' => 'Second said no.'],
        ]]);

        $this->assertSame('First said no.', $gate->refusalForLogin('ada', $this->account(), false));
    }

    public function testARefusalWithNoReasonNamesThePluginToGoAndAsk(): void
    {
        $gate = $this->gate([AuthGate::BEFORE_LOGIN => [
            'Silent Veto' => ['allowed' => false],
        ]]);

        $refusal = $gate->refusalForLogin('ada', $this->account(), false);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('Silent Veto', $refusal);
    }

    /**
     * The most important line in the class. A dispatcher that throws — a
     * half-booted kernel — must not be able to hold the door shut, because the
     * only way to disable the plugin responsible is through a UI you have to sign
     * in to reach.
     */
    public function testADispatchThatThrowsIsNoOpinionAndNoObstacle(): void
    {
        $gate = new AuthGate(static function (): array {
            throw new \RuntimeException('the plugin system is on fire');
        });

        $this->assertNull($gate->refusalForLogin('ada', $this->account(), false));

        // And the announcements survive it too, so a broken dispatcher cannot
        // turn a sign-in into a 500.
        $gate->announceLoggedIn('ada', $this->account(), false);
        $gate->announceLoginFailed('ada', AuthGate::FAILED_CREDENTIALS);
        $gate->announceLoggedOut('ada', $this->account());
        $gate->announceLockedOut('ada', 900);

        $this->assertTrue(true, 'nothing propagated out of the gate');
    }

    public function testAGateWithNothingBehindItPermitsAndAnnouncesNothing(): void
    {
        $gate = new AuthGate();

        $this->assertNull($gate->refusalForLogin('ada', $this->account(), false));
        $this->assertFalse($gate->listensTo(AuthGate::BEFORE_LOGIN));
    }

    /* ------------------------------------------------------------ the cost -- */

    public function testNothingIsDispatchedWhenNoPluginDeclaresTheHook(): void
    {
        $asked = [];
        $gate = $this->gate([], function (string $hook) use (&$asked): bool {
            $asked[] = $hook;

            return false;
        });

        $this->assertNull($gate->refusalForLogin('ada', $this->account(), false));
        $gate->announceLoggedIn('ada', $this->account(), false);
        $gate->announceLoginFailed('ada', AuthGate::FAILED_CREDENTIALS);
        $gate->announceLoggedOut('ada', $this->account());
        $gate->announceLockedOut('ada', 900);

        $this->assertSame([], $this->hooksSeen(), 'no payload was built and nothing was dispatched');
        $this->assertCount(5, $asked, 'each hook asked once whether anybody was listening');
    }

    public function testAListenedHookIsStillDispatched(): void
    {
        $gate = $this->gate([], static fn (string $hook): bool => $hook === AuthGate::LOGGED_IN);

        $gate->announceLoggedIn('ada', $this->account(), false);
        $gate->announceLoggedOut('ada', $this->account());

        $this->assertSame([AuthGate::LOGGED_IN], $this->hooksSeen());
    }

}
