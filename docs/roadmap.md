# Roadmap — after v1.0.0

v1.0.0 shipped a feature-complete, zero-dependency flat-file CMS. Nothing below
is a *gap* that stops a real site — v1 is usable as it stands. These are the
next increments, ordered by how much they matter to a site actually running on
click-cms. `docs/backlog.md` remains the finer-grained tracker; this document is
the forward-looking view.

## Constraints that do not change

Every item here holds the lines v1 was built on, because they are the product:

- **Zero runtime dependencies.** No `composer require`, no database requirement.
  It runs on ordinary shared hosting. A feature that needs a daemon or a service
  is either redesigned to fit, or declined.
- **Strict DDD layering and TDD.** New work ships behind tests; the domain does
  no I/O.
- **Semantic versioning.** v1 is out, so anything that changes a public API
  response or a stored document shape is a **v2** concern. Keep the v1.x line
  additive and backward-compatible.

## Near-term — foundations a released project needs

1. ~~**Continuous integration for the test suites.**~~ *(small)* **Done.** A
   `ci.yml` workflow runs the PHP suite (on PHP 8.3, the runtime version), the
   admin suite (on Node 22), and a container smoke test that builds the image and
   polls `/health.php`, on every push and pull request. Jobs are independent and
   fail in isolation; in-flight runs are cancelled when a branch is superseded.

2. ~~**Builder pages bypass the site chrome.**~~ *(medium)* **Done.** A shared
   `Http\PageShell` now produces the document chrome — `lang`, the SEO head, the
   site header/navigation, the theme link, the `<main>` wrapper — and both the
   section renderer and the visual builder wrap their body in it. Core hands the
   shell to the `web.render` hook, so a builder page is navigable and indexable
   like any other; a full theme may still ignore the shell and return its own
   document. Per-breakpoint builder styles ride into the head via the shell's
   `$extraHead`.

3. ~~**Delivery pagination and filtering.**~~ *(medium)* **Done.** A shared
   `Http\DeliveryQuery` parses `?limit`, `?offset` and `?filter[field]=value`
   and applies them to a published listing in memory; both `GET /api/pages` and
   `GET /api/collections/:type/published` use it. `limit` is capped at 100 so one
   request cannot ask for an unbounded slice, and a malformed control falls back
   to the unpaginated default rather than erroring. Filtering is a shallow exact
   match on a top-level `data` field, or membership when the field is a list (a
   tag, a category). The response gains a `meta` block (`total` after filtering,
   `count`, `limit`, `offset`); with no parameters present the listing is
   unchanged, so this is additive and v1.x-safe.

## Rounding out what shipped

4. ~~**Collections admin depth.**~~ *(medium)* **Done.** The collection entry
   editor now reuses the page editor's `PageVersions` and `PageLanguages` panels:
   an entry's version history lists and restores per language (new `/versions`
   routes on `CollectionsController`, backed by the same `HistoryService` a page
   uses), and `getEntry` returns `availableLocales` so the editor offers a
   language switcher and creates a translation on first save in a new language.

   **Preview** is resolved as a signed draft-delivery link, chosen because a
   collection entry has no server-rendered view (it is delivered as JSON for a
   front end to render). `POST …/entries/:slug/preview` mints a signed,
   permission-gated link; `GET …/preview/:slug` returns the entry's *draft* as
   delivery JSON, reachable anonymously but gated by the signature (or a
   session), and marked `no-store` / `noindex`. A front-end preview environment
   points at the link and renders it as it would the published entry. The link
   reuses the one preview-signing secret pages already use.

5. ~~**Media cropping.**~~ *(medium)* **Done.** A site declares named
   art-directed crops under `core.media.crops` — `{ name, aspectWidth,
   aspectHeight }` — and the media pipeline cuts each one focal-point-aware at
   upload and recuts it when the focal point moves, alongside the existing square
   crop and never upscaling (long edge capped at 1600px). The crops and their
   boxes ride through `MediaItem` into the delivery/media API (`urls.crops`), so
   a front end drops the right-shaped image straight into an `<img>`. Empty by
   default: a site that declares none keeps just the responsive ladder and the
   square. `CropBox`/`CoreConfig` parse leniently, so one malformed entry costs
   that crop, not the set. (Editor-facing crop previews in the media library
   remain future polish — crops are a config/delivery concern, and the focal-point
   editor that drives them already exists.)

6. ~~**Relation ergonomics.**~~ *(small–medium)* **Done.** Back-references —
   "which posts point at this author?" — are answered by a new
   `BackReferenceService` that scans, on demand, only the collection types whose
   schema declares a reference field pointing at the target (no stored index for
   a flat-file write to keep consistent). A `GET …/entries/:slug/backreferences`
   route (authenticated, since it may surface drafts) feeds a "Referenced by"
   panel in the entry editor. Ordering within a many-reference is now editable:
   the reference picker's chips carry accessible up/down move controls, and the
   stored order is the delivery order.

7. ~~**Marketplace: decide or drop.**~~ *(varies)* **Kept and hardened.** The
   install path was exercised end to end and threat-modelled, and it had real
   holes:
   - **Zip Slip.** `installFromZip` called `ZipArchive::extractTo` with no entry
     validation — a crafted archive could write a PHP shell into the document
     root. Extraction now validates every entry (no absolute paths, no `..`, no
     backslashes/NUL, no drive letters), writes each file itself (so an archived
     symlink becomes an inert file, never a live link), caps entry count and
     total inflated size against a zip bomb, and extracts to a same-filesystem
     temp dir so the install is an atomic rename.
   - **Missing authorization.** The controller's docstring claimed the guard
     enforced an install capability; nothing did — any signed-in account could
     install code. The kernel now gates browsing on `ManagePlugins` and
     installing on `InstallPlugins`, both administrator-only, on top of the
     existing auth and CSRF.
   - **Signed-id integrity.** A registry install now requires the package to
     install under the same id the signed manifest vouched for, so a valid
     signature for one plugin cannot smuggle in different code.

   Covered by a new end-to-end test (generated keypair, `file://` registry →
   signed manifest → checksummed package → safe extraction → install) plus Zip
   Slip, oversize, id-mismatch and capability-gate tests.

## Depth and adoption

8. **More builder node types.** *(incremental)* Columns/containers, video,
   embed, list, quote, divider, and reusable blocks or templates.

9. **Collaboration review workflow.** *(medium)* The request-review →
   approve → publish-together flow beyond presence and comments, with optional
   notifications. **Live cursors are explicitly not planned:** they need a
   real-time transport (SSE/WebSocket) that conflicts with the zero-dependency,
   shared-hosting constraint. Polling presence is the deliberate ceiling, not a
   stepping stone.

10. **Adoption and DX.** *(varies)* ~~A second theme beyond the default~~ (done —
    `themes/dark`, alongside a real theme system), a documentation site, an
    installation/quick-start guide, an example content seeder, and a published
    Docker image.

11. **A render cache.** *(medium)* Under load, every request reads files. A
    page/render cache invalidated on publish keeps a flat-file site fast without
    adding a runtime dependency (the cache is itself flat files).

12. **Accessibility audit.** *(small–medium)* An automated a11y pass over the
    admin UI and the default public theme, fixing what it finds.

## Shipped since this list was written

Not on the original roadmap, but done — recorded so the list stays an honest
picture of where the project is rather than only where it was going:

- **Storage backends.** MySQL/MariaDB and PostgreSQL joined JSON and SQLite, all
  four behind one shared contract test, so switching is a config change.
- **A theme system.** Themes as packages in `themes/`, discovered, switchable
  from the admin, with cache-busted stylesheet URLs. Closes most of the Theming
  section in `backlog.md`.
- **Self-update.** A signed release feed with freeze and rollback defences and
  key rotation, a policy dial (`security` by default: security fixes install
  themselves, everything else waits for an administrator), an installer that
  verifies before touching anything and can roll back, and a cron entry point.
  Published from GitHub Actions; documented in `docs/updates.md`.
- **Media.** Art-directed crops, and video (MP4/WebM, byte-range served).
- **Marketplace hardening.** A Zip Slip vulnerability and a missing
  authorization gate, both found by exercising the install path rather than
  reading it.

## Choosing what is next

- Prefer items that unblock a real site over depth for its own sake — that is why
  CI, builder chrome and delivery pagination lead.
- Anything touching a public API response or a stored shape waits for a v2
  branch; keep v1.x releases additive.
