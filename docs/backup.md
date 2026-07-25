# Backup and restore

click-cms can back the whole site up and put it back — every content document in
every type and every language, plus the media library.

The archive is **independent of the storage backend**. One taken from a site on
Postgres restores onto a site on flat files. That is not a bonus: it is the
property the feature is built around, because the day you need a backup is
usually the day the machine that held the database is gone.

> **A backup is the entire site, including things nobody has published.**
> Unpublished drafts, and `user` documents carrying password hashes. Archives are
> written under `data/`, which is not web-served, and every HTTP route that
> touches them is administrator-only. Treat a downloaded archive the way you
> would treat a database dump — because that is what it is.

## What went wrong before, and why the format changed

The previous implementation built its archive by walking the `content/`
directory. That directory holds documents on exactly one of the four supported
backends. On `sqlite`, `mysql`, `mariadb` and `postgres` the documents live in
the database, so the archive contained the site's media, **not one single content
document** — and wrote a manifest reporting success. A site backed up nightly for
a year had a year of archives that would have restored an empty site.

The export now reads `StorageInterface::types()` and `findByType()`, which is the
pair that makes "everything in this site" expressible without knowing what a site
contains or where the bytes live.

Archives in the old shape (backup format 1) **cannot be restored**. They are
refused rather than half-understood, because on any database backend restoring
one would silently produce an empty site — the original bug, run backwards.

## Configuration

Under `core.backup` in `config/core.json`:

```json
"backup": {
  "enabled": false,
  "intervalHours": 24,
  "keep": 7,
  "includeMedia": true,
  "maxMediaBytes": 536870912
}
```

| Key | Default | What it does |
|---|---|---|
| `enabled` | **`false`** | Whether the site takes *scheduled* backups at all. |
| `intervalHours` | `24` | How long between scheduled backups. Never below 1. |
| `keep` | `7` | How many archives are retained. Never below 1. |
| `includeMedia` | `true` | Whether uploaded files are backed up alongside the documents. |
| `maxMediaBytes` | `536870912` (512 MB) | The largest single media file a backup will take. `0` means no ceiling. |

**`enabled` is off by default on purpose.** A backup nobody asked for is a
directory that grows every night on a host whose disk quota the CMS knows nothing
about, and the first anyone hears of it is the site failing to write a page
because the volume is full. Taking backups is a decision with a cost, so it is
made deliberately.

Neither the on-demand download nor `--force` is gated on `enabled`: that setting
governs the unattended schedule, and an administrator asking for a backup in
person is not that.

**`maxMediaBytes` never silently drops anything.** A file over the ceiling is
recorded in the manifest under `skippedMedia` with its size and the reason,
printed by the CLI on every run, returned by the API, and reported again as a
failure when the archive is restored. A backup that quietly dropped the 2 GB video
is the failure this whole feature exists to prevent; a size ceiling is the obvious
place to reintroduce it.

## Running it

```cron
23 3 * * *  cd /var/www/html && php bin/click-backup.php >> data/backups/cron.log 2>&1
```

```bash
php bin/click-backup.php                   # back up if the interval says it is due
php bin/click-backup.php --force           # back up now, whatever the interval says
php bin/click-backup.php --dry-run         # report what would happen, change nothing
php bin/click-backup.php --list            # what archives exist
php bin/click-backup.php --keep=30         # override retention for this run

php bin/click-backup.php --restore=2026-07-25T030000Z.zip
php bin/click-backup.php --restore=/mnt/usb/offsite.zip --overwrite
php bin/click-backup.php --restore=… --dry-run   # verify and preview; writes nothing
```

The interval is enforced inside the CMS, so a more frequent cron entry is
harmless. **A dry run never advances the schedule** — previewing what would happen
must not consume the interval and leave tonight's real backup silently skipping.

Everything runs under a non-blocking `flock`, so two overlapping cron runs cannot
both write into the pool and both prune it, and retention cannot delete a pool
entry out from under a restore that is reading it. A run that finds the lock held
simply ends.

`bin/click-backup.php` refuses to run under a web SAPI at all (404): served over
HTTP it would be an unauthenticated endpoint that reads every draft and every
password hash on the site.

Exit codes: `0` nothing to do or backup taken, `1` something failed. Cron mails on
non-zero, which is the notification.

## The archive format

```
data/backups/
  pool/<sha256>.<ext>          media, stored once each, shared by every archive
  2026-07-25T030000Z.zip       content JSON + a manifest naming the pool entries it needs
  schedule.json                when the last scheduled run happened
  backup.lock
```

Inside an archive:

```
manifest.json
content/<type>/<locale>/<slug>.json     one file per document, whatever the backend
content/media/<path>                    only in a self-contained archive
```

Documents are serialised as the same JSON the flat-file backend writes, whatever
backend they came out of — which is what makes an archive from SQLite identical to
one from files, and therefore restorable onto either.

The manifest is the index a restore iterates. Every document and every media file
is written because the manifest named it, never because it happened to be in the
ZIP. A stray entry appended to an archive is therefore never looked at.

```json
{
  "generator": "click-cms",
  "formatVersion": 2,
  "createdAt": "2026-07-25T03:00:00+00:00",
  "sourceBackend": "sqlite",
  "mediaStorage": "pool",
  "counts": { "documents": 11, "media": 14, "skippedMedia": 1 },
  "documents": [
    { "entry": "content/page/en/home.json", "key": "page:en:home", "type": "page",
      "slug": "home", "locale": "en", "sha256": "…", "bytes": 3249 }
  ],
  "media": [
    { "path": "photo-a1b2.png", "sha256": "…", "bytes": 2048,
      "entry": null, "pool": "pool/<sha256>.png" }
  ],
  "skippedMedia": [
    { "path": "keynote.mp4", "bytes": 2147483648,
      "reason": "larger than core.backup.maxMediaBytes (536870912 bytes)" }
  ]
}
```

### Two shapes: pooled and self-contained

`mediaStorage` says which one an archive is.

- **`pool`** — the media lives once in `data/backups/pool` and the manifest names
  the entries it needs. This is what a scheduled backup is. Cheap, and inseparable
  from this installation.
- **`embedded`** — the media bytes are inside the ZIP. This is what a download is,
  because an archive that referred to a pool the recipient does not have could not
  be restored anywhere — which is the only reason to download one.

To take a nightly archive off-site, either copy the whole `data/backups`
directory (archives *and* pool — that is what an rsync job should do), or ask the
API for a self-contained copy of one archive (below).

### Deduplication

The pool is content-addressed: the file's SHA-256 *is* its name. Two files land
on the same name exactly when they are the same file, so:

- Seven nightly backups of a library that has not changed store it **once**. The
  second night computes the same digest, finds the file already there, and writes
  nothing.
- A changed picture is different bytes and therefore a new entry; the old one
  stays for the older archives that still refer to it.
- Two copies of the same picture under different names are one set of bytes.

There is no modification-time or size heuristic to be wrong about.

The rendition cache (`content/media/derived/`) is **not** backed up: it is built
on demand from the stored originals, and archiving it would multiply the archive
for files the site regenerates for free.

### Retention, and why pruning is careful

Retention keeps the newest `keep` archives. Archive names are UTC timestamps, so
sorting them as strings sorts them chronologically — no modification time is
consulted, because a copy or a filesystem restore changes those.

Pool entries are **not** deleted because the archive that referenced them was
pruned. Media is shared: seven archives may all point at the same
`pool/<sha>.jpg`, which is the whole reason the pool is worth having. Deleting
"its" media with the oldest archive would take the pictures out of the six that
remain — and nothing would report it. The next restore would produce a site with
every page intact and every image gone, and the backup that could have fixed it
was the one just deleted.

So the rule is:

1. Work out which archives survive.
2. Read the pool references from **every surviving manifest** — that is the live
   set.
3. Delete only pool entries the live set does not name.

And when a surviving archive's manifest cannot be read — a corrupt ZIP, a file
being written by something else — its requirements are unknown. There is no safe
guess, so **the pool is not pruned at all on that run** and the CLI says so. A
slightly larger pool beats a silently broken backup. Retention by age still
proceeds; an unreadable archive is a reason not to delete bytes on its behalf, not
a reason to stop retaining.

A file in `data/backups/pool` whose name is not a valid reference was not written
by the CMS and is left alone.

## Restoring

```bash
php bin/click-backup.php --restore=2026-07-25T030000Z.zip
```

### It verifies first, completely, before writing anything

The whole archive is read and hashed before a single byte is written. A restore
that verified as it went would write half a site and then discover the archive was
truncated, leaving an installation that is neither what it was nor what the backup
held.

A restore is **refused outright** when:

- the archive is not a readable ZIP, or is not there;
- it has no `manifest.json`, or the manifest is not readable JSON;
- it is in a backup format this version does not read (including format 1, the
  old directory walk);
- the manifest's stated counts disagree with the lists it carries;
- an entry the manifest names is missing from the archive;
- an entry's size or SHA-256 does not match the manifest — truncation, bit rot, or
  tampering;
- an entry expands to more than the manifest allows (a zip bomb);
- a manifest names an entry path that could escape its destination — absolute,
  `..`, a backslash, a drive letter, a NUL, an empty segment;
- a pool reference is not a bare digest;
- a pooled media file is missing from the pool, or does not match its checksum;
- the archive is pooled and no pool is available (a pooled archive taken to another
  installation, which says so plainly).

Nothing is on disk when a restore is refused.

Zip Slip specifically: entry names are validated **as names**, before any path is
built from them, which is the check the marketplace installer's RCE did not have.
Nothing is extracted because it is in the ZIP; something is extracted because the
manifest named it *and* the name passed.

### It never overwrites unless told to

`--overwrite` is off by default. Anything already present is left exactly as it is
and reported as skipped — the same stance `bin/click-seed.php` takes, for the same
reason. A restore is far more often run to recover the handful of things that went
missing than to roll an entire site back to Tuesday, and overwriting by default
would mean the ordinary use of this feature destroys every edit made since the
backup was taken.

Both the live document *and* an unpublished draft count as present: a draft
occupies the address just as firmly, and overwriting one would destroy work that
was never backed up in the first place.

Every restore prints three separate lists — restored, already-there, and failed.
They are kept apart because collapsing them would let a restore that failed on half
the site read as a restore that found half the site already present, and those are
opposite facts about whether anyone needs to act.

### It writes through the application

Documents go in through `ContentService`, so the version chain records them, the
audit trail sees them, and the render cache is invalidated. Publishable types are
published after being saved — otherwise a restore would return a site whose every
page was an unpublished draft and whose every address 404ed.

A restore boots the application, which is also what registers the site's
collection types, so a restored collection entry is published rather than left as
a draft.

### One document, one failure

A document whose stored key disagrees with the key the manifest gives it is
skipped and reported, and the rest of the archive still goes in. One doctored
document is not a reason to abandon a recovery.

## The HTTP API

All three routes are **administrator-only** (`settings.manage`). The kernel's
deny-by-default guard refuses an anonymous caller before a handler runs; the
capability check in each handler is what distinguishes a signed-in editor from a
signed-in administrator, and that is the distinction that matters when the payload
is every draft and every password hash on the site.

| Route | What it does |
|---|---|
| `GET /api/backup` | Builds a fresh **self-contained** archive and streams it as a download. |
| `GET /api/backup?archive=<name>` | Streams a **self-contained copy** of a retained archive — how a nightly backup leaves the machine. |
| `GET /api/backups` | Lists retained archives (newest first) and the current settings. |
| `POST /api/backups` | Takes a retained backup now and applies retention. |

Downloads are sent with `Cache-Control: no-store, private` and
`X-Content-Type-Options: nosniff`, and the temporary file is removed immediately.

Converting a retained archive to a portable one verifies the source in full first:
a corrupt backup faithfully converted into a portable corrupt backup is worse than
an error, because it will be carried off-site and trusted.

**There is no restore endpoint, deliberately.** A restore writes over a live site
and, on a site of any size, takes long enough that a browser or a proxy will give
up in the middle of it — and "the restore was interrupted halfway" is the one
outcome worse than the data loss it was repairing. Restore lives at a console,
with a lock, with a verification pass in front of it.

## Known quirks

**Media metadata on the flat-file backend.** `MediaService` writes its metadata as
`content/media/<id>.json`, which sits inside the content root. The flat-file
backend therefore reports `media` as a content type and reads those files as
documents. A backup of a JSON-backed site consequently carries each media item
twice — once as a file, once as a `media` "document" — which is harmless (they
restore to different paths) but makes the document count on a JSON site higher than
the same content on a database backend. Database backends do not see them at all.

**Slugs differing only in case.** As `docs/core.md` records, slugs that differ only
in case are not portable between backends. A site relying on them would lose
documents restoring from SQLite onto flat files, on a case-insensitive filesystem.

## What lives where

| File | Responsibility |
|---|---|
| `src/Domain/Backup/ArchivePath.php` | What an entry inside an archive may be called (the Zip Slip guard). |
| `src/Domain/Backup/PoolReference.php` | The `pool/<sha256>.<ext>` name, and what is a valid one. |
| `src/Domain/Backup/BackupManifest.php` | The manifest value object, and refusing a malformed one. |
| `src/Domain/Backup/RetentionPlan.php` | Which archives and which pool entries may go — pure, no I/O. |
| `src/Application/Backup/BackupExporter.php` | Reads storage and media, writes an archive. |
| `src/Application/Backup/BackupVerifier.php` | Checks an archive against its manifest. |
| `src/Application/Backup/BackupRestorer.php` | Verifies, then writes through `ContentService`. |
| `src/Application/Backup/MediaPool.php` | The content-addressed media store. |
| `src/Application/Backup/BackupStore.php` | The `data/backups` directory: naming, listing, pruning. |
| `src/Application/Backup/BackupScheduler.php` | The interval and the lock. |
| `src/Application/Backup/BackupService.php` | Taking a backup, in either shape. |
| `bin/click-backup.php` | The cron entry point and the restore command. |
| `plugins/backup/bootstrap.php` | The administrator-only HTTP surface. |
