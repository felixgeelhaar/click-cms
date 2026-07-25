<?php

declare(strict_types=1);

namespace Click\Cms\Application\Config;

use Click\Cms\Domain\ValueObjects\Locale;

use Click\Cms\Domain\History\RetentionPolicy;
use Click\Cms\Domain\Update\UpdatePolicy;

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

    /*
     * MySQL connection settings, under core.storage.mysql. Each falls back to an
     * environment variable so credentials — a password especially — can be kept
     * out of a committed config file and injected by the runtime (a container's
     * secret, for instance). The environment wins only when the config value is
     * empty, so an explicit config setting is still honoured.
     */

    public function storageMysqlHost(): string
    {
        return $this->mysqlSetting('core.storage.mysql.host', 'CLICK_CMS_MYSQL_HOST', '127.0.0.1');
    }

    public function storageMysqlPort(): int
    {
        $configured = $this->int('core.storage.mysql.port', 0);
        if ($configured > 0) {
            return $configured;
        }
        $env = getenv('CLICK_CMS_MYSQL_PORT');
        return is_string($env) && is_numeric($env) ? (int) $env : 3306;
    }

    public function storageMysqlDatabase(): string
    {
        return $this->mysqlSetting('core.storage.mysql.database', 'CLICK_CMS_MYSQL_DATABASE', 'clickcms');
    }

    public function storageMysqlUser(): string
    {
        return $this->mysqlSetting('core.storage.mysql.user', 'CLICK_CMS_MYSQL_USER', 'clickcms');
    }

    public function storageMysqlPassword(): string
    {
        return $this->mysqlSetting('core.storage.mysql.password', 'CLICK_CMS_MYSQL_PASSWORD', '');
    }

    private function mysqlSetting(string $path, string $envVar, string $default): string
    {
        $configured = trim($this->string($path, ''));
        if ($configured !== '') {
            return $configured;
        }
        $env = getenv($envVar);

        return is_string($env) && $env !== '' ? $env : $default;
    }

    /*
     * PostgreSQL connection settings, under core.storage.postgres, with the same
     * environment-variable fallback the MySQL settings use.
     */

    public function storagePostgresHost(): string
    {
        return $this->mysqlSetting('core.storage.postgres.host', 'CLICK_CMS_POSTGRES_HOST', '127.0.0.1');
    }

    public function storagePostgresPort(): int
    {
        $configured = $this->int('core.storage.postgres.port', 0);
        if ($configured > 0) {
            return $configured;
        }
        $env = getenv('CLICK_CMS_POSTGRES_PORT');
        return is_string($env) && is_numeric($env) ? (int) $env : 5432;
    }

    public function storagePostgresDatabase(): string
    {
        return $this->mysqlSetting('core.storage.postgres.database', 'CLICK_CMS_POSTGRES_DATABASE', 'clickcms');
    }

    public function storagePostgresUser(): string
    {
        return $this->mysqlSetting('core.storage.postgres.user', 'CLICK_CMS_POSTGRES_USER', 'clickcms');
    }

    public function storagePostgresPassword(): string
    {
        return $this->mysqlSetting('core.storage.postgres.password', 'CLICK_CMS_POSTGRES_PASSWORD', '');
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

    /* -------------------------------------------------------------- cache -- */

    /**
     * Whether rendered public pages may be cached, under `core.cache.enabled`.
     *
     * Defaults to off. A cache that arrives switched on with an upgrade is a
     * cache nobody chose, and the one failure mode that matters here — a visitor
     * served a page that is no longer true — is invisible to the person who
     * would have to notice it. A site turns this on deliberately, having read
     * what it cannot see (a `web.render` plugin varying its output on anything
     * that is not in the key).
     */
    public function cacheEnabled(): bool
    {
        return $this->bool('core.cache.enabled', false);
    }

    /* -------------------------------------------------------------- media -- */

    /**
     * The art-directed crops a site declares, under `core.media.crops` as a list
     * of `{ name, aspectWidth, aspectHeight }`. A malformed entry is dropped and
     * a duplicate name keeps the first, so a typo costs that one crop rather than
     * the whole set. Empty by default: a site that wants none declares none, and
     * the media pipeline then cuts only the original ladder and the square.
     *
     * @return list<\Click\Cms\Domain\Media\CropBox>
     */
    public function mediaCrops(): array
    {
        $raw = $this->get('core.media.crops');
        if (!is_array($raw)) {
            return [];
        }

        $crops = [];
        foreach ($raw as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $box = \Click\Cms\Domain\Media\CropBox::fromArray($spec);
            if ($box !== null) {
                $crops[$box->name] ??= $box;
            }
        }

        return array_values($crops);
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

    /**
     * How many failed logins across every account together are tolerated in a
     * window before the site stops accepting logins at all.
     *
     * The lockout settings above count per username, so a password tried once
     * against a hundred accounts never trips any of them. This is the ceiling
     * on that shape of attack. Fifty by default: an installation of the size
     * this CMS is built for sees a handful of fat-fingered logins in a quarter
     * of an hour, never fifty, so the number is far above ordinary clumsiness
     * and far below what makes a spray worth running.
     *
     * Zero or less turns the site-wide ceiling off, for an operator who has
     * decided to bound login rate somewhere in front of the application.
     */
    public function sprayMaxFailures(): int
    {
        return $this->int('core.auth.sprayMaxFailures', 50);
    }

    /** The window {@see self::sprayMaxFailures()} counts over. */
    public function sprayWindowSeconds(): int
    {
        return $this->int('core.auth.sprayWindowSeconds', 900);
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

    /* ------------------------------------------------------------ updates -- */

    /**
     * What the site may install without asking a human.
     *
     * {@see UpdatePolicy::Security} when unset, which is the setting's whole
     * point: a site nobody is watching still repairs itself against known
     * vulnerabilities, and anything that could change behaviour waits.
     */
    public function updatePolicy(): UpdatePolicy
    {
        return UpdatePolicy::fromString($this->string('core.updates.policy', ''));
    }

    /*
     * Where releases are published and which key signs them. Both fall back to
     * an environment variable, like the database credentials do, so a fleet can
     * be pointed at its own release channel by the runtime rather than by
     * editing a committed file on every instance. Empty by default: an update
     * feed is a decision to let a third party choose what code this site runs,
     * so it is named on purpose or not at all.
     */

    public function updateFeedUrl(): string
    {
        return $this->mysqlSetting('core.updates.feedUrl', 'CLICK_CMS_UPDATE_FEED_URL', '');
    }

    /**
     * Every key whose signature on the feed is trusted unconditionally.
     *
     * `core.updates.publicKey` is the single-key form; `core.updates.publicKeys`
     * takes a list, which is what makes a rotation survivable — the old and new
     * key can both be trusted while installations catch up. These are the anchor:
     * a feed can announce further keys, but never revoke one of these, so the
     * operator stays the root of trust.
     *
     * @return list<string>
     */
    public function updatePublicKeys(): array
    {
        $keys = [];
        foreach ($this->stringList('core.updates.publicKeys', []) as $key) {
            $key = trim($key);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        $single = $this->updatePublicKey();
        if ($single !== '' && !in_array($single, $keys, true)) {
            $keys[] = $single;
        }

        return $keys;
    }

    public function updatePublicKey(): string
    {
        return $this->mysqlSetting('core.updates.publicKey', 'CLICK_CMS_UPDATE_PUBLIC_KEY', '');
    }

    /**
     * Whether pre-releases are offered at all. False by default, and never
     * installed unattended even when true — see {@see UpdatePolicy}.
     */
    public function updateAllowPreRelease(): bool
    {
        return $this->bool('core.updates.allowPreRelease', false);
    }

    /* ------------------------------------------------------------- backup -- */

    /**
     * Whether the site takes scheduled backups at all, under `core.backup.enabled`.
     *
     * Off by default. A backup nobody asked for is a directory that grows every
     * night on a host whose disk quota the CMS knows nothing about, and the first
     * anyone hears of it is the site failing to write a page because the volume
     * is full. Taking backups is a decision with a cost, so it is made on
     * purpose. Nothing here affects the administrator's on-demand download, which
     * has always been available and writes nothing to the site.
     */
    public function backupEnabled(): bool
    {
        return $this->bool('core.backup.enabled', false);
    }

    /**
     * How long between scheduled backups. Never below an hour, whatever the file
     * says: a cron line and an interval of zero would produce archives as fast as
     * the machine can write them.
     */
    public function backupIntervalHours(): int
    {
        return max(1, $this->int('core.backup.intervalHours', 24));
    }

    /**
     * How many archives are retained.
     *
     * Never below one. Zero would mean retention deleting the backup it has just
     * taken, which is not a configuration anybody wants and is an easy thing to
     * type. A week by default: long enough that a Friday mistake is still
     * recoverable on Monday, short enough to be a bounded amount of disk.
     */
    public function backupKeep(): int
    {
        return max(1, $this->int('core.backup.keep', 7));
    }

    /** Whether uploaded files are backed up alongside the documents. */
    public function backupIncludeMedia(): bool
    {
        return $this->bool('core.backup.includeMedia', true);
    }

    /**
     * The largest single media file a backup will take, in bytes.
     *
     * Anything above it is *recorded as skipped in the manifest*, never silently
     * omitted — a backup that quietly dropped the 2 GB video is the failure this
     * whole feature was rebuilt to prevent, and a size ceiling is the obvious
     * place to reintroduce it.
     *
     * 512 MB by default, which is above the CMS's own upload ceiling for video
     * and therefore skips nothing on a site whose media arrived through the CMS.
     * Zero or less means no ceiling.
     */
    public function backupMaxMediaBytes(): int
    {
        return $this->int('core.backup.maxMediaBytes', 512 * 1024 * 1024);
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
