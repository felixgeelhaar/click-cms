# Click CMS handbook — English

[Deutsch](handbook-de.md) · [Hands-on demos](demos-en.md)

**Here to edit your website?** Start with the [simple editor guide](editor-en.md). It takes you through one task at a time. The technical sections below are for the person who runs or builds your site.

For editors, site owners and developers. These instructions describe Click 1.11. Features depend on your role, installed plugins and the site's section and collection definitions. English interface labels are included so you can find controls even when reading the German guide.

## Choose your starting point

| Your task | Start here |
|---|---|
| Change words or pictures | Editing below, then demo 1 |
| Approve and publish work | Publishing below, then demo 2 |
| Manage a website | Administration and operations below |
| Build a website or SPA | Developer guide below, then demo 4 |
| Try Click without affecting visitors | [Local demo setup](demos-en.md) |

## Sign in and understand access

Use the admin address supplied by your administrator, normally `/admin/` after the CMS address. A subdirectory installation might use `/2026/cms2/admin/`. Enter your username, not necessarily your email. Complete the required password change and any two-factor challenge. Store credentials in a password manager. Never use the documented initial credentials on an existing site.

| Role | Typical responsibility |
|---|---|
| Administrator | Content, accounts, settings, extensions and updates |
| Editor | Edit and publish content; deletion is restricted by ownership |
| Author | Create and maintain their own drafts; ask an editor to publish |
| Viewer | Read without changing content |

Missing controls may reflect permissions. Ask your administrator before attempting a different account. [Detailed permissions](roles.md) · [Two-factor authentication](two-factor.md).

## Everyday editing

Open **Pages**, find the title or address, and choose **Edit**. Read the publication status first. Work through the content sections, opening the section you need; use the page settings for less frequent metadata changes. Existing sites may provide different fields and section designs.

A page contains ordered sections. Fill in the fields in each section; use the section controls to reorder, duplicate or remove it. Save before leaving. Removing a section changes the draft; deleting a page removes the document. Prefer taking a page down when you only want to hide it temporarily.

Upload images through **Media** or the image chooser. Add a useful image description for meaningful images. Check the crop on mobile as well as desktop. Keep source files separately; deleting media can break pages that use it. [Image guide](pictures.md).

Use the language selector for the intended translation. Review every translated field and check the public URL in that language. Changing one translation does not replace the need to check the others; a missing translation may fall back to another locale.

## Save, preview, publish and recover

**Save** keeps your work. **Publish** makes the saved version available to visitors. Saving changes to an already published page leaves the previous published version live until you publish again. Read the status after both actions; do not assume a successful save means the public site changed.

Preview the draft before publishing. Treat shared preview links as private access to unpublished material. Check headings, links, images, language and narrow screens. An author hands the preview to an editor rather than publishing directly.

Use history to inspect an earlier version and restore it if needed. Verify the restored draft and explicitly publish when ready. If the editor reports another person's changes, preserve your text separately, reload the current version and reconcile the edits. Do not repeatedly overwrite a conflict.

Take down a page to remove its public version while retaining editable content. After publishing or taking down, check the actual public website; a separate SPA or static site may need to refresh or rebuild. [Publishing reference](publishing.md) · [Editing reference](editing.md).

## Collections, navigation and other screens

**Collections** hold repeated structured entries such as news or team profiles. Choose the collection, create or edit an entry, complete its required fields, save and publish. Its fields and public rendering are supplied by your developer. [Collections guide](collections.md).

Administrators use **Menus** to add published destinations and order navigation. Creating a page alone does not guarantee a menu link. Use **Redirects** when replacing an old address; test the old URL and avoid redirect loops.

**Submissions** contains form responses when the relevant forms are configured. Limit access, avoid storing unnecessary personal information and establish a retention policy. **Builder**, **Themes**, **Plugins** and **Marketplace** affect design or functionality; try changes on a copy before applying them to the live site. The [admin tour](admin-tour.md) explains each screen.

## Administration and security

Create individual accounts under **Users**, give each the least powerful suitable role, and remove access when someone leaves. Use **Profile** for personal account settings and configure two-factor authentication where available. Keep recovery material private. Passwords are stored as hashes and cannot be read back; ask the site operator for a controlled reset if credentials are lost.

Install trusted extensions only. Keep PHP and Click supported and updated. Use HTTPS. Serve only `public/`; keep configuration, content, account files, sessions and backups outside public access. Never put administrator passwords, API management credentials or preview secrets in browser code or a public repository.

## Installation, backups and automatic upgrades

Use a release ending in `-install.zip` for a new installation; the other archive is for updates. PHP 8.1 or newer is required by this version. Serve the public directory and make the necessary content/data locations writable by PHP. Follow the complete [installation guide](install.md), including host checks, storage selection and split-root hosting.

Back up `content/`, `data/`, `config/` and local themes/plugins; database storage also needs a database backup. Store copies outside the public web directory and test restoration on an isolated copy. An update backup is not a substitute for independent disaster recovery. [Backup reference](backup.md).

Under **Updates**, review the installed version, offered release, policy and history. Policies are `manual`, `notify`, `security`, `minor` and `all`. `minor` permits unattended stable patch/minor updates; major releases require approval under that policy. Pre-releases never install automatically. The default `security` policy automatically installs releases marked as security updates.

Automatic checks are triggered by due GET/HEAD requests reaching PHP, including delivery API requests, normally once daily. An idle or fully cached site cannot check until a request reaches Click. Managed deployments disable this mechanism. Updates require verified signed feeds, package verification, outbound access and writable installation/backup paths. Split public/application roots need the actual absolute PHP `publicRoot` path; an SFTP path is not necessarily that path. [Full update configuration and recovery](updates.md).

## Developer guide: rendered sites and headless SPAs

Click can render a theme or supply published content through the delivery API. Define reusable section schemas in `config/sections/` and collection schemas in `config/collections/`. Keep the shape of content separate from presentation, and test schema changes with existing documents. [Architecture and storage](core.md) · [Visual builder](visual-builder.md).

For a SPA, configure the CMS base URL including any subdirectory. Fetch `/api/pages` or `/api/pages/{slug}`; handle unavailable content, locale fallback, network errors and maintenance responses. A missing or unpublished page should produce your own not-found experience. Use a deliberate renderer for every section type; never insert untrusted HTML directly. Configure delivery CORS only for the origins that need it.

The [TypeScript client](../sdk/typescript/README.md) documents the exact response envelope and generated section types. Generate types when schemas change and compile the frontend against them. Keep authenticated editing separate from public delivery; visitors need no administrator session to read published content. [Demo 4](demos-en.md#demo-4-read-published-content-from-a-spa) provides a minimal read-only request.

For static frontends, publication does not itself deploy rebuilt HTML. Configure a receiver for signed publication/unpublication [webhooks](webhooks.md); validate signatures and protect deployment credentials. Test the full chain from editor publication to the public website.

## Troubleshooting and acceptance checklist

| Symptom | Check |
|---|---|
| Cannot sign in | Correct admin URL, username, password and second factor; ask the operator for recovery |
| No publish control | Account role and permissions |
| Saved changes absent | Publish status, intended locale, frontend cache or rebuild |
| Upload fails | File restrictions, PHP upload limits and writable media storage |
| Admin blank | Correct built release assets and public/application paths |
| Update fails | Update history, PHP logs, signature/feed access, disk space and permissions |
| Brief 503 during update | Retry after the maintenance interval; investigate persistent failures |

Before handing over a site, run all four demos on an isolated copy, test a restore, verify that private files cannot be downloaded, and document the responsible operator, backup location and update policy. Never use production credentials or real form submissions in a public demo.
