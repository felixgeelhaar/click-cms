import { beforeEach, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import CollectionEntryEdit from './CollectionEntryEdit.vue';

const type = {
  id: 'posts', label: 'Posts', titleField: 'title',
  fields: [{ name: 'title', type: 'text', label: 'Title' }],
};
const ok = (data, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => data });

const mountEditor = async ({ revision = 'rev-1' } = {}) => {
  global.fetch = vi.fn(async (url, init = {}) => {
    const path = new URL(String(url), 'http://localhost').pathname;
    const method = (init.method || 'GET').toUpperCase();
    if (path === '/api/auth/check') return ok({ data: { user: { capabilities: [] } } });
    if (path === '/api/pages') return ok({ locale: 'en', locales: ['en'] });
    if (path.endsWith('/versions')) return ok({ data: [] });
    if (path.endsWith('/backreferences')) return ok({ data: [] });
    if (path === '/api/collections/posts/entries/hello' && method === 'GET') {
      return ok({ data: { slug: 'hello', data: { title: 'Hello' }, revision } });
    }
    if (method === 'PUT') return ok({ error: 'This entry changed remotely.' }, 409);
    return ok({ data: [] });
  });
  const wrapper = mount(CollectionEntryEdit, { props: { type, slug: 'hello' } });
  await flushPromises();
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  vi.restoreAllMocks();
  window.confirm = vi.fn(() => true);
});

it('sends the loaded revision and retains local values after a 409 conflict', async () => {
  const wrapper = await mountEditor({ revision: 'rev-8' });
  await wrapper.get('input[type="text"]').setValue('Local edit');
  await wrapper.get('.actions .btn-primary').trigger('click');
  await flushPromises();
  const put = global.fetch.mock.calls.find(([, init]) => init?.method === 'PUT');
  expect(JSON.parse(put[1].body).expectedRevision).toBe('rev-8');
  expect(wrapper.get('input[type="text"]').element.value).toBe('Local edit');
  expect(wrapper.get('.btn-reload-conflict').exists()).toBe(true);
});

it('does not save an existing entry without a revision token', async () => {
  const wrapper = await mountEditor({ revision: '' });
  global.fetch.mockClear();
  await wrapper.get('.actions .btn-primary').trigger('click');
  await flushPromises();
  expect(global.fetch.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(false);
  expect(wrapper.get('[role="alert"]').text()).toContain('Reload this entry');
});

it('asks before cancelling an edited entry', async () => {
  const wrapper = await mountEditor();
  await wrapper.get('input[type="text"]').setValue('Unsaved');
  window.confirm = vi.fn(() => false);
  await wrapper.get('.actions .btn-secondary').trigger('click');
  expect(wrapper.emitted('cancel')).toBeUndefined();
});

it('protects unsaved collection values on browser reload', async () => {
  const wrapper = await mountEditor();
  await wrapper.get('input[type="text"]').setValue('Unsaved');
  const event = new Event('beforeunload', { cancelable: true });
  window.dispatchEvent(event);
  expect(event.defaultPrevented).toBe(true);
});

it('does not replace values typed while a save is in flight', async () => {
  let resolveWrite;
  const wrapper = await mountEditor();
  global.fetch.mockImplementation(async (url, init = {}) => {
    const path = new URL(String(url), 'http://localhost').pathname;
    const method = (init.method || 'GET').toUpperCase();
    if (method === 'PUT') return await new Promise((resolve) => { resolveWrite = resolve; });
    if (path === '/api/pages') return ok({ locale: 'en', locales: ['en'] });
    if (path.endsWith('/versions') || path.endsWith('/backreferences')) return ok({ data: [] });
    return ok({ data: { slug: 'hello', data: { title: 'Hello' }, revision: 'rev-2' } });
  });
  const saving = wrapper.get('.actions .btn-primary').trigger('click');
  await flushPromises();
  await wrapper.get('input[type="text"]').setValue('Typed during save');
  resolveWrite(ok({ data: { slug: 'hello', data: { title: 'Hello' }, revision: 'rev-2' } }));
  await saving;
  await flushPromises();
  expect(wrapper.get('input[type="text"]').element.value).toBe('Typed during save');
  expect(wrapper.vm.dirty).toBe(true);
});
