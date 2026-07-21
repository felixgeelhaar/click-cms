
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

**Delivery** — how an external front end reads content — is a plugin, currently
`rest-api` and `graphql`. A site that uses the CMS's own page rendering needs
neither. Both can be disabled and the CMS stays fully manageable.

Outstanding: page CRUD still lives in the `rest-api` plugin, so disabling it
leaves the admin UI unable to manage pages. Split it — full CRUD to core as
management, read-only public delivery stays in the plugin.

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

- Rich text is a plain textarea. The seam exists to drop an editor in without
  touching anything else.
- Sidebar navigation uses `<a>` elements with no `href`, so it is not
  keyboard-focusable and is not announced as navigation. An accessibility
  defect, not a cosmetic one.
- Section and repeater reordering is arrow buttons rather than drag and drop.
  Accessible and dependency-free, but not what people expect.
- No preview of what a page will look like once rendered.

---

## Media

Uploads are stored outside the document root and streamed through the
application, with responsive variants (`-sm`, `-md`, `-lg`, `-xl`) generated
once at upload time.

Outstanding:

- SVG is refused because it can carry script. Logos are usually SVG, so this is
  a real cost. Supporting it safely needs a sanitiser; a half-sanitised SVG is
  worse than none.
- No cropping or focal point. Variants preserve the source aspect ratio, because
  cropping to a fixed box is an editorial decision the CMS should not make
  silently.
- No bulk operations, folders, or search. Fine for tens of images, painful at
  hundreds.

---

## Security

Fixed already: content-based upload type detection, generated filenames, upload
size limits, path-traversal defence on media serving, and removal of the
hardcoded `admin`/`admin` fallback that applied when a user document had no
password field.

Outstanding, and the first one matters most:

- The installer creates `admin` with the password `admin` and never forces a
  change. Fine for a demo, unacceptable for anything reachable. A first-run
  password change should be mandatory.
- No CSRF protection on state-changing API requests.
- No rate limiting on login beyond the existing lockout.
- Sessions are a single file, so concurrent logins from different users will
  fight over it.

---

## Marketplace

Plugin discovery and installation, with signed manifests. Present in core but
not exercised. Needs a decision on whether it is a product direction or should
be removed — an unused path that installs arbitrary code is a liability, not a
feature.
