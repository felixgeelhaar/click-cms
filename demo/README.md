# Headless demo — Click CMS + a separate front end

This is a worked example of running Click CMS **headless**: the CMS is the
content backend (admin UI + delivery API), and a completely separate website —
`turboscience-web`, its own brand, its own components — renders that content by
reading the delivery API. Two containers, wired together.

```
┌────────────────────────┐        /api/*        ┌────────────────────────┐
│  turboscience-web       │  ───────────────▶   │  click-cms (headless)   │
│  Vue SPA + nginx        │   (compose network) │  admin UI + delivery    │
│  :3000  (the website)   │  ◀───────────────   │  :8080  (backend)       │
└────────────────────────┘     published JSON    └────────────────────────┘
```

- The browser only ever talks to **:3000**. nginx there proxies `/api/*` to the
  `cms` service over the compose network, so there is **no CORS** and the CMS
  host is never baked into the JavaScript bundle.
- The CMS is switched to **headless** (`settings.headless = true`) by the seed
  step, so it renders no site of its own — its root just points at `/api/pages`.
- `turboscience-web` maps each Click CMS section type (`rich-text`, `facts`,
  `card-grid`, `call-to-action`, `media-text`) to its own branded component, and
  reads blog posts and their resolved author from the collections delivery API.

## Run it

```bash
# from the repo root
docker compose -f docker-compose.turboscience.yml up -d --build
./demo/seed.sh          # log in, create the real content, switch to headless
```

Then open:

| URL | What |
|---|---|
| http://localhost:3000 | The TurboScience website (content pulled from the CMS) |
| http://localhost:8080/admin | The Click CMS admin — where the content lives (`admin` / `TurboScience2026!`) |
| http://localhost:8080/api/pages | The raw delivery API |

Edit anything in the admin, publish it, and reload http://localhost:3000 — the
change is there. That is the whole point: one backend, any number of front ends.

## Tear down

```bash
docker compose -f docker-compose.turboscience.yml down
rm -rf demo/state        # discard the seeded content
```

## Layout

```
docker-compose.turboscience.yml   the two services
demo/seed.sh                      creates the real content via the API, goes headless
demo/turboscience-web/            the front end (Vite + Vue 3 SPA, nginx)
  src/api.js                      the delivery client (read-only, anonymous)
  src/components/SectionRenderer.vue   maps CMS section types -> components
  src/components/sections/*       one branded component per section type
  src/pages/*                     Home, About, Blog, Post
  nginx.conf                      serves the SPA, proxies /api to the cms service
demo/state/                       runtime content + data (git-ignored)
```
