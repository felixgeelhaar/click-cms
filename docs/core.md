# What belongs in core

Click CMS is a small core plus a plugin system. This document defines where the
line sits, so the question does not have to be re-argued for every feature.

## The test

Something belongs in core when **the CMS is incoherent without it**.

Three questions decide it:

1. **Can the application boot without this?** If not, it is core. Storage is the
   clearest case: there is nothing to run without a place to put content.
2. **Can an editor do their job without this?** If not, it is core. An editor
   who cannot choose a section design or place an image cannot edit.
3. **Would a reasonable site turn this off?** If yes, it is a plugin. A site
   that renders its own pages genuinely has no use for a delivery API.

A useful tie-breaker: if the admin UI breaks when a thing is removed, that thing
is not optional, whatever the directory it currently lives in.

Two failure modes this is meant to prevent:

- **Fake optionality.** Shipping something as a plugin that nothing works
  without. The system claims a flexibility it does not have, and the first
  person to disable it discovers the lie.
- **Core creep.** Absorbing features because it is convenient, until the core is
  the whole product and the plugin system is decoration.

## Qualities core must hold

These are properties, not features. A change that breaks one of them is a
regression even if every test passes.

**Boots with zero plugins installed.** The sharpest test of whether the line is
drawn correctly. If deleting `plugins/` stops the CMS working, something in
there was not really a plugin.

**No runtime dependencies.** `composer.json` requires PHP and nothing else.
Every dependency is a supply-chain risk and an upgrade obligation, and this is
software people install on hosting they do not fully control.

**No database required.** Flat files are the default. A database is an option
for sites that outgrow them, never a prerequisite.

**Runs on ordinary shared hosting.** PHP and Apache. No long-running process, no
Node, no build step at deploy time. This is the constraint that decides which
designs are even available, and it is why GD is used rather than Imagick and why
storage is a file per document.

**Core owns structure, sites own content shape.** Core provides field types; a
site declares its own section types. Core ships no `hero` and no `services` — a
CMS that knows what a hero is has become one company's website generator.

**Security is not uninstallable.** Authentication, upload policy and path safety
live in core precisely so no configuration can switch them off.

**The domain has no I/O.** `src/Domain` reads no files, opens no sockets and
knows nothing about HTTP. That is what makes it testable without fixtures, and
it is why the storage port lives in the domain while its implementations do not.

**Failure is visible.** Silent degradation is the recurring bug in this
codebase: plugins that never loaded, a test suite that ran nothing, a 404 served
as 200. Core should refuse or report, never quietly do less than asked.

## Core capabilities

Twelve, and nothing else. The count grew once: languages, history and preview
were argued in as core on the grounds that each is structural — none can be
supplied by something layered on top, and each becomes dramatically more
expensive the longer it waits.

| # | Capability | Why it cannot be a plugin |
|---|---|---|
| 1 | **Content** — the aggregate, its key, and the service that reads and writes it | There is no CMS without content |
| 2 | **Storage** — the port, plus flat-file and SQLite implementations | The application cannot boot without one |
| 3 | **Schema** — field types, section types, validation | Content with no defined shape cannot be validated or rendered |
| 4 | **Media** — upload policy, storage, responsive variants, and telling the editor when a file cannot fill them | Content references media; the reference must always resolve, and silently producing a soft image is the kind of quiet failure core exists to prevent |
| 5 | **Identity** — accounts, authentication, sessions, roles | Security must not be removable |
| 6 | **HTTP kernel** — request to response, route matching, security headers | It is the entry point |
| 7 | **Management API** — the endpoints the admin UI depends on | The admin UI is how the product is used |
| 8 | **Extension points** — plugin discovery, installation and lifecycle, events, hooks | The plugin system cannot itself be a plugin, and a plugin system with no way to install plugins is developer-only |
| 9 | **Configuration and health** — loading settings, reporting readiness | Needed to run and to operate |
| 10 | **Languages** — a locale dimension on content, from the key through storage, API and rendering | Cannot be added later without rewriting how every document is addressed. A bilingual site is not an edge case, and retrofitting this is the most expensive change available in this project |
| 11 | **History** — a previous version of a document, and a way back to it | An editor who cannot undo is one mistake away from unrecoverable loss. It is a property of how documents are stored, so nothing above storage can supply it |
| 12 | **Preview** — seeing an unpublished change rendered before it is public | An editor who cannot see their work before publishing is guessing. Rendering already exists; without this it is only reachable by publishing |

The last three were argued in as core because of where they have to live, not
because they existed. All three are now written, along with media quality
reporting and a second storage backend that can finally be selected. What is
still missing is the admin UI for them — see the order of work below.

### Media quality is part of media

Core generates a variant ladder — 640, 1024, 1536 and 2048 pixels wide — and
never scales an image up, because an upscaled image is a larger file that looks
no better. The consequence is that an upload smaller than a rung simply produces
fewer variants.

That used to happen in silence. A real upload during testing was 1022 pixels
wide; it produced one variant, the library displayed `sm`, and nothing said why
or what it would cost. On a high-density display that image is stretched by the
browser and looks soft — and the person who would notice is a visitor, not the
person who uploaded it.

That is precisely the failure mode listed under **Failure is visible**, so the
remedy lives in core alongside the ladder that causes it:

- **At upload**, it says what could be produced and what could not, in the
  editor's terms rather than in pixels alone — that this picture will look soft
  on modern phones and laptops, and roughly what size to supply instead.
- **In the schema**, an `image` field may declare the width it is displayed at.
  A card in a four-column grid and a full-bleed header have very different
  needs, and only the section type knows which it is. With that declared, the
  warning becomes specific: the same 1022-pixel file is fine in the card and
  wrong in the header.
- **The upload is never refused.** A small image is often the only one that
  exists, and a logo that must ship today beats a warning that blocks it. Core
  reports; the editor decides.

Deliberately out of scope: judging compression artefacts, sharpness or subject
matter. Those need heuristics that are wrong often enough to erode trust in the
warnings that are right.

### Draft and publish

Saving a page does not publish it. Every save records a new version; the newest
version is the working copy, and `content/{type}/{locale}/{slug}.json` holds
published documents only. Presence there **is** publication — that is what the
public read path consults, and keeping that path a single file read is the
constraint shared hosting imposes.

The document therefore carries no `status` field any more. It used to, beside a
read path that decided visibility by whether the file existed at all, which is
two answers to one question: a page could claim to be published and 404 to every
visitor, with nothing able to say which was right. What a UI needs is derived
instead — `published`, `hasUnpublishedChanges`, `neverPublished` — from facts
that cannot contradict each other.

Four consequences worth stating, because each was a choice:

- **Publication is per language.** `page:de:home` and `page:en:home` are already
  two documents, so publishing one is publishing that document. There is no
  cross-language grouping, and adding one would mean a German translation going
  live the moment its English original was approved.
- **Only some types are publishable**, and the list is stated in
  `Domain\Publishing\Publishable` rather than inferred from a payload. Pages
  are; users and media are not. Nobody drafts a login, and an account that
  existed only as a version would make signing in depend on somebody having
  pressed Publish.
- **Restoring restores the working copy**, not the live page. Undo would
  otherwise be the one editing action that reaches the public with no review
  step.
- **Unpublishing records no version.** The newest version is the working copy,
  so appending the state being taken down would rewind whatever replacement was
  being written for it. What was live is already retained by the publish that
  put it there.

`content.publish` and `content.unpublish` are separate capabilities. An editor
holds both, an author neither — which is what the role comments have claimed
since they were written, and what nothing enforced while saving was publishing.

### More than one site

One installation, many sites: code, plugins and themes shared; content, media,
accounts and settings not.

**It is additive, which is the whole reason it could be built at all.** An
installation with no `config/sites.json` has one site whose content is at
`content/` and `data/` — byte for byte where it has always been — and adding a
second site does not move the first. Nothing about the stored shape changes,
which is what keeps this inside the v1.x line rather than making it the v2
concern a site dimension on `ContentKey` would have been.

**The implementation is one seam.** Each request resolves a site from its
hostname and hands every service a different root directory. Nothing below the
kernel knows sites exist: no service takes a site argument, no query has to
remember to scope itself.

That is the decision worth defending. The usual implementation is a site column
on every document and a predicate on every read — which is how multi-site is
usually built and how it usually leaks, because one query somewhere forgets the
predicate and a client sees another client's drafts. Here the isolation is a
property of where the bytes are: nothing below the kernel is *given* an
unscoped root, so nothing below the kernel can forget to scope. It is the same
reasoning that made publication presence-in-`content/` rather than a status
field — a rule enforced by structure cannot be forgotten by a handler.

**With one correction, made the day after this was written.** The claim here was
that a forgotten scope is "not expressible", full stop. It was expressible
through the plugin API: `PluginManager` handed out only the installation root,
and five plugin call sites appended `/data/…` to it. Two read the session store
that way to authorise a request, found nothing where they looked, and allowed it.

The kernel's guarantee held — every service it constructs is handed a scoped
root. What did not hold was the boundary at the plugin surface, which is now
`getSiteRoot()` for anything belonging to a site and `getBasePath()` for code the
installation deploys. The lesson worth keeping is narrower and more useful than
the original claim: **structure prevents a mistake only where the structure
reaches**, and an API that hands out a wider scope than a caller needs is a hole
in it.

Three consequences, each a real cost accepted knowingly:

- **Sites cannot share content.** No cross-posting, no shared media library, no
  account spanning sites. For an agency serving separate clients that is the
  point; for a publisher with one newsroom feeding four brands this is the wrong
  tool, and saying so is better than a half-isolation nobody can reason about.
- **Section types fall back but do not merge.** A site's own
  `config/sections/` replaces the installation's rather than adding to it, so
  what a site renders is answerable by looking in one place.
- **`X-Forwarded-Host` is ignored.** It is set by whoever is in front, which
  unless a proxy strips it includes the client — so honouring it would let a
  visitor pick which site's content they are served by sending a header.

See [`docs/multi-site.md`](multi-site.md).

### Single sign-on

OpenID Connect, core for the same reason two-step sign-in is. Off unless a site
configures it, and `enabled` means "has everything it needs" rather than "a flag
is set" — a half-configured provider produces no button rather than one that
leads to a broken redirect.

**SAML is declined outright**, and that is a design decision rather than a gap.
Verifying a SAML assertion means XML digital signatures, which means XML
canonicalisation — a well-known source of signature-bypass bugs, not something to
hand-roll, and a library for it would be the runtime dependency this project does
not take. The TOTP case above was defensible because its failure mode is loud;
this one's is silent, which is exactly the difference.

Three decisions worth stating:

- **The link is the provider's `sub`, never the email.** Addresses get
  reassigned — `jo@company.com` leaves and a different Jo is given it six months
  later — and matching on email would hand the second Jo the first Jo's account.
  Email is used only to *find* an account the first time, only when the provider
  has verified it, and only when the site opted in.
- **Nothing the callback supplies is trusted alone.** State, nonce and the PKCE
  verifier are generated server-side and held in a pending session; the code, the
  state and the ID token are each checked against something this server kept.
- **Closing local passwords applies only to linked accounts.** A site using SSO
  still has a local administrator who is not linked, and closing password login
  for them would mean losing the site along with the provider during an outage.

See [`docs/sso.md`](sso.md) for the configuration and the full list of what the
ID token is checked for.

### Two-step sign-in

Core, on the "security is not uninstallable" line. The plugin hook
{@see AuthGate} was designed with a second factor in mind and could carry one —
but a second factor a site can uninstall, or that stops working when a plugin
fails to load, is not a second factor. The hook remains the right home for
somebody else's *different* second factor; this is the one that ships.

TOTP (RFC 6238), hand-rolled, which is normally the wrong instinct and is
defensible here for one reason: **the failure mode is loud**. A wrong
implementation does not produce subtly weak codes, it produces codes that no
authenticator app agrees with, which the first enrolment reveals — and the RFC
publishes test vectors, which the suite checks against. The alternative was
requiring a Composer package in order to turn on two-factor, which breaks the
rule the whole project is built on.

Four decisions worth stating:

- **Enrolled is not confirmed.** A secret exists from the moment the key is
  shown and protects nothing until the person has produced a code from it.
  Treating "has a secret" as "has two-factor" locks people out at the exact
  moment they have not finished setting it up.
- **The state between the two login steps authenticates nothing.** The pending
  session holds a username and no `user` key at all, so `SessionStore::user()`
  goes on answering null and every guard treats the caller as anonymous.
  Whoever holds the password alone can reach exactly one endpoint. If that were
  not true the second factor would be decoration.
- **Wrong codes count against the password lockout.** Six digits with unlimited
  guesses is a weaker secret than the password it is strengthening.
- **Recovery codes are hashed with SHA-256, not `password_hash`.** They are 100
  bits of `random_bytes`, not something a person chose; slow hashing defends
  low-entropy secrets and buys nothing here. The account password next door is
  user-chosen and does use `password_hash` — the difference is the point.

The enrolment lives in the account's own content document under `twoFactor`,
which is the sharpest illustration of why `ContentGate` withholds document
bodies from plugins: without that rule, every plugin would be handed this block
on every save.

### Scheduled publishing

A page can be given a time to go live and a time to come down. It is core rather
than a plugin for the same reason draft-and-publish is: it decides what the
public can see, and a site able to uninstall it would have schedules on disk that
nothing performs.

**A schedule is not a status field, and does not reopen that argument.** The
whole point of the section above is that publication is presence in `content/`
and never a claim stored on the document. A schedule does not claim anything
about the present — `publishAt` says *somebody intends this to be published
then*, and intent and state may legitimately disagree. It is stored beside the
document, in `data/schedule/`, never inside it. (There is a mundane second
reason: the schema validator discards every field a section type does not
declare, so a `publishAt` written into `data` would vanish on the next save.)

**It is a sweep, not a trigger.** `bin/click-schedule.php`, from cron. Checking
the schedule on each page request was considered and rejected twice over: a page
nobody visits would never publish — and the pages most likely to be scheduled are
exactly the ones with no traffic until they are live — and it would put a write
on the public read path, which is one file read on shared hosting by design.

    0,5,10,15,20,25,30,35,40,45,50,55 * * * *  cd /var/www/html && php bin/click-schedule.php

A schedule is only as precise as the interval between sweeps. Five minutes is a
reasonable default; one minute is not unreasonable.

**A site with no cron entry gets a feature that visibly does nothing** rather
than one that half works. The admin panel says so in as many words once a
scheduled time has passed with the schedule still standing, because the
alternative is an editor waiting indefinitely for a publication no process on the
machine is going to perform. That is the `Failure is visible` quality applied to
an absent operator rather than absent code.

Four decisions worth stating:

- **It computes a state, not a queue.** Cron does not always run. A window that
  opened at 09:00 and closed at 11:00, first swept at 11:30, leaves the page
  down — it does not publish it and wait a further sweep to take it down again.
  Replaying stale instructions in order would put a page live that its editor
  said should be gone, for however long the next sweep is away.
- **Scheduling is governed by `content.publish`.** An account that may not
  publish now may not arrange to publish later either, or the capability would
  mean "cannot publish *immediately*", which is not a rule.
- **The publish gate is asked at the moment of publication, not when the
  schedule is set.** A review workflow's answer is about the page as it stands,
  and the page will have changed by then. A refused scheduled publish keeps its
  schedule and is retried, so an approval granted later still results in the
  page going live.
- **A takedown is not gated.** The gate exists so a review can stop something
  reaching the public; a takedown moves the way the gate is protecting. Asking
  its permission would let a broken review plugin hold a page live past the date
  its editor set — which for a legal notice or an expiring offer is the failure
  that actually costs.

The sweep writes through the same decorated storage a request does, so a
scheduled publish is versioned, audited and cache-invalidated exactly like one
made by hand — and it is recorded against whoever set the schedule rather than
against nobody, via `Application::runAs()`.

### Choosing a storage backend

Core ships two, and `config/core.json` decides which one runs:

```json
{
  "core": {
    "storage": {
      "backend": "json",
      "sqlite": { "path": "data/content.sqlite" }
    }
  }
}
```

`backend` is `json` or `sqlite`. It defaults to `json`, and every setting under
`storage` has a default, so an installation with no configuration file at all
boots on flat files. That is the "no database required" property above, enforced
rather than intended.

`sqlite.path` is only read for the SQLite backend. A relative path resolves
against the installation root rather than the working directory, which differs
between the web server and the CLI and would otherwise give one install two
databases. It defaults under `data/` because that directory is already expected
to be writable and already outside the web root — a database served over HTTP
hands out every account record in it.

Anything else is refused at boot, loudly: an unrecognised backend name, or
`sqlite` on a PHP build without the `pdo_sqlite` extension, throws with the value
that was configured, the backends that exist, and what to change. Nothing falls
back to JSON. A site that silently ran on a different store than it was
configured for would look exactly like one that had lost every document it ever
wrote, which is the quiet failure this document exists to forbid.

Two caveats when moving content between backends:

- **Migration is not automatic.** Switching `backend` points the CMS at an empty
  store; it does not copy anything across. Move it first with
  `php bin/click-migrate-storage.php <from> <to>`, which is safe to re-run — a
  document already present identically in the target is skipped, so an
  interrupted run is finished by running it again. Version history is not moved.
- **Slugs that differ only in case are not portable.** SQLite tells `page:Home`
  and `page:home` apart. The flat-file backend inherits whatever the host
  filesystem does, and on macOS or Windows those are one document. A site
  relying on the distinction loses content moving from SQLite to files.

### The render cache

Off by default. Turn it on with:

```json
{ "core": { "cache": { "enabled": true } } }
```

Rendered public pages are then stored under `data/cache/pages` as flat files —
no new dependency, and the whole thing is safe to delete by hand.

**What it will not cache.** A preview, and any request from a signed-in
visitor. Both would let one person's view of the site become everyone's.

**How it stays fresh.** Invalidation is wired into the storage layer rather than
into each handler, so there is no code path that can change a document and
forget to clear. Every write clears the whole cache rather than one entry:
every document embeds the site header, so publishing a page that appears in the
menu changes the header of every other page, and there is no dependency map
from a rendered document back to what went into it. Admin writes are rare and
public reads are not, so a full refill costs nothing worth measuring.

**The one thing it cannot see.** A `web.render` plugin is handed the request and
may vary its output on the query string, a cookie or the time of day. None of
those are in the cache key and none of them are detectable from here. A site
running such a plugin must leave the cache off. Section type definitions are the
other blind spot: editing `config/sections/*.json` on disk is a deploy-time
change with no handler to hook, so clear `data/cache/pages` as part of the
deploy.

### Extension points

Capability 8. A plugin declares the hooks it wants in its `plugin.json` and
implements `hook_<name_with_underscores>`; core fires each by name and collects
what came back. There are fourteen, in three kinds.

| Hook | Kind | What it is handed | What it may do |
|---|---|---|---|
| `api.routes` | collect | nothing | return `"METHOD /path" => handler` |
| `web.render` | transform | the page, the shell, whether this is a preview | return replacement markup |
| `content.before_save` | **veto** | `key`, `type`, `slug`, `locale`, `user`, `created`, `reason` | refuse the write, with a reason |
| `content.saved` | announce | the same | nothing that changes the outcome |
| `content.before_delete` | **veto** | `key`, `type`, `slug`, `locale`, `user` | refuse the removal, with a reason |
| `content.deleted` | announce | the same | nothing that changes the outcome |
| `content.before_publish` | **veto** | `key`, `type`, `slug`, `locale`, `user` | refuse the publish, with a reason |
| `content.published` | announce | the same | nothing that changes the outcome |
| `content.unpublished` | announce | the same | nothing that changes the outcome |
| `auth.before_login` | **veto** | `username`, `remember`, `role`, `mustChangePassword` | refuse the sign-in, with a reason |
| `auth.logged_in` | announce | the same | nothing that changes the outcome |
| `auth.login_failed` | announce | `username`, `reason` | nothing that changes the outcome |
| `auth.logged_out` | announce | `username`, `role`, `mustChangePassword` | nothing that changes the outcome |
| `auth.locked_out` | announce | `username`, `retryAfter` | nothing that changes the outcome |

The first two only ever *add*. `content.before_publish` is the first that can
stop core doing something, which is what an editorial workflow needs: a review
step nothing enforces is a note-taking feature. Its contract is stated in
`Application\Plugin\PublishGate` and is deliberately lopsided.

**Refusing.** Return `['allowed' => false, 'reason' => '…']`. The reason is
shown to the editor, so it must name the state that is blocking — *"this page is
waiting for review"*, not *"not allowed"*. The publish is answered `409`: the
request is well formed and the caller is entitled to make it, and calling that
`403` would send an editor hunting for a permission they already hold.

**Everything else permits.** `null`, an empty array, a missing `allowed`, a
plugin that never implements the hook — all mean "no opinion". Fail-closed on a
refusal, fail-open on silence.

**A plugin that throws has no opinion.** The throw is logged and the publish goes
ahead. That is the deliberate choice between two bad outcomes: a gate that failed
to gate publishes a page early, which is visible and reversible, while a plugin
fault that makes the site unpublishable locks out the person who would fix it.
Each plugin is dispatched in isolation for this hook — via
`PluginManager::executeHookIsolated()` — so one broken extension cannot swallow
another's refusal and leave the gate silently disarmed. `api.routes` and
`web.render` keep failing loudly, because a site quietly missing a plugin's
endpoints is worse than one that refuses to boot.

**First refusal wins.** Plugins run in dependency order, so which one answers is
stable rather than a race.

**There is no way to force a publish.** Permitting is already the default, so an
"allow" return could only override another plugin's refusal, and a gate any
plugin can switch off is not a gate.

`content.published` is the companion, and exists because "clear the review now
the change is live" is only true *after* the promotion landed. Doing that inside
the veto would spend an approval on a publish that then failed in storage.

Both fire from `PageService::publish()` rather than from the HTTP handler.
Publishing has several callers — the management API, the seeder, a plugin
publishing a release — and a gate any of them can walk around is decoration.

#### The content lifecycle

The five save/delete/unpublish hooks obey exactly the rules above — silence
permits, a throw is not an opinion, the first refusal wins, announcements are
advisory — and are stated in `Application\Plugin\ContentGate`. What they add is
reach: a search index that stays current, a webhook that rebuilds one page of a
static front end, an audit shipper, a validator that refuses a document its own
schema says is incomplete. None of that is expressible against `api.routes` and
`web.render`, which can only add.

They fire from `Infrastructure\Plugin\ContentEventStorage`, a storage decorator,
for the reason `CacheInvalidatingStorage` is one: *"also fire the event"* is the
instruction that gets forgotten — by the handler added next year, by the CLI, by
the seeder — and an event system that fires on some write paths and not others is
worse than none, because a search index that is usually current gets trusted. In
storage, the question stops being "did this code path remember?" and becomes "did
it write?". A write it cannot see is one that bypassed storage, and there is no
such path. The proof is that the first-boot admin seed in `AuthController` is
observable to a plugin, and nothing was added to `AuthController` to make it so.

It sits **outermost** of the write decorators — outside cache invalidation, which
is outside authorization, versioning and audit. So:

- an announcement means the write was authorised, versioned, audited, on disk
  *and* the stale render cache dropped. A listener that re-renders the page it was
  just told about cannot warm a cache from content it has been told is old.
- a `before_` hook can fire for a write a layer beneath then refuses. Treat the
  before-hooks as side-effect-free; only `content.saved` / `content.deleted` mean
  it happened.

**A refused write throws** `Application\Plugin\ContentRefusedException`, carrying
the hook, the key and the plugin's reason. `save()` returns `void`, so unlike
`publish()` there is no in-band channel, and returning normally from a write that
wrote nothing is how an editor comes to believe their work is on disk. Handlers
that want a `409` instead of a `500` should catch it.

**An event only fires once the thing happened.** A save that threw in storage is
never announced; a delete that removed nothing and an unpublish that took nothing
down are silent. Over-firing would have a listener drop an index entry for a
document that never existed.

**Payloads carry identity, not content.** `key`, `type`, `slug`, `locale`, the
acting `user` reduced to `username` and `role`, plus `created` and `reason` on a
save. The document's `data` is deliberately absent: users are ordinary content
documents, so a payload carrying `data` would hand every plugin a password hash
on every password change, and would keep doing so for whatever secret a future
content type holds. A plugin that needs the body reads the key it was given.

**A hook nobody listens to costs nothing.** These fire on every write, so before
building a payload the gate asks `PluginManager::hasHookListeners()`, which
answers from the `hooks` array each `plugin.json` already put in memory —
memoised, no bootstrap loaded, no file read. Measured on a site listening to none
of them: **0.15 µs per write and zero extra storage reads**, against ~290 µs for
one real versioned flat-file write — under 0.06% of a write, and nothing at all on
a read. The two reads that decide `created` happen only when somebody is
listening.

`content.published` is fired by the publish gate at the service layer and *not*
also by the decorator, which would deliver every publish twice.
`content.unpublished` is the reverse: nothing at the service layer announces a
takedown, so it fires from storage and is therefore seen wherever the takedown
came from.

#### Authentication

The five auth hooks obey the same rules and are stated in
`Application\Plugin\AuthGate`. They exist for the three things people most want
to add to a CMS's identity layer and cannot: a second factor, an audit trail
shipped off the box, and an alert when an account is being ground at.

They are the one part of this surface with **explicit call sites** — in
`Http\AuthController` — rather than a decorator behind them, because a session is
not a content document and there is nothing to decorate. What makes the call
sites trustworthy instead is that there is only one of each: one password check,
one place a session is created, one place a failure is counted, all in that file.

**Where the veto sits.** `auth.before_login` fires *after* the site-wide spray
ceiling, *after* the per-account lockout, *after* `password_verify` and *after*
the account's status check — and before the session exists. Each of those is
load-bearing:

- **Behind the two limits**, so a plugin can neither weaken them nor be reached by
  an attempt they have already refused. A hook in front of them would let an
  attacker drive plugin work — an SMS, a webhook — per attempt, through the very
  limit that exists to stop that, and hand plugin code the usernames of a spray
  in progress. A locked-out account never reaches plugin code at all.
- **After the password check**, so a plugin is only asked about an attempt that
  has already proved the first factor. "Should this person provide a second
  factor" is not answerable before the first one is known to be right.
- **Before the session**, because a refusal that left somebody signed in would
  not be a refusal.

**Fail-open matters more here than anywhere else.** A second-factor plugin that
threw on every attempt would, under fail-closed, lock *every* account out of the
site — and the only way to disable a plugin is the admin UI, which needs a
sign-in. That failure would need shell access to undo. Against it, the cost of
failing open is one sign-in that skipped a second factor behind a password that
was still verified, on the record in the error log. There is likewise no way for
a plugin to *force* a sign-in: an "allow" return could only override another
plugin's refusal, and would make a bug in any plugin an authentication bypass.

**A refusal answers `403`, with the plugin's reason verbatim.** A second factor
cannot be asked for without admitting the first was accepted, so hiding the
refusal behind *"invalid credentials"* would make 2FA unusable; `403` rather than
`401` is what distinguishes it from a wrong password. **Which** plugin refused,
and for which account, goes to the error log and not to the caller. A refusal is
counted against that account's own lockout — the veto must not be an unmetered
surface to retry against — but *not* against the site-wide ceiling, on the
distinction the controller already draws: whoever got there proved the password,
so it is not evidence of credential stuffing, and counting it would have a site
using a second factor spray-block itself during ordinary step-ups.

**A failed sign-in tells a plugin exactly what the caller is told, and no more.**
`invalid_credentials` covers a wrong password, an unknown account *and* an account
with no usable hash, because all three are answered with the same `401`. Handing
plugins the difference would make every listener an enumeration oracle for a
distinction this flow spends real effort hiding — the lockout and the spray
refusal are worded identically for the same reason, and the throttle hashes
usernames so the CMS never keeps a list of accounts somebody has been probing. A
plugin that genuinely needs to know whether an account exists holds a content
service and can look it up under its own name, which is an attributable act
rather than a fact core volunteers. `account_inactive` *is* distinguished, because
the caller already gets a `403` for it; `refused_by_plugin` reports the veto.

**No password, hash or session identifier appears in any payload**, and that is
enforced rather than remembered: `AuthGate::describe()` reduces an account
through an allowlist (`role`, `mustChangePassword`), so a field added to a user
document or to the session is invisible until somebody decides otherwise. Users
are ordinary content documents here, so forwarding "the user" would hand every
plugin a bcrypt hash on every sign-in. The request body is not passed either — it
holds the password; a second-factor plugin reads its own field from the request
it is already inside. Nor is the source address, which a plugin can read from
`$_SERVER` itself and which core will not make a spoofable header look vouched
for.

**Only the transition is announced.** `auth.locked_out` fires on the failure that
establishes a lockout, not on every attempt refused while it holds, so an alert
is one alert. Attempts refused by the lockout or the spray ceiling announce
nothing at all. A sign-out by somebody who was not signed in is silent, and a
request missing a username never reaches an account and so is not a failed
sign-in.

**A hook nobody listens to costs nothing.** `PluginManager` answers from the
memoised `hasHookListeners()` before dispatching anything, and the gate asks
before it builds a payload — including before the two extra reads of the lockout
file that establish whether *this* failure was the one that tipped the account
over.

#### Not offered, deliberately

Not everything worth an event is reachable from a place that cannot be bypassed,
and a hook that fires on some paths only is a trap:

- **Media upload and deletion.** `MediaService` writes files and their metadata
  JSON directly, not through the content port, so a storage decorator cannot see
  them. Doing this properly means a port for media and a decorator on it, not a
  call bolted into one HTTP handler.
- **A password change.** `auth.*` covers sessions; the password itself is a write
  to a user document, so a listener already sees it as `content.saved` with the
  key of the account — without the new hash, which is the point. A separate hook
  would deliver the same fact twice and tempt somebody to put the credential in it.
- **Settings changed.** Settings live in `data/settings.json`, outside the content
  port, and are invalidated by their own handlers.
- **`content.before_unpublish`.** A takedown is reversible by republishing, and
  the editorial question a veto exists to answer — "may this go live?" — is
  already answered by `content.before_publish`. A second gate here would be a
  veto with no case behind it.

### Explicitly not core

- **Delivery APIs** (`rest-api`, `graphql`) — how an *external* front end reads
  content. A self-rendering site needs neither.
- **Page rendering and themes** — how a site looks is the site's business.
- **Editorial features** — SEO, redirects, forms, comments, search, social,
  analytics. Most sites want some and no site wants all.
- **Publishing a registry** — hosting a catalogue of plugins for others is a
  separate product. *Consuming* one is core; running one is not.
- **Free-form building** — see `backlog.md`; a mode a site opts into.

## Where core is today

Honest assessment, not aspiration.

### Sound

The domain layer. `Content`, `ContentKey`, the schema types, `UploadPolicy` and
`ImageSize` are small, dependency-free and covered by tests. Storage sits behind
a port with two implementations, both selectable from configuration and both
held to one shared contract test, so the choice of backend cannot change what a
caller observes. Media generates variants and refuses what it
cannot verify. This is the part that is right.

### `Core\Application`

Was one class of about a thousand lines doing everything. Sessions, login
throttling and configuration now live on their own:

```
Application\Authentication\SessionStore     reading, expiry, idle timeout
Application\Authentication\LoginThrottle    failure counting and lockout
Application\Authentication\CsrfGuard        token generation and comparison
Application\Config\CoreConfig               one name and one default per setting
Domain\Identity\Role, Capability            who may do what
Http\CoreApiRoutes                          the management API
Application\Content\PageService             page management rules
```

Still to extract: the HTTP kernel itself, route matching and the health checks.
Application remains larger than it should be, but each remaining responsibility
is now visible rather than tangled with authentication.

### Gaps against the capability list

Closed since this was written: the seeded password now has to be changed before
anything else can be reached; CSRF tokens are required on state-changing
requests, plugin installation included; authentication is deny-by-default rather
than an allowlist of protected prefixes; roles map to named capabilities; page
management moved into core, so the delivery plugins are genuinely optional; and
sessions are one file per session, keyed by a random identifier in an HttpOnly
cookie, where they were previously a single shared record that authenticated any
visitor as whoever had last signed in; and `SqliteStorage` is now reachable,
chosen by `core.storage.backend` and executed by the same tests as the flat-file
backend, which is what closed the gap between capability 2's claim and the code.

`config/config.json` is gone. It declared a `storage.default` nothing read and an
`admin.enabled: false` that was false while the admin UI worked, alongside three
more sections no code consulted — a file describing a system that did not exist,
sitting next to the one the application actually loads. The single real setting
in it moved to `core.storage` in `config/core.json`; the rest was fiction and was
deleted rather than implemented.

Still open:

- **Nothing enforces capabilities at the storage layer.** A handler that forgets
  to ask is still able to act. Checks belong closer to the operation.
- **No way to migrate content between storage backends.** Both are now
  selectable and both are proven against one shared contract, but switching
  `core.storage.backend` points the CMS at an empty store rather than moving
  what is already there. Until there is a command for it, changing backend on a
  site with content is a manual job.
- **Media reports nothing about quality.** See above. The ladder silently
  produces fewer variants for a small upload.
- **No history or preview.** Capabilities 11 and 12, neither started.
  Languages (capability 10) is done: a locale is part of `ContentKey`, flat-file
  storage is `{type}/{locale}/{slug}.json`, and a request that cannot be
  answered in the language it asked for is answered in the default language and
  says so rather than pretending. Documents in the pre-languages layout are
  still read, and migrate the next time they are saved.
- **No languages or preview.** Capabilities 10 and 12, neither started.
- **No languages or history.** Capabilities 10 and 11, neither started.
  `ContentKey` is `type/slug` with no locale dimension, which is the specific
  thing that has to change first.
- **History is in.** Capability 11. Storage is wrapped in a decorator, so every
  write leaves a snapshot behind whichever backend is underneath, and deleting a
  page retains the state it removed rather than being the one operation with no
  way back. Versions live under `data/versions`, outside the content directory,
  because they hold unpublished drafts and must not be reachable as content;
  their layout is derived from the key's own string form, so a key that gains a
  dimension gains a directory level rather than a migration. Restoring writes
  forward — the restored state becomes the newest version — so a restore of the
  wrong version is itself undoable. Retention keeps the newest
  `core.history.retainVersions` (twenty by default) per document, oldest
  discarded first, with two exemptions: the newest version, which is now the
  working copy, and the version a publish recorded, which is what the live site
  is serving. That said "no exemptions" until draft-and-publish landed, at which
  point it became a data-loss bug — twenty-one edits without publishing would
  have discarded the version the public was reading, leaving the site serving a
  state nothing could name or put back.
- **Preview shows the stored document, not an unsaved edit.** Capability 12 is
  built: a signed, expiring link renders any page through the same
  `SectionRenderer` the public site uses, per language — the token signs the
  document's full identity, so a link to one translation cannot open another,
  and a preview of a translation that does not exist is a 404 rather than a
  quiet fallback to the language that does.

  It now also shows a change to an *already published* page without publishing
  it, which it could not do while a page had one stored document and saving that
  document was publishing it. The decision that gap was waiting on has been
  made — see **Draft and publish** above — and preview reads the working copy,
  saying on the page itself when what is shown is not what the public is
  reading.
- **No audit trail.** Who changed what, and when, is not recorded anywhere.
- **The two install paths are not equally trusted.** Registry installs verify a
  signed manifest with `openssl_verify` against a configured public key, which
  is sound. Uploading a ZIP verifies nothing. That asymmetry matches what
  WordPress does and is defensible for an administrator-only action, but it
  should be a stated decision rather than an accident, and the UI should say
  which path is verified.
- **`serveAdminUi()`** proxies a development server on hardcoded
  `localhost:4321`. Development convenience in the production path.
- **`loadLegacyAdminUser()`** reads a path no current code writes. Dead
  compatibility shim.

## Order of work

Correctness before tidiness, and nothing that changes behaviour mixed with
anything that does not.

The security work listed here previously — forced password change, CSRF, page
CRUD in core, extracting authentication, the capability model — is done.

Five of the twelve capabilities are now built and reachable by an editor:
**languages**, **history**, **preview**, **media quality reporting** and
**draft-and-publish**, with the second storage backend selectable. The page
editor and page list expose publication state, language switching, version
history and restore; the capability model refuses an author the publish control
rather than letting the server's 403 be the interface.

What remains:

1. **Move users and plugins management into core.** After the delivery-plugin
   cleanup, `rest-api` holds no delivery surface — only user CRUD, plugin
   management and `/api/info`, all of which the admin UI depends on. A plugin
   named "delivery API" holding account management together is the fake
   optionality this document exists to prevent. Move it to core and delete the
   plugin. Security-sensitive (passwords, plugin activation); wants its own
   verified pass. Related: plugin deactivation does not persist across a restart.
2. **Extract identity out of `Application`.** The kernel is ~1,560 lines, of
   which some 39 methods are authentication, sessions, throttling, CSRF and
   password changes — an entire bounded context living inside the HTTP entry
   point, and the largest DDD violation in the codebase. This is the pure-refactor
   centrepiece and should be done test-first.
3. **History covers pages only.** Media and user documents are versioned at the
   storage layer, but nothing exposes those versions.
4. **Extracting the kernel is largely done.** Health, the security gate
   (`ApiGuard`), authentication (`AuthController`), user and plugin management,
   delivery CORS and the marketplace are each their own tested unit now, and
   `Application` has gone from ~1660 lines to ~1170. What remains in it is the
   kernel's real job — routing, boot composition and page rendering — plus
   config loading, which the settings-out-of-files work will revisit.
5. **Settings out of files.** Bootstrap stays on disk because storage
   configuration cannot live in storage; everything else becomes a document,
   edited in the admin UI.
6. ~~No audit trail.~~ Done: an append-only trail records who did what
   (created, updated, deleted, published, unpublished, restored), as a storage
   decorator wrapping versioning, readable by an administrator at `GET /api/audit`.
7. **Concurrent editors are unmodelled.** Two people editing one page produce two
   draft chains with no rule for which wins. Draft-and-publish makes this
   visible where immediate saves hid it. See `collaboration.md`.

Only then go through the plugins one at a time. Each should have to justify
itself against the test at the top of this document, and anything that fails it
either moves into core or is deleted.
