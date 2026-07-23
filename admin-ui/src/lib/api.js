/**
 * Wraps fetch so every state-changing request carries the session's CSRF token.
 *
 * Installed once, at start-up, rather than threaded through each call site:
 * a component that forgets the token would fail confusingly, and a new one
 * would have to remember. The one place that can forget is here.
 */

let csrfToken = null;

export function setCsrfToken(token) {
  csrfToken = token || null;
}

export function getCsrfToken() {
  return csrfToken;
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

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

    return original(input, init);
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
