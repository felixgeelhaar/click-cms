import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Menus from './Menus.vue';

/**
 * The menu editor.
 *
 * The load-bearing behaviours: the editor saves the whole item list through a
 * single PUT, reordering is preserved into the body it sends, a menu with no
 * items is a valid empty nav, and a target the server rejects (a javascript:
 * link) surfaces the server's message rather than being swallowed. The
 * target-safety rule itself lives and is tested in PHP; here we prove the editor
 * neither strips nor invents items on the way to that check.
 */

const MAIN = {
  id: 'main',
  name: 'Main navigation',
  items: [
    { label: 'Home', target: 'home' },
    { label: 'About', target: 'about' },
    { label: 'Docs', target: 'https://example.com/docs' },
  ],
};

/** Route the mocked fetch: list, one menu, pages for the datalist, and PUT. */
const server = ({ menu = MAIN, putStatus = 200, putError = null } = {}) => {
  const putBodies = [];
  global.fetch = vi.fn(async (url, init = {}) => {
    const u = String(url);
    const method = (init.method || 'GET').toUpperCase();

    if (method === 'PUT' && u.includes('/api/menus/')) {
      putBodies.push(JSON.parse(init.body));
      return {
        ok: putStatus < 400,
        status: putStatus,
        json: async () => (putError ? { error: putError } : { status: putStatus, data: {} }),
      };
    }
    if (u.match(/\/api\/menus\/[^/]+$/)) return { ok: true, status: 200, json: async () => ({ data: menu }) };
    if (u.includes('/api/menus')) return { ok: true, status: 200, json: async () => ({ data: [{ id: menu.id, name: menu.name }] }) };
    if (u.includes('/api/pages')) {
      return {
        ok: true,
        status: 200,
        json: async () => ({
          data: [
            { key: 'page:en:home', slug: 'home', locale: 'en', data: { title: 'Home' } },
            { key: 'page:en:about', slug: 'about', locale: 'en', data: { title: 'About the workshop' } },
          ],
          locale: 'en',
          locales: ['en'],
        }),
      };
    }
    return { ok: true, status: 200, json: async () => ({ data: [] }) };
  });
  return { putBodies };
};

const mountMenus = async (opts) => {
  const ctx = server(opts);
  const wrapper = mount(Menus);
  await flushPromises();
  return { wrapper, ...ctx };
};

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('loading', () => {
  it('shows the selected menu\'s items', async () => {
    const { wrapper } = await mountMenus();
    const labels = wrapper.findAll('.item .field input').map((i) => i.element.value);
    // label + target for each of three rows
    expect(labels).toContain('Home');
    expect(labels).toContain('https://example.com/docs');
  });

  it('offers a default "main" menu when the site has none', async () => {
    global.fetch = vi.fn(async (url) => {
      const u = String(url);
      if (u.match(/\/api\/menus\/[^/]+$/)) return { ok: false, status: 404, json: async () => ({ error: 'Menu not found' }) };
      if (u.includes('/api/menus')) return { ok: true, status: 200, json: async () => ({ data: [] }) };
      return { ok: true, status: 200, json: async () => ({ data: [] }) };
    });
    const wrapper = mount(Menus);
    await flushPromises();
    expect(wrapper.find('#menu-select').element.value).toBe('main');
  });
});

describe('saving the whole list', () => {
  it('sends every item through a single PUT', async () => {
    const { wrapper, putBodies } = await mountMenus();
    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();

    expect(putBodies).toHaveLength(1);
    expect(putBodies[0].items.map((i) => i.label)).toEqual(['Home', 'About', 'Docs']);
    expect(putBodies[0].items[2].target).toBe('https://example.com/docs');
  });

  it('preserves a reorder into the saved body', async () => {
    const { wrapper, putBodies } = await mountMenus();

    // Move the first item ("Home") down one.
    await wrapper.find('[aria-label="Move item 1 down"]').trigger('click');
    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();

    expect(putBodies[0].items.map((i) => i.label)).toEqual(['About', 'Home', 'Docs']);
  });

  it('saves an empty menu as a valid empty nav', async () => {
    const { wrapper, putBodies } = await mountMenus({ menu: { id: 'footer', name: 'Footer', items: [] } });

    expect(wrapper.find('.empty-state').exists()).toBe(true);
    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();

    expect(putBodies[0].items).toEqual([]);
  });

  it('drops a half-typed blank row rather than saving a labelless item', async () => {
    const { wrapper, putBodies } = await mountMenus({ menu: { id: 'main', name: 'Main', items: [{ label: 'Home', target: 'home' }] } });

    await wrapper.find('[aria-label="Add a sub-item under item 1"]'); // sanity: control exists
    // Add a fresh, empty top-level item and save without filling it in.
    wrapper.vm.addItem();
    await flushPromises();
    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();

    expect(putBodies[0].items.map((i) => i.label)).toEqual(['Home']);
  });
});

describe('nesting', () => {
  it('saves a sub-item under its parent', async () => {
    const { wrapper, putBodies } = await mountMenus({
      menu: { id: 'main', name: 'Main', items: [{ label: 'Products', target: 'products' }] },
    });

    await wrapper.find('[aria-label="Add a sub-item under item 1"]').trigger('click');
    await flushPromises();
    // The label is still typed; the target is now picked from the site's pages.
    await wrapper.find('.child .text-input').setValue('Widgets');
    await wrapper.find('.child .link-select').setValue('about');
    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();

    expect(putBodies[0].items[0].children).toEqual([{ label: 'Widgets', target: 'about' }]);
  });
});

describe('picking a target', () => {
  it('lists the site\'s pages by title rather than asking for a slug from memory', async () => {
    const { wrapper } = await mountMenus();
    const options = wrapper.findAll('.item .link-select option').map((o) => o.text());

    expect(options).toContain('About the workshop — /about');
    // The free-text box and its slug-only datalist are gone.
    expect(wrapper.find('datalist#page-slugs').exists()).toBe(false);
  });

  it('saves the bare slug the menu domain accepts, never a path', async () => {
    const { wrapper, putBodies } = await mountMenus();

    await wrapper.findAll('.item .link-select')[0].setValue('about');
    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();

    // MenuItem::classify() rejects "/about" outright, so a path here throws on
    // save. This is the one value in the two contexts that must not be a path.
    expect(putBodies[0].items[0].target).toBe('about');
    for (const item of putBodies[0].items) {
      expect(item.target).not.toMatch(/^\//);
      expect(item.target).toMatch(/^(?:[a-z0-9][a-z0-9-]*(?:\/[a-z0-9][a-z0-9-]*)?|https?:\/\/\S+)$/);
    }
  });

  it('keeps an external URL through a round trip', async () => {
    const { wrapper, putBodies } = await mountMenus();

    const external = wrapper.findAll('.item .link-url');
    expect(external).toHaveLength(1);
    expect(external[0].element.value).toBe('https://example.com/docs');

    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();
    expect(putBodies[0].items[2].target).toBe('https://example.com/docs');
  });

  it('warns about a target that names no page, and saves it unchanged anyway', async () => {
    const { wrapper, putBodies } = await mountMenus({
      menu: { id: 'main', name: 'Main', items: [{ label: 'Old', target: 'renamed-away' }] },
    });

    const warning = wrapper.find('.item .link-warning');
    expect(warning.exists()).toBe(true);
    expect(warning.text()).toContain('no longer exists');

    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();

    // Opening the screen must not destroy content, so the broken target is still
    // exactly what it was — visible, flagged, and the editor's to fix.
    expect(putBodies[0].items).toEqual([{ label: 'Old', target: 'renamed-away' }]);
  });
});

describe('a rejected target', () => {
  it('surfaces the server error rather than swallowing it', async () => {
    const { wrapper } = await mountMenus({ putStatus: 400, putError: 'Menu target "javascript:alert(1)" is not an allowed link.' });

    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();

    const banner = wrapper.find('.banner.error');
    expect(banner.exists()).toBe(true);
    expect(banner.text()).toContain('not an allowed link');
  });

  /**
   * A menu is per language now, so the editor has to say which one it is
   * editing — otherwise translating the navigation means editing the same
   * document twice and losing the first attempt.
   */
  it('reads and writes the language being edited', async () => {
    const calls = [];
    global.fetch = vi.fn((url, init) => {
      calls.push(`${init?.method ?? 'GET'} ${url}`);
      const body = url.startsWith('/api/menus/')
        ? { data: { id: 'main', name: 'Main', items: [] } }
        : url === '/api/menus'
          ? { data: [{ id: 'main', name: 'Main' }] }
          : { data: [], locale: 'de', locales: ['de', 'en'] };
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
    });

    const wrapper = mount(Menus);
    await flushPromises();

    const picker = wrapper.find('#menu-locale');
    expect(picker.exists()).toBe(true);

    await picker.setValue('en');
    await picker.trigger('change');
    await flushPromises();

    expect(calls.some((c) => c.includes('/api/menus/main?locale=en'))).toBe(true);
    // The default language keeps the plain URL a single-language site sends.
    expect(calls.some((c) => c === 'GET /api/menus/main')).toBe(true);
  });
});
