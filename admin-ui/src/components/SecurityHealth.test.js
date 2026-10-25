import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SecurityHealth from './SecurityHealth.vue';

const checks = [
  { id: 'authentication', label: 'Authentication', status: 'good', value: 'enabled', detail: 'Enabled.', action: 'Review authentication.', href: '/admin/settings' },
  { id: 'scheduled-backups', label: 'Scheduled backups', status: 'review', value: 'configured', detail: 'This does not verify a recent archive or restore.', action: 'Run a restore drill.', href: '' },
  { id: 'plugin-admission', label: 'Plugin admission', status: 'review', value: 'unrestricted', detail: 'No plugin allowlist.', action: 'Review plugins.', href: '/admin/plugins' },
];

beforeEach(() => {
  global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ data: { checks } }) }));
});
afterEach(() => vi.restoreAllMocks());

describe('SecurityHealth', () => {
  it('shows verified checks, their evidence and next steps without a score', async () => {
    const wrapper = mount(SecurityHealth);
    await flushPromises();
    expect(wrapper.text()).toContain('Authentication');
    expect(wrapper.text()).toContain('This does not verify a recent archive or restore.');
    expect(wrapper.text()).toContain('Run a restore drill.');
    expect(wrapper.get('a[href="/admin/plugins"]').text()).toContain('Review plugins');
    expect(wrapper.text()).not.toMatch(/score|% secure|security rating/i);
    expect(global.fetch).toHaveBeenCalledWith('/api/security/health');
  });

  it('reports a failed health request with retry', async () => {
    global.fetch = vi.fn(async () => ({ ok: false, status: 503, json: async () => ({ error: 'Unavailable' }) }));
    const wrapper = mount(SecurityHealth);
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Unavailable');
    expect(wrapper.find('button').text()).toBe('Retry');
  });
});
