import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DraftMerge from './DraftMerge.vue';

describe('DraftMerge', () => {
  it('shows an automatic merge for disjoint fields and emits only on explicit apply', async () => {
    const wrapper = mount(DraftMerge, {
      props: {
        base: { title: 'Home', seo: { description: 'Old' } },
        local: { title: 'Local title', seo: { description: 'Old' } },
        server: { title: 'Home', seo: { description: 'Server description' } },
        serverRevision: 'rev-new',
      },
    });
    expect(wrapper.text()).toContain('Local title');
    expect(wrapper.text()).toContain('Server description');
    expect(wrapper.get('[data-testid="apply-merge"]').attributes('disabled')).toBeUndefined();
    expect(wrapper.emitted('apply')).toBeUndefined();

    await wrapper.get('[data-testid="apply-merge"]').trigger('click');
    expect(wrapper.emitted('apply')).toEqual([[
      { page: { title: 'Local title', seo: { description: 'Server description' } }, revision: 'rev-new' },
    ]]);
  });

  it('requires a local or server choice for every overlapping field', async () => {
    const wrapper = mount(DraftMerge, {
      props: {
        base: { title: 'Home' },
        local: { title: 'Local title' },
        server: { title: 'Server title' },
        serverRevision: 'rev-new',
      },
    });
    expect(wrapper.get('[data-testid="apply-merge"]').attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain('Home');

    await wrapper.get('input[value="server"]').setValue();
    expect(wrapper.get('[data-testid="apply-merge"]').attributes('disabled')).toBeUndefined();
    await wrapper.get('[data-testid="apply-merge"]').trigger('click');
    expect(wrapper.emitted('apply')[0][0]).toEqual({ page: { title: 'Server title' }, revision: 'rev-new' });
  });
});
