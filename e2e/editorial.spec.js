import { test, expect } from '@playwright/test';
import { resolve } from 'node:path';
const out = resolve(process.env.CLICK_E2E_SCREENSHOTS || 'test-results/editorial-screenshots');
test.skip(process.env.CLICK_E2E !== '1', 'Requires isolated running CMS');
test('Editorial screens remain usable in both themes and on mobile', async ({ page }) => {
  test.setTimeout(120000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/admin/');
  await page.locator('#login-username').fill(process.env.CLICK_E2E_USER || 'admin');
  await page.locator('#login-password').fill(process.env.CLICK_E2E_PASS || 'admin-demo-pass-99');
  await page.locator('button[type=submit]').click();
  await expect(page.locator('h1')).toHaveText('Dashboard');
  const headings = { '': 'Dashboard', pages: 'Pages', 'pages/edit/home': 'Edit Page', collections: 'Collections', media: 'Media', users: 'Users', profile: 'Profile', plugins: 'Plugins', marketplace: 'Marketplace', settings: 'Settings', security: 'Security health', themes: 'Themes', updates: 'Updates', redirects: 'Redirects', menus: 'Menus', submissions: 'Form submissions', reviews: 'Reviews', release: 'Release', builder: 'Visual Builder', webhooks: 'Webhooks', password: 'Change password', 'pages/new': 'New Page' };
  const optional = new Set(['release', 'builder', 'webhooks']);
  const available = await page.locator('.sidebar a').evaluateAll(links => links.map(link => new URL(link.href).pathname));
  const routes = ['','pages','pages/edit/home','collections','media','users','profile','plugins','marketplace','settings','security','themes','updates','redirects','menus','submissions','reviews','release','builder','webhooks','password','pages/new'].filter(route => !optional.has(route) || available.includes(`/admin/${route}`));
  for (const theme of (process.env.CLICK_E2E_MOBILE_ONLY === '1' ? [] : ['light', 'dark'])) {
    for (const route of routes) {
      await page.goto(`/admin/${route}`);
      await expect(page.locator('h1').first()).toHaveText(headings[route]);
      if ((await page.locator('html').getAttribute('data-theme') || 'light') !== theme) await page.getByRole('button', { name: /^Dark theme/ }).click();
      await expect(page.locator('.main-content')).toBeVisible();
      await page.waitForLoadState('networkidle');
      if (route === 'pages/edit/home') await expect(page.locator('#page-title')).not.toHaveValue('');
      await page.screenshot({ path: `${out}/${route.replaceAll('/', '-') || 'dashboard'}-${theme}.png`, fullPage: true });
    }
  }
  await page.setViewportSize({ width: 390, height: 844 });
  for (const route of routes) {
    await page.goto(`/admin/${route}`);
    await expect(page.locator('h1').first()).toHaveText(headings[route]);
    await page.waitForLoadState('networkidle');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
    expect.soft(overflow, `horizontal overflow on ${route}`).toBe(false);
    await page.screenshot({ path: `${out}/${route.replaceAll('/', '-') || 'dashboard'}-mobile.png`, fullPage: true });
  }
  expect(errors).toEqual([]);
});
