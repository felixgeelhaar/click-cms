import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import UpdateNotice from './UpdateNotice.vue';

/**
 * The sign-in notice: what it says, who sees it, and what it does not do.
 *
 * Every rule here is about restraint. This appears in front of somebody who
 * came to do something else, so it must not appear when it cannot be acted on,
 * must not interrupt a login when the feed is down, and must not swap the
 * software out from under a working session without being asked.
 */
describe('the update notice', () => {
  const respond = (payload, ok = true) =>
    vi.fn(() => Promise.resolve({ ok, status: ok ? 200 : 500, json: () => Promise.resolve(payload) }));

  const available = {
    data: { hasUpdate: true, currentVersion: '1.4.4', release: { version: '1.4.5', security: false } },
  };

  beforeEach(() => {
    global.fetch = respond({ data: {} });
  });

  const mountWith = (capabilities = ['plugins.install']) =>
    mount(UpdateNotice, { props: { capabilities } });

  it('offers the release when one is waiting', async () => {
    global.fetch = respond(available);

    const wrapper = mountWith();
    await flushPromises();

    expect(wrapper.text()).toContain('1.4.5');
    expect(wrapper.text()).toContain('1.4.4');
    expect(wrapper.find('.btn-primary').exists()).toBe(true);
  });

  it('says nothing when the site is current', async () => {
    global.fetch = respond({ data: { hasUpdate: false, currentVersion: '1.4.5' } });

    const wrapper = mountWith();
    await flushPromises();

    expect(wrapper.find('.notice').exists()).toBe(false);
  });

  /** A banner you cannot act on is noise; the button would 403 anyway. */
  it('is not shown to a user who cannot install', async () => {
    global.fetch = respond(available);

    const wrapper = mountWith(['content.edit.any']);
    await flushPromises();

    expect(wrapper.find('.notice').exists()).toBe(false);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  /**
   * Whether a newer version exists is not worth interrupting a login over. A
   * feed that is down, misconfigured or slow must leave the admin usable.
   */
  it('stays silent when the check fails', async () => {
    global.fetch = vi.fn(() => Promise.reject(new Error('network down')));

    const wrapper = mountWith();
    await flushPromises();

    expect(wrapper.find('.notice').exists()).toBe(false);
  });

  it('marks a security release as one', async () => {
    global.fetch = respond({
      data: { hasUpdate: true, currentVersion: '1.4.4', release: { version: '1.4.6', security: true } },
    });

    const wrapper = mountWith();
    await flushPromises();

    expect(wrapper.text()).toContain('Security release');
  });

  it('installs only when asked, and reports the version afterwards', async () => {
    const calls = [];
    global.fetch = vi.fn((url, init) => {
      calls.push([url, init?.method ?? 'GET']);
      const payload = url === '/api/updates' ? available : { data: { installed: true, version: '1.4.5' } };
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(payload) });
    });

    const wrapper = mountWith();
    await flushPromises();
    // Nothing has been installed by merely showing the notice.
    expect(calls.map(([, method]) => method)).toEqual(['GET']);

    await wrapper.find('.btn-primary').trigger('click');
    await flushPromises();

    expect(calls).toContainEqual(['/api/updates/apply', 'POST']);
    expect(wrapper.text()).toContain('Updated to 1.4.5');
  });

  it('reports a failed install rather than pretending', async () => {
    global.fetch = vi.fn((url) =>
      url === '/api/updates'
        ? Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(available) })
        : Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({ error: 'Disk full' }) }),
    );

    const wrapper = mountWith();
    await flushPromises();
    await wrapper.find('.btn-primary').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Disk full');
    expect(wrapper.text()).not.toContain('Updated to');
  });

  it('can be set aside', async () => {
    global.fetch = respond(available);

    const wrapper = mountWith();
    await flushPromises();
    await wrapper.findAll('button').at(-1).trigger('click');

    expect(wrapper.find('.notice').exists()).toBe(false);
  });
});
