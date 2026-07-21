import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
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
