# Changelog

All notable changes to click-cms are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

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
