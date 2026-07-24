import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Themes from './Themes.vue';

/**
 * The theme switcher. Its whole value is that it shows which design the site is
 * actually wearing and can change it, so these pin that the list comes from the
 * server, that the live theme is marked rather than offered again, that pressing
 * Activate really posts the choice, and that an account not allowed to switch is
 * told why instead of being left looking at an unchanged list.
 */

const theme = (id, name, active, extra = {}) => ({
  id,
  name,
  version: '1.0.0',
  description: `${name} description`,
  author: 'Click CMS',
  active,
  stylesheetUrl: `/themes/${id}/theme.css?v=1`,
  ...extra,
});

const withThemes = (activeId, onActivate) => {
  let active = activeId;

  global.fetch = vi.fn(async (url, init) => {
    if (init?.method === 'POST') {
      const sent = JSON.parse(init.body);
      const refusal = onActivate?.(sent);
      if (refusal) return refusal;
      active = sent.id;
      return { ok: true, json: async () => ({ data: { activated: true, active } }) };
    }
    return {
      ok: true,
      json: async () => ({
        data: {
          active,
          themes: [theme('dark', 'Dark', active === 'dark'), theme('default', 'Default', active === 'default')],
        },
      }),
    };
  });
};

const mountThemes = async (activeId, onActivate) => {
  withThemes(activeId, onActivate);
  const wrapper = mount(Themes);
  await flushPromises();
  return wrapper;
};

beforeEach(() => vi.restoreAllMocks());

describe('the theme list', () => {
  it('renders every installed theme with its version, description and author', async () => {
    const wrapper = await mountThemes('default');

    const cards = wrapper.findAll('.theme-card');
    expect(cards).toHaveLength(2);
    expect(wrapper.text()).toContain('Dark');
    expect(wrapper.text()).toContain('Default description');
    expect(wrapper.text()).toContain('v1.0.0');
    expect(wrapper.text()).toContain('Click CMS');
  });

  it('marks the active theme and does not offer to activate it again', async () => {
    const wrapper = await mountThemes('dark');

    const active = wrapper.findAll('.theme-card').filter((c) => c.classes('active'));
    expect(active).toHaveLength(1);
    expect(active[0].text()).toContain('Dark');
    expect(active[0].find('button').exists()).toBe(false);
    expect(wrapper.findAll('button')).toHaveLength(1);
  });

  it('says so plainly when nothing is installed', async () => {
    global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ data: { active: null, themes: [] } }) }));
    const wrapper = mount(Themes);
    await flushPromises();

    expect(wrapper.text()).toContain('No themes are installed');
  });
});

describe('switching theme', () => {
  it('posts the chosen theme to the activate endpoint', async () => {
    const wrapper = await mountThemes('default');

    await wrapper.findAll('.theme-card').find((c) => c.text().includes('Dark')).find('button').trigger('click');
    await flushPromises();

    const post = global.fetch.mock.calls.find(([, init]) => init?.method === 'POST');
    expect(post, 'a POST should have been sent').toBeTruthy();
    expect(post[0]).toBe('/api/themes/activate');
    expect(JSON.parse(post[1].body)).toEqual({ id: 'dark' });
  });

  it('shows the new theme as active once the server has kept it', async () => {
    const wrapper = await mountThemes('default');

    await wrapper.findAll('.theme-card').find((c) => c.text().includes('Dark')).find('button').trigger('click');
    await flushPromises();

    const active = wrapper.findAll('.theme-card').filter((c) => c.classes('active'));
    expect(active[0].text()).toContain('Dark');
  });

  it('explains a refusal instead of pretending the theme changed', async () => {
    // An editor pressing Activate: the server refuses with 403, and its wording
    // is what the person sees.
    const wrapper = await mountThemes('default', () => ({
      ok: false,
      status: 403,
      json: async () => ({ error: 'You do not have permission to change the theme.' }),
    }));

    await wrapper.findAll('.theme-card').find((c) => c.text().includes('Dark')).find('button').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('You do not have permission to change the theme.');
    const active = wrapper.findAll('.theme-card').filter((c) => c.classes('active'));
    expect(active[0].text()).toContain('Default');
  });
});
