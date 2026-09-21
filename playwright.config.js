// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * Optional local smoke against a running Click CMS instance.
 *
 * Specs skip unless `CLICK_E2E=1` is set. Chrome is preferred via
 * `channel: 'chrome'` (same as `scripts/screenshots`) so Playwright need not
 * download a browser when the host already has Chrome.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.CLICK_E2E_BASE_URL || 'http://127.0.0.1:8080',
    ...devices['Desktop Chrome'],
    ...(process.env.CLICK_E2E_NO_CHANNEL
      ? {}
      : { channel: 'chrome' }),
  },
});
