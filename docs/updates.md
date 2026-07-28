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
  "allowPreRelease": false
}
```

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

Updates install from the command line, via cron:

```cron
17 3 * * *  cd /var/www/html && php bin/click-update.php >> data/updates/cron.log 2>&1
```

```bash
php bin/click-update.php              # check; install what the policy allows
php bin/click-update.php --dry-run    # report what would happen, change nothing
php bin/click-update.php --force      # ignore the once-a-day interval
```

The interval is enforced inside the CMS, so a more frequent cron entry is
harmless. Overlapping runs take an exclusive lock; the second one ends rather
than installing in parallel.

**There is deliberately no "update during a web request" mode.** Installing
replaces `src/` underneath the running process, and mid-request that can leave a
half-served page unable to autoload the class it needs next, with an opcode
cache holding a mix of two releases. A CLI run has no page in flight.

An administrator can also install an offered update from **Manage → Updates**,
which does not wait for cron.

### If an update goes wrong

Every install moves the replaced directories aside first. The path is printed,
logged to `data/updates/history.json`, and restorable:

```php
(new UpdateInstaller('/var/www/html'))->restore('/var/www/html/data/updates/backup-…');
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
4. The published feed is **fetched back and compared** against the one the run
   built. The workflow fails if the site is not serving it.

### Why step 4 exists

`actions/deploy-pages` reporting success means GitHub accepted the deployment.
It does not mean the site is serving what was deployed, and on **2026-07-27**
those two came apart:

- The v1.7.0 release built a correct feed listing 15 releases, and both jobs
  went green.
- The site went on serving the 14-release feed built two hours earlier, for at
  least fifteen minutes.
- The release was published, its packages were attached and downloadable, and
  **no installation would ever have been offered it** — the feed is the only way
  a site learns a release exists.
- A `workflow_dispatch` run of the same file, with an identical payload and the
  same `pages_build_version`, published it in seconds.

There is evidence this was not a one-off: v1.6.0 was released at 07:13 that day
and only entered the feed at 17:08, when an unrelated merge happened to trigger
a rebuild. Between those times it was published and undiscoverable. The pattern
looks like the feed trailing until some *later* commit forces a republish, which
would make every release invisible until unrelated work lands.

**The GitHub-side cause is not established.** Several plausible explanations were
tested and disproved — the deployment payloads are byte-identical between the
run that failed and the one that worked, the concurrency group is correct, and
the commit SHA is the same in both. The check does not pretend to fix that.

What it fixes is the defect that belongs to this repository: the pipeline
asserted success without checking the outcome, so the failure was invisible
until somebody fetched the feed by hand. That is the same rule the signing check
already applies one step earlier, and it simply had not been extended past the
deploy.

It is a check and **not a retry**, deliberately. A retry that usually works
would hide an infrastructure problem behind an occasional slow release. Failing
loudly also makes the behaviour reproducible, which is what any real diagnosis
of the GitHub-side cause will need.

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

Then run **Publish site** once by hand to put the feed and the documentation up.

To sign by hand:

```bash
php scripts/updates/build-feed.php releases.json private-key.pem _site \
  --sequence=$(date +%s) --ttl-days=30
```

The tool refuses to sign a feed with a malformed entry, so a broken release is
caught before it reaches anybody.

### Known issue: a release may not reach the feed on the first try

**Check after every release**, until [issue #26][issue-26] is closed.

[issue-26]: https://github.com/felixgeelhaar/click-cms/issues/26

Twice now, the release workflow has deployed a correct feed, reported success on
every job, and the site has gone on serving the previous one. The release is
published and its packages download fine — but the feed is how an installation
learns a release exists, so until the feed lists it, **nobody is offered it**.
v1.6.0 was undiscoverable for ten hours that way.

Step 4 above catches it now: the workflow fetches the feed back and fails if the
site is not serving what it built. When that happens the run goes red with the
expected digest, and the fix is one command:

```bash
gh workflow run pages.yml --ref main
```

That has republished it in seconds every time.

**Do not read a passing re-run as the problem being solved.** The deployment
reported success and did not take effect; re-running only papers over it. The
cause is not established — six explanations have been ruled out by experiment, and
the one that remains, that it happens only on `release`-triggered deploys, cannot
be tested without publishing a release. Every real release is therefore a data
point, which is why this section asks you to check.

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
