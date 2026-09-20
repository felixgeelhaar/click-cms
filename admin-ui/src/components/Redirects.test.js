import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Redirects from './Redirects.vue';

/**
 * The redirects editor. These pin that rules come from the API, that a failed
 * load is said plainly, that Save PUTs the whole list, and that an empty site
 * shows the empty state rather than a blank editor.
 */

const rule = (from, to, permanent = true) => ({ from, to, permanent });

const jsonRes = (status, body) => ({
  ok: status >= 200 && status < 300,
  status,
  json: async () => body,
});

const withRedirects = (list, { onPut } = {}) => {
  let rules = list;

  global.fetch = vi.fn(async (url, init = {}) => {
    const method = (init.method || 'GET').toUpperCase();
    const path = String(url);

    if (method === 'GET' && path === '/api/redirects') {
      return jsonRes(200, { data: rules });
    }
    if (method === 'PUT' && path === '/api/redirects') {
      const sent = JSON.parse(init.body);
      if (onPut) return onPut(sent);
      rules = sent.redirects ?? [];
      return jsonRes(200, { data: rules });
    }
    return jsonRes(200, { data: [] });
  });
};

const mountRedirects = async (list, opts = {}) => {
  withRedirects(list, opts);
  const wrapper = mount(Redirects);
  await flushPromises();
  return wrapper;
};

beforeEach(() => vi.restoreAllMocks());

describe('the redirect list', () => {
  it('renders every rule from the API', async () => {
    const wrapper = await mountRedirects([
      rule('/old-about', '/about'),
      rule('/blog', 'https://example.com/news', false),
    ]);

    const rows = wrapper.findAll('.rule');
    expect(rows).toHaveLength(2);
    expect(rows[0].find('input').element.value).toBe('/old-about');
    expect(rows[0].findAll('input')[1].element.value).toBe('/about');
    expect(rows[1].find('select').element.value).toBe('false');
  });

  it('says so plainly when there are no redirects', async () => {
    const wrapper = await mountRedirects([]);

    expect(wrapper.text()).toContain('No redirects yet.');
    expect(wrapper.findAll('.rule')).toHaveLength(0);
  });

  it('shows an error banner when the list cannot be loaded', async () => {
    global.fetch = vi.fn(async () => jsonRes(500, { error: 'Storage unavailable' }));
    const wrapper = mount(Redirects);
    await flushPromises();

    expect(wrapper.find('[role="alert"]').text()).toContain('Could not load redirects');
    expect(wrapper.find('[role="alert"]').text()).toContain('Request failed (500)');
  });
});

describe('saving redirects', () => {
  it('PUTs the whole list to /api/redirects', async () => {
    const wrapper = await mountRedirects([rule('/a', '/b')]);

    await wrapper.find('#from-0').setValue('/legacy');
    await wrapper.find('#to-0').setValue('/home');
    await wrapper.find('.actions .btn-primary').trigger('click');
    await flushPromises();

    const put = global.fetch.mock.calls.find(([, init]) => init?.method === 'PUT');
    expect(put, 'a PUT should have been sent').toBeTruthy();
    expect(put[0]).toBe('/api/redirects');
    expect(JSON.parse(put[1].body)).toEqual({
      redirects: [{ from: '/legacy', to: '/home', permanent: true }],
    });
    expect(wrapper.find('[role="status"]').text()).toMatch(/Saved 1 redirect/);
  });

  it('includes a newly added blank rule in the PUT body', async () => {
    const wrapper = await mountRedirects([]);

    await wrapper.find('.page-header .btn-primary').trigger('click');
    await wrapper.find('#from-0').setValue('/moved');
    await wrapper.find('#to-0').setValue('/here');
    await wrapper.find('.actions .btn-primary').trigger('click');
    await flushPromises();

    const put = global.fetch.mock.calls.find(([, init]) => init?.method === 'PUT');
    expect(JSON.parse(put[1].body).redirects).toEqual([
      { from: '/moved', to: '/here', permanent: true },
    ]);
    expect(wrapper.findAll('.rule')).toHaveLength(1);
  });
});
