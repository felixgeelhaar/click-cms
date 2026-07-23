<?php

declare(strict_types=1);

namespace Click\Cms\Application\Config;

use Click\Cms\Domain\ValueObjects\Locale;

use Click\Cms\Domain\History\RetentionPolicy;

/**
 * Typed access to `config/core.json`.
 *
 * Settings were previously read with `$this->coreConfig['core']['auth'][...] ??
 * default` wherever they were needed, which meant every default was repeated at
 * each call site and could disagree between them. Here each has one name and
 * one default.
 *
 * A missing or unreadable file is not an error: every setting has a default, so
 * a fresh install with no configuration runs.
 */
final class CoreConfig
{
    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            return new self([]);
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return new self(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /* ------------------------------------------------------------ content -- */

    /**
     * Whether the REST delivery API answers.
     *
     * Delivery is optional by design: a site that renders its own pages needs
     * no API for a front end to read. Management endpoints are unaffected.
     */
    public function restApiEnabled(): bool
    {
        return $this->bool('core.restApi.enabled', true);
    }

    public function graphqlEnabled(): bool
    {
        return $this->bool('core.graphql.enabled', true);
    }

    /**
     * Origins allowed to read the delivery API from a browser.
     *
     * Empty by default, which means same-origin only. A front end served from
     * another origin — the usual arrangement when the CMS backs a separate
     * site — must be named explicitly. A wildcard is deliberately not
     * supported: the delivery API is anonymous, but a public read API that
     * anybody's page can call is still a decision to make on purpose.
     *
     * @return list<string>
     */
    public function deliveryAllowedOrigins(): array
    {
        return $this->stringList('core.delivery.allowedOrigins', []);
    }

    /* ---------------------------------------------------------- languages -- */

    /**
     * The language a document is in when nothing says otherwise.
     *
     * Also the language served when a translation is missing, and the one the
     * pre-languages storage layout is taken to hold. `en` by default so a site
     * that has never heard of this setting behaves exactly as it did before.
     */
    public function defaultLocale(): Locale
    {
        return Locale::tryFromString($this->string('core.languages.default', Locale::DEFAULT))
            ?? Locale::default();
    }

    /**
     * Every language this site publishes in.
     *
     * The default locale is always a member, whatever the file says: a site
     * whose configuration excludes the language its fallback serves would have
     * a fallback nobody is allowed to ask for.
     *
     * @return list<Locale>
     */
    public function locales(): array
    {
        $default = $this->defaultLocale();

        $locales = [$default->code => $default];

        foreach ($this->stringList('core.languages.available', []) as $code) {
            $locale = Locale::tryFromString($code);

            // An unparseable tag is dropped rather than thrown: a typo in the
            // list of languages should cost that one language, not the site.
            if ($locale !== null) {
                $locales[$locale->code] ??= $locale;
            }
        }

        return array_values($locales);
    }

    /** Whether this site publishes in a given language at all. */
    public function supportsLocale(Locale $locale): bool
    {
        foreach ($this->locales() as $known) {
            if ($known->equals($locale)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------ storage -- */

    /**
     * Which storage backend holds content.
     *
     * Flat files by default, so a fresh install with no configuration at all
     * boots without a database — that is a stated property of core, not a
     * convenience. SQLite is opt-in for sites that have outgrown a directory
     * full of files.
     *
     * Returned verbatim rather than normalised so that a misconfigured value can
     * be quoted back to whoever wrote it. Matching is the factory's job.
     */
    public function storageBackend(): string
    {
        return trim($this->string('core.storage.backend', 'json'));
    }

    /**
     * Where the SQLite database lives, relative to the installation root unless
     * absolute.
     *
     * Under `data/` because that is the directory already expected to be
     * writable and already kept out of the web root — a database served over
     * HTTP would hand out every account record in it.
     */
    public function storageSqlitePath(): string
    {
        return trim($this->string('core.storage.sqlite.path', 'data/content.sqlite'));
    }

    /* ------------------------------------------------------------ history -- */

    /**
     * How many versions of a document are kept.
     *
     * Never below one, whatever the file says: zero would leave history looking
     * enabled while retaining nothing, and an editor discovering that on the
     * day they need it is the failure the feature exists to prevent. A site
     * that genuinely wants no history should say so by not asking for a
     * restore, not by configuring a limit that quietly does nothing.
     */
    public function historyRetainedVersions(): int
    {
        return max(1, $this->int('core.history.retainVersions', RetentionPolicy::DEFAULT_LIMIT));
    }

    /* --------------------------------------------------------------- auth -- */

    public function authEnabled(): bool
    {
        return $this->bool('core.auth.enabled', true);
    }

    public function sessionTtlSeconds(bool $remembered = false): int
    {
        return $remembered
            ? $this->int('core.auth.rememberTtlSeconds', 2_592_000)
            : $this->int('core.auth.sessionTtlSeconds', 28_800);
    }

    public function idleTimeoutSeconds(): int
    {
        return $this->int('core.auth.idleTimeoutSeconds', 1_800);
    }

    public function lockoutMaxAttempts(): int
    {
        return $this->int('core.auth.lockoutMaxAttempts', 5);
    }

    public function lockoutWindowSeconds(): int
    {
        return $this->int('core.auth.lockoutWindowSeconds', 900);
    }

    public function lockoutDurationSeconds(): int
    {
        return $this->int('core.auth.lockoutDurationSeconds', 900);
    }

    /** Never below eight, whatever the file says. */
    public function passwordMinLength(): int
    {
        return max(8, $this->int('core.auth.passwordMinLength', 8));
    }

    /* -------------------------------------------------------- marketplace -- */

    public function marketplaceEnabled(): bool
    {
        return $this->bool('core.marketplace.enabled', true);
    }

    public function marketplaceRegistryUrl(): string
    {
        return $this->string('core.marketplace.registryUrl', '');
    }

    public function marketplacePublicKey(): string
    {
        return $this->string('core.marketplace.publicKey', '');
    }

    /* ------------------------------------------------------------ plugins -- */

    /**
     * @return list<string>
     */
    public function excludedPluginIds(): array
    {
        return $this->stringList('core.plugins.exclude.ids', ['admin-ui', 'authentication']);
    }

    /**
     * @return list<string>
     */
    public function excludedPluginDirs(): array
    {
        return $this->stringList('core.plugins.exclude.dirs', ['admin-ui', 'auth']);
    }

    /* -------------------------------------------------------------- lookup -- */

    private function get(string $path): mixed
    {
        $value = $this->values;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function bool(string $path, bool $default): bool
    {
        $value = $this->get($path);

        return $value === null ? $default : (bool) $value;
    }

    private function int(string $path, int $default): int
    {
        $value = $this->get($path);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function string(string $path, string $default): string
    {
        $value = $this->get($path);

        return is_string($value) ? $value : $default;
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private function stringList(string $path, array $default): array
    {
        $value = $this->get($path);

        if (!is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
