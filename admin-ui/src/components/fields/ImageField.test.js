import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ImageField from './ImageField.vue';

/**
 * The image field's preview honours the chosen focal point, so an editor can
 * see the effect of the mark they set in the media library rather than having
 * to publish and look at the live site.
 */

const media = (overrides = {}) => ({
  id: 'harbour-crane-a1b2c3',
  originalName: 'Harbour Crane.jpg',
  width: 2400,
  height: 1600,
  variants: ['sm', 'md'],
  alt: 'A crane',
  focalPoint: { x: 0.25, y: 0.75 },
  objectPosition: '25% 75%',
  urls: { original: '/api/media/file/harbour-crane-a1b2c3.jpg', variants: { sm: { url: '/api/media/file/harbour-crane-a1b2c3-sm.jpg', width: 640 } } },
  quality: null,
  ...overrides,
});

const mountField = async (items, modelValue) => {
  global.fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: items }) }));
  const wrapper = mount(ImageField, {
    props: { field: { label: 'Photo' }, modelValue },
  });
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('focal point in the preview', () => {
  it('applies the object-position from the selected item', async () => {
    const wrapper = await mountField([media()], 'harbour-crane-a1b2c3');

    const preview = wrapper.get('.preview');
    expect(preview.attributes('style')).toContain('object-position: 25% 75%');
  });

  it('falls back to centre when the item carries no object-position', async () => {
    const wrapper = await mountField([media({ objectPosition: undefined, focalPoint: undefined })], 'harbour-crane-a1b2c3');

    const preview = wrapper.get('.preview');
    expect(preview.attributes('style')).toContain('object-position: 50% 50%');
  });
});
