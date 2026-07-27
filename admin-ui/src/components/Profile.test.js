import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Profile from './Profile.vue';

/**
 * The Profile screen, and the difference between saving and saying you saved.
 *
 * `saveProfile` used to be `alert('Profile saved!')` — no request, no write. The
 * button announced success and persisted nothing, so anyone who changed their
 * display name, saw "saved" and closed the tab had been told a falsehood by the
 * software. That is the worst kind of failure in this codebase's own terms: not
 * a visible error, but a confident wrong answer.
 */
const user = { username: 'ann', displayName: 'Ann Editor', email: 'ann@example.test' };

beforeEach(() => { vi.restoreAllMocks(); });

describe('Profile', () => {
  it('actually sends the change to the API', async () => {
    global.fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: {} }) }));
    const wrapper = mount(Profile, { props: { user } });

    await wrapper.get('#profile-display-name').setValue('Ann Smith');
    await wrapper.get('button').trigger('click');
    await flushPromises();

    // Filtered rather than indexed: the screen also asks for the account's
    // two-step sign-in status when it mounts, so the save is no longer the only
    // request it makes. What this test is about is unchanged — exactly one
    // write, carrying the edited values — and asserting "exactly one write"
    // still catches both the original bug (no request at all) and its opposite
    // (the same change sent twice).
    const writes = global.fetch.mock.calls.filter(([url]) => url.startsWith('/api/users/'));
    expect(writes).toHaveLength(1);

    const [url, init] = writes[0];
    expect(url).toBe('/api/users/ann');
    expect(init.method).toBe('PUT');
    expect(JSON.parse(init.body)).toEqual({
      displayName: 'Ann Smith',
      email: 'ann@example.test',
    });
  });

  it('confirms only after the request succeeded', async () => {
    global.fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({}) }));
    const wrapper = mount(Profile, { props: { user } });

    // Nothing claimed before the button is even pressed.
    expect(wrapper.text()).not.toContain('have been saved');

    await wrapper.get('button').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Your details have been saved.');
  });

  /**
   * `/api/users/*` requires ManageUsers, so this is what an editor or author
   * gets. Reporting it as success would recreate the original bug for exactly
   * the people most likely to hit it.
   */
  it('tells an account that may not change these details, rather than claiming success', async () => {
    global.fetch = vi.fn(async () => ({ ok: false, status: 403, json: async () => ({}) }));
    const wrapper = mount(Profile, { props: { user } });

    await wrapper.get('button').trigger('click');
    await flushPromises();

    expect(wrapper.text()).not.toContain('have been saved');
    expect(wrapper.text()).toContain('Ask an administrator');
  });

  it('reports any other failure instead of swallowing it', async () => {
    global.fetch = vi.fn(async () => ({ ok: false, status: 500, json: async () => ({ error: 'Storage is unavailable' }) }));
    const wrapper = mount(Profile, { props: { user } });

    await wrapper.get('button').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Storage is unavailable');
    expect(wrapper.text()).not.toContain('have been saved');
  });

  it('reports a network failure rather than appearing to succeed', async () => {
    global.fetch = vi.fn(async () => { throw new Error('offline'); });
    const wrapper = mount(Profile, { props: { user } });

    await wrapper.get('button').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('offline');
    expect(wrapper.text()).not.toContain('have been saved');
  });
});
