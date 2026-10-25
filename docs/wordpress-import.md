# WordPress WXR import (bounded first pass)

The CLI reads a WordPress eXtended RSS export and can create page and post text
as Click working copies. It never publishes imported records.

```sh
php bin/click-wordpress-import.php export.xml
php bin/click-wordpress-import.php --dry-run export.xml
php bin/click-wordpress-import.php --apply export.xml
php bin/click-wordpress-import.php --apply --site=client-a --locale=de export.xml
```

Read-only inspection is the default. The JSON report inventories item types,
statuses, duplicate slugs, unsupported content types, attachment URLs and
`_wp_old_slug` candidates. This first pass is intentionally source-only during
dry run: it does not query target schemas, target slugs or permissions. Apply
validates each record against the selected site's configured schemas as it
writes; the result can be partial, and every conflict or validation failure is
reported under `import.skipped`. Back up the site before applying and inspect
the whole report.

Supported mappings:

- WordPress `page` → one `rich-text` section containing the WXR title and HTML
  body.
- WordPress `post` → fields `title`, `body` and `excerpt` on the configured
  `post` collection. If the site has no compatible collection, posts are skipped
  with the validation or unknown-collection reason.
- Both mappings create unpublished working copies regardless of the source
  status. Existing records are never overwritten; a repeated apply reports them
  as skipped conflicts.

Attachments are inventory-only. The importer does not fetch remote files,
download media or rewrite image URLs. Attachment source URLs appear in the
report for a separate, reviewed media migration. `_wp_old_slug` values are
reported as redirect candidates; no redirect rules are created. Categories,
tags, users, menus, widgets, plugins, custom post types and theme metadata are
not migrated. A missing `rich-text` section or a required body field that cannot
be satisfied causes that page to be skipped rather than silently dropping its
text.

The 64 MiB file-size ceiling and non-networking XML parser bound the source
inspection. For a large export, split it into smaller files and inspect each
report before applying.
