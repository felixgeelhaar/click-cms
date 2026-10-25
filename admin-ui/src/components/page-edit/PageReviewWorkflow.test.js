import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PageReviewWorkflow from './PageReviewWorkflow.vue';

const response = (status, data) => ({ ok: status >= 200 && status < 300, status, json: async () => status < 400 ? { data } : { error: data } });
const waiting = { page: 'about', locale: 'en', state: 'in_review', requestedBy: 'alex', requestedByName: 'Alex', requestedAt: '2026-10-25T10:00:00Z', note: 'Please review this.', reviewer: 'sam', history: [] };

describe('PageReviewWorkflow', () => {
  it('loads the page-language review status and shows its current trail', async () => {
    global.fetch = vi.fn(async () => response(200, waiting));
    const wrapper = mount(PageReviewWorkflow, { props: { slug: 'about', locale: 'en', currentUsername: 'alex', canRequest: true, savedRevision: 'rev-1' } });
    await flushPromises();
    expect(wrapper.text()).toContain('Waiting for review');
    expect(wrapper.text()).toContain('sam');
    expect(wrapper.text()).toContain('Please review this.');
  });

  it('requests review with a note and optional reviewer, then reloads status', async () => {
    global.fetch = vi.fn()
      .mockResolvedValueOnce(response(200, { ...waiting, state: '' }))
      .mockResolvedValueOnce(response(200, waiting));
    const wrapper = mount(PageReviewWorkflow, { props: { slug: 'about', locale: 'en', currentUsername: 'alex', canRequest: true, savedRevision: 'rev-1', refreshToken: 0 } });
    await flushPromises();
    await wrapper.get('[data-testid="review-note"]').setValue('Please review this.');
    await wrapper.get('[data-testid="reviewer-id"]').setValue('sam');
    await wrapper.get('.request-form').trigger('submit');
    await flushPromises();
    expect(global.fetch.mock.calls.map(([url]) => url)).toEqual([
      '/api/collaboration/review?page=about&locale=en', '/api/collaboration/review',
    ]);
    expect(JSON.parse(global.fetch.mock.calls[1][1].body)).toEqual({ page: 'about', locale: 'en', note: 'Please review this.', reviewer: 'sam', revision: 'rev-1' });
    expect(wrapper.text()).toContain('Waiting for review');
  });

  it('explains that an optional collaboration plugin may be unavailable', async () => {
    global.fetch = vi.fn(async () => response(404, 'Not found'));
    const wrapper = mount(PageReviewWorkflow, { props: { slug: 'about', locale: 'en', canRequest: true } });
    await flushPromises();
    expect(wrapper.text()).toContain('Review workflow is not available on this site.');
  });

  it('does not submit a review request for an unsaved working copy', async () => {
    global.fetch = vi.fn(async () => response(200, { ...waiting, state: '' }));
    const wrapper = mount(PageReviewWorkflow, { props: { slug: 'about', locale: 'en', canRequest: true, savedRevision: 'rev-1', dirty: true } });
    await flushPromises();
    expect(wrapper.get('[data-testid="request-review"]').attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain('Save the page before requesting a review');
    await wrapper.get('.request-form').trigger('submit');
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });

  it('explains when the saved revision changed before the request arrived', async () => {
    global.fetch = vi.fn()
      .mockResolvedValueOnce(response(200, { ...waiting, state: '' }))
      .mockResolvedValueOnce({ ok: false, status: 409, json: async () => ({ error: 'This page changed since it was loaded.', data: { currentRevision: 'rev-new' } }) });
    const wrapper = mount(PageReviewWorkflow, { props: { slug: 'about', locale: 'en', canRequest: true, savedRevision: 'rev-old' } });
    await flushPromises();
    await wrapper.get('.request-form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Could not request review: The page changed after it was loaded. Reload the page, save it, then request review again.');
  });

  it('ignores a review status response from the previous locale', async () => {
    let resolveEnglish;
    global.fetch = vi.fn()
      .mockImplementationOnce(() => new Promise((resolve) => { resolveEnglish = resolve; }))
      .mockResolvedValueOnce(response(200, { page: 'about', locale: 'de', state: 'changes_requested', history: [] }));
    const wrapper = mount(PageReviewWorkflow, { props: { slug: 'about', locale: 'en' } });
    await wrapper.setProps({ locale: 'de' });
    await flushPromises();
    resolveEnglish(response(200, { page: 'about', locale: 'en', state: 'approved', history: [] }));
    await flushPromises();
    expect(wrapper.text()).toContain('Changes requested');
    expect(wrapper.text()).not.toContain('Approved by');
  });

  it('offers another review when a previously published revision is now stale', async () => {
    global.fetch = vi.fn(async () => response(200, { page: 'about', locale: 'en', state: 'published', stale: true, revision: 'old-rev', history: [] }));
    const wrapper = mount(PageReviewWorkflow, { props: { slug: 'about', locale: 'en', canRequest: true, savedRevision: 'new-rev' } });
    await flushPromises();
    expect(wrapper.text()).toContain('The page changed after the review was completed. Save the current version and request another review.');
    expect(wrapper.get('[data-testid="request-review"]').text()).toContain('Request review again');
  });

  it('lets an author refresh a stale in-review request against the current saved revision', async () => {
    global.fetch = vi.fn()
      .mockResolvedValueOnce(response(200, { page: 'about', locale: 'en', state: 'in_review', stale: true, revision: 'old-rev', history: [] }))
      .mockResolvedValueOnce(response(200, { page: 'about', locale: 'en', state: 'in_review', stale: false, revision: 'new-rev', history: [] }));
    const wrapper = mount(PageReviewWorkflow, { props: { slug: 'about', locale: 'en', canRequest: true, savedRevision: 'new-rev' } });
    await flushPromises();
    expect(wrapper.text()).toContain('The page changed after this review was requested. Refresh the request for the current saved version.');
    await wrapper.get('.request-form').trigger('submit');
    await flushPromises();
    expect(JSON.parse(global.fetch.mock.calls[1][1].body).revision).toBe('new-rev');
    expect(wrapper.text()).toContain('Waiting for review');
  });
});
