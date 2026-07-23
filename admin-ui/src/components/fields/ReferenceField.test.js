import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ReferenceField from './ReferenceField.vue';

/**
 * The reference picker. Its job: fetch the target's items, let the editor pick
 * one by title, and emit the chosen slug (never the title). A value that is not
 * among the fetched options is still shown, so a dangling reference is visible
 * rather than silently cleared.
 */
describe('ReferenceField', () => {
  beforeEach(() => {
    global.fetch = vi.fn(async (url) => {
      const u = String(url);
      if (u.includes('/api/collections/team-member/entries')) {
        return { ok: true, json: async () => ({ data: [
          { slug: 'ada', title: 'Ada Lovelace' },
          { slug: 'grace', title: 'Grace Hopper' },
        ] }) };
      }
      if (u.includes('/api/pages')) {
        return { ok: true, json: async () => ({ data: [
          { slug: 'home', data: { title: 'Home' } },
        ] }) };
      }
      return { ok: true, json: async () => ({ data: [] }) };
    });
  });

  const mountField = (references, modelValue = null) =>
    mount(ReferenceField, { props: { field: { name: 'author', references }, modelValue, inputId: 'f1' } });

  it('loads the target collection entries as options with their titles', async () => {
    const wrapper = mountField('team-member');
    await flushPromises();
    const texts = wrapper.findAll('option').map((o) => o.text());
    expect(texts).toContain('Ada Lovelace');
    expect(texts).toContain('Grace Hopper');
    // The values are slugs, not titles.
    const values = wrapper.findAll('option').map((o) => o.attributes('value'));
    expect(values).toContain('ada');
  });

  it('emits the selected slug', async () => {
    const wrapper = mountField('team-member');
    await flushPromises();
    await wrapper.find('select').setValue('grace');
    expect(wrapper.emitted('update:modelValue')[0]).toEqual(['grace']);
  });

  it('resolves a page reference from the pages endpoint', async () => {
    const wrapper = mountField('page');
    await flushPromises();
    expect(wrapper.findAll('option').map((o) => o.text())).toContain('Home');
  });

  it('still shows a dangling value that is no longer among the options', async () => {
    const wrapper = mountField('team-member', 'deleted-person');
    await flushPromises();
    const texts = wrapper.findAll('option').map((o) => o.text());
    expect(texts.some((t) => t.includes('deleted-person') && t.includes('missing'))).toBe(true);
  });

  const mountMulti = (modelValue = []) =>
    mount(ReferenceField, { props: { field: { name: 'related', references: 'team-member', multiple: true }, modelValue, inputId: 'f2' } });

  it('multiple: renders a chip per selected slug with its title', async () => {
    const wrapper = mountMulti(['ada']);
    await flushPromises();
    const chips = wrapper.findAll('.ref-chip').map((c) => c.text());
    expect(chips.some((t) => t.includes('Ada Lovelace'))).toBe(true);
    // The already-selected item is not offered again in the add dropdown.
    expect(wrapper.findAll('option').map((o) => o.text())).not.toContain('Ada Lovelace');
  });

  it('multiple: adding appends the slug to the array', async () => {
    const wrapper = mountMulti(['ada']);
    await flushPromises();
    await wrapper.find('select').setValue('grace');
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([['ada', 'grace']]);
  });

  it('multiple: removing a chip drops that slug', async () => {
    const wrapper = mountMulti(['ada', 'grace']);
    await flushPromises();
    await wrapper.findAll('.ref-chip-remove')[0].trigger('click');
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([['grace']]);
  });

  it('multiple: moving a chip down reorders the array', async () => {
    const wrapper = mountMulti(['ada', 'grace']);
    await flushPromises();
    // The first chip's "down" arrow is its second move button.
    const firstChipMoves = wrapper.findAll('.ref-chip')[0].findAll('.ref-chip-move');
    await firstChipMoves[1].trigger('click');
    expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([['grace', 'ada']]);
  });

  it('multiple: the first chip cannot move up and the last cannot move down', async () => {
    const wrapper = mountMulti(['ada', 'grace']);
    await flushPromises();
    const chips = wrapper.findAll('.ref-chip');
    // First chip: up disabled.
    expect(chips[0].findAll('.ref-chip-move')[0].attributes('disabled')).toBeDefined();
    // Last chip: down disabled.
    expect(chips[1].findAll('.ref-chip-move')[1].attributes('disabled')).toBeDefined();
  });
});
