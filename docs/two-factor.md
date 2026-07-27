# Two-step sign-in

Also called two-factor authentication, or 2FA. With it on, signing in needs your
password *and* a six-digit code from an app on your phone.

It is worth turning on. A password can be guessed, reused from a site that was
breached, or typed into a convincing fake. A code that changes every thirty
seconds and lives on your phone is not any of those things.

## Turning it on

**Profile → Two-step sign-in → Set up two-step sign-in.**

You will need an authenticator app. Any of them work — Google Authenticator, 1Password,
Bitwarden, Aegis, the one built into your password manager.

1. **Add the site to your app.** The screen shows a key; your app either scans it
   or lets you type it in.
2. **Write down the recovery codes.** Ten of them. Each works once.
3. **Type the six-digit code your app is showing.** This proves the app is set up
   before anything starts depending on it.

That third step is not a formality. Until you complete it, nothing changes —
you can still sign in with your password alone. This is deliberate: turning on a
lock you have not yet tested the key for is how people lock themselves out.

## The recovery codes

**Write them down somewhere that is not your phone.** They are the only way back
into your account if you lose it.

They are shown once and never again. The site stores only a scrambled version,
so nobody — including whoever runs the site — can read them back to you. If you
lose both your phone and the codes, an administrator has to remove your account
and make you a new one.

Each code works once. After you use one, it is gone, and the Profile screen tells
you how many are left.

## Signing in from then on

Password first, then the code. If you cannot reach your app, type one of your
recovery codes into the same box — it takes either.

Codes are only good for about a minute either side of now, so if yours are being
refused, check that your phone's clock is set automatically.

## Turning it off

**Profile → Two-step sign-in → Turn off**, and type your password.

The password is asked for on purpose. Without it, anyone who found your laptop
still signed in could quietly strip the protection off your account — which
would make the protection worth very little.

## For whoever runs the site

- It is **core**, not a plugin, and cannot be uninstalled. See
  [`docs/core.md`](core.md).
- It is **per account and opt-in**. There is no way to require it for everyone
  yet; that is a policy question — whether an administrator can lock a colleague
  out of a site until they own a smartphone — and it has not been settled.
- The shared secret and the recovery-code hashes live in the account's own
  document, under `twoFactor`. That is the reason
  [`ContentGate`](core.md#extension-points) withholds document bodies from
  plugins: without that rule, every plugin would receive this block on every
  save.
- Codes are **TOTP, RFC 6238, SHA-1, six digits, thirty seconds** — the settings
  every authenticator app implements. SHA-256 is permitted by the standard and is
  not used here, because most apps silently ignore the request and then generate
  codes the site rejects.
- Wrong codes count against **the same lockout as wrong passwords**. Six digits
  with unlimited guesses would be a weaker secret than the password it is
  strengthening.
