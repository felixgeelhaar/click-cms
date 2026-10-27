import { test, expect } from '@playwright/test';

test.skip(process.env.CLICK_E2E !== '1', 'Requires an isolated running CMS');

test('bundled plugins retain activation and expose working features', async ({ page }) => {
  test.setTimeout(120000);
  await page.goto('/admin/');
  await page.locator('#login-username').fill(process.env.CLICK_E2E_USER || 'admin');
  await page.locator('#login-password').fill(process.env.CLICK_E2E_PASS || 'admin-demo-pass-99');
  await page.locator('button[type=submit]').click();
  await expect(page.locator('.admin-app .main-content')).toBeVisible();
  const session = await (await page.request.get('/api/auth/check')).json();
  const headers = { 'X-Click-CSRF': session.data.csrfToken };
  const api = async (path, method = 'GET', data) => {
    const response = await page.request.fetch(`/api/${path}`, { method, data, headers });
    const body = await response.json();
    expect(response.ok(), `${path}: ${JSON.stringify(body)}`).toBe(true);
    return body;
  };
  const { data: plugins, issues } = await api('plugins');
  expect(issues).toEqual([]);
  const bundled = ['backup', 'collaboration', 'forms', 'graphql-api', 'search-api', 'visual-builder', 'webhooks'];
  for (const id of bundled) {
    const plugin = plugins.find(entry => entry.id === id);
    expect(plugin, `Missing ${id}`).toBeTruthy();
    if (plugin.state !== 'activated') await api(`plugins/${id}/activate`, 'POST');
    await api(`plugins/${id}/deactivate`, 'POST');
    expect((await api('plugins')).data.find(entry => entry.id === id).state).not.toBe('activated');
    await api(`plugins/${id}/activate`, 'POST');
    expect((await api('plugins')).data.find(entry => entry.id === id).state).toBe('activated');
  }
  expect(Array.isArray((await api('search?q=the')).results)).toBe(true);
  expect((await api('graphql', 'POST', { query: '{ pages { slug title } }' })).data.pages.length).toBeGreaterThan(0);
  expect((await api('backups', 'POST', {})).data.documents).toBeGreaterThan(0);
  expect((await api('backups')).data.backups.length).toBeGreaterThan(0);
  expect((await api('collaboration/presence', 'POST', { page: 'home', locale: 'en' })).data.editors.length).toBeGreaterThan(0);
  const submission = `Plugin form check ${Date.now()}`;
  await api('forms/submit', 'POST', { name: 'Integration test', email: 'test@example.invalid', message: submission });
  const submissions = await api('forms/submissions');
  expect(JSON.stringify(submissions)).toContain(submission);
  await api('forms/submit', 'POST', { website: 'spam.invalid', name: 'Spam', email: 'spam@example.invalid', message: submission });
  expect(await api('forms/submissions')).toEqual(submissions);
  const anonymous = await page.context().browser().newContext();
  try {
    for (const path of ['forms/submissions', 'backups', 'collaboration/comments?page=home', 'webhooks']) {
      const response = await anonymous.request.get(new URL(`/api/${path}`, page.url()).href);
      expect([401, 403]).toContain(response.status());
    }
  } finally {
    await anonymous.close();
  }
  const body = `Plugin integration check ${Date.now()}`;
  await api('collaboration/comments', 'POST', { page: 'home', locale: 'en', body });
  expect(JSON.stringify(await api('collaboration/comments?page=home&locale=en'))).toContain(body);
  const endpoint = (await api('webhooks', 'POST', {
    url: 'https://example.com/click-test', events: ['content.saved'], active: false,
    description: 'Isolated integration test; never sent',
  })).data;
  try {
    expect(endpoint.secret).toBeTruthy();
    expect(JSON.stringify(await api('webhooks'))).not.toContain(endpoint.secret);
  } finally {
    await api(`webhooks/${endpoint.id}`, 'DELETE');
  }
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  for (const [route, heading] of Object.entries({
    plugins: 'Plugins', submissions: 'Form submissions', reviews: 'Reviews',
    builder: 'Visual Builder', webhooks: 'Webhooks',
  })) {
    await page.goto(`/admin/${route}`);
    await expect(page.getByRole('heading', { name: heading, exact: true }).first()).toBeVisible();
    await page.waitForLoadState('networkidle');
  }
  expect(errors).toEqual([]);
});

test('external ZIP stays inactive until activated and survives a reload', async ({ page }) => {
  const { mkdtempSync, rmSync } = await import('node:fs');
  const { tmpdir } = await import('node:os');
  const { join } = await import('node:path');
  const { execFileSync } = await import('node:child_process');
  const directory = mkdtempSync(join(tmpdir(), 'click-external-e2e-'));
  const id = `external-check-${Date.now()}`;
  const name = id.replaceAll('-', ' ');
  const archive = join(directory, 'plugin.zip');
  const bootstrap = `<?php
require_once __DIR__ . '/../../src/Application/Plugin/BasePlugin.php';
class Plugin_${id.replaceAll('-', '_')} extends \\Click\\Cms\\Application\\Plugin\\BasePlugin {
 public function getPluginId(): string { return '${id}'; }
 public function getPluginName(): string { return '${name}'; }
 public function hook_api_routes(array $params): array {
   return ['GET /api/${id}' => fn(): array => ['data' => ['message' => 'External plugin works']]];
 }
}`;
  execFileSync('php', ['-r', '$z=new ZipArchive();$z->open($argv[1],ZipArchive::CREATE);$z->addFromString("plugin/plugin.json",$argv[2]);$z->addFromString("plugin/bootstrap.php",$argv[3]);$z->close();', archive,
    JSON.stringify({ name, version: '1.0.0', dependencies: [], hooks: ['api.routes'] }), bootstrap]);
  try {
    await page.goto('/admin/');
    await page.locator('#login-username').fill(process.env.CLICK_E2E_USER || 'admin');
    await page.locator('#login-password').fill(process.env.CLICK_E2E_PASS || 'admin-demo-pass-99');
    await page.locator('button[type=submit]').click();
    await expect(page.locator('.admin-app .main-content')).toBeVisible();
    await page.goto('/admin/marketplace');
    await page.locator('input[type=file]').setInputFiles(archive);
    await expect(page.locator('.banner.success')).toContainText(`${name} uploaded`);
    const session = await (await page.request.get('/api/auth/check')).json();
    const headers = { 'X-Click-CSRF': session.data.csrfToken };
    expect((await page.request.get(`/api/${id}`)).status()).toBe(404);
    const activate = await page.request.post(`/api/plugins/${id}/activate`, { headers });
    expect(activate.ok()).toBe(true);
    try {
      await page.reload();
      expect((await (await page.request.get(`/api/${id}`)).json()).data.message).toBe('External plugin works');
    } finally {
      const deactivate = await page.request.post(`/api/plugins/${id}/deactivate`, { headers });
      expect(deactivate.ok()).toBe(true);
    }
    expect((await page.request.get(`/api/${id}`)).status()).toBe(404);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});
