import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Media from './Media.vue';

/**
 * Focal-point editing on the media library.
 *
 * The variant ladder preserves the source aspect ratio, so a layout that crops
 * an image can cut off the subject. The editor marks the point that must stay
 * visible; these cover that the marker turns a click into fractional coordinates
 * and persists them, and that the point can be moved without a mouse.
 */

const item = (overrides = {}) => ({
  id: 'harbour-crane-a1b2c3',
  extension: 'jpg',
  originalName: 'Harbour Crane.jpg',
  width: 2400,
  height: 1600,
  bytes: 204800,
  variants: ['sm', 'md'],
  alt: '',
  focalPoint: { x: 0.5, y: 0.5 },
  objectPosition: '50% 50%',
  urls: { original: '/api/media/file/harbour-crane-a1b2c3.jpg', variants: { sm: { url: '/api/media/file/harbour-crane-a1b2c3-sm.jpg', width: 640 } } },
  srcset: '',
  quality: null,
  ...overrides,
});

/** Answer /api/media with the given items and capabilities with a stub. */
const respondWith = (items) => {
  global.fetch = vi.fn(async (url, options) => {
    if (String(url).includes('/api/media/capabilities')) {
      return { ok: true, status: 200, json: async () => ({ data: { acceptedMimeTypes: [], maxBytes: 0, resizingAvailable: true, variants: [] } }) };
    }
    // The PUT that persists a focal point, or the initial list.
    return { ok: true, status: 200, json: async () => ({ data: options?.method === 'PUT' ? {} : items }) };
  });
};

const mountMedia = async (items) => {
  respondWith(items);
  const wrapper = mount(Media);
  await flushPromises();
  return wrapper;
};

/** The last PUT the component made, as { url, body }. */
const lastPut = () => {
  const calls = global.fetch.mock.calls.filter(([, opts]) => opts?.method === 'PUT');
  const [url, opts] = calls[calls.length - 1];
  return { url: String(url), body: JSON.parse(opts.body) };
};

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('focal point', () => {
  it('turns a click on the preview into fractional coordinates and persists them', async () => {
    const wrapper = await mountMedia([item()]);

    const target = wrapper.get('[data-test="focal-target"]');
    // jsdom computes no layout, so the box the click is measured against is
    // supplied here: a 200×150 preview clicked a quarter across and half down.
    target.element.getBoundingClientRect = () => ({ left: 0, top: 0, width: 200, height: 150 });
    await target.trigger('click', { clientX: 50, clientY: 75 });
    await flushPromises();

    const { url, body } = lastPut();
    expect(url).toContain('/api/media/harbour-crane-a1b2c3');
    expect(body.focalPoint).toEqual({ x: 0.25, y: 0.5 });
  });

  it('moves the point on the preview to where it was clicked', async () => {
    const wrapper = await mountMedia([item()]);

    const target = wrapper.get('[data-test="focal-target"]');
    target.element.getBoundingClientRect = () => ({ left: 0, top: 0, width: 200, height: 150 });
    await target.trigger('click', { clientX: 150, clientY: 30 });

    const marker = wrapper.get('[data-test="focal-marker"]');
    expect(marker.attributes('style')).toContain('left: 75%');
    expect(marker.attributes('style')).toContain('top: 20%');
  });

  it('can be nudged with the keyboard, so it is settable without a mouse', async () => {
    const wrapper = await mountMedia([item()]);

    const target = wrapper.get('[data-test="focal-target"]');
    await target.trigger('keydown', { key: 'ArrowRight' });
    await flushPromises();

    // A single right nudge from centre: 0.5 + one step.
    const { body } = lastPut();
    expect(body.focalPoint.x).toBeGreaterThan(0.5);
    expect(body.focalPoint.y).toBe(0.5);
  });

  it('carries an accessible label naming the image and the current point', async () => {
    const wrapper = await mountMedia([item()]);

    const label = wrapper.get('[data-test="focal-target"]').attributes('aria-label');
    expect(label).toContain('Harbour Crane.jpg');
    expect(label.toLowerCase()).toContain('focal');
  });

  it('never sends a coordinate outside the image, even clicking past the edge', async () => {
    const wrapper = await mountMedia([item()]);

    const target = wrapper.get('[data-test="focal-target"]');
    target.element.getBoundingClientRect = () => ({ left: 0, top: 0, width: 200, height: 150 });
    await target.trigger('click', { clientX: 260, clientY: -20 });
    await flushPromises();

    const { body } = lastPut();
    expect(body.focalPoint.x).toBe(1);
    expect(body.focalPoint.y).toBe(0);
  });
});

/**
 * An SVG is resolution-independent: the server stores it with no width and no
 * variant ladder. The card must read that as a vector rather than rendering an
 * empty "×" where a pixel size would be.
 */
describe('svg items', () => {
  const svgItem = () => item({
    id: 'company-logo-a1b2c3',
    extension: 'svg',
    mimeType: 'image/svg+xml',
    originalName: 'logo.svg',
    width: null,
    height: null,
    variants: [],
    urls: { original: '/api/media/file/company-logo-a1b2c3.svg', variants: {} },
  });

  it('describes a vector instead of blank dimensions', async () => {
    const wrapper = await mountMedia([svgItem()]);

    const text = wrapper.text();
    expect(text).toContain('Scalable vector');
    expect(text).toContain('scales to any size');
    // No stray "null" leaking a missing pixel size, and it is not mislabelled as
    // a raster that simply failed to produce variants.
    expect(text).not.toContain('null');
    expect(text).not.toContain('no resized versions');
  });
});
