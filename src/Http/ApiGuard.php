<?php

declare(strict_types=1);

namespace Click\Cms\Http;

use Click\Cms\Application\Authentication\CsrfGuard;
use Click\Cms\Application\Authentication\SessionStore;
use Click\Cms\Domain\Identity\Capability;
use Click\Cms\Domain\Identity\Role;

/**
 * Decides whether an API request may proceed: is this path public, is the caller
 * authenticated and permitted, and does an unsafe request carry a valid CSRF
 * token.
 *
 * Pulled out of the HTTP kernel because it is the security gate every request
 * passes through, and a gate is exactly the thing that should be one small,
 * separately-testable unit rather than three methods buried in a class of
 * seventeen hundred lines. The rules did not change in the move; what changed is
 * that they can now be exercised directly, one case at a time, instead of only
 * through a full request.
 *
 * It reads the session but holds no other state, and returns a response array to
 * short-circuit with — or null to mean "keep going" — so the kernel stays the
 * only thing that speaks HTTP.
 */
final class ApiGuard
{
    public function __construct(private readonly SessionStore $sessions) {}

    /**
     * Whether a path may be reached without a session at all.
     *
     * Deny-by-default: anything not named here needs authentication. An allowlist
     * rather than a list of protected prefixes, because a list of protected
     * prefixes fails open — forget to add one and it is public.
     */
    public function isPublic(string $path, string $method): bool
    {
        if (str_starts_with($path, 'auth/')) {
            return true;
        }

        // The GraphQL delivery endpoint is public regardless of verb. GraphQL
        // clients send their query in a POST body, but it is still a read — no
        // accounts, no writes, published content only, all enforced in the
        // plugin — so it is checked before the guard below that would otherwise
        // reject every POST out of hand.
        if ($path === 'graphql') {
            return true;
        }

        // A visitor with no account submits a contact form, so this one POST is
        // public. It only writes a form-submission document and cannot read
        // anything; the plugin validates and honeypots it. Nothing an anonymous
        // caller does through it is a request the site's own pages could not
        // already make, so there is nothing to forge.
        if ($path === 'forms/submit') {
            return true;
        }

        if (!CsrfGuard::isSafeMethod($method)) {
            return false;
        }

        // History is management even though it hangs off a public path. The
        // allowlist below would otherwise hand an anonymous reader every
        // unpublished draft a page has ever been in, which is the exact
        // opposite of what publishing means.
        if (preg_match('#^pages/[^/]+/versions(/|$)#', $path) === 1) {
            return false;
        }

        // Published content, read by a front end that has no account.
        if ($path === 'pages' || str_starts_with($path, 'pages/')) {
            return true;
        }

        // The bytes of an image a public page references.
        if (str_starts_with($path, 'media/file/')) {
            return true;
        }

        // Full-text search over published pages, for a front end with no account.
        // The search plugin reads only published documents, so this is a read of
        // public content, safe to answer anonymously like /api/pages.
        if ($path === 'search') {
            return true;
        }

        // The site's own navigation, read by a front end with no account.
        //
        // A menu is what a visitor sees in the header of every page: labels and
        // where they point. Withholding it from the delivery API meant a
        // headless site could read its content but not its navigation, so the
        // nav had to be hardcoded in the front end — which is the one thing a
        // CMS is for. There is no draft menu and no unpublished item, so there
        // is nothing here that a rendered page does not already show.
        if ($path === 'menus' || preg_match('#^menus/[^/]+$#', $path) === 1) {
            return $method === 'GET';
        }

        // Published collection entries, read by a front end with no account. Only
        // the `/published` delivery paths are opened — never the `/entries`
        // management paths, which return working copies and drafts — so this can
        // no more leak an unpublished entry than /api/pages can leak a draft.
        if (preg_match('#^collections/[^/]+/published(/[^/]+)?$#', $path) === 1) {
            return true;
        }

        // A collection entry preview: a signed link that returns the DRAFT entry
        // as delivery JSON, for a front-end preview environment with no account.
        // The path is reachable anonymously, but the handler returns nothing
        // without a valid signed token (or a session) — as with the page
        // `/preview/` route, the signature is the gate, not the guard. Minting a
        // link is a POST and so is not caught here: it stays authenticated.
        if (preg_match('#^collections/[^/]+/preview/[^/]+$#', $path) === 1) {
            return true;
        }

        return false;
    }

    /**
     * The authentication and coarse authorization gate. Returns a response to
     * refuse with, or null to allow the request through.
     *
     * @return array<string, mixed>|null
     */
    public function enforceAuth(string $path, string $method): ?array
    {
        // An account with an outstanding password change may do nothing else.
        // Checked before the per-path rules so that no endpoint, present or
        // future, can be reached while the seeded password is still in place.
        $session = $this->sessions->user();
        if ($session !== null && ($session['mustChangePassword'] ?? false)) {
            return [
                'status' => 403,
                'error' => 'Set a new password before continuing.',
                'mustChangePassword' => true,
            ];
        }

        if ($this->isPublic($path, $method)) {
            return null;
        }

        $user = $this->sessions->user();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Not authenticated'];
        }

        // Asked as capability questions rather than role comparisons, so the
        // rules live in one place and can be changed without hunting for every
        // `=== 'admin'` in the codebase.
        $role = Role::fromName($user['role'] ?? null);

        if (str_starts_with($path, 'users') && !$role->can(Capability::ManageUsers)) {
            return ['status' => 403, 'error' => 'You do not have permission to manage users.'];
        }

        if (str_starts_with($path, 'marketplace') && !$role->can(Capability::InstallPlugins)) {
            return ['status' => 403, 'error' => 'You do not have permission to install plugins.'];
        }

        if (str_starts_with($path, 'plugins') && $method !== 'GET' && !$role->can(Capability::ManagePlugins)) {
            return ['status' => 403, 'error' => 'You do not have permission to manage plugins.'];
        }

        // A first pass only. HistoryService asks the same question again against
        // the document's owner, which is the check that actually governs — this
        // one cannot see whose page it is, and exists so a role without the
        // capability is turned away before any handler runs.
        if (str_ends_with($path, '/restore') && !$role->can(Capability::RestoreContent)) {
            return ['status' => 403, 'error' => 'You do not have permission to restore a previous version.'];
        }

        return null;
    }

    /**
     * The CSRF gate for unsafe requests. Returns a 403 response to refuse with,
     * or null to allow.
     *
     * @param array<string, mixed> $server The request's $_SERVER, so the token
     *                                     can be read from the header.
     * @return array<string, mixed>|null
     */
    public function enforceCsrf(string $path, string $method, array $server): ?array
    {
        if (CsrfGuard::isSafeMethod($method)) {
            return null;
        }

        // Logging in and out must work without a token; there is no session to
        // protect yet, and being unable to log out would be worse than the risk.
        //
        // GraphQL delivery is POSTed but reads only published content and changes
        // nothing, so a forged request achieves nothing a plain fetch could not,
        // and requiring a token would stop an anonymous front end — the intended
        // caller — from using it at all.
        //
        // A contact-form submission is the same shape of thing: a public POST an
        // anonymous visitor makes, which only appends a form-submission document
        // and can read nothing. Requiring a token here would break the form for
        // any visitor who also holds a session — an editor previewing their own
        // site — while adding no protection, since the endpoint needs no account
        // and a forger gains nothing the plain form does not already allow.
        if (in_array($path, ['auth/login', 'auth/logout', 'graphql', 'forms/submit'], true)) {
            return null;
        }

        $expected = $this->sessions->read()['csrfToken'] ?? null;

        // No session means nothing to forge.
        if (!is_string($expected) || $expected === '') {
            return null;
        }

        if (!CsrfGuard::matches($expected, CsrfGuard::tokenFromRequest($server))) {
            return ['status' => 403, 'error' => 'Missing or invalid CSRF token.'];
        }

        return null;
    }
}
