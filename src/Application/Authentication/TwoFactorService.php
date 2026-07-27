<?php

declare(strict_types=1);

namespace Click\Cms\Application\Authentication;

use Click\Cms\Application\Content\ContentService;
use Click\Cms\Domain\Identity\Base32;
use Click\Cms\Domain\Identity\Totp;
use Click\Cms\Domain\Identity\TwoFactorEnrolment;

/**
 * Turning a second factor on, proving it, using it and turning it off.
 *
 * Core, not a plugin, on `core.md`'s "security is not uninstallable" line. The
 * plugin hook {@see \Click\Cms\Application\Plugin\AuthGate} was designed with
 * exactly this in mind and could carry it — but a second factor that a site can
 * uninstall, or that stops working when a plugin fails to load, is not a second
 * factor. The hook remains the right place for someone else's *different* second
 * factor; this is the one that ships.
 *
 * The enrolment lives in the user's content document under `twoFactor`. That is
 * where accounts already live, so it inherits storage, backup and the audit
 * trail rather than needing a parallel store — and it is why
 * {@see \Click\Cms\Application\Plugin\ContentGate} withholds document bodies
 * from plugins, since this block is now among the secrets that would otherwise
 * be handed out on every save.
 */
final class TwoFactorService
{
    /** How many recovery codes are issued at enrolment. */
    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private readonly ContentService $content,
        private readonly string $issuer = 'Click CMS',
    ) {}

    public function enrolmentFor(string $username): TwoFactorEnrolment
    {
        $account = $this->content->user($username);

        if ($account === null) {
            return TwoFactorEnrolment::none();
        }

        $block = $account->data['twoFactor'] ?? null;

        return TwoFactorEnrolment::fromArray(is_array($block) ? $block : null);
    }

    public function isActiveFor(string $username): bool
    {
        return $this->enrolmentFor($username)->isActive();
    }

    /**
     * Issue a secret and a set of recovery codes, and show them once.
     *
     * The account is **not** protected yet — see
     * {@see TwoFactorEnrolment::isActive()}. Until the person proves they can
     * produce a code, turning two-factor on would lock them out at exactly the
     * moment they had not finished setting it up.
     *
     * Starting enrolment again replaces any unconfirmed one, which is what
     * somebody who lost the QR code halfway through needs. It deliberately does
     * *not* replace a confirmed one: that path is {@see disable()}, and it
     * requires proof.
     *
     * @return array{secret: string, uri: string, recoveryCodes: list<string>}|null
     *         Null when the account does not exist or is already protected.
     */
    public function beginEnrolment(string $username): ?array
    {
        $account = $this->content->user($username);
        if ($account === null) {
            return null;
        }

        if ($this->enrolmentFor($username)->isActive()) {
            return null;
        }

        $secret = Totp::generateSecret();
        $codes = $this->generateRecoveryCodes();

        $account->update(['twoFactor' => TwoFactorEnrolment::pending(
            $secret,
            array_map(TwoFactorEnrolment::hashRecoveryCode(...), $codes),
        )->toArray()]);

        $this->content->save($account);

        return [
            'secret' => $secret,
            'uri' => Totp::enrolmentUri($secret, $username, $this->issuer),
            // Returned in clear exactly once. Only their hashes are stored, so
            // there is no way to show them again — which is the point.
            'recoveryCodes' => $codes,
        ];
    }

    /**
     * Prove the enrolment by producing a code from it.
     *
     * @return bool True when two-factor is now in force for this account.
     */
    public function confirmEnrolment(string $username, string $code, ?int $now = null): bool
    {
        $enrolment = $this->enrolmentFor($username);

        if (!$enrolment->isPending()) {
            return false;
        }

        if (!$this->verifyTotp($enrolment, $code, $now ?? time())) {
            return false;
        }

        $account = $this->content->user($username);
        if ($account === null) {
            return false;
        }

        $account->update(['twoFactor' => $enrolment->confirmed(gmdate('c'))->toArray()]);
        $this->content->save($account);

        return true;
    }

    /**
     * Check a code presented at sign-in, spending a recovery code if that is
     * what was given.
     *
     * A recovery code is consumed on use whether or not the sign-in then
     * completes. That is deliberate: the alternative is a code that can be
     * replayed until a login happens to succeed, which is not single use.
     */
    public function verifyChallenge(string $username, string $code, ?int $now = null): bool
    {
        $enrolment = $this->enrolmentFor($username);

        if (!$enrolment->isActive()) {
            return false;
        }

        if ($this->verifyTotp($enrolment, $code, $now ?? time())) {
            return true;
        }

        $remaining = $enrolment->withoutRecoveryCode($code);
        if ($remaining === null) {
            return false;
        }

        $account = $this->content->user($username);
        if ($account === null) {
            return false;
        }

        $account->update(['twoFactor' => $remaining->toArray()]);
        $this->content->save($account);

        return true;
    }

    /**
     * Turn it off.
     *
     * The caller is responsible for having proved who is asking — this is
     * reached from a handler that has already required the account password,
     * for the same reason changing a password requires the current one: a
     * borrowed session must not be able to remove the thing protecting the
     * account.
     */
    public function disable(string $username): bool
    {
        $account = $this->content->user($username);
        if ($account === null) {
            return false;
        }

        // Null rather than an empty block, so the field is removed from the
        // document rather than left as a husk that `fromArray` has to interpret.
        $account->update(['twoFactor' => null]);
        $this->content->save($account);

        return true;
    }

    /**
     * Verify a TOTP code against the stored base32 secret.
     *
     * The decode happens here rather than in {@see Totp}, so that class can keep
     * taking raw bytes and be checked directly against the RFC's own vectors.
     */
    private function verifyTotp(TwoFactorEnrolment $enrolment, string $code, int $now): bool
    {
        if ($enrolment->secret === null) {
            return false;
        }

        $raw = Base32::decode($enrolment->secret);

        return $raw !== null && $raw !== '' && Totp::verify($raw, $code, $now);
    }

    /**
     * Codes a person can read off a screen and type back.
     *
     * Grouped in two halves with a dash, from an alphabet with no `0`/`O` or
     * `1`/`l`, because these get printed and retyped weeks later — usually in a
     * hurry, usually from a phone photo. Roughly 100 bits, which is why
     * {@see TwoFactorEnrolment} can store them under a fast hash.
     *
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = '';
            for ($n = 0; $n < 20; $n++) {
                if ($n === 10) {
                    $code .= '-';
                }
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = $code;
        }

        return $codes;
    }
}
