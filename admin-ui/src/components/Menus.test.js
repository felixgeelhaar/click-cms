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
    if (u.includes('/api/pages')) return { ok: true, status: 200, json: async () => ({ data: [{ slug: 'home' }, { slug: 'about' }] }) };
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
    const childInputs = wrapper.findAll('.child .text-input');
    await childInputs[0].setValue('Widgets');
    await childInputs[1].setValue('widgets');
    await wrapper.find('form.editor').trigger('submit');
    await flushPromises();

    expect(putBodies[0].items[0].children).toEqual([{ label: 'Widgets', target: 'widgets' }]);
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
});
