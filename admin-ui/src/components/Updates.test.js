import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Updates from './Updates.vue';

/**
 * The updates page is the one place an administrator finds out that the code
 * running their site is out of date, and the one button that changes it. So what
 * is pinned here is that it reports the real server state rather than a hopeful
 * default — the version actually running, an offered release with its severity —
 * and that pressing the button reaches the apply endpoint rather than only
 * looking like it did.
 */

const OK = (data) => ({ ok: true, status: 200, json: async () => ({ data }) });

const respond = ({ status = {}, history = [], apply } = {}) => {
  global.fetch = vi.fn(async (url, init) => {
    if (url === '/api/updates/history') return OK(history);
    if (url === '/api/updates/apply') return apply ?? OK({ installed: true, version: '1.2.0' });
    return OK({
      currentVersion: '1.0.0',
      policy: 'security',
      configured: true,
      feedError: null,
      hasUpdate: false,
      step: 'none',
      reason: 'Already up to date.',
      release: null,
      ...status,
    });
  });
};

const mountUpdates = async (options) => {
  respond(options);
  const wrapper = mount(Updates);
  await flushPromises();
  return wrapper;
};

beforeEach(() => vi.restoreAllMocks());

describe('the updates page', () => {
  it('shows the version actually running and the policy in force', async () => {
    const wrapper = await mountUpdates();

    expect(wrapper.text()).toContain('1.0.0');
    expect(wrapper.text()).toContain('installs security releases automatically');
  });

  it('shows an available update with its step and security badge', async () => {
    const wrapper = await mountUpdates({
      status: {
        hasUpdate: true,
        step: 'minor',
        reason: '',
        release: { version: '1.2.0', security: true, notes: 'Fixes a hole.' },
      },
    });

    expect(wrapper.text()).toContain('1.2.0');
    expect(wrapper.text()).toContain('minor');
    expect(wrapper.text()).toContain('Security');
    expect(wrapper.text()).toContain('Fixes a hole.');
    expect(wrapper.text()).toContain('Install this update');
  });

  it('says the site is current, and offers no install button, when nothing is on offer', async () => {
    const wrapper = await mountUpdates();

    expect(wrapper.text()).toContain('Already up to date.');
    expect(wrapper.text()).not.toContain('Install this update');
  });

  it('installing POSTs to the apply endpoint rather than only changing the UI', async () => {
    const wrapper = await mountUpdates({
      status: {
        hasUpdate: true,
        step: 'patch',
        reason: '',
        release: { version: '1.0.1', security: true, notes: '' },
      },
      apply: OK({ installed: true, version: '1.0.1' }),
    });

    await wrapper.find('.btn-primary').trigger('click');
    await flushPromises();

    const applied = global.fetch.mock.calls.find(([url]) => url === '/api/updates/apply');
    expect(applied, 'the apply endpoint should have been called').toBeTruthy();
    expect(applied[1].method).toBe('POST');
    expect(wrapper.text()).toContain('1.0.1 installed');
  });

  it('checking again asks the server rather than reusing the first answer', async () => {
    const wrapper = await mountUpdates();

    await wrapper.find('.btn-secondary').trigger('click');
    await flushPromises();

    const checked = global.fetch.mock.calls.find(([url]) => url === '/api/updates/check');
    expect(checked, 'the check endpoint should have been called').toBeTruthy();
    expect(checked[1].method).toBe('POST');
  });

  it('renders the update history', async () => {
    const wrapper = await mountUpdates({
      history: [
        { at: '2026-07-01T10:00:00+00:00', from: '1.0.0', to: '1.0.1', ok: true, error: null },
        { at: '2026-06-01T10:00:00+00:00', from: '0.9.0', to: '1.0.0', ok: false, error: 'checksum mismatch' },
      ],
    });

    expect(wrapper.text()).toContain('1.0.0 → 1.0.1');
    expect(wrapper.text()).toContain('Installed');
    expect(wrapper.text()).toContain('Failed');
    expect(wrapper.text()).toContain('checksum mismatch');
  });

  it('tells a non-administrator why the page is empty instead of showing nothing', async () => {
    global.fetch = vi.fn(async () => ({
      ok: false,
      status: 403,
      json: async () => ({ error: 'You do not have permission to update this site.' }),
    }));
    const wrapper = mount(Updates);
    await flushPromises();

    expect(wrapper.text()).toContain('do not have permission');
    expect(wrapper.text()).not.toContain('Check now');
  });

  it('surfaces a feed problem rather than letting it read as up to date', async () => {
    const wrapper = await mountUpdates({
      status: { feedError: 'The update feed signature does not verify, so nothing from it was used.' },
    });

    expect(wrapper.text()).toContain('signature does not verify');
  });
});
