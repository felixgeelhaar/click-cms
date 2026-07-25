# Installing Click CMS

Three routes, in order of how quickly they get you to a running site. Whichever
you take, the first two things to do afterwards are the same: **change the admin
password** and **decide whether to seed the example site**.

## Requirements

- **PHP 8.1 or newer.** That is the whole list — `composer.json` requires PHP and
  nothing else.
- **The `gd` extension**, if you want responsive image variants. Without it
  uploads still work; they are simply stored at the size they arrived.
- **`pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`**, only if you choose that storage
  backend. The default needs none of them.
- **Node 20+**, only to build the admin UI from source. The Docker image and a
  release archive already contain it built.

No database, no long-running process, no build step at deploy time. This runs on
ordinary shared hosting, which is the constraint that decided most of its design.

## Route 1 — Docker

```bash
docker run -d --name click-cms -p 8080:80 \
  -v click-cms-content:/var/www/html/content \
  -v click-cms-data:/var/www/html/data \
  ghcr.io/felixgeelhaar/click-cms:latest
```

Open <http://localhost:8080/admin/> and sign in as `admin` / `admin`.

**The volumes are not optional.** `content/` holds every document and every
uploaded file; `data/` holds sessions, version history, the audit trail and the
search index. Without volumes both vanish when the container is replaced, which
is to say on the next upgrade.

To build the image yourself rather than pull it:

```bash
docker compose up -d --build
```

## Route 2 — From a release archive

Each release attaches two archives. Take the one ending `-install.zip`:

```bash
# replace 1.0.0 with the current release
curl -LO https://github.com/felixgeelhaar/click-cms/releases/download/v1.0.0/click-cms-1.0.0-install.zip
unzip click-cms-1.0.0-install.zip
cd click-cms-1.0.0-install
php -S localhost:8080 -t public
```

It ships with `vendor/`, the built admin UI and the default configuration, so
there is nothing to install and nothing to write before it starts. Upload the
unpacked directory to shared hosting and point the document root at `public/`.

The other archive — `click-cms-<version>.zip`, without the suffix — is the
*upgrade* package the updater unpacks over a site that already exists. It
deliberately contains no `config/`, so unzipping it as a fresh install fails on
the first boot. Take the `-install` one.

For a real deployment, point Apache or nginx at `public/` — never at the project
root, which would serve `content/`, `data/` and `config/` to the internet.

An Apache virtual host to copy is in [`docker/apache-vhost.conf`](../docker/apache-vhost.conf).

## Route 3 — From source

```bash
git clone https://github.com/felixgeelhaar/click-cms.git
cd click-cms
composer install
cd admin-ui && npm install && npm run build && cd ..

php -S localhost:8080 -t public
```

For admin UI development, run the Astro dev server alongside it — the admin route
proxies to it, so changes are live:

```bash
cd admin-ui && npm run dev     # then use http://localhost:4321/admin/
```

## First run

### 1. Change the password

The installer seeds `admin` / `admin` and the account can do exactly one thing
until that password is replaced: replace it. The password is published in this
documentation, so it is not a secret and was never meant to be one.

### 2. Seed the example site (optional)

A fresh install has no content, so every screen is an empty list and the public
site is a 404 — which shows you nothing about what a section, a collection or a
menu is.

```bash
php bin/click-seed.php --dry-run   # what it would create
php bin/click-seed.php             # create it
```

It never overwrites: anything already there is skipped, so it is safe to run
against a site that already has content, and safe to run twice. There is no flag
that deletes anything — to remove the example site, delete its pages from the
admin.

In Docker:

```bash
docker exec click-cms php bin/click-seed.php
```

### 3. Choose a storage backend

Flat JSON files by default — no database, nothing to install, and the content
directory is readable and diffable. To use something else, set it in
`config/core.json`:

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

`backend` accepts `json`, `sqlite`, `mysql`, `mariadb` and `postgres` (spelled
`postgresql` or `pgsql` if you prefer). Each
database backend needs its matching PDO extension. An unrecognised backend, or
one whose extension is missing, fails at boot with a message saying what to
change — it never falls back silently.

Switching the setting does not move existing content. Move it first:

```bash
php bin/click-migrate-storage.php json sqlite
```

That is safe to re-run — a document already identical in the target is skipped —
so an interrupted migration is finished by running it again. Version history is
not moved.

See [`core.md`](core.md) for the full comparison.

## Going to production

- **Serve `public/`, not the project root.** `content/`, `data/` and `config/`
  must not be reachable over HTTP.
- **Make `content/` and `data/` writable** by the web server user, and nothing
  else writable.
- **Use HTTPS.** Sessions are cookie-based; over plain HTTP they are readable in
  transit.
- **Turn on the render cache** if the site is mostly read:
  `{"core": {"cache": {"enabled": true}}}`. Read
  [what it cannot see](core.md#the-render-cache) first — it is off by default for
  a reason.
- **Set up updates.** [`updates.md`](updates.md) covers the signed release feed
  and the policies for installing from it.
- **Back up `content/` and `data/`.** With the flat-file backend that is a file
  copy; there is nothing to dump.

## Where things live

| Path | What it is | Back it up |
|---|---|---|
| `content/` | Every published document, and every uploaded file | **Yes** |
| `data/` | Sessions, version history, audit trail, caches | **Yes** — history and the audit trail are here |
| `config/` | Core settings, section types, collection types | Yes — it is the site's shape |
| `themes/` | Look and feel | Yes, if you wrote one |
| `plugins/` | Installed plugins | Reinstallable, but back it up if any are local |
| `public/` | The document root — only entry points live here | No |
| `src/`, `vendor/` | The application | No |

`content/` and `data/` are in `.gitignore` on purpose: they are a site's data,
not the project's code.

## Troubleshooting

**The admin UI is blank or 404s.** The admin UI was not built. Run
`cd admin-ui && npm install && npm run build`, or use the Docker image, which
contains it already.

**Uploads are refused.** Check that `content/media` is writable, and that the
file is one of the accepted types — the refusal message says which.

**Images upload but produce no responsive variants.** The `gd` extension is
missing. Install it and re-upload; existing files are not reprocessed.

**A page edit does not appear on the public site.** Saving is not publishing.
Publish it from the page's own screen. If it is published and still stale, the
render cache is on and something changed outside storage — clear
`data/cache/pages`.

**"Unknown storage backend" at boot.** The `backend` value in `config/core.json`
is not one of the five names above, or its PDO extension is not installed. The
message names which.
