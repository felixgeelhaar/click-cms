# Click CMS

A modern PHP CMS with plugin architecture, Vue admin UI, Visual Builder, and marketplace.

![PHP](https://img.shields.io/badge/PHP-8.5+-777BB4?style=flat&logo=php)
![Vue](https://img.shields.io/badge/Vue-3-4FC08D?style=flat&logo=vuedotjs)
![Astro](https://img.shields.io/badge/Astro-FF5A03?style=flat&logo=astro)

## Features

- **Plugin Architecture** - Extend functionality with 40+ built-in plugins
- **Visual Builder** - Drag-and-drop page builder with responsive layouts
- **Modern Admin UI** - Vue 3 + Astro with collapsible sidebar, theme toggle
- **REST & GraphQL APIs** - Headless content management
- **Marketplace** - GitHub Pages-based plugin marketplace with signed manifests
- **Two Storage Backends** - flat JSON files by default, SQLite when you want one

## Quick Start

```bash
# Install dependencies
composer install
cd admin-ui && npm install

# Start development servers

# Terminal 1: PHP server
php -S localhost:8080 -t public

# Terminal 2: Admin UI (Vue dev server)
cd admin-ui && npm run dev
```

- **Public Site**: http://localhost:8080
- **Admin UI**: http://localhost:4321/admin/
- **API**: http://localhost:8080/api/

### Demo Credentials

- Username: `admin`
- Password: `admin`

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

`backend` accepts `json` or `sqlite`; SQLite additionally needs PHP's
`pdo_sqlite` extension. An unrecognised backend, or SQLite without the
extension, fails at boot with a message saying what to change — it never falls
back silently. Switching backends does not migrate existing content. See
[`docs/core.md`](docs/core.md) for the details.

## Plugins

### Core Plugins (Pre-installed)
- **REST API** - RESTful content API
- **GraphQL API** - GraphQL endpoint
- **Visual Builder** - Drag-and-drop page builder

### Available Plugins
- Authentication & 2FA
- SEO Optimization
- Image Optimization
- Cache (Redis/Memcached)
- Forms Builder
- Comments System
- Tags & Categories
- Custom Fields
- Content Blocks
- Multi-language (i18n)
- Email/SMTP
- Webhooks
- Scheduled Publishing
- Rate Limiting
- Redirects
- And more...

## Architecture

```
click/
├── src/                    # Core application
│   ├── Application/        # Application services
│   ├── Core/               # Main Application class
│   └── Domain/             # Domain models
├── plugins/                # Plugins (40+)
│   ├── rest-api/
│   ├── graphql/
│   ├── visual-builder/
│   └── ...
├── admin-ui/              # Vue 3 + Astro admin
│   └── src/components/
├── config/                # Configuration
├── marketplace/           # Marketplace files
├── sdk/                   # PHP SDK for plugins
└── public/                # Web root
```

## Development

```bash
# Run tests
composer test

# Build admin UI
cd admin-ui && npm run build
```

## License

MIT
