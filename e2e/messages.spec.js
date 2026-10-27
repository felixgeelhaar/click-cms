import { test, expect } from '@playwright/test';
import { existsSync } from 'node:fs';
import { resolve, sep } from 'node:path';

// Exercise the production build with controlled errors, without login attempts
// against a real account or dependencies on a particular PHP error response.
test('editor errors follow the saved message language in the built admin', async ({ browser }) => {
  const root = resolve('public');
  test.skip(!existsSync(resolve(root, 'admin/index.html')), 'Build the admin first');
  const context = await browser.newContext({ locale: 'de-DE' });
  let diagnostic = 'Invalid credentials';
  await context.route('http://click.test/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/api/')) {
      if (url.pathname === '/api/auth/check') return route.fulfill({ json: { data: { authenticated: false } } });
      if (url.pathname === '/api/auth/sso/status') return route.fulfill({ json: { data: { enabled: false, passwordLogin: true } } });
      if (url.pathname === '/api/auth/login') return route.fulfill({ status: 401, json: { error: diagnostic } });
      return route.fulfill({ json: {} });
    }
    const file = resolve(root, '.' + url.pathname + (url.pathname.endsWith('/') ? 'index.html' : ''));
    if (file.startsWith(root + sep) && existsSync(file)) return route.fulfill({ path: file });
    return route.fulfill({ status: 404 });
  });
  try {
    const page = await context.newPage();
    await page.goto('http://click.test/admin/');
    await page.locator('#login-username').fill('verification-user');
    await page.locator('#login-password').fill('verification-only');
    await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await expect(page.getByRole('alert')).toHaveText('Benutzername oder Passwort ist nicht korrekt.');
    await page.locator('#message-language').selectOption('en');
    await expect(page.getByRole('alert')).toHaveText('Invalid credentials');
    await page.reload();
    await expect(page.locator('#message-language')).toHaveValue('en');
    await page.locator('#message-language').selectOption('de');
    diagnostic = '<img src=x onerror=alert(1)> plugin failure';
    await page.locator('#login-username').fill('verification-user');
    await page.locator('#login-password').fill('verification-only');
    await page.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Die Aktion konnte nicht abgeschlossen werden.');
    await page.getByRole('button', { name: 'Technische Details', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText(diagnostic);
    await expect(page.getByRole('alert').locator('img')).toHaveCount(0);
  } finally { await context.close(); }
});

test('German interface renders every admin route and keeps authored content unchanged', async ({ browser }, testInfo) => {
  test.setTimeout(120_000);
  const root = resolve('public');
  test.skip(!existsSync(resolve(root, 'admin/index.html')), 'Build the admin first');
  const context = await browser.newContext({ locale: 'de-DE', viewport: { width: 1440, height: 1000 } });
  const capabilities = ['content.view', 'content.create', 'content.edit.any', 'content.edit.own', 'content.publish', 'media.view', 'media.upload', 'settings.manage', 'users.manage', 'plugins.manage', 'plugins.install', 'edit.freeform'];
  const user = { username: 'verification-editor', displayName: 'Save Changes', role: 'admin', capabilities };
  const plugins = ['visual-builder', 'webhooks', 'collaboration'].map(id => ({ id, name: id, state: 'active' }));
  const errors = [];
  await context.route('http://click.test/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/api/')) {
      const responses = {
        '/api/auth/check': { data: { authenticated: true, user } },
        '/api/plugins': { data: plugins },
        '/api/site': { data: { id: 'test', title: 'My English website' } },
        '/api/settings': { data: { siteName: 'My English website', headless: false, freeformEditing: true } },
        '/api/security/health': { data: { checks: [{ id: 'authentication', label: 'Authentication', status: 'good', value: 'enabled', detail: 'The configuration enables authenticated administration.', action: 'Review core.auth.enabled in config/core.json.' }] } },
        '/api/media/capabilities': { data: { acceptedMimeTypes: [], variants: [], resizingAvailable: true } },
        '/api/themes': { data: { themes: [] } },
        '/api/marketplace': { data: { plugins: [], configured: false } },
        '/api/updates': { data: { configured: false, policy: 'minor', currentVersion: '1.11.0' } },
        '/api/collections': { data: [{ id: 'articles', label: 'Articles', titleField: 'title', fields: [{ name: 'title', type: 'text', label: 'Custom English label' }] }] },
        '/api/pages/home': { data: { slug: 'home', data: { title: 'Save Changes', sections: [] }, revision: 'test-revision', publication: { published: true, hasUnpublishedChanges: true, publishedAt: '2026-10-20T10:00:00Z' } }, locale: 'en' },
        '/api/pages': { data: [], locales: ['en', 'de'], defaultLocale: 'en' },
        '/api/section-types': { data: [] },
        '/api/auth/2fa': { data: { enabled: false } },
        '/api/users': { data: [{ slug: user.username, data: user }] },
        '/api/profile': { data: user },
      };
      return route.fulfill({ json: responses[url.pathname] ?? { data: [] } });
    }
    const file = resolve(root, '.' + url.pathname + (url.pathname.endsWith('/') ? 'index.html' : ''));
    if (file.startsWith(root + sep) && existsSync(file)) return route.fulfill({ path: file });
    if (url.pathname.startsWith('/admin/')) return route.fulfill({ path: resolve(root, 'admin/index.html') });
    return route.fulfill({ status: 404 });
  });
  try {
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    const screens = [
      ['', 'Übersicht'], ['pages', 'Seiten'], ['pages/new', 'Neue Seite'],
      ['pages/edit/home', 'Seite bearbeiten'], ['collections/articles/entries/new', 'Neuer Eintrag für Articles'],
      ['collections', 'Sammlungen'], ['media', 'Medien'], ['users', 'Benutzer'],
      ['profile', 'Profil'], ['plugins', 'Plugins'], ['marketplace', 'Erweiterungsverzeichnis'],
      ['settings', 'Einstellungen'], ['security', 'Sicherheitsprüfung'], ['themes', 'Designs'],
      ['updates', 'Aktualisierungen'], ['redirects', 'Weiterleitungen'], ['menus', 'Menüs'],
      ['submissions', 'Formularnachrichten'], ['builder', 'Visueller Seitenbaukasten'],
      ['reviews', 'Prüfungen'], ['release', 'Gemeinsam veröffentlichen'], ['password', 'Passwort ändern'],
    ];
    for (const [path, heading] of screens) {
      await page.goto(`http://click.test/admin/${path}`);
      await expect(page.getByRole('main').getByRole('heading', { name: heading, exact: true })).toBeVisible();
      await expect(page.locator('.admin-app')).toHaveAttribute('lang', 'de');
      // The author's display name happens to match an English UI label.
      await expect(page.locator('.brand-name')).toHaveText('Save Changes');
      if (['pages/new', 'pages/edit/home', 'settings', 'security', 'media'].includes(path)) {
        await page.screenshot({ path: testInfo.outputPath(`german-${path.replaceAll('/', '-')}.png`), fullPage: true });
      }
    }
    await page.goto('http://click.test/admin/settings');
    await expect(page.locator('input[type="text"]')).toHaveValue('My English website');
    await page.locator('#message-language').selectOption('en');
    await expect(page.getByRole('main').getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
    await expect(page.locator('input[type="text"]')).toHaveValue('My English website');
    await expect(page).toHaveTitle('Settings - Click CMS Admin');
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});
