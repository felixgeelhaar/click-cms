# Running more than one site

One installation, many sites. The code, the plugins and the themes are shared;
the content, the media, the accounts and the settings are not.

That split is the whole point. An agency running eight client sites wants **one
thing to update** and **eight things that cannot see each other**.

## Nothing changes until you ask for it

An installation with no `config/sites.json` has exactly one site, and its content
stays at `content/` and `data/` where it has always been.

Adding the first *other* site does not move it either — the primary site keeps
the original layout, and only the new ones live under `sites/<id>/`:

```
content/                  ← the primary site
data/
sites/
  acme/
    content/              ← a second site
    data/
  globex/
    content/
    data/
```

That is deliberate. A multi-site feature that begins by relocating an existing
site's content is a feature nobody dares turn on.

## Adding a site

```bash
php bin/click-sites.php --add=acme --host=acme.example.com --title="Acme Ltd"
php bin/click-sites.php --list
php bin/click-sites.php --check
```

Then point the hostname at the same installation. Any vhost or server block that
already serves the CMS will do — nothing about the web server needs to know there
is more than one site, because the routing happens inside PHP.

`--check` is worth running after any edit. It catches the one configuration
mistake with a genuinely bad outcome: **two sites claiming the same hostname**,
where which client's content a visitor sees would depend on declaration order.

## How a request finds its site

By the `Host` header, against the `hosts` each site declares. `*.acme.com`
matches any subdomain; it deliberately does **not** match the bare `acme.com`, so
a wildcard cannot quietly claim a domain another site declared.

A hostname nobody declared falls back to the default site — the one marked
`primary`, or the first declared. That is on purpose: a request on an IP address,
a health check, or a staging alias somebody forgot is ordinary, and answering it
with the default beats a 500 that takes the installation down when DNS changes.

**`X-Forwarded-Host` is ignored.** It is set by whoever is in front, which
unless a proxy strips it includes the client — so honouring it would let a
visitor choose which site's content they are served by sending a header. A
reverse proxy doing its job passes the original `Host` through, so there is
nothing to gain and a site boundary to lose.

## What each site owns

| Per site | Shared |
|---|---|
| pages, collections, media | the CMS code |
| accounts and sessions | plugins (the code) |
| site name and branding | themes (the packages) |
| menus, redirects | software updates |
| version history, audit trail | self-update and the marketplace |
| schedules, webhooks and their queues | |
| which theme is active | |
| section types, *if it declares any* | section types, otherwise |
| `config/core.json`, *if it declares one* | `config/core.json`, otherwise |

### A site can have its own `config/core.json`

Drop one at `sites/<id>/config/core.json` and it is laid over the
installation's. A site overrides only the keys it names and keeps the
installation's answer for everything else — so a site wanting German does not
have to restate the storage backend, the cache settings and the login thresholds
to get it.

```json
{
  "core": {
    "languages": { "default": "de", "available": ["de", "fr"] },
    "storage":   { "backend": "sqlite" },
    "sso":       { "enabled": true, "issuer": "https://id.acme.example" }
  }
}
```

Lists are **replaced, not merged**. `available: ["de"]` means this site
publishes German — not German in addition to whatever the installation listed —
because otherwise a site could only ever widen a set and never narrow one, and
narrowing is the common case.

#### Two settings a site may not override

| Setting | Why |
|---|---|
| `core.updates` | Self-update replaces `src/` in one directory tree that every site runs. Two sites asking for different policies is not a disagreement to resolve; it is a question with one outcome, and whichever site the updater ran as would decide for all of them. |
| `core.marketplace` | It installs plugin code into the shared `plugins/` directory, so "where may code be installed from" cannot be a per-site answer without letting one site choose what another runs. |

A site that sets either is **ignored and logged**, not silently dropped — look
for `click-cms:` in the PHP error log. Configuration somebody wrote that does
nothing is worse than configuration that is refused out loud.

Which **plugins load** is deliberately not on that list. It looks similar and is
not: the code is installed once and shared, and excluding one only decides what
boots for this site. One client having the visual builder and another not is a
normal thing to want.

```json
{ "core": { "plugins": { "exclude": { "ids": ["visual-builder"] } } } }
```

#### A malformed site config does not take the site down

It falls back to the installation's configuration and logs. Taking a client's
site offline over a stray comma — when a perfectly usable configuration is
sitting right there — is the worse of the two failures.

#### Not to be confused with `data/settings.json`

That one is per site too, and holds the name and branding an administrator edits
in the admin panel. Two files with confusingly similar jobs, which is why they
are named separately here: `config/core.json` is deployment configuration, and
`data/settings.json` is something a person edits on screen.

**Accounts are per site.** Somebody who works on three of your client sites has
three accounts. That is the safe default — an account that spanned sites would be
a way to reach a client's content from another client's login — and it is the
arrangement to argue with if it does not suit; see below.

**Section types fall back.** A site with its own `sites/acme/config/sections/`
uses those; one without uses the installation's `config/sections/`. That is what
lets eight client sites share one set of designs while any one of them departs
from it, without copying the other seven.

## From the command line

A command line has no hostname, so every `bin/` tool takes `--site=`:

```bash
php bin/click-schedule.php --site=acme
php bin/click-backup.php --site=acme
php bin/click-seed.php --site=acme
```

Or set `CLICK_CMS_SITE` for a cron entry that always means the same site, which
is one less thing to get wrong on each line.

**A `--site` naming an unknown site stops rather than falling back.** A typo in a
cron entry would otherwise quietly operate on the wrong client's content, which
is not an error anybody notices quickly.

Each site needs its own cron entries for scheduled publishing and webhooks. There
is no all-sites sweep, deliberately: one site's broken webhook endpoint should not
be able to make another site's scheduled publication late.

## How the isolation actually works

Worth knowing, because it explains what the feature can and cannot do.

Each request resolves a site from its hostname and then hands every service a
**different root directory**. Nothing below the kernel knows sites exist — no
service takes a site argument, no query has to remember to scope itself.

The usual way to build this is a site column on every document and a predicate on
every read. That is how multi-site is usually built and how it usually leaks: one
query somewhere forgets the predicate, and a client sees another client's drafts.
Here the isolation is a property of *where the bytes are*, so no query below the
kernel can forget to scope itself — it is handed a scoped root or nothing.

**That is a claim about the kernel, not about everything.** This document first
said a forgotten scope was "not expressible", which was wrong and was proved
wrong within a day. Plugins get their paths from `PluginManager`, which offered
only the *installation* root; five plugin call sites appended `/data/…` to it, and
on any site but the primary that is another site's directory. Two of them read
the session store that way to decide whether the caller was allowed to do
something, found no session where they had looked, and permitted the request.

`PluginManager` now offers `getSiteRoot()` beside `getBasePath()`, and the rule
for anyone writing a plugin is:

- **`getBasePath()`** — code the installation deploys: `plugins/`, `admin-ui/dist`,
  `config/core.json`.
- **`getSiteRoot()`** — anything belonging to a site: its `data/`, its `content/`,
  the session store it reads to identify the caller.

On a single-site installation the two are the same directory, which is exactly
why getting it wrong is invisible until somebody declares a second site.

The cost is the honest one: **sites cannot share content.** No cross-posting an
article to two sites, no shared media library, no single account across sites. For
an agency serving separate clients that is the point rather than a limitation. For
a publisher wanting one newsroom feeding four brands, this is the wrong tool.

## Storage backends

The flat-file backend needs nothing: each site is a directory.

For SQLite, give each site its own file — `sites/acme/data/content.sqlite` — which
is what a per-site `config/core.json` does naturally.

For MySQL or PostgreSQL, give each site its own database or its own credentials.
There is deliberately no shared table with a site column, for the reason above:
the moment two sites share a table, isolation depends on every query remembering
a predicate.

## Backups

Per site, and that is usually what you want — restoring one client should not
touch another. `php bin/click-backup.php --site=acme` backs up that site alone.

There is no whole-installation backup command. The installation is code, and code
belongs in version control rather than in a backup archive.
