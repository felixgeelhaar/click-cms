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

Nine, and nothing else.

| # | Capability | Why it cannot be a plugin |
|---|---|---|
| 1 | **Content** — the aggregate, its key, and the service that reads and writes it | There is no CMS without content |
| 2 | **Storage** — the port, plus flat-file and SQLite implementations | The application cannot boot without one |
| 3 | **Schema** — field types, section types, validation | Content with no defined shape cannot be validated or rendered |
| 4 | **Media** — upload policy, storage, responsive variants | Content references media; the reference must always resolve |
| 5 | **Identity** — accounts, authentication, sessions, roles | Security must not be removable |
| 6 | **HTTP kernel** — request to response, route matching, security headers | It is the entry point |
| 7 | **Management API** — the endpoints the admin UI depends on | The admin UI is how the product is used |
| 8 | **Extension points** — plugin discovery, installation and lifecycle, events, hooks | The plugin system cannot itself be a plugin, and a plugin system with no way to install plugins is developer-only |
| 9 | **Configuration and health** — loading settings, reporting readiness | Needed to run and to operate |

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

### The main problem: `Core\Application`

One class, roughly a thousand lines, with about fifty methods. It is currently
all of:

- the HTTP kernel and router
- authentication, sessions, idle timeout and login lockout
- configuration loading and validation
- plugin installation endpoints
- health checks
- proxying the admin UI to a development server
- rendering public pages
- logging

That is at least seven responsibilities in one file, and it is why the login bug
went unnoticed: authentication is buried in a class nobody reads as an
authentication class. Capabilities 5, 6, 7 and 9 above are all tangled here.

It should become a thin kernel that wires collaborators together:

```
Http\Kernel              request -> response, nothing else
Http\Router              route tables and matching
Http\SecurityHeaders
Application\Auth\*       authentication, sessions, lockout
Application\Config       loading and access
Application\Health
```

None of that changes behaviour. It makes each piece findable, testable, and
small enough to review.

### Gaps against the capability list

- **Identity (5)** is the weakest. Roles are a bare string on a user document,
  with no capability model, so "an administrator may use the builder but an
  editor may not" cannot currently be expressed. There is no CSRF protection,
  and sessions are a single shared file that concurrent logins will fight over.
- **The installer creates `admin` with the password `admin` and never forces a
  change.** This is the most dangerous item in the project.
- **Management API (7)** is incomplete: page CRUD still lives in the `rest-api`
  plugin, so disabling that plugin leaves the admin UI unable to manage pages.
  Full CRUD belongs in core; read-only delivery stays in the plugin.
- **Installing plugins has no CSRF protection.** This is the sharpest edge in
  the codebase. The endpoint is correctly restricted to administrators, but with
  no CSRF token an administrator who loads a hostile page while logged in can be
  made to install a plugin without knowing. On an endpoint that installs
  executable code, a cross-site request forgery becomes remote code execution.
  CSRF is listed below as a general gap; this is the reason it is urgent.
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

The first two are security work and come before everything else.

1. **Force a password change on first run.** The installer creates `admin` with
   the password `admin`. Anyone who finds a deployment owns it, with no user
   interaction required at all.
2. **Add CSRF protection**, starting with plugin installation. An
   administrator's session is otherwise one hostile page away from installing
   executable code.
3. **Move page CRUD into the management API.** Completes capability 7 and makes
   the delivery plugins genuinely optional.
4. **Extract authentication, sessions and lockout out of `Application`.** Pure
   refactor, no behaviour change, and the precondition for taking identity
   seriously.
5. **Add a capability model.** Roles that mean something, so editing modes and
   permissions can be expressed at all.
6. **Extract the kernel, router, config and health.** What remains of
   `Application` should fit on a screen.

Only then go through the plugins one at a time. Each should have to justify
itself against the test at the top of this document, and anything that fails it
either moves into core or is deleted.
