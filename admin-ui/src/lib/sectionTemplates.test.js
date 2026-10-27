import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  listSectionTemplates,
  saveSectionTemplate,
  deleteSectionTemplate,
  cloneTemplateSections,
} from './sectionTemplates.js';

const memoryStorage = () => {
  const data = new Map();
  return {
    getItem: (key) => data.get(key) ?? null,
    setItem: (key, value) => data.set(key, value),
    removeItem: (key) => data.delete(key),
  };
};

beforeEach(() => { vi.stubGlobal('localStorage', memoryStorage()); });

afterEach(() => vi.unstubAllGlobals());

const accountOne = { account: 'alice', site: 'https://cms.test/primary' };

describe('section starter templates', () => {
  it('stores and lists page section snapshots with a stable id', () => {
    const saved = saveSectionTemplate('Landing', [{ type: 'hero', values: { heading: 'Hello' } }], accountOne);
    expect(saved.name).toBe('Landing');
    expect(saved.sections).toEqual([{ type: 'hero', values: { heading: 'Hello' } }]);
    expect(listSectionTemplates(accountOne)).toEqual([saved]);
    expect(listSectionTemplates({ account: 'bob', site: accountOne.site })).toEqual([]);
  });

  it('does not retain mutable references to the editor state', () => {
    const source = [{ type: 'hero', values: { heading: 'Hello' } }];
    saveSectionTemplate('Landing', source, accountOne);
    source[0].values.heading = 'Changed';
    expect(listSectionTemplates(accountOne)[0].sections[0].values.heading).toBe('Hello');
    const copy = cloneTemplateSections(listSectionTemplates(accountOne)[0]);
    copy[0].values.heading = 'Also changed';
    expect(listSectionTemplates(accountOne)[0].sections[0].values.heading).toBe('Hello');
  });

  it('replaces a template with the same normalized name and can delete it', () => {
    saveSectionTemplate('Landing', [{ type: 'hero', values: {} }], accountOne);
    const replaced = saveSectionTemplate(' landing ', [{ type: 'quote', values: {} }], accountOne);
    expect(listSectionTemplates(accountOne)).toHaveLength(1);
    expect(listSectionTemplates(accountOne)[0]).toEqual(replaced);
    expect(deleteSectionTemplate(replaced.id, accountOne)).toBe(true);
    expect(listSectionTemplates(accountOne)).toEqual([]);
  });

  it('rejects empty names and malformed sections', () => {
    expect(() => saveSectionTemplate('  ', [], accountOne)).toThrow(/name/i);
    expect(() => saveSectionTemplate('Bad', [{ type: '', values: {} }], accountOne)).toThrow(/section/i);
    expect(() => saveSectionTemplate('Unscoped', [], null)).toThrow(/account and site/i);
  });

  it('removes an account and site personal starter store on logout', async () => {
    saveSectionTemplate('Landing', [{ type: 'hero', values: {} }], accountOne);
    const { clearSectionTemplates } = await import('./sectionTemplates.js');
    expect(clearSectionTemplates(accountOne)).toBe(true);
    expect(listSectionTemplates(accountOne)).toEqual([]);
    expect(clearSectionTemplates(accountOne)).toBe(false);
  });
});
