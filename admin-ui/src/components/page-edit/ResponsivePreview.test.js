import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ResponsivePreview from './ResponsivePreview.vue';

describe('responsive draft preview', () => {
  it('renders supplied unsaved HTML in an inert sandbox and switches viewport widths', async () => {
    const wrapper = mount(ResponsivePreview, { props: { html: '<h1>Unsaved</h1>' } });
    const frame = wrapper.get('iframe');
    expect(frame.attributes('srcdoc')).toBe('<h1>Unsaved</h1>');
    expect(frame.attributes('sandbox')).toBe('');
    expect(frame.attributes('title')).toBe('Rendered page preview');
    await wrapper.get('button').trigger('click');
    expect(wrapper.get('iframe').element.style.width).toBe('375px');
    expect(wrapper.get('iframe').attributes('sandbox')).toBe('');
  });

  it('requests an immediate refresh explicitly', async () => {
    const wrapper = mount(ResponsivePreview, { props: { html: '' } });
    await wrapper.findAll('button').at(-1).trigger('click');
    expect(wrapper.emitted('refresh')).toHaveLength(1);
  });
});
