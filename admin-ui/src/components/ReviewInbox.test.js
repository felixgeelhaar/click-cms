import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ReviewInbox from './ReviewInbox.vue';

const openReview = {
  page: 'about', locale: 'en', state: 'in_review', requestedBy: 'alex', requestedByName: 'Alex',
  reviewRevision: 'review-snapshot', requestedAt: '2026-10-25T10:00:00Z', note: 'Please check the service details.', reviewer: 'Sam', history: [],
};
const response = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });

describe('ReviewInbox', () => {
  it('lists open reviews and navigates to the requested page language', async () => {
    global.fetch = vi.fn(async () => response(200, { data: { open: [openReview] } }));
    const wrapper = mount(ReviewInbox, { props: { canReview: true, currentUsername: 'sam' } });
    await flushPromises();

    expect(wrapper.text()).toContain('about');
    expect(wrapper.text()).toContain('Please check the service details.');
    await wrapper.get('[data-testid="open-about"]').trigger('click');
    expect(wrapper.emitted('navigate')[0][0]).toBe('/admin/pages/edit/about?locale=en');
  });

  it('records an approval with the review note and refreshes the inbox', async () => {
    global.fetch = vi.fn()
      .mockResolvedValueOnce(response(200, { data: { open: [openReview] } }))
      .mockResolvedValueOnce(response(200, { data: {} }))
      .mockResolvedValueOnce(response(200, { data: { open: [] } }));
    const wrapper = mount(ReviewInbox, { props: { canReview: true, currentUsername: 'sam' } });
    await flushPromises();
    await wrapper.get('textarea').setValue('Looks good.');
    await wrapper.get('[data-testid="approve-about"]').trigger('click');
    await flushPromises();

    expect(global.fetch.mock.calls[1][0]).toBe('/api/collaboration/review/decision');
    expect(JSON.parse(global.fetch.mock.calls[1][1].body)).toMatchObject({
      page: 'about', locale: 'en', decision: 'approve', note: 'Looks good.', reviewRevision: 'review-snapshot',
    });
    expect(wrapper.text()).toContain('No reviews are waiting.');
  });

  it('does not offer a reviewer approval for their own request', async () => {
    global.fetch = vi.fn(async () => response(200, { data: { open: [openReview] } }));
    const wrapper = mount(ReviewInbox, { props: { canReview: true, currentUsername: 'alex' } });
    await flushPromises();

    expect(wrapper.find('[data-testid="approve-about"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="changes-about"]').exists()).toBe(true);
  });

  it('shows changes-requested items as awaiting the author instead of offering stale decisions', async () => {
    global.fetch = vi.fn(async () => response(200, { data: { open: [{ ...openReview, state: 'changes_requested' }] } }));
    const wrapper = mount(ReviewInbox, { props: { canReview: true, currentUsername: 'sam' } });
    await flushPromises();
    expect(wrapper.text()).toContain('Changes requested');
    expect(wrapper.find('[data-testid="approve-about"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="changes-about"]').exists()).toBe(false);
  });
});

it('prevents approving a draft that changed since the review request', async () => {
  global.fetch = vi.fn(async () => response(200, { data: { open: [{ ...openReview, stale: true }] } }));
  const wrapper = mount(ReviewInbox, { props: { canReview: true, currentUsername: 'sam' } });
  await flushPromises();
  expect(wrapper.get('[data-testid="approve-about"]').attributes('disabled')).toBeDefined();
  expect(wrapper.text()).toContain('The draft changed');
});
