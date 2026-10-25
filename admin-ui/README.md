# Click CMS Admin UI

Astro + Vue 3 front end for Click CMS. The PHP backend serves the built files
from `public/admin`; this package is the source.

## Screens

- **Dashboard** — counts and first-run guidance on an empty site
- **Pages / Collections** — draft editing, versions, languages, schedule, preview
- **Builder** — free-form page layout (when the site and account allow it)
- **Media** — upload, folders, search, bulk delete, focal point, crop previews
- **Menus / Redirects** — site navigation and permanent moves
- **Themes** — list, activate, upload a ZIP
- **Plugins / Marketplace** — activate, configure, install from registry or ZIP
- **Users / Profile** — accounts, password, two-factor
- **Settings / Updates / Webhooks / Form submissions** — site policy, self-update,
  outbound hooks, and collected form posts

Collaboration (presence, comments, review) lives on the page editor when the
collaboration plugin is active.

## Typography

- Display: **Sora**
- Body: **Source Sans 3**

Defined as `--font-display` / `--font-body` in `AdminLayout.astro`.

## Setup

```bash
cd admin-ui
npm install
npm run build    # writes dist/; the CMS serves it as public/admin
npm run dev      # hot reload; proxies /api to the PHP backend
```

Point the proxy at a different backend if needed:

```bash
CLICK_CMS_API_URL=http://localhost:8080 npm run dev
```

## API surface

The UI talks to the Click CMS JSON API under `/api/…` — pages, collections,
media, themes, plugins, marketplace, users, settings, menus, redirects, updates,
webhooks, seed, builder blocks, and collaboration routes when that plugin is
loaded. There is no separate GraphQL-only admin path; GraphQL is a delivery
plugin, not how this UI loads its screens.

Fault responses still carry a human `error` string. Some known faults also
include a machine `code` (for example `forbidden`); clients that only read
`error` keep working.

## Layout

```


### Typography

- **Font**: Inter (Google Fonts)
- **Base Size**: 14px
- **Headings**: 1.875rem - 1.125rem
- **Line Height**: 1.5

### Spacing

- **Cards**: 1.5rem padding
- **Grid Gap**: 1.5rem
- **Border Radius**: 12px (cards), 8px (buttons)

## API Integration

The admin UI connects to the Click CMS backend via:

- `GET /api/info` - System info and plugin list
- `GET /api/pages` - List all pages
- `POST /api/graphql` - GraphQL queries

## Browser Support

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers

## Performance

- Static HTML generation via Astro
- Vue components hydrate on client
- D3 charts use SVG for crisp rendering
- Minimal JavaScript bundle size

## Future Enhancements

- [ ] Dark mode toggle
- [ ] Real-time WebSocket updates
- [ ] More D3 visualizations (line charts, area charts)
- [ ] Drag-and-drop page ordering
- [ ] Media library with image previews
- [ ] Plugin configuration UI
- [ ] User management interface

The build also stages these files in `public/admin/`, where the PHP server serves them at `/admin/`. Rebuild after changing the admin UI.
||||||| dd55734

### Typography

- **Font**: Inter (Google Fonts)
- **Base Size**: 14px
- **Headings**: 1.875rem - 1.125rem
- **Line Height**: 1.5

### Spacing

- **Cards**: 1.5rem padding
- **Grid Gap**: 1.5rem
- **Border Radius**: 12px (cards), 8px (buttons)

## API Integration

The admin UI connects to the Click CMS backend via:

- `GET /api/info` - System info and plugin list
- `GET /api/pages` - List all pages
- `POST /api/graphql` - GraphQL queries

## Browser Support

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers

## Performance

- Static HTML generation via Astro
- Vue components hydrate on client
- D3 charts use SVG for crisp rendering
- Minimal JavaScript bundle size

## Future Enhancements

- [ ] Dark mode toggle
- [ ] Real-time WebSocket updates
- [ ] More D3 visualizations (line charts, area charts)
- [ ] Drag-and-drop page ordering
- [ ] Media library with image previews
- [ ] Plugin configuration UI
- [ ] User management interface
## Browser support

The interface targets current Chrome, Edge, Firefox, Safari, and mobile browsers.

## Build output

The build also stages these files in `public/admin/`, where the PHP server serves them at `/admin/`. Rebuild after changing the admin UI.
