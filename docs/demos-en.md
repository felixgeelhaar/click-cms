# Click CMS demos — English

[Deutsch](demos-de.md) · [English handbook](handbook-en.md)

These are hands-on walkthroughs, not a hosted public sandbox. Use a disposable local instance or an isolated staging copy. Never seed or practice recovery on a production site. Allow about 30 minutes for the four demos after installation.

## Prepare a local instance

From a Click source checkout with Docker installed:

```bash
docker compose up -d --build
docker compose ps
```

Open the address shown by your Compose configuration; the default setup uses `http://localhost:8080/admin/`. On a fresh installation only, sign in with `admin` / `admin` and immediately choose a unique password. Do not publish this instance on the internet with the initial credentials.

Seed sample content as the web user. Find the container name with `docker compose ps`, then substitute it below:

```bash
docker exec -u www-data CONTAINER_NAME php bin/click-seed.php --dry-run
docker exec -u www-data CONTAINER_NAME php bin/click-seed.php
```

The seed skips existing documents. Confirm sample pages appear. Your site may use different sections from the screenshots. Installation alternatives: [release archive and source](install.md).

## Demo 1: edit a page and preview it

**Audience:** editor or administrator. **Time:** 10 minutes.

1. Open **Pages** and edit a sample page. Record its slug and original public heading.
2. Change a text field to “Our next chapter”. Save.
3. Open the draft preview and check the new words. Open the public page in a separate window.
4. If the page was already live, confirm the public version still shows the original words. If it was never published, confirm it is unavailable publicly.
5. Publish, then refresh the public page. Confirm “Our next chapter” appears.
6. Repeat the preview check at a narrow mobile width and follow any links you changed.

**Success:** saved drafts remain separate from public content, and publishing changes the public page.

![Sample page editor; available fields depend on the site configuration.](images/edit-page.png)

## Demo 2: review, restore and take down

**Audience:** administrator plus a separate author account. **Time:** 10 minutes.

1. Create an author test account under **Users**, then sign in as that author in a separate browser profile.
2. Create a page with a unique demo slug. Add a section and save it. Confirm the author cannot publish.
3. Share its preview privately with the administrator. Verify the preview before publishing as administrator.
4. As administrator, change some text and save another revision. Open history and restore the earlier revision. Check the restored content before publishing.
5. Take down the demo page. Confirm its public URL is unavailable but it remains editable in **Pages**.

**Success:** the author cannot bypass publication permissions, history can recover a draft, and taking down preserves editable content. Remove the test account and demo page after the exercise.

## Demo 3: media, translation and navigation

**Audience:** administrator. **Time:** 5 minutes.

1. Upload a non-sensitive sample image in **Media**. Select it in a page section and enter a useful image description.
2. Preview desktop and mobile crops, save and publish.
3. If the installation has a second locale configured, select it and add translated text. Save and publish that locale. Check both language URLs and any fallback indicator in the API.
4. Add the published page to the intended menu in **Menus**. Confirm the link on the public site where that menu is rendered.
5. If the site has collections, create one sample entry, complete required fields and confirm its published rendering.

**Success:** the image is accessible, the intended translations are delivered, and navigation reaches the page. Locale configuration, collection fields and menu rendering depend on your site; record unavailable steps instead of assuming they exist.

## Demo 4: read published content from a SPA

**Audience:** developer. **Time:** 5 minutes. Requires a running CMS with the delivery API enabled and a published page.

Replace the base URL and slug with your local values. Run in a modern browser console on an allowed origin or in Node with global `fetch`:

```javascript
const cmsBase = 'http://localhost:8080';
const slug = 'home';
const response = await fetch(`${cmsBase}/api/pages/${encodeURIComponent(slug)}`);
if (response.status === 404) {
  console.log('Page missing or unpublished');
} else if (response.status === 503) {
  console.log('CMS temporarily unavailable; retry later');
} else if (!response.ok) {
  throw new Error(`CMS request failed: ${response.status}`);
} else {
  const payload = await response.json();
  console.log(payload);
}
```

Inspect the real response envelope before rendering; the [typed client reference](../sdk/typescript/README.md) describes it. This example deliberately logs content rather than injecting HTML.

**Success:** published content is readable without admin credentials. Take down the test page and confirm the request no longer exposes it. For a separate frontend, also test CORS, missing translations, maintenance errors and the frontend's own not-found display. For a static site, verify that publication triggers its configured signed webhook and rebuild.

## Operator drill: updates and recovery

Use a separate copy with its own writable content and data directories, no production credentials, no active production webhooks and no public traffic. Back it up, record the installed version and policy, and follow the [update guide](updates.md) for a genuinely newer compatible signed release. Confirm version, admin login, pages, translations, media and delivery API afterwards. Test restoration using the [backup guide](backup.md).

An installation already on the newest release cannot demonstrate an actual upgrade. Record that limitation. Do not downgrade or disable signature checks to force a demo. On hosts without shell access, use the admin update controls and the host's file-management or approved deployment mechanism.

## Stop and clean up

```bash
docker compose down
```

This stops the local stack. Keep volumes until you have finished checking your work. Delete demo documents and test accounts only in the isolated instance. Record the Click version, browser, role, locale and actual result for each demo when using this as an acceptance test.
