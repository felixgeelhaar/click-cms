import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import CommentsPanel from './CommentsPanel.vue';

/**
 * The comments panel: a page's review thread. The behaviours worth pinning:
 *
 *  - it renders the thread the API returns for the page it is given;
 *  - submitting POSTs the comment to the comments endpoint with the page in the
 *    body, then reloads so it shows the server's canonical record;
 *  - resolving POSTs to the resolve endpoint with the comment's id;
 *  - a comment body is untrusted editor text, so it is shown as inert text and
 *    never as live markup — the same stored-XSS contract the plugin holds at
 *    rest, on the display side.
 */

const comment = (id, author, body, resolved = false) => ({
  id,
  page: 'home',
  locale: 'en',
  author,
  authorName: author,
  body,
  resolved,
  postedAt: '2026-07-01T10:00:00.000000+00:00',
  resolvedAt: null,
  resolvedBy: null,
});

/**
 * Route the mocked fetch. GET returns the given thread; POST to either endpoint
 * is recorded and answered with success. A fresh thread can be handed back after
 * a write via `afterWrite` so a reload reflects it.
 */
const server = ({ thread = [], afterWrite = null } = {}) => {
  const posts = [];
  let current = thread;
  global.fetch = vi.fn(async (url, init = {}) => {
    const u = String(url);
    const method = (init.method || 'GET').toUpperCase();

    if (method === 'POST') {
      posts.push({ url: u, body: JSON.parse(init.body) });
      if (afterWrite) current = afterWrite;
      return { ok: true, status: 200, json: async () => ({ data: {} }) };
    }
    // GET the thread.
    return { ok: true, status: 200, json: async () => ({ data: current }) };
  });
  return { posts };
};

const mountPanel = async (opts, props = {}) => {
  const ctx = server(opts);
  const wrapper = mount(CommentsPanel, { props: { page: 'home', locale: 'en', ...props } });
  await flushPromises();
  return { wrapper, ...ctx };
};

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('rendering the thread', () => {
  it('renders each comment from the mocked fetch', async () => {
    const { wrapper } = await mountPanel({
      thread: [
        comment('a', 'Ada Lovelace', 'The hero crop is wrong'),
        comment('b', 'Bob', 'Fixed the footer typo', true),
      ],
    });

    const rows = wrapper.findAll('.comment');
    expect(rows).toHaveLength(2);
    expect(wrapper.text()).toContain('Ada Lovelace');
    expect(wrapper.text()).toContain('The hero crop is wrong');
    expect(wrapper.text()).toContain('Fixed the footer typo');
  });

  it('shows an empty state when there are no comments', async () => {
    const { wrapper } = await mountPanel({ thread: [] });
    expect(wrapper.findAll('.comment')).toHaveLength(0);
    expect(wrapper.text().toLowerCase()).toContain('no comments');
  });

  it('fetches for the page and locale it was given', async () => {
    const { wrapper } = await mountPanel({ thread: [] }, { page: 'about', locale: 'de' });
    const url = String(global.fetch.mock.calls[0][0]);
    expect(url).toContain('page=about');
    expect(url).toContain('locale=de');
    expect(wrapper.exists()).toBe(true);
  });

  it('shows an error state when the request fails', async () => {
    global.fetch = vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) }));
    const wrapper = mount(CommentsPanel, { props: { page: 'home', locale: 'en' } });
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
  });
});

describe('posting a comment', () => {
  it('POSTs the comment to the comments endpoint with the page in the body', async () => {
    const { wrapper, posts } = await mountPanel({ thread: [] });

    await wrapper.find('textarea').setValue('Please review the intro paragraph');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    const create = posts.find((p) => p.url.endsWith('/api/collaboration/comments'));
    expect(create).toBeTruthy();
    expect(create.body).toMatchObject({
      page: 'home',
      locale: 'en',
      body: 'Please review the intro paragraph',
    });
  });

  it('does not post an empty comment', async () => {
    const { wrapper, posts } = await mountPanel({ thread: [] });

    await wrapper.find('textarea').setValue('   ');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(posts).toHaveLength(0);
  });

  it('clears the input and shows the new comment after posting', async () => {
    const { wrapper } = await mountPanel({
      thread: [],
      afterWrite: [comment('new', 'Ada', 'Please review the intro')],
    });

    await wrapper.find('textarea').setValue('Please review the intro');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(wrapper.find('textarea').element.value).toBe('');
    expect(wrapper.text()).toContain('Please review the intro');
  });
});

describe('resolving a comment', () => {
  it('POSTs to the resolve endpoint with the comment id', async () => {
    const { wrapper, posts } = await mountPanel({
      thread: [comment('c-42', 'Ada', 'Typo in the footer')],
    });

    await wrapper.find('.comment .btn-link').trigger('click');
    await flushPromises();

    const resolve = posts.find((p) => p.url.endsWith('/api/collaboration/comments/resolve'));
    expect(resolve).toBeTruthy();
    expect(resolve.body).toMatchObject({ id: 'c-42' });
  });

  it('shows a resolved comment as resolved and offers no resolve button', async () => {
    const { wrapper } = await mountPanel({
      thread: [comment('c', 'Ada', 'Already handled', true)],
    });

    expect(wrapper.find('.comment.is-resolved').exists()).toBe(true);
    expect(wrapper.find('.comment .btn-link').exists()).toBe(false);
  });
});

describe('untrusted input', () => {
  it('renders a comment body as inert text, never as live markup', async () => {
    const payload = '<script>alert(document.cookie)</script>';
    const { wrapper } = await mountPanel({ thread: [comment('x', 'Mallory', payload)] });

    // No script element was created from the stored value...
    expect(wrapper.findAll('script')).toHaveLength(0);
    // ...and the value is present as text the browser will not execute.
    expect(wrapper.text()).toContain(payload);
  });
});
