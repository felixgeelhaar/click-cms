<?php

declare(strict_types=1);

namespace Click\Cms\Application\Authentication;

/**
 * Bounds the rate of failed logins across the whole site.
 *
 * {@see LoginThrottle} counts per username, which stops someone grinding
 * through a dictionary against one account but is blind to the opposite shape
 * of attack: one common password tried against a hundred different usernames.
 * Every account stays two or three failures below its own threshold, no lockout
 * ever fires, and the site is nonetheless being worked through methodically.
 * This counts failures without regard to which account they named, so that
 * pattern has a ceiling too.
 *
 * It is a separate class rather than more state inside the throttle because the
 * two answer different questions — "is this account under attack" and "is this
 * site under attack" — and only the second has to be consulted before an
 * account is even named. Keeping them apart is what lets the site-wide check
 * run first, before anything reveals whether the username exists.
 *
 * Deliberately not per-IP, for the reason set out in {@see LoginThrottle}: an
 * attacker rotates addresses far more easily than a defender can track them,
 * and a spray is usually distributed anyway. Counting the failures themselves
 * needs no view of where they came from.
 */
final class LoginSprayGuard
{
    /**
     * @param int $maxFailures Failures tolerated in a window. Zero or less
     *   switches the guard off entirely — an operator who genuinely wants no
     *   site-wide ceiling should be able to say so outright, rather than
     *   approximate it with a number so large it never fires.
     */
    public function __construct(
        private readonly string $path,
        private readonly int $maxFailures = 50,
        private readonly int $windowSeconds = 900,
    ) {}

    /**
     * How many seconds until logins are accepted again, or null when they are
     * being accepted now.
     *
     * There is no stored "blocked until" stamp anywhere in here, and that is
     * the point. A latched deadline outlives the failures that set it: a burst
     * that ends at noon would still be refusing a legitimate editor at ten past
     * on the strength of a number written down earlier. Instead the answer is
     * recomputed from the failure times themselves on every call, so the
     * refusal lifts the moment the last failure that justified it falls out of
     * the window — not a second later.
     */
    public function secondsRemaining(?int $now = null): ?int
    {
        if ($this->maxFailures < 1) {
            return null;
        }

        $now ??= time();
        $failures = $this->recent($now);
        $count = count($failures);

        if ($count < $this->maxFailures) {
            return null;
        }

        // The block ends when enough of the oldest failures have expired to put
        // the count back under the threshold — that is, when the one at this
        // position ages out.
        $remaining = ($failures[$count - $this->maxFailures] + $this->windowSeconds) - $now;

        return $remaining > 0 ? $remaining : null;
    }

    public function isTripped(?int $now = null): bool
    {
        return $this->secondsRemaining($now) !== null;
    }

    public function recordFailure(?int $now = null): void
    {
        $now ??= time();

        $failures = $this->recent($now);
        $failures[] = $now;

        $this->save($failures);
    }

    /*
     * There is deliberately no way to clear this counter. A successful login
     * cannot be trusted to mean the attack is over: an attacker who holds one
     * valid account of their own — a spray against a site with open
     * registration, say — would otherwise reset the ceiling at will and spray
     * indefinitely underneath it. The count expires by time or not at all.
     */

    /**
     * Failure times inside the window, oldest first.
     *
     * A stamp in the future is dropped rather than trusted: the only ways to
     * get one are a clock that moved backwards or a hand-edited file, and
     * neither should be able to hold the login form shut indefinitely.
     *
     * @return list<int>
     */
    private function recent(int $now): array
    {
        $cutoff = $now - $this->windowSeconds;

        $failures = [];
        foreach ($this->all() as $stamp) {
            if (is_int($stamp) && $stamp > $cutoff && $stamp <= $now) {
                $failures[] = $stamp;
            }
        }

        sort($failures);

        return $failures;
    }

    /**
     * @return list<mixed>
     */
    private function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($this->path), true);
        $failures = is_array($decoded) ? ($decoded['failures'] ?? null) : null;

        // A corrupt or truncated file reads as no failures rather than as an
        // error. Losing the count costs at most one window of protection;
        // throwing here would cost every login on the site.
        return is_array($failures) ? array_values($failures) : [];
    }

    /**
     * @param list<int> $failures
     */
    private function save(array $failures): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        // Only the newest $maxFailures can change any answer this class gives,
        // so nothing older is worth keeping. That is what bounds the file under
        // a sustained attack: a million attempts cost the same few bytes as
        // fifty, and the state left behind is a function of the threshold
        // rather than of the attacker's patience.
        $keep = max(1, $this->maxFailures);
        if (count($failures) > $keep) {
            $failures = array_slice($failures, -$keep);
        }

        // Read-modify-write, so two failures landing in the same instant can
        // cost one count. That is the same exposure the per-username throttle
        // carries, and it errs the safe way round for a defender: a spray is
        // thousands of attempts, and losing a handful of them to a race moves
        // the ceiling by seconds, not by anything an attacker could aim at.
        file_put_contents(
            $this->path,
            json_encode(['failures' => array_values($failures)], JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
