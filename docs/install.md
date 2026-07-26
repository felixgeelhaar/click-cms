# Installing Click CMS

Three routes, in order of how quickly they get you to a running site. Whichever
you take, the first two things to do afterwards are the same: **change the admin
password** and **decide whether to seed the example site**.

## If you are not the one installing this

This page is written for whoever puts the software on a server. If your job is
to edit a site that already exists, you do not need any of it — go straight to
[Getting started](getting-started.md), which covers signing in and changing your
first piece of text.

If you are setting a site up for yourself, here is what the three routes below
mean in plain terms. All of them end in the same place: a site you sign into at
`/admin/`.

- **Route 1 — Docker.** For someone comfortable running commands on a server, or
  who already uses Docker. The quickest route if that describes you.
- **Route 2 — From a release archive.** The one for ordinary web hosting. You
  download a zip file, unpack it, and upload the folder to your hosting account
  the way you would any other website. No database to set up and nothing to
  install alongside it.
- **Route 3 — From source.** For developers who want to change the software
  itself.

Before you start, you need somewhere to put the site — a hosting account, or a
server — and that host needs **PHP 8.1 or newer**. The Requirements section
below is the full list; if you are unsure whether your hosting qualifies, that
list is the thing to send to your host's support.

If none of that sounds like something you want to do, this is a job worth handing
to a developer for an hour. Once it is running, editing the site needs no
technical knowledge at all.

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

### Ask the host, rather than guessing

Every release ships `public/preflight.php`, which answers the list above for a
specific host — and answers it **as the web server**, which matters because
shared hosting routinely runs one PHP on the command line and another for the
web, so a shell session can tell you the wrong thing.

Set a token, either in the server config (better — an update replaces the file,
and with it any edit):

```apache
SetEnv CLICK_PREFLIGHT_TOKEN a-long-hard-to-guess-string
```

then open `https://example.com/preflight.php?token=a-long-hard-to-guess-string`.
It reports the PHP version, the extensions, the largest upload the host will
accept, whether the update feed can be fetched and verified, and whether there is
a writable directory outside the document root to keep the site's own files in.
Failures are separated from warnings: a failure means it will not run here, a
warning means it will run with something missing.

Until a token is set the page answers 404, like a file that is not there, so an
installation that never uses it never publishes a description of its server. When
you are done, delete it or unset the token — and note that an update restores the
placeholder, so forgetting fails safe on the next release.

The check that most often fails is the upload ceiling: PHP's own default is 2 MB,
well under what the CMS accepts, and it is otherwise discovered in the middle of
moving a site's media.

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

### In a subdirectory

A site does not have to answer at a domain root. `example.com/2026/cms/` works
with no configuration at all: the CMS reads where it is installed from the
request, and adjusts both what it routes and what it links to.

Put the contents of `public/` at the address the site should answer on, and the
rest of the archive anywhere the web server can read:

```
/web/2026/cms/     index.php, .htaccess, theme.css      ← the public directory
~/click-cms/       src, vendor, plugins, themes, config, content, data
```

Then tell the CMS where the rest of it is, so `index.php` can find it:

```apache
# .htaccess next to the public files, or the vhost
SetEnv CLICK_CMS_ROOT /home/example/click-cms
```

Splitting it this way is worth doing whenever the document root cannot be moved
— which is most shared hosting, where the whole account is served from one tree.
Everything the site is made of then sits outside it, and `content/`, `data/` and
`config/` are unreachable over HTTP whatever a rewrite rule does.

Set nothing and the CMS uses the directory above `public/`, which is the
ordinary layout. The path is read from the server rather than written into a
file **because an update replaces `public/`**: an edit to `index.php` would
survive only until the next release.

### Behind a reverse proxy

A proxy that publishes the site at `/blog/` in front of an application installed
at a root is the one case the request cannot show: the script's own path says
nothing about the public URL. Two ways to deal with it.

Most proxies already send `X-Forwarded-Prefix`. Name the proxy and the CMS will
believe it:

```json
{ "core": { "trustedProxies": ["10.0.0.0/8"] } }
```

Addresses or CIDR ranges — a range because an ingress controller's pods do not
keep one address. **Nobody is trusted by default**, and that is deliberate: the
header is written by whoever sent the request, and the prefix it carries goes
into every URL the site emits, so believing an unnamed sender would let a visitor
rewrite every link on a page. Name only the proxy your traffic actually arrives
through, and make sure nothing else can reach the application directly.

Or state the prefix outright, which needs no trust in anybody:

```json
{ "core": { "basePath": "/blog" } }
```

This wins over everything else. An empty string means the domain root, and is
honoured as an answer rather than read as the absence of one — which is what a
site published at a root from a script in a directory needs to say.

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

In Docker, run it **as the web server's user**:

```bash
docker exec -u www-data click-cms php bin/click-seed.php
```

`docker exec` runs as root by default, and content it creates that way is owned
by root — the site can read it and then cannot edit or delete any of it. The
same applies to every CLI tool here.

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
