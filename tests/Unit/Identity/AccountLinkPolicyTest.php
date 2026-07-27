<?php

declare(strict_types=1);

namespace Click\Cms\Tests\Unit\Identity;

use Click\Cms\Domain\Identity\Oidc\AccountLinkDecision;
use Click\Cms\Domain\Identity\Oidc\AccountLinkPolicy;
use Click\Cms\Domain\Identity\Oidc\IdTokenClaims;
use PHPUnit\Framework\TestCase;

/**
 * Which local account a verified single sign-on belongs to.
 *
 * The verifier decides whether the provider really said this. This decides who
 * the provider said it *about*, and getting it wrong hands somebody another
 * person's account with a perfectly valid token — the same outcome as a broken
 * signature check, reached by a route nobody looks at as hard.
 */
final class AccountLinkPolicyTest extends TestCase
{
    private function claims(string $subject = 'sub-1', ?string $email = 'jo@example.com', bool $verified = true): IdTokenClaims
    {
        return IdTokenClaims::verified(array_filter([
            'sub' => $subject,
            'email' => $email,
            'email_verified' => $verified,
        ], static fn ($v): bool => $v !== null));
    }

    /* -------------------------------------------------------- by subject -- */

    public function testAnAlreadyLinkedAccountSignsIn(): void
    {
        $decision = (new AccountLinkPolicy())->decide($this->claims(), 'jo', null);

        $this->assertSame(AccountLinkDecision::SIGN_IN, $decision->outcome);
        $this->assertSame('jo', $decision->username);
    }

    /**
     * The rule that makes the whole thing safe against reassigned addresses.
     * Once an account carries the provider's stable subject, nothing else is
     * consulted — so `jo@company.com` being given to a different Jo six months
     * later cannot reach the first Jo's account.
     */
    public function testALinkedAccountIsNotReEvaluatedAgainstTheEmail(): void
    {
        $policy = new AccountLinkPolicy(AccountLinkPolicy::LINK_BY_VERIFIED_EMAIL);

        $decision = $policy->decide($this->claims(), 'jo', 'somebody-else');

        $this->assertSame(AccountLinkDecision::SIGN_IN, $decision->outcome);
        $this->assertSame('jo', $decision->username);
    }

    /* ---------------------------------------------------------- by email -- */

    public function testEmailMatchingIsOffByDefault(): void
    {
        $decision = (new AccountLinkPolicy())->decide($this->claims(), null, 'jo');

        $this->assertTrue($decision->isRefusal());
    }

    public function testAVerifiedEmailAdoptsAnExistingAccountWhenTheSiteAllowsIt(): void
    {
        $policy = new AccountLinkPolicy(AccountLinkPolicy::LINK_BY_VERIFIED_EMAIL);

        $decision = $policy->decide($this->claims(), null, 'jo');

        $this->assertSame(AccountLinkDecision::ADOPT, $decision->outcome);
        $this->assertSame('jo', $decision->username);
    }

    /**
     * At a provider that lets somebody set their own address without proving
     * it, matching on an unverified email is an invitation to type in the
     * administrator's.
     */
    public function testAnUnverifiedEmailNeverAdoptsAnAccount(): void
    {
        $policy = new AccountLinkPolicy(AccountLinkPolicy::LINK_BY_VERIFIED_EMAIL);

        $decision = $policy->decide($this->claims(verified: false), null, 'jo');

        $this->assertTrue($decision->isRefusal());
        $this->assertStringContainsString('not verified', (string) $decision->reason);
    }

    /**
     * A provider that says nothing about verification has promised nothing.
     */
    public function testAMissingVerificationClaimCountsAsUnverified(): void
    {
        $policy = new AccountLinkPolicy(AccountLinkPolicy::LINK_BY_VERIFIED_EMAIL);
        $claims = IdTokenClaims::verified(['sub' => 'sub-1', 'email' => 'jo@example.com']);

        $this->assertTrue($policy->decide($claims, null, 'jo')->isRefusal());
    }

    /* ------------------------------------------------------ provisioning -- */

    public function testProvisioningIsOffByDefault(): void
    {
        $decision = (new AccountLinkPolicy())->decide($this->claims(), null, null);

        $this->assertTrue($decision->isRefusal());
    }

    public function testProvisioningCreatesAnAccountWhenTheSiteAllowsIt(): void
    {
        $policy = new AccountLinkPolicy(provision: true);

        $decision = $policy->decide($this->claims(), null, null);

        $this->assertSame(AccountLinkDecision::CREATE, $decision->outcome);
    }

    /**
     * A provisioned account gets the least a role can be. The site does not
     * know who this is beyond "the provider recognised them", and a provider
     * may be a consumer one where that means anybody at all.
     */
    public function testAProvisionedAccountGetsTheLeastPrivilegedRoleByDefault(): void
    {
        $decision = (new AccountLinkPolicy(provision: true))->decide($this->claims(), null, null);

        $this->assertSame('viewer', $decision->role);
    }

    /**
     * The trap this closes: with provisioning on and email matching off, an
     * address that already belongs to a local account would otherwise fall
     * through and create a *second* account with the same address, leaving two
     * people believing they share one.
     */
    public function testAnExistingEmailIsRefusedRatherThanDuplicated(): void
    {
        $policy = new AccountLinkPolicy(provision: true);

        $decision = $policy->decide($this->claims(), null, 'jo');

        $this->assertTrue($decision->isRefusal());
        $this->assertStringContainsString('already exists', (string) $decision->reason);
    }

    /* ---------------------------------------------------------- reasons -- */

    public function testEveryRefusalSaysWhatToDoNext(): void
    {
        $refusals = [
            (new AccountLinkPolicy())->decide($this->claims(), null, null),
            (new AccountLinkPolicy())->decide($this->claims(), null, 'jo'),
        ];

        foreach ($refusals as $refusal) {
            $this->assertStringContainsString('administrator', (string) $refusal->reason);
        }
    }
}
