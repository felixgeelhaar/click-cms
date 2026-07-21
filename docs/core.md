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

Twelve, and the last three are gaps rather than implementations. They are listed
as core because of where they have to live, not because they are written.

### Media quality is part of media

Core generates a variant ladder — 640, 1024, 1536 and 2048 pixels wide — and
never scales an image up, because an upscaled image is a larger file that looks
no better. The consequence is that an upload smaller than a rung simply produces
fewer variants.

Today that happens in silence. A real upload during testing was 1022 pixels
wide; it produced one variant, the library displayed `sm`, and nothing said why
or what it would cost. On a high-density display that image is stretched by the
browser and looks soft. The person who uploaded it has no way to know, and the
person who would notice is a visitor.

That is precisely the failure mode listed under **Failure is visible**, so the
remedy belongs in core alongside the ladder that causes it:

- **At upload**, say what could be produced and what could not, in the editor's
  terms rather than in pixels alone — that this picture will look soft on modern
  phones and laptops, and roughly what size to supply instead.
- **In the schema**, let an `image` field declare the width it is displayed at.
  A card in a four-column grid and a full-bleed header have very different
  needs, and only the section type knows which it is. With that declared, the
  warning becomes specific: the same 1022-pixel file is fine in the card and
  wrong in the header.
- **Never refuse the upload.** A small image is often the only one that exists,
  and a logo that must ship today beats a warning that blocks it. Core reports;
  the editor decides.

Deliberately out of scope: judging compression artefacts, sharpness or subject
matter. Those need heuristics that are wrong often enough to erode trust in the
warnings that are right.

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
a port with two implementations. Media generates variants and refuses what it
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
visitor as whoever had last signed in.

Still open:

- **Nothing enforces capabilities at the storage layer.** A handler that forgets
  to ask is still able to act. Checks belong closer to the operation.
- **`SqliteStorage` is written and never constructed.** `Application::boot()`
  hardcodes `JsonStorage`, and `config/config.json` declares a
  `storage.default` that nothing reads. Capability 2 claims two implementations;
  one of them is unreachable.
- **Media reports nothing about quality.** See above. The ladder silently
  produces fewer variants for a small upload.
- **No languages or preview.** Capabilities 10 and 12, neither started.
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
  discarded first, with no exemptions.
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

What remains, in this order:

1. **Languages.** First because it changes how every document is addressed, and
   because every day it waits, more content exists to migrate. A locale belongs
   in `ContentKey`, which means storage layout, the management API, the delivery
   payload and the admin UI all move with it. Nothing else on this list should
   start until the content model has settled.
2. **History.** Second because it is also storage-shaped, and doing it after
   languages means versioning a key that already has its final form rather than
   versioning one and then migrating it.
3. **Media quality reporting.** Independent of both, small, and the editor feels
   it immediately.
4. **Preview.** Cheap once rendering and history exist — an unpublished version
   rendered at a URL that does not require the viewer to be signed in.
5. **Wire `SqliteStorage`,** or withdraw the claim that core has two storage
   backends.
6. **Extract the kernel, router, config and health.** What remains of
   `Application` should fit on a screen.
7. **Settings out of files.** Bootstrap stays on disk because storage
   configuration cannot live in storage; everything else becomes a document,
   edited in the admin UI.

Only then go through the plugins one at a time. Each should have to justify
itself against the test at the top of this document, and anything that fails it
either moves into core or is deleted.
