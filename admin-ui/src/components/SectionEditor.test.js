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
      templateScope: { account: 'editor', site: 'https://cms.test/primary' },
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

describe('SectionEditor starter templates', () => {
  it('saves and applies a reusable snapshot of the current page sections', async () => {
    global.localStorage = {
      value: null,
      getItem() { return this.value; },
      setItem(key, value) { this.value = value; },
    };
    const wrapper = await mountEditor();
    await wrapper.get('#section-template-name').setValue('Campaign landing');
    await wrapper.get('form.template-row').trigger('submit');
    expect(wrapper.text()).toContain('Saved “Campaign landing”.');

    await wrapper.get('[aria-label="Remove section 1"]').trigger('click');
    await wrapper.get('#section-template-picker').setValue(wrapper.find('#section-template-picker option:nth-child(3)').element.value);
    await wrapper.findAll('button').find((button) => button.text() === 'Replace sections').trigger('click');

    expect(wrapper.text()).toContain('Applied “Campaign landing”.');
    expect(lastEmit(wrapper)).toHaveLength(3);
    expect(lastEmit(wrapper)[0]).toEqual({ type: 'hero', values: { heading: 'One' } });
  });

  it('offers the checked-in shared starter and does not allow deleting it', async () => {
    global.fetch = vi.fn(async (url) => ({
      ok: true, status: 200,
      json: async () => url === '/api/section-presets' ? { data: [{
        id: 'starter-business-site', name: 'Business services site', currentVersion: 1,
        versions: [{ version: 1, updatedAt: 'Source controlled', updatedBy: 'Click CMS' }],
        editable: false, compatible: true,
        sections: [
          { type: 'section-heading', values: { heading: 'Good work, done with care', intro: 'Intro' } },
          { type: 'card-grid', values: { heading: 'How we can help', columns: '3', cards: [] } },
          { type: 'rich-text', values: { heading: 'A little about us', body: '<p>About</p>', width: 'narrow' } },
          { type: 'faq', values: { heading: 'Questions, answered', intro: 'Intro', items: [] } },
          { type: 'call-to-action', values: { heading: 'Ready to talk?', body: 'Talk', buttonLabel: 'Contact us', buttonUrl: '/contact' } },
        ],
      }] } : { data: [] },
    }));
    const wrapper = mount(SectionEditor, { props: { modelValue: [] } });
    await flushPromises();
    const option = wrapper.find('#section-template-picker option:nth-child(2)');
    expect(option.text()).toBe('Business services site · approved starter · v1');
    expect(wrapper.findAll('#section-template-picker option').filter((item) => item.text().includes('Business services site'))).toHaveLength(1);
    await wrapper.get('#section-template-picker').setValue(option.element.value);
    expect(wrapper.get('button[aria-label="Delete starter Business services site"]').attributes('disabled')).toBeDefined();
    await wrapper.findAll('button').find((button) => button.text() === 'Replace sections').trigger('click');
    expect(wrapper.text()).toContain('Applied “Business services site” v1.');
    expect(lastEmit(wrapper).map((section) => section.type)).toEqual([
      'section-heading', 'card-grid', 'rich-text', 'faq', 'call-to-action',
    ]);
  });

  it('adopts a versioned shared preset only after explicit append or replace', async () => {
    global.fetch = vi.fn(async (url) => ({
      ok: true, status: 200,
      json: async () => url === '/api/section-presets'
        ? { data: [{ id: 'preset-1', name: 'Campaign hero', currentVersion: 3, compatible: true, versions: [{ version: 1 }, { version: 2 }, { version: 3 }], sections: [{ type: 'hero', values: { heading: 'Preset heading' } }] }] }
        : { data: [] },
    }));
    const wrapper = mount(SectionEditor, { props: {
      modelValue: [{ type: 'existing', values: { heading: 'Keep me' } }],
      templateScope: { account: 'editor', site: 'https://cms.test/primary' },
    } });
    await flushPromises();
    await wrapper.get('#section-template-picker').setValue('shared:preset-1');
    expect(wrapper.text()).toContain('Version 3');
    await wrapper.get('[data-testid="append-shared-preset"]').trigger('click');
    expect(lastEmit(wrapper)).toEqual([
      { type: 'existing', values: { heading: 'Keep me' } },
      { type: 'hero', values: { heading: 'Preset heading' } },
    ]);
    expect(wrapper.text()).toContain('Adopted a snapshot');
  });

  it('shows when the selected adopted snapshot has a newer server version', async () => {
    let reads = 0;
    global.fetch = vi.fn(async (url) => ({
      ok: true, status: 200,
      json: async () => url === '/api/section-presets'
        ? { data: [{ id: 'preset-1', name: 'Campaign hero', currentVersion: ++reads === 1 ? 1 : 2, compatible: true, versions: [], sections: [{ type: 'hero', values: { heading: `Version ${reads}` } }] }] }
        : { data: [] },
    }));
    const wrapper = mount(SectionEditor, { props: { modelValue: [] } });
    await flushPromises();
    await wrapper.get('#section-template-picker').setValue('shared:preset-1');
    await wrapper.get('[data-testid="replace-shared-preset"]').trigger('click');
    await wrapper.get('[data-testid="refresh-shared-presets"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Select v2 above, then Append or Replace');
  });

  it('previews a retained shared version and explicitly adopts that immutable snapshot', async () => {
    global.fetch = vi.fn(async (url) => {
      if (url === '/api/section-presets') return { ok: true, status: 200, json: async () => ({ data: [{
        id: 'preset-history', name: 'Campaign', currentVersion: 2,
        versions: [{ version: 1, updatedAt: '2025-01-01', updatedBy: 'editor' }, { version: 2, updatedAt: '2025-02-01', updatedBy: 'admin' }],
        sections: [{ type: 'hero', values: { heading: 'Current v2' } }], compatible: true,
      }] }) };
      if (url.endsWith('/versions/1')) return { ok: true, status: 200, json: async () => ({ data: {
        id: 'preset-history', name: 'Campaign', version: 1, compatible: true,
        sections: [{ type: 'hero', values: { heading: 'Historical v1' } }],
      } }) };
      return { ok: true, status: 200, json: async () => ({ data: [] }) };
    });
    const wrapper = mount(SectionEditor, { props: { modelValue: [{ type: 'existing', values: { heading: 'Keep' } }] } });
    await flushPromises();
    await wrapper.get('#section-template-picker').setValue('shared:preset-history');
    await wrapper.get('#shared-preset-version').setValue('1');
    await flushPromises();
    expect(wrapper.text()).toContain('Preview of v1: 1 sections.');
    expect(wrapper.get('[aria-label="Selected preset version preview"]').text()).toContain('hero');
    await wrapper.get('[data-testid="append-shared-preset"]').trigger('click');
    expect(lastEmit(wrapper)).toEqual([
      { type: 'existing', values: { heading: 'Keep' } },
      { type: 'hero', values: { heading: 'Historical v1' } },
    ]);
    expect(wrapper.text()).toContain('Applied “Campaign” v1');
    expect(global.fetch).toHaveBeenCalledWith('/api/section-presets/preset-history/versions/1');
  });

  it('blocks adoption of a retained version that no longer matches the site schema', async () => {
    global.fetch = vi.fn(async (url) => {
      if (url === '/api/section-presets') return { ok: true, status: 200, json: async () => ({ data: [{
        id: 'preset-schema', name: 'Old schema', currentVersion: 2,
        versions: [{ version: 1 }, { version: 2 }], sections: [{ type: 'hero', values: {} }], compatible: true,
      }] }) };
      if (url.endsWith('/versions/1')) return { ok: true, status: 200, json: async () => ({ data: {
        id: 'preset-schema', name: 'Old schema', version: 1, compatible: false,
        compatibilityErrors: { '0.heading': 'Heading is now required.' }, sections: [{ type: 'hero', values: {} }],
      } }) };
      return { ok: true, status: 200, json: async () => ({ data: [] }) };
    });
    const wrapper = mount(SectionEditor, { props: { modelValue: [] } });
    await flushPromises();
    await wrapper.get('#section-template-picker').setValue('shared:preset-schema');
    await wrapper.get('#shared-preset-version').setValue('1');
    await flushPromises();
    expect(wrapper.text()).toContain('cannot be adopted');
    expect(wrapper.get('[data-testid="replace-shared-preset"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-testid="append-shared-preset"]').trigger('click');
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
  });

  it('does not expose shared preset management to ordinary editors', async () => {
    global.fetch = vi.fn(async (url) => ({ ok: true, status: 200, json: async () => url === '/api/section-presets' ? { data: [{ id: 'p1', name: 'Shared', currentVersion: 1, versions: [], sections: [] }] } : { data: [] } }));
    const wrapper = mount(SectionEditor, { props: { modelValue: [] } });
    await flushPromises();
    expect(wrapper.find('[data-testid="create-shared-preset"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="update-shared-preset"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="delete-shared-preset"]').exists()).toBe(false);
  });

  it('lets a preset manager create, version, and delete shared presets with revision preconditions', async () => {
    let preset = null;
    global.fetch = vi.fn(async (url, options = {}) => {
      if (url === '/api/section-types') return { ok: true, status: 200, json: async () => ({ data: [] }) };
      if (url === '/api/section-presets' && options.method === 'POST') {
        const input = JSON.parse(options.body);
        preset = { id: 'preset-managed', name: input.name, currentVersion: 1, sections: input.sections, compatible: true, versions: [{ version: 1 }] };
        return { ok: true, status: 201, json: async () => ({ data: preset }) };
      }
      if (url === '/api/section-presets/preset-managed' && options.method === 'PUT') {
        const input = JSON.parse(options.body);
        expect(input.expectedVersion).toBe(1);
        preset = { ...preset, currentVersion: 2, sections: input.sections, versions: [{ version: 1 }, { version: 2 }] };
        return { ok: true, status: 200, json: async () => ({ data: preset }) };
      }
      if (url === '/api/section-presets/preset-managed' && options.method === 'DELETE') {
        expect(JSON.parse(options.body).expectedVersion).toBe(2);
        preset = null;
        return { ok: true, status: 200, json: async () => ({ data: { deleted: true } }) };
      }
      return { ok: true, status: 200, json: async () => ({ data: preset ? [preset] : [] }) };
    });
    const wrapper = mount(SectionEditor, { props: {
      modelValue: [{ type: 'hero', values: { heading: 'Current page' } }],
      canManagePresets: true,
    } });
    await flushPromises();
    await wrapper.get('#shared-preset-name').setValue('Agency hero');
    await wrapper.findAll('form.template-row').find((form) => form.find('#shared-preset-name').exists()).trigger('submit');
    await flushPromises();
    expect(JSON.parse(global.fetch.mock.calls.find(([, options]) => options?.method === 'POST')[1].body).sections[0].values.heading).toBe('Current page');
    expect(wrapper.find('#section-template-picker').element.value).toBe('shared:preset-managed');
    await wrapper.findAll('form.template-row').find((form) => form.find('#shared-preset-name').exists()).trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Version 2');
    await wrapper.get('[aria-label="Delete starter Agency hero"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Deleted “Agency hero”.');
  });
});


it('starts content first with a short outline and reveals sections with errors', async () => {
  global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ data: [] }) }));
  const wrapper = mount(SectionEditor, { props: { contentFirst: true, modelValue: [
    { type: 'hero', values: { heading: 'One' } }, { type: 'quote', values: { text: 'Two' } },
  ] } });
  await flushPromises();
  expect(wrapper.findAll('.section-body')).toHaveLength(1);
  await wrapper.setProps({ errors: { '1.text': 'Required' } });
  expect(wrapper.findAll('.section-body')).toHaveLength(2);
  vi.spyOn(window, 'confirm').mockReturnValue(false);
  await wrapper.get('[aria-label="Remove section 1"]').trigger('click');
  expect(wrapper.findAll('.section-item')).toHaveLength(2);
  expect(wrapper.emitted('update:modelValue')).toBeUndefined();
});
