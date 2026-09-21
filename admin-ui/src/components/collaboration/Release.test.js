import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Release from './Release.vue';

/**
 * The release screen. Worth pinning:
 *
 *  - it loads pages, collection entries, and open reviews together;
 *  - selecting pages and entries posts a mixed release body;
 *  - a 409 lists each refused item as an editorial blocker.
 */

const page = (slug, title) => ({
  slug,
  key: `page:en:${slug}`,
  data: { title },
});

const collectionType = (id, label) => ({
  id,
  label,
  titleField: 'title',
});

const entry = (slug, title) => ({
  slug,
  key: `post:en:${slug}`,
  data: { values: { title } },
});

const review = (overrides = {}) => ({
  page: 'about',
  locale: 'en',
  type: 'page',
  state: 'in_review',
  open: true,
  ...overrides,
});

const server = ({
  pages = [page('home', 'Home'), page('about', 'About')],
  locale = 'en',
  open = [review()],
  collections = [collectionType('post', 'Posts')],
  entriesByType = { post: [entry('hello-world', 'Hello')] },
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

    if (path === '/api/collections' && method === 'GET') {
      return {
        ok: true,
        status: 200,
        json: async () => ({ data: collections }),
      };
    }

    const entriesMatch = path.match(/^\/api\/collections\/([^/]+)\/entries$/);
    if (entriesMatch && method === 'GET') {
      const type = decodeURIComponent(entriesMatch[1]);
      return {
        ok: true,
        status: 200,
        json: async () => ({ data: entriesByType[type] || [] }),
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
  it('loads pages and entries and marks open-review items as not ready', async () => {
    const wrapper = await mountRelease({
      open: [
        review(),
        review({ page: 'hello-world', type: 'post', state: 'in_review' }),
      ],
    });

    expect(global.fetch).toHaveBeenCalledWith('/api/pages');
    expect(global.fetch).toHaveBeenCalledWith('/api/collaboration/review');
    expect(global.fetch).toHaveBeenCalledWith('/api/collections');
    expect(global.fetch).toHaveBeenCalledWith('/api/collections/post/entries');

    expect(wrapper.text()).toContain('Pages');
    expect(wrapper.text()).toContain('Collection entries');

    const tables = wrapper.findAll('table.release-table');
    expect(tables).toHaveLength(2);
    const pageTableRows = tables[0].findAll('tbody tr');
    expect(pageTableRows).toHaveLength(2);
    expect(pageTableRows[0].text()).toContain('home');
    expect(pageTableRows[0].text()).toContain('Ready');
    expect(pageTableRows[1].text()).toContain('about');
    expect(pageTableRows[1].text()).toContain('Waiting for review');

    const homeLink = pageTableRows[0].find('a.page-link');
    expect(homeLink.attributes('href')).toBe('/admin/pages/edit/home?locale=en');

    const entryRows = tables[1].findAll('tbody tr');
    expect(entryRows).toHaveLength(1);
    expect(entryRows[0].text()).toContain('Posts');
    expect(entryRows[0].text()).toContain('hello-world');
    expect(entryRows[0].text()).toContain('Waiting for review');
    expect(entryRows[0].find('a.page-link').attributes('href')).toBe(
      '/admin/collections/post/entries/hello-world?locale=en',
    );

    expect(wrapper.find('#release-locale').element.value).toBe('en');
  });

  it('selects pages and entries and posts a mixed release body', async () => {
    let posted = null;
    const wrapper = await mountRelease({
      open: [],
      release: (body) => {
        posted = body;
        const published = [
          ...(body.pages || []).map((slug) => ({ page: slug, locale: body.locale })),
          ...(body.entries || []).map((e) => ({
            type: e.type,
            page: e.page,
            locale: body.locale,
          })),
        ];
        return {
          status: 200,
          ok: true,
          data: { published, failed: [] },
        };
      },
    });

    const tables = wrapper.findAll('table.release-table');
    await tables[0].findAll('tbody input[type="checkbox"]')[0].setValue(true);
    await tables[1].findAll('tbody input[type="checkbox"]')[0].setValue(true);
    await wrapper.find('#release-locale').setValue('de');
    await wrapper.find('.btn-primary').trigger('click');
    await flushPromises();

    const releaseCall = global.fetch.mock.calls.find(
      ([url, opts]) => String(url) === '/api/collaboration/release' && opts?.method === 'POST',
    );
    expect(releaseCall).toBeTruthy();
    expect(posted).toEqual({
      pages: ['home'],
      entries: [{ type: 'post', page: 'hello-world' }],
      locale: 'de',
    });
    expect(wrapper.text()).toContain('Published');
    expect(wrapper.text()).toContain('home');
    expect(wrapper.text()).toContain('post/hello-world');
  });

  it('shows 409 refusals as editorial blockers for pages and entries', async () => {
    const wrapper = await mountRelease({
      open: [],
      release: {
        status: 409,
        ok: false,
        error: 'This release was not published because some of its items are not ready.',
        data: {
          refused: [
            { page: 'home', locale: 'en', reason: 'This page is waiting for review and has not been approved yet.' },
            {
              type: 'post',
              page: 'hello-world',
              locale: 'en',
              reason: 'A reviewer asked for changes on this entry.',
            },
          ],
          published: [],
        },
      },
    });

    await wrapper.findAll('table.release-table')[0]
      .findAll('tbody input[type="checkbox"]')[0]
      .setValue(true);
    await wrapper.find('.btn-primary').trigger('click');
    await flushPromises();

    expect(wrapper.find('.blockers').exists()).toBe(true);
    expect(wrapper.text()).toContain('Editorial blockers');
    expect(wrapper.text()).toContain('home');
    expect(wrapper.text()).toContain('waiting for review');
    expect(wrapper.text()).toContain('post/hello-world');
    expect(wrapper.text()).toContain('asked for changes');
    expect(wrapper.find('.blockers a.page-link').attributes('href')).toBe('/admin/pages/edit/home?locale=en');
  });
});
