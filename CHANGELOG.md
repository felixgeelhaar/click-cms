# Changelog

All notable changes to click-cms are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [1.1.0] — 2026-07-25

A minor release, not a major one, despite `StorageInterface` gaining a method:
the docblock claiming plugins supplied a backend via a `storage.init` hook
described a hook nothing has ever fired, so no third-party implementation was
reachable and none can have broken. That claim is corrected rather than left
standing.

### Collections appear on the public site
- The gap that made the feature half a feature: entries could be created,
  published and referenced, and then appeared nowhere. A collection type may now
  declare a **`route`** in its own definition — `post` ships with `blog`, so a
  post is readable at `/blog/why-we-stopped-staining`. A collection with no route
  has no public address and stays admin-only, which is `team-member` by intent.
- A **Collection list** section lists a collection's published entries, reading
  title, summary and picture from the collection's own schema.
- A page always wins a contested path, a draft entry answers 404
  byte-identically to an address that never existed, and an empty listing renders
  nothing rather than a bare heading.

### Nine more section designs
- Questions and answers, prices, a testimonial, a gallery, people, a details
  list, logos, a section heading, and the collection listing above. Fifteen in
  total, all demonstrated on the seeded example site.

### A wider plugin surface
- Four hooks became nine: `content.before_save` and `content.before_delete` are
  vetoable; `content.saved`, `content.deleted` and `content.unpublished` are
  announcements. Fired from a storage decorator, so no write can miss them, and
  costing 0.15µs per write when nobody listens.
- The actor in an event payload is reduced to username and role by an allowlist,
  so a plugin never sees a password hash.

### Editors and documentation
- A guide written for people who are not developers, with screenshots of every
  admin screen, split by what each role can actually see. Published at
  <https://felixgeelhaar.github.io/click-cms/>.
- Links to your own pages are **chosen from a list** rather than typed from
  memory, in menus and in section link fields.

### Operations
- A **backup** that contains the site on any storage backend, deduplicating
  media by content hash and verifying an archive before restoring any of it.
- A **render cache** for public pages, off by default, invalidated at the
  storage layer.
- `types()` on the storage port, so "everything in this site" is expressible.

### Fixed
- **The project had no licence file** while `composer.json` and the README both
  claimed MIT — legally, all rights reserved. Every release archive shipped
  without one, silently, because the copy was followed by `|| true`.
- A stored-XSS hole in the visual builder: the button and image nodes escaped
  their URLs but never scheme-checked them, so `javascript:` survived intact.
- The publication banner reported an unsaved page over work that was published,
  and told administrators they could not publish, whenever data had not yet
  arrived.
- A failed request made every section on a page announce that its design was
  "no longer declared" and dump the content as JSON.
- The Profile screen never saved anything and said it had.
- A top-level image had no width rule while the renderer wrote its intrinsic
  size, so a real photograph pushed the page sideways.
- A plugin refusing content answered 500; it now answers 409 and names the hook.
- `visual-builder.schema.json` had never been valid JSON.
- Release archives now include a separate installable package — the previous one
  omitted `config/` and could only be used to upgrade.

## [1.0.0] — 2026-07-23

The first stable release. click-cms is a flat-file CMS with **zero runtime
dependencies** (it runs on ordinary shared hosting: PHP 8.1+, no database
required), built on strict domain-driven layering and covered by 810 PHP and
151 admin-UI tests.

### Content & editing
- Pages composed from schema-driven sections, with a dependency-free rich-text
  editor and server-side HTML sanitising.
- **Collections** — repeatable content types (blog posts, team members, …)
  defined as JSON field schemas. Entries inherit draft/publish, history and
  audit because each is an ordinary content document. Includes declared
  **ordering** and single/many **relations** (a `reference` field linking to
  other entries or pages, resolved to titles at read time).
- **Draft and publish** per language, with version history and a way back to any
  previous version.
- **Preview** of unpublished changes through signed, expiring links.
- **Internationalisation** — a locale is part of every content key; documents
  are per-language.
- SEO metadata; a **visual builder** for free-form page layouts.

### Media
- Uploads stored outside the document root, with responsive variants generated
  at upload time, focal points, and strict SVG sanitising.
- A library that scales: filename search, virtual folders, and bulk delete.
- Quality warnings when an upload is too small for the size it will be shown at.

### Navigation & structure
- A rendered public site header (brand, active state, dropdowns, mobile menu,
  sticky) driven by an editable menu.
- A grouped, collapsible admin sidebar with a mobile drawer.
- Redirects, and drag-and-drop reordering of sections and repeater rows.

### Delivery & extensibility
- Renders its own site, or runs **headless** — serving content only through the
  delivery API — at the flip of a runtime setting.
- Editorial plugins: forms (with honeypot), full-text search, backup, GraphQL
  delivery, and polling-based **collaboration** (presence + review comments).
- A plugin system with discovery, lifecycle, events and hooks.

### Platform & security
- Two storage backends (flat-file JSON and SQLite) behind one contract, with a
  versioning/audit decorator stack and a **capability check at the storage
  boundary**. A CLI migrates content between backends.
- Deny-by-default API guard, CSRF on state-changing requests, a forced
  first-login password change, one file per session, and per-owner content
  permissions.
