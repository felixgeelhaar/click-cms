<?php

declare(strict_types=1);

namespace Click\Cms\Http;

/**
 * The URL prefix an installation is served under.
 *
 * click-cms is built for ordinary shared hosting, and on shared hosting a site
 * in a subdirectory is the normal case: an account's whole document root is one
 * directory tree, and a CMS lands somewhere inside it rather than at the domain
 * root. Everything used to assume the root — routing matched `/api/…` against
 * the raw request path, and every generated link was absolute from `/` — so an
 * installation under `/2026/cms/` matched no route at all.
 *
 * This is the one place that knows about the prefix. It does two opposite jobs,
 * and a site needs both to work:
 *
 *  - {@see strip()} takes it off an incoming request path, so every route below
 *    can go on matching `/api/…` and know nothing about where it is installed;
 *  - {@see url()} puts it back on a path the CMS hands out, so links, media URLs
 *    and form actions point at this installation rather than at the domain root.
 *
 * Strip without url gives a site that routes correctly and then hands out broken
 * links, which is the failure that is easy to ship because the admin still works.
 *
 * The value is derived, not configured, wherever possible: a person installing a
 * CMS by unzipping it into a directory should not also have to describe where
 * they put it. `SCRIPT_NAME` already says so.
 */
final class BasePath
{
    private function __construct(private readonly string $prefix) {}

    /**
     * No prefix — a site at the domain root, and the default every URL-emitting
     * class takes.
     *
     * A null object rather than a nullable dependency on purpose: `?->url() ??
     * $path` at a dozen call sites is the shape that eventually leaves one URL
     * unprefixed, and one unprefixed URL is a broken page.
     */
    public static function root(): self
    {
        return new self('');
    }

    /**
     * Work out the prefix for this request.
     *
     * Three sources, most trustworthy first: what the site configured, what a
     * proxy the site trusts says, and what the request itself shows.
     *
     * @param array<string, mixed> $server     The request's `$_SERVER`.
     * @param string|null          $configured `core.basePath`, when a site sets it.
     * @param TrustedProxies|null  $proxies    Who may set `X-Forwarded-Prefix`.
     *                                         Nobody, unless a site says otherwise.
     */
    public static function detect(
        array $server,
        ?string $configured = null,
        ?TrustedProxies $proxies = null,
    ): self {
        // An explicit setting wins, including an explicitly empty one. It exists
        // for the case detection cannot see: behind a reverse proxy the script
        // lives at one path and the public URL is another, and only the operator
        // knows the mapping. That cuts both ways — a site published at the root
        // from a script in a directory says so with an empty value, and falling
        // back to detection there would overrule them with the exact value they
        // configured around. Absent (null) is what means "work it out".
        if ($configured !== null) {
            return new self(self::normalise($configured));
        }

        $forwarded = self::forwardedPrefix($server, $proxies);
        if ($forwarded !== null) {
            return new self(self::normalise($forwarded));
        }

        $script = (string) ($server['SCRIPT_NAME'] ?? '');

        // Only a script name that actually names a PHP file describes where the
        // installation lives. PHP's built-in server reports the *requested path*
        // as SCRIPT_NAME when it routes through a script, so trusting it blindly
        // reads `/api/pages` as a site installed under `/api` — every route then
        // 404s on a developer's machine and nowhere else.
        if (!str_ends_with($script, '.php')) {
            return new self('');
        }

        $directory = str_replace('\\', '/', dirname($script));

        return new self(self::normalise($directory));
    }

    /**
     * The prefix a trusted proxy says the site is published under, if any.
     *
     * `X-Forwarded-Prefix` is the field's convention for the arrangement
     * detection cannot see: a proxy serving the site at `/blog/` in front of an
     * application installed at a root, where the script's own path says nothing
     * about the public URL. Honouring it means such a site needs no per-
     * environment configuration.
     *
     * It is only ever read from a sender the site named as its proxy, because
     * this header is written by whoever sent the request. The prefix goes into
     * every URL the site emits, so believing an untrusted sender would let a
     * visitor rewrite every link on a page — and a cached render would then hand
     * their version to everybody else.
     *
     * @param array<string, mixed> $server
     */
    private static function forwardedPrefix(array $server, ?TrustedProxies $proxies): ?string
    {
        if ($proxies === null || !$proxies->trusts((string) ($server['REMOTE_ADDR'] ?? ''))) {
            return null;
        }

        $header = (string) ($server['HTTP_X_FORWARDED_PREFIX'] ?? '');
        if ($header === '') {
            return null;
        }

        // Leftmost wins in a chain, as with every other X-Forwarded header: it is
        // the prefix the outermost proxy — the one facing the visitor — publishes.
        $value = trim(explode(',', $header, 2)[0]);

        // A trusted proxy is trusted, not infallible, and a misconfigured one is
        // a likelier source of nonsense than an attacker. Only something that is
        // actually a path is accepted: no scheme, no traversal, nothing that has
        // to be escaped in an attribute. Anything else falls through to the
        // request's own evidence rather than being concatenated into every link.
        // No colon in the allowed set, though a path segment may legally contain
        // one: a prefix has no use for it, and leaving it out is what makes
        // `https:/evil.example` fail here rather than end up spliced onto the
        // front of every link as a relative path.
        if ($value === '' || preg_match('#^/?[A-Za-z0-9._~%!$&\'()*+,;=@/-]*$#', $value) !== 1) {
            return null;
        }
        if (str_contains($value, '..') || str_contains($value, '//')) {
            return null;
        }

        return $value;
    }

    /** The prefix, with a leading slash and no trailing one. Empty at the root. */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Take the prefix off an incoming request path.
     *
     * Expects a path with no query string — the kernel splits that off first,
     * because a prefix is a path concern and a query is not.
     *
     * A path that does not belong to this installation is returned unchanged, so
     * it reaches the router and 404s there. Rewriting it to something that does
     * match would serve one site's page under another site's URL.
     */
    public function strip(string $path): string
    {
        $path = $this->withoutPrefix($path);

        // Shared hosting without mod_rewrite reaches the front controller by
        // naming it — `/2026/cms/index.php/api/pages` — and the rest of the path
        // arrives as PATH_INFO. Taking the script name off here means the router
        // sees the same `/api/pages` either way, so a host that cannot rewrite
        // still runs the site.
        if ($path === '/index.php') {
            return '/';
        }
        if (str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php'));
        }

        return $path;
    }

    /**
     * Put the prefix on a path the CMS hands out.
     *
     * Anything already absolute — an external URL, a protocol-relative one — is
     * left alone: it names a host of its own, and prefixing it would corrupt it.
     */
    public function url(string $path): string
    {
        if ($this->prefix === '' || $path === '') {
            return $path;
        }

        if (str_starts_with($path, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) === 1) {
            return $path;
        }

        if (!str_starts_with($path, '/')) {
            return $path;
        }

        return $this->prefix . $path;
    }

    private function withoutPrefix(string $path): string
    {
        if ($this->prefix === '') {
            return $path;
        }

        // The installation root itself, with or without its trailing slash.
        if ($path === $this->prefix || $path === $this->prefix . '/') {
            return '/';
        }

        // A segment boundary, not a string prefix: `/cms` must not swallow
        // `/cmsx`, which is a different directory that happens to start the same.
        if (str_starts_with($path, $this->prefix . '/')) {
            return substr($path, strlen($this->prefix));
        }

        return $path;
    }

    /** `2026/cms/` and `/2026/cms` are the same prefix; `/` and `` are none. */
    private static function normalise(string $value): string
    {
        $value = trim($value);
        $value = '/' . trim(str_replace('\\', '/', $value), '/');

        return $value === '/' ? '' : $value;
    }
}
