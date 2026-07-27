<?php

declare(strict_types=1);

namespace Click\Cms\Domain\Identity\Oidc;

/**
 * Which local account a verified sign-in belongs to, and whether one may be
 * created.
 *
 * The most dangerous part of single sign-on, and the least obviously so. The
 * signature checking in {@see IdTokenVerifier} decides whether the provider
 * really said this; *this* decides who the provider said it about, and getting
 * it wrong hands somebody another person's account with a perfectly valid
 * token.
 *
 * ## Why `sub` and not email
 *
 * The obvious implementation matches on the email address. It is wrong in two
 * directions:
 *
 * - **Emails are reassigned.** `jo@company.com` leaves, and six months later a
 *   different Jo is given the same address. Matching on email hands the second
 *   Jo the first Jo's account, including anything the first Jo could publish.
 * - **Emails are not always verified.** At a provider that lets somebody set
 *   their own address without proving it, matching on email is an invitation to
 *   type in the administrator's.
 *
 * So the link is the provider's `sub`, which is the one identifier OpenID
 * Connect requires to be stable and never reassigned. It is recorded on the
 * account the first time, and matched on afterwards.
 *
 * Email is used for exactly one thing: finding the account to link *the first
 * time*, and only when the provider has verified it and the site has opted in.
 * That is a deliberate, configurable risk — it is how an existing team gets on
 * to SSO without an administrator hand-editing every account — and it is off by
 * default.
 */
final class AccountLinkPolicy
{
    /** Sign in only accounts that already carry this provider's `sub`. */
    public const LINK_BY_SUBJECT_ONLY = 'subject';

    /**
     * Also adopt an existing local account whose email matches a *verified*
     * address, recording the `sub` on it for next time.
     */
    public const LINK_BY_VERIFIED_EMAIL = 'verified-email';

    public function __construct(
        private readonly string $linking = self::LINK_BY_SUBJECT_ONLY,
        /**
         * Whether a person the provider knows and this site does not may have
         * an account created for them.
         *
         * Off by default, and that default is the important one: a site with
         * provisioning on and a public identity provider — a consumer Google
         * account, say — hands an editor login to anyone on the internet with an
         * email address.
         */
        private readonly bool $provision = false,
        /** What a provisioned account may do. Deliberately the least. */
        private readonly string $provisionRole = 'viewer',
    ) {}

    /**
     * What to do with a verified sign-in.
     *
     * @param ?string $bySubject   Username of the account already carrying this
     *                             `sub`, if any.
     * @param ?string $byEmail     Username of the account with this email, if any.
     */
    public function decide(IdTokenClaims $claims, ?string $bySubject, ?string $byEmail): AccountLinkDecision
    {
        // Already linked. Nothing else is consulted — not the email, not the
        // provisioning setting — because the link is the answer and re-deriving
        // it from a mutable field is how an account gets handed to somebody
        // else after an address is reassigned.
        if ($bySubject !== null) {
            return AccountLinkDecision::signIn($bySubject);
        }

        if ($this->linking === self::LINK_BY_VERIFIED_EMAIL && $byEmail !== null) {
            if (!$claims->emailVerified) {
                return AccountLinkDecision::refuse(
                    'An account with that email address exists here, but the identity provider '
                    . 'has not verified the address, so it cannot be matched to it.'
                );
            }

            return AccountLinkDecision::adopt($byEmail);
        }

        // An email that matches a local account, at a site that has not opted
        // in to matching on email. Refused explicitly rather than falling
        // through to provisioning, which would otherwise create a *second*
        // account with the same address and leave two people believing they
        // share one.
        if ($byEmail !== null) {
            return AccountLinkDecision::refuse(
                'An account with that email address already exists here and is not linked to '
                . 'single sign-on. An administrator has to link it.'
            );
        }

        if (!$this->provision) {
            return AccountLinkDecision::refuse(
                'This site does not create accounts automatically. Ask an administrator for one.'
            );
        }

        return AccountLinkDecision::create($this->provisionRole);
    }
}
