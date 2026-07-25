import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import FieldInput from './FieldInput.vue';
import RichTextField from './RichTextField.vue';
import LinkField from './LinkField.vue';

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

/**
 * A `url` field is a link an editor points at one of their own pages far more
 * often than at anywhere else — the shipped "Call to action" section is exactly
 * that. It used to be a bare <input type="url"> with no suggestions at all, so
 * the address was typed from memory and a typo shipped silently.
 */
describe('FieldInput url fields', () => {
  const PAGES = [
    { key: 'page:en:home', slug: 'home', locale: 'en', data: { title: 'Home' } },
    { key: 'page:en:contact', slug: 'contact', locale: 'en', data: { title: 'Contact us' } },
  ];

  beforeEach(() => {
    global.fetch = vi.fn(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ data: PAGES, locale: 'en', locales: ['en'] }),
    }));
  });

  const mountUrl = async (over = {}, props = {}) => {
    const wrapper = mount(FieldInput, {
      props: { field: field({ name: 'buttonLink', label: 'Button link', type: 'url', ...over }), ...props },
    });
    await flushPromises();
    return wrapper;
  };

  it('reaches the page picker rather than a bare url input', async () => {
    const wrapper = await mountUrl();

    expect(wrapper.findComponent(LinkField).exists()).toBe(true);
    expect(wrapper.find('input[type="url"]').exists()).toBe(false);
  });

  it('emits a root-relative path, which SectionRenderer links as-is', async () => {
    const wrapper = await mountUrl();
    await wrapper.find('select.link-select').setValue('/contact');

    const emitted = wrapper.emitted('update:modelValue');
    // Unlike a menu target, this context wants the path the public router
    // serves: SectionRenderer puts the value straight into an href.
    expect(emitted.at(-1)[0]).toBe('/contact');
  });

  it('keeps the label associated with the picker it now renders', async () => {
    const wrapper = await mountUrl();

    const label = wrapper.find('label.field-label');
    expect(label.text()).toContain('Button link');
    expect(label.attributes('for')).toBe(wrapper.find('select.link-select').attributes('id'));
  });

  it('offers no empty choice on a required field, and passes the error through', async () => {
    const wrapper = await mountUrl({ required: true }, { error: 'Button link is required.' });

    const none = wrapper.findAll('option').find((o) => o.element.value === '');
    expect(none.attributes('disabled')).toBeDefined();
    expect(wrapper.find('select.link-select').attributes('aria-invalid')).toBe('true');
  });

  it('shows and warns about a stored address that names no page', async () => {
    const wrapper = await mountUrl({}, { modelValue: '/kontakt' });

    expect(wrapper.find('.link-warning').text()).toContain('no longer exists');
    // Nothing was rewritten on the way in.
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
  });
});
