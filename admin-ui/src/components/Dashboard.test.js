import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Dashboard from './Dashboard.vue';

/**
 * The dashboard counted `data.status`, a field removed when publishing became an
 * action rather than a value. The first screen an editor saw therefore reported
 * "0 Published, 0 Drafts" for a site that was entirely live.
 *
 * It also used to swallow fetch failures into `console.error`, so a broken API
 * looked identical to an empty site. These pin both the counts and the failure
 * / first-run surfaces.
 */

const page = (slug, publication) => ({
  key: `page:en:${slug}`,
  slug,
  data: { title: slug },
  publication,
});

const LIVE = { published: true, hasUnpublishedChanges: false, neverPublished: false };
const PENDING = { published: true, hasUnpublishedChanges: true, neverPublished: false };
const NEVER = { published: false, hasUnpublishedChanges: false, neverPublished: true };

const mountDashboard = async (pages, plugins = [], capabilities = []) => {
  global.fetch = vi.fn(async (url) => ({
    ok: true,
    json: async () => ({ data: String(url).includes('/api/plugins') ? plugins : pages }),
  }));

  const wrapper = mount(Dashboard, { props: { capabilities } });
  await flushPromises();
  return wrapper;
};

/** The stat cards as {label: value}, which is what the screen actually claims. */
const stats = (wrapper) =>
  Object.fromEntries(
    wrapper.findAllComponents({ name: 'StatCard' }).map((c) => [c.props('label'), c.props('value')])
  );

beforeEach(() => vi.restoreAllMocks());

describe('what the dashboard reports', () => {
  it('counts live pages from publication state, not a removed field', async () => {
    const wrapper = await mountDashboard([
      page('home', LIVE),
      page('about', LIVE),
      page('secret', NEVER),
    ]);

    expect(stats(wrapper)['Live']).toBe(2);
  });

  /**
   * The number an editor should act on: finished work that no visitor can see.
   * "Drafts" no longer names anything the system has.
   */
  it('counts pages whose edits are waiting to go live', async () => {
    const wrapper = await mountDashboard([
      page('home', LIVE),
      page('about', PENDING),
      page('prices', PENDING),
    ]);

    expect(stats(wrapper)['Edits pending']).toBe(2);
  });

  it('counts every page regardless of publication', async () => {
    const wrapper = await mountDashboard([
      page('home', LIVE),
      page('secret', NEVER),
    ]);

    expect(stats(wrapper)['Total Pages']).toBe(2);
  });
});

describe('when the API fails', () => {
  it('shows an error instead of pretending the site is empty', async () => {
    global.fetch = vi.fn(async () => ({
      ok: false,
      status: 500,
      json: async () => ({ error: 'Storage unavailable' }),
    }));

    const wrapper = mount(Dashboard);
    await flushPromises();

    expect(wrapper.find('[role="alert"]').text()).toContain('Storage unavailable');
    expect(wrapper.find('.first-run').exists()).toBe(false);
  });
});

describe('a fresh empty site', () => {
  it('offers a first-run panel with a create-page link', async () => {
    const wrapper = await mountDashboard([], [], ['content.create']);

    expect(wrapper.find('.first-run').exists()).toBe(true);
    expect(wrapper.text()).toContain('Your site is empty');
    expect(wrapper.find('a.btn-primary').attributes('href')).toContain('/admin/pages/new');
    // No settings.manage → no seed button.
    expect(wrapper.findAll('button').filter((b) => b.text().includes('example')).length).toBe(0);
  });

  it('lets an administrator load the example site', async () => {
    let seeded = false;
    global.fetch = vi.fn(async (url, init) => {
      if (init?.method === 'POST' && url === '/api/seed') {
        seeded = true;
        return {
          ok: true,
          status: 201,
          json: async () => ({ data: { created: ['page/home', 'page/about'], skipped: [], failures: [], noop: false } }),
        };
      }
      if (String(url).includes('/api/pages')) {
        return { ok: true, json: async () => ({ data: seeded ? [page('home', LIVE)] : [] }) };
      }
      return { ok: true, json: async () => ({ data: [] }) };
    });

    const wrapper = mount(Dashboard, { props: { capabilities: ['settings.manage'] } });
    await flushPromises();

    expect(wrapper.find('.first-run').exists()).toBe(true);
    await wrapper.findAll('button').find((b) => b.text().includes('example')).trigger('click');
    await flushPromises();

    const seedCall = global.fetch.mock.calls.find(
      ([url, init]) => url === '/api/seed' && init?.method === 'POST'
    );
    expect(seedCall, 'seed POST should have been sent').toBeTruthy();
    expect(wrapper.find('[role="status"]').text()).toContain('Loaded 2 example items');
    expect(wrapper.find('.first-run').exists()).toBe(false);
  });
});
