import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PresenceBar from './PresenceBar.vue';

/**
 * The presence bar: who else is on this page right now. The behaviours worth
 * pinning:
 *
 *  - it renders the roster the poll returns;
 *  - a poll is a heartbeat POST to the presence endpoint carrying the page, and
 *    the roster comes back in the same response — one request per tick;
 *  - given the signed-in username it shows *others*, not the viewer reflected
 *    back at themselves;
 *  - a display name is rendered as inert text.
 *
 * A fake timer keeps the component's polling interval from leaking real timers
 * into the suite; every assertion here is about the first, immediate poll.
 */

const roster = (...editors) => ({ data: { editors } });
const editor = (user, name) => ({ user, name, lastSeen: 1_000_000 });

const server = (payload) => {
  const posts = [];
  global.fetch = vi.fn(async (url, init = {}) => {
    posts.push({ url: String(url), body: init.body ? JSON.parse(init.body) : null });
    return { ok: true, status: 200, json: async () => payload };
  });
  return { posts };
};

const mountBar = async (payload, props = {}) => {
  const ctx = server(payload);
  const wrapper = mount(PresenceBar, { props: { page: 'home', locale: 'en', ...props } });
  await flushPromises();
  return { wrapper, ...ctx };
};

beforeEach(() => {
  vi.restoreAllMocks();
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
});

describe('rendering the roster', () => {
  it('renders each current viewer from the mocked poll', async () => {
    const { wrapper } = await mountBar(roster(editor('ada', 'Ada Lovelace'), editor('hanna', 'Hanna')));

    const names = wrapper.findAll('.presence-editor').map((n) => n.text());
    expect(names.join(' ')).toContain('Ada Lovelace');
    expect(names.join(' ')).toContain('Hanna');
  });

  it('heartbeats to the presence endpoint with the page in the body', async () => {
    const { posts } = await mountBar(roster(editor('ada', 'Ada Lovelace')));

    expect(posts[0].url).toContain('/api/collaboration/presence');
    expect(posts[0].body).toMatchObject({ page: 'home', locale: 'en' });
  });

  it('renders nothing when nobody else is present', async () => {
    const { wrapper } = await mountBar(roster());
    expect(wrapper.find('.presence').exists()).toBe(false);
  });

  it('excludes the signed-in viewer, showing only others', async () => {
    const { wrapper } = await mountBar(
      roster(editor('ada', 'Ada Lovelace'), editor('hanna', 'Hanna')),
      { currentUser: 'ada' },
    );

    const text = wrapper.text();
    expect(text).toContain('Hanna');
    expect(text).not.toContain('Ada Lovelace');
  });
});

describe('untrusted input', () => {
  it('renders a display name as inert text, never as live markup', async () => {
    const payload = '<script>alert(1)</script>';
    const { wrapper } = await mountBar(roster(editor('x', payload)));

    expect(wrapper.findAll('script')).toHaveLength(0);
    expect(wrapper.text()).toContain(payload);
  });
});
