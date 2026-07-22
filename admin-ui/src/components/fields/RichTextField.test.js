import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import RichTextField from './RichTextField.vue';

/**
 * The rich-text editor is where an editor's formatting is authored, and its
 * output is written into the public page as markup. So two things matter here:
 * the formatting it applies must end up in the emitted model, and nothing
 * unsafe an editor pastes may survive into that model. The server sanitiser is
 * the real security boundary — this client pass is convenience and cleanliness
 * — but the component must still never hand up a live <script>.
 */

const editableOf = (wrapper) => wrapper.get('[contenteditable="true"]');

const lastModel = (wrapper) => {
  const events = wrapper.emitted('update:modelValue');
  return events ? events[events.length - 1][0] : undefined;
};

// Places the selection across the whole text content of an element, the way a
// user would drag-select before clicking a toolbar button.
const selectAll = (el) => {
  const range = document.createRange();
  range.selectNodeContents(el);
  const sel = window.getSelection();
  sel.removeAllRanges();
  sel.addRange(range);
};

afterEach(() => {
  vi.restoreAllMocks();
});

describe('RichTextField', () => {
  it('renders a toolbar and an editable region', () => {
    const wrapper = mount(RichTextField, { props: { modelValue: '' } });

    expect(wrapper.get('[contenteditable="true"]')).toBeTruthy();
    expect(wrapper.get('button[aria-label="Bold"]')).toBeTruthy();
    expect(wrapper.get('button[aria-label="Italic"]')).toBeTruthy();
    expect(wrapper.get('button[aria-label="Link"]')).toBeTruthy();
    expect(wrapper.get('button[aria-label="Bullet list"]')).toBeTruthy();
    expect(wrapper.get('button[aria-label="Numbered list"]')).toBeTruthy();
    expect(wrapper.get('button[aria-label="Heading 2"]')).toBeTruthy();
    expect(wrapper.get('button[aria-label="Heading 3"]')).toBeTruthy();
    expect(wrapper.get('button[aria-label="Paragraph"]')).toBeTruthy();
  });

  it('shows the model value as rendered HTML, not escaped text', () => {
    const wrapper = mount(RichTextField, {
      props: { modelValue: '<p>Hello <strong>world</strong></p>' },
    });

    const editable = editableOf(wrapper);
    expect(editable.element.querySelector('strong')).not.toBeNull();
    expect(editable.element.textContent).toContain('Hello world');
  });

  it('emits the edited HTML when the content changes', async () => {
    const wrapper = mount(RichTextField, { props: { modelValue: '<p>old</p>' } });

    const editable = editableOf(wrapper);
    editable.element.innerHTML = '<p>new text</p>';
    await editable.trigger('input');

    expect(lastModel(wrapper)).toContain('new text');
  });

  it('wraps the selection in <strong> when Bold is pressed, and emits it', async () => {
    // Attached to the document: the Selection API only holds a range on a node
    // that is actually in the page, which is where a real editor's caret lives.
    const wrapper = mount(RichTextField, { props: { modelValue: '' }, attachTo: document.body });

    const editable = editableOf(wrapper);
    editable.element.innerHTML = 'pick me';
    selectAll(editable.element);

    await wrapper.get('button[aria-label="Bold"]').trigger('click');

    expect(editable.element.querySelector('strong')).not.toBeNull();
    expect(lastModel(wrapper)).toContain('<strong>');
    expect(lastModel(wrapper)).toContain('pick me');
  });

  it('turns the current block into a heading when Heading 2 is pressed', async () => {
    const wrapper = mount(RichTextField, { props: { modelValue: '<p>title</p>' }, attachTo: document.body });

    const editable = editableOf(wrapper);
    selectAll(editable.element.querySelector('p'));

    await wrapper.get('button[aria-label="Heading 2"]').trigger('click');

    expect(editable.element.querySelector('h2')).not.toBeNull();
    expect(lastModel(wrapper)).toContain('<h2>');
    expect(lastModel(wrapper)).toContain('title');
  });

  it('wraps the selection in a link using the prompted URL', async () => {
    vi.spyOn(window, 'prompt').mockReturnValue('https://example.com');
    const wrapper = mount(RichTextField, { props: { modelValue: '' }, attachTo: document.body });

    const editable = editableOf(wrapper);
    editable.element.innerHTML = 'a link';
    selectAll(editable.element);

    await wrapper.get('button[aria-label="Link"]').trigger('click');

    const anchor = editable.element.querySelector('a');
    expect(anchor).not.toBeNull();
    expect(anchor.getAttribute('href')).toBe('https://example.com');
    expect(lastModel(wrapper)).toContain('href="https://example.com"');
  });

  it('refuses a javascript: URL rather than writing it into the model', async () => {
    vi.spyOn(window, 'prompt').mockReturnValue('javascript:alert(1)');
    const wrapper = mount(RichTextField, { props: { modelValue: '' } });

    const editable = editableOf(wrapper);
    editable.element.innerHTML = 'danger';
    selectAll(editable.element);

    await wrapper.get('button[aria-label="Link"]').trigger('click');

    expect(lastModel(wrapper) ?? '').not.toContain('javascript:');
  });

  it('strips a pasted script from the emitted model', async () => {
    const wrapper = mount(RichTextField, { props: { modelValue: '' } });

    const editable = editableOf(wrapper);
    editable.element.innerHTML = '<p>ok</p><script>alert(1)</script>';
    await editable.trigger('input');

    const model = lastModel(wrapper);
    expect(model).not.toContain('<script');
    expect(model).not.toContain('alert(1)');
    expect(model).toContain('ok');
  });

  it('strips a disallowed onclick handler from the emitted model', async () => {
    const wrapper = mount(RichTextField, { props: { modelValue: '' } });

    const editable = editableOf(wrapper);
    editable.element.innerHTML = '<p onclick="steal()">hi</p>';
    await editable.trigger('input');

    expect(lastModel(wrapper)).not.toContain('onclick');
    expect(lastModel(wrapper)).toContain('hi');
  });

  it('round-trips: value in, edited, emitted value renders back the same', async () => {
    const wrapper = mount(RichTextField, {
      props: { modelValue: '<p>start</p>' },
    });

    const editable = editableOf(wrapper);
    editable.element.innerHTML = '<p>Hello <em>there</em></p>';
    await editable.trigger('input');

    const emitted = lastModel(wrapper);

    const second = mount(RichTextField, { props: { modelValue: emitted } });
    expect(second.get('[contenteditable="true"]').element.querySelector('em')).not.toBeNull();
    expect(second.get('[contenteditable="true"]').element.textContent).toContain('Hello there');
  });
});
