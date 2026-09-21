import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Webhooks from './Webhooks.vue';

/**
 * The webhooks screen. These pin that endpoints and deliveries come from the
 * API, that a failed load is said plainly, that adding a webhook POSTs the
 * draft (and surfaces the one-time secret), and that an empty site shows the
 * empty state rather than a blank list.
 */

const endpoint = (overrides = {}) => ({
  id: 'wh_abc',
  url: 'https://example.com/hooks/click',
  description: 'Rebuild the front end',
  events: ['content.published'],
  active: true,
  ...overrides,
});

const jsonRes = (status, body) => ({
  ok: status >= 200 && status < 300,
  status,
  json: async () => body,
});

const withWebhooks = ({ endpoints = [], deliveries = [], canSend = true, onPost } = {}) => {
  let listed = endpoints;

  global.fetch = vi.fn(async (url, init = {}) => {
    const method = (init.method || 'GET').toUpperCase();
    const path = String(url);

    if (method === 'GET' && path === '/api/webhooks') {
      return jsonRes(200, { data: { endpoints: listed, canSend } });
    }
    if (method === 'GET' && path === '/api/webhooks/deliveries') {
      return jsonRes(200, { data: deliveries });
    }
    if (method === 'POST' && path === '/api/webhooks') {
      const sent = JSON.parse(init.body);
      if (onPost) return onPost(sent);
      listed = [...listed, endpoint({ id: 'wh_new', ...sent })];
      return jsonRes(201, { data: { id: 'wh_new', secret: 'sec_once_only' } });
    }
    if (method === 'PUT' && path.startsWith('/api/webhooks/')) {
      return jsonRes(200, { data: {} });
    }
    if (method === 'DELETE' && path.startsWith('/api/webhooks/')) {
      const id = path.slice('/api/webhooks/'.length);
      listed = listed.filter((e) => e.id !== id);
      return jsonRes(200, { data: null });
    }
    return jsonRes(200, { data: [] });
  });
};

const mountWebhooks = async (opts = {}) => {
  withWebhooks(opts);
  const wrapper = mount(Webhooks);
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  vi.restoreAllMocks();
  window.confirm = vi.fn(() => true);
});

describe('the webhook list', () => {
  it('renders every endpoint from the API with URL, description and events', async () => {
    const wrapper = await mountWebhooks({
      endpoints: [
        endpoint(),
        endpoint({
          id: 'wh_xyz',
          url: 'https://search.example/reindex',
          description: 'Search index',
          events: ['content.saved', 'content.deleted'],
          active: false,
        }),
      ],
    });

    expect(wrapper.findAll('.endpoint')).toHaveLength(2);
    expect(wrapper.text()).toContain('https://example.com/hooks/click');
    expect(wrapper.text()).toContain('Rebuild the front end');
    expect(wrapper.text()).toContain('content.published');
    expect(wrapper.text()).toContain('Search index');
    expect(wrapper.text()).toContain('Off');
  });

  it('says so plainly when nothing is configured', async () => {
    const wrapper = await mountWebhooks({ endpoints: [] });

    expect(wrapper.text()).toContain('None yet. Nothing is being sent anywhere.');
    expect(wrapper.findAll('.endpoint')).toHaveLength(0);
  });

  it('shows an error banner when the list cannot be loaded', async () => {
    global.fetch = vi.fn(async (url) => {
      if (String(url) === '/api/webhooks/deliveries') {
        return jsonRes(200, { data: [] });
      }
      return jsonRes(500, { error: 'Storage unavailable' });
    });
    const wrapper = mount(Webhooks);
    await flushPromises();

    expect(wrapper.findAll('[role="alert"]').some((el) => el.text().includes('Storage unavailable'))).toBe(true);
  });
});

describe('adding a webhook', () => {
  it('POSTs the draft to /api/webhooks and shows the one-time signing secret', async () => {
    const wrapper = await mountWebhooks({ endpoints: [] });

    const fields = wrapper.findAll('.field input');
    await fields[0].setValue('https://hooks.example/rebuild');
    await fields[1].setValue('Static rebuild');
    await wrapper.find('.btn-primary').trigger('click');
    await flushPromises();

    const post = global.fetch.mock.calls.find(([, init]) => init?.method === 'POST');
    expect(post, 'a POST should have been sent').toBeTruthy();
    expect(post[0]).toBe('/api/webhooks');
    expect(JSON.parse(post[1].body)).toEqual({
      url: 'https://hooks.example/rebuild',
      description: 'Static rebuild',
      events: ['content.published'],
    });
    expect(wrapper.text()).toContain('Copy this signing secret now');
    expect(wrapper.find('#new-secret').element.value).toBe('sec_once_only');
    expect(wrapper.text()).toContain('https://hooks.example/rebuild');
  });
});

describe('deleting a webhook', () => {
  it('DELETEs the endpoint once confirmed and refreshes the list', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const wrapper = await mountWebhooks({ endpoints: [endpoint()] });

    await wrapper.find('.btn-danger').trigger('click');
    await flushPromises();

    expect(confirm).toHaveBeenCalled();
    const del = global.fetch.mock.calls.find(([, init]) => init?.method === 'DELETE');
    expect(del, 'a DELETE should have been sent').toBeTruthy();
    expect(del[0]).toBe('/api/webhooks/wh_abc');
    expect(wrapper.text()).toContain('None yet. Nothing is being sent anywhere.');
  });
});
