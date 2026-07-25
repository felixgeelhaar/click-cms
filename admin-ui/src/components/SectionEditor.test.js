import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import SectionEditor from './SectionEditor.vue';

/**
 * Sections reorder by drag or by the arrow buttons, and either way the emitted
 * payload is the same { type, values } list — the UI-only keys the editor keeps
 * (_uid, _collapsed) must never leak into what is stored. The arrow buttons stay
 * as the accessible fallback.
 */

const mountEditor = async () => {
  global.fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data: [] }) }));
  const wrapper = mount(SectionEditor, {
    props: {
      modelValue: [
        { type: 'hero', values: { heading: 'One' } },
        { type: 'cards', values: { heading: 'Two' } },
        { type: 'quote', values: { heading: 'Three' } },
      ],
    },
  });
  await flushPromises();
  return wrapper;
};

const lastEmit = (wrapper) => {
  const events = wrapper.emitted('update:modelValue');
  return events[events.length - 1][0];
};

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('SectionEditor reordering', () => {
  it('reorders by dragging a section onto a later position', async () => {
    const wrapper = await mountEditor();
    const handles = wrapper.findAll('.drag-handle');
    const items = wrapper.findAll('.section-item');

    await handles[0].trigger('dragstart');
    await items[2].trigger('dragover');
    await items[2].trigger('drop');

    expect(lastEmit(wrapper)).toEqual([
      { type: 'cards', values: { heading: 'Two' } },
      { type: 'quote', values: { heading: 'Three' } },
      { type: 'hero', values: { heading: 'One' } },
    ]);
  });

  it('still reorders with the arrow-button fallback', async () => {
    const wrapper = await mountEditor();

    await wrapper.get('[aria-label="Move section 1 down"]').trigger('click');

    expect(lastEmit(wrapper)).toEqual([
      { type: 'cards', values: { heading: 'Two' } },
      { type: 'hero', values: { heading: 'One' } },
      { type: 'quote', values: { heading: 'Three' } },
    ]);
  });

  it('emits only { type, values } — no UI-only keys leak after a drag', async () => {
    const wrapper = await mountEditor();
    const handles = wrapper.findAll('.drag-handle');
    const items = wrapper.findAll('.section-item');

    await handles[2].trigger('dragstart');
    await items[0].trigger('dragover');
    await items[0].trigger('drop');

    const payload = lastEmit(wrapper);
    expect(payload).toHaveLength(3);
    for (const section of payload) {
      expect(Object.keys(section).sort()).toEqual(['type', 'values']);
    }
    expect(payload[0]).toEqual({ type: 'quote', values: { heading: 'Three' } });
  });

  it('marks the dragged section while a drag is in progress', async () => {
    const wrapper = await mountEditor();
    const handles = wrapper.findAll('.drag-handle');

    await handles[1].trigger('dragstart');

    const items = wrapper.findAll('.section-item');
    expect(items[1].attributes('aria-grabbed')).toBe('true');
    expect(items[0].attributes('aria-grabbed')).toBe('false');
  });
});

/**
 * What an editor is told when the section designs could not be fetched.
 *
 * `typeFor()` returns null for a type that was genuinely removed *and* for one
 * the app simply failed to load. Reporting the second as the first meant a
 * failed request made every section on the page announce that its design "is no
 * longer declared", with the stored values dumped underneath as raw JSON. The
 * page was fine; only a fetch had failed. Same fault as the publication banner
 * claiming an unsaved page — an unknown answer stated as a definite one.
 */
describe('SectionEditor when the design catalogue cannot be loaded', () => {
  const mountWithFailedFetch = async () => {
    global.fetch = vi.fn(async () => ({ ok: false, status: 401, json: async () => ({}) }));
    const wrapper = mount(SectionEditor, {
      props: { modelValue: [{ type: 'media-text', values: { heading: 'Real content' } }] },
    });
    await flushPromises();
    return wrapper;
  };

  it('does not accuse the section of having a removed design', async () => {
    const wrapper = await mountWithFailedFetch();

    expect(wrapper.text()).not.toContain('no longer declared');
    expect(wrapper.find('.section-missing').exists()).toBe(false);
  });

  it('says the fields cannot be shown, and that the content is safe', async () => {
    const wrapper = await mountWithFailedFetch();

    expect(wrapper.find('.section-unavailable').exists()).toBe(true);
    expect(wrapper.text()).toContain('Your content is safe');
  });

  it('does not dump the stored values as JSON at the editor', async () => {
    const wrapper = await mountWithFailedFetch();

    expect(wrapper.find('.orphan-values').exists()).toBe(false);
  });

  /**
   * The genuine case must still work: once the catalogue is known and the type
   * really is absent from it, say so and keep the values visible so removing a
   * definition cannot silently destroy content.
   */
  it('still reports a genuinely removed design once the catalogue is known', async () => {
    global.fetch = vi.fn(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ data: [{ id: 'rich-text', label: 'Rich text', fields: [] }] }),
    }));
    const wrapper = mount(SectionEditor, {
      props: { modelValue: [{ type: 'deleted-design', values: { heading: 'Kept' } }] },
    });
    await flushPromises();

    expect(wrapper.find('.section-missing').exists()).toBe(true);
    expect(wrapper.text()).toContain('no longer declared');
    expect(wrapper.find('.orphan-values').exists()).toBe(true);
    expect(wrapper.text()).toContain('Kept');
  });
});
