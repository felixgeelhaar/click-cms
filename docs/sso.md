# Single sign-on

Letting people sign in with the account they already have — Entra ID, Google
Workspace, Okta, Keycloak, Authentik, anything that speaks OpenID Connect.

The reason to want it is rarely convenience. It is that **leaving the
organisation removes access**: when somebody's account is disabled centrally,
they stop being able to sign in here too, without anybody remembering to come
and delete them.

## What is supported, and what is not

**OpenID Connect**, authorization code flow with PKCE. That is what every
provider in the list above speaks.

**SAML is not supported and is not planned.** Verifying a SAML assertion means
XML digital signatures, which means XML canonicalisation, which is a
well-known source of signature-bypass bugs and is not something to hand-roll —
and a library for it would be a runtime dependency, which this project does not
take (see [`docs/core.md`](core.md)). A provider that offers both should be
configured for OIDC. One that offers only SAML cannot be used here, and saying so
plainly is better than a half-implementation nobody should trust.

## Setting it up

Configuration, not an admin screen. Setting this up means pasting a client secret
and deciding who may sign in — done once, by whoever also configures the identity
provider, and a mistake hands out accounts. A file that lives with the deployment
and can be reviewed in a diff is the right home for that.

In `config/core.json`:

```json
{
  "core": {
    "sso": {
      "enabled": true,
      "issuer": "https://id.example.com",
      "clientId": "click-cms",
      "clientSecret": "…",
      "redirectUri": "https://site.example.com/api/auth/sso/callback",
      "buttonLabel": "Sign in with Acme",

      "linking": "subject",
      "provisionAccounts": false,
      "provisionRole": "viewer",
      "allowPasswordLogin": false,

      "roleClaim": "groups",
      "roleMap": {
        "cms-admins": "admin",
        "cms-editors": "editor"
      }
    }
  }
}
```

At the provider, register `redirectUri` exactly as written above. It is checked
by the provider, and it is one of the few things that must match character for
character.

**`enabled` means "has everything it needs".** A block missing the issuer, the
client id, the secret or the redirect is treated as off — so a half-finished
configuration produces no button, rather than a button that leads to a broken
redirect nobody can diagnose.

### Who is allowed in

The two settings that decide this both default to the restrictive answer, and
the defaults are the important part.

**`linking`** — how a sign-in is matched to a local account.

- `subject` (default) — only accounts already carrying this provider's identifier
  can sign in. An administrator links them by setting `ssoSubject` on the
  account, or by turning the next option on once and back off.
- `verified-email` — additionally adopt an existing account whose email matches a
  **verified** address, recording the identifier on it for next time. This is how
  an existing team gets on to single sign-on without hand-editing every account.

  It is not the default because it is a real, if narrow, risk: at a provider that
  lets somebody set their own address without proving it, this is an invitation
  to type in the administrator's. An unverified address is never matched, and a
  provider that says nothing about verification counts as unverified.

**`provisionAccounts`** — whether somebody the provider knows and this site does
not may have an account created.

Off by default, and think hard before turning it on. With it on and a *consumer*
identity provider — a plain Google account, say — you have handed a login to
anyone on the internet with an email address. It is safe with a provider that
only knows your own organisation's people, and only then.

A provisioned account gets `provisionRole`, which defaults to `viewer` — the
least a role can be here.

### Which accounts are linked, and why not by email

The link is the provider's `sub` claim, which OpenID Connect requires to be
stable and never reassigned. It is recorded on the account the first time and
matched on afterwards.

Matching on email would be the obvious thing and is wrong twice over:

- **Addresses get reassigned.** `jo@company.com` leaves; six months later a
  different Jo is given the same address, and matching on email would hand the
  second Jo the first Jo's account and everything it could publish.
- **Addresses are not always verified**, as above.

Once an account is linked, the email is not consulted again — so a person
changing their address at the provider does not lose their account, and an
address being reassigned does not give it away.

### Roles

Optional. With `roleClaim` and `roleMap` set, the local role is taken from the
provider on every sign-in; the first entry in `roleMap` that matches wins, so
somebody in two mapped groups gets a stable answer that the site chose.

Without them, the role is whatever an administrator set here, and single sign-on
never touches it. That is the default deliberately: silently rewriting a role on
every login is a surprising thing for a CMS to do to a site that did not ask.

### Local passwords

`allowPasswordLogin: false` closes password sign-in **for accounts linked to the
provider**. That is usually what you want — an account that can still be signed
into with a password is a way around the central disabling that was the point.

It deliberately does **not** close password login for unlinked accounts. A site
using single sign-on still has a local administrator who is not linked, and
locking them out during a provider outage would mean losing the site along with
the provider.

The refusal comes *after* the password is checked, not before, so it cannot be
used to discover which accounts exist and are linked.

## What is checked, and why

Every one of the following has been shipped by somebody as a way to sign in as
anybody, and there is a test for each in
`tests/Unit/Identity/IdTokenVerifierTest.php`:

| Check | What it stops |
|---|---|
| algorithm pinned to RS256 | `alg: none`; HS256 "verified" with the public key as the shared secret |
| key taken only from the published JWKS | a token supplying its own signing key |
| `iss` matched exactly | any provider vouching for anyone |
| `aud` (and `azp` when present) matched | a token minted for a different application at the same provider |
| `exp` / `iat` with a minute's leeway | a token stolen last year |
| `nonce` matched to this sign-in | a token captured from one login replayed into another |
| `state` matched to this sign-in | login CSRF — an attacker's half-finished login handed to a victim, signing them into the attacker's account |
| PKCE `S256` | an authorization code that leaked through a referrer, a proxy log or a shared browser |

The reason a sign-in was refused goes to the **error log**, never to the browser.
The callback is reachable by anyone, so a response distinguishing "no such linked
account" from "provisioning is off" would describe the site's configuration and
its user list to whoever is probing.

## Requirements

- `ext-openssl`, for the signature check. It ships with essentially every PHP
  build.
- An outbound HTTPS path: `curl` or `allow_url_fopen`.

Both discovery and the signing keys are cached on disk under `data/cache/sso`.
When a token names a key that is not in the cache — which is what a key rotation
looks like — the keys are re-fetched **once** and the token re-checked, so a
rotation is picked up immediately and a stream of forged tokens cannot be used to
make this site hammer its own provider.

## When something goes wrong

A failed sign-on returns to the login screen with a short message. The useful
detail is in the PHP error log, prefixed `click-cms sso:`.

The two that come up most:

- **"The ID token came from a different issuer."** Almost always a trailing
  slash: the configured `issuer` is compared exactly with what the provider
  publishes. The configured value has its trailing slash stripped for you; the
  provider's is whatever it says.
- **"You could not be signed in."** With `linking: subject` and
  `provisionAccounts: false` — the defaults — this is what a person who has no
  local account sees. That is the configuration working; somebody has to make
  them an account first.
