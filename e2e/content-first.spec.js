import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';

test.skip(!process.env.CLICK_E2E, 'Set CLICK_E2E=1 with an isolated running instance');

test('content first editor keeps edits across views, saves, previews and fits mobile', async ({ page }) => {
  await page.goto('/admin/');
  await page.locator('#login-username').fill(process.env.CLICK_E2E_USER || 'admin');
  await page.locator('#login-password').fill(process.env.CLICK_E2E_PASS || 'admin-demo-pass-99');
  await page.locator('button[type=submit]').click();
  await expect(page.locator('#login-username')).toBeHidden();
  await page.goto('/admin/pages/edit/home');
  await expect(page.locator('#page-title')).toBeVisible();
  const title = await page.locator('#page-title').inputValue();
  const updated = `${title} — editor check`;
  await page.locator('#page-title').fill(updated);
  const views = page.getByRole('navigation', { name: 'Page editor views' });
  await views.getByRole('button', { name: 'Page settings', exact: true }).click();
  await expect(page.locator('#page-title')).toBeHidden();
  await expect(page.getByText('Unsaved changes', { exact: true })).toBeVisible();
  await expect(page.getByRole('complementary', { name: 'Publishing and review' })).toBeVisible();
  await views.getByRole('button', { name: 'Activity', exact: true }).click();
  await views.getByRole('button', { name: 'Content', exact: true }).click();
  await expect(page.locator('#page-title')).toHaveValue(updated);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.banner.notice')).toContainText('Saved');
  await page.reload();
  await expect(page.locator('#page-title')).toHaveValue(updated);
  await page.locator('#page-title').fill(title);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.banner.notice')).toContainText('Saved');
  await page.getByRole('button', { name: 'Live preview', exact: true }).click();
  await expect(page.locator('iframe[sandbox]')).toBeVisible();
  await expect(page.locator('iframe[sandbox]')).toHaveAttribute('sandbox', '');
  await page.getByRole('button', { name: 'Hide live preview', exact: true }).click();
  const out = process.env.CLICK_E2E_SCREENSHOTS;
  if (out) await mkdir(out, { recursive: true });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.evaluate(() => window.scrollTo(0, 0));
  if (out) await page.screenshot({ path: `${out}/click-editor-content-desktop.png`, fullPage: true });
  await views.getByRole('button', { name: 'Page settings', exact: true }).click();
  if (out) await page.screenshot({ path: `${out}/click-editor-settings-desktop.png`, fullPage: true });
  await views.getByRole('button', { name: 'Content', exact: true }).click();
  for (const width of [768, 390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    for (const view of ['Page settings', 'Activity', 'Content']) {
      await views.getByRole('button', { name: view, exact: true }).click();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${view} at ${width}px`).toBe(true);
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    if (out && width === 390) await page.screenshot({ path: `${out}/click-editor-content-mobile.png`, fullPage: true });
  }
});
