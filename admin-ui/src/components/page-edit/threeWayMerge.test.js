import { describe, expect, it } from 'vitest';
import { mergeThreeWay, resolveThreeWayMerge } from './threeWayMerge.js';

describe('three-way draft merge', () => {
  it('combines non-overlapping field changes', () => {
    const base = { title: 'Home', seo: { description: 'Old' } };
    const local = { title: 'Local title', seo: { description: 'Old' } };
    const server = { title: 'Home', seo: { description: 'Server description' } };
    const result = mergeThreeWay(base, local, server);
    expect(result.conflicts).toEqual([]);
    expect(result.merged).toEqual({ title: 'Local title', seo: { description: 'Server description' } });
  });

  it('requires an explicit local or server choice for overlapping edits', () => {
    const base = { title: 'Home' };
    const local = { title: 'Local title' };
    const server = { title: 'Server title' };
    const result = mergeThreeWay(base, local, server);
    expect(result.conflicts).toEqual([{ path: 'title', base: 'Home', local: 'Local title', server: 'Server title' }]);
    expect(result.resolved).toBe(false);
    expect(resolveThreeWayMerge(base, local, server, { title: 'server' })).toEqual({ title: 'Server title' });
    expect(resolveThreeWayMerge(base, local, server, { title: 'local' })).toEqual({ title: 'Local title' });
  });

  it('merges section fields only when section structure is stable and unambiguous', () => {
    const base = { sections: [{ id: 'hero-1', type: 'hero', values: { title: 'Hello', body: 'Old' } }, { id: 'footer-1', type: 'footer', values: { note: 'Old' } }] };
    const local = { sections: [{ id: 'hero-1', type: 'hero', values: { title: 'Local title', body: 'Old' } }, { id: 'footer-1', type: 'footer', values: { note: 'Old' } }] };
    const server = { sections: [{ id: 'hero-1', type: 'hero', values: { title: 'Hello', body: 'Server body' } }, { id: 'footer-1', type: 'footer', values: { note: 'Old' } }] };
    const result = mergeThreeWay(base, local, server);
    expect(result.conflicts).toEqual([]);
    expect(result.merged.sections[0].values).toEqual({ title: 'Local title', body: 'Server body' });
  });

  it('treats changed or ambiguous section structure as one conflict', () => {
    const base = { sections: [{ id: 'text-1', type: 'text', values: { body: 'Old' } }] };
    const local = { sections: [{ id: 'text-1', type: 'text', values: { body: 'Local' } }, { id: 'text-2', type: 'text', values: { body: 'Added' } }] };
    const server = { sections: [{ id: 'text-1', type: 'text', values: { body: 'Server' } }] };
    const result = mergeThreeWay(base, local, server);
    expect(result.conflicts.map(({ path }) => path)).toEqual(['sections']);
    expect(resolveThreeWayMerge(base, local, server, { sections: 'server' })).toEqual(server);
  });

  it('does not merge primitive or identity-free arrays by index', () => {
    const result = mergeThreeWay({ tags: ['a', 'b'] }, { tags: ['local', 'b'] }, { tags: ['a', 'server'] });
    expect(result.conflicts.map(({ path }) => path)).toEqual(['tags']);
  });

  it('does not merge arrays whose stable item identities were reordered', () => {
    const base = { sections: [{ id: 'one', type: 'text', values: { body: 'A' } }, { id: 'two', type: 'text', values: { body: 'B' } }] };
    const local = { sections: [{ id: 'two', type: 'text', values: { body: 'B' } }, { id: 'one', type: 'text', values: { body: 'Local A' } }] };
    const server = { sections: [{ id: 'one', type: 'text', values: { body: 'Server A' } }, { id: 'two', type: 'text', values: { body: 'B' } }] };
    expect(mergeThreeWay(base, local, server).conflicts.map(({ path }) => path)).toEqual(['sections']);
  });

  it('keeps __proto__ as inert own data instead of changing the result prototype', () => {
    const local = JSON.parse('{"__proto__":{"polluted":true}}');
    const merged = mergeThreeWay({}, local, {}).merged;
    expect(Object.getPrototypeOf(merged)).toBe(Object.prototype);
    expect(Object.hasOwn(merged, '__proto__')).toBe(true);
    expect(merged.__proto__).toEqual({ polluted: true });
  });
});
