import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Marketplace from './Marketplace.vue';

/**
 * The marketplace screen. Two things it has to get right that have already
 * bitten once:
 *
 * - An unconfigured registry must look like an empty state, not a red banner.
 *   The catalogue used to report "not configured" as an error, and the UI
 *   treated a missing `registryUrl` field the same way — so a fresh install
 *   looked broken.
 * - Choosing a ZIP must POST to `/api/marketplace/upload`. That route used to
 *   405; these pin that the screen still asks for it, so a regression is a
 *   failing test rather than a silent broken button.
 */

const catalog = (data) => ({
  ok: true,
  status: 200,
  json: async () => ({ data }),
});

const emptyCatalog = catalog({
  available: [],
  errors: [],
  installed: [],
  registryConfigured: false,
  message: 'Marketplace catalog not configured',
});

beforeEach(() => vi.restoreAllMocks());

describe('when no registry is configured', () => {
  it('explains the empty state without a failure banner', async () => {
    global.fetch = vi.fn(async () => emptyCatalog);

    const wrapper = mount(Marketplace);
    await flushPromises();

    expect(wrapper.find('.banner.error').exists()).toBe(false);
    expect(wrapper.text()).toContain('No registry is configured');
  });
});

describe('uploading a plugin', () => {
  it('posts the chosen ZIP to /api/marketplace/upload', async () => {
    global.fetch = vi.fn(async (url, init) => {
      if (init?.method === 'POST' && url === '/api/marketplace/upload') {
        return {
          ok: true,
          status: 201,
          json: async () => ({ data: { id: 'demo-plugin', name: 'Demo Plugin', version: '1.0.0' } }),
        };
      }
      return emptyCatalog;
    });

    const wrapper = mount(Marketplace);
    await flushPromises();

    const input = wrapper.get('input[type="file"]');
    const file = new File(['PK\x03\x04'], 'demo.zip', { type: 'application/zip' });
    Object.defineProperty(input.element, 'files', { value: [file] });
    await input.trigger('change');
    await flushPromises();

    const upload = global.fetch.mock.calls.find(
      ([url, init]) => url === '/api/marketplace/upload' && init?.method === 'POST'
    );
    expect(upload, 'upload POST should have been sent').toBeTruthy();
    expect(upload[1].body).toBeInstanceOf(FormData);
    expect(wrapper.find('.banner.success').text()).toContain('Demo Plugin uploaded');
  });

  it('surfaces a server refusal instead of claiming success', async () => {
    global.fetch = vi.fn(async (url, init) => {
      if (init?.method === 'POST') {
        return { ok: false, status: 405, json: async () => ({ error: 'Method not allowed' }) };
      }
      return emptyCatalog;
    });

    const wrapper = mount(Marketplace);
    await flushPromises();

    const input = wrapper.get('input[type="file"]');
    const file = new File(['PK\x03\x04'], 'demo.zip', { type: 'application/zip' });
    Object.defineProperty(input.element, 'files', { value: [file] });
    await input.trigger('change');
    await flushPromises();

    expect(wrapper.find('.banner.error').text()).toContain('Method not allowed');
    expect(wrapper.find('.banner.success').exists()).toBe(false);
  });
});
