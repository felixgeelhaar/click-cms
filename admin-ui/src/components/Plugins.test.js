import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Plugins from './Plugins.vue';
import { messageLanguage } from '../lib/messages.js';
afterEach(() => { messageLanguage.value = 'en'; });

/**
 * The Plugins page. Discovery issues used to be invisible — a bad plugin.json
 * just meant the folder never appeared. These pin that the list still renders
 * healthy plugins and that skipped folders are called out.
 */

beforeEach(() => vi.restoreAllMocks());

const mountPlugins = async (body) => {
  global.fetch = vi.fn(async () => ({
    ok: true,
    status: 200,
    json: async () => body,
  }));
  const wrapper = mount(Plugins);
  await flushPromises();
  return wrapper;
};

describe('the plugin list', () => {
  it('renders installed plugins', async () => {
    const wrapper = await mountPlugins({
      data: [
        { id: 'search', name: 'Search', description: 'Full-text search', state: 'activated', version: '1.0.0' },
      ],
      issues: [],
    });

    expect(wrapper.text()).toContain('Search');
    expect(wrapper.text()).toContain('activated');
    expect(wrapper.find('.banner.warn').exists()).toBe(false);
  });

  it('surfaces discovery issues from the API', async () => {
    const wrapper = await mountPlugins({
      data: [],
      issues: [
        { directory: 'broken', reason: 'plugin.json is not valid JSON (Syntax error).' },
      ],
    });

    expect(wrapper.find('.banner.warn').exists()).toBe(true);
    expect(wrapper.text()).toContain('broken');
    expect(wrapper.text()).toContain('not valid JSON');
  });

  it('shows an error when the request fails', async () => {
    global.fetch = vi.fn(async () => ({
      ok: false,
      status: 500,
      json: async () => ({ error: 'Storage unavailable' }),
    }));
    const wrapper = mount(Plugins);
    await flushPromises();

    expect(wrapper.find('[role="alert"]').text()).toContain('Storage unavailable');
  });
});

describe('changing plugin state', () => {
  const plugin = { id: 'search-api', name: 'Search API', state: 'discovered' };
  const response = (data, ok = true) => ({ ok, json: async () => data });

  it.each(['activate', 'deactivate'])('shows a refused %s without reloading away the error', async (action) => {
    const state = action === 'deactivate' ? 'activated' : 'discovered';
    global.fetch = vi.fn(async (url, init) => init?.method === 'POST'
      ? response({ error: 'Plugin blocked by deployment policy' }, false)
      : response({ data: [{ ...plugin, state }] }));
    const wrapper = mount(Plugins);
    await flushPromises();
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(wrapper.get('.plugin-card [role="alert"]').text()).toContain('Plugin blocked by deployment policy');
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined();
  });

  it('handles network failures in German and allows retrying', async () => {
    messageLanguage.value = 'de';
    global.fetch = vi.fn(async (url, init) => {
      if (init?.method === 'POST') throw new TypeError('Failed to fetch');
      return response({ data: [plugin] });
    });
    const wrapper = mount(Plugins);
    await flushPromises();
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Das Plugin konnte nicht aktiviert werden.');
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined();
  });

  it('prevents duplicate changes and refreshes the state after success', async () => {
    let complete;
    let activated = false;
    global.fetch = vi.fn(async (url, init) => {
      if (init?.method === 'POST') return new Promise(resolve => { complete = () => { activated = true; resolve(response({ data: { activated: true } })); }; });
      return response({ data: [{ ...plugin, state: activated ? 'activated' : 'discovered' }] });
    });
    const wrapper = mount(Plugins);
    await flushPromises();
    await wrapper.get('button').trigger('click');
    expect(wrapper.get('button').attributes('disabled')).toBeDefined();
    expect(wrapper.get('button').attributes('aria-busy')).toBe('true');
    await wrapper.get('button').trigger('click');
    expect(fetch.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(1);
    complete();
    await flushPromises();
    expect(wrapper.get('button').text()).toBe('Deactivate');
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });
});
