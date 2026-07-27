
## Editing modes: constrained sections vs free-form building

The decision that shapes most of the rest of this list.

Click CMS serves two audiences, and they want opposite things from the same
screen:

| | Constrained sections | Free-form building |
|---|---|---|
| Who builds the site | a developer or agency | the site's own owner |
| Who edits it afterwards | a non-technical colleague | the same person |
| Design authority | the developer | whoever is editing |
| The guarantee | the design cannot be broken | anything is possible, including breaking it |
| Comparable to | Kirby blueprints, Storyblok | WordPress with a page builder |

Both are legitimate. Serving only the first rules out most of the small-business
market a PHP flat-file CMS actually lives in; serving only the second makes the
CMS unusable for agency work, where "your client cannot break this" is the
entire value. WordPress carries both — locked block patterns for delivered
sites, page builders for self-built ones — and there is no reason to pick one
here either.

"The editor can change the layout" is the feature, not the bug, when the editor
owns the design.

### What exists today

**Constrained sections — built.** A site declares section types in
`config/sections/`, each a fixed set of typed fields. The editor picks a design
from that list and fills the fields in. Validation happens at the HTTP boundary
and discards anything the schema does not declare, so stored content can only
ever hold a shape the site's templates were written for. See
`src/Domain/Schema` and `src/Http/CoreApiRoutes`.

**Free-form building — built.** `docs/visual-builder.md` defines the data
model: a node tree with breakpoints and per-breakpoint style overrides.
`plugins/visual-builder` renders that tree to HTML server-side, and the editor
exists — `admin-ui/src/components/Builder.vue` with its palette and inspector.

This paragraph said "designed, not built" long after the editor landed, which is
the kind of stale claim this document exists to avoid. What remains open is
reusable blocks or templates, and that is a data-model decision before it is a
feature: whether a page references a saved block or holds a snapshot of it, and
what happens to every page using one when it changes.

### The risk to resolve first

The danger is not that both modes exist. It is that both are silently available
at once, so nobody can tell which rules apply. An agency ships a site built from
section types, and a client eventually drops a free-form canvas into it and
breaks the thing the agency guaranteed.

That is a configuration gap, not a reason to drop either mode.

### Proposed resolution

1. A site declares which modes its editors get. Ideally per role: an
   administrator building the site may use the builder, an editor maintaining it
   sees section types only. This is how WordPress separates the two, via
   capabilities.
2. Consider expressing free-form building **as a section type** rather than as a
   parallel system. A site that wants freedom declares a `free-form` section
   whose value is a builder node tree; a site that does not simply does not
   declare it. That leaves one content model, one editor shell, and turns the
   constraint into a per-site choice instead of two competing systems.
3. Finish the constrained path first. It is close to usable and has a real user
   waiting. The builder is a v2 milestone.

---

## Storage

Backends sit behind `Domain\Storage\StorageInterface` — five methods: `find`,
`findByType`, `save`, `delete`, `exists`. Four backends now implement it, all
core, all passing one shared contract test (`StorageContractTestCase`), so a
site switches between them by changing `core.storage.backend` and nothing in the
application changes:

- **`json`** (default) — flat files, no database, runs on shared hosting. The
  property that defines the product; never regress it.
- **`sqlite`** — one file, needs only `pdo_sqlite`, for a site that outgrows
  flat files but still has no database server.
- **`mysql`** (alias **`mariadb`**) — needs `pdo_mysql`, for a shared database
  behind several app servers.
- **`postgres`** (aliases `postgresql`, `pgsql`) — needs `pdo_pgsql`.

All three SQL backends keep the payload as JSON in a single column, with
`type`/`locale`/`slug` as the only real columns, and enforce the same
`ContentKeyRules` as the flat-file backend — so the legal key space is identical
and content is portable between any backend. They differ only in dialect (the
upsert, and how a database is auto-created). Each is opt-in: `StorageFactory`
checks the PDO driver up front and fails loudly rather than falling back, since
a site silently running on a different store than it asked for looks exactly
like every document having vanished.

The earlier plan was for these to be plugins. In practice a SQL backend is a
~150-line PDO adapter that must satisfy the *same* contract as the others, so it
belongs beside them in core, exercised by the same test — not behind a plugin
boundary that would only make the shared contract harder to guarantee.

### NoSQL / document stores — a later idea, not a commitment

Worth recording because it comes up and the trade-off is not obvious. The port
is already document-shaped — a JSON payload addressed by `(type, locale, slug)`
— so a document store, **MongoDB** especially, is the most *natural* technical
fit of any backend; `MongoStorage` would be short and direct.

The reasons it is parked, not built:

- **It breaks the dependency posture.** JSON needs nothing; SQLite/MySQL/Postgres
  need a PDO driver that *ships with PHP*. MongoDB needs the `ext-mongodb` PECL
  extension (not bundled), and its ergonomic API needs the `mongodb/mongodb`
  Composer library — a **runtime dependency**, which cuts against the
  zero-runtime-dependency line the whole product is built on. The extension
  requirement alone is a step past "runs anywhere."
- **It serves a segment that isn't the audience.** A document store earns its
  keep at sharding / horizontal scale / very large document volumes. This CMS's
  range — shared hosting → SQLite → one MySQL/Postgres — already spans its niche;
  someone who needs Mongo's scale is choosing a different class of CMS.

If it is ever pursued, the shape is decided: implement it against the **raw
`MongoDB\Driver\*` API** (Manager/Query/BulkWrite/Command) to avoid the Composer
runtime dependency, make it **opt-in** and **fail loudly** when `ext-mongodb` is
absent — exactly the shape SQLite and the SQL backends already have — and run it
against the same `StorageContractTestCase` before claiming it works. A
key-value store (Redis, DynamoDB) would fit the port just as cleanly and carries
the same "not the audience" caveat.

---

## Management API and delivery API

Two different things that were previously one.

**Management** — pages, media, schemas, authentication — is core. The admin UI
cannot function without it, so it must not be something a site can uninstall.
Page and media CRUD, publication, history and preview now live in core's
`CoreApiRoutes`.

**Delivery** — how an external front end reads content — should be a plugin, and
a site that renders its own pages needs none of it.

### Resolved

- Page CRUD moved to core; the `rest-api` plugin's copies were removed (they were
  dead-shadowed, since core matches first).
- Core's anonymous `GET /api/pages` / `GET /api/pages/:slug` **is** the REST
  delivery surface now — published content, no account needed.
- The `graphql` plugin is a real, safe delivery API: read-only, published-only,
  no account access, reachable anonymously.

### Outstanding — the sharp one

After the `rest-api` cleanup, that plugin provides **no delivery surface at
all**. What survives in it is `GET/POST/PUT/DELETE /api/users*`, the
`/api/plugins*` management routes, and `/api/info` — all **management**, all
depended on by the admin UI's Users, Plugins and Dashboard pages. So a plugin
named "REST API / delivery" is the thing holding user and plugin management
together. That is exactly the fake-optionality core.md warns about: a site that
disables the "delivery API" to render its own pages would lose account
management.

**Done.** User management moved to `UsersController`, plugin management to
`PluginsController`, both core and both gated by `ApiGuard`; the `rest-api`
plugin was deleted. The response never carries a password hash and the floor
comes from config.

### Also found

- ~~Plugin deactivation does not persist.~~ Done. `discover()` no longer
  pre-marks state; `boot()` activates each plugin that is not persisted as
  deactivated (`isDeactivated`), and a new plugin still activates by default.
  It was deeper than first thought: `discover()`'s pre-marking also made
  `activate()` short-circuit and skip loading the bootstrap, so on any boot after
  the first an active plugin's routes would have vanished — masked only because
  every container test began with an empty `data/`. Verified across a real
  restart.

---

## Plugin system

Hooks, events, lifecycle and route registration, discovered from `plugins/`.
Working, with one sharp edge: a directory without a `plugin.json` is skipped in
silence. Thirty directories were in that state and had never executed. Discovery
should report a directory that contains `bootstrap.php` but no manifest, rather
than ignoring it.

Plugins should be things a site can genuinely run without. Anything the admin UI
depends on belongs in core.

---

## Admin UI

Vue 3 and Astro, built into the image and served as static files under the
document root. Working: authentication, page list and editor, the section
editor, and the media library.

Known gaps:

- ~~Rich text is a plain textarea.~~ Done: `richtext` fields now use a
  dependency-free contenteditable editor (bold, italic, links, lists, H2/H3),
  and the renderer sanitises the stored HTML to a fixed tag/attribute allowlist
  server-side — the admin editor's sanitising is a courtesy, the renderer's is
  the security boundary, since anyone can POST directly to the API.
- ~~Sidebar navigation uses `<a>` elements with no `href`.~~ Fixed: the links
  carry their destination and mark the current page, so they are keyboard
  reachable, announced as links, and open in a new tab on Cmd/Ctrl-click.
- ~~Section and repeater reordering is arrow buttons rather than drag and drop.~~
  Done: rows drag, and the arrow buttons stayed — dragging is what people expect,
  but it is unusable by keyboard and hostile on touch, so removing the buttons
  would have traded one group of users for another.
- ~~No preview of what a page will look like once rendered.~~ Done: an editor
  previews the working copy, and can mint a signed link that shows it to somebody
  with no account at all — which is what preview is actually for.

---

## Media

Uploads are stored outside the document root and streamed through the
application, with responsive variants (`-sm`, `-md`, `-lg`, `-xl`) generated
once at upload time.

Outstanding:

- ~~SVG is refused.~~ Done: SVG uploads are accepted only after a strict
  allowlist sanitiser (ext-dom, no dependency) strips script, event handlers,
  `foreignObject`, external references and XXE vectors; the stored file is the
  sanitised output, never the raw upload; and it is served as `image/svg+xml`
  under a `default-src 'none'; sandbox` CSP as defence in depth.
- ~~No focal point.~~ Done: an image carries a focal point (a 0..1 coordinate,
  centre by default), set by clicking or arrow-nudging a preview in the media
  library. It rides through `MediaItem::toArray` into the delivery API and is
  expressible as CSS `object-position`, so a front end honours it for free. Still
  open: actual server-side cropping to fixed boxes (deliberately deferred — a
  crop is an editorial decision, and object-position covers the common case).
- ~~No bulk operations, folders, or search.~~ Done: the library searches by name,
  groups into folders and deletes in bulk, so it stays usable past a few hundred
  images.
- ~~No server-side cropping to fixed boxes.~~ Done: a site declares named
  art-directed crops under `core.media.crops` and each is cut focal-point-aware
  at upload and recut when the point moves, alongside the responsive ladder.
- ~~Video is refused.~~ Done: MP4 and WebM are accepted (their own 64 MB ceiling,
  stored verbatim — the CMS does not transcode) and served with byte-range
  support, so a site can host its own hero clip rather than depending on a front
  end's bundled assets.

---

## Security

Fixed already: content-based upload type detection, generated filenames, upload
size limits, path-traversal defence on media serving, and removal of the
hardcoded `admin`/`admin` fallback that applied when a user document had no
password field.

Done since:

- The installer's `admin`/`admin` now forces a password change before anything
  else is reachable, and the server enforces the minimum length itself.
- State-changing API requests require a CSRF token sent as a header.
- Sessions are one file per session, named by a random identifier held in an
  HttpOnly cookie. They were a single shared file, which meant any anonymous
  visitor was treated as whoever had last logged in.

Outstanding:

- ~~No rate limiting on login beyond the existing lockout.~~ Done, and twice
  over: `LoginThrottle` locks a named account out after its own threshold, and
  `LoginSprayGuard` refuses every login while the site as a whole is over a
  failure ceiling in a rolling window — which is the case the per-account
  lockout cannot see, since a spray tries one password against a thousand
  names. The same two limits now govern the second-factor step, because six
  digits with unlimited guesses would be a weaker secret than the password it
  strengthens.
- ~~No second factor.~~ Done: TOTP in core, with recovery codes. See
  `docs/two-factor.md` and the section in `core.md`.
- ~~No single sign-on.~~ Done: OpenID Connect, with SAML explicitly declined and
  the reason recorded. See `docs/sso.md`.
- ~~Capabilities are enforced only where a handler remembers to ask for them.~~
  Mitigated: `AuthorizingStorage` asks the type-blind question — may this account
  mutate content at all — at the storage boundary, so a handler that forgets its
  check is caught by something structural rather than by review. The specific
  capability still belongs to the handler, which alone knows ownership and intent.
- ~~No audit trail: nothing records who changed what.~~ Done: `AuditingStorage`
  wraps versioning as the outermost decorator, so every write — save, delete,
  publish, unpublish, restore — records who did it on top of the record of what
  changed.

---

## Marketplace

Plugin discovery and installation, with signed manifests. Present in core but
not exercised. Needs a decision on whether it is a product direction or should
be removed — an unused path that installs arbitrary code is a liability, not a
feature.

---

## Theming

A page rendered by the CMS itself links one stylesheet, `/theme.css`, served
straight off disk by Apache. `SectionRenderer` emits no styling of its own —
only semantic markup with stable class names (`cms-section--<type>`,
`cms-field--<name>`, presentation modifiers such as `cms-section--columns-4`)
that the stylesheet targets. That separation is right and should stay.

**Built.** A theme is now an installable package rather than a file the
application owns:

- Themes live in `themes/` at the installation root — outside the application, so
  a deploy that replaces the code leaves the design alone.
- Each is a directory with a `theme.json` manifest and a stylesheet.
  `ThemeRepository` discovers them, and the active one is remembered in
  `data/theme.json`; `ThemesController` lists them and activates one (gated on
  ManageSettings).
- `PageShell` links the active theme's stylesheet instead of a hardcoded path,
  and the URL carries an mtime version, so an edited theme is not served from a
  browser cache.
- Two ship: `default` (the previous stylesheet, moved out intact) and `dark`,
  which exists to prove the system works with more than one.
- The vhost aliases `/themes` read-only and refuses to execute anything in it —
  a theme is someone else's package, so a stray `.php` inside one must never
  become a way to run code.

`public/theme.css` is still shipped so nothing breaks mid-upgrade, and
`PageShell` falls back to it when a site has no themes directory at all. It is
dead once a site has themes and can be removed in a later release.

Still open: no way to *install* a theme from the admin (they are placed on disk),
and no plugin-supplied themes.

---

## Plugin review (findings, not yet acted on)

Deferred through the core work; opened by driving the running system. Each plugin
is judged against core.md's test: would a reasonable site turn it off? All three
pass that test — a self-rendering site needs none of them — so all three stay
plugins. But each has a concrete defect found by exercising it.

**Verified good:** the CMS boots and works with `plugins/` emptied — health,
admin UI, login and the management API all function. "Boots with zero plugins"
holds, which is what makes the without-plugins case — a plain self-rendering
site that installs nothing extra — real.

### rest-api (public delivery) — DONE, but see "Management API and delivery API"

Cleaned: the dead-shadowed page/version/media routes and their handlers are
removed. What remains is management stranded in a delivery plugin — moving it to
core is tracked above. Original findings, for the record:

- It registered `GET /api/pages/:slug/versions` and `.../versions/:versionId`,
  which **core also registers**. Core matches first, so the plugin's copies never
  run — and the plugin's are the old locale-blind versions, so even if they did
  they would now be wrong. Dead, shadowed routes that read as live.
- Its page CRUD overlaps core's management API the same way. Core's `getPage`
  already serves published content anonymously, which is why `/api/pages/home`
  answers with the plugin's own page routes shadowed.
- It fires a `content.status_changed` event on a `data['status']` field that was
  removed with draft-and-publish, so that event can never fire for a page again.
- The split to settle: the plugin should be *only* the public read surface that
  core does not already provide, not a second copy of routes core owns.

### graphql (alternative delivery) — DONE

Rewritten to a safe read-only, published-only delivery API with no account
access and no mutations, reachable anonymously. It previously also leaked
password hashes to any authenticated caller and could write unvalidated content;
both are gone. Original findings, for the record:

- `POST /api/graphql` returned 401: it is not in the public allowlist, so the one
  thing a delivery API is for — a front end with no account reading published
  content — does not work. REST delivery works anonymously; GraphQL does not.
- Making it public is not as simple as adding a prefix: a POST with an arbitrary
  query is a larger anonymous surface than a REST GET, and it needs its own
  answer for depth-limiting and for serving published-only content. Until then it
  is an authenticated-only API, which is not delivery.

### visual-builder (free-form building) — a milestone, not a plugin task

- One bootstrap hook; the server-side node-tree renderer exists. The editor —
  canvas, selection, drag and drop, breakpoints, undo — does not. `backlog.md`
  already scopes this as its own milestone. Nothing to review until that is built.
