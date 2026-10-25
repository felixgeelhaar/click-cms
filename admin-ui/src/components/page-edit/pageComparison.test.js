import { describe, it, expect } from 'vitest';
import { comparePageDrafts } from './pageComparison.js';

describe('page draft comparison', () => {
  it('reports changed fields with inert, readable values', () => {
    expect(comparePageDrafts(
      { title: 'Old', sections: [{ type: 'text', values: { body: 'A' } }] },
      { title: 'New', sections: [{ type: 'text', values: { body: 'B' } }] },
    )).toEqual([
      { path: 'title', before: 'Old', after: 'New' },
      { path: 'sections[0].values.body', before: 'A', after: 'B' },
    ]);
  });

  it('marks added and removed values and bounds long text', () => {
    const result = comparePageDrafts({ sections: [] }, { sections: [{ values: { body: 'x'.repeat(300) } }] });
    expect(result[0].before).toBe('—');
    expect(result[0].after.length).toBeLessThan(130);
    expect(result[0].after).toContain('…');
  });
});
