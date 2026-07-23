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

5. **Media cropping.** *(medium)* Focal points cover the common case; add
   optional server-side cropping to fixed boxes for art-directed images. This was
   deliberately deferred at v1 — object-position handled the 80%.

6. ~~**Relation ergonomics.**~~ *(small–medium)* **Done.** Back-references —
   "which posts point at this author?" — are answered by a new
   `BackReferenceService` that scans, on demand, only the collection types whose
   schema declares a reference field pointing at the target (no stored index for
   a flat-file write to keep consistent). A `GET …/entries/:slug/backreferences`
   route (authenticated, since it may surface drafts) feeds a "Referenced by"
   panel in the entry editor. Ordering within a many-reference is now editable:
   the reference picker's chips carry accessible up/down move controls, and the
   stored order is the delivery order.

7. **Marketplace: decide or drop.** *(varies)* The plugin marketplace is present
   but unexercised. An unused path that installs arbitrary (signed) code is a
   liability, not a feature. Either commit to it — exercise it end to end and
   threat-model the install path — or remove it.

## Depth and adoption

8. **More builder node types.** *(incremental)* Columns/containers, video,
   embed, list, quote, divider, and reusable blocks or templates.

9. **Collaboration review workflow.** *(medium)* The request-review →
   approve → publish-together flow beyond presence and comments, with optional
   notifications. **Live cursors are explicitly not planned:** they need a
   real-time transport (SSE/WebSocket) that conflicts with the zero-dependency,
   shared-hosting constraint. Polling presence is the deliberate ceiling, not a
   stepping stone.

10. **Adoption and DX.** *(varies)* A second theme beyond the default, a
    documentation site, an installation/quick-start guide, an example content
    seeder, and a published Docker image.

11. **A render cache.** *(medium)* Under load, every request reads files. A
    page/render cache invalidated on publish keeps a flat-file site fast without
    adding a runtime dependency (the cache is itself flat files).

12. **Accessibility audit.** *(small–medium)* An automated a11y pass over the
    admin UI and the default public theme, fixing what it finds.

## Choosing what is next

- Prefer items that unblock a real site over depth for its own sake — that is why
  CI, builder chrome and delivery pagination lead.
- Anything touching a public API response or a stored shape waits for a v2
  branch; keep v1.x releases additive.
