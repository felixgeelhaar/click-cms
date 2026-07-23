import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Collections from './Collections.vue';

/**
 * Tests for the collections admin UI, driven through the whole component tree
 * (Collections → CollectionEntries → CollectionEntryEdit) against a mocked
 * fetch, so the real wiring between the three screens is exercised rather than
 * each in isolation.
 */

const LIVE = { published: true, hasUnpublishedChanges: false, neverPublished: false, publishedAt: '2026-07-01T10:00:00+00:00' };
const NEVER = { published: false, hasUnpublishedChanges: false, neverPublished: true, publishedAt: null };

const TYPES = [
  {
    id: 'blog',
    label: 'Blog Posts',
    description: 'Articles and news',
    titleField: 'title',
    entryCount: 2,
    fields: [
      { name: 'title', type: 'text', label: 'Title', required: true },
      { name: 'body', type: 'textarea', label: 'Body' },
    ],
  },
  {
    id: 'team',
    label: 'Team Members',
    description: 'The people page',
    titleField: 'name',
    entryCount: 0,
    fields: [{ name: 'name', type: 'text', label: 'Name', required: true }],
  },
];

const ENTRIES = [
  { slug: 'hello-world', locale: 'en', title: 'Hello World', data: { title: 'Hello World', body: 'Hi' }, updatedAt: '2026-07-01', publication: LIVE },
  { slug: 'draft-post', locale: 'en', title: 'Draft Post', data: { title: 'Draft Post', body: '' }, updatedAt: '2026-07-02', publication: NEVER },
];

const clone = (x) => JSON.parse(JSON.stringify(x));
const jsonRes = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });

/**
 * A fetch that routes by method and path across the whole collections API.
 * `opts.onPost` lets a test override entry creation (e.g. to force a 422).
 */
const makeFetch = (opts = {}) => vi.fn(async (url, init = {}) => {
  const method = (init.method || 'GET').toUpperCase();
  const path = String(url).split('?')[0];
  const body = init.body ? JSON.parse(init.body) : {};

  if (method === 'GET' && path === '/api/collections') return jsonRes(200, { data: clone(TYPES) });
  if (method === 'GET' && path === '/api/collections/blog/entries') return jsonRes(200, { data: clone(ENTRIES) });

  const entryMatch = path.match(/^\/api\/collections\/blog\/entries\/([^/]+)$/);
  if (method === 'GET' && entryMatch) {
    const entry = ENTRIES.find((e) => e.slug === entryMatch[1]);
    return jsonRes(200, { data: clone(entry) });
  }

  if (method === 'POST' && path === '/api/collections/blog/entries') {
    if (opts.onPost) return opts.onPost(body);
    return jsonRes(201, { data: { slug: 'new-post', data: body.values, publication: NEVER } });
  }
  if (method === 'PUT' && entryMatch) {
    return jsonRes(200, { data: { slug: entryMatch[1], data: body.values, publication: NEVER } });
  }
  if (method === 'POST' && /\/publish$/.test(path)) return jsonRes(200, { data: { publication: LIVE } });
  if (method === 'POST' && /\/unpublish$/.test(path)) return jsonRes(200, { data: { publication: NEVER } });
  if (method === 'DELETE') return jsonRes(200, { data: null });

  return jsonRes(200, { data: [] });
});

const mountCollections = async (opts) => {
  global.fetch = makeFetch(opts);
  const wrapper = mount(Collections);
  await flushPromises();
  return wrapper;
};

const badges = (wrapper) => wrapper.findAll('.status-badge').map((b) => b.text());
const postCalls = (path) =>
  global.fetch.mock.calls.filter(([u, i]) => (i?.method || 'GET') === 'POST' && String(u).includes(path));

beforeEach(() => {
  vi.restoreAllMocks();
  window.confirm = vi.fn(() => true);
});

describe('collection types list', () => {
  it('renders the declared types from the API', async () => {
    const wrapper = await mountCollections();
    const cards = wrapper.findAll('.collection-card');

    expect(cards).toHaveLength(2);
    expect(cards[0].text()).toContain('Blog Posts');
    expect(cards[0].text()).toContain('Articles and news');
    expect(cards[0].text()).toContain('2 entries');
    expect(cards[1].text()).toContain('Team Members');
    expect(cards[1].text()).toContain('0 entries');
  });

  it('shows an empty state when no types are declared', async () => {
    global.fetch = vi.fn(async () => jsonRes(200, { data: [] }));
    const wrapper = mount(Collections);
    await flushPromises();

    expect(wrapper.find('.empty-state').exists()).toBe(true);
    expect(wrapper.findAll('.collection-card')).toHaveLength(0);
  });
});

describe('selecting a type', () => {
  it('lists the type’s entries with titles and publication badges', async () => {
    const wrapper = await mountCollections();

    await wrapper.findAll('.collection-card')[0].trigger('click');
    await flushPromises();

    const titles = wrapper.findAll('.entry-title').map((t) => t.text());
    expect(titles).toEqual(['Hello World', 'Draft Post']);

    // The badge reflects each entry's derived publication state.
    expect(badges(wrapper)).toEqual(['Live', 'Draft']);
  });
});

describe('creating an entry', () => {
  it('POSTs { values } and shows a 422 error next to the offending field', async () => {
    const wrapper = await mountCollections({
      onPost: () => jsonRes(422, { error: 'Validation failed', errors: { title: 'Title is required.' } }),
    });

    await wrapper.findAll('.collection-card')[0].trigger('click');
    await flushPromises();
    await wrapper.find('.btn-new').trigger('click');
    await flushPromises();

    // Type into the Title field specifically (a new entry also has a slug input).
    const titleField = wrapper.findAll('.field').find((f) => f.text().includes('Title'));
    await titleField.find('input').setValue('My Post');

    await wrapper.find('.btn-primary').trigger('click');
    await flushPromises();

    // The request carried the field values under a `values` key.
    const [, init] = postCalls('/api/collections/blog/entries')
      .find(([u]) => String(u).endsWith('/api/collections/blog/entries'));
    const sent = JSON.parse(init.body);
    expect(sent.values.title).toBe('My Post');

    // The 422 message is rendered against the Title field, not the Body field.
    expect(titleField.find('.error-message').text()).toContain('Title is required.');
    const bodyField = wrapper.findAll('.field').find((f) => f.text().includes('Body'));
    expect(bodyField.find('.error-message').exists()).toBe(false);
  });

  it('POSTs successfully and keeps the editor open so the entry can be published', async () => {
    const wrapper = await mountCollections();

    await wrapper.findAll('.collection-card')[0].trigger('click');
    await flushPromises();
    await wrapper.find('.btn-new').trigger('click');
    await flushPromises();

    const titleField = wrapper.findAll('.field').find((f) => f.text().includes('Title'));
    await titleField.find('input').setValue('Fresh Post');
    await wrapper.find('.btn-primary').trigger('click');
    await flushPromises();

    expect(postCalls('/api/collections/blog/entries').length).toBe(1);
    // Still on the editor, now able to publish the just-created entry.
    expect(wrapper.find('.btn-publish').exists()).toBe(true);
  });
});

describe('entry editor depth — languages and history', () => {
  const VERSIONS = [
    { id: 'v2', recordedAt: '2026-07-02T10:00:00Z', title: 'Draft Post', author: 'ed', reason: 'save' },
    { id: 'v1', recordedAt: '2026-07-01T10:00:00Z', title: 'Older Draft', author: 'ed', reason: 'save' },
  ];

  // A fetch that additionally answers the auth check, the site locales, and the
  // per-entry version endpoints the deeper editor now uses.
  const deepFetch = () => vi.fn(async (url, init = {}) => {
    const method = (init.method || 'GET').toUpperCase();
    const path = String(url).split('?')[0];
    const query = String(url).split('?')[1] || '';

    if (path === '/api/auth/check') {
      return jsonRes(200, { data: { user: { username: 'ed', capabilities: ['content.restore', 'content.preview'] } } });
    }
    const previewMint = path.match(/^\/api\/collections\/blog\/entries\/([^/]+)\/preview$/);
    if (method === 'POST' && previewMint) {
      return jsonRes(200, { data: { url: `/api/collections/blog/preview/${previewMint[1]}?token=tok`, expiresAt: 4102444800 } });
    }
    if (method === 'GET' && path === '/api/pages') {
      return jsonRes(200, { data: [], locale: 'en', locales: ['en', 'de'] });
    }
    if (method === 'GET' && path === '/api/collections') return jsonRes(200, { data: clone(TYPES) });
    if (method === 'GET' && path === '/api/collections/blog/entries') return jsonRes(200, { data: clone(ENTRIES) });

    const versionsMatch = path.match(/^\/api\/collections\/blog\/entries\/([^/]+)\/versions$/);
    if (method === 'GET' && versionsMatch) return jsonRes(200, { data: clone(VERSIONS) });

    const backrefMatch = path.match(/^\/api\/collections\/blog\/entries\/([^/]+)\/backreferences$/);
    if (method === 'GET' && backrefMatch) {
      return jsonRes(200, { data: [{ type: 'blog', slug: 'related-post', title: 'Related Post', field: 'related', locale: 'en' }] });
    }

    const restoreMatch = path.match(/^\/api\/collections\/blog\/entries\/([^/]+)\/versions\/([^/]+)\/restore$/);
    if (method === 'POST' && restoreMatch) {
      return jsonRes(200, { data: { restoredFrom: {}, entry: { slug: restoreMatch[1], data: { title: 'Older Draft' }, publication: NEVER } } });
    }

    const entryMatch = path.match(/^\/api\/collections\/blog\/entries\/([^/]+)$/);
    if (method === 'GET' && entryMatch) {
      // A German request for an entry that only exists in English is a 404, so
      // the language panel can mark it untranslated.
      if (/locale=de/.test(query)) return jsonRes(404, { error: 'Entry not found.' });
      const entry = ENTRIES.find((e) => e.slug === entryMatch[1]);
      return jsonRes(200, { data: clone(entry) });
    }

    return jsonRes(200, { data: [] });
  });

  const openDraftEditor = async () => {
    const wrapper = mount(Collections);
    await flushPromises();
    await wrapper.findAll('.collection-card')[0].trigger('click');
    await flushPromises();
    await wrapper.findAll('.btn-edit')[1].trigger('click'); // draft-post
    await flushPromises();
    return wrapper;
  };

  it('shows the language panel when the site has more than one language', async () => {
    global.fetch = deepFetch();
    const wrapper = await openDraftEditor();

    expect(wrapper.find('.langs').exists()).toBe(true);
    // Both configured languages are offered.
    const langButtons = wrapper.findAll('.lang').map((b) => b.text());
    expect(langButtons.join(' ')).toMatch(/en/);
    expect(langButtons.join(' ')).toMatch(/de/);
  });

  it('switching to an untranslated language fetches it and invites creating it', async () => {
    global.fetch = deepFetch();
    const wrapper = await openDraftEditor();

    const german = wrapper.findAll('.lang').find((b) => b.text().includes('de'));
    await german.trigger('click');
    await flushPromises();

    // It requested the German copy of this very entry.
    const askedGerman = global.fetch.mock.calls.some(
      ([u, i]) => (i?.method || 'GET') === 'GET'
        && /\/api\/collections\/blog\/entries\/draft-post\?locale=de/.test(String(u))
    );
    expect(askedGerman).toBe(true);
    // And says the translation does not exist yet.
    expect(wrapper.text()).toContain('not translated');
  });

  it('shows what references the entry', async () => {
    global.fetch = deepFetch();
    const wrapper = await openDraftEditor();

    const panel = wrapper.find('.backrefs');
    expect(panel.exists()).toBe(true);
    expect(panel.text()).toContain('Related Post');
    expect(panel.text()).toContain('related');
  });

  it('mints a preview link and shows it', async () => {
    global.fetch = deepFetch();
    const wrapper = await openDraftEditor();

    const previewButton = wrapper.findAll('.actions button').find((b) => b.text().includes('Preview'));
    expect(previewButton).toBeTruthy();

    await previewButton.trigger('click');
    await flushPromises();

    // It minted a link for this entry...
    const minted = global.fetch.mock.calls.some(
      ([u, i]) => (i?.method || 'GET') === 'POST'
        && /\/api\/collections\/blog\/entries\/draft-post\/preview/.test(String(u))
    );
    expect(minted).toBe(true);
    // ...and surfaced it for copying.
    expect(wrapper.find('.preview-link').exists()).toBe(true);
    expect(wrapper.find('#entry-preview-url').element.value).toContain('/api/collections/blog/preview/draft-post');
  });

  it('lists version history and restores an earlier version', async () => {
    global.fetch = deepFetch();
    const wrapper = await openDraftEditor();

    expect(wrapper.find('.versions').exists()).toBe(true);
    expect(wrapper.findAll('.version')).toHaveLength(2);

    // Restore the older version (index 1): open the in-panel confirmation, then
    // confirm.
    const restoreButtons = wrapper.findAll('.version .btn-sm');
    await restoreButtons[0].trigger('click');
    await flushPromises();
    await wrapper.find('.confirm .btn-sm.primary').trigger('click');
    await flushPromises();

    const restored = global.fetch.mock.calls.some(
      ([u, i]) => (i?.method || 'GET') === 'POST'
        && /\/api\/collections\/blog\/entries\/draft-post\/versions\/v1\/restore/.test(String(u))
    );
    expect(restored).toBe(true);
  });
});

describe('publishing an entry', () => {
  it('calls the publish endpoint for the edited entry', async () => {
    const wrapper = await mountCollections();

    await wrapper.findAll('.collection-card')[0].trigger('click');
    await flushPromises();

    // Edit the draft entry (index 1), which offers a Publish action.
    await wrapper.findAll('.btn-edit')[1].trigger('click');
    await flushPromises();

    expect(wrapper.find('.btn-publish').exists()).toBe(true);
    await wrapper.find('.btn-publish').trigger('click');
    await flushPromises();

    const publishCall = postCalls('/publish')[0];
    expect(publishCall).toBeTruthy();
    expect(String(publishCall[0])).toBe('/api/collections/blog/entries/draft-post/publish');

    // The badge in the editor now reflects the live state returned by publish.
    expect(wrapper.find('.publication-bar .status-badge').text()).toBe('Live');
  });
});
