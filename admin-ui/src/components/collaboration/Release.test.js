import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Release from './Release.vue';

/**
 * The release screen. Worth pinning:
 *
 *  - it loads pages and open reviews together;
 *  - selecting pages and publishing posts the shorthand release body;
 *  - a 409 lists each refused page as an editorial blocker.
 */

const page = (slug, title) => ({
  slug,
  key: `page:en:${slug}`,
  data: { title },
});

const review = (overrides = {}) => ({
  page: 'about',
  locale: 'en',
  state: 'in_review',
  open: true,
  ...overrides,
});

const server = ({
  pages = [page('home', 'Home'), page('about', 'About')],
  locale = 'en',
  open = [review()],
  release = null,
} = {}) => {
  global.fetch = vi.fn(async (url, opts = {}) => {
    const path = String(url);
    const method = (opts.method || 'GET').toUpperCase();

    if (path === '/api/pages' && method === 'GET') {
      return {
        ok: true,
        status: 200,
        json: async () => ({ data: pages, locale, locales: [locale] }),
      };
    }

    if (path === '/api/collaboration/review' && method === 'GET') {
      return {
        ok: true,
        status: 200,
        json: async () => ({ data: { open } }),
      };
    }

    if (path === '/api/collaboration/release' && method === 'POST') {
      const body = typeof release === 'function'
        ? release(JSON.parse(opts.body || '{}'))
        : (release ?? { status: 200, ok: true, data: { published: [], failed: [] } });
      return {
        ok: body.ok ?? (body.status ?? 200) < 400,
        status: body.status ?? 200,
        json: async () => body,
      };
    }

    return { ok: false, status: 404, json: async () => ({ error: 'missing' }) };
  });
};

const mountRelease = async (opts) => {
  server(opts);
  const wrapper = mount(Release);
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('Release', () => {
  it('loads pages and marks open-review pages as not ready', async () => {
    const wrapper = await mountRelease();

    expect(global.fetch).toHaveBeenCalledWith('/api/pages');
    expect(global.fetch).toHaveBeenCalledWith('/api/collaboration/review');

    const rows = wrapper.findAll('tbody tr');
    expect(rows).toHaveLength(2);
    expect(rows[0].text()).toContain('home');
    expect(rows[0].text()).toContain('Home');
    expect(rows[0].text()).toContain('Ready');
    expect(rows[1].text()).toContain('about');
    expect(rows[1].text()).toContain('Waiting for review');

    expect(wrapper.find('#release-locale').element.value).toBe('en');
  });

  it('selects pages and posts a release with the chosen locale', async () => {
    let posted = null;
    const wrapper = await mountRelease({
      open: [],
      release: (body) => {
        posted = body;
        return {
          status: 200,
          ok: true,
          data: {
            published: body.pages.map((slug) => ({ page: slug, locale: body.locale })),
            failed: [],
          },
        };
      },
    });

    const boxes = wrapper.findAll('tbody input[type="checkbox"]');
    await boxes[0].setValue(true);
    await boxes[1].setValue(true);
    await wrapper.find('#release-locale').setValue('de');
    await wrapper.find('.btn-primary').trigger('click');
    await flushPromises();

    const releaseCall = global.fetch.mock.calls.find(
      ([url, opts]) => String(url) === '/api/collaboration/release' && opts?.method === 'POST',
    );
    expect(releaseCall).toBeTruthy();
    expect(posted).toEqual({ pages: ['home', 'about'], locale: 'de' });
    expect(wrapper.text()).toContain('Published');
    expect(wrapper.text()).toContain('home');
    expect(wrapper.text()).toContain('about');
  });

  it('shows 409 refusals as editorial blockers', async () => {
    const wrapper = await mountRelease({
      open: [],
      release: {
        status: 409,
        ok: false,
        error: 'This release was not published because some of its pages are not ready.',
        data: {
          refused: [
            { page: 'home', locale: 'en', reason: 'This page is waiting for review and has not been approved yet.' },
            { page: 'about', locale: 'en', reason: 'A reviewer asked for changes on this page.' },
          ],
          published: [],
        },
      },
    });

    await wrapper.findAll('tbody input[type="checkbox"]')[0].setValue(true);
    await wrapper.find('.btn-primary').trigger('click');
    await flushPromises();

    expect(wrapper.find('.blockers').exists()).toBe(true);
    expect(wrapper.text()).toContain('Editorial blockers');
    expect(wrapper.text()).toContain('home');
    expect(wrapper.text()).toContain('waiting for review');
    expect(wrapper.text()).toContain('about');
    expect(wrapper.text()).toContain('asked for changes');
  });
});
