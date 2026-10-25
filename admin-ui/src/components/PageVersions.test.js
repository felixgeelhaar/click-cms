import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PageVersions from './PageVersions.vue';

/**
 * The panel once refused to show history for anything but the default language,
 * because the API addressed versions by slug alone. That was fixed on the
 * server — history is now per translation — and the panel must show whatever
 * versions it is handed regardless of which language is being edited.
 */

const version = (id, reason, title) => ({
  id,
  reason,
  title,
  author: 'ada',
  recordedAt: '2026-07-01T10:00:00+00:00',
});

describe('version history panel', () => {
  it('lists whatever versions it is given, with no language gate', () => {
    const wrapper = mount(PageVersions, {
      props: {
        versions: [version('v2', 'save', 'Kontakt zwei'), version('v1', 'publish', 'Kontakt eins')],
        canRestore: true,
      },
    });

    const items = wrapper.findAll('.version');
    expect(items).toHaveLength(2);
    expect(wrapper.text()).toContain('Kontakt zwei');
    expect(wrapper.text()).not.toContain('only reachable');
  });

  it('marks the newest version as the working copy', () => {
    const wrapper = mount(PageVersions, {
      props: { versions: [version('v2', 'save'), version('v1', 'publish')] },
    });

    expect(wrapper.find('.version-tag.current').text()).toBe('Working copy');
  });

  it('offers no restore control on the working copy itself', () => {
    const wrapper = mount(PageVersions, {
      props: {
        versions: [version('v2', 'save'), version('v1', 'save')],
        canRestore: true,
      },
    });

    // One restore button: on the older version, never on the working copy.
    const restoreButtons = wrapper.findAll('button').filter((b) => b.text() === 'Restore');
    expect(restoreButtons).toHaveLength(1);
  });

  it('hides restore entirely from someone without the capability', () => {
    const wrapper = mount(PageVersions, {
      props: {
        versions: [version('v2', 'save'), version('v1', 'save')],
        canRestore: false,
      },
    });

    expect(wrapper.findAll('button').filter((b) => b.text() === 'Restore')).toHaveLength(0);
  });
});

describe('version comparison', () => {
  it('compares a saved historical snapshot with the current working copy', async () => {
    global.fetch = vi.fn(async () => ({
      ok: true,
      json: async () => ({ data: { document: { title: 'Old title', data: { sections: [] } } } }),
    }));
    const wrapper = mount(PageVersions, {
      props: {
        slug: 'home', locale: 'de',
        workingCopy: { title: 'New title', data: { sections: [] } },
        versions: [version('v2', 'save', 'New title'), version('v1', 'save', 'Old title')],
      },
    });

    await wrapper.findAll('button').find((button) => button.text() === 'Compare').trigger('click');
    await flushPromises();

    expect(global.fetch).toHaveBeenCalledWith('/api/pages/home/versions/v1?locale=de');
    expect(wrapper.find('.version-diff').text()).toContain('title');
    expect(wrapper.find('.version-diff').text()).toContain('Old title');
    expect(wrapper.find('.version-diff').text()).toContain('New title');
  });

  it('shows an actionable error when the snapshot cannot be loaded', async () => {
    global.fetch = vi.fn(async () => ({ ok: false, status: 500, json: async () => ({ error: 'unavailable' }) }));
    const wrapper = mount(PageVersions, {
      props: { slug: 'home', workingCopy: { title: 'current' }, versions: [version('v2', 'save'), version('v1', 'save')] },
    });
    await wrapper.findAll('button').find((button) => button.text() === 'Compare').trigger('click');
    await flushPromises();
    expect(wrapper.find('.version-diff .versions-error').text()).toContain('Could not load this version');
  });
});
