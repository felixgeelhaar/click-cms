// @ts-check
import { test, expect } from '@playwright/test';

test.skip(!process.env.CLICK_E2E, 'Set CLICK_E2E=1 with a running instance');

/**
 * Short admin smoke: sign in, open the home page editor, see Save.
 *
 * Credentials default to the seeded demo account; override with
 * CLICK_E2E_USER / CLICK_E2E_PASS when needed.
 *
 * A fresh install still has mustChangePassword. After sign-in the Choose a
 * password screen appears — fill current=`admin` (the published install
 * password) and new=`CLICK_E2E_PASS`, then continue.
 */
test('admin can open the home page editor and see Save', async ({ page }) => {
  const user = process.env.CLICK_E2E_USER || 'admin';
  const pass = process.env.CLICK_E2E_PASS || 'admin-demo-pass-99';
  const installedPass = 'admin';

  await page.goto('/admin/');

  // Prefer the known e2e password; fall back to the install default when the
  // account has never been changed (CI after seed, local first boot).
  await page.fill('#login-username', user);
  await page.fill('#login-password', pass);
  await page.click('button[type=submit]');

  if (await page.locator('#login-username').isVisible({ timeout: 2000 }).catch(() => false)) {
    await page.fill('#login-username', user);
    await page.fill('#login-password', installedPass);
    await page.click('button[type=submit]');
  }

  const choosePassword = page.getByRole('heading', { name: 'Choose a password' });
  if (await choosePassword.isVisible({ timeout: 3000 }).catch(() => false)) {
    await page.fill('#current', installedPass);
    await page.fill('#next', pass);
    await page.fill('#confirm', pass);
    await page.click('button[type=submit]');
    await expect(choosePassword).toBeHidden({ timeout: 15000 });
  }

  await page.goto('/admin/pages');
  await expect(page.getByRole('heading', { name: 'Pages' })).toBeVisible({ timeout: 15000 });

  await page.goto('/admin/pages/edit/home');
  await expect(page.getByRole('button', { name: 'Save' })).toBeVisible({ timeout: 15000 });
});
