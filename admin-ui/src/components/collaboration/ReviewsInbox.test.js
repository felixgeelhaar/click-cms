import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ReviewsInbox from './ReviewsInbox.vue';

/**
 * The open-reviews inbox. Worth pinning:
 *
 *  - it loads `GET /api/collaboration/review` with no page (that is the list);
 *  - each row shows page, locale, state, requester, assignee and when it was
 *    asked, and links to the page editor;
 *  - an empty list is an empty state, not a blank table;
 *  - a failed fetch is an error banner.
 */

const review = (overrides = {}) => ({
  page: 'home',
  locale: 'en',
  state: 'in_review',
  open: true,
  requestedBy: 'ada',
  requestedByName: 'Ada Lovelace',
  requestedAt: '2026-07-01T10:00:00.000000+00:00',
  reviewer: 'grace',
  ...overrides,
});

const server = ({ open = [review()], ok = true, status = 200, fail = false } = {}) => {
  global.fetch = vi.fn(async (url) => {
    if (fail) throw new Error('network down');
    return {
      ok,
      status,
      json: async () => (ok ? { data: { open } } : { error: 'nope' }),
    };
  });
};

const mountInbox = async (opts) => {
  server(opts);
  const wrapper = mount(ReviewsInbox);
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('ReviewsInbox', () => {
  it('lists open reviews from the API and links each row to the page editor', async () => {
    const wrapper = await mountInbox({
      open: [
        review(),
        review({
          page: 'about',
          locale: 'de',
          state: 'changes_requested',
          requestedBy: 'charles',
          requestedByName: '',
          reviewer: '',
          requestedAt: '2026-07-02T11:00:00.000000+00:00',
        }),
      ],
    });

    expect(global.fetch).toHaveBeenCalledWith('/api/collaboration/review');
    expect(String(global.fetch.mock.calls[0][0])).not.toContain('page=');

    const rows = wrapper.findAll('tbody tr');
    expect(rows).toHaveLength(2);

    expect(rows[0].text()).toContain('home');
    expect(rows[0].text()).toContain('en');
    expect(rows[0].text()).toContain('Waiting for review');
    expect(rows[0].text()).toContain('Ada Lovelace');
    expect(rows[0].text()).toContain('grace');
    expect(rows[0].find('time').attributes('datetime')).toBe('2026-07-01T10:00:00.000000+00:00');

    const home = rows[0].find('a.page-link');
    expect(home.attributes('href')).toBe('/admin/pages/edit/home?locale=en');

    expect(rows[1].text()).toContain('about');
    expect(rows[1].text()).toContain('de');
    expect(rows[1].text()).toContain('Changes requested');
    expect(rows[1].text()).toContain('charles');
    expect(rows[1].find('a.page-link').attributes('href')).toBe('/admin/pages/edit/about?locale=de');

    await home.trigger('click');
    expect(wrapper.emitted('navigate')[0]).toEqual(['/admin/pages/edit/home?locale=en']);
  });

  it('shows an empty state when nothing is open', async () => {
    const wrapper = await mountInbox({ open: [] });

    expect(wrapper.find('.empty').exists()).toBe(true);
    expect(wrapper.text().toLowerCase()).toContain('no open reviews');
    expect(wrapper.find('table').exists()).toBe(false);
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });

  it('shows an error banner when the fetch fails', async () => {
    const refused = await mountInbox({ ok: false, status: 500 });
    expect(refused.find('[role="alert"]').text()).toContain('Could not load open reviews');
    expect(refused.find('table').exists()).toBe(false);
    expect(refused.find('.empty').exists()).toBe(false);

    const down = await mountInbox({ fail: true });
    expect(down.find('[role="alert"]').text()).toContain('Could not load open reviews');
  });
});
