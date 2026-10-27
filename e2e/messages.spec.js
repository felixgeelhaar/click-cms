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
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page.getByRole('alert')).toHaveText('Benutzername oder Passwort ist nicht korrekt.');
    await page.locator('#message-language').selectOption('en');
    await expect(page.getByRole('alert')).toHaveText('Invalid credentials');
    await page.reload();
    await expect(page.locator('#message-language')).toHaveValue('en');
    await page.locator('#message-language').selectOption('de');
    diagnostic = '<img src=x onerror=alert(1)> plugin failure';
    await page.locator('#login-username').fill('verification-user');
    await page.locator('#login-password').fill('verification-only');
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Die Aktion konnte nicht abgeschlossen werden.');
    await page.getByRole('button', { name: 'Technische Details', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText(diagnostic);
    await expect(page.getByRole('alert').locator('img')).toHaveCount(0);
  } finally { await context.close(); }
});
