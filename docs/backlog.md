
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

**Free-form building — designed, not built.** `docs/visual-builder.md` defines
a coherent data model: a node tree with breakpoints and per-breakpoint style
overrides. `plugins/visual-builder` renders that tree to HTML server-side. What
does not exist is the editor: canvas, node selection, drag and drop, a style
panel, breakpoint switching, undo.

That editor is the largest single piece of work in this project — larger than
the content core, schema system, media library and admin UI combined. It should
be scoped as its own milestone, not treated as a plugin someone finishes off
between other tasks.

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

Backends sit behind `Domain\Storage\StorageInterface`. JSON files are the
default because they need no database and therefore run on shared hosting;
SQLite is available for sites that outgrow flat files. Both are core, since the
application cannot boot without a storage backend.

MySQL and PostgreSQL backends are wanted but not written. They belong as
plugins: an alternative implementation of an existing port is exactly what the
plugin system is for. The previous directories of those names were removed
because they had no manifest and had never loaded.

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
- Section and repeater reordering is arrow buttons rather than drag and drop.
  Accessible and dependency-free, but not what people expect.
- No preview of what a page will look like once rendered.

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
- No bulk operations, folders, or search. Fine for tens of images, painful at
  hundreds.

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

- No rate limiting on login beyond the existing lockout.
- Capabilities are enforced only where a handler remembers to ask for them.
  Nothing structural stops a new endpoint from skipping the check.
- No audit trail: nothing records who changed what.

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

What is missing is a way to *supply* a theme:

- The path is hardcoded in core. A site cannot point at its own stylesheet
  without editing the renderer.
- `public/theme.css` lives inside the application, so replacing it means editing
  a file the CMS owns and a deploy overwrites it. A theme should be site-owned
  and live outside the image.
- There is no notion of more than one theme, or of choosing between them, or of
  a plugin shipping one.
- No cache-busting: an edited `theme.css` sits in browser caches with no version
  in the URL.

The current file exists to make a fresh install render something presentable. It
is a default, not a theming system, and should not be mistaken for one.

---

## Plugin review (findings, not yet acted on)

Deferred through the core work; opened by driving the running system. Each plugin
is judged against core.md's test: would a reasonable site turn it off? All three
pass that test — a self-rendering site needs none of them — so all three stay
plugins. But each has a concrete defect found by exercising it.

**Verified good:** the CMS boots and works with `plugins/` emptied — health,
admin UI, login and the management API all function. "Boots with zero plugins"
holds, which is what makes the without-plugins TurboScience case real.

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
