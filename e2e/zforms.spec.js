import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test.skip(process.env.CLICK_E2E !== '1', 'Requires an isolated running CMS');

test('German public form preserves text and recovers from validation and connection errors', async ({ page }) => {
  const confirmation = '<img src=x onerror="window.injected=true"> Vielen Dank.';
  const html = execFileSync('php', ['-r', `
    require 'vendor/autoload.php';
    $renderer = new Click\\Cms\\Http\\SectionRenderer(new Click\\Cms\\Infrastructure\\Schema\\JsonSectionTypeRepository('config/sections'));
    $page = Click\\Cms\\Domain\\Content\\Content::create(Click\\Cms\\Domain\\ValueObjects\\ContentKey::page('form-check','de'), ['sections'=>[['type'=>'form','values'=>['heading'=>'Kontakt','confirmation'=>$argv[1]]]]]);
    echo $renderer->render($page);
  `, confirmation], { encoding: 'utf8' });
  await page.goto('/contact');
  await page.setContent(html);
  const form = page.locator('form.cms-form');
  await expect(form).toContainText('Ihr Name');
  await form.locator('[name=name]').fill('Browser form check');
  await form.locator('[name=email]').fill('test@example.invalid');
  await form.locator('[name=message]').fill('A stored browser check');
  await page.route('**/api/forms/submit', route => route.abort());
  await form.locator('button[type=submit]').click();
  await expect(form.getByRole('status')).toContainText('Senden nicht möglich');
  await expect(form.locator('button[type=submit]')).toBeEnabled();
  await page.unroute('**/api/forms/submit');
  // Bypass native email validation to exercise the server's translated error.
  await form.locator('[name=email]').fill('invalid');
  await form.evaluate(node => node.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
  await expect(form.getByRole('status')).toContainText('gültige E-Mail-Adresse');
  await form.locator('[name=email]').fill('test@example.invalid');
  await form.locator('button[type=submit]').click();
  await expect(page.locator('.cms-form-thanks')).toHaveText(confirmation);
  await expect(page.locator('.cms-form-thanks img')).toHaveCount(0);
  expect(await page.evaluate(() => window.injected)).toBeUndefined();
});

test('direct API callers cannot bypass payload or persistent rate limits', async ({ request }) => {
  const oversized = await request.post('/api/forms/submit', {
    headers: { 'Accept-Language': 'de' }, data: { locale: 'de', message: 'x'.repeat(33000) },
  });
  expect(oversized.status()).toBe(413);
  expect((await oversized.json()).error).toContain('zu lang');
  let limited;
  for (let attempt = 0; attempt < 6; attempt++) {
    limited = await request.post('/api/forms/submit', {
      headers: { 'X-Forwarded-For': `192.0.2.${attempt}` },
      data: { locale: 'de', name: 'Bounded test', email: 'test@example.invalid', message: 'Rate-limit check' },
    });
    if (limited.status() === 429) break;
    expect(limited.ok()).toBe(true);
  }
  expect(limited.status()).toBe(429);
  expect((await limited.json()).error).toContain('Zu viele');
});
