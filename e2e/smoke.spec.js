// @ts-check
import { test, expect } from '@playwright/test';

test.skip(!process.env.CLICK_E2E, 'Set CLICK_E2E=1 with a running instance');

/**
 * Sign in with one password; return whether the API accepted the session.
 *
 * Waits for the login POST to finish before deciding — the form stays visible
 * (and disabled) while the request is in flight, so a visibility check alone
 * races the first attempt.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} user
 * @param {string} pass
 */
async function signIn(page, user, pass) {
  const username = page.locator('#login-username');
  await expect(username).toBeEnabled({ timeout: 15_000 });
  await username.fill(user);
  await page.locator('#login-password').fill(pass);

  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('/api/auth/login') && r.request().method() === 'POST',
      { timeout: 30_000 },
    ),
    page.click('button[type=submit]'),
  ]);

  return response.ok();
}

/**
 * Short admin smoke: sign in, open the home page editor, see Save.
 *
 * Credentials default to the seeded demo account; override with
 * CLICK_E2E_USER / CLICK_E2E_PASS when needed.
 *
 * A fresh install still has mustChangePassword. After sign-in the Choose a
 * password screen appears — fill current=`admin` (the published install
 * password) and new=`CLICK_E2E_PASS`, then continue. CI resets the account
 * so that gate is usually already cleared.
 */
test('admin can open the home page editor and see Save', async ({ page }) => {
  test.setTimeout(90_000);

  const user = process.env.CLICK_E2E_USER || 'admin';
  const pass = process.env.CLICK_E2E_PASS || 'admin-demo-pass-99';
  const installedPass = 'admin';

  await page.goto('/admin/');

  // Prefer the known e2e password; fall back to the install default when the
  // account has never been changed (local first boot without the CI reset).
  if (!(await signIn(page, user, pass))) {
    await expect(page.locator('#login-username')).toBeEnabled({ timeout: 15_000 });
    expect(await signIn(page, user, installedPass)).toBeTruthy();
  }

  const choosePassword = page.getByRole('heading', { name: 'Choose a password' });
  if (await choosePassword.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await page.fill('#current', installedPass);
    await page.fill('#next', pass);
    await page.fill('#confirm', pass);
    await page.click('button[type=submit]');
    await expect(choosePassword).toBeHidden({ timeout: 15_000 });
  }

  await page.goto('/admin/pages');
  await expect(page.getByRole('heading', { name: 'Pages' })).toBeVisible({ timeout: 15_000 });

  await page.goto('/admin/pages/edit/home');
  await expect(page.getByRole('button', { name: 'Save' })).toBeVisible({ timeout: 15_000 });
});
