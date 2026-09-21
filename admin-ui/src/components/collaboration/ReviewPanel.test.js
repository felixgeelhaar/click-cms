import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ReviewPanel from './ReviewPanel.vue';

/**
 * The review panel: where a page stands in the approval workflow. Behaviours
 * worth pinning:
 *
 *  - it loads settings and the current review state for the page it is given;
 *  - requesting review POSTs to the review endpoint with the page in the body;
 *  - approving POSTs to the decision endpoint with decision "approve";
 *  - a server refusal (such as self-approve) is shown as an error alert.
 */

const emptyReview = () => ({
  page: 'home',
  locale: 'en',
  state: '',
  open: false,
  requestedBy: '',
  requestedByName: '',
  requestedAt: '',
  note: '',
  reviewer: '',
  decidedBy: null,
  decidedByName: null,
  decidedAt: null,
  decisionNote: null,
  history: [],
});

const inReview = (overrides = {}) => ({
  ...emptyReview(),
  state: 'in_review',
  open: true,
  requestedBy: 'ada',
  requestedByName: 'Ada Lovelace',
  requestedAt: '2026-07-01T10:00:00.000000+00:00',
  note: 'Please check the hero image',
  ...overrides,
});

/**
 * Route the mocked fetch. Settings and review GET are answered from fixtures;
 * POSTs are recorded.
 */
const server = ({ review = emptyReview(), gateEnabled = false } = {}) => {
  const posts = [];
  let current = review;

  global.fetch = vi.fn(async (url, init = {}) => {
    const u = String(url);
    const method = (init.method || 'GET').toUpperCase();

    if (u.includes('/api/collaboration/review/settings')) {
      return { ok: true, status: 200, json: async () => ({ data: { enabled: gateEnabled } }) };
    }

    if (method === 'POST') {
      posts.push({ url: u, body: JSON.parse(init.body) });
      return { ok: true, status: 200, json: async () => ({ data: current }) };
    }

    if (u.includes('/api/collaboration/review')) {
      return { ok: true, status: 200, json: async () => ({ data: current }) };
    }

    return { ok: false, status: 404, json: async () => ({}) };
  });

  return {
    posts,
    setReview: (next) => { current = next; },
  };
};

const mountPanel = async (opts, props = {}) => {
  const ctx = server(opts);
  const wrapper = mount(ReviewPanel, { props: { page: 'home', locale: 'en', ...props } });
  await flushPromises();
  return { wrapper, ...ctx };
};

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('loading review state', () => {
  it('shows the current review state from the mocked fetch', async () => {
    const { wrapper } = await mountPanel({ review: inReview() });

    expect(wrapper.text()).toContain('Waiting for review');
    expect(wrapper.text()).toContain('Ada Lovelace');
    expect(wrapper.text()).toContain('Please check the hero image');
  });

  it('shows no review when the page has none', async () => {
    const { wrapper } = await mountPanel({ review: emptyReview() });

    expect(wrapper.text()).toContain('No review');
    expect(wrapper.find('form .btn-primary').text()).toContain('Request review');
  });

  it('fetches for the page and locale it was given', async () => {
    await mountPanel({ review: emptyReview() }, { page: 'about', locale: 'de' });

    const reviewCall = global.fetch.mock.calls.find(([url]) =>
      String(url).includes('/api/collaboration/review?') && !String(url).includes('settings'),
    );
    const url = String(reviewCall[0]);
    expect(url).toContain('page=about');
    expect(url).toContain('locale=de');
  });

  it('notes when review gating is off', async () => {
    const { wrapper } = await mountPanel({ review: emptyReview(), gateEnabled: false });
    expect(wrapper.text().toLowerCase()).toContain('review gating is off');
  });
});

describe('requesting a review', () => {
  it('POSTs to the review endpoint with the page in the body', async () => {
    const { wrapper, posts } = await mountPanel({ review: emptyReview() });

    const note = wrapper.find('#collaboration-review-request-note');
    await note.setValue('Check the intro');
    await flushPromises();
    await wrapper.find('form.review-form').trigger('submit');
    await flushPromises();

    const create = posts.find((p) => p.url.endsWith('/api/collaboration/review') && !p.url.includes('decision') && !p.url.includes('cancel') && !p.url.includes('settings'));
    expect(create).toBeTruthy();
    expect(create.body).toMatchObject({
      page: 'home',
      locale: 'en',
      note: 'Check the intro',
    });
  });
});

describe('approving a review', () => {
  it('POSTs to the decision endpoint with decision approve', async () => {
    const { wrapper, posts } = await mountPanel({ review: inReview() });

    await wrapper.get('.review-decision .btn-primary').trigger('click');
    await flushPromises();

    const decision = posts.find((p) => p.url.endsWith('/api/collaboration/review/decision'));
    expect(decision).toBeTruthy();
    expect(decision.body).toMatchObject({
      page: 'home',
      locale: 'en',
      decision: 'approve',
    });
  });

  it('shows the server error when self-approve is refused', async () => {
    global.fetch = vi.fn(async (url, init = {}) => {
      const u = String(url);
      const method = (init.method || 'GET').toUpperCase();

      if (u.includes('/api/collaboration/review/settings')) {
        return { ok: true, status: 200, json: async () => ({ data: { enabled: true } }) };
      }
      if (method === 'GET' && u.includes('/api/collaboration/review')) {
        return { ok: true, status: 200, json: async () => ({ data: inReview() }) };
      }
      if (u.endsWith('/api/collaboration/review/decision')) {
        return {
          ok: false,
          status: 403,
          json: async () => ({ error: 'You cannot approve your own request for review.' }),
        };
      }
      return { ok: true, status: 200, json: async () => ({ data: {} }) };
    });

    const wrapper = mount(ReviewPanel, { props: { page: 'home', locale: 'en' } });
    await flushPromises();

    await wrapper.get('.review-decision .btn-primary').trigger('click');
    await flushPromises();

    expect(wrapper.get('.review-error').text()).toContain('You cannot approve your own request for review.');
  });
});
