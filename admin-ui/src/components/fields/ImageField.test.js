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

/**
 * The picker used to report every failed request as an empty library, which
 * sent an editor whose library is full off to upload the pictures again.
 */
describe('when the library cannot be loaded', () => {
  it('says so rather than claiming the library is empty', async () => {
    global.fetch = vi.fn(async () => ({
      ok: false,
      status: 401,
      json: async () => ({ status: 401, error: 'Not authenticated' }),
    }));
    const wrapper = mount(ImageField, { props: { field: { label: 'Photo' }, modelValue: '' } });
    await flushPromises();

    const hint = wrapper.get('[data-test="library-failed"]').text();
    expect(hint).toContain('could not be loaded');
    expect(hint).not.toContain('upload images on the Media page');
  });

  it('says the same when the request throws outright', async () => {
    global.fetch = vi.fn(async () => { throw new Error('offline'); });
    const wrapper = mount(ImageField, { props: { field: { label: 'Photo' }, modelValue: '' } });
    await flushPromises();

    expect(wrapper.get('[data-test="library-failed"]').text()).toContain('could not be loaded');
  });

  it('still reports a genuinely empty library as empty', async () => {
    const wrapper = await mountField([], '');

    expect(wrapper.find('[data-test="library-failed"]').exists()).toBe(false);
    expect(wrapper.get('.hint').text()).toContain('upload images on the Media page');
  });

  it('asks for pictures only, so a video never appears as a broken thumbnail', async () => {
    await mountField([media()], '');

    expect(String(global.fetch.mock.calls[0][0])).toContain('kind=image');
  });
});
