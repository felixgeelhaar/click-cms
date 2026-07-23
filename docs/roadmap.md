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

1. **Continuous integration for the test suites.** *(small)* There is no
   workflow that runs the 810 PHP and 151 admin tests; the only workflow is the
   marketplace registry. A released project must not regress silently — add a
   GitHub Actions workflow that runs both suites (and ideally the container
   smoke test) on every push and pull request, and require it before merge.

2. **Builder pages bypass the site chrome.** *(medium)* The visual-builder
   plugin renders a standalone HTML document, so a page built with the builder
   has **no navigation, no SEO metadata, and no theme**. Route builder output
   through the same `<head>`/header wrapper ordinary pages use — extract a shared
   page shell so both the section renderer and the builder produce a full,
   navigable, indexable page.

3. **Delivery pagination and filtering.** *(medium)* Collection `/published`
   (and page delivery) return every item. A blog with hundreds of posts needs
   `?limit`/`?offset` (or a cursor) and simple field filtering, so a front end
   is not forced to fetch and discard. Additive to the API — safe within v1.x.

## Rounding out what shipped

4. **Collections admin depth.** *(medium)* Entries already inherit version
   history, preview and per-language documents at the storage layer, but the
   collection entry editor exposes none of them — unlike the page editor. Wire
   history, signed preview links and translation switching into the collection
   admin so an entry is as fully editable as a page.

5. **Media cropping.** *(medium)* Focal points cover the common case; add
   optional server-side cropping to fixed boxes for art-directed images. This was
   deliberately deferred at v1 — object-position handled the 80%.

6. **Relation ergonomics.** *(small–medium)* Back-references (which posts point
   at this author?) and ordering within a many-reference. Both build directly on
   the reference field added late in v1.

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
