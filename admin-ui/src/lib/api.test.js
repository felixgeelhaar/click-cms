import { describe, it, expect, beforeEach, vi } from 'vitest';
import { installCsrfFetch, setCsrfToken } from './api.js';

/**
 * The one place a request can be got wrong for every call site at once.
 *
 * Both concerns it carries are invisible at the call site by design — a
 * component writes `fetch('/api/pages')` and neither remembers the CSRF token
 * nor knows where the CMS is installed — so both are pinned here.
 */
describe('the installed fetch wrapper', () => {
  let original;

  /** Load the admin at a given URL, with a fresh wrapper over a spy. */
  const install = (pathname) => {
    window.history.replaceState({}, '', pathname);
    window.__clickCsrfInstalled = false;
    original = vi.fn(() => Promise.resolve(new Response('{}')));
    window.fetch = original;
    installCsrfFetch();
  };

  beforeEach(() => setCsrfToken(null));

  it('sends a request to the installation it is running in', async () => {
    install('/2026/cms/admin/pages');

    await window.fetch('/api/pages');

    expect(original).toHaveBeenCalledWith('/2026/cms/api/pages', expect.anything());
  });

  it('changes nothing for a CMS at the domain root', async () => {
    install('/admin/pages');

    await window.fetch('/api/pages');

    expect(original).toHaveBeenCalledWith('/api/pages', expect.anything());
  });

  /** A URL that already names its destination is not this wrapper's business. */
  it('leaves absolute and protocol-relative URLs alone', async () => {
    install('/2026/cms/admin');

    await window.fetch('https://example.com/api/pages');
    await window.fetch('//example.com/api/pages');

    expect(original).toHaveBeenNthCalledWith(1, 'https://example.com/api/pages', expect.anything());
    expect(original).toHaveBeenNthCalledWith(2, '//example.com/api/pages', expect.anything());
  });

  /**
   * Prefixing an already-prefixed URL would produce /2026/cms/2026/cms/api/…,
   * which 404s in a way that looks like the endpoint is missing.
   */
  it('never applies the prefix twice', async () => {
    install('/2026/cms/admin');

    await window.fetch('/2026/cms/api/pages');

    expect(original).toHaveBeenCalledWith('/2026/cms/api/pages', expect.anything());
  });

  it('still adds the CSRF token to an unsafe request', async () => {
    install('/2026/cms/admin');
    setCsrfToken('tok');

    await window.fetch('/api/pages', { method: 'POST' });

    const [url, init] = original.mock.calls[0];
    expect(url).toBe('/2026/cms/api/pages');
    expect(new Headers(init.headers).get('X-Click-CSRF')).toBe('tok');
  });
});

describe('CSRF destination and Request support', () => {
  let original;
  beforeEach(() => {
    window.history.replaceState({}, '', '/admin');
    window.__clickCsrfInstalled = false;
    original = vi.fn(async () => new Response('{}'));
    window.fetch = original;
    installCsrfFetch();
    setCsrfToken('private-token');
  });

  it('does not disclose the token to another origin', async () => {
    await window.fetch('https://outside.example/upload', { method: 'POST' });
    expect(new Headers(original.mock.calls[0][1].headers).has('X-Click-CSRF')).toBe(false);
  });

  it('uses the method and headers of a Request object', async () => {
    // nox:ignore TAINT-006 -- window.fetch is a vi.fn mock; this same-origin fixture performs no network request.
    await window.fetch(new Request(window.location.origin + '/api/pages', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }));
    const [, init] = original.mock.calls[0];
    expect(new Headers(init.headers).get('X-Click-CSRF')).toBe('private-token');
    expect(new Headers(init.headers).get('Content-Type')).toBe('application/json');
  });
});
