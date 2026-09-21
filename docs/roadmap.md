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
   `ci.yml` workflow runs the PHP suite (on PHP 8.5, the runtime version), the
   admin suite (on Node 22), a job that proves the project still runs on PHP 8.1
   — the floor `composer.json` and every entry in the update feed promise — and a
   container smoke test that builds the image and polls `/health.php`, on every
   push and pull request. Jobs are independent and fail in isolation; in-flight
   runs are cancelled when a branch is superseded.

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
   that crop, not the set. Editor-facing crop thumbs on each media library card
   also ship (see item 20).

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

8. **More builder node types.** *(incremental)* ~~Columns/containers, video,
   embed, list, quote, divider~~ (done — all six, server-rendered and editable;
   the embed node builds its iframe from an allowlisted URL rather than
   rendering author markup, and the work closed a stored-XSS hole in the
   existing button and image nodes). ~~**Reusable blocks**~~ (done — snapshot-
   only named blocks under `data/builder-blocks`, deep-copied on insert so
   editing a saved block never rewrites pages that already used it; see item
   17). Templates beyond that remain open if a site wants live references.

9. ~~**Collaboration review workflow.**~~ *(medium)* **Done for the editor
   panel, the open-reviews inbox (with a requester waiting-list filter), and the
   release screen.** Request → approve → request-changes → cancel sits on the
   page editor against the existing collaboration API and publish gate;
   `/admin/reviews` lists every review still open and can narrow to those
   requested by the signed-in account; `/admin/release` publishes a chosen set
   together for accounts with `content.publish`. **Live cursors are explicitly
   not planned:** they need a real-time transport (SSE/WebSocket) that conflicts
   with the zero-dependency, shared-hosting constraint. Polling presence is the
   deliberate ceiling, not a stepping stone.

10. **Adoption and DX.** *(varies)* ~~A second theme beyond the default~~ (done —
    `themes/dark`, alongside a real theme system), ~~an installation/quick-start
    guide~~ (done — `docs/install.md`, plus a README corrected of several claims
    that were simply untrue), ~~an example content seeder~~ (done —
    `bin/click-seed.php`, which writes through the same services the admin UI
    posts to and never overwrites), ~~a published Docker image~~ (done — GHCR on
    release, multi-arch, smoke-started by the workflow that publishes it. This
    was recorded as done long before it was true: the workflow existed and had
    never once succeeded, because the admin suite timed out under emulation
    while building the arm64 layer. The first image actually reached the
    registry with 1.2.1 on 2026-07-25).
    Releases now also attach an install archive, because the existing package is
    an upgrade package and cannot be installed from.

    ~~**Still open:** a documentation site.~~ Done — the guide is generated by
    `scripts/docs/build-site.php` and published to GitHub Pages at
    <https://felixgeelhaar.github.io/click-cms/>, with screenshots captured from
    a real seeded instance by `scripts/screenshots/capture.mjs`.

11. ~~**A render cache.**~~ *(done)* Rendered public pages are stored as flat
    files under `data/cache/pages`, **off by default**. Invalidation is a
    storage decorator rather than a call in each handler, so no code path can
    change a document and forget to clear; non-content admin writes are covered
    by a blanket flush. Previews and signed-in renders are excluded on both
    sides. What it cannot see — a `web.render` plugin varying on anything not in
    the key, and section schemas edited on disk — is stated in `core.md` rather
    than papered over.

12. ~~**Accessibility audit.**~~ *(done)* axe-core runs over every admin screen
    as part of the suite. Five defects it found and eight it could not (Login's
    placeholder-only labels and suppressed focus outline, an ARIA-prohibited
    label on a drag handle no keyboard could reach, a missing skip link) are
    fixed, along with five WCAG contrast failures. The one forced visual change
    is a darker border on form controls, whose background matches the surface
    behind them and which therefore had no compliant boundary at all.

## Next — product and reliability

Ordered by how much they matter to a site already running on click-cms. Items
marked *shipped in this cycle* are recorded so this list stays honest.

13. ~~**Editing-mode site policy.**~~ *(done)* A site can turn free-form editing
    off entirely (`Settings::freeformEditing`), and `PageService` refuses a
    `builder` payload when the site flag or `UseFreeFormBuilder` capability
    says no. The Builder nav follows the same gate. Still open under this
    heading: expressing free-form **as a section type** so constrained and
    free-form share one editor shell (large; see backlog).

14. ~~**First-run dashboard and in-app seed.**~~ *(done)* Empty sites get a
    first-run panel; `POST /api/seed` runs the never-overwrite example seeder
    for administrators; fetch failures no longer look like zeros.

15. ~~**Marketplace upload path.**~~ *(done)* `POST /api/marketplace/upload` is
    wired and hardened; catalogue returns `registryConfigured` instead of a
    false error banner on a fresh install.

16. ~~**Plugin discovery diagnostics.**~~ *(done)* Invalid `plugin.json` and
    bootstrap-without-manifest folders are reported on `GET /api/plugins` and
    shown on the Plugins page.

17. ~~**Reusable builder blocks (snapshot).**~~ *(done)* Save a selection as a
    named block; insert by deep-copy. No live references in v1.x — changing a
    saved block must not rewrite pages that already used it.

18. ~~**Collaboration review UI.**~~ *(done)* Review panel on the page editor
    (request, approve, request-changes, cancel), an open-reviews inbox at
    `/admin/reviews` when the collaboration plugin is installed (including a
    "Requested by me" waiting-list filter), and a release screen at
    `/admin/release` for accounts with `content.publish`.

19. ~~**Theme install from admin.**~~ *(done)* Upload a theme ZIP into `themes/`
    with the same Zip-Slip defences the marketplace uses; activate remains as
    today. Plugin-supplied themes stay later.

20. ~~**Media crop previews.**~~ *(done)* Show declared art-directed crop thumbs
    on each media library card (`urls.crops` already exists).

21. ~~**Docker cron profile.**~~ *(done)* `docker compose --profile cron up`
    runs `click-schedule`, `click-webhooks`, `click-update` and `click-backup`
    on a loop (`docker/cron-loop.sh`) so production is not "remember to install
    cron on the host".

22. ~~**Structured API error envelope.**~~ *(started, additive — further along)* Known
    faults MAY include a machine `code` beside the existing human `error` string
    (`Http\ApiFault`). Themes opted in first; Seed, BuilderBlocks, Marketplace,
    Updates, Settings, Menus and Plugins now do too. Other controllers can follow
    without breaking clients that only read `error`. Blank 500s for unknown faults
    stay opaque on purpose.

23. **Kernel / route decomposition.** *(in progress)* Continue peeling
    identity, settings and marketplace-sized concerns out of
    `Application.php` / `CoreApiRoutes.php` toward application services — no
    behaviour change, less accumulation. `SettingsController`, `SiteController`
    and `AuditController` are peeled: settings, site identity and the audit
    trail are only reached through their controllers. Earlier this cycle:
    `BuilderBlocksController`, `ThemeInstaller`, `Http\ApiFault` (themes first)
    and `MarketplaceController` (enablement + ManagePlugins / InstallPlugins
    gates moved out of `Application` / `ApiGuard`). Next peel candidates:
    thinning `CoreApiRoutes` further — `MediaController` owns `/api/media*`;
    `PagesController` now owns pages CRUD, publication, schedule, versions and
    preview. `CoreApiRoutes` keeps section-types for this pass.

24. ~~**Admin coverage and smoke.**~~ *(done for v1.x CI)* Vitest coverage for
    Users, Webhooks, Redirects and the admin deep-link `<base>` injector;
    PHPUnit smoke for every admin surface's read API plus publish. A full GUI
    tour of every sidebar screen was exercised manually; deep-link blank pages
    (`/admin/pages/edit/…`) were fixed by injecting `<base href="…/admin/">`
    so relative `./_astro` assets resolve after a hard refresh. Optional local
    Playwright via `CLICK_E2E=1` (`npm run test:e2e`); CI also runs an `e2e`
    job (seed + built admin + chromium smoke). Complements the axe suite.

25. ~~**Doc honesty.**~~ *(done)* Prune stale backlog claims; `admin-ui/README.md`
    matches what ships (Astro + Vue 3, Sora / Source Sans 3 — no D3, no Inter,
    no “future media/users/plugins” checklist for screens that already exist).

## Explicitly declined or parked

- **Live cursors** — need a real-time transport; conflicts with shared-hosting.
- **MongoDB / NoSQL backends** — break zero-runtime-dependency posture; niche
  is already covered by JSON → SQLite → MySQL/Postgres (see backlog).
- **Free-form as a section type** — right long-term model; large enough to be
  its own milestone after the site policy gate.

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
- **Editing-mode policy, first-run seed, marketplace upload, plugin discovery
  diagnostics** — see items 13–16 above.

## Choosing what is next

- Prefer items that unblock a real site over depth for its own sake — that is why
  CI, builder chrome and delivery pagination lead.
- Anything touching a public API response or a stored shape waits for a v2
  branch; keep v1.x releases additive.
- Work the numbered "Next" list in order unless a real site is blocked on
  something lower down.
