# Updates

click-cms can update itself. This describes the feed it reads, how a release is
published and signed, and what an operator has to keep true for it to keep
working.

Updating is remote code execution by design, so everything below is written to
fail closed: anything that cannot be verified produces "no update available"
rather than a best guess.

## For a site operator

Configure the channel in `config/core.json`:

```json
"updates": {
  "policy": "security",
  "feedUrl": "https://felixgeelhaar.github.io/click-cms/feed.json",
  "publicKey": "-----BEGIN PUBLIC KEY-----\n…\n-----END PUBLIC KEY-----",
  "allowPreRelease": false,
  "publicRoot": "/srv/www/example/public"
}
```

`publicRoot` is optional. Set it to the absolute path of the existing public
directory when the application root (containing `src/`, `vendor/` and
`config/`) is separate from the web server's document root. The updater replaces
its software entries, keeps its `.htaccess`, and stores/restores its previous contents
in the ordinary update backup. The configured directory must exist and must not
itself be a symlink; an invalid setting makes install and restore stop before
changing files. Leave it unset for the standard `application-root/public`
layout. The value is installation-wide and must be present for both CLI and
admin updates.

When converting an already-running split-root installation, install this
updater version and configure `publicRoot` through a deployment/manual step
before relying on automatic updates. An older updater cannot understand the
setting and may continue replacing the wrong `public/` directory.

`feedUrl` and `publicKey` also read from `CLICK_CMS_UPDATE_FEED_URL` and
`CLICK_CMS_UPDATE_PUBLIC_KEY`, so a key need not sit in a committed file.

### The policy

What may install itself with nobody present:

| Policy | Behaviour |
|---|---|
| `manual` | Never checks. For an install a deploy pipeline owns. |
| `notify` | Checks and reports; installs nothing. |
| **`security`** (default) | **Installs security releases automatically.** Everything else waits for an administrator. |
| `minor` | Installs patch and minor releases; a major waits. |
| `all` | Installs anything, including a major. |

Two rules hold under every policy: **a major is never automatic** below `all`,
and **a pre-release is never automatic at all**.

The default is `security` for the same reason WordPress landed there: a site
nobody is watching should still repair itself against a known, published
vulnerability, while anything that could change behaviour waits for a human.

### Running it

Ordinary GET/HEAD requests to Click trigger a due update check, once per day.
This includes REST requests from a separate SPA. No cron, shell access, PHP
subprocesses or loopback HTTP are required. `security`, `minor` and `all` install
only releases their policy permits; `notify` only checks and `manual` does no
background work. Managed deployments (`CLICK_DEPLOYMENT_MANAGED=1`) disable it.
POST requests and uploads never trigger a check. A site receiving no requests
cannot check until its next request; fully cached traffic that never reaches PHP
also cannot trigger it.

The front controller takes a shared installation lock before autoloading.
Downloads and verification happen before exclusive maintenance. The installer
acquires the exclusive lock only when no other PHP request is using the code;
if busy, the web scheduler retries in five minutes. During replacement, requests
receive HTTP 503 with `Retry-After: 5` and `Cache-Control: no-store`. The updating
process exits without booting the application with its old loaded classes. The
next request starts with the new release. The due request may take longer while
checking/downloading; this is an in-process worker, not a detached background job.

PHP needs writable installation and backup directories, OpenSSL/Zip support and
outbound access to the configured feed and packages. OPcache must validate
changed files or permit a reset from the web PHP pool. Host execution limits
still apply: preparation may fail on hosts with very low time/memory limits.
Failures are shown in update status/history or the PHP error log; they never
justify weakening signatures, checksum verification or policy.

An optional CLI scheduler remains available on hosts that offer cron:

```bash
php bin/click-update.php              # check; install what the policy allows
php bin/click-update.php --dry-run    # report what would happen, change nothing
php bin/click-update.php --force      # ignore the once-a-day interval
```

An administrator can also install an offered update from **Manage → Updates**.
All installers use the same request barrier. CLI runs cannot reset a separate
web pool's OPcache; those hosts must enable timestamp validation or provide their
own cache lifecycle.

### If an update goes wrong

Before mutation, the updater writes a durable `data/updates/swap-journal.json`
and preserves its dependency-free recovery code outside the replaced software.
If the worker is killed, the next front-controller request restores the previous
software before Composer loads. An invalid journal or failed recovery keeps the
site in maintenance and retains the evidence for an operator. The public root
and front controller remain reachable during replacement. Keep `data/updates`
private and include it in operational backups. Transaction backups must live in
`data/updates`; custom external installer backup roots are refused so early
recovery can validate every recovery path.


Every install preserves the previous software in a backup. The path is printed,
logged to `data/updates/history.json`, and restorable:

```php
$root = '/var/www/html';
$config = CoreConfig::load($root . '/config/core.json');
(new UpdateInstaller($root, null, $config->updatePublicRoot()))
    ->restore($root . '/data/updates/backup-…');
```

## The feed format

A single signed JSON document:

```json
{
  "sequence": 1730000000,
  "expires": "2026-08-24T06:27:30+00:00",
  "releases": [
    {
      "version": "1.2.0",
      "packageUrl": "https://github.com/…/click-cms-1.2.0.zip",
      "sha256": "9f2c…",
      "security": false,
      "notes": "Adds art-directed media crops.",
      "requiresPhp": "8.1"
    }
  ]
}
```

Every field is mandatory except `notes` and `requiresPhp`. A release whose
`version` is unparseable, whose `sha256` is not 64 hex characters, or whose
`packageUrl` is not HTTPS is **dropped** — one bad entry costs that release, not
the feed.

The signature is detached, base64, at **`<feedUrl>.sig`**, over the exact bytes
of `feed.json` — RSA with SHA-256. It is verified *before* the document is
parsed, so what is checked is the document received rather than our reading of
it. A detached signature is the same arrangement Apache and the Linux
distributions use; a signature cannot live inside the document it covers.

### Why `sequence` and `expires` exist

A signature says who wrote a document. It says nothing about whether that
document is the current one — and a correctly signed document stays correctly
signed forever. An attacker who can decide *which* signed document a site
receives therefore still has two moves. Both are named in [The Update
Framework](https://theupdateframework.io/)'s threat model, and both are closed:

- **Freeze.** Keep serving a genuinely signed older feed. The site never hears
  about the security release published since and reports itself up to date — a
  comfortable way to stay vulnerable indefinitely. `expires` bounds how long a
  signed feed is believable.

- **Rollback.** Replay an *older* signed feed to pin a site to a release whose
  vulnerability is public. `sequence` must increase; the highest value ever
  believed is remembered in `data/updates/feed-state.json`, and a feed that goes
  backwards is refused despite a perfect signature.

The floor rises only on feeds that were actually accepted, so a forged feed
claiming a huge `sequence` cannot lock out the legitimate feed behind it.

## Publishing a release

Automated in `.github/workflows/release.yml`, triggered by publishing a GitHub
Release:

1. Tag `v1.2.0` and publish the release. **Put `[security]` in the body if it
   fixes a vulnerability** — that flag is what allows sites on the default
   policy to install it unattended.
2. The workflow installs production dependencies, builds the admin UI, packs
   `src public plugins vendor bin themes` into `click-cms-1.2.0.zip`, and
   attaches it to the release. `content/`, `data/` and `config/` are never
   shipped — they are the site's, and the installer refuses to write them.
3. The feed is rebuilt from the published releases, each package's `sha256` read
   from the artefact itself, signed with `UPDATE_PRIVATE_KEY`, and deployed to
   GitHub Pages.
4. The feed is published by **dispatching** `pages.yml` — not by deploying from
   inside the release run, which does not take effect (see the known issue
   below). The release job waits on that run and fails with it.
5. The published feed is **fetched back and compared** against the one the run
   built. The workflow fails if the site is not serving it.

### Why step 5 exists

`actions/deploy-pages` reporting success means GitHub accepted the deployment.
It does not mean the site is serving what was deployed, and those two came apart
three times — see [the known issue](#known-issue-a-pages-deploy-made-from-a-release-run-is-never-served)
below for the evidence and the workaround.

Step 4 avoids the condition that causes it. This step is what catches it if the
avoidance ever stops working, and it is the reason the problem was found at all:
until it existed, the pipeline asserted success without checking the outcome, so
a release could be published, packaged, downloadable and offered to nobody, with
every tick green.

That is the same rule the signing check one step earlier already applies —
"reporting green without signing would be the silent failure this project keeps
having to remove" — which simply had not been extended past the deploy.

It is a check and **not a retry**, deliberately. A retry that usually works would
hide an infrastructure problem behind an occasionally slow release.

### One-time setup, for whoever runs the project

Two things, once, before any of the above works:

1. **Generate the signing key and install both halves** — see [Keys](#keys)
   below. Keep the private half somewhere it will survive: losing it means every
   installed site refuses every future release until it is handed a new public
   key by hand.

2. **Enable GitHub Pages with source set to "GitHub Actions"** — not "Deploy
   from a branch". The workflow deploys an artefact it builds; with the branch
   source selected there is nothing for it to deploy to, and every run fails at
   the last step.

3. **Add a `PAGES_DISPATCH_TOKEN` secret**, so a release can publish its own
   feed. Without it a release still succeeds and its packages still download,
   but the feed is not updated and no installation is offered the release — see
   [the known issue](#known-issue-a-pages-deploy-made-from-a-release-run-is-never-served)
   below for why.

   A fine-grained personal access token, scoped as narrowly as this can be:

   - **Repository access:** only this repository.
   - **Permissions:** `Actions: read and write` — enough to start a workflow run
     and read its status. Nothing else.
   - **Expiry:** set one. A token that never expires is a token nobody ever
     revisits. Ninety days is reasonable; the release will fail loudly when it
     lapses, which is the reminder.

   Add it under **Settings → Secrets and variables → Actions** as
   `PAGES_DISPATCH_TOKEN`.

   **Why a token at all.** `GITHUB_TOKEN` deliberately cannot start further
   workflow runs — that restriction exists to stop workflows triggering each
   other in loops. Publishing the feed needs a *dispatched* run, so it needs a
   credential that is allowed to dispatch.

   **What it costs.** A standing credential in the repository that can start
   workflow runs. Anyone who can read repository secrets, or alter a workflow
   that uses them, could run arbitrary workflow code with it. That is a real
   increase in attack surface, and the narrow scope above is what keeps it
   small: it cannot read code, write contents, publish releases or touch other
   repositories.

   A GitHub App installation token is stronger — short-lived, revocable, not
   tied to a person who might leave — and is the better choice for anything with
   more than one maintainer.

Then run **Publish site** once by hand to put the feed and the documentation up.

To sign by hand:

```bash
php scripts/updates/build-feed.php releases.json private-key.pem _site \
  --sequence=$(date +%s) --ttl-days=30
```

The tool refuses to sign a feed with a malformed entry, so a broken release is
caught before it reaches anybody.

### Known issue: a Pages deploy made from a release run is never served

Click resolves this on its supported publication path by requiring an independent dispatch and removing the release-context fallback. The upstream Pages defect is not claimed fixed. [Issue #26][issue-26] has the full investigation; [site publication](site-publication.md) describes the current safeguards.

[issue-26]: https://github.com/felixgeelhaar/click-cms/issues/26

**The behaviour.** A GitHub Pages deployment created under a `release` event is
accepted, reports success, and is then not served. The release is published and
its packages download fine — but the feed is how an installation learns a release
exists, so until the feed lists it, **nobody is offered it**. v1.6.0 was
undiscoverable for ten hours before anyone noticed.

**The evidence.** Eleven deploys, one clean split:

| Trigger | Landed | Failed |
|---|---|---|
| `push` | 3 | 0 |
| `workflow_dispatch` | 3 | 0 |
| `workflow_call` from a dispatch | 1 | 0 |
| the same, behind a `needs` | 1 | 0 |
| **`release`** | **0** | **3** |

Every other candidate — the call mechanism, the job structure, two deploys close
together, the commit SHA, workflow concurrency, CDN caching, a malformed build —
was ruled out by an experiment rather than by argument.

**The workaround.** `release.yml` no longer deploys Pages itself. It dispatches
`pages.yml` and waits for that run, failing with it. Dispatched runs land.

**Two things still protect you**, because a workaround is not a cure:

- `pages.yml` checks every generated file after deploying, including documentation,
  the feed and its detached signature. Stale or missing files fail publication.
- The release job waits on the dispatched run rather than firing and forgetting,
  so a release cannot go green over a feed nobody is serving.

If a release ever does fail at this step, republish by hand:

```bash
gh workflow run pages.yml --ref main
```

**Do not read a passing re-run as the problem being solved.** The deployment
reported success and did not take effect; re-running only moves past it.

### The obligation this creates

**The feed must be re-signed before it expires, even when there is no new
release.** The expiry that defeats a freeze attack also means the feed goes
stale on its own. A project that publishes twice a year and signs a 30-day feed
would have every installation reporting a feed error for ten months — and
learning to ignore it.

`.github/workflows/pages.yml` re-signs weekly against a 30-day TTL, which leaves
three missed runs of margin. This mirrors TUF's *timestamp* role: something
short-lived vouches that the long-lived thing is still current. A scheduled run
that cannot sign — because no key is configured — fails loudly rather than
reporting green every Monday while the feed quietly expires underneath.

That workflow is also the only thing that publishes to GitHub Pages, and it has
to be. `deploy-pages` replaces the *entire* site, so a second workflow adding a
documentation site there would not have added pages — it would have deleted
`feed.json`, and every installation would have begun reporting a feed error with
nothing in either workflow looking wrong. Whatever deploys must assemble the
whole site, so exactly one thing deploys.

`sequence` comes from `date +%s` rather than a workflow run number. A run number
is per-workflow, so a release at run 3 publishing after a scheduled re-sign at
run 20 would send the feed backwards and every site would refuse it — turning
the rollback defence into a self-inflicted outage.

### Keys

- Generate: `openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out private-key.pem`
- Public half: `openssl rsa -in private-key.pem -pubout`
- The private key goes in the `UPDATE_PRIVATE_KEY` repository secret and nowhere
  else. Anyone holding it can make every installation run their code.
- The public half goes in each site's `core.updates.publicKey`.

### Rotating the signing key

A feed may announce the keys to trust from now on, in a `keys` array. Because
that announcement is inside the signed bytes, it is believed only when the feed
carrying it was signed by a key that is *already* trusted — so a key cannot
announce itself.

```json
{
  "sequence": 1730000000,
  "expires": "…",
  "keys": ["-----BEGIN PUBLIC KEY-----\n…new…\n-----END PUBLIC KEY-----"],
  "releases": [ … ]
}
```

To rotate:

1. Publish a feed **signed with the current key** whose `keys` lists both the
   current and the new public key. Installations adopt the pair.
2. Once installations have fetched at least once (a week, given the schedule),
   publish a feed signed with the **new** key whose `keys` lists only the new
   key. The old key is then retired everywhere.

Announced keys are remembered in `data/updates/feed-state.json` and replace the
previous announcement rather than accumulating, so a key really can be retired —
a set that only grew would keep a compromised key trusted forever.

**The configured key is never revocable this way.** `core.updates.publicKey` (or
the `publicKeys` list) is trusted unconditionally, whatever a feed says, so the
anchor of trust stays the thing the operator typed rather than something the
network can talk them out of. The intended arrangement is TUF's: a rarely used
**offline** key in the configuration, and an **online** signing key that it
rotates. If the online key is compromised, the offline key signs a feed that
retires it; if the offline key is compromised, every site must be edited by hand
— which is why it should not be on the build machine.

Configure several anchors when you want an overlap window:

```json
"updates": { "publicKeys": ["-----BEGIN PUBLIC KEY-----\n…a…", "-----BEGIN PUBLIC KEY-----\n…b…"] }
```

## What is not implemented

Stated plainly, because a half-known threat model is worse than a known one:

- **No threshold signing.** One valid signature is enough; TUF would require *m
  of n*, so a single stolen key is not sufficient on its own.
- **No transparency log.** A maliciously signed release would not be publicly
  detectable the way Sigstore's log makes it.
- **No mirror or mix-and-match protection beyond the above.** The feed is a
  single document from a single origin.
- **No staged rollout.** Every site on `security` takes a security release as
  soon as it sees it.

## Split-root installations and existing sites

When `public/` is served from a separate directory, set
`core.updates.publicRoot` to that directory's absolute filesystem path. The
scheduled CLI updater and the administrator's update action use the same value.
An SFTP chroot path or URL is not a filesystem path. A missing, relative or unsafe
configured directory stops installation before code changes. The directory and
the application must be on a filesystem where the directory moves can succeed;
a failed move triggers rollback rather than a recursive fallback copy.

```json
{
  "core": {
    "updates": {
      "policy": "minor",
      "allowPreRelease": false,
      "publicRoot": "/absolute/path/to/web/2026/cms2"
    }
  }
}
```

Merge these settings into the existing configuration; retain the configured
feed URL and signing key. `minor` opts into unattended stable patch and minor
updates. It does not authorize majors or prereleases. Ordinary CMS traffic
provides the scheduler, including API traffic from TurboScience. When a newer
major or beta is available, the updater still installs the newest stable release
permitted by the policy instead of letting that newer offer block compatible
updates.

The external public directory participates in code backup, failed-install
rollback and explicit restore. Its existing `.htaccess` survives; the release's
version is retained as `.htaccess.dist` for comparison. Failure to preserve those
site-owned rules fails the update. Content, configuration and private data are
not release files and remain outside the replacement set. Software-owned starter
presets ship inside `src/Resources`, so an existing site receives them without
any write to its configuration directory.

### First transition from an older updater

An updater already installed on a site runs its old implementation. Adding
`publicRoot` does not retrofit external-directory support into that old code.
For a detached public directory, use a verified release's corrected installer
for a one-time controlled transition, preserving both application and public
backups. Rehearse it on an isolated copy first. After the corrected updater and
site-local public-root setting are present, subsequent compatible stable
releases use the unattended web path, without server cron or shell access.

Do not claim that CLI execution alone drains web traffic. PHP requests and
opcode caches can still overlap a directory swap. Use the host's maintenance
window/traffic controls and PHP cache restart procedure when required; the
installer is a rollback-capable directory replacement, not a zero-downtime
transaction across running requests. A release requiring data migration or a
PHP restart needs an explicitly managed transition.

### Isolated TurboScience rehearsal

With PHP/Zip/OpenSSL, Python 3.10+, Node 24 LTS and the admin development
dependencies installed, run:

```sh
php scripts/rehearse-turboscience-upgrade.php /path/to/click-cms /path/to/turboscience-website
```

The harness uses local `v1.4.0` and `v1.8.0` Git tags, the site's checked-in
configuration and Bludit export, and its migration functions to reconstruct an
isolated Click fixture. It does not copy credentials or access the live site.
Local candidate version numbers and an ephemeral RSA signing key are rehearsal
fixtures, not published releases. Loopback servers exercise the signed feed and
prefixed API delivery. The SPA probe executes the site's actual page and chrome
clients with a DOM implementation; it does not replace browser visual testing
or verification of the production host's Apache, scheduler or opcode cache.
