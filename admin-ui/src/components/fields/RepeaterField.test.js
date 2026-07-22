import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import RepeaterField from './RepeaterField.vue';

/**
 * Rows can be reordered by dragging or by the arrow buttons. Both paths must
 * produce the same array — the plain row objects, reordered — because the
 * emitted value flows straight back to storage. The arrows stay as the keyboard
 * and screen-reader route; drag is only an added pointer affordance.
 */

const field = {
  label: 'Cards',
  fields: [{ name: 'title', type: 'text' }],
};

const mountRows = () =>
  mount(RepeaterField, {
    props: {
      field,
      modelValue: [{ title: 'A' }, { title: 'B' }, { title: 'C' }],
    },
  });

const lastEmit = (wrapper) => {
  const events = wrapper.emitted('update:modelValue');
  return events[events.length - 1][0];
};

describe('RepeaterField reordering', () => {
  it('reorders by dragging a row onto a later position', async () => {
    const wrapper = mountRows();
    const handles = wrapper.findAll('.drag-handle');
    const rows = wrapper.findAll('.repeater-row');

    await handles[0].trigger('dragstart');
    await rows[2].trigger('dragover');
    await rows[2].trigger('drop');

    expect(lastEmit(wrapper)).toEqual([{ title: 'B' }, { title: 'C' }, { title: 'A' }]);
  });

  it('reorders by dragging a row onto an earlier position', async () => {
    const wrapper = mountRows();
    const handles = wrapper.findAll('.drag-handle');
    const rows = wrapper.findAll('.repeater-row');

    await handles[2].trigger('dragstart');
    await rows[0].trigger('dragover');
    await rows[0].trigger('drop');

    expect(lastEmit(wrapper)).toEqual([{ title: 'C' }, { title: 'A' }, { title: 'B' }]);
  });

  it('still reorders with the arrow-button fallback', async () => {
    const wrapper = mountRows();

    await wrapper.get('[aria-label="Move entry 1 down"]').trigger('click');

    expect(lastEmit(wrapper)).toEqual([{ title: 'B' }, { title: 'A' }, { title: 'C' }]);
  });

  it('emits plain row objects with unchanged shape after a drag', async () => {
    const wrapper = mountRows();
    const handles = wrapper.findAll('.drag-handle');
    const rows = wrapper.findAll('.repeater-row');

    await handles[0].trigger('dragstart');
    await rows[1].trigger('dragover');
    await rows[1].trigger('drop');

    const payload = lastEmit(wrapper);
    expect(payload).toHaveLength(3);
    for (const row of payload) {
      expect(Object.keys(row)).toEqual(['title']);
    }
  });

  it('marks the dragged row while a drag is in progress', async () => {
    const wrapper = mountRows();
    const handles = wrapper.findAll('.drag-handle');

    await handles[1].trigger('dragstart');

    const rows = wrapper.findAll('.repeater-row');
    expect(rows[1].attributes('aria-grabbed')).toBe('true');
    expect(rows[0].attributes('aria-grabbed')).toBe('false');
  });
});
