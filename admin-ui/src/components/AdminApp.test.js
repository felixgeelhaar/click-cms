import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import AdminApp from './AdminApp.vue';
import PageEdit from './PageEdit.vue';
import { draftRecoveryKey } from './page-edit/draftRecovery.js';
import { saveSectionTemplate } from '../lib/sectionTemplates.js';

let wrapper;
beforeEach(() => {
  delete window.__clickCsrfInstalled;
  window.history.replaceState({}, '', '/admin/pages/new?locale=de');
  global.fetch = vi.fn(async (url) => ({ ok: true, json: async () =>
    String(url).endsWith('/auth/check')
      ? { data: { authenticated: true, user: { username: 'admin', capabilities: ['content.view', 'content.create', 'content.edit.any', 'content.delete.any', 'media.view', 'media.upload', 'settings.manage', 'plugins.manage', 'plugins.install', 'users.manage'] } } }
      : { data: [] } }));
});
afterEach(() => { wrapper?.unmount(); vi.restoreAllMocks(); });

it('replaces the creation address with the saved page address under the installation prefix', async () => {
  window.history.replaceState({}, '', '/cms/admin/pages/new?locale=de');
  wrapper = shallowMount(AdminApp);
  await flushPromises();
  const replace = vi.spyOn(window.history, 'replaceState');
  const editor = wrapper.getComponent(PageEdit);
  editor.vm.$emit('replace-route', '/admin/pages/edit/neue-seite?locale=de');
  await flushPromises();
  expect(replace).toHaveBeenCalledWith({ __clickAdminIndex: expect.any(Number) }, '', '/cms/admin/pages/edit/neue-seite?locale=de');
  expect(wrapper.getComponent(PageEdit).props()).toMatchObject({ slug: 'neue-seite', initialLocale: 'de' });
});

it('updates titles on navigation, browser history, and logout', async () => {
  wrapper = shallowMount(AdminApp);
  await flushPromises();
  expect(document.title).toBe('New Page - Click CMS Admin');
  wrapper.getComponent({ name: 'Sidebar' }).vm.$emit('navigate', '/admin/media');
  await flushPromises();
  expect(document.title).toBe('Media - Click CMS Admin');
  window.history.replaceState({}, '', '/admin/settings');
  window.dispatchEvent(new PopStateEvent('popstate'));
  await flushPromises();
  expect(document.title).toBe('Settings - Click CMS Admin');
  await wrapper.get('.text-button').trigger('click');
  await flushPromises();
  expect(document.title).toBe('Sign in - Click CMS Admin');
});

it('clears only the logged-out account page recovery data after logout succeeds', async () => {
  const storageDescriptor = Object.getOwnPropertyDescriptor(window, 'localStorage');
  const ownKey = draftRecoveryKey({ account: 'admin', site: 'site-a', document: 'home', locale: 'en' });
  const otherKey = draftRecoveryKey({ account: 'other', site: 'site-a', document: 'home', locale: 'en' });
  const values = new Map([[ownKey, 'draft'], [otherKey, 'draft']]);
  const storage = {
    get length() { return values.size; },
    key(index) { return [...values.keys()][index] ?? null; },
    getItem(key) { return values.get(key) ?? null; },
    setItem(key, value) { values.set(key, String(value)); },
    removeItem(key) { values.delete(key); },
  };
  Object.defineProperty(window, 'localStorage', { configurable: true, value: storage });
  const site = `${window.location.origin}/site-a`;
  const ownTemplatesKey = `click-cms:section-templates:v2:${encodeURIComponent(site)}:${encodeURIComponent('admin')}`;
  const otherTemplatesKey = `click-cms:section-templates:v2:${encodeURIComponent(site)}:${encodeURIComponent('other')}`;
  saveSectionTemplate('Private starter', [{ type: 'text', values: { body: 'private' } }], { account: 'admin', site });
  saveSectionTemplate('Other starter', [{ type: 'text', values: { body: 'other' } }], { account: 'other', site });
  global.fetch = vi.fn(async (url) => ({ ok: true, json: async () => {
    if (String(url).endsWith('/auth/check')) return { data: { authenticated: true, user: { username: 'admin', capabilities: ['content.view'] } } };
    if (String(url).endsWith('/api/site')) return { data: { id: 'site-a' } };
    return { data: [] };
  } }));
  wrapper = shallowMount(AdminApp);
  await flushPromises();
  await wrapper.get('.text-button').trigger('click');
  await flushPromises();
  expect(values.has(ownKey)).toBe(false);
  expect(values.get(otherKey)).toBe('draft');
  expect(values.has(ownTemplatesKey)).toBe(false);
  expect(values.has(otherTemplatesKey)).toBe(true);
  values.delete(otherTemplatesKey);
  if (storageDescriptor) Object.defineProperty(window, 'localStorage', storageDescriptor);
  else delete window.localStorage;
});

it('cancels in-app navigation and logout when an editor vetoes discarding changes', async () => {
  wrapper = shallowMount(AdminApp);
  await flushPromises();
  const veto = (event) => event.preventDefault();
  window.addEventListener('click:before-navigate', veto);
  wrapper.getComponent({ name: 'Sidebar' }).vm.$emit('navigate', '/admin/media');
  await flushPromises();
  expect(document.title).toBe('New Page - Click CMS Admin');
  const fetchSpy = vi.spyOn(global, 'fetch');
  await wrapper.get('.text-button').trigger('click');
  await flushPromises();
  expect(fetchSpy).not.toHaveBeenCalled();
  window.removeEventListener('click:before-navigate', veto);
});

it('restores cancelled browser back and forward without prompting twice', async () => {
  window.history.replaceState({}, '', '/admin/pages/new');
  wrapper = shallowMount(AdminApp);
  await flushPromises();
  wrapper.getComponent({ name: 'Sidebar' }).vm.$emit('navigate', '/admin/media');
  await flushPromises();
  const veto = vi.fn((event) => event.preventDefault());
  window.addEventListener('click:before-navigate', veto);
  const go = vi.spyOn(window.history, 'go').mockImplementation(() => {});

  window.history.replaceState({ __clickAdminIndex: 0 }, '', '/admin/pages/new');
  window.dispatchEvent(new PopStateEvent('popstate'));
  expect(go).toHaveBeenCalledWith(1);
  expect(veto).toHaveBeenCalledTimes(1);
  window.history.replaceState({ __clickAdminIndex: 1 }, '', '/admin/media');
  window.dispatchEvent(new PopStateEvent('popstate'));
  await flushPromises();
  expect(veto).toHaveBeenCalledTimes(1);
  expect(document.title).toBe('Media - Click CMS Admin');

  window.history.replaceState({ __clickAdminIndex: 2 }, '', '/admin/settings');
  window.dispatchEvent(new PopStateEvent('popstate'));
  expect(go).toHaveBeenCalledWith(-1);
  expect(veto).toHaveBeenCalledTimes(2);
  window.history.replaceState({ __clickAdminIndex: 1 }, '', '/admin/media');
  window.dispatchEvent(new PopStateEvent('popstate'));
  await flushPromises();
  expect(veto).toHaveBeenCalledTimes(2);
  expect(window.location.pathname).toBe('/admin/media');
  window.removeEventListener('click:before-navigate', veto);
});

it('names the forced password screen', async () => {
  global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ data: {
    authenticated: true, user: { username: 'admin', mustChangePassword: true },
  } }) }));
  wrapper = shallowMount(AdminApp);
  await flushPromises();
  expect(document.title).toBe('Choose a password - Click CMS Admin');
});

it('keeps a viewer in a read-only content workspace and hides administration navigation', async () => {
  window.history.replaceState({}, '', '/admin/pages/new');
  global.fetch = vi.fn(async (url) => ({ ok: true, json: async () =>
    String(url).endsWith('/auth/check')
      ? { data: { authenticated: true, user: { username: 'reader', role: 'viewer', capabilities: ['content.view', 'media.view'] } } }
      : { data: [] } }));
  wrapper = shallowMount(AdminApp);
  await flushPromises();
  expect(wrapper.findComponent(PageEdit).exists()).toBe(false);
  expect(wrapper.findComponent({ name: 'Dashboard' }).exists()).toBe(true);
  const sidebar = wrapper.getComponent({ name: 'Sidebar' });
  expect(sidebar.props('capabilities')).toEqual(['content.view', 'media.view']);
});

it('does not open administrator routes by address for a viewer', async () => {
  for (const path of ['/admin/security', '/admin/users', '/admin/settings', '/admin/plugins', '/admin/reviews']) {
    wrapper?.unmount();
    wrapper = undefined;
    window.history.replaceState({}, '', path);
    global.fetch = vi.fn(async (url) => ({ ok: true, json: async () =>
      String(url).endsWith('/auth/check')
        ? { data: { authenticated: true, user: { username: 'reader', role: 'viewer', capabilities: ['content.view', 'media.view'] } } }
        : { data: [] } }));
    wrapper = shallowMount(AdminApp);
    await flushPromises();
    expect(wrapper.findComponent({ name: 'Dashboard' }).exists(), path).toBe(true);
  }
});

it('lets an author open an edit route but not administrator or review inbox routes', async () => {
  window.history.replaceState({}, '', '/admin/pages/edit/my-article');
  global.fetch = vi.fn(async (url) => ({ ok: true, json: async () =>
    String(url).endsWith('/auth/check')
      ? { data: { authenticated: true, user: { username: 'author', role: 'author', capabilities: ['content.view', 'content.create', 'content.edit.own'] } } }
      : { data: [] } }));
  wrapper = shallowMount(AdminApp);
  await flushPromises();
  expect(wrapper.findComponent(PageEdit).exists()).toBe(true);
  wrapper.getComponent({ name: 'Sidebar' }).vm.$emit('navigate', '/admin/security');
  await flushPromises();
  expect(wrapper.findComponent({ name: 'Dashboard' }).exists()).toBe(true);
  wrapper.getComponent({ name: 'Sidebar' }).vm.$emit('navigate', '/admin/reviews');
  await flushPromises();
  expect(wrapper.findComponent({ name: 'Dashboard' }).exists()).toBe(true);
});

it('shows the review inbox to a full content editor and passes decision capability separately', async () => {
  window.history.replaceState({}, '', '/admin/reviews');
  global.fetch = vi.fn(async (url) => ({ ok: true, json: async () =>
    String(url).endsWith('/auth/check')
      ? { data: { authenticated: true, user: { username: 'reviewer', capabilities: ['content.view', 'content.edit.any', 'content.publish'] } } }
      : { data: [] } }));
  wrapper = shallowMount(AdminApp);
  await flushPromises();
  const inbox = wrapper.findComponent({ name: 'ReviewInbox' });
  expect(inbox.exists()).toBe(true);
  expect(inbox.props()).toMatchObject({ currentUsername: 'reviewer', canReview: true });
});
