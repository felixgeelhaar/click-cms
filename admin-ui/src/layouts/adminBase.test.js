import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

/**
 * Deep links under /admin/… must keep ./_astro assets resolving to the admin
 * mount. The layout injects a <base> from the pathname; these pin that the
 * script is still in the source and that relative resolution needs it.
 */
describe('admin deep-link base', () => {
  it('ships an inline base injector in AdminLayout', () => {
    const here = dirname(fileURLToPath(import.meta.url));
    const src = readFileSync(join(here, 'AdminLayout.astro'), 'utf8');
    expect(src).toContain('document.createElement(\'base\')');
    expect(src).toContain('insertBefore(base');
    expect(src).toMatch(/\/admin\)\(\?:\\\/\|\$\)/);
  });

  it('resolves relative assets against the admin mount, not the deep path', () => {
    const deep = 'http://127.0.0.1:8080/admin/pages/edit/home';
    const mount = 'http://127.0.0.1:8080/admin/';
    expect(new URL('./_astro/app.js', deep).href).toBe(
      'http://127.0.0.1:8080/admin/pages/edit/_astro/app.js',
    );
    expect(new URL('./_astro/app.js', mount).href).toBe(
      'http://127.0.0.1:8080/admin/_astro/app.js',
    );
  });
});
