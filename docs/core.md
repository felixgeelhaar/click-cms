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
  store; it does not copy anything across. There is no migration command yet.
- **Slugs that differ only in case are not portable.** SQLite tells `page:Home`
  and `page:home` apart. The flat-file backend inherits whatever the host
  filesystem does, and on macOS or Windows those are one document. A site
  relying on the distinction loses content moving from SQLite to files.

### Explicitly not core

- **Delivery APIs** (`rest-api`, `graphql`) — how an *external* front end reads
  content. A self-rendering site needs neither.
- **Page rendering and themes** — how a site looks is the site's business.
- **Editorial features** — SEO, redirects, forms, comments, search, social,
  analytics. Most sites want some and no site wants all.
- **Alternative storage backends** — MySQL, PostgreSQL. Implementations of an
  existing port is exactly what plugins are for.
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
