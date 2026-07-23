import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Settings from './Settings.vue';

/**
 * The headless switch. Its whole value is that it reflects and changes the real
 * server state, so these pin that the switch reads the current mode and that
 * flipping it PUTs the new value rather than only changing the UI.
 */

const withSettings = (headless) => {
  global.fetch = vi.fn(async (url, init) => {
    if (init?.method === 'PUT') {
      const sent = JSON.parse(init.body);
      return { ok: true, json: async () => ({ data: { headless: sent.headless } }) };
    }
    return { ok: true, json: async () => ({ data: { headless } }) };
  });
};

const mountSettings = async (headless) => {
  withSettings(headless);
  const wrapper = mount(Settings);
  await flushPromises();
  return wrapper;
};

beforeEach(() => vi.restoreAllMocks());

describe('the headless switch', () => {
  it('shows the switch off when the site renders its own pages', async () => {
    const wrapper = await mountSettings(false);

    expect(wrapper.find('input[type="checkbox"]').element.checked).toBe(false);
    expect(wrapper.text()).toContain('The public site is on');
  });

  it('shows the switch on when the instance is headless', async () => {
    const wrapper = await mountSettings(true);

    expect(wrapper.find('input[type="checkbox"]').element.checked).toBe(true);
    expect(wrapper.text()).toContain('The public site is off');
  });

  it('turning it on PUTs headless:true, not just a UI change', async () => {
    const wrapper = await mountSettings(false);

    await wrapper.find('input[type="checkbox"]').setValue(true);
    await flushPromises();

    const put = global.fetch.mock.calls.find(([, init]) => init?.method === 'PUT');
    expect(put, 'a PUT should have been sent').toBeTruthy();
    expect(JSON.parse(put[1].body)).toEqual({ headless: true });
    expect(wrapper.text()).toContain('Headless mode is on');
  });

  it('reverts the switch to the server state when a save is refused', async () => {
    // Load as off, then a PUT is refused (e.g. a non-admin). The switch must not
    // stay visually on when the server did not accept the change.
    global.fetch = vi.fn(async (url, init) => {
      if (init?.method === 'PUT') {
        return { ok: false, status: 403, json: async () => ({ error: 'nope' }) };
      }
      return { ok: true, json: async () => ({ data: { headless: false } }) };
    });
    const wrapper = mount(Settings);
    await flushPromises();

    await wrapper.find('input[type="checkbox"]').setValue(true);
    await flushPromises();

    expect(wrapper.find('input[type="checkbox"]').element.checked).toBe(false);
    expect(wrapper.text()).toContain('nope');
  });
});
