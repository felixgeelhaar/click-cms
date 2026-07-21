import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Dashboard from './Dashboard.vue';

/**
 * The dashboard counted `data.status`, a field removed when publishing became an
 * action rather than a value. The first screen an editor saw therefore reported
 * "0 Published, 0 Drafts" for a site that was entirely live.
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

const mountDashboard = async (pages, plugins = []) => {
  global.fetch = vi.fn(async (url) => ({
    ok: true,
    json: async () => ({ data: String(url).includes('/api/plugins') ? plugins : pages }),
  }));

  const wrapper = mount(Dashboard);
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
