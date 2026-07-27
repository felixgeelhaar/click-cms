<?php

declare(strict_types=1);

namespace Click\Cms\Application\Authentication\Oidc;

use Click\Cms\Domain\Identity\Oidc\AccountLinkPolicy;

/**
 * How this site's single sign-on is configured, read from `config/core.json`
 * under `core.sso`.
 *
 * Configuration rather than an admin screen, deliberately and for now. Setting
 * this up means pasting a client secret and choosing who may sign in — a
 * decision taken once, by whoever also configures the identity provider, and
 * one where a mistake hands out accounts. A file that lives with the deployment
 * and can be reviewed in a diff is the right home for that; an admin form is
 * something to add once the shape has settled, not before.
 *
 * The whole feature is **off unless configured**, and every dial defaults to
 * the restrictive answer. A site that has never heard of any of this is not
 * quietly running an untested authentication path.
 */
final class OidcSettings
{
    /**
     * @param array<string, string> $extraAuthorizationParameters
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
        public readonly string $scope,
        public readonly string $buttonLabel,
        public readonly string $roleClaim,
        /** @var array<string, string> Claim value => local role. */
        public readonly array $roleMap,
        public readonly AccountLinkPolicy $linkPolicy,
        public readonly array $extraAuthorizationParameters,
        /**
         * Whether an account linked to the provider may still use its password.
         *
         * Off by default. The point of putting an organisation behind single
         * sign-on is usually that leaving the organisation removes access, and
         * a local password that still works is a way around that which nobody
         * remembers to close.
         */
        public readonly bool $allowPasswordLogin,
    ) {}

    public static function disabled(): self
    {
        return new self(
            false,
            '',
            '',
            '',
            '',
            'openid profile email',
            'Sign in with single sign-on',
            '',
            [],
            new AccountLinkPolicy(),
            [],
            true,
        );
    }

    /**
     * @param array<string, mixed> $config The `core.sso` block.
     */
    public static function fromArray(array $config): self
    {
        $issuer = self::text($config['issuer'] ?? null);
        $clientId = self::text($config['clientId'] ?? null);
        $clientSecret = self::text($config['clientSecret'] ?? null);
        $redirectUri = self::text($config['redirectUri'] ?? null);

        // Enabled means "has everything it needs", not "somebody set a flag".
        // A half-configured provider that announced itself would put a button on
        // the login screen that leads to a broken redirect, and the person who
        // pressed it would have no way to tell whose fault it was.
        $enabled = ($config['enabled'] ?? false) === true
            && $issuer !== ''
            && $clientId !== ''
            && $clientSecret !== ''
            && $redirectUri !== '';

        $roleMap = [];
        foreach ($config['roleMap'] ?? [] as $claimValue => $role) {
            if (is_string($claimValue) && is_string($role)) {
                $roleMap[$claimValue] = $role;
            }
        }

        $extra = [];
        foreach ($config['authorizationParameters'] ?? [] as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $extra[$name] = (string) $value;
            }
        }

        return new self(
            $enabled,
            // Trailing slash removed: `iss` is compared exactly, and a provider
            // that publishes `https://id.example.com` against a configured
            // `https://id.example.com/` fails every sign-in with a message
            // about the issuer that looks, to a reader, like a match.
            rtrim($issuer, '/'),
            $clientId,
            $clientSecret,
            $redirectUri,
            self::text($config['scope'] ?? null) ?: 'openid profile email',
            self::text($config['buttonLabel'] ?? null) ?: 'Sign in with single sign-on',
            self::text($config['roleClaim'] ?? null),
            $roleMap,
            new AccountLinkPolicy(
                self::text($config['linking'] ?? null) === AccountLinkPolicy::LINK_BY_VERIFIED_EMAIL
                    ? AccountLinkPolicy::LINK_BY_VERIFIED_EMAIL
                    : AccountLinkPolicy::LINK_BY_SUBJECT_ONLY,
                ($config['provisionAccounts'] ?? false) === true,
                self::text($config['provisionRole'] ?? null) ?: 'viewer',
            ),
            $extra,
            ($config['allowPasswordLogin'] ?? false) === true,
        );
    }

    /**
     * The local role for a verified sign-in, or null to leave it as it is.
     *
     * Null rather than a default, so a site that configured no mapping does not
     * silently rewrite the role an administrator set by hand on every login.
     *
     * @param array<string, mixed> $claims
     */
    public function roleFor(array $claims): ?string
    {
        if ($this->roleClaim === '' || $this->roleMap === []) {
            return null;
        }

        $value = $claims[$this->roleClaim] ?? null;

        // A groups claim is usually a list. Mapped in configuration order rather
        // than in the order the provider sent, so which role wins when somebody
        // is in two mapped groups is the site's decision and is stable.
        $values = is_array($value) ? $value : [$value];

        foreach ($this->roleMap as $claimValue => $role) {
            if (in_array($claimValue, $values, true)) {
                return $role;
            }
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
