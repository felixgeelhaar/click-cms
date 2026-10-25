#!/usr/bin/env node
/** Run TurboScience's actual delivery clients against an isolated loopback CMS. */
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { stripTypeScriptTypes, createRequire } from 'node:module';
import { parseArgs } from 'node:util';
import { resolve } from 'node:path';

const { values } = parseArgs({ options: {
  site: { type: 'string' }, base: { type: 'string' },
} });
assert(values.site && values.base, 'Provide --site and --base');
const base = new URL(values.base);
assert(['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname), 'Only loopback CMS hosts are permitted');
assert.equal(base.protocol, 'http:');
const require = createRequire(new URL('../../admin-ui/package.json', import.meta.url));
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<!doctype html><html><body></body></html>');
for (const name of ['document', 'DOMParser', 'Node', 'Element', 'HTMLElement']) {
  globalThis[name] = dom.window[name];
}
const dataUrl = (source) => 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
const source = async (name) => stripTypeScriptTypes(await readFile(resolve(values.site, 'src/lib', name + '.ts'), 'utf8'));
const sections = dataUrl(await source('sections-client'));
const pageSource = (await source('clickcms-client')).replace("from './sections-client'", `from '${sections}'`);
const { fetchPageFromClickCms } = await import(dataUrl(pageSource));
const { fetchChrome } = await import(dataUrl(await source('clickcms-chrome')));
const exportBase = resolve(values.site, 'bludit-content-export');
const exports = (await readdir(exportBase, { withFileTypes: true })).filter((entry) => entry.isDirectory()).map((entry) => entry.name).sort();
assert(exports.length, 'No checked-in content export');
const exported = JSON.parse(await readFile(resolve(exportBase, exports.at(-1), 'export.json'), 'utf8'));
const expected = Object.fromEntries(exported.data.map((entry) => [entry.key, entry]));
const snapshot = {};
for (const locale of ['de', 'en']) {
  const page = await fetchPageFromClickCms(values.base, 'home', locale);
  const chrome = await fetchChrome(values.base, locale, { siteBase: '/2026/' });
  assert(page, `${locale} page client returned null`);
  assert.deepEqual(page.sections.map((section) => section.design), ['hero', 'banner', 'services', 'stats', 'partners', 'contact']);
  assert.equal(page.sections[0].title, expected[`${locale}-hero-headline`].title.trim(), `${locale} hero heading differs from the checked-in export`);
  assert.equal(page.sections[2].blocks.length, 4, `${locale} service cards missing`);
  for (const block of page.sections[2].blocks) {
    assert.equal(block.images.length, 1, `${locale} service image missing`);
    assert(block.images[0].startsWith(base.origin + '/2026/cms2/api/media/file/fixture-'), `${locale} service image was not resolved by the CMS`);
  }
  assert.deepEqual(chrome.nav.map((item) => item.anchor), ['#services', '#stats', '#contact']);
  assert.equal(Object.keys(chrome.texts).length, 6, `${locale} form strings missing`);
  assert(chrome.texts['form.submit']?.trim());
  const media = page.sections.flatMap((section) => [section.video, section.poster, ...section.blocks.flatMap((block) => block.images)]).filter(Boolean);
  assert(media.length >= 6, `${locale} media references missing`);
  for (const url of media) {
    if (!url.includes('/api/media/file/')) continue;
    assert.equal(new URL(url).origin, base.origin, 'CMS media must remain on the isolated host');
    assert(new URL(url).pathname.startsWith('/2026/cms2/api/media/file/'), 'CMS prefix was lost or duplicated');
    const response = await fetch(url, { headers: { Range: 'bytes=0-0' } });
    assert([200, 206].includes(response.status), `Media URL is not reachable: ${url} (${response.status})`);
    await response.body?.cancel();
  }
  snapshot[locale] = { page, chrome };
}
dom.window.close();
console.log(JSON.stringify(snapshot));
