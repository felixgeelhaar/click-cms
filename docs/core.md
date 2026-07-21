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
requests; authentication is deny-by-default rather than an allowlist of
protected prefixes; roles map to named capabilities; and page management moved
into core, so the delivery plugins are genuinely optional.

Still open:

- **Sessions are a single file.** Two people signed in at once share one record
  and will overwrite each other. Isolated in SessionStore, so replacing it
  touches nothing else.
- **Nothing enforces capabilities at the storage layer.** A handler that forgets
  to ask is still able to act. Checks belong closer to the operation.
- **No audit trail.** Who changed what, and when, is not recorded anywhere.
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
