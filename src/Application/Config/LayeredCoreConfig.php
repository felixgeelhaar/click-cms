<?php

declare(strict_types=1);

namespace Click\Cms\Application\Config;

/**
 * A site's `config/core.json` laid over the installation's.
 *
 * Multi-site gave each site its own content, media, accounts and settings and
 * left this one file shared, so eight client sites had one storage backend, one
 * set of languages and one identity provider between them. That was recorded as
 * a limitation rather than hidden, and this removes it.
 *
 * ## A deep merge, not a replacement
 *
 * A site declaring `core.languages.default` should get its own default language
 * and keep everything else the installation set. Replacing the whole document
 * would mean every site restating the entire configuration, and a site that
 * forgot a key would silently fall back to a built-in default rather than to the
 * installation's considered value.
 *
 * Lists are replaced whole rather than concatenated. `available: ["de"]` has to
 * mean *this site publishes German*, not German in addition to whatever the
 * installation listed — otherwise a site can only ever widen a set, never narrow
 * one, and narrowing is the common case.
 *
 * ## What a site may not override
 *
 * Some settings have exactly one answer per installation because the thing they
 * configure exists once. Self-update replaces `src/` in a directory tree every
 * site runs: two sites asking for different update policies is not a
 * disagreement to resolve, it is a question with one outcome, and whichever site
 * the updater happened to run as would decide for all of them. The marketplace
 * is the same — it installs plugin code into the shared `plugins/` directory, so
 * "where may code be installed from" cannot be a per-site answer without letting
 * one site choose what another site runs.
 *
 * Those are refused rather than merged, and {@see refused()} names any a site
 * tried, so the attempt can be logged instead of vanishing. Silently ignoring
 * configuration somebody wrote is the failure mode `core.md` keeps naming.
 *
 * Which *plugins load* is deliberately not on that list. It looks similar and is
 * not: the code is installed once and shared, and excluding one only decides
 * what boots for this site. One client having the visual builder and another not
 * is a normal thing to want, and it changes nothing outside its own request.
 */
final class LayeredCoreConfig
{
    /**
     * Configuration a site may not override, by dotted path.
     *
     * Deliberately short, and each entry is here because the thing it configures
     * exists once per installation rather than once per site.
     *
     * @var list<string>
     */
    private const INSTALLATION_ONLY = [
        'core.updates',
        'core.marketplace',
    ];

    /** @var list<string> Paths a site tried to override and was refused. */
    private array $refused = [];

    /**
     * The configuration this site actually runs on.
     *
     * @param array<string, mixed> $installation Parsed `config/core.json`.
     * @param array<string, mixed> $site         Parsed `sites/<id>/config/core.json`, or `[]`.
     * @return array<string, mixed>
     */
    public function effective(array $installation, array $site): array
    {
        $this->refused = [];

        // Stripped before merging rather than restored afterwards. Restoring
        // would mean the installation's value has to exist to win, so a site
        // could introduce a setting the installation never declared — which for
        // `core.updates.feedUrl` is the whole of the update channel.
        $site = $this->withoutInstallationOnly($site);

        return $this->mergeDeep($installation, $site);
    }

    /**
     * Paths the site declared and was not allowed to set, for the caller to log.
     *
     * @return list<string>
     */
    public function refused(): array
    {
        return $this->refused;
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private function withoutInstallationOnly(array $site): array
    {
        foreach (self::INSTALLATION_ONLY as $path) {
            $segments = explode('.', $path);
            $site = $this->forget($site, $segments, $path);
        }

        return $site;
    }

    /**
     * Remove one dotted path, recording it if anything was actually there.
     *
     * @param array<string, mixed> $subject
     * @param list<string>         $segments
     * @return array<string, mixed>
     */
    private function forget(array $subject, array $segments, string $path): array
    {
        $head = array_shift($segments);

        if (!array_key_exists($head, $subject)) {
            return $subject;
        }

        if ($segments === []) {
            unset($subject[$head]);
            $this->refused[] = $path;

            return $subject;
        }

        if (!is_array($subject[$head])) {
            return $subject;
        }

        $subject[$head] = $this->forget($subject[$head], $segments, $path);

        // A branch emptied by the removal is dropped too, so an otherwise empty
        // `core` does not survive as a husk the merge then has to reason about.
        if ($subject[$head] === []) {
            unset($subject[$head]);
        }

        return $subject;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function mergeDeep(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $existing = $base[$key] ?? null;

            // Both sides an associative array: merge. Anything else — a list, a
            // scalar, a shape change in either direction — takes the site's
            // value whole, because half-merging two different shapes produces
            // something neither side asked for.
            if (is_array($value) && is_array($existing) && !$this->isList($value) && !$this->isList($existing)) {
                $base[$key] = $this->mergeDeep($existing, $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * `array_is_list` is PHP 8.1, which is this project's floor, so it could be
     * used directly — it is spelled out only because an empty array is a list
     * *and* an empty map, and this treats it as a list so that `crops: {}` from
     * a site replaces rather than merges into the installation's crops.
     *
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return $value === [] || array_is_list($value);
    }
}
