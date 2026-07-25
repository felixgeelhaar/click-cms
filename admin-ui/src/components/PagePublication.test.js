import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PagePublication from './PagePublication.vue';

/**
 * The publication banner, and the difference between "no" and "not yet".
 *
 * An existing page arrives with `publication` null and fills in when the API
 * answers. The banner used to read that null as "not saved", so opening a page
 * that was saved, published and live flashed a red strip saying *this page has
 * not been saved yet* — beside a note telling an administrator their account
 * could not publish. Two false statements, in an `aria-live` region that reads
 * them out.
 *
 * It is the same fault this codebase keeps removing: an unknown answer reported
 * as a definite one. These tests hold the distinction in place.
 */
describe('PagePublication', () => {
  const live = {
    published: true,
    hasUnpublishedChanges: false,
    neverPublished: false,
    publishedAt: '2026-07-25T10:00:00+00:00',
  };

  const mountWith = (props) => mount(PagePublication, {
    props: { slug: 'home', canPublish: true, canUnpublish: true, ...props },
  });

  /* ------------------------------------------------- before the answer -- */

  it('claims nothing about publication while the answer is still coming', () => {
    const text = mountWith({ publication: null }).text();

    expect(text).not.toContain('has not been saved yet');
    expect(text).not.toContain('Not published');
    expect(text).not.toContain('Taken down');
    expect(text).toContain('Checking whether this page is live');
  });

  /**
   * The permission half of the same bug: `canPublish` defaults to false, so the
   * fallback branch fired before permissions were known and told an
   * administrator they could not publish.
   */
  it('does not tell anyone they lack permission before permissions are known', () => {
    const wrapper = mount(PagePublication, {
      props: { publication: null, slug: 'home' }, // no capabilities yet
    });

    expect(wrapper.text()).not.toContain('cannot publish');
  });

  it('is marked busy so assistive technology does not read a placeholder as fact', () => {
    const section = mountWith({ publication: null }).get('section');

    expect(section.attributes('aria-busy')).toBe('true');
    expect(section.classes()).toContain('loading');
    // Not the alarming one.
    expect(section.classes()).not.toContain('unsaved');
  });

  /* -------------------------------------------------- once it is known -- */

  it('reports a live page as live', () => {
    const wrapper = mountWith({ publication: live });

    expect(wrapper.text()).toContain('Published — the public site matches what is here');
    expect(wrapper.get('section').attributes('aria-busy')).toBe('false');
    expect(wrapper.get('section').classes()).toContain('live');
  });

  it('still says a genuinely unsaved new page is unsaved', () => {
    // A new page has no publication and never will until it is saved, so this
    // must not be swallowed by the loading state.
    const wrapper = mountWith({ publication: null, isNew: true });

    expect(wrapper.text()).toContain('has not been saved yet');
    expect(wrapper.get('section').attributes('aria-busy')).toBe('false');
  });

  it('still says a taken-down page is down', () => {
    const wrapper = mountWith({
      publication: { published: false, hasUnpublishedChanges: false, neverPublished: false },
    });

    expect(wrapper.text()).toContain('Taken down');
  });

  it('still warns when saved changes are not live', () => {
    const wrapper = mountWith({
      publication: { ...live, hasUnpublishedChanges: true },
      pendingCount: 3,
    });

    expect(wrapper.text()).toContain('your latest changes are not live');
    expect(wrapper.text()).toContain('Publish 3 changes');
  });

  it('tells an account that truly cannot publish, once that is known', () => {
    const wrapper = mount(PagePublication, {
      props: { publication: live, slug: 'home', canPublish: false, canUnpublish: false },
    });

    expect(wrapper.text()).toContain('cannot publish');
  });
});
