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
 * Search, folder grouping and bulk delete.
 *
 * Filtering is done on the server (GET /api/media?q=&folder=), so these mount the
 * component against a stub that filters like the server would, and assert that
 * the rendered grid reflects the response — the component's job is to send the
 * right query and render what comes back. Bulk delete asserts the outgoing
 * request carries exactly the ticked ids.
 */
describe('search, folders and bulk delete', () => {
  const library = () => [
    item({ id: 'harbour-crane-a1', originalName: 'products/Harbour Crane.jpg' }),
    item({ id: 'city-skyline-b2', originalName: 'products/City Skyline.jpg' }),
    item({ id: 'logo-c3', originalName: 'logo.jpg' }),
  ];

  /**
   * Answer /api/media the way the server does: parse q and folder from the URL,
   * filter the library, and always return the full folder set. Records every
   * request so a test can assert what was sent.
   */
  const serveLibrary = (all) => {
    const calls = [];
    global.fetch = vi.fn(async (url, options) => {
      const u = String(url);
      calls.push({ url: u, options });

      if (u.includes('/api/media/capabilities')) {
        return { ok: true, status: 200, json: async () => ({ data: { acceptedMimeTypes: [], maxBytes: 0, resizingAvailable: true, variants: [] } }) };
      }
      if (u.includes('/api/media/bulk-delete')) {
        const ids = JSON.parse(options.body).ids;
        return { ok: true, status: 200, json: async () => ({ data: { requested: ids.length, deleted: ids.length, results: ids.map((id) => ({ id, deleted: true })) } }) };
      }
      // GET /api/media[?q=&folder=]
      const params = new URL(u, 'http://x').searchParams;
      const q = (params.get('q') ?? '').toLowerCase();
      const folder = params.get('folder');
      const folderOf = (name) => (name.includes('/') ? name.slice(0, name.lastIndexOf('/')) : '');
      const data = all.filter((it) => {
        if (q && !it.originalName.toLowerCase().includes(q)) return false;
        if (folder !== null && folderOf(it.originalName) !== folder) return false;
        return true;
      });
      return { ok: true, status: 200, json: async () => ({ data, folders: ['', 'products'] }) };
    });
    return calls;
  };

  const mountLibrary = async (all) => {
    const calls = serveLibrary(all);
    const wrapper = mount(Media);
    await flushPromises();
    return { wrapper, calls };
  };

  it('filters the rendered list by the search box', async () => {
    vi.useFakeTimers();
    try {
      serveLibrary(library());
      const wrapper = mount(Media);
      // Settle the mounted load. advanceTimersByTimeAsync flushes the microtasks
      // and the setImmediate flushPromises relies on, which are faked here.
      await vi.advanceTimersByTimeAsync(0);

      // Three items to start.
      expect(wrapper.findAll('.card')).toHaveLength(3);

      await wrapper.get('[data-test="media-search"]').setValue('skyline');
      // The search is debounced (250ms); advancing past it fires the reload.
      await vi.advanceTimersByTimeAsync(300);

      expect(wrapper.findAll('.card')).toHaveLength(1);
      expect(wrapper.text()).toContain('City Skyline.jpg');
      expect(wrapper.text()).not.toContain('Harbour Crane.jpg');
    } finally {
      vi.useRealTimers();
    }
  });

  it('sends the chosen folder as a query parameter', async () => {
    const { wrapper, calls } = await mountLibrary(library());

    await wrapper.get('[data-test="media-folder"]').setValue('products');
    await flushPromises();

    const last = calls.filter((c) => c.url.includes('/api/media?')).pop();
    expect(last.url).toContain('folder=products');
    // The two products items remain; the root logo is filtered out.
    expect(wrapper.findAll('.card')).toHaveLength(2);
  });

  it('triggers bulk-delete with exactly the selected ids once confirmed', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const { wrapper, calls } = await mountLibrary(library());

    // Tick the first two cards.
    const boxes = wrapper.findAll('[data-test="select-item"]');
    await boxes[0].setValue(true);
    await boxes[1].setValue(true);

    // The bulk bar appears and reports the count.
    expect(wrapper.get('[data-test="bulk-bar"]').text()).toContain('2 selected');

    await wrapper.get('[data-test="bulk-delete"]').trigger('click');
    await flushPromises();

    expect(confirm).toHaveBeenCalled();
    const del = calls.find((c) => c.url.includes('/api/media/bulk-delete'));
    expect(del).toBeTruthy();
    expect(del.options.method).toBe('POST');
    expect(JSON.parse(del.options.body).ids).toEqual(['harbour-crane-a1', 'city-skyline-b2']);
  });

  it('does not send a request when the confirm is dismissed', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    const { wrapper, calls } = await mountLibrary(library());

    await wrapper.findAll('[data-test="select-item"]')[0].setValue(true);
    await wrapper.get('[data-test="bulk-delete"]').trigger('click');
    await flushPromises();

    expect(calls.some((c) => c.url.includes('/api/media/bulk-delete'))).toBe(false);
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
