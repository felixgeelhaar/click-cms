# Click CMS

A modern PHP CMS with plugin architecture, Vue admin UI, Visual Builder, and marketplace.

![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=flat&logo=php)
![Vue](https://img.shields.io/badge/Vue-3-4FC08D?style=flat&logo=vuedotjs)
![Astro](https://img.shields.io/badge/Astro-FF5A03?style=flat&logo=astro)

## Features

- **Plugin Architecture** - Eight plugins ship with it; the CMS boots with none
- **Visual Builder** - Drag-and-drop page builder with responsive layouts
- **Modern Admin UI** - Vue 3 + Astro with collapsible sidebar, theme toggle
- **REST & GraphQL APIs** - Headless content management
- **Scheduled publishing** - a page goes live, and comes down, at a stated time
- **Two-step sign-in** - TOTP, with recovery codes
- **Single sign-on** - OpenID Connect against Entra ID, Google, Okta, Keycloak
- **Webhooks** - signed, queued and retried, so a static front end knows to rebuild
- **Multi-site** - one installation, many sites; shared code, separate content
- **Marketplace** - GitHub Pages-based plugin marketplace with signed manifests
- **Zero runtime dependencies** - `composer.json` requires PHP and nothing else
- **Five storage backends** - flat JSON files by default; SQLite, MySQL, MariaDB
  or PostgreSQL when a site outgrows them

## Quick Start

The fastest route to a running site:

```bash
docker run -d -p 8080:80 \
  -v click-cms-content:/var/www/html/content \
  -v click-cms-data:/var/www/html/data \
  ghcr.io/felixgeelhaar/click-cms:latest
```

From source instead:

```bash
composer install
cd admin-ui && npm install && npm run build && cd ..
php -S localhost:8080 -t public
```

- **Public Site**: http://localhost:8080
- **Admin UI**: http://localhost:8080/admin/
- **API**: http://localhost:8080/api/

Sign in as `admin` / `admin`. The account can do exactly one thing until that
password is replaced: replace it.

A fresh install has no content, so nothing on screen shows you what a section or
a collection is. Fill it in with a small example site:

```bash
php bin/click-seed.php --dry-run   # what it would create
php bin/click-seed.php             # create it
```

It never overwrites — anything already there is skipped — so it is safe to run
twice, and safe on a site that already has content.

For admin UI development, run the Astro dev server alongside PHP and use
http://localhost:4321/admin/ instead:

```bash
cd admin-ui && npm run dev
```

**[Full installation guide →](docs/install.md)**

## Storage

Content is stored as flat JSON files by default — no database, nothing to
install. To use SQLite instead, set the backend in `config/core.json`:

```json
{
  "core": {
    "storage": {
      "backend": "sqlite",
      "sqlite": { "path": "data/content.sqlite" }
    }
  }
}
```

`backend` accepts `json`, `sqlite`, `mysql`, `mariadb` and `postgres`. Each
database backend needs its matching PDO extension. An unrecognised backend, or
one whose extension is missing, fails at boot with a message saying what to
change — it never falls back silently.

Switching the setting does not move existing content; move it first with
`php bin/click-migrate-storage.php json sqlite`, which is safe to re-run. See
[`docs/core.md`](docs/core.md) for the details.

## Plugins

Eight ship with the project. The CMS boots with `plugins/` deleted entirely —
that is the test of whether the line between core and plugin is drawn honestly.

| Plugin | What it does |
|---|---|
| `admin-ui` | The web admin panel |
| `visual-builder` | Drag-and-drop page builder with responsive layouts |
| `graphql` | A GraphQL endpoint for content |
| `search` | Full-text search over published pages, at `GET /api/search` |
| `forms` | Contact forms; stores submissions and lists them for editors |
| `collaboration` | Presence and page comments, for review before publishing |
| `backup` | A ZIP export of content, media and drafts |
| `webhooks` | Signed HTTP deliveries when content changes, queued and retried |

Authentication (including two-step sign-in and single sign-on), languages,
media, redirects, menus, version history, preview, scheduled publishing and the
REST API are **core**, not plugins — see [`docs/core.md`](docs/core.md) for
why each one cannot be removable.

## Architecture

```
click/
├── src/                    # Core application
│   ├── Application/        # Application services
│   ├── Core/               # Main Application class
│   ├── Domain/             # Domain models, no I/O
│   ├── Http/               # Kernel, controllers, renderers
│   └── Infrastructure/     # Storage, media, adapters
├── plugins/                # The eight bundled plugins
├── admin-ui/               # Vue 3 + Astro admin
├── themes/                 # Public themes
├── config/                 # Core settings, section and collection types
├── content/                # A site's documents and media (gitignored)
├── data/                   # Sessions, history, audit trail, caches (gitignored)
├── sdk/                    # PHP plugin SDK and a generated TypeScript client
├── bin/                    # CLI tools (seed, migrate storage, update,
│                        #   schedule, webhooks, sites)
└── public/                 # Web root — the only directory served
```

## Development

```bash
composer test          # the PHP suite
composer test:admin    # the admin UI suite
composer lint          # syntax-check every PHP file
composer seed          # fill a fresh install with the example site

cd admin-ui && npm run build   # build the admin UI
```

## Documentation

- [Installation and first run](docs/install.md) — Docker, release archive or source
- [What belongs in core](docs/core.md) — the line between core and plugin, storage
  backends, draft and publish, the render cache
- [Updates](docs/updates.md) — the signed release feed and update policies
- [Visual builder](docs/visual-builder.md) — the node document model
- [Collaboration](docs/collaboration.md) — presence and review comments
- [Publishing](docs/publishing.md) — saving vs publishing, and scheduling
- [Two-step sign-in](docs/two-factor.md) — turning it on, and the recovery codes
- [Single sign-on](docs/sso.md) — OpenID Connect setup, and what is checked
- [Webhooks](docs/webhooks.md) — the payload, the signature, verifying it
- [More than one site](docs/multi-site.md) — shared code, separate content
- [Practices](docs/practices.md) — how this codebase is worked on

## License

MIT
