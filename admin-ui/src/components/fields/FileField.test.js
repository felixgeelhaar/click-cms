import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import FileField from './FileField.vue';
import { leafComponent } from './leafComponent.js';
import ImageField from './ImageField.vue';
import FieldInput from './FieldInput.vue';

/**
 * A file field is picked from the library, not typed.
 *
 * The behaviour that matters is that an editor never has to know an id: the
 * field asks the server for video, shows what it finds by name, and stores the
 * id itself. Everything here is asserted through the rendered field rather than
 * internals, because the complaint this fixes was about what the editor sees.
 */

const clip = (overrides = {}) => ({
  id: 'workshop-tour-a1b2c3',
  originalName: 'Workshop Tour.mp4',
  extension: 'mp4',
  mimeType: 'video/mp4',
  bytes: 4 * 1024 * 1024,
  ...overrides,
});

const mountField = async (items, modelValue = '') => {
  global.fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: items }) }));
  const wrapper = mount(FileField, {
    props: { field: { label: 'Video', type: 'file' }, modelValue },
  });
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('choosing a file', () => {
  it('asks the server for video only', async () => {
    await mountField([clip()]);

    expect(global.fetch).toHaveBeenCalledWith('/api/media?kind=video');
  });

  it('lists candidates by name rather than by id', async () => {
    const wrapper = await mountField([clip()]);
    await wrapper.get('.picker button').trigger('click');

    const option = wrapper.get('.chooser-item');
    expect(option.text()).toContain('Workshop Tour.mp4');
    expect(option.text()).not.toContain('workshop-tour-a1b2c3');
  });

  it('emits the id when one is picked, so the editor never types it', async () => {
    const wrapper = await mountField([clip()]);
    await wrapper.get('.picker button').trigger('click');
    await wrapper.get('.chooser-item').trigger('click');

    expect(wrapper.emitted('update:modelValue')).toEqual([['workshop-tour-a1b2c3']]);
  });

  it('closes the chooser after a pick', async () => {
    const wrapper = await mountField([clip()]);
    await wrapper.get('.picker button').trigger('click');
    await wrapper.get('.chooser-item').trigger('click');

    expect(wrapper.find('.chooser').exists()).toBe(false);
  });

  it('clears back to nothing', async () => {
    const wrapper = await mountField([clip()], 'workshop-tour-a1b2c3');
    await wrapper.get('[data-test="file-selected"] .btn-sm').trigger('click');

    expect(wrapper.emitted('update:modelValue')).toEqual([['']]);
  });
});

describe('what it says about the chosen file', () => {
  it('shows format and size, not the empty dimensions an image card would', async () => {
    const wrapper = await mountField([clip()], 'workshop-tour-a1b2c3');

    const detail = wrapper.get('.selected-detail').text();
    expect(detail).toContain('MP4');
    expect(detail).toContain('4.0 MB');
    expect(detail).not.toContain('×');
  });

  it('warns when the file is heavy enough to matter on a phone', async () => {
    const big = clip({ bytes: 40 * 1024 * 1024 });
    const wrapper = await mountField([big], big.id);

    expect(wrapper.get('.selected-warning').text()).toContain('large file');
  });

  it('stays quiet about size when the file is reasonable', async () => {
    const wrapper = await mountField([clip()], 'workshop-tour-a1b2c3');

    expect(wrapper.find('.selected-warning').exists()).toBe(false);
  });

  it('recognises a reference the library does not have rather than showing blank', async () => {
    const wrapper = await mountField([clip()], 'pasted-by-hand-999');

    const unknown = wrapper.get('[data-test="file-unknown"]');
    expect(unknown.text()).toContain('pasted-by-hand-999');
    expect(unknown.text()).toContain('not in the media library');
  });

  /**
   * A failed request is not an empty library. Telling somebody to go and upload
   * a film they already uploaded hides the real problem and wastes their time.
   */
  it('says the library could not be loaded when the request throws', async () => {
    global.fetch = vi.fn(async () => {
      throw new Error('offline');
    });
    const wrapper = mount(FileField, {
      props: { field: { label: 'Video', type: 'file' }, modelValue: '' },
    });
    await flushPromises();

    const hint = wrapper.get('[data-test="library-failed"]').text();
    expect(hint).toContain('could not be loaded');
    expect(hint).not.toContain('upload one on the Media page');
  });

  it('says the same when the server answers 401 rather than throwing', async () => {
    global.fetch = vi.fn(async () => ({
      ok: false,
      status: 401,
      json: async () => ({ status: 401, error: 'Not authenticated' }),
    }));
    const wrapper = mount(FileField, {
      props: { field: { label: 'Video', type: 'file' }, modelValue: '' },
    });
    await flushPromises();

    expect(wrapper.get('[data-test="library-failed"]').text()).toContain('could not be loaded');
  });

  it('still reports a genuinely empty library as empty', async () => {
    const wrapper = await mountField([]);

    expect(wrapper.find('[data-test="library-failed"]').exists()).toBe(false);
    expect(wrapper.get('.hint').text()).toContain('upload one on the Media page');
  });
});

/**
 * The mapping lived in three components and `file` was missed by all three,
 * which is the defect that produced the paste-an-id workflow in the first place.
 */
describe('the shared field-to-editor mapping', () => {
  it('gives a file field the picker', () => {
    expect(leafComponent({ type: 'file' })).toBe(FileField);
  });

  it('still gives an image field the image picker', () => {
    expect(leafComponent({ type: 'image' })).toBe(ImageField);
  });

  it('falls back to the plain input for everything else', () => {
    expect(leafComponent({ type: 'text' })).toBe(FieldInput);
    expect(leafComponent({})).toBe(FieldInput);
  });
});
