import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PageEdit from './PageEdit.vue';

/**
 * The SEO field group on PageEdit. These cover the behaviours whose breakage
 * would be silent: that the stored `seo` values load into the fields, and — the
 * one that matters most — that they ride along in the save payload under a
 * stable `seo` key. The persistence path (PageService leaves unknown top-level
 * keys alone) only holds if the client actually sends them, so the payload
 * assertion is the guard against SEO edits vanishing on save.
 */

const ok = (body, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => body });
const notFound = () => ({ ok: false, status: 404, json: async () => ({ error: 'Page not found' }) });

const installFetch = ({ seo = undefined, media = [] } = {}) => {
  global.fetch = vi.fn(async (url, init = {}) => {
    const method = (init.method || 'GET').toUpperCase();
    const u = new URL(String(url), 'http://localhost');
    const path = u.pathname;
    const locale = u.searchParams.get('locale') || 'en';

    if (path === '/api/auth/check') return ok({ data: { user: { capabilities: [] } } });
    if (path === '/api/section-types') return ok({ data: [], warnings: {} });
    if (path.startsWith('/api/media')) return ok({ data: media });
    if (path === '/api/pages' && method === 'GET') return ok({ data: [], locale: 'en', locales: ['en'] });
    if (path === '/api/pages' && method === 'POST') return ok({ data: { slug: 'home' } }, 201);

    if (/^\/api\/pages\/[^/]+\/versions$/.test(path) && method === 'GET') return ok({ data: [] });

    const pageMatch = path.match(/^\/api\/pages\/([^/]+)$/);
    if (pageMatch) {
      const slug = pageMatch[1];
      if (method === 'PUT') return ok({ data: { slug } });
      return ok({
        data: { key: `page:${locale}:${slug}`, slug, locale, data: { title: 'Home', sections: [], seo } },
        locale,
        publication: { published: true, hasUnpublishedChanges: false, neverPublished: false, publishedAt: null },
      });
    }

    return ok({ data: [] });
  });
};

const mountEdit = async (scenario = {}, props = {}) => {
  installFetch(scenario);
  const wrapper = mount(PageEdit, { props: { slug: 'home', ...props } });
  await flushPromises();
  await flushPromises();
  return wrapper;
};

const putBody = () => {
  const call = global.fetch.mock.calls.find(([url, init]) =>
    (init?.method || 'GET').toUpperCase() === 'PUT' &&
    /^\/api\/pages\/home$/.test(new URL(String(url), 'http://localhost').pathname));
  return call ? JSON.parse(call[1].body) : null;
};

beforeEach(() => {
  vi.restoreAllMocks();
  window.confirm = vi.fn(() => true);
});

describe('the SEO field group', () => {
  it('loads stored SEO values into the fields', async () => {
    const wrapper = await mountEdit({
      seo: {
        metaTitle: 'Custom title',
        description: 'A description',
        canonicalUrl: 'https://example.com/home',
        noindex: true,
      },
    });

    expect(wrapper.find('#seo-meta-title').element.value).toBe('Custom title');
    expect(wrapper.find('#seo-description').element.value).toBe('A description');
    expect(wrapper.find('#seo-canonical').element.value).toBe('https://example.com/home');
    expect(wrapper.find('#seo-noindex').element.checked).toBe(true);
  });

  it('starts empty when the page has no SEO data', async () => {
    const wrapper = await mountEdit({ seo: undefined });

    expect(wrapper.find('#seo-meta-title').element.value).toBe('');
    expect(wrapper.find('#seo-description').element.value).toBe('');
    expect(wrapper.find('#seo-noindex').element.checked).toBe(false);
  });

  it('sends the SEO fields under a stable "seo" key on save', async () => {
    const wrapper = await mountEdit({ seo: { metaTitle: 'Old' } });

    await wrapper.find('#seo-meta-title').setValue('New title');
    await wrapper.find('#seo-description').setValue('New description');
    await wrapper.find('#seo-canonical').setValue('https://example.com/x');
    await wrapper.find('#seo-noindex').setValue(true);

    global.fetch.mockClear();
    await wrapper.vm.savePage();
    await flushPromises();

    const body = putBody();
    expect(body, 'save should PUT to the page endpoint').toBeTruthy();
    expect(body.seo).toEqual({
      metaTitle: 'New title',
      description: 'New description',
      ogImage: '',
      canonicalUrl: 'https://example.com/x',
      noindex: true,
    });
  });

  it('reuses the media image picker for the Open Graph image', async () => {
    const wrapper = await mountEdit({
      seo: { ogImage: 'photo-1' },
      media: [{
        id: 'photo-1',
        originalName: 'photo.jpg',
        alt: 'A photo',
        width: 1600,
        height: 900,
        variants: ['sm', 'md'],
        urls: { original: '/api/media/file/photo-1' },
      }],
    });

    // The og:image slot mounts the same ImageField section fields use, and it
    // resolves the stored reference to a selected preview rather than showing
    // the raw id.
    expect(wrapper.find('.seo-group .selected').exists()).toBe(true);
    expect(wrapper.find('.seo-group .selected-name').text()).toBe('photo.jpg');
  });
});
