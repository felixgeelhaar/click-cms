/**
 * Where this admin is mounted, worked out from its own address.
 *
 * The CMS can be installed anywhere — at a domain root, or in a subdirectory
 * like `/2026/cms/`, which is the ordinary case on shared hosting. The admin is
 * one build serving all of them, so it cannot be told its prefix when it is
 * built: it reads it off the URL it was loaded from.
 *
 * The rule that keeps this small: **internal route paths never carry the
 * prefix**. Every route comparison in the app goes on matching `/admin/pages`,
 * and the conversion happens only where the app meets the browser — the `href`
 * on a link, a `pushState`, and reading `location.pathname` back.
 */

/** `/2026/cms` from `/2026/cms/admin/pages`; empty at the domain root. */
export function basePathFrom(pathname) {
  // Non-greedy, so the *first* `/admin` segment is the mount point — and a
  // trailing `/` or end-of-path is required, so an installation in a directory
  // whose name merely begins with "admin" is not mistaken for it.
  const match = /^(.*?)\/admin(?:\/|$)/.exec(pathname || '');

  return match ? match[1] : '';
}

/** The prefix for the page currently loaded. */
export function basePath() {
  return typeof window === 'undefined' ? '' : basePathFrom(window.location.pathname);
}

/** Add a prefix to a route path. Exported with an explicit path for testing. */
export function withBaseOf(pathname, path) {
  return basePathFrom(pathname) + path;
}

/** Turn an internal route path into a URL the browser can be sent to. */
export function withBase(path) {
  return basePath() + path;
}

/** Take the prefix off a browser path, leaving the route the app matches on. */
export function routeFrom(pathname) {
  const base = basePathFrom(pathname);

  return base === '' ? pathname : pathname.slice(base.length);
}

/** The route for the page currently loaded, query string included. */
export function currentRoute() {
  if (typeof window === 'undefined') return '/admin';

  return routeFrom(window.location.pathname) + window.location.search;
}
