import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Sidebar from './Sidebar.vue';

/**
 * The admin sidebar.
 *
 * The load-bearing behaviours: every item is a real focusable link (a defect
 * that once made this nav keyboard-unusable was items rendered as hrefless
 * anchors), the current route is marked for assistive tech and not just colour,
 * a section a role cannot use is not built at all rather than hidden, and a
 * collapsible group actually opens and closes. The grouping and icons are
 * presentation; these are the things a change could silently break.
 */

const mountSidebar = (props = {}) =>
  mount(Sidebar, { props: { activeRoute: '/admin', userRole: 'admin', collapsed: false, showBuilder: false, ...props } });

describe('Sidebar', () => {
  it('renders every item as a real link with an href', () => {
    const wrapper = mountSidebar();
    const links = wrapper.findAll('a.nav-item');
    expect(links.length).toBeGreaterThan(0);
    for (const link of links) {
      expect(link.attributes('href')).toMatch(/^\/admin/);
    }
  });

  it('marks the current route with aria-current, not only styling', () => {
    const wrapper = mountSidebar({ activeRoute: '/admin/pages' });
    const current = wrapper.findAll('a.nav-item').filter((a) => a.attributes('aria-current') === 'page');
    expect(current).toHaveLength(1);
    expect(current[0].text()).toContain('Pages');
  });

  it('treats the dashboard root as active only on the exact route', () => {
    const onPages = mountSidebar({ activeRoute: '/admin/pages' });
    const dash = onPages.findAll('a.nav-item').find((a) => a.attributes('href') === '/admin');
    expect(dash.attributes('aria-current')).toBeUndefined();

    const onDash = mountSidebar({ activeRoute: '/admin' });
    const dash2 = onDash.findAll('a.nav-item').find((a) => a.attributes('href') === '/admin');
    expect(dash2.attributes('aria-current')).toBe('page');
  });

  it('does not build the admin-only Manage group for a non-admin', () => {
    const admin = mountSidebar({ userRole: 'admin' });
    expect(admin.text()).toContain('Manage');
    expect(admin.find('a[href="/admin/users"]').exists()).toBe(true);

    const author = mountSidebar({ userRole: 'author' });
    expect(author.text()).not.toContain('Manage');
    // The admin-only destinations are absent from the markup entirely.
    expect(author.find('a[href="/admin/users"]').exists()).toBe(false);
    expect(author.find('a[href="/admin/settings"]').exists()).toBe(false);
    // A non-admin still gets their own content nav.
    expect(author.find('a[href="/admin/pages"]').exists()).toBe(true);
  });

  it('only shows the Builder link when the builder is available', () => {
    expect(mountSidebar({ showBuilder: false }).find('a[href="/admin/builder"]').exists()).toBe(false);
    expect(mountSidebar({ showBuilder: true }).find('a[href="/admin/builder"]').exists()).toBe(true);
  });

  it('collapses and expands a group from its header, with aria-expanded tracking it', async () => {
    const wrapper = mountSidebar();
    const header = wrapper.findAll('button.nav-group-header').find((b) => b.text().includes('Content'));
    expect(header).toBeTruthy();
    // The list holding the group's items is toggled with v-show, which sets the
    // inline display style — the reliable signal that it is actually hidden.
    const groupList = () => wrapper.find('a[href="/admin/pages"]').element.closest('ul');

    expect(header.attributes('aria-expanded')).toBe('true');
    expect(groupList().style.display).not.toBe('none');

    await header.trigger('click');
    expect(header.attributes('aria-expanded')).toBe('false');
    expect(groupList().style.display).toBe('none');

    await header.trigger('click');
    expect(header.attributes('aria-expanded')).toBe('true');
    expect(groupList().style.display).not.toBe('none');
  });

  it('emits navigate with the destination on an ordinary click', async () => {
    const wrapper = mountSidebar();
    await wrapper.find('a[href="/admin/media"]').trigger('click');
    expect(wrapper.emitted('navigate')).toBeTruthy();
    expect(wrapper.emitted('navigate')[0]).toEqual(['/admin/media']);
  });

  it('hides group headings when collapsed to the icon rail', () => {
    const wrapper = mountSidebar({ collapsed: true });
    // Headings are not shown, but the items remain reachable.
    expect(wrapper.find('button.nav-group-header').exists()).toBe(false);
    expect(wrapper.find('a[href="/admin/pages"]').exists()).toBe(true);
  });
});
