import { describe, it, expect } from 'vitest';
import { basePathFrom, routeFrom, withBaseOf } from './base.js';

/**
 * Where the admin is mounted, worked out from the address bar.
 *
 * The admin is one build served from wherever the CMS is installed, so it
 * cannot be told its prefix at build time — a site at /2026/cms/admin/ and one
 * at /admin/ run the same files. It reads the prefix off its own URL instead,
 * and everything here is a case that would otherwise send a request or a link
 * to the wrong place.
 */
describe('the admin base path', () => {
  it('is empty for a CMS installed at the domain root', () => {
    expect(basePathFrom('/admin')).toBe('');
    expect(basePathFrom('/admin/')).toBe('');
    expect(basePathFrom('/admin/pages')).toBe('');
  });

  it('is everything before /admin for a CMS in a subdirectory', () => {
    expect(basePathFrom('/2026/cms/admin')).toBe('/2026/cms');
    expect(basePathFrom('/2026/cms/admin/pages/edit/home')).toBe('/2026/cms');
  });

  /**
   * A prefix that itself starts with "admin" must not be mistaken for the mount
   * point. Only a whole path segment counts, so the first `/admin` here is part
   * of the installation's own directory name.
   */
  it('matches /admin as a whole segment, not as a string', () => {
    expect(basePathFrom('/admin-tools/admin/pages')).toBe('/admin-tools');
    expect(basePathFrom('/administration/admin')).toBe('/administration');
  });

  /** Nothing sensible to derive, and guessing would be worse than doing nothing. */
  it('is empty when the path does not contain the mount point at all', () => {
    expect(basePathFrom('/somewhere/else')).toBe('');
    expect(basePathFrom('')).toBe('');
  });
});

describe('turning an internal route into a browser URL', () => {
  it('adds the prefix to a route path', () => {
    expect(withBaseOf('/2026/cms/admin/pages', '/admin/media')).toBe('/2026/cms/admin/media');
  });

  it('changes nothing at the domain root', () => {
    expect(withBaseOf('/admin/pages', '/admin/media')).toBe('/admin/media');
  });

  it('keeps a query string on the end where it belongs', () => {
    expect(withBaseOf('/2026/cms/admin', '/admin/pages/new?locale=de'))
      .toBe('/2026/cms/admin/pages/new?locale=de');
  });
});

describe('turning a browser URL into an internal route', () => {
  /**
   * The routing table matches `/admin/pages` and knows nothing about prefixes —
   * which is what keeps 30-odd route comparisons free of this concern. The
   * conversion happens once, here, at the boundary.
   */
  it('takes the prefix off', () => {
    expect(routeFrom('/2026/cms/admin/pages')).toBe('/admin/pages');
    expect(routeFrom('/2026/cms/admin')).toBe('/admin');
  });

  it('leaves a root-installed path alone', () => {
    expect(routeFrom('/admin/pages')).toBe('/admin/pages');
  });

  it('round-trips with withBase', () => {
    const url = withBaseOf('/2026/cms/admin', '/admin/pages/edit/home');

    expect(routeFrom(url)).toBe('/admin/pages/edit/home');
  });
});
