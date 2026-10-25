const PREFIX = 'click:page-draft:v1:';
const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

/** Stable, exact account/site/document/language scope. Values are encoded so
 * delimiters in a username or slug cannot make two scopes collide. */
export function draftRecoveryKey(scope) {
  const exact = ['account', 'site', 'document', 'locale'].map((part) => {
    const value = scope?.[part];
    if (typeof value !== 'string' || !value) throw new Error(`Missing draft scope: ${part}`);
    return encodeURIComponent(value);
  });
  return `${PREFIX}${exact.join(':')}`;
}

export function readDraftRecovery(storage, scope, now = Date.now()) {
  try {
    if (!storage) return null;
    const key = draftRecoveryKey(scope);
    const raw = storage.getItem(key);
    if (!raw) return null;
    const record = JSON.parse(raw);
    const page = record?.page;
    if (!record || record.version !== 1 || !Number.isFinite(record.savedAt)
        || record.savedAt > now || now - record.savedAt > MAX_AGE_MS || !page || typeof page !== 'object'
        || typeof page.title !== 'string' || !Array.isArray(page.sections)
        || !page.seo || typeof page.seo !== 'object' || Array.isArray(page.seo)) {
      storage.removeItem(key);
      return null;
    }
    return page;
  } catch {
    return null;
  }
}

export function writeDraftRecovery(storage, scope, page, now = Date.now()) {
  storage.setItem(draftRecoveryKey(scope), JSON.stringify({ version: 1, savedAt: now, page }));
}

export function clearDraftRecovery(storage, scope) {
  try { storage?.removeItem(draftRecoveryKey(scope)); } catch { /* best effort cleanup */ }
}

/** Remove this account's opt-in page recovery data when its session ends. */
export function clearAccountDraftRecovery(storage, account) {
  if (!storage || typeof account !== 'string' || !account) return;
  const accountPrefix = `${PREFIX}${encodeURIComponent(account)}:`;
  try {
    for (let index = storage.length - 1; index >= 0; index -= 1) {
      const key = storage.key(index);
      if (typeof key === 'string' && key.startsWith(accountPrefix)) storage.removeItem(key);
    }
  } catch { /* browser storage may be disabled; best effort cleanup */ }
}
