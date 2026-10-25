import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PageEdit from './PageEdit.vue';

/**
 * PageEdit is the largest, most stateful admin component and shipped with no
 * tests — which is exactly how the earlier publication/language defects reached
 * the merged branch. These cover the behaviours whose breakage would be silent
 * and costly: the draft/publish safety messaging, the capability gate on the
 * publish control, the publish button's naming of what it will do, per-language
 * loading, and the guarantee that Save is not Publish.
 *
 * Assertions target specific elements (`.pub-headline`, `.btn-publish`,
 * `#page-title`, the notice banner) rather than `wrapper.text()`, because the
 * safety strings recur across the publication banner, the language panel and the
 * notices — a whole-document match would pass against the wrong element, which
 * is the trap Pages.test.js warns about.
 */

/* -------------------------------------------------------- fixtures -- */

const LIVE = { published: true, hasUnpublishedChanges: false, neverPublished: false, publishedAt: '2026-07-01T10:00:00+00:00' };
const PENDING = { published: true, hasUnpublishedChanges: true, neverPublished: false, publishedAt: '2026-07-01T10:00:00+00:00' };
const NEVER = { published: false, hasUnpublishedChanges: false, neverPublished: true, publishedAt: null };

const version = (reason) => ({
  id: `v-${reason}-${Math.random().toString(36).slice(2, 7)}`,
  reason,
  title: 'A title',
  author: 'ada',
  recordedAt: '2026-07-01T10:00:00+00:00',
});

const ok = (body, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => body, text: async () => JSON.stringify(body) });
const notFound = () => ({ ok: false, status: 404, json: async () => ({ error: 'Page not found' }) });

/**
 * A fetch double that routes by method + path, so a single mount can answer the
 * capabilities probe, the site-locale list, the per-locale page reads, the
 * version reads and the section-type list the way the real API would.
 *
 * `pages` is keyed by locale: a present entry is a translation that exists, a
 * missing/undefined entry is a 404 — a language with no working copy of its own,
 * which is the case item 4 turns on.
 */
const installFetch = ({
  capabilities = [],
  locales = ['en'],
  defaultLocale = 'en',
  pages = {},
  versions = {},
  createdSlug = 'home',
  draftPreviewHtml = '<!doctype html><html><body><h1>Unsaved preview</h1></body></html>',
  publishConflict = null,
} = {}) => {
  global.fetch = vi.fn(async (url, init = {}) => {
    const method = (init.method || 'GET').toUpperCase();
    const u = new URL(String(url), 'http://localhost');
    const path = u.pathname;
    const locale = u.searchParams.get('locale') || defaultLocale;

    if (path === '/api/auth/check') {
      return ok({ data: { user: { username: 'editor', capabilities } } });
    }
    if (path === '/api/section-types') {
      return ok({ data: [], warnings: {} });
    }
    if (path === '/api/pages' && method === 'GET') {
      return ok({ data: [], locale: defaultLocale, locales });
    }
    if (path === '/api/pages' && method === 'POST') {
      return ok({ data: { slug: createdSlug } }, 201);
    }

    const versionsMatch = path.match(/^\/api\/pages\/([^/]+)\/versions$/);
    if (versionsMatch && method === 'GET') {
      return ok({ data: versions[locale] ?? [] });
    }

    const actionMatch = path.match(/^\/api\/pages\/([^/]+)\/(publish|unpublish)$/);
    if (actionMatch && method === 'POST') {
      if (publishConflict && actionMatch[2] === 'publish') {
        return {
          ok: false,
          status: 409,
          json: async () => ({ error: publishConflict }),
        };
      }
      const state = pages[locale];
      return ok({ data: { publication: state?.publication ?? null } });
    }

    const previewMatch = path.match(/^\/api\/pages\/([^/]+)\/preview$/);
    if (previewMatch && method === 'POST') {
      return ok({ data: { url: `/preview/token`, expiresAt: 1893456000 } });
    }

    const draftRenderMatch = path.match(/^\/api\/pages\/([^/]+)\/preview\/render$/);
    if (draftRenderMatch && method === 'POST') {
      return { ok: true, status: 200, text: async () => draftPreviewHtml, json: async () => ({}) };
    }

    const pageMatch = path.match(/^\/api\/pages\/([^/]+)$/);
    if (pageMatch && (method === 'GET' || method === 'PUT')) {
      const slug = pageMatch[1];
      if (method === 'PUT') return ok({ data: { slug, revision: 'rev-2' } });

      const state = pages[locale];
      if (!state) return notFound();
      return ok({
        data: {
          key: `page:${locale}:${slug}`,
          slug,
          locale,
          data: { title: state.title, sections: state.sections ?? [] },
          revision: state.revision ?? 'rev-1',
        },
        locale,
        publication: state.publication ?? null,
        availableLocales: Object.keys(pages),
      });
    }

    return ok({ data: [] });
  });
};

const mountEdit = async (scenario, props = {}) => {
  installFetch(scenario);
  const wrapper = mount(PageEdit, { props: { slug: 'home', ...props } });
  await flushPromises();
  await flushPromises();
  return wrapper;
};

const publishButton = (wrapper) => wrapper.find('.btn-publish');
const publishCalls = () =>
  global.fetch.mock.calls.filter(([url, init]) =>
    /\/publish$/.test(new URL(String(url), 'http://localhost').pathname) &&
    (init?.method || 'GET').toUpperCase() === 'POST');

describe('in-memory live draft preview', () => {
  it('renders current unsaved fields in a sandbox without saving them', async () => {
    const wrapper = await mountEdit({
      capabilities: ['content.preview'],
      pages: { en: { title: 'Saved title', sections: [], publication: PENDING } },
      draftPreviewHtml: '<!doctype html><html><body><h1>Unsaved title</h1></body></html>',
    });
    await wrapper.get('#page-title').setValue('Unsaved title');
    const liveButton = wrapper.findAll('button').find((candidate) => candidate.text() === 'Live preview');
    await liveButton.trigger('click');
    await flushPromises();

    const request = global.fetch.mock.calls.find(([url, init]) =>
      new URL(String(url), 'http://localhost').pathname === '/api/pages/home/preview/render'
      && init?.method === 'POST');
    expect(request).toBeTruthy();
    expect(JSON.parse(request[1].body)).toMatchObject({ title: 'Unsaved title', sections: [] });
    expect(global.fetch.mock.calls.some(([url, init]) => new URL(String(url), 'http://localhost').pathname === '/api/pages/home' && init?.method === 'PUT')).toBe(false);
    expect(publishCalls()).toHaveLength(0);
    expect(wrapper.get('iframe').attributes('srcdoc')).toContain('Unsaved title');
    expect(wrapper.get('iframe').attributes('sandbox')).toBe('');
  });
});

beforeEach(() => {
  vi.restoreAllMocks();
  const values = new Map();
  Object.defineProperty(window, 'localStorage', { configurable: true, value: {
    get length() { return values.size; },
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
    clear: () => values.clear(),
  } });
  window.confirm = vi.fn(() => true);
});

/* --------------------------------------- 1. unmissable draft state -- */

describe('surfacing the publication state', () => {
  /**
   * A never-published page is the highest-stakes state: an editor who believes
   * it is live will not publish it, and nobody ever sees it. The messaging must
   * be the full banner, not a badge — so it is asserted on `.pub-headline`, the
   * banner's own sentence, not on the document as a whole.
   */
  it('says plainly that a never-published page cannot be seen by anyone', async () => {
    const wrapper = await mountEdit({
      pages: { en: { title: 'Home', sections: [], publication: NEVER } },
    });

    expect(wrapper.find('.pub-headline').text()).toBe(
      'Not published — no visitor can see this page'
    );
    expect(wrapper.find('.pub-detail').text()).toContain('never been on the public site');
  });

  /**
   * A published page with pending edits is the other silent failure: the editor
   * saves, sees the change on screen, and assumes it is live. The banner has to
   * say the live site is behind.
   */
  it('warns that the live site is behind when there are pending changes', async () => {
    const wrapper = await mountEdit({
      pages: { en: { title: 'Home', sections: [], publication: PENDING } },
    });

    expect(wrapper.find('.pub-headline').text()).toBe(
      'Published — but your latest changes are not live'
    );
    expect(wrapper.find('.pub-detail').text()).toContain('Visitors are still reading');
  });

  /**
   * The other side of the same coin: a clean live page must NOT raise the
   * pending-changes alarm, or the banner cries wolf and stops being read.
   */
  it('does not claim pending changes when the live page is up to date', async () => {
    const wrapper = await mountEdit({
      pages: { en: { title: 'Home', sections: [], publication: LIVE } },
    });

    expect(wrapper.find('.pub-headline').text()).toBe(
      'Published — the public site matches what is here'
    );
    expect(wrapper.find('.pub-detail').text()).not.toContain('not live');
  });
});

/* ------------------------------------- 2. publish respects capability -- */

describe('the publish control and capability', () => {
  /**
   * The server answers 403 without the capability, but that must not be the only
   * guard: drawing a button an author can only ever be refused teaches them the
   * product is broken. Without content.publish there is no publish button at all.
   */
  it('renders no publish button for a user without content.publish', async () => {
    const wrapper = await mountEdit({
      capabilities: [],
      pages: { en: { title: 'Home', sections: [], publication: NEVER } },
    });

    expect(publishButton(wrapper).exists()).toBe(false);
    expect(wrapper.find('.pub-no-permission').exists()).toBe(true);
  });

  it('renders the publish button for a user with content.publish', async () => {
    const wrapper = await mountEdit({
      capabilities: ['content.publish'],
      pages: { en: { title: 'Home', sections: [], publication: NEVER } },
    });

    expect(publishButton(wrapper).exists()).toBe(true);
    expect(wrapper.find('.pub-no-permission').exists()).toBe(false);
  });
});

/* ------------------------------- 3. the publish button names the action -- */

describe('the publish button naming what it will do', () => {
  /**
   * When the count is knowable it belongs on the button: "Publish 2 changes"
   * tells the editor exactly what goes live. The count is the number of saved
   * versions newer than the last publish, so two saves after a publish reads 2.
   */
  it('names the number of pending changes when it is known', async () => {
    const wrapper = await mountEdit({
      capabilities: ['content.publish'],
      pages: { en: { title: 'Home', sections: [], publication: PENDING } },
      versions: { en: [version('save'), version('save'), version('publish')] },
    });

    expect(publishButton(wrapper).text()).toBe('Publish 2 changes');
  });

  /**
   * When there is no publish in history the count is genuinely unknown, and the
   * button must degrade to "Publish changes" rather than invent a number.
   */
  it('degrades to "Publish changes" when the count is unknown', async () => {
    const wrapper = await mountEdit({
      capabilities: ['content.publish'],
      pages: { en: { title: 'Home', sections: [], publication: PENDING } },
      versions: { en: [version('save'), version('save')] },
    });

    expect(publishButton(wrapper).text()).toBe('Publish changes');
  });
});

/* ------------------------------------- 4. language switching loads right -- */

describe('language switching', () => {
  /**
   * Switching to a configured language must re-fetch with that locale and show
   * that language's content — not leave the previous language on screen. The
   * title input is asserted directly, because the German title must be what an
   * editor sees in the field they will edit.
   */
  it('loads the chosen language content and re-fetches with that locale', async () => {
    const wrapper = await mountEdit({
      locales: ['en', 'de'],
      pages: {
        en: { title: 'English home', sections: [], publication: LIVE },
        de: { title: 'Deutsch Startseite', sections: [], publication: LIVE },
      },
    });

    expect(wrapper.find('#page-title').element.value).toBe('English home');

    global.fetch.mockClear();
    await wrapper.vm.switchLocale('de');
    await flushPromises();

    const deRead = global.fetch.mock.calls.find(([url, init]) =>
      (init?.method || 'GET').toUpperCase() === 'GET' &&
      /^\/api\/pages\/home$/.test(new URL(String(url), 'http://localhost').pathname) &&
      new URL(String(url), 'http://localhost').searchParams.get('locale') === 'de');
    expect(deRead, 'the page should be re-read with locale=de').toBeTruthy();

    expect(wrapper.find('#page-title').element.value).toBe('Deutsch Startseite');
  });

  /**
   * An untranslated language is not the same page with blank fields: it is a
   * document that does not exist yet. It must show empty fields and the "no
   * version yet" guidance — never the default language's text, which is the
   * regression that made an editor think their translation had vanished.
   */
  it('shows empty fields and guidance for an untranslated language, not the default text', async () => {
    const wrapper = await mountEdit({
      locales: ['en', 'de'],
      pages: {
        en: { title: 'English home', sections: [], publication: LIVE },
        // no `de` entry -> the signed-in read 404s -> translation missing
      },
    });

    expect(wrapper.find('#page-title').element.value).toBe('English home');

    await wrapper.vm.switchLocale('de');
    await flushPromises();

    // The field is empty, and specifically not carrying the English title over.
    expect(wrapper.find('#page-title').element.value).toBe('');

    const guidance = wrapper.findAll('p.banner.notice').map((p) => p.text());
    expect(guidance.some((t) => /There is no German version of this page yet/.test(t))).toBe(true);
  });
});

/* ---------------------------------------------- 5. save is not publish -- */

describe('saving is not publishing', () => {
  /**
   * Save writes the working copy and nothing more: it PUTs to the page endpoint,
   * never POSTs to /publish, and the confirmation says the work is not yet
   * public. The three are asserted together because the danger is precisely a
   * Save that looks like it went live.
   */
  it('posts to the save endpoint, does not publish, and says it is not yet public', async () => {
    const wrapper = await mountEdit({
      capabilities: ['content.publish'],
      pages: { en: { title: 'Home', sections: [], publication: PENDING } },
    });

    global.fetch.mockClear();
    await wrapper.vm.savePage();
    await flushPromises();

    const savePut = global.fetch.mock.calls.find(([url, init]) =>
      (init?.method || 'GET').toUpperCase() === 'PUT' &&
      /^\/api\/pages\/home$/.test(new URL(String(url), 'http://localhost').pathname));
    expect(savePut, 'save should PUT to the page endpoint').toBeTruthy();

    expect(publishCalls(), 'save must not publish').toHaveLength(0);

    const notice = wrapper.find('p.banner.notice[role="status"]');
    expect(notice.exists()).toBe(true);
    expect(notice.text()).toBe('Saved. This is not on the public site until you publish.');
  });

  it('sends the loaded revision and preserves local edits after a conflict', async () => {
    const wrapper = await mountEdit({
      pages: { en: { title: 'Home', sections: [], revision: 'rev-7', publication: PENDING } },
    });
    await wrapper.get('#page-title').setValue('My local title');
    global.fetch.mockImplementation(async (url, init = {}) => {
      if ((init.method || 'GET').toUpperCase() === 'PUT') return ok({ error: 'This page changed remotely.' }, 409);
      return ok({ data: [], locale: 'en', locales: ['en'] });
    });

    await wrapper.vm.savePage();
    await flushPromises();

    const put = global.fetch.mock.calls.find(([, init]) => init?.method === 'PUT');
    expect(JSON.parse(put[1].body).expectedRevision).toBe('rev-7');
    expect(wrapper.get('#page-title').element.value).toBe('My local title');
    expect(wrapper.get('[role="alert"]').text()).toContain('changed remotely');
    expect(wrapper.get('.btn-reload-conflict').exists()).toBe(true);
  });

  it('compares local edits with the latest server copy after a conflict', async () => {
    const wrapper = await mountEdit({
      pages: { en: { title: 'Home', sections: [], revision: 'rev-7', publication: PENDING } },
    });
    await wrapper.get('#page-title').setValue('My local title');
    global.fetch.mockImplementation(async (url, init = {}) => {
      const path = new URL(String(url), 'http://localhost').pathname;
      if ((init.method || 'GET').toUpperCase() === 'PUT') return ok({ error: 'This page changed remotely.' }, 409);
      if (path === '/api/pages/home') {
        return ok({ data: { slug: 'home', locale: 'en', data: { title: 'Remote title', sections: [] }, revision: 'rev-8' }, publication: PENDING });
      }
      if (path === '/api/pages') return ok({ locale: 'en', locales: ['en'] });
      return ok({ data: [] });
    });
    await wrapper.vm.savePage();
    await wrapper.vm.compareConflict();
    await flushPromises();
    expect(wrapper.find('.draft-comparison').text()).toContain('Remote title');
    expect(wrapper.find('.draft-comparison').text()).toContain('My local title');
  });

  it('applies a non-overlapping merge locally and uses the latest server revision on an explicit save', async () => {
    const wrapper = await mountEdit({
      pages: { en: { title: 'Home', sections: [{ type: 'text', values: { body: 'Base body' } }], revision: 'rev-7', publication: PENDING } },
    });
    await wrapper.get('#page-title').setValue('My local title');
    global.fetch.mockImplementation(async (url, init = {}) => {
      const path = new URL(String(url), 'http://localhost').pathname;
      if ((init.method || 'GET').toUpperCase() === 'PUT') return ok({ error: 'This page changed remotely.' }, 409);
      if (path === '/api/pages/home') {
        return ok({ data: { slug: 'home', locale: 'en', data: { title: 'Home', sections: [{ type: 'text', values: { body: 'Server body' } }] }, revision: 'rev-8' }, publication: PENDING });
      }
      if (path === '/api/pages') return ok({ locale: 'en', locales: ['en'] });
      return ok({ data: [] });
    });
    await wrapper.vm.savePage();
    await wrapper.vm.compareConflict();
    await flushPromises();

    expect(wrapper.findComponent({ name: 'DraftMerge' }).exists()).toBe(true);
    await wrapper.get('[data-testid="apply-merge"]').trigger('click');
    expect(wrapper.get('#page-title').element.value).toBe('My local title');
    expect(wrapper.vm.page.sections[0].values.body).toBe('Server body');
    expect(wrapper.text()).toContain('Review the merged draft and save when ready');
    expect(global.fetch.mock.calls.filter(([, init]) => init?.method === 'PUT')).toHaveLength(1);

    await wrapper.vm.savePage();
    const puts = global.fetch.mock.calls.filter(([, init]) => init?.method === 'PUT');
    expect(JSON.parse(puts[1][1].body)).toMatchObject({ expectedRevision: 'rev-8', title: 'My local title' });
    expect(JSON.parse(puts[1][1].body).sections[0].values.body).toBe('Server body');
    expect(publishCalls()).toHaveLength(0);
  });

  it('ignores a conflict comparison response after changing locale', async () => {
    const wrapper = await mountEdit({
      locales: ['en', 'de'],
      pages: {
        en: { title: 'English', sections: [], revision: 'rev-en', publication: PENDING },
        de: { title: 'Deutsch', sections: [], revision: 'rev-de', publication: PENDING },
      },
    });
    await wrapper.get('#page-title').setValue('Local English');
    let resolveEnglish;
    let deferredUsed = false;
    global.fetch.mockImplementation(async (url, init = {}) => {
      const path = new URL(String(url), 'http://localhost').pathname;
      const requestedLocale = new URL(String(url), 'http://localhost').searchParams.get('locale') || 'en';
      if (path === '/api/pages/home' && requestedLocale === 'en' && !deferredUsed) {
        deferredUsed = true;
        return new Promise((resolve) => { resolveEnglish = resolve; });
      }
      if (path === '/api/pages/home') return ok({ data: { slug: 'home', locale: 'de', data: { title: 'Deutsche saved', sections: [] }, revision: 'rev-de-latest' }, locale: 'de' });
      if (path === '/api/pages' && (init.method || 'GET').toUpperCase() === 'GET') return ok({ locale: 'en', locales: ['en', 'de'] });
      return ok({ data: [] });
    });
    const comparison = wrapper.vm.compareConflict();
    await flushPromises();
    window.confirm = vi.fn(() => true);
    await wrapper.vm.switchLocale('de');
    resolveEnglish(ok({ data: { slug: 'home', locale: 'en', data: { title: 'Remote English', sections: [] }, revision: 'rev-en-latest' }, locale: 'en' }));
    await Promise.all([comparison, flushPromises()]);

    expect(wrapper.get('#page-title').element.value).toBe('Deutsche saved');
    expect(wrapper.findComponent({ name: 'DraftMerge' }).exists()).toBe(false);
  });

  it('blocks saving an existing page when its read has no revision', async () => {
    const wrapper = await mountEdit({ pages: { en: { title: 'Home', sections: [], revision: '', publication: LIVE } } });
    global.fetch.mockClear();
    await wrapper.vm.savePage();
    await flushPromises();
    expect(global.fetch.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(false);
    expect(wrapper.get('[role="alert"]').text()).toContain('Reload this page');
  });

  it('asks before cancelling when there are unsaved edits', async () => {
    const wrapper = await mountEdit({ pages: { en: { title: 'Home', sections: [], publication: LIVE } } });
    await wrapper.get('#page-title').setValue('Unsaved');
    window.confirm = vi.fn(() => false);
    await wrapper.get('.actions .btn-secondary').trigger('click');
    expect(wrapper.emitted('cancel')).toBeUndefined();
  });

  it('tracks unsaved edits for browser reload protection', async () => {
    const wrapper = await mountEdit({ pages: { en: { title: 'Home', sections: [], publication: LIVE } } });
    const event = new Event('beforeunload', { cancelable: true });
    await wrapper.get('#page-title').setValue('Unsaved');
    window.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true);
  });

  it('stores recovery only after opt-in and offers an explicit restore after reload', async () => {
    const scenario = { pages: { en: { title: 'Home', sections: [], publication: LIVE } } };
    const wrapper = await mountEdit(scenario);
    expect(window.localStorage.length).toBe(0);
    await wrapper.get('.recovery-opt-in input').setValue(true);
    await wrapper.get('#page-title').setValue('Recovered title');
    await flushPromises();
    expect(window.localStorage.length).toBe(1);
    wrapper.unmount();

    const reopened = await mountEdit(scenario);
    expect(reopened.find('.recovery-banner').text()).toContain('local recovery copy is available');
    expect(reopened.get('#page-title').element.value).toBe('Home');
    await reopened.get('.recovery-banner .btn-secondary').trigger('click');
    expect(reopened.get('#page-title').element.value).toBe('Recovered title');
    expect(reopened.find('.draft-comparison').exists()).toBe(true);
  });

  it('keeps edits typed during a save marked dirty', async () => {
    let resolveWrite;
    const wrapper = await mountEdit({ pages: { en: { title: 'Home', sections: [], revision: 'rev-1', publication: LIVE } } });
    global.fetch.mockImplementation(async (url, init = {}) => {
      if ((init.method || 'GET').toUpperCase() === 'PUT') {
        return await new Promise((resolve) => { resolveWrite = resolve; });
      }
      const path = new URL(String(url), 'http://localhost').pathname;
      if (path === '/api/pages') return ok({ locale: 'en', locales: ['en'] });
      if (path.endsWith('/versions')) return ok({ data: [] });
      if (path.endsWith('/schedule')) return ok({ data: { publishAt: null, unpublishAt: null } });
      return ok({ data: { slug: 'home', revision: 'rev-1' } });
    });
    const saving = wrapper.vm.savePage();
    await flushPromises();
    await wrapper.get('#page-title').setValue('Typed while saving');
    resolveWrite(ok({ data: { slug: 'home', revision: 'rev-2' } }));
    await saving;
    await flushPromises();
    expect(wrapper.get('#page-title').element.value).toBe('Typed while saving');
    expect(wrapper.vm.dirty).toBe(true);
  });
});

it('replaces the new-page route after the first save and preserves its language', async () => {
  const wrapper = await mountEdit({ locales: ['en', 'de'], createdSlug: 'neue-seite' }, { slug: '', initialLocale: 'de' });
  await wrapper.get('#page-title').setValue('Neue Seite');
  await wrapper.get('#page-slug').setValue('neue-seite');
  await wrapper.vm.savePage();
  await flushPromises();
  expect(wrapper.emitted('replace-route')).toEqual([['/admin/pages/edit/neue-seite?locale=de']]);
  await wrapper.vm.savePage();
  await flushPromises();
  expect(wrapper.emitted('replace-route')).toHaveLength(1);
});

/* ------------------------------------- 6. publish 409 is editorial -- */

describe('publish conflict as editorial state', () => {
  it('shows a 409 publish refusal as a warning banner, not an error', async () => {
    const wrapper = await mountEdit({
      capabilities: ['content.publish'],
      pages: { en: { title: 'Home', sections: [], publication: PENDING } },
      publishConflict: 'This page has an open review that must be resolved first.',
    });

    await wrapper.vm.publishPage();
    await flushPromises();

    const banner = wrapper.find('p.banner.warning[role="alert"]');
    expect(banner.exists()).toBe(true);
    expect(banner.classes()).not.toContain('error');
    expect(banner.text()).toContain('open review');
    expect(banner.text()).toContain('Open the review panel below');
  });
});
