<?php

declare(strict_types=1);

namespace Click\Cms\Application\Update;

use Click\Cms\Domain\Update\SemanticVersion;
use Click\Cms\Domain\Update\UpdateDecision;
use Click\Cms\Domain\Update\UpdatePolicy;

/**
 * The one place the rest of the application asks about updates.
 *
 * Three collaborators, each of which already knows one thing well: the feed
 * knows what has been published and refuses to believe an unsigned answer, the
 * decision knows whether a release supersedes what is running and whether the
 * policy permits taking it unattended, and the installer knows how to apply one
 * without leaving the site broken. What is left — and what lives here — is the
 * sequencing, and the record of what happened.
 *
 * The record is the part that is easy to leave out and expensive not to have.
 * An unattended update that succeeds is invisible; one that fails is invisible
 * *and* confusing, because the administrator's first question is "did something
 * try to change this site, and when?". Every attempt is appended to
 * `data/updates/history.json`, whether it succeeded or not.
 */
final class UpdateService
{
    /** Kept short deliberately: this is a log an administrator reads, not an audit trail. */
    private const HISTORY_LIMIT = 50;

    /**
     * Why the feed produced nothing, from the most recent check.
     *
     * It does not travel in the {@see UpdateDecision} because that object is
     * pure domain — it is handed releases and knows nothing about where they
     * failed to come from. But "your feed URL is wrong" is exactly what an
     * administrator staring at "Already up to date" needs to be told, so it is
     * kept here for the caller that reports status.
     */
    private ?string $lastFeedError = null;

    public function __construct(
        private readonly string $basePath,
        private readonly ReleaseFeed $feed,
        private readonly UpdateInstaller $installer,
        /** The version of the code currently running — {@see \Click\Cms\Core\Application::VERSION}. */
        private readonly string $currentVersion,
    ) {
    }

    public function currentVersion(): string
    {
        return $this->currentVersion;
    }

    /** Why the last feed fetch yielded nothing, or null when it was fine. */
    public function lastFeedError(): ?string
    {
        return $this->lastFeedError;
    }

    /**
     * What, if anything, is on offer for this installation right now.
     *
     * `PHP_VERSION` rather than a configured value: the question is what *this*
     * process can run, and a release requiring a PHP this server does not have
     * is not an offer however the site is configured.
     */
    public function check(
        string $feedUrl,
        string $publicKey,
        UpdatePolicy $policy,
        bool $allowPreRelease = false,
    ): UpdateDecision {
        $feed = $this->feed->fetch($feedUrl, $publicKey);
        $this->lastFeedError = $feed['error'];

        return UpdateDecision::decide(
            $this->current(),
            $feed['releases'],
            $policy,
            PHP_VERSION,
            $allowPreRelease,
        );
    }

    /**
     * Install only what the policy allows to happen with nobody watching.
     *
     * This is what a cron or a request-tail hook calls. When the policy does not
     * permit the offered release it does nothing at all — not even a record,
     * because "we looked and were not allowed" is the normal state of a site on
     * the default policy and would otherwise fill the history with noise.
     *
     * @return array{applied: bool, success: bool, error: ?string, backup: ?string, decision: array<string, mixed>}
     */
    public function applyIfAutomatic(
        string $feedUrl,
        string $publicKey,
        UpdatePolicy $policy,
        bool $allowPreRelease = false,
    ): array {
        $decision = $this->check($feedUrl, $publicKey, $policy, $allowPreRelease);

        if (!$decision->hasUpdate() || !$decision->automatic) {
            return $this->notApplied($decision, null);
        }

        return $this->apply($decision);
    }

    /**
     * Install what is on offer because an administrator asked for it.
     *
     * The difference from {@see applyIfAutomatic} is the whole point: a human
     * pressed the button, so `automatic` — which only ever answered "may this
     * happen unattended?" — no longer applies. What is not waived is the
     * existence of an update: there is nothing to approve when nothing is
     * offered, and a policy of `manual` offers nothing by design, so a site
     * managed by a deploy pipeline cannot be updated out from under it by a
     * click either.
     *
     * @return array{applied: bool, success: bool, error: ?string, backup: ?string, decision: array<string, mixed>}
     */
    public function applyApproved(
        string $feedUrl,
        string $publicKey,
        UpdatePolicy $policy,
        bool $allowPreRelease = false,
    ): array {
        $decision = $this->check($feedUrl, $publicKey, $policy, $allowPreRelease);

        if (!$decision->hasUpdate()) {
            return $this->notApplied(
                $decision,
                $this->lastFeedError ?? ($decision->reason !== '' ? $decision->reason : 'There is no update to install.'),
            );
        }

        return $this->apply($decision);
    }

    /**
     * Every update this installation has attempted, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        $path = $this->historyPath();
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /* -------------------------------------------------------------- internals -- */

    private function apply(UpdateDecision $decision): array
    {
        $release = $decision->release;
        // Only ever reached with a release in hand; the callers check first.
        assert($release !== null);

        $result = $this->installer->install($release);

        $this->record(
            $release->version->toString(),
            (bool) $result['success'],
            $result['error'] ?? null,
            $result['backup'] ?? null,
        );

        return [
            'applied' => true,
            'success' => (bool) $result['success'],
            'error' => $result['error'] ?? null,
            'backup' => $result['backup'] ?? null,
            'decision' => $decision->toArray(),
        ];
    }

    /**
     * @return array{applied: bool, success: bool, error: ?string, backup: ?string, decision: array<string, mixed>}
     */
    private function notApplied(UpdateDecision $decision, ?string $error): array
    {
        return [
            'applied' => false,
            'success' => false,
            'error' => $error,
            'backup' => null,
            'decision' => $decision->toArray(),
        ];
    }

    private function current(): SemanticVersion
    {
        // An unparseable version means every release looks newer, which would
        // offer an update on a build that never declared one. Treated as 0.0.0
        // only because that is what "we do not know what is running" honestly
        // implies — the administrator still approves anything non-automatic.
        return SemanticVersion::tryFromString($this->currentVersion)
            ?? SemanticVersion::fromString('0.0.0');
    }

    /**
     * Append one attempt. Failures to write are swallowed: losing the note is
     * bad, but refusing an otherwise-successful update because the log could not
     * be written would be worse.
     */
    private function record(string $toVersion, bool $ok, ?string $error, ?string $backup): void
    {
        $entries = $this->history();
        array_unshift($entries, [
            'at' => gmdate(DATE_ATOM),
            'from' => $this->currentVersion,
            'to' => $toVersion,
            'ok' => $ok,
            'error' => $error,
            'backup' => $backup === null ? null : basename($backup),
        ]);

        $dir = $this->basePath . '/data/updates';
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return;
        }

        @file_put_contents(
            $this->historyPath(),
            (string) json_encode(array_slice($entries, 0, self::HISTORY_LIMIT), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function historyPath(): string
    {
        return $this->basePath . '/data/updates/history.json';
    }
}
