import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Login from './Login.vue';

/**
 * Signing in, including the second step.
 *
 * The screen is the only way into the admin, so a fault here is total. Two
 * things it has to get right:
 *
 * - **The second step must be reachable.** If the login screen does not
 *   understand `twoFactorRequired`, then turning two-factor on locks every
 *   account out of the site permanently, with a correct password and a correct
 *   code and no way to present the second.
 * - **The server's reason must survive.** This screen used to read
 *   `data.error?.message` while every endpoint in this CMS answers with `error`
 *   as a plain string, so a locked-out person saw "Login failed" instead of
 *   "too many failed attempts, try again in 15 minutes" — the one message that
 *   tells them the problem is not their password.
 */
beforeEach(() => { vi.restoreAllMocks(); });

const answer = (body, ok = true, status = 200) =>
  ({ ok, status, json: async () => body });

describe('Login', () => {
  const signIn = async (wrapper) => {
    await wrapper.get('#login-username').setValue('ann');
    await wrapper.get('#login-password').setValue('hunter2');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
  };

  it('signs in when there is no second factor', async () => {
    global.fetch = vi.fn(async () => answer({ data: { success: true, user: { username: 'ann' } } }));
    const wrapper = mount(Login);

    await signIn(wrapper);

    expect(wrapper.emitted('loggedIn')).toBeTruthy();
    expect(wrapper.emitted('loggedIn')[0][0].username).toBe('ann');
  });

  /* ------------------------------------------------------ second step -- */

  it('asks for a code when the account has a second factor', async () => {
    global.fetch = vi.fn(async () => answer({
      data: { success: false, twoFactorRequired: true, csrfToken: 'tok' },
    }));
    const wrapper = mount(Login);

    await signIn(wrapper);

    expect(wrapper.emitted('loggedIn')).toBeFalsy();
    expect(wrapper.find('#login-code').exists()).toBe(true);
    expect(wrapper.find('#login-password').exists()).toBe(false);
  });

  it('completes the sign-in with the code', async () => {
    global.fetch = vi.fn(async (url) => (
      url === '/api/auth/login'
        ? answer({ data: { success: false, twoFactorRequired: true, csrfToken: 'tok' } })
        : answer({ data: { success: true, user: { username: 'ann' } } })
    ));
    const wrapper = mount(Login);

    await signIn(wrapper);
    await wrapper.get('#login-code').setValue('123456');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(global.fetch).toHaveBeenCalledWith('/api/auth/2fa', expect.objectContaining({ method: 'POST' }));
    expect(wrapper.emitted('loggedIn')).toBeTruthy();
  });

  it('keeps asking when the code is wrong', async () => {
    global.fetch = vi.fn(async (url) => (
      url === '/api/auth/login'
        ? answer({ data: { success: false, twoFactorRequired: true, csrfToken: 'tok' } })
        : answer({ error: 'That code is not right.' }, false, 401)
    ));
    const wrapper = mount(Login);

    await signIn(wrapper);
    await wrapper.get('#login-code').setValue('000000');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(wrapper.emitted('loggedIn')).toBeFalsy();
    expect(wrapper.text()).toContain('That code is not right.');
    expect(wrapper.find('#login-code').exists()).toBe(true);
  });

  /**
   * The password is not needed after the first step and should not sit in
   * memory — or in a field a later screenshot or a shoulder catches — for the
   * rest of the session.
   */
  it('drops the password once the code is being asked for', async () => {
    global.fetch = vi.fn(async () => answer({
      data: { success: false, twoFactorRequired: true, csrfToken: 'tok' },
    }));
    const wrapper = mount(Login);

    await signIn(wrapper);
    await wrapper.get('button.btn-text').trigger('click');

    expect(wrapper.get('#login-password').element.value).toBe('');
  });

  /* ---------------------------------------------------------- errors -- */

  it('shows the reason the server gave', async () => {
    global.fetch = vi.fn(async () => answer(
      { error: 'Too many failed attempts. Try again in 15 minute(s).' },
      false,
      429,
    ));
    const wrapper = mount(Login);

    await signIn(wrapper);

    expect(wrapper.text()).toContain('Try again in 15 minute(s)');
  });

  it('falls back to a plain message when the server gave no reason', async () => {
    global.fetch = vi.fn(async () => answer({}, false, 500));
    const wrapper = mount(Login);

    await signIn(wrapper);

    expect(wrapper.text()).toContain('Login failed');
  });
});
