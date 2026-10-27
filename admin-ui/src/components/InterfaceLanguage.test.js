import { afterEach, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PagePublication from './PagePublication.vue';
import PageLanguages from './PageLanguages.vue';
import Settings from './Settings.vue';
import Sidebar from './Sidebar.vue';
import { messageLanguage } from '../lib/messages.js';

const wrappers = [];
const render = (component, options) => { const wrapper = mount(component, options); wrappers.push(wrapper); return wrapper; };
afterEach(() => { wrappers.forEach(wrapper => wrapper.unmount()); wrappers.length = 0; messageLanguage.value = 'en'; vi.unstubAllGlobals(); });

it('switches publication states and actions without changing their events', async () => {
  messageLanguage.value = 'de';
  const wrapper = render(PagePublication, { props: {
    slug: 'home', canPublish: true, canUnpublish: true, pendingCount: 2,
    publication: { published: true, neverPublished: false, hasUnpublishedChanges: true, publishedAt: '2026-10-20T10:00:00Z' },
  } });
  expect(wrapper.text()).toContain('die neuesten Änderungen sind noch nicht sichtbar');
  expect(wrapper.text()).not.toContain('Visitors are still');
  expect(wrapper.text()).toContain('2 Änderungen veröffentlichen');
  await wrapper.findAll('button').find(button => button.text().includes('2 Änderungen')).trigger('click');
  expect(wrapper.emitted('publish')).toHaveLength(1);
  messageLanguage.value = 'en';
  await flushPromises();
  expect(wrapper.text()).toContain('Publish 2 changes');
});

it('updates language names and statuses while retaining content locale codes', async () => {
  const wrapper = render(PageLanguages, { props: { locales: ['en', 'de'], current: 'en', translations: { en: { exists: true, publication: { published: true } }, de: { exists: false } } } });
  expect(wrapper.text()).toContain('German');
  messageLanguage.value = 'de';
  await flushPromises();
  expect(wrapper.text()).toContain('Deutsch');
  expect(wrapper.text()).toContain('Nicht übersetzt');
  await wrapper.findAll('button').find(button => button.text().includes('Deutsch')).trigger('click');
  expect(wrapper.emitted('select')).toEqual([['de']]);
});

it('translates navigation labels without changing destination URLs or permissions', async () => {
  messageLanguage.value = 'de';
  const wrapper = render(Sidebar, { props: { capabilities: ['content.view', 'media.view', 'settings.manage'] } });
  expect(wrapper.get('a[href="/admin/pages"]').text()).toContain('Seiten');
  expect(wrapper.get('a[href="/admin/settings"]').text()).toContain('Einstellungen');
  expect(wrapper.find('a[href="/admin/users"]').exists()).toBe(false);
});

it('saves authored values unchanged and switches an existing confirmation message', async () => {
  messageLanguage.value = 'de';
  vi.stubGlobal('fetch', vi.fn(async (url, init) => ({ ok: true, json: async () => ({ data: init?.method === 'PUT' ? JSON.parse(init.body) : { siteName: 'Save Changes', headless: false, freeformEditing: true } }) })));
  const wrapper = render(Settings);
  await flushPromises();
  const input = wrapper.get('input[type="text"]');
  expect(input.element.value).toBe('Save Changes');
  await input.setValue('My English title');
  await wrapper.get('form').trigger('submit');
  await flushPromises();
  const request = fetch.mock.calls.find(([, init]) => init?.method === 'PUT');
  expect(JSON.parse(request[1].body)).toEqual({ siteName: 'My English title' });
  expect(wrapper.text()).toContain('Websitename gespeichert');
  expect(wrapper.text()).toContain('My English title');
  messageLanguage.value = 'en';
  await flushPromises();
  expect(wrapper.text()).toContain('Site name saved. Your header now shows “My English title”.');
  expect(input.element.value).toBe('My English title');
});

it('translates both combined PHP findings and the healthy runtime explanation', async () => {
  const { default: SecurityHealth } = await import('./SecurityHealth.vue');
  messageLanguage.value = 'de';
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ data: { checks: [
    { id: 'php-runtime', label: 'PHP runtime and configuration', status: 'attention', detail: 'PHP 8.0 is below the supported minimum of 8.1. display_errors is enabled. expose_php is enabled.' },
    { id: 'php-runtime', label: 'PHP runtime and configuration', status: 'good', detail: 'The runtime meets the PHP 8.1 minimum; display_errors and expose_php are disabled. This check does not evaluate the PHP branch lifecycle.' },
  ] } }) })));
  const wrapper = render(SecurityHealth);
  await flushPromises();
  expect(wrapper.text()).toContain('PHP 8.0 liegt unter');
  expect(wrapper.text()).toContain('display_errors ist aktiviert.');
  expect(wrapper.text()).toContain('Die Laufzeit erfüllt');
  expect(wrapper.text()).not.toContain('is enabled');
});
