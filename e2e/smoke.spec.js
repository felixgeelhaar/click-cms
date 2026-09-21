// @ts-check
import { test, expect } from '@playwright/test';

test.skip(!process.env.CLICK_E2E, 'Set CLICK_E2E=1 with a running instance');

/**
 * Short admin smoke: sign in, open the home page editor, see Save.
 *
 * Credentials default to the seeded demo account; override with
 * CLICK_E2E_USER / CLICK_E2E_PASS when needed.
 */
test('admin can open the home page editor and see Save', async ({ page }) => {
  const user = process.env.CLICK_E2E_USER || 'admin';
  const pass = process.env.CLICK_E2E_PASS || 'admin-demo-pass-99';

  await page.goto('/admin/');
  await page.fill('#login-username', user);
  await page.fill('#login-password', pass);
  await page.click('button[type=submit]');

  await page.goto('/admin/pages');
  await expect(page.getByRole('heading', { name: 'Pages' })).toBeVisible({ timeout: 15000 });

  await page.goto('/admin/pages/edit/home');
  await expect(page.getByRole('button', { name: 'Save' })).toBeVisible({ timeout: 15000 });
});
