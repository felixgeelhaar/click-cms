#!/usr/bin/env node
/**
 * Recapture the documentation screenshots from a running instance.
 *
 *   node scripts/screenshots/capture.mjs [--base=URL] [--out=DIR] [--only=name,name]
 *
 * Every picture in `docs/images` comes from here. They are captures of the real
 * admin driven against a real seeded site, never mock-ups, because a mock-up
 * drifts from the product silently and a capture drifts loudly — the next run
 * simply looks different.
 *
 * ## The check that matters
 *
 * Each shot declares the heading it expects, and a mismatch fails the run. That
 * is not ceremony: an account without permission for a screen is redirected to
 * the Dashboard, and a screenshot of the wrong screen still looks like a
 * screenshot. Without the assertion, the way you find out is a reader telling
 * you the picture does not match the words.
 *
 * ## Why 1400 wide with no downscaling
 *
 * The first version of this captured at 2× and shrank the files with `sips`,
 * which is macOS-only and made the committed images impossible to reproduce on
 * anything else. Rendering straight to the width the documentation displays is
 * portable, is one step instead of two, and — because the text is rasterised at
 * its final size rather than resampled — is no worse to read.
 *
 * ## Prerequisites
 *
 *   docker run -d --name click-shots -p 8199:80 click-cms:latest
 *   docker exec -u www-data click-shots php bin/click-seed.php
 *   cd scripts/screenshots && npm install
 *
 * The seeded example site is what the pictures show, so the guide and the
 * screenshots describe the same content. Chrome is used via Playwright's
 * `channel: 'chrome'` rather than a downloaded browser, so there is nothing to
 * fetch beyond the package itself.
 */

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, '../..');

const args = new Map(
  process.argv.slice(2)
    .filter((a) => a.startsWith('--'))
    .map((a) => {
      const [k, ...v] = a.slice(2).split('=');
      return [k, v.join('=') || true];
    }),
);

const BASE = args.get('base') || 'http://localhost:8199';
const OUT = resolve(REPO, args.get('out') || 'docs/images');
const ONLY = typeof args.get('only') === 'string' ? args.get('only').split(',') : null;

/** The installed credentials, and what the first run must change them to. */
const USERNAME = 'admin';
const INSTALLED_PASSWORD = 'admin';
const PASSWORD = 'workshop-2026';

/**
 * Documentation is displayed at roughly 700px, so 1400 gives a retina display
 * something to work with without producing files nobody wants in a repository.
 */
const VIEWPORT = { width: 1400, height: 900 };

/**
 * Every picture, in the order a reader meets it.
 *
 * `heading` is asserted after navigation. `scrollTo` frames the shot on a piece
 * of text instead of the top of the page, which is how the page editor becomes
 * four readable pictures rather than one 13,000-pixel strip nobody can use.
 */
const SHOTS = [
  // The first-run flow. These two are captured before the password is changed,
  // so they must come first and in this order.
  { name: 'sign-in', route: '/admin/', stage: 'anonymous' },
  { name: 'choose-password', stage: 'password-prompt' },

  { name: 'dashboard', route: '/admin', heading: 'Dashboard' },
  { name: 'pages-list', route: '/admin/pages', heading: 'Pages', waitFor: 'text=Rivet & Oak' },

  // The page editor, framed four ways.
  {
    name: 'edit-page',
    route: '/admin/pages/edit/home',
    heading: 'Edit Page',
    // The settled state, not a timeout: an early capture caught the banner
    // before its data arrived and documented a published page as unsaved.
    waitFor: 'text=Published — the public site matches what is here',
    alsoWaitFor: 'text=Media and text',
  },
  { name: 'section-controls', route: null, scrollTo: 'Facts', offset: 80 },
  { name: 'add-section', route: null, scrollTo: 'Add a section', offset: 260 },
  { name: 'save-and-history', route: null, scrollTo: 'VERSION HISTORY', offset: 220 },

  { name: 'media-library', route: '/admin/media', heading: 'Media' },
  { name: 'collections', route: '/admin/collections', heading: 'Collections' },
  { name: 'submissions', route: '/admin/submissions', heading: 'Form submissions' },
  { name: 'builder', route: '/admin/builder', heading: 'Visual Builder' },
  { name: 'profile', route: '/admin/profile', heading: 'Profile' },

  // Administrator-only screens.
  { name: 'menus', route: '/admin/menus', heading: 'Menus', waitFor: 'select' },
  { name: 'plugins', route: '/admin/plugins', heading: 'Plugins' },
  { name: 'marketplace', route: '/admin/marketplace', heading: 'Marketplace' },
  { name: 'users', route: '/admin/users', heading: 'Users' },
  { name: 'redirects', route: '/admin/redirects', heading: 'Redirects' },
  { name: 'themes', route: '/admin/themes', heading: 'Themes' },
  { name: 'settings', route: '/admin/settings', heading: 'Settings' },
  { name: 'updates', route: '/admin/updates', heading: 'Updates' },

  // The public site — the point of all of it.
  { name: 'public-site', route: '/', public: true },
  { name: 'public-site-mobile', route: '/', public: true, viewport: { width: 520, height: 1000 } },
];

const wanted = (shot) => !ONLY || ONLY.includes(shot.name);

let failures = 0;

const report = (ok, name, detail = '') => {
  if (!ok) failures += 1;
  console.log(`  ${ok ? 'ok     ' : 'FAILED '}${name.padEnd(22)}${detail}`);
};

await mkdir(OUT, { recursive: true });

const browser = await chromium.launch({ channel: 'chrome' });

try {
  const ctx = await browser.newContext({ viewport: VIEWPORT, colorScheme: 'light' });
  const page = await ctx.newPage();

  /* ------------------------------------------------- the first-run flow -- */

  await page.goto(`${BASE}/admin/`, { waitUntil: 'networkidle' });

  const signInVisible = await page.locator('#login-username').count() > 0;
  if (!signInVisible) {
    console.error(`No sign-in form at ${BASE}/admin/ — is the instance running and freshly installed?`);
    process.exit(1);
  }

  await page.fill('#login-username', USERNAME);
  await page.fill('#login-password', INSTALLED_PASSWORD);
  if (wanted({ name: 'sign-in' })) {
    await page.screenshot({ path: `${OUT}/sign-in.png` });
    report(true, 'sign-in');
  }

  await page.click('button[type=submit]');

  // A fresh install refuses everything until the published installed password is
  // replaced, so this screen is unavoidable — and therefore worth documenting.
  const prompted = await page.locator('#next').count() > 0
    || await page.waitForSelector('#next', { timeout: 8000 }).then(() => true).catch(() => false);

  if (prompted) {
    if (wanted({ name: 'choose-password' })) {
      await page.screenshot({ path: `${OUT}/choose-password.png` });
      report(true, 'choose-password');
    }
    await page.fill('#current', INSTALLED_PASSWORD);
    await page.fill('#next', PASSWORD);
    await page.fill('#confirm', PASSWORD);
    await page.click('button[type=submit]');
  } else {
    // Only a failure if it was actually asked for: reported unconditionally, a
    // second run of `--only=users` exited non-zero over a shot nobody wanted.
    if (wanted({ name: 'choose-password' })) {
      report(false, 'choose-password', 'not a fresh install — recreate the instance to capture it');
    }

    // Signing in again is needed either way — this must not sit inside the
    // report above, or skipping the report skips the sign-in and every later
    // shot times out waiting for a dashboard nobody reached.
    if (await page.locator('#login-username').count() > 0) {
      await page.fill('#login-username', USERNAME);
      await page.fill('#login-password', PASSWORD);
      await page.click('button[type=submit]');
    }
  }

  const signedIn = await page.waitForSelector('text=Total Pages', { timeout: 20000 })
    .then(() => true).catch(() => false);
  if (!signedIn) {
    console.error(
      `\nCould not reach the dashboard at ${BASE}.\n`
      + `Check the instance is running and that the admin password is either the\n`
      + `installed one or "${PASSWORD}" — this tool sets the latter on a fresh install.`,
    );
    process.exit(1);
  }

  /* ------------------------------------------------------- everything else -- */

  for (const shot of SHOTS) {
    if (shot.stage || !wanted(shot)) continue;

    if (shot.public) {
      const pub = await browser.newContext({
        viewport: shot.viewport ?? VIEWPORT,
        colorScheme: 'light',
      });
      const pubPage = await pub.newPage();
      await pubPage.goto(`${BASE}${shot.route}`, { waitUntil: 'networkidle' });
      await pubPage.waitForTimeout(900);
      await pubPage.screenshot({ path: `${OUT}/${shot.name}.png` });
      await pub.close();
      report(true, shot.name);
      continue;
    }

    if (shot.route) {
      await page.goto(`${BASE}${shot.route}`, { waitUntil: 'networkidle' });

      let missing = null;
      for (const selector of [shot.waitFor, shot.alsoWaitFor].filter(Boolean)) {
        const found = await page.waitForSelector(selector, { timeout: 20000 })
          .then(() => true).catch(() => false);
        if (!found) { missing = selector; break; }
      }
      if (missing) {
        // `continue` here previously continued the inner loop, so a shot whose
        // content never arrived was reported as failed and then captured anyway
        // — the picture and the verdict disagreeing.
        report(false, shot.name, `never saw ${missing}`);
        continue;
      }
      await page.waitForTimeout(1200);

      // The assertion that stops a redirect being shipped as a screenshot.
      if (shot.heading) {
        const seen = await page.evaluate(
          () => document.querySelector('main h1, h1')?.innerText?.trim() ?? '',
        );
        if (seen !== shot.heading) {
          report(false, shot.name, `expected "${shot.heading}", got "${seen}"`);
          continue;
        }
      }
    }

    if (shot.scrollTo) {
      const target = page.locator(`text=${shot.scrollTo}`).first();
      if (await target.count() === 0) {
        report(false, shot.name, `no text "${shot.scrollTo}" to frame on`);
        continue;
      }
      await target.scrollIntoViewIfNeeded();
      await page.evaluate((o) => window.scrollBy(0, -o), shot.offset ?? 120);
      await page.waitForTimeout(500);
    } else if (shot.route) {
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.waitForTimeout(300);
    }

    await page.screenshot({ path: `${OUT}/${shot.name}.png` });
    report(true, shot.name);
  }
} finally {
  await browser.close();
}

console.log(`\n${failures === 0 ? 'All shots captured.' : `${failures} shot(s) failed.`}`);
console.log(`Written to ${OUT}`);
process.exit(failures === 0 ? 0 : 1);
