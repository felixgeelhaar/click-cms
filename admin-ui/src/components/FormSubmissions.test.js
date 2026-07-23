import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import FormSubmissions from './FormSubmissions.vue';

/**
 * The submissions screen shows leads collected by a contact form. Two
 * properties matter enough to pin:
 *
 *  - It renders whatever order the API returns, which is newest first. The
 *    server does the sorting (on a microsecond timestamp); the component must
 *    not silently re-order and hide that.
 *  - A submission is untrusted text typed by an anonymous visitor. The whole
 *    plugin exists to accept arbitrary input, so the moment this screen renders
 *    a value as markup instead of as text it becomes the stored-XSS payload's
 *    destination. Values must be shown as inert text.
 */

const submission = (id, page, name, email, message, submittedAt) => ({
  id,
  page,
  locale: 'en',
  submittedAt,
  fields: { name, email, message },
});

/** Answer /api/forms/submissions with the given rows. */
const respondWith = (rows) => {
  global.fetch = vi.fn(async () => ({
    ok: true,
    status: 200,
    json: async () => ({ data: rows }),
  }));
};

const mountList = async (rows) => {
  respondWith(rows);
  const wrapper = mount(FormSubmissions);
  await flushPromises();
  return wrapper;
};

beforeEach(() => {
  vi.restoreAllMocks();
});

describe('form submissions list', () => {
  it('renders each submission with its page, name, email and message', async () => {
    const wrapper = await mountList([
      submission('a', 'contact', 'Ada Lovelace', 'ada@example.com', 'Hello there', '2026-07-01T10:00:00.000000+00:00'),
    ]);

    const text = wrapper.text();
    expect(text).toContain('contact');
    expect(text).toContain('Ada Lovelace');
    expect(text).toContain('ada@example.com');
    expect(text).toContain('Hello there');
  });

  it('preserves the order the API returned — newest first', async () => {
    const wrapper = await mountList([
      submission('c', 'contact', 'Third', 'c@example.com', 'third', '2026-07-03T10:00:00.000000+00:00'),
      submission('b', 'contact', 'Second', 'b@example.com', 'second', '2026-07-02T10:00:00.000000+00:00'),
      submission('a', 'contact', 'First', 'a@example.com', 'first', '2026-07-01T10:00:00.000000+00:00'),
    ]);

    const rows = wrapper.findAll('.submission');
    expect(rows).toHaveLength(3);
    expect(rows[0].text()).toContain('Third');
    expect(rows[2].text()).toContain('First');
  });

  it('shows an empty state when there are no submissions', async () => {
    const wrapper = await mountList([]);

    expect(wrapper.findAll('.submission')).toHaveLength(0);
    expect(wrapper.text().toLowerCase()).toContain('no submissions');
  });

  it('shows an error state when the request fails', async () => {
    global.fetch = vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) }));
    const wrapper = mount(FormSubmissions);
    await flushPromises();

    expect(wrapper.find('[role="alert"]').exists()).toBe(true);
  });

  it('renders a submitted value as inert text, never as live markup', async () => {
    const payload = '<script>alert(document.cookie)</script>';
    const wrapper = await mountList([
      submission('x', 'contact', 'Mallory', 'm@example.com', payload, '2026-07-01T10:00:00.000000+00:00'),
    ]);

    // No script element was created from the stored value...
    expect(wrapper.findAll('script')).toHaveLength(0);
    // ...and the value is present as text the browser will not execute.
    expect(wrapper.text()).toContain(payload);
  });

  it('exposes an accessible heading and region', async () => {
    const wrapper = await mountList([
      submission('a', 'contact', 'Ada', 'ada@example.com', 'hi', '2026-07-01T10:00:00.000000+00:00'),
    ]);

    // A labelled region and a heading, so the screen is navigable by assistive
    // technology rather than an unlabelled blob of divs.
    expect(wrapper.find('[aria-labelledby]').exists()).toBe(true);
    expect(wrapper.find('h1, h2').exists()).toBe(true);
  });
});
