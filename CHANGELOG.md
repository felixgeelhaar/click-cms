# Changelog

All notable changes to click-cms are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### A site can be installed in a subdirectory
- `example.com/2026/cms/` now works, with no configuration. Everything used to
  assume a domain root: routes were matched against the raw request path, so a
  request under a subdirectory matched nothing at all, and every URL the CMS
  handed out started at `/`. That left the claim that this runs on ordinary
  shared hosting only half true — a subdirectory is the normal shape there.
- The prefix is detected from the request, so unzipping the archive into a
  directory is the whole installation step. `core.basePath` states it outright
  where that is preferred; an empty value there means the domain root and is
  honoured rather than read as unset.
- Behind a reverse proxy — the one arrangement a request cannot show, since the
  script sits at one path and the site is published at another — the CMS reads
  `X-Forwarded-Prefix`, but **only from a proxy the site has named** in
  `core.trustedProxies` (addresses or CIDR ranges). Nobody is trusted by
  default. The header is written by whoever sent the request and its value lands
  in every URL the site emits, so an unnamed sender who was believed could
  rewrite every link on a page — and a cached render would then serve their
  version to everyone else. A value that is not a path is refused even from a
  trusted proxy, on the grounds that a misconfigured proxy is likelier than a
  hostile one.
- Hosting without `mod_rewrite` works too: `/2026/cms/index.php/api/pages`
  resolves to the same route a rewriting host produces.
- The admin UI is one build that runs at any prefix — its assets are addressed
  relative to the document, and its requests and links pick the prefix up at
  runtime. No per-installation build, which would put a build step back into
  deployment.
- **`CLICK_CMS_ROOT`** names the installation's directory when it is not the one
  above `public/`. That is how `content/`, `data/` and `config/` stay out of a
  document root that cannot be moved. It is an environment variable — a vhost
  `SetEnv`, `.user.ini`, an FPM pool — precisely because an update replaces
  `public/`, so an edit to `index.php` would not survive one.
- Nothing changes for a site at a domain root: with no prefix, every path is
  emitted exactly as it was.

## [1.3.0] — 2026-07-25

No change to any shipped PHP file. A minor release rather than a patch because
the environment plugins execute in moves two versions, which is worth a visible
signal even though nothing in this project's own API changed.

### The container runs the current PHP
- The runtime image moves from `php:8.3-apache` to `php:8.5-apache`, and CI, the
  release and Pages tooling, and the marketplace registry workflow move with it.
  Both images are Debian 13, so the runtime stage's library pins are unchanged.
- Verified by building and exercising the image rather than by reading a version
  string: every extension loads, the seeded site serves, and signing in,
  uploading, publishing and rendering produced no deprecation, warning or fatal.
- **If you run custom plugins, this is the change to read.** They now execute on
  8.5. Anything relying on behaviour removed in 8.4 or 8.5 will fail there and
  did not before.

### The oldest PHP we promise is now tested
- `composer.json` says `php: >=8.1`, the README badge says 8.1+, and every entry
  in the signed update feed advertises `requiresPhp: "8.1"` — which is what an
  installation checks before it will accept an upgrade. Nothing tested any of
  it, and with the suite running on the latest PHP a feature from a later
  version could have shipped, with the first person to find out being someone on
  8.1 whose site stopped booting.
- A CI job parses every shipped file on 8.1 and then boots the application and
  asks for a rendered page. Both halves earn their place: `json_validate()`,
  added in 8.3, is valid 8.1 syntax and passes the parse step, and fails the
  boot step. The floor holds — 8.1.34 serves the seeded site.

## [1.2.1] — 2026-07-25

Nothing a site runs changes. This exists because 1.2.0 could not publish a
container image, and neither could 1.0.0 or 1.1.0 — so this is the first
release that actually ships one.

### Fixed
- The release build publishes a `linux/arm64` image alongside `amd64`, which on
  an amd64 runner means QEMU. Every accessibility test took between 5.5 and 11
  seconds there, nine of them passed Vitest's 5-second default, and the image
  build failed reporting nine accessibility failures when nothing was
  inaccessible. Both prior releases failed the same way and nobody noticed,
  because the release itself succeeded and only the image was missing.
- CI's PHP job had been red since 23 July, including through 1.1.0: a dev
  dependency required PHP 8.4 while the job runs 8.3 to match the runtime image,
  so `composer install` failed and the suite never ran there at all. It passed
  for anyone whose local PHP happened to be newer. Verified now inside a
  `php:8.3-cli` container — 1918 tests, all passing — and every shipped file
  still parses on 8.1, which is the version the update feed advertises.

## [1.2.0] — 2026-07-25

A minor release whose most consequential change is a bug fix. Sites running any
earlier version should upgrade: the session store could hand a signed-in person
a 401 under ordinary concurrent use, and everything else here is smaller than
that.

### Sessions no longer tear under concurrency
- The store wrote with `file_put_contents(..., LOCK_EX)`, which truncates the
  target when it opens it and only then takes the lock — while reads took no
  lock at all. A reader landing in that window decoded an empty file and was
  told nobody was signed in. Every authenticated request records activity, so a
  single admin page load raced itself.
- Measured before the fix: **12 of 160 concurrent requests returned 401 on a
  session that was valid throughout**. It never looked like an auth bug. It
  looked like the media picker reporting an empty library, the comments panel
  refusing permission, and the section list failing to load — a CMS that is
  intermittently and inexplicably flaky.
- Writes are now staged next to the target and renamed into place, which is
  atomic within a directory on POSIX. Re-measured over HTTP afterwards: 630
  concurrent requests, no spurious 401s.

### Video, and picking files without knowing their names
- `FieldType::File` had no case in the renderer, so a file field printed its own
  stored reference as prose — a page showing `clip-4f2a91c0` where a film
  belonged. The media library had accepted MP4 and WebM for some time, so an
  editor could upload a film, see it in the library, and had no way at all to
  put it on a page. A **`video`** section design now ships and a file field
  renders a player: controls, `preload="none"`, never autoplay, and a poster
  named by a sibling field so the page does not open on a black rectangle.
- A file field is **chosen from the library** rather than typed. It had no
  editor of its own and fell through to the generic text input, so adding a clip
  meant copying an opaque id from the Media page and pasting it in, with a typo
  producing a silently empty section.
- `GET /api/media` accepts **`?kind=image|video`**. Without it every picker
  listed the whole library, so a film appeared in the image chooser as a broken
  thumbnail and could be selected into a slot that renders an `<img>`.

### Structure the markup could not previously express
- A field may declare **what it is** — `heading`, `subheading`, `quote`, `note`
  on prose, `ordered` or `definitions` on a repeater. Element choice used to
  come entirely from a field's name and type, which is why a testimonial could
  not be a `<blockquote>` and opening hours could not be a `<dl>`. Declaring
  nothing renders byte-identically to before.
- A **`list`** field type, which a repeater cannot contain a repeater to
  express. A plan's "what's included" was a textarea rendering one paragraph of
  `<br>`-separated lines: a list to look at, and not a list to anything reading
  the document.
- A select **inside a row** now marks that row, as one at section level marks
  the section — the only way "the plan we recommend" can reach the markup.

### Every public page has a document outline
- No page had an `<h1>`. The title existed only in `<title>`, so every page
  opened at level two with nothing above it.
- A row title inside a repeater was an `<h2>`, making each card a sibling of the
  heading that introduced it rather than a child. Pages now read H1 → H2 → H3
  with no skipped levels.

### Collections and review
- **A collection entry can be previewed before it is published.** The preview
  route matched only a single-segment page slug, so an author could draft a post
  and nobody — the author included, and more to the point whoever had to approve
  it — could see it rendered. For a CMS whose author role exists to draft work
  for someone else to release, that was the review step missing its subject.
- A token minted for one entry does not open another, so a shared review link
  cannot expose every draft on the site.

### Plugins
- **Five authentication hooks.** `auth.before_login` is vetoable, which is what
  makes a second factor possible; it fires behind both the spray ceiling and the
  lockout, after the password check and before any session exists. No password,
  hash, email, CSRF token or session id reaches a listener.
- A failed sign-in reports one reason for a wrong password, an unknown account
  and an account with no usable hash, because the caller gets one 401 for all
  three. Handing a plugin the difference would make every listener an
  enumeration oracle.

### Fixed
- A link inside a repeater row showed its own address: a card rendered
  `<a href="/tables">/tables</a>`, offering a visitor the raw path as the words
  to click. Rows honour `labelField` now, falling back to the row's title rather
  than to the address.
- The two Pages workflows declared different concurrency groups while a comment
  claimed they shared one. On the first real release a documentation push and
  the release deployed concurrently, the earlier one landed last, and a feed
  built before the tag existed — offering zero releases — replaced the correct
  one. Nothing went red; the feed was valid, signed, and advertised no upgrade.
- The media library rendered a video through the image card: a broken `<img>`,
  an "×" where the dimensions belong, a focal point with no crop to set, and "no
  resized versions" reading as a failure rather than as how video works here.
- Both media pickers reported a *failed request* as an empty library, sending
  someone to upload files they already had.
- `admin-ui/src/components/Media.vue` contained a raw NUL byte inside a string
  constant. Legal JavaScript, invisible in an editor, and it made the file binary
  to `grep`, `diff` and every other text tool — searches for anything in it
  silently returned nothing.
- The section catalogue in `config/sections/README.md` omitted a design that
  ships. A test now compares the table against `config/sections` both ways.

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
