import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Settings from './Settings.vue';

/**
 * The headless switch and the free-form editing switch. Their whole value is
 * that they reflect and change the real server state, so these pin that each
 * switch reads the current mode and that flipping it PUTs the new value rather
 * than only changing the UI.
 */

const withSettings = (headless, freeformEditing = true) => {
  let state = { headless, siteName: '', freeformEditing };
  global.fetch = vi.fn(async (url, init) => {
    if (init?.method === 'PUT') {
      const sent = JSON.parse(init.body);
      state = { ...state, ...sent };
      return { ok: true, json: async () => ({ data: { ...state } }) };
    }
    return { ok: true, json: async () => ({ data: { ...state } }) };
  });
};

const mountSettings = async (headless, freeformEditing = true) => {
  withSettings(headless, freeformEditing);
  const wrapper = mount(Settings);
  await flushPromises();
  return wrapper;
};

beforeEach(() => vi.restoreAllMocks());

describe('the headless switch', () => {
  it('shows the switch off when the site renders its own pages', async () => {
    const wrapper = await mountSettings(false);

    expect(wrapper.findAll('input[type="checkbox"]')[0].element.checked).toBe(false);
    expect(wrapper.text()).toContain('The public site is on');
  });

  it('shows the switch on when the instance is headless', async () => {
    const wrapper = await mountSettings(true);

    expect(wrapper.findAll('input[type="checkbox"]')[0].element.checked).toBe(true);
    expect(wrapper.text()).toContain('The public site is off');
  });

  it('turning it on PUTs headless:true, not just a UI change', async () => {
    const wrapper = await mountSettings(false);

    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);
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
      return { ok: true, json: async () => ({ data: { headless: false, freeformEditing: true } }) };
    });
    const wrapper = mount(Settings);
    await flushPromises();

    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);
    await flushPromises();

    expect(wrapper.findAll('input[type="checkbox"]')[0].element.checked).toBe(false);
    expect(wrapper.text()).toContain('nope');
  });
});

describe('free-form editing', () => {
  it('shows free-form on by default', async () => {
    const wrapper = await mountSettings(false, true);

    expect(wrapper.findAll('input[type="checkbox"]')[1].element.checked).toBe(true);
    expect(wrapper.text()).toContain('Free-form editing is on');
  });

  it('turning it off PUTs freeformEditing:false', async () => {
    const wrapper = await mountSettings(false, true);

    await wrapper.findAll('input[type="checkbox"]')[1].setValue(false);
    await flushPromises();

    const put = global.fetch.mock.calls.find(([, init]) => {
      if (init?.method !== 'PUT') return false;
      return JSON.parse(init.body).freeformEditing === false;
    });
    expect(put, 'a freeformEditing PUT should have been sent').toBeTruthy();
    expect(wrapper.text()).toContain('Free-form editing is off');
  });
});
