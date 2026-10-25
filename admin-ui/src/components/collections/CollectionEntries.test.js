import { it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import CollectionEntries from './CollectionEntries.vue';

const props = { type: { id: 'blog', label: 'Blog', titleField: 'title' } };
it('reports a failed delete and keeps the entry visible', async () => {
  window.confirm = vi.fn(() => true);
  global.fetch = vi.fn(async (_, init) => init?.method === 'DELETE'
    ? { ok: false, status: 403, json: async () => ({ error: 'Permission denied' }) }
    : { ok: true, json: async () => ({ data: [{ slug: 'hello', title: 'Hello' }] }) });
  const wrapper = mount(CollectionEntries, { props });
  await flushPromises();
  await wrapper.get('.btn-delete').trigger('click');
  await flushPromises();
  expect(wrapper.get('[role="alert"]').text()).toContain('Permission denied');
  expect(wrapper.findAll('.entry-row')).toHaveLength(1);
  expect(global.fetch).toHaveBeenCalledTimes(2);
});
it('offers retry instead of an empty collection when loading fails', async () => {
  global.fetch = vi.fn(async () => ({ ok: false, status: 503 }));
  const wrapper = mount(CollectionEntries, { props });
  await flushPromises();
  expect(wrapper.find('.empty-state').exists()).toBe(false);
  await wrapper.get('.btn-retry').trigger('click');
  await flushPromises();
  expect(global.fetch).toHaveBeenCalledTimes(2);
});
