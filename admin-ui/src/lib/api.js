/**
 * Wraps fetch so every request reaches this installation, and every
 * state-changing one carries the session's CSRF token.
 *
 * Installed once, at start-up, rather than threaded through each call site:
 * a component that forgets the token would fail confusingly, and a new one
 * would have to remember. The one place that can forget is here.
 *
 * The URL prefix arrived later, for the same reason. A component asks for
 * `/api/pages`, but the CMS may be installed at `/2026/cms/`, where the request
 * has to go to `/2026/cms/api/pages` — and there are some seventy such calls.
 * Prefixing them here lets a component go on writing the address it means, and
 * means a new one cannot forget that either.
 */

import { basePath } from './base.js';

let csrfToken = null;

export function setCsrfToken(token) {
  csrfToken = token || null;
}

export function getCsrfToken() {
  return csrfToken;
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

/**
 * Point a root-absolute request at this installation.
 *
 * Only a string beginning with a single `/` is touched: that is a path within
 * this CMS. An absolute URL, a protocol-relative one, and a Request object all
 * name their own destination and are passed through untouched — as is anything
 * already carrying the prefix, so an already-prefixed URL is never doubled.
 */
function withInstallationPrefix(input) {
  const prefix = basePath();

  if (prefix === '' || typeof input !== 'string') return input;
  if (!input.startsWith('/') || input.startsWith('//')) return input;
  if (input === prefix || input.startsWith(prefix + '/')) return input;

  return prefix + input;
}

export function installCsrfFetch() {
  if (typeof window === 'undefined' || window.__clickCsrfInstalled) return;
  window.__clickCsrfInstalled = true;

  const original = window.fetch.bind(window);

  window.fetch = (input, init = {}) => {
    const method = (init.method || 'GET').toUpperCase();

    if (csrfToken && !SAFE_METHODS.has(method)) {
      const headers = new Headers(init.headers || {});
      headers.set('X-Click-CSRF', csrfToken);
      init = { ...init, headers };
    }

    return original(withInstallationPrefix(input), init);
  };
}

/** Read the current token from the server and remember it. */
export async function refreshCsrfToken() {
  try {
    const res = await fetch('/api/auth/check');
    const body = await res.json();
    setCsrfToken(body.data?.csrfToken ?? null);
    return body.data ?? null;
  } catch {
    setCsrfToken(null);
    return null;
  }
}
