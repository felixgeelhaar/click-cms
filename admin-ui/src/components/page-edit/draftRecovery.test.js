import { describe, it, expect } from 'vitest';
import { draftRecoveryKey, readDraftRecovery, writeDraftRecovery, clearDraftRecovery, clearAccountDraftRecovery } from './draftRecovery.js';

const scope = { account: 'ada', site: 'primary', document: 'page:home', locale: 'de' };

function storage() {
  const values = new Map();
  return {
    get length() { return values.size; },
    key: (index) => [...values.keys()][index] ?? null,
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  };
}

describe('opt-in draft recovery storage', () => {
  it('scopes drafts to account, site, document and locale', () => {
    expect(draftRecoveryKey(scope)).not.toBe(draftRecoveryKey({ ...scope, account: 'grace' }));
    expect(draftRecoveryKey(scope)).not.toBe(draftRecoveryKey({ ...scope, site: 'secondary' }));
    expect(draftRecoveryKey(scope)).not.toBe(draftRecoveryKey({ ...scope, locale: 'en' }));
  });

  it('stores and reads only a draft under its exact scope', () => {
    const store = storage();
    const page = { title: 'Changed', sections: [], seo: {} };
    writeDraftRecovery(store, scope, page);
    expect(readDraftRecovery(store, scope)).toEqual(page);
    expect(readDraftRecovery(store, { ...scope, locale: 'en' })).toBeNull();
  });

  it('removes the scoped draft after save, discard or opt-out', () => {
    const store = storage();
    writeDraftRecovery(store, scope, { title: 'Changed' });
    clearDraftRecovery(store, scope);
    expect(readDraftRecovery(store, scope)).toBeNull();
  });

  it('ignores malformed or stale draft entries', () => {
    const store = storage();
    store.setItem(draftRecoveryKey(scope), '{bad json');
    expect(readDraftRecovery(store, scope)).toBeNull();
  });

  it('expires valid records after seven days and removes them', () => {
    const store = storage();
    const now = 10_000_000;
    writeDraftRecovery(store, scope, { title: 'Old', sections: [], seo: {} }, now - 8 * 24 * 60 * 60 * 1000);
    expect(readDraftRecovery(store, scope, now)).toBeNull();
    expect(store.getItem(draftRecoveryKey(scope))).toBeNull();
  });

  it('rejects future timestamps and clears only the signed-out account', () => {
    const store = storage();
    writeDraftRecovery(store, scope, { title: 'Future', sections: [], seo: {} }, 20_000);
    expect(readDraftRecovery(store, scope, 10_000)).toBeNull();
    writeDraftRecovery(store, scope, { title: 'Ada', sections: [], seo: {} }, 10_000);
    const graceScope = { ...scope, account: 'grace' };
    writeDraftRecovery(store, graceScope, { title: 'Grace', sections: [], seo: {} }, 10_000);
    clearAccountDraftRecovery(store, 'ada');
    expect(readDraftRecovery(store, scope, 10_001)).toBeNull();
    expect(readDraftRecovery(store, graceScope, 10_001)?.title).toBe('Grace');
  });
});
