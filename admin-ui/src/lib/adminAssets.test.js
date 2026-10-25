import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { it, expect } from 'vitest';

const layout = readFileSync('src/layouts/AdminLayout.astro', 'utf8');
for (const path of ['/admin', '/admin/', '/admin/pages/edit/home', '/2026/cms/admin/pages/edit/home']) {
  it(`resolves built assets from the admin root on ${path}`, () => {
    const script = layout.match(/<script is:inline>([\s\S]*?)<\/script>/)?.[1];
    expect(script).toBeTruthy();
    const dom = new JSDOM(`<head><script>${script}</script><link rel="stylesheet" href="./_astro/app.css"></head>`, {
      url: `http://localhost${path}`, runScripts: 'dangerously',
    });
    const root = path.slice(0, path.indexOf('/admin')) + '/admin/';
    expect(dom.window.document.querySelector('link').href).toBe(`http://localhost${root}_astro/app.css`);
    dom.window.close();
  });
}
