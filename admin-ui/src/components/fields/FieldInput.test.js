import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import FieldInput from './FieldInput.vue';
import RichTextField from './RichTextField.vue';

/**
 * FieldInput routes a field to the right control by its declared type. The one
 * distinction that matters here: a `richtext` field must reach the HTML editor,
 * while a `textarea` stays a plain escaped textarea. They were once the same
 * control, and conflating them is what would let unformatted prose masquerade
 * as markup — or an editor lose their formatting.
 */

const field = (over = {}) => ({ name: 'body', label: 'Body', type: 'text', ...over });

describe('FieldInput field routing', () => {
  it('renders the rich-text editor for a richtext field', () => {
    const wrapper = mount(FieldInput, {
      props: { field: field({ type: 'richtext' }), modelValue: '<p>hi</p>' },
    });

    expect(wrapper.findComponent(RichTextField).exists()).toBe(true);
    // Not the bare textarea the field type used to fall through to.
    expect(wrapper.find('textarea').exists()).toBe(false);
  });

  it('renders a plain textarea for a textarea field', () => {
    const wrapper = mount(FieldInput, {
      props: { field: field({ type: 'textarea' }), modelValue: 'plain' },
    });

    expect(wrapper.find('textarea').exists()).toBe(true);
    expect(wrapper.findComponent(RichTextField).exists()).toBe(false);
  });

  it('passes the rich-text editor value straight up through v-model', async () => {
    const wrapper = mount(FieldInput, {
      props: { field: field({ type: 'richtext' }), modelValue: '' },
    });

    wrapper.findComponent(RichTextField).vm.$emit('update:modelValue', '<p><strong>bold</strong></p>');
    await wrapper.vm.$nextTick();

    const emitted = wrapper.emitted('update:modelValue');
    expect(emitted).toBeTruthy();
    expect(emitted[emitted.length - 1][0]).toBe('<p><strong>bold</strong></p>');
  });
});
