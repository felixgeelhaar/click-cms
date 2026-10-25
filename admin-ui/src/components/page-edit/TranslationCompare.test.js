import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TranslationCompare from './TranslationCompare.vue';

const response = (data, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  json: async () => data,
});

describe('read-only translation comparison', () => {
  it('shows source and the current unsaved target side by side without writing either', async () => {
    global.fetch = vi.fn(async (url) => {
      const locale = new URL(String(url), 'http://localhost').searchParams.get('locale');
      return response({
        locale,
        data: { data: { title: 'Saved English', sections: [{ type: 'rich-text', values: { body: 'Source text' } }] } },
      });
    });
    const current = { title: 'Unsaved German', sections: [{ type: 'rich-text', values: { body: 'Zieltext' } }] };
    const wrapper = mount(TranslationCompare, {
      props: {
        slug: 'home', locales: ['en', 'de', 'fr'], currentLocale: 'de',
        workingCopy: current,
        translations: {
          en: { exists: true, publication: { published: true, hasUnpublishedChanges: false } },
          de: { exists: true, publication: { published: true, hasUnpublishedChanges: true } },
          fr: { exists: false, publication: null },
        },
      },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Saved English');
    expect(wrapper.text()).toContain('Unsaved German');
    expect(wrapper.text()).toContain('Zieltext');
    expect(wrapper.text()).toContain('Live and up to date');
    expect(wrapper.text()).toContain('Live, edits pending');
    expect(global.fetch.mock.calls.every(([, init]) => !['POST', 'PUT', 'DELETE'].includes(init?.method))).toBe(true);
    expect(current.title).toBe('Unsaved German');
  });

  it('loads a newly selected target safely and reports a failed source read', async () => {
    global.fetch = vi.fn(async (url) => {
      const locale = new URL(String(url), 'http://localhost').searchParams.get('locale');
      if (locale === 'en') return response({ error: 'Source unavailable' }, 503);
      if (locale === 'fr') return response({ locale: 'fr', data: { data: { title: 'French title', sections: [] } } });
      return response({ locale: 'de', data: { data: { title: 'German title', sections: [] } } });
    });
    const wrapper = mount(TranslationCompare, {
      props: { slug: 'home', locales: ['en', 'de', 'fr'], currentLocale: 'de', workingCopy: { title: 'German title', sections: [] } },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('Source unavailable');

    await wrapper.get('select[aria-label="Target language"]').setValue('fr');
    await flushPromises();
    expect(wrapper.text()).toContain('French title');
    expect(wrapper.text()).toContain('Source unavailable');
    expect(global.fetch.mock.calls.every(([, init]) => !['POST', 'PUT', 'DELETE'].includes(init?.method))).toBe(true);
  });

  it('does not compare a language with itself', async () => {
    global.fetch = vi.fn(async () => response({ locale: 'en', data: { data: { title: 'English', sections: [] } } }));
    const wrapper = mount(TranslationCompare, {
      props: { slug: 'home', locales: ['en', 'de'], currentLocale: 'de', workingCopy: { title: 'German', sections: [] } },
    });
    await flushPromises();
    await wrapper.get('select[aria-label="Source language"]').setValue('de');
    await flushPromises();

    expect(wrapper.text()).toContain('Choose two different languages');
  });

  it('ignores stale locale responses after the selected source changes', async () => {
    let resolveEnglish;
    global.fetch = vi.fn((url) => {
      const locale = new URL(String(url), 'http://localhost').searchParams.get('locale');
      if (locale === 'en') return new Promise((resolve) => { resolveEnglish = resolve; });
      return Promise.resolve(response({ data: { data: { title: `${locale} title`, sections: [] } } }));
    });
    const wrapper = mount(TranslationCompare, {
      props: { slug: 'home', locales: ['en', 'de', 'fr'], currentLocale: 'de', workingCopy: { title: 'German', sections: [] } },
    });
    await flushPromises();
    await wrapper.get('select[aria-label="Source language"]').setValue('fr');
    await flushPromises();
    expect(wrapper.text()).toContain('fr title');

    resolveEnglish(response({ data: { data: { title: 'Stale English title', sections: [] } } }));
    await flushPromises();
    expect(wrapper.text()).toContain('fr title');
    expect(wrapper.text()).not.toContain('Stale English title');
  });

  it('keeps unmarked freshness unknown and marks the saved source and target revisions explicitly', async () => {
    global.fetch = vi.fn(async (url, init = {}) => {
      const parsed = new URL(String(url), 'http://localhost');
      if (parsed.pathname === '/api/translation-freshness') {
        if (init.method === 'POST') return response({ data: { freshness: 'reviewed', sourceLocale: 'en', targetLocale: 'de', currentSourceRevision: 'source-r2', currentTargetRevision: 'target-r4', sourceRevision: 'source-r2', targetRevision: 'target-r4', reviewedBy: 'alice', reviewedAt: '2026-10-25T12:00:00Z', staleReasons: [] } });
        return response({ data: { freshness: 'unknown', sourceLocale: 'en', targetLocale: 'de', currentSourceRevision: 'source-r2', currentTargetRevision: 'target-r4', sourceRevision: null, targetRevision: null, reviewedAt: null, reviewedBy: null, staleReasons: [] } });
      }
      const locale = parsed.searchParams.get('locale');
      return response({ locale, data: { data: { title: `${locale} saved`, sections: [], owner: 'alice' }, revision: locale === 'en' ? 'source-r2' : 'target-r4' } });
    });
    const wrapper = mount(TranslationCompare, {
      props: {
        slug: 'home', locales: ['en', 'de'], currentLocale: 'de', currentRevision: 'target-r4',
        workingCopy: { title: 'Target draft', sections: [] }, canMark: true, currentUsername: 'alice', targetDirty: false,
      },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Not yet reviewed');
    expect(wrapper.text()).not.toContain('Translation is up to date');
    const mark = wrapper.get('[data-testid="mark-translation-reviewed"]');
    expect(mark.attributes('disabled')).toBeUndefined();
    await mark.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Reviewed against current source');
    const post = global.fetch.mock.calls.find(([url, init]) => String(url).includes('/api/translation-freshness') && init?.method === 'POST');
    expect(JSON.parse(post[1].body)).toMatchObject({
      slug: 'home', sourceLocale: 'en', targetLocale: 'de', sourceRevision: 'source-r2', targetRevision: 'target-r4',
    });
  });

  it('shows stale source snapshots and disables marking when the current target is unsaved', async () => {
    global.fetch = vi.fn(async (url) => {
      const parsed = new URL(String(url), 'http://localhost');
      if (parsed.pathname === '/api/translation-freshness') return response({ data: { freshness: 'stale', sourceLocale: 'en', targetLocale: 'de', currentSourceRevision: 'source-new', currentTargetRevision: 'target-r4', sourceRevision: 'source-old', targetRevision: 'target-r4', reviewedAt: '2026-10-24T12:00:00Z', reviewedBy: 'alice', staleReasons: ['source_changed'] } });
      const locale = parsed.searchParams.get('locale');
      return response({ locale, data: { data: { title: `${locale} saved`, sections: [], owner: 'alice' } } });
    });
    const wrapper = mount(TranslationCompare, {
      props: { slug: 'home', locales: ['en', 'de'], currentLocale: 'de', workingCopy: { title: 'Unsaved', sections: [] }, canMark: true, currentUsername: 'alice', targetDirty: true },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Source changed since this review');
    expect(wrapper.get('[data-testid="mark-translation-reviewed"]').attributes('disabled')).toBeDefined();
  });

  it('does not mark a newer source revision than the snapshot shown in the comparison', async () => {
    global.fetch = vi.fn(async (url) => {
      const parsed = new URL(String(url), 'http://localhost');
      if (parsed.pathname === '/api/translation-freshness') return response({ data: { freshness: 'unknown', currentSourceRevision: 'source-new', currentTargetRevision: 'target-r4', reviewedAt: null, staleReasons: [] } });
      const locale = parsed.searchParams.get('locale');
      const revision = locale === 'en' ? 'source-old-displayed' : 'target-r4';
      return response({ locale, data: { revision, data: { title: `${locale} saved`, sections: [], owner: 'alice' } } });
    });
    const wrapper = mount(TranslationCompare, {
      props: { slug: 'home', locales: ['en', 'de'], currentLocale: 'de', workingCopy: { title: 'Target', sections: [] }, canMark: true, currentUsername: 'alice', targetDirty: false },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Saved translations changed while the comparison loaded');
    expect(wrapper.get('[data-testid="mark-translation-reviewed"]').attributes('disabled')).toBeDefined();
    expect(global.fetch.mock.calls.some(([url, init]) => String(url).includes('/api/translation-freshness') && init?.method === 'POST')).toBe(false);
  });

  it('uses the editor loaded revision when current working copy is older than the saved target', async () => {
    global.fetch = vi.fn(async (url) => {
      const parsed = new URL(String(url), 'http://localhost');
      if (parsed.pathname === '/api/translation-freshness') {
        return response({ data: { freshness: 'unknown', currentSourceRevision: 'en-r1', currentTargetRevision: 'de-r2', reviewedAt: null, staleReasons: [] } });
      }
      const locale = parsed.searchParams.get('locale');
      const revision = locale === 'en' ? 'en-r1' : 'de-r2';
      return response({ locale, data: { revision, data: { title: `${locale} saved`, sections: [], owner: 'alice' } } });
    });
    const wrapper = mount(TranslationCompare, {
      props: {
        slug: 'home', locales: ['en', 'de'], currentLocale: 'de', currentRevision: 'de-r1',
        workingCopy: { title: 'Target loaded before another editor saved', sections: [] },
        canMark: true, currentUsername: 'alice', targetDirty: false,
      },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Saved translations changed while the comparison loaded');
    expect(wrapper.get('[data-testid="mark-translation-reviewed"]').attributes('disabled')).toBeDefined();
    expect(global.fetch.mock.calls.some(([url, init]) => String(url).includes('/api/translation-freshness') && init?.method === 'POST')).toBe(false);
  });

  it('ignores a failed mark response and finalizer after the comparison pair changes', async () => {
    let rejectMark;
    global.fetch = vi.fn((url, init = {}) => {
      const parsed = new URL(String(url), 'http://localhost');
      if (parsed.pathname === '/api/translation-freshness' && init.method === 'POST') {
        return new Promise((_, reject) => { rejectMark = reject; });
      }
      if (parsed.pathname === '/api/translation-freshness') {
        const source = parsed.searchParams.get('sourceLocale');
        return Promise.resolve(response({ data: { freshness: 'unknown', currentSourceRevision: `${source}-r1`, currentTargetRevision: 'de-r1', reviewedAt: null, staleReasons: [] } }));
      }
      const locale = parsed.searchParams.get('locale');
      return Promise.resolve(response({ locale, data: { revision: `${locale}-r1`, data: { title: locale, sections: [], owner: 'alice' } } }));
    });
    const wrapper = mount(TranslationCompare, {
      props: { slug: 'home', locales: ['en', 'de', 'fr'], currentLocale: 'de', currentRevision: 'de-r1', workingCopy: { title: 'de', sections: [] }, canMark: true, currentUsername: 'alice' },
    });
    await flushPromises();
    await wrapper.get('[data-testid="mark-translation-reviewed"]').trigger('click');
    await wrapper.get('select[aria-label="Source language"]').setValue('fr');
    await flushPromises();

    rejectMark(new Error('old request failed'));
    await flushPromises();

    expect(wrapper.text()).not.toContain('old request failed');
    expect(wrapper.get('[data-testid="mark-translation-reviewed"]').attributes('disabled')).toBeUndefined();
  });

  it('ignores a review response after the selected locale pair changes', async () => {
    let resolveMark;
    global.fetch = vi.fn((url, init = {}) => {
      const parsed = new URL(String(url), 'http://localhost');
      if (parsed.pathname === '/api/translation-freshness' && init.method === 'POST') {
        return new Promise((resolve) => { resolveMark = resolve; });
      }
      if (parsed.pathname === '/api/translation-freshness') {
        const source = parsed.searchParams.get('sourceLocale');
        return Promise.resolve(response({ data: { freshness: 'unknown', currentSourceRevision: `${source}-r1`, currentTargetRevision: 'de-r1', reviewedAt: null, staleReasons: [] } }));
      }
      const locale = parsed.searchParams.get('locale');
      return Promise.resolve(response({ locale, data: { revision: `${locale}-r1`, data: { title: locale, sections: [], owner: 'alice' } } }));
    });
    const wrapper = mount(TranslationCompare, {
      props: { slug: 'home', locales: ['en', 'de', 'fr'], currentLocale: 'de', currentRevision: 'de-r1', workingCopy: { title: 'de', sections: [] }, canMark: true, currentUsername: 'alice' },
    });
    await flushPromises();
    await wrapper.get('[data-testid="mark-translation-reviewed"]').trigger('click');
    await wrapper.get('select[aria-label="Source language"]').setValue('fr');
    await flushPromises();

    resolveMark(response({ data: { freshness: 'reviewed', sourceLocale: 'en', targetLocale: 'de', currentSourceRevision: 'en-r1', currentTargetRevision: 'de-r1', reviewedAt: '2026-10-25T12:00:00Z', reviewedBy: 'alice' } }));
    await flushPromises();

    expect(wrapper.text()).toContain('Not yet reviewed against this source');
    expect(wrapper.text()).not.toContain('Reviewed against current source by');
  });
});
