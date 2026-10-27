import { test, expect } from '@playwright/test';
test.skip(process.env.CLICK_E2E !== '1', 'Requires an isolated running CMS');
test('administrator configures form alerts without file edits and receives translated test feedback', async ({ page }) => {
  test.setTimeout(90000);
  await page.goto('/admin/');
  await page.locator('#login-username').fill(process.env.CLICK_E2E_USER || 'admin');
  await page.locator('#login-password').fill(process.env.CLICK_E2E_PASS || 'admin-demo-pass-99');
  await page.locator('button[type=submit]').click();
  await expect(page.locator('.admin-app .main-content')).toBeVisible();
  const session = await (await page.request.get('/api/auth/check')).json();
  const headers = { 'X-Click-CSRF': session.data.csrfToken };
  await page.request.post('/api/plugins/forms/activate', { headers });
  await page.goto('/admin/plugins');
  const card = page.locator('.plugin-card').filter({ has: page.getByRole('heading', { name: 'Forms', exact: true }) });
  await card.getByRole('button', { name: 'Settings', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/plugins\/forms\/settings$/);
  const panel = page.locator('.plugin-settings');
  await expect(panel.getByRole('heading', { name: 'Form email alerts' })).toBeVisible();
  await expect(panel.locator('fieldset')).toBeEnabled();
  const original = (await (await page.request.get('/api/forms/email-settings')).json()).data;
  const recipient = `editor-${Date.now()}@example.invalid`;
  try {
    await panel.locator('input[type=checkbox]').uncheck();
    await panel.locator('#plugin-forms-to').fill(recipient);
    await panel.locator('#plugin-forms-from').fill('cms@example.invalid');
    await panel.locator('#plugin-forms-locale').selectOption('de');
    await expect(panel.getByRole('button', { name: 'Send test email', exact: true })).toBeDisabled();
    await panel.getByRole('button', { name: 'Save email settings' }).click();
    await expect(panel.getByRole('status')).toHaveText('Email settings saved.');
    await page.reload();
    await expect(panel.locator('#plugin-forms-to')).toHaveValue(recipient);
    await expect(panel.locator('#plugin-forms-locale')).toHaveValue('de');
    // Avoid sending email from CI; real PHP hand-offs are verified separately with a local capture transport.
    await page.route('**/api/forms/email-test', route => route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ error: 'The server could not send the test email. Your settings are saved. Ask your hosting provider to check email sending.' }) }));
    await page.locator('#message-language').selectOption('de');
    await panel.getByRole('button', { name: 'Test-E-Mail senden', exact: true }).click();
    await expect(panel.getByRole('alert')).toContainText('Deine Einstellungen sind gespeichert');
    await expect(panel.locator('#plugin-forms-to')).toHaveValue(recipient);
    await page.route('**/api/forms/email-test', route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true}' }));
    await panel.getByRole('button', { name: 'Test-E-Mail senden', exact: true }).click();
    await expect(panel.getByRole('status')).toContainText('Prüfe den Posteingang');
    const anonymous = await page.context().browser().newContext();
    try {
      expect((await anonymous.request.post(new URL('/api/forms/email-test', page.url()).href)).status()).toBe(401);
    } finally { await anonymous.close(); }
  } finally {
    await page.request.put('/api/forms/email-settings', { headers, data: original });
    await page.goto('/admin/settings');
    await expect(page.locator('.plugin-settings')).toHaveCount(0);
  }
});
