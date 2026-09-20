import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Plugins from './Plugins.vue';

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
