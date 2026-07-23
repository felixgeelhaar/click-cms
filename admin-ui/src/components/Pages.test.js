import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Pages from './Pages.vue';

/**
 * Regression tests for defects that reached the merged branch.
 *
 * All three were caused by the server changing underneath this component —
 * publication stopped being a field, and the content key gained a language
 * segment — and none were caught, because there was no frontend test suite at
 * all. They are written here as the cases that would have failed.
 */

const page = (slug, publication, locale = 'en') => ({
  key: `page:${locale}:${slug}`,
  slug,
  locale,
  data: { title: slug },
  ...(publication === null ? {} : { publication }),
});

const LIVE = { published: true, hasUnpublishedChanges: false, neverPublished: false, publishedAt: '2026-07-01T10:00:00+00:00' };
const PENDING = { published: true, hasUnpublishedChanges: true, neverPublished: false, publishedAt: '2026-07-01T10:00:00+00:00' };
const NEVER = { published: false, hasUnpublishedChanges: false, neverPublished: true, publishedAt: null };
const TAKEN_DOWN = { published: false, hasUnpublishedChanges: false, neverPublished: false, publishedAt: null };

/** The badge text for each rendered page, which is the thing under test. */
const badges = (wrapper) => wrapper.findAll('.status-badge').map((b) => b.text());

/** Answer /api/pages with the given rows, and anything else with an empty list. */
const respondWith = (rows, locales = ['en']) => {
  global.fetch = vi.fn(async (url) => ({
    ok: true,
    status: 200,
    json: async () =>
      String(url).includes('/api/pages')
        ? { data: rows, locale: 'en', locales }
        : { data: [] },
  }));
};

const mountPages = async (rows, locales) => {
  respondWith(rows, locales);
  const wrapper = mount(Pages);
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  vi.restoreAllMocks();
  window.confirm = vi.fn(() => true);
});

describe('publication state', () => {
  /**
   * The regression itself. The component read `data.status`, which was removed
   * when publishing became an action rather than a field, and fell back to
   * 'draft' — so a site that was entirely live rendered as entirely draft.
   */
  it('describes a live page as live, not as a draft', async () => {
    const wrapper = await mountPages([page('home', LIVE)]);

    // Asserted on the badge, not on the page text: filter tabs also say "Live",
    // so a whole-document match passes even when the badge is wrong — which it
    // did, the first time this test was written.
    expect(badges(wrapper)).toEqual(['Live']);
  });

  it('distinguishes a page with unpublished edits from a clean one', async () => {
    const wrapper = await mountPages([page('home', LIVE), page('about', PENDING)]);

    expect(badges(wrapper)).toEqual(['Live', 'Live, edits pending']);
  });

  it('distinguishes never-published from taken-down', async () => {
    const never = await mountPages([page('draft-page', NEVER)]);
    expect(badges(never)).toEqual(['Never published']);

    const down = await mountPages([page('retired', TAKEN_DOWN)]);
    expect(badges(down)).toEqual(['Taken down']);
  });

  /**
   * An anonymous response carries no `publication` key. Asserting a state from
   * its absence is what produced the original bug, so absence must read as
   * unknown rather than as a guess.
   */
  it('says the state is unavailable rather than guessing at it', async () => {
    const wrapper = await mountPages([page('home', null)]);

    expect(badges(wrapper)).toEqual(['Status unavailable']);
  });
});

describe('addressing a page', () => {
  /**
   * Keys became `page:en:home` when content gained a language. The component
   * stripped only the `page:` prefix, so every link and every displayed address
   * carried the language: `/en/home`.
   */
  it('shows the slug without the language segment', async () => {
    const wrapper = await mountPages([page('home', LIVE)]);

    expect(wrapper.text()).toContain('/home');
    expect(wrapper.text()).not.toContain('/en:home');
    expect(wrapper.text()).not.toContain('en:home');
  });

  it('links to the editor with a real href', async () => {
    const wrapper = await mountPages([page('home', LIVE)]);

    const edit = wrapper.findAll('a').map((a) => a.attributes('href'));
    expect(edit.some((href) => href?.includes('/admin/pages/edit/home'))).toBe(true);
  });
});

describe('deleting', () => {
  /**
   * Delete sent no language, so removing a page while viewing German deleted
   * the English document — usually the published one.
   */
  it('deletes the language being viewed, not the default one', async () => {
    const wrapper = await mountPages([page('kontakt', LIVE, 'de')], ['en', 'de']);

    global.fetch.mockClear();
    await wrapper.vm.deletePage?.('kontakt');
    await flushPromises();

    const deleteCall = global.fetch.mock.calls.find(([, init]) => init?.method === 'DELETE');
    expect(deleteCall, 'a DELETE request should have been sent').toBeTruthy();
    expect(String(deleteCall[0])).toContain('locale=');
  });
});
